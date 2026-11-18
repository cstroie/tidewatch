<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/snmp.php';
require_once __DIR__ . '/prom.php';
require_once __DIR__ . '/discover.php';
require_once __DIR__ . '/alerts.php';

/** Poll one device: refresh status/uptime, store a sample per enabled metric. */
function poll_device(array $dev): void
{
    $now = time();
    $id = (int)$dev['id'];
    $metrics = db_all('SELECT * FROM metrics WHERE device_id=? AND enabled=1', [$id]);
    if (($dev['type'] ?? 'snmp') === 'prom') {
        poll_prom($dev, $metrics, $now);
        return;
    }

    $oids = [OID_UPTIME, OID_SYSNAME, OID_SYSCONTACT, OID_SYSLOCATION];
    foreach ($metrics as $m) {
        foreach (preg_split('#[/,]#', $m['oid']) as $o) {
            $oids[] = $o;
        }
    }
    try {
        $vals = snmp_open($dev)->get(array_values(array_unique($oids)));
    } catch (Throwable $e) {
        $vals = [];
    }
    if (!isset($vals[OID_UPTIME])) {
        db_exec("UPDATE devices SET status='down', last_poll=? WHERE id=?", [$now, $id]);
        alert_device($dev, 'down');
        return;
    }
    $uptime = (int)$vals[OID_UPTIME];
    $rebooted = $dev['uptime'] !== null && $uptime < (int)$dev['uptime'];
    db_exec("UPDATE devices SET status='up', last_poll=?, uptime=? WHERE id=?", [$now, $uptime, $id]);
    alert_device($dev, 'up');

    // keep the descriptive system fields current (only write when they changed)
    $sys = ['sysname' => $vals[OID_SYSNAME] ?? null, 'syscontact' => $vals[OID_SYSCONTACT] ?? null, 'syslocation' => $vals[OID_SYSLOCATION] ?? null];
    foreach ($sys as $col => $v) {
        $v = $v === null || $v === '' ? null : (string)$v;
        if ($v !== ($dev[$col] ?? null)) {
            db_exec("UPDATE devices SET $col=? WHERE id=?", [$v, $id]);
        }
    }

    $pdo = db();
    $pdo->beginTransaction();
    $ins = $pdo->prepare(db_is_sqlite()
        ? 'INSERT OR REPLACE INTO samples_raw (metric_id, ts, value) VALUES (?,?,?)'
        : 'REPLACE INTO samples_raw (metric_id, ts, value) VALUES (?,?,?)');
    foreach ($metrics as $m) {
        $v = metric_value($m, $vals, $now, $rebooted);
        if ($v === null) {
            continue;
        }
        $ins->execute([$m['id'], $now, $v]);
        alert_metric($dev, $m, $v);
    }
    $pdo->commit();
}

/** Poll a Prometheus /metrics device: up/down is HTTP success, values are keyed by series. */
function poll_prom(array $dev, array $metrics, int $now): void
{
    $id = (int)$dev['id'];
    try {
        $series = prom_read($dev);
    } catch (Throwable $e) {
        $series = [];
    }
    if (!$series) {
        db_exec("UPDATE devices SET status='down', last_poll=? WHERE id=?", [$now, $id]);
        alert_device($dev, 'down');
        return;
    }
    db_exec("UPDATE devices SET status='up', last_poll=? WHERE id=?", [$now, $id]);
    alert_device($dev, 'up');

    $vals = [];
    foreach ($series as $k => $s) {
        $vals[$k] = (string)$s['value'];
    }
    $pdo = db();
    $pdo->beginTransaction();
    $ins = $pdo->prepare(db_is_sqlite()
        ? 'INSERT OR REPLACE INTO samples_raw (metric_id, ts, value) VALUES (?,?,?)'
        : 'REPLACE INTO samples_raw (metric_id, ts, value) VALUES (?,?,?)');
    foreach ($metrics as $m) {
        $v = metric_value($m, $vals, $now, false);
        if ($v === null) {
            continue;
        }
        $ins->execute([$m['id'], $now, $v]);
        alert_metric($dev, $m, $v);
    }
    $pdo->commit();
}

