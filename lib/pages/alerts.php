<?php
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['act'] ?? '') === 'clear_events') {
    db_exec('DELETE FROM events');
    flash('Event log cleared.');
    redirect('app.php?p=alerts');
}
$alerts = db_all('SELECT a.*, d.name dname, m.label mlabel, m.unit FROM alerts a LEFT JOIN devices d ON d.id = a.device_id LEFT JOIN metrics m ON m.id = a.metric_id ORDER BY a.state = \'crit\' DESC, a.since DESC');
$last = latest_values(array_filter(array_column($alerts, 'metric_id')));
$level = (string)($_GET['level'] ?? '');
$where = in_array($level, ['crit', 'warn', 'ok', 'info'], true) ? 'WHERE e.level = ' . db()->quote($level) : '';
$events = db_all("SELECT e.*, d.name dname FROM events e LEFT JOIN devices d ON d.id = e.device_id $where ORDER BY e.id DESC LIMIT 200");
page_start('Alerts', 'alerts');
?>
<div class="page-head"><div><h1>Alerts</h1><div class="sub"><?= count($alerts) ?> active</div></div></div>
<?= flash() ?>
<div class="card flush" style="margin-bottom:20px">
  <?php if ($alerts): ?>
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>State</th><th>Device</th><th>What</th><th class="num">Value</th><th>Since</th></tr></thead>
    <tbody>
    <?php foreach ($alerts as $a): ?>
      <tr><td><span class="pill pill-<?= e($a['state']) ?>"><?= e($a['state']) ?></span></td>
        <td><a href="app.php?p=device&id=<?= (int)$a['device_id'] ?>"><?= e($a['dname']) ?></a></td>
        <td><?= $a['metric_id'] ? e($a['mlabel']) : 'Device unreachable' ?></td>
        <td class="num"><?= $a['metric_id'] && isset($last[$a['metric_id']]) ? e(fmt_unit($last[$a['metric_id']], $a['unit'])) : '—' ?></td>
        <td><?= date('M j H:i', (int)$a['since']) ?> <span class="muted small">(<?= e(fmt_ago((int)$a['since'])) ?>)</span></td></tr>
    <?php endforeach ?>
    </tbody></table></div>
  <?php else: ?><div class="empty-state"><?= icon('bell') ?><p>All calm. No active alerts.</p></div><?php endif ?>
</div>
<div class="card">
  <div class="card-head"><h2>Event log</h2>
    <div class="right">
      <div class="seg"><?php foreach (['' => 'All', 'crit' => 'Crit', 'warn' => 'Warn', 'ok' => 'OK', 'info' => 'Info'] as $k => $l): ?><a href="app.php?p=alerts&level=<?= $k ?>" class="<?= $level === $k ? 'active' : '' ?>"><?= $l ?></a><?php endforeach ?></div>
      <form method="post" data-confirm="Clear the whole event log?" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="clear_events"><button class="btn btn-ghost btn-sm">Clear</button></form>
    </div></div>
  <ul class="events">
  <?php foreach ($events as $ev): ?>
    <li><time><?= date('M j H:i:s', (int)$ev['ts']) ?></time><span class="pill pill-<?= e($ev['level']) ?>"><?= e($ev['level']) ?></span><span><?= e($ev['message']) ?></span></li>
  <?php endforeach ?>
  <?php if (!$events): ?><li class="muted">No events.</li><?php endif ?>
  </ul>
</div>
<?php
page_end();
