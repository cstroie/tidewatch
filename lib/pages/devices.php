<?php
require_once __DIR__ . '/device_form.php';
require_once dirname(__DIR__) . '/devices.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    if ($act === 'save') {
        $r = device_save($_POST);
        flash($r['msg'], $r['kind']);
        if (!$r['id']) {
            redirect($id ? "app.php?p=device&id=$id&tab=settings" : 'app.php?p=device_add');
        }
        redirect($id ? "app.php?p=device&id=$id&tab=settings" : "app.php?p=device&id={$r['id']}&tab=metrics");
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
<div class="page-head"><hgroup><h1>Devices</h1><p><?= count($devices) ?> configured</p></hgroup>
  <div class="actions"><a class="btn btn-sm" href="app.php?p=device_add">Add device</a></div></div>
<?= flash() ?>
<div class="card flush mb">
<?php if ($devices): ?><div class="table-wrap"><table class="tbl">
  <thead><tr><th></th><th>Name</th><th>Host</th><th>Type</th><th class="num">Metrics</th><th>Uptime</th><th>Last poll</th><th>Public</th></tr></thead>
  <tbody>
  <?php foreach ($devices as $d): ?>
    <tr class="<?= $d['enabled'] ? '' : 'is-disabled' ?>">
      <td><?= device_badge($d['enabled'] ? $d : ['status' => 'unknown'] + $d, 'sm') ?></td>
      <td><a href="app.php?p=device&id=<?= $d['id'] ?>"><b><?= e($d['name']) ?></b></a><div class="small muted desc"><?= e($d['sysdescr']) ?></div><?php if (!empty($d['syslocation'])): ?><div class="small muted"><?= e($d['syslocation']) ?></div><?php endif ?></td>
      <td class="mono"><?= e($d['host']) ?><?= (int)$d['port'] !== (($d['type'] ?? 'snmp') === 'prom' ? 80 : 161) ? ':' . (int)$d['port'] : '' ?></td>
      <td><?= ($d['type'] ?? 'snmp') === 'prom' ? 'Prometheus' : 'v' . e($d['version']) ?></td>
      <td class="num"><?= (int)$d['nm'] ?></td>
      <td><?= e(fmt_uptime($d['uptime'] ? (int)$d['uptime'] : null)) ?></td>
      <td><?= e(fmt_ago($d['last_poll'] ? (int)$d['last_poll'] : null)) ?></td>
      <td><?= $d['public'] ? '<span class="pill pill-accent">public</span>' : '' ?></td>
    </tr>
  <?php endforeach ?>
  </tbody></table></div>
<?php else: ?><p class="empty">No devices yet. <a href="app.php?p=device_add">Add one</a>.</p><?php endif ?>
</div>
<?php
page_end();
