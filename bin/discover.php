<?php
declare(strict_types=1);
// Usage:
//   php bin/discover.php <device_id>
//   php bin/discover.php --add <name> <host> [community=public] [version=2c] [port=161]
//   php bin/discover.php --list
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/lib/discover.php';

$a = array_slice($argv, 1);
if (($a[0] ?? '') === '--list') {
    foreach (db_all('SELECT d.id, d.name, d.host, d.status, COUNT(m.id) n FROM devices d LEFT JOIN metrics m ON m.device_id=d.id GROUP BY d.id, d.name, d.host, d.status') as $d) {
        printf("%3d  %-20s %-20s %-8s %d metrics\n", $d['id'], $d['name'], $d['host'], $d['status'], $d['n']);
    }
    exit(0);
}
if (($a[0] ?? '') === '--add') {
    if (count($a) < 3) {
        fwrite(STDERR, "need name and host\n");
        exit(1);
    }
    db_exec('INSERT INTO devices (name, host, community, version, port) VALUES (?,?,?,?,?)',
        [$a[1], $a[2], $a[3] ?? 'public', $a[4] ?? '2c', (int)($a[5] ?? 161)]);
    $id = (int)db()->lastInsertId();
    echo "Added device $id\n";
} elseif (ctype_digit($a[0] ?? '')) {
    $id = (int)$a[0];
} else {
    fwrite(STDERR, "usage: discover.php <id> | --add name host [community] [version] [port] | --list\n");
    exit(1);
}
try {
    echo 'Discovered ' . discover_device($id) . " new metrics\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Discovery failed: ' . $e->getMessage() . "\n");
    exit(1);
}
