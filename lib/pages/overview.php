<?php
$devices = db_all('SELECT * FROM devices WHERE enabled = 1 ORDER BY name');
$up = count(array_filter($devices, fn($d) => $d['status'] === 'up'));
$down = count(array_filter($devices, fn($d) => $d['status'] === 'down'));
$nAlerts = (int)db_val('SELECT COUNT(*) FROM alerts');
$nMetrics = (int)db_val('SELECT COUNT(*) FROM metrics WHERE enabled = 1');
$events = db_all('SELECT e.*, d.name dname FROM events e LEFT JOIN devices d ON d.id = e.device_id ORDER BY e.id DESC LIMIT 8');

page_start('Overview', 'overview');
?>
<div class="page-head">
  <div><h1>Overview</h1><div class="sub"><?= count($devices) ?> devices · last refresh <?= date('H:i:s') ?></div></div>
  <div class="actions"><a class="btn btn-ghost btn-sm" href="app.php?p=devices">Manage devices</a></div>
</div>
<?= flash() ?>
<div class="stat-row card" style="margin-bottom:20px">
  <div class="stat"><span class="label">Devices up</span><span class="value" style="color:var(--ok)"><?= $up ?><small>/ <?= count($devices) ?></small></span></div>
  <div class="stat"><span class="label">Down</span><span class="value" style="color:<?= $down ? 'var(--crit)' : 'inherit' ?>"><?= $down ?></span></div>
  <div class="stat"><span class="label">Active alerts</span><span class="value" style="color:<?= $nAlerts ? 'var(--warn)' : 'inherit' ?>"><?= $nAlerts ?></span></div>
  <div class="stat"><span class="label">Metrics</span><span class="value"><?= $nMetrics ?></span></div>
</div>

<?php if (!$devices): ?>
  <div class="card empty-state"><?= icon('server') ?><p>No devices yet.</p><a class="btn" href="app.php?p=device_add">Add your first device</a></div>
<?php endif ?>

<div class="grid grid-auto">
<?php foreach ($devices as $d):
    $gauges = $d['status'] === 'up' ? device_summary((int)$d['id']) : [];
    $graph = $d['status'] === 'up' ? device_graph((int)$d['id']) : ['points' => []];
    $pts = $graph['points'];
    $nowVal = $pts ? end($pts) : null;
?>
  <a class="card dev-card <?= $d['status'] === 'down' ? 'is-down' : '' ?>" href="app.php?p=device&id=<?= $d['id'] ?>">
    <div class="top">
      <?= device_badge($d) ?>
      <div style="min-width:0">
        <div class="name"><?= e($d['name']) ?></div>
        <div class="desc"><?= e($d['sysdescr'] ?: $d['host']) ?></div>
      </div>
      <span class="pill pill-<?= e($d['status']) ?>" style="margin-left:auto"><?= e($d['status']) ?></span>
    </div>
    <?php if ($gauges): ?>
      <div class="gauges">
        <?php foreach ($gauges as [$l, $v, $max, $u, $w, $c, $min]): ?><div class="gauge-wrap" style="flex:1;min-width:0;text-align:center"><?= gauge_html($l, $v, $max, $u, $w, $c, (float)($min ?? 0)) ?></div><?php endforeach ?>
      </div>
    <?php elseif ($d['status'] === 'down'): ?>
      <div class="muted small">No response since <?= e(fmt_ago((int)$d['last_poll'])) ?>.</div>
    <?php endif ?>
    <?php if ($pts): ?>
      <div><div class="small muted" style="display:flex;justify-content:space-between"><span><?= e($graph['label']) ?> · 6h</span><span><?= e(fmt_num((float)$nowVal)) ?><?= $graph['zero'] ? '' : ' ' ?><?= e($graph['unit']) ?></span></div><?= sparkline($pts, 260, 36, $graph['zero']) ?></div>
    <?php endif ?>
    <div class="foot"><span>⏱ <?= e(fmt_uptime($d['uptime'] ? (int)$d['uptime'] : null)) ?></span><span>polled <?= e(fmt_ago($d['last_poll'] ? (int)$d['last_poll'] : null)) ?></span></div>
  </a>
<?php endforeach ?>
</div>

<?php if ($events): ?>
<div class="card" style="margin-top:20px">
  <div class="card-head"><h2>Recent events</h2><div class="right"><a class="small" href="app.php?p=alerts">All events →</a></div></div>
  <ul class="events">
  <?php foreach ($events as $ev): ?>
    <li><time><?= date('M j H:i', (int)$ev['ts']) ?></time><span class="pill pill-<?= e($ev['level']) ?>"><?= e($ev['level']) ?></span><span><?= e($ev['message']) ?></span></li>
  <?php endforeach ?>
  </ul>
</div>
<?php endif ?>
<?php
page_end(['gauge.js']);
