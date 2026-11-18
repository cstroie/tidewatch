<?php
require_once __DIR__ . '/device_form.php';
require_once dirname(__DIR__) . '/metrics.php';

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

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    if ($act === 'save') {
        $f = fn($k, $d = null) => isset($_POST[$k]) && trim((string)$_POST[$k]) !== '' ? trim((string)$_POST[$k]) : $d;
        $name = mb_substr((string)$f('name', ''), 0, 128);
        $host = (string)$f('host', '');
        if (!preg_match('/^[A-Za-z0-9.:\-_]+$/', $host)) {
            flash('A valid host/IP is required.', 'err');
            redirect($id ? "app.php?p=device&id=$id&tab=settings" : 'app.php?p=device_add');
        }
        $prom = $id ? db_val('SELECT type FROM devices WHERE id = ?', [$id]) === 'prom' : ($_POST['type'] ?? '') === 'prom';
        $autoName = $name === ''; // blank name: use sysName once polled/discovered, host until then
        if ($autoName) {
            $name = $id ? (string)db_val('SELECT COALESCE(NULLIF(sysname, \'\'), ?) FROM devices WHERE id = ?', [$host, $id]) : $host;
        }
        $path = '/' . ltrim((string)$f('path', '/metrics'), '/');
        if ($prom && (preg_match('#[\s"<>]#', $path) || strlen($path) > 255)) {
            flash('Invalid metrics path.', 'err');
            redirect($id ? "app.php?p=device&id=$id&tab=settings" : 'app.php?p=device_add');
        }
        $row = [
            'type' => $prom ? 'prom' : 'snmp', 'scheme' => ($_POST['scheme'] ?? '') === 'https' ? 'https' : 'http', 'path' => $path,
            'name' => $name, 'host' => $host, 'port' => max(1, min(65535, (int)$f('port', $prom ? 80 : 161))),
            'version' => in_array($_POST['version'] ?? '', ['1', '2c', '3'], true) ? $_POST['version'] : '2c',
            'community' => $f('community', 'public'),
            'v3_user' => $f('v3_user'), 'v3_level' => $f('v3_level', 'authPriv'),
            'v3_auth_proto' => $f('v3_auth_proto', 'SHA'), 'v3_priv_proto' => $f('v3_priv_proto', 'AES'),
            'poll_interval' => max(60, (int)$f('poll_interval', $prom ? 300 : 60)),
            'enabled' => empty($_POST['enabled']) ? 0 : 1, 'public' => empty($_POST['public']) ? 0 : 1,
            'icon' => isset(DEVICE_ICONS[$_POST['icon'] ?? '']) ? $_POST['icon'] : null,
        ];
        // keep stored passphrases when left blank
        foreach (['v3_auth_pass', 'v3_priv_pass'] as $k) {
            if ($f($k) !== null) {
                $row[$k] = $f($k);
            }
        }
        if ($id) {
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($row)));
            db_exec("UPDATE devices SET $set WHERE id = ?", [...array_values($row), $id]);
            $st = poll_now($id);
            flash('Device saved' . ($st ? "; polled, status: $st" : '') . '.', $st === 'down' ? 'err' : 'ok');
            redirect("app.php?p=device&id=$id&tab=settings");
        }
        db_exec('INSERT INTO devices (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')', array_values($row));
        $id = (int)db()->lastInsertId();
        if (!empty($_POST['discover'])) {
            try {
                $n = discover_device($id);
                $sys = $autoName ? (string)db_val('SELECT sysname FROM devices WHERE id = ?', [$id]) : '';
                if ($sys !== '') {
                    db_exec('UPDATE devices SET name = ? WHERE id = ?', [mb_substr($sys, 0, 128), $id]);
                }
                $msg = "Device added; discovered $n metrics";
                $type = 'ok';
            } catch (Throwable $e) {
                $msg = 'Device added, but discovery failed: ' . $e->getMessage() . '. Check host, credentials/URL and firewall, then rediscover';
                $type = 'err';
            }
        } else {
            $msg = 'Device added';
            $type = 'ok';
        }
        $st = poll_now($id);
        flash($msg . ($st ? "; polled, status: $st" : '') . '.', $st === 'down' ? 'err' : $type);
        redirect("app.php?p=device&id=$id&tab=metrics");
    }
    if ($act === 'delete' && $id) {
        $mids = array_column(db_all('SELECT id FROM metrics WHERE device_id = ?', [$id]), 'id');
        foreach (['samples_raw', 'samples_5m', 'samples_1h', 'last_counter', 'alerts'] as $t) {
            db_exec("DELETE FROM $t WHERE metric_id IN (" . in_list($mids) . ')');
        }
        db_exec('DELETE FROM alerts WHERE device_id = ?', [$id]);
        db_exec('DELETE FROM metrics WHERE device_id = ?', [$id]);
        db_exec('DELETE FROM devices WHERE id = ?', [$id]);
        flash('Device deleted.');
        redirect('app.php?p=devices');
    }
}

$devices = db_all('SELECT d.*, (SELECT COUNT(*) FROM metrics m WHERE m.device_id = d.id) nm FROM devices d ORDER BY d.name');
page_start('Devices', 'devices');
?>
<div class="page-head"><div><h1>Devices</h1><div class="sub"><?= count($devices) ?> configured</div></div>
  <div class="actions"><a class="btn btn-sm" href="app.php?p=device_add">+ Add device</a></div></div>
<?= flash() ?>
<div class="card flush" style="margin-bottom:20px"><div class="table-wrap"><table class="tbl">
  <thead><tr><th></th><th>Name</th><th>Host</th><th>Type</th><th class="num">Metrics</th><th>Uptime</th><th>Last poll</th><th>Public</th></tr></thead>
  <tbody>
  <?php foreach ($devices as $d): ?>
    <tr style="<?= $d['enabled'] ? '' : 'opacity:.5' ?>">
      <td><?= device_badge($d['enabled'] ? $d : ['status' => 'unknown'] + $d, 'sm') ?></td>
      <td><a href="app.php?p=device&id=<?= $d['id'] ?>"><b><?= e($d['name']) ?></b></a><div class="small muted" style="max-width:320px;overflow:hidden;text-overflow:ellipsis"><?= e($d['sysdescr']) ?></div><?php if (!empty($d['syslocation'])): ?><div class="small muted"><?= e($d['syslocation']) ?></div><?php endif ?></td>
      <td class="mono"><?= e($d['host']) ?><?= (int)$d['port'] !== (($d['type'] ?? 'snmp') === 'prom' ? 80 : 161) ? ':' . (int)$d['port'] : '' ?></td>
      <td><?= ($d['type'] ?? 'snmp') === 'prom' ? 'Prometheus' : 'v' . e($d['version']) ?></td>
      <td class="num"><?= (int)$d['nm'] ?></td>
      <td><?= e(fmt_uptime($d['uptime'] ? (int)$d['uptime'] : null)) ?></td>
      <td><?= e(fmt_ago($d['last_poll'] ? (int)$d['last_poll'] : null)) ?></td>
      <td><?= $d['public'] ? '<span class="pill pill-accent">public</span>' : '' ?></td>
    </tr>
  <?php endforeach ?>
  <?php if (!$devices): ?><tr><td colspan="8" class="muted" style="text-align:center;padding:30px">No devices yet. <a href="app.php?p=device_add">Add one</a>.</td></tr><?php endif ?>
  </tbody></table></div></div>
<?php
page_end();
