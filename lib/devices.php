<?php
declare(strict_types=1);
require_once __DIR__ . '/metrics.php';
require_once __DIR__ . '/discover.php';

/** Poll a just-saved device right away; returns its resulting status ('' if disabled or failed). */
function poll_now(int $id): string
{
    $dev = db_one('SELECT * FROM devices WHERE id = ?', [$id]);
    if (!$dev || !$dev['enabled']) {
        return '';
    }
    try {
        poll_device($dev);
    } catch (Throwable $e) {
        return '';
    }
    return (string)db_val('SELECT status FROM devices WHERE id = ?', [$id]);
}

/**
 * Validate and store the device form ($post: the form's fields; id empty for a new device).
 * Returns ['id' => saved id or 0 on a validation error, 'msg' => text for a notice, 'kind' => ok|warn|err].
 */
function device_save(array $post): array
{
    $id = (int)($post['id'] ?? 0);
    $f = fn($k, $d = null) => isset($post[$k]) && trim((string)$post[$k]) !== '' ? trim((string)$post[$k]) : $d;
    $name = mb_substr((string)$f('name', ''), 0, 128);
    $host = (string)$f('host', '');
    if (!preg_match('/^[A-Za-z0-9.:\-_]+$/', $host)) {
        return ['id' => 0, 'msg' => 'A valid host/IP is required.', 'kind' => 'err'];
    }
    $prom = $id ? db_val('SELECT type FROM devices WHERE id = ?', [$id]) === 'prom' : ($post['type'] ?? '') === 'prom';
    $autoName = $name === ''; // blank name: use sysName once polled/discovered, host until then
    if ($autoName) {
        $name = $id ? (string)db_val('SELECT COALESCE(NULLIF(sysname, \'\'), ?) FROM devices WHERE id = ?', [$host, $id]) : $host;
    }
    $path = '/' . ltrim((string)$f('path', '/metrics'), '/');
    if ($prom && (preg_match('#[\s"<>]#', $path) || strlen($path) > 255)) {
        return ['id' => 0, 'msg' => 'Invalid metrics path.', 'kind' => 'err'];
    }
    $row = [
        'type' => $prom ? 'prom' : 'snmp', 'scheme' => ($post['scheme'] ?? '') === 'https' ? 'https' : 'http', 'path' => $path,
        'name' => $name, 'host' => $host, 'port' => max(1, min(65535, (int)$f('port', $prom ? 80 : 161))),
        'version' => in_array($post['version'] ?? '', ['1', '2c', '3'], true) ? $post['version'] : '2c',
        'community' => $f('community', 'public'),
        'v3_user' => $f('v3_user'), 'v3_level' => $f('v3_level', 'authPriv'),
        'v3_auth_proto' => $f('v3_auth_proto', 'SHA'), 'v3_priv_proto' => $f('v3_priv_proto', 'AES'),
        'poll_interval' => max(60, (int)$f('poll_interval', $prom ? 300 : 60)),
        'enabled' => empty($post['enabled']) ? 0 : 1, 'public' => empty($post['public']) ? 0 : 1,
        'icon' => isset(DEVICE_ICONS[$post['icon'] ?? '']) ? $post['icon'] : null,
    ];
    foreach (['v3_auth_pass', 'v3_priv_pass'] as $k) { // keep stored passphrases when left blank
        if ($f($k) !== null) {
            $row[$k] = $f($k);
        }
    }
    if ($id) {
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($row)));
        db_exec("UPDATE devices SET $set WHERE id = ?", [...array_values($row), $id]);
        $st = poll_now($id);
        return ['id' => $id, 'msg' => 'Device saved' . ($st ? "; polled, status: $st" : '') . '.', 'kind' => $st === 'down' ? 'warn' : 'ok'];
    }
    $row['sort'] = (int)db_val('SELECT COALESCE(MAX(sort), 0) + 1 FROM devices'); // new devices go last on the overview
    db_exec('INSERT INTO devices (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')', array_values($row));
    $id = (int)db()->lastInsertId();
    $msg = 'Device added';
    $kind = 'ok';
    if (!empty($post['discover'])) {
        try {
            $n = discover_device($id);
            $sys = $autoName ? (string)db_val('SELECT sysname FROM devices WHERE id = ?', [$id]) : '';
            if ($sys !== '') {
                db_exec('UPDATE devices SET name = ? WHERE id = ?', [mb_substr($sys, 0, 128), $id]);
            }
            $msg = "Device added; discovered $n metrics";
        } catch (Throwable $e) {
            $msg = 'Device added, but discovery failed: ' . $e->getMessage() . '. Check host, credentials/URL and firewall, then rediscover';
            $kind = 'err';
        }
    }
    $st = poll_now($id);
    return ['id' => $id, 'msg' => $msg . ($st ? "; polled, status: $st" : '') . '.', 'kind' => $st === 'down' && $kind === 'ok' ? 'warn' : $kind];
}
