<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/snmp.php';

const OID_SYSDESCR = '1.3.6.1.2.1.1.1.0';
const OID_UPTIME   = '1.3.6.1.2.1.1.3.0';
const OID_SYSNAME  = '1.3.6.1.2.1.1.5.0';

/**
 * Walk a device and create metrics for interfaces, CPU, load, storage and sensors.
 * Existing metrics (same OID) are kept with their user edits. Returns number added.
 */
function discover_device(int $id): int
{
    $dev = db_one('SELECT * FROM devices WHERE id=?', [$id]);
    if (!$dev) {
        throw new InvalidArgumentException("No device $id");
    }
    $s = snmp_open($dev);
    $sys = $s->get([OID_SYSDESCR, OID_SYSNAME, OID_UPTIME]);
    if (!$sys) {
        throw new RuntimeException('No SNMP response');
    }
    db_exec('UPDATE devices SET sysdescr=?, sysname=?, uptime=? WHERE id=?',
        [$sys[OID_SYSDESCR] ?? null, $sys[OID_SYSNAME] ?? null, (int)($sys[OID_UPTIME] ?? 0), $id]);

    $m = [];
    $add = function (string $kind, string $oid, string $label, ?string $unit, string $type = 'gauge', float $scale = 1, ?float $warn = null, ?float $crit = null) use (&$m) {
        $m[] = compact('kind', 'oid', 'label', 'unit', 'type', 'scale', 'warn', 'crit');
    };

    // Interfaces (IF-MIB)
    $descr = snmp_column($s, '1.3.6.1.2.1.2.2.1.2');
    $names = snmp_column($s, '1.3.6.1.2.1.31.1.1.1.1');
    $alias = snmp_column($s, '1.3.6.1.2.1.31.1.1.1.18');
    $types = snmp_column($s, '1.3.6.1.2.1.2.2.1.3');
    $hc    = snmp_column($s, '1.3.6.1.2.1.31.1.1.1.6');
    foreach ($descr as $i => $d) {
        if (($types[$i] ?? '') === '24') {
            continue; // softwareLoopback
        }
        $name = $names[$i] ?? $d;
        if (!empty($alias[$i])) {
            $name .= " ({$alias[$i]})";
        }
        $in  = isset($hc[$i]) ? "1.3.6.1.2.1.31.1.1.1.6.$i"  : "1.3.6.1.2.1.2.2.1.10.$i";
        $out = isset($hc[$i]) ? "1.3.6.1.2.1.31.1.1.1.10.$i" : "1.3.6.1.2.1.2.2.1.16.$i";
        $add('if_in',  $in,  "$name in",  'bps', 'counter', 8);
        $add('if_out', $out, "$name out", 'bps', 'counter', 8);
        $add('if_status', "1.3.6.1.2.1.2.2.1.8.$i", "$name status", null);
        $add('if_err', "1.3.6.1.2.1.2.2.1.14.$i", "$name in errors", 'pps', 'counter');
        $add('if_err', "1.3.6.1.2.1.2.2.1.20.$i", "$name out errors", 'pps', 'counter');
    }

    // CPU (HOST-RESOURCES hrProcessorLoad)
    $n = 0;
    foreach (snmp_column($s, '1.3.6.1.2.1.25.3.3.1.2') as $i => $v) {
        $add('cpu', "1.3.6.1.2.1.25.3.3.1.2.$i", 'CPU ' . $n++, '%', 'gauge', 1, 80, 95);
    }
    // Load average (UCD)
    foreach (snmp_column($s, '1.3.6.1.4.1.2021.10.1.5') as $i => $v) {
        $add('load', "1.3.6.1.4.1.2021.10.1.5.$i", ['1' => 'Load 1m', '2' => 'Load 5m', '3' => 'Load 15m'][$i] ?? "Load $i", null, 'gauge', 0.01);
    }

    // Storage (hrStorageTable): value = 100 * used / size
    $stype = snmp_column($s, '1.3.6.1.2.1.25.2.3.1.2');
    $sdesc = snmp_column($s, '1.3.6.1.2.1.25.2.3.1.3');
    foreach ($stype as $i => $t) {
        $t = oid_norm($t);
        $d = $sdesc[$i] ?? "storage $i";
        $ram  = str_ends_with($t, '25.2.1.2');
        $disk = str_ends_with($t, '25.2.1.4');
        if (!$ram && !$disk) {
            continue;
        }
        if ($disk && preg_match('#^/(run|dev|sys|proc|snap)(/|$)#', $d)) {
            continue;
        }
        $add($ram ? 'mem' : 'storage', "1.3.6.1.2.1.25.2.3.1.6.$i/1.3.6.1.2.1.25.2.3.1.5.$i",
            $ram ? 'Memory' : $d, '%', 'ratio', 100, 85, 95);
    }

    // LM-SENSORS: temperatures (m°C) and fans (RPM)
    $tn = snmp_column($s, '1.3.6.1.4.1.2021.13.16.2.1.2');
    foreach ($tn as $i => $name) {
        $add('sensor', "1.3.6.1.4.1.2021.13.16.2.1.3.$i", $name, '°C', 'gauge', 0.001, 75, 90);
    }
    foreach (snmp_column($s, '1.3.6.1.4.1.2021.13.16.3.1.2') as $i => $name) {
        $add('sensor', "1.3.6.1.4.1.2021.13.16.3.1.3.$i", $name, 'rpm');
    }

    // ENTITY-SENSOR-MIB
    $etype = snmp_column($s, '1.3.6.1.2.1.99.1.1.1.1');
    if ($etype) {
        $escale = snmp_column($s, '1.3.6.1.2.1.99.1.1.1.2');
        $eprec  = snmp_column($s, '1.3.6.1.2.1.99.1.1.1.3');
        $ename  = snmp_column($s, '1.3.6.1.2.1.47.1.1.1.1.7');
        $units = ['3' => 'VAC', '4' => 'VDC', '5' => 'A', '6' => 'W', '7' => 'Hz', '8' => '°C', '9' => '%RH', '10' => 'rpm'];
        foreach ($etype as $i => $t) {
            if (!isset($units[$t])) {
                continue;
            }
            $scale = 10 ** (3 * ((int)($escale[$i] ?? 9) - 9)) * 10 ** (-(int)($eprec[$i] ?? 0));
            $add('sensor', "1.3.6.1.2.1.99.1.1.1.4.$i", $ename[$i] ?? "sensor $i", $units[$t], 'gauge', $scale);
        }
    }

    // Printer-MIB: supplies (% remaining when max is known) and page counter
    $sdesc = snmp_column($s, '1.3.6.1.2.1.43.11.1.1.6');
    if ($sdesc) {
        $smax = snmp_column($s, '1.3.6.1.2.1.43.11.1.1.8');
        foreach ($sdesc as $i => $d) {
            if ((int)($smax[$i] ?? 0) > 0) {
                $add('supply', "1.3.6.1.2.1.43.11.1.1.9.$i/1.3.6.1.2.1.43.11.1.1.8.$i", $d, '%', 'ratio', 100, 20, 10);
            }
        }
        foreach (snmp_column($s, '1.3.6.1.2.1.43.10.2.1.4') as $i => $v) {
            $add('pages', "1.3.6.1.2.1.43.10.2.1.4.$i", 'Pages printed', 'pages');
        }
    }

    $added = 0;
    $ins = db()->prepare('INSERT INTO metrics (device_id, kind, oid, label, unit, type, scale, warn, crit) VALUES (?,?,?,?,?,?,?,?,?)');
    foreach ($m as $r) {
        if (db_val('SELECT 1 FROM metrics WHERE device_id=? AND oid=?', [$id, $r['oid']])) {
            continue;
        }
        $ins->execute([$id, $r['kind'], $r['oid'], $r['label'], $r['unit'], $r['type'], $r['scale'], $r['warn'], $r['crit']]);
        $added++;
    }
    log_event('info', "Discovery: $added new metrics", $id);
    return $added;
}
