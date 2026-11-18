<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/snmp.php';
require_once __DIR__ . '/discover.php';
require_once __DIR__ . '/alerts.php';

/** Poll one device: refresh status/uptime, store a sample per enabled metric. */
function poll_device(array $dev): void
{
    $now = time();
    $id = (int)$dev['id'];
    $metrics = db_all('SELECT * FROM metrics WHERE device_id=? AND enabled=1', [$id]);

    $oids = [OID_UPTIME];
    foreach ($metrics as $m) {
        foreach (explode('/', $m['oid']) as $o) {
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
            return round((float)$raw * $scale, 6);
    }
}