/** Turn raw SNMP values into a stored value; null if not computable yet. */
function metric_value(array $m, array $vals, int $now, bool $rebooted): ?float
{
    $scale = (float)$m['scale'];
    switch ($m['type']) {
        case 'ratio':
            [$a, $b] = explode('/', $m['oid']);
            if (!isset($vals[$a], $vals[$b]) || (float)$vals[$b] == 0.0) {
                return null;
            }
            return round((float)$vals[$a] / (float)$vals[$b] * $scale, 3);

        case 'mem_ucd': // total, free, buffers, cached (kB): used % excluding cache
            $o = explode(',', $m['oid']);
            $t = (float)($vals[$o[0]] ?? 0);
            if ($t <= 0 || !isset($vals[$o[1]])) {
                return null;
            }
            $used = $t - (float)$vals[$o[1]] - (float)($vals[$o[2]] ?? 0) - (float)($vals[$o[3]] ?? 0);
            return round(max(0.0, $used) / $t * $scale, 3);

        case 'idle': // UCD ssCpuIdle -> busy %
            if (!isset($vals[$m['oid']]) || !is_numeric($vals[$m['oid']])) {
                return null;
            }
            return 100.0 - (float)$vals[$m['oid']];

        case 'cpu_raw': // UCD ssCpuRaw* tick counters; first OID is idle. busy % = 1 - d(idle)/d(total)
            $o = explode(',', $m['oid']);
            $cur = [];
            foreach ($o as $x) {
                if (!isset($vals[$x]) || !is_numeric($vals[$x])) {
                    return null;
                }
                $cur[] = (float)$vals[$x];
            }
            $prev = db_val('SELECT raw FROM last_counter WHERE metric_id=?', [$m['id']]);
            db_upsert('last_counter', ['metric_id' => $m['id'], 'ts' => $now, 'raw' => implode(',', $cur)], ['metric_id']);
            $prev = $prev ? array_map('floatval', explode(',', (string)$prev)) : null;
            if (!$prev || count($prev) !== count($cur) || $rebooted) {
                return null;
            }
            $d = [];
            foreach ($cur as $k => $v) {
                $d[$k] = $v - $prev[$k];
                if ($d[$k] < 0) {
                    $d[$k] += 4294967296.0; // Counter32 wrap
                }
            }
            $total = array_sum($d);
            return $total > 0 ? round(100 * (1 - $d[0] / $total), 2) : null;

        case 'counter':
            if (!isset($vals[$m['oid']]) || !is_numeric($vals[$m['oid']])) {
                return null;
            }
            $raw = $vals[$m['oid']];
            $prev = db_one('SELECT ts, raw FROM last_counter WHERE metric_id=?', [$m['id']]);
            db_upsert('last_counter', ['metric_id' => $m['id'], 'ts' => $now, 'raw' => $raw], ['metric_id']);
            if (!$prev || $rebooted) {
                return null;
            }
            $dt = $now - (int)$prev['ts'];
            if ($dt <= 0 || $dt > 3600) {
                return null;
            }
            $delta = (float)$raw - (float)$prev['raw'];
            if ($delta < 0) {
                // counter wrap: 32-bit if the previous value fit, else 64-bit
                $delta += (float)$prev['raw'] <= 4294967295 ? 4294967296.0 : 18446744073709551616.0;
                if ($delta < 0 || $delta > 1.25e11 * $dt) {
                    return null; // implausible (> 1 Tbps): counter reset
                }
            }
            return round($delta / $dt * $scale, 3);

        default: // gauge
            $raw = $vals[$m['oid']] ?? null;
            if ($raw === null || !is_numeric(trim($raw))) {
                return null;
            }
            $raw = (float)$raw;
            if ($m['kind'] === 'sensor' && $raw >= 2147483648.0) {
                $raw -= 4294967296.0; // Gauge32 carrying a negative reading
            }
            return round($raw * $scale, 6);
    }
}
