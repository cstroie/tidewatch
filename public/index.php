<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/view.php';
require dirname(__DIR__) . '/lib/data.php';

// Public page: no hosts, IPs, descriptions or configuration are shown here.
$devices = db_all('SELECT id, name, status, last_poll, uptime, icon, sysdescr FROM devices WHERE enabled = 1 AND public = 1 ORDER BY name');
$dashboards = db_all('SELECT id, name, layout_json FROM dashboards WHERE public = 1 ORDER BY name');
$dashId = (int)($_GET['d'] ?? 0);
$dash = null;
foreach ($dashboards as $d) {
    if ((int)$d['id'] === $dashId) {
        $dash = $d;
    }
}
$down = array_filter($devices, fn($d) => $d['status'] === 'down');
$pubIds = array_column($devices, 'id');
$warn = $pubIds ? (int)db_val('SELECT COUNT(*) FROM alerts WHERE metric_id IS NOT NULL AND device_id IN (' . in_list($pubIds) . ')') : 0;

header('Refresh: 60');
page_start($dash ? $dash['name'] : 'Status', '', true);

if ($dash): ?>
  <div class="page-head"><div><h1><?= e($dash['name']) ?></h1><div class="sub"><a href="index.php">← Status</a></div></div></div>
  <div class="dash" data-dash data-id="<?= (int)$dash['id'] ?>"></div>
  <script type="application/json" id="dash-layout"><?= json_encode(json_decode($dash['layout_json'], true) ?: [], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php
    page_end(array_merge(CHART_JS, ['dash.js']));
    exit;
endif;
?>
<div class="hero <?= !$devices ? 'is-empty' : ($down ? 'is-bad' : ($warn ? 'is-warn' : '')) ?>">
  <?= $down ? icon('bell') : logo_svg() ?>
  <div>
    <h1><?= !$devices ? e(config('app_name')) . ' status' : ($down ? count($down) . ' of ' . count($devices) . ' systems down' : ($warn ? 'All systems up, ' . $warn . ' warning' . ($warn > 1 ? 's' : '') : 'All systems operational')) ?></h1>
    <p>Updated <?= date('H:i') ?> · refreshes every minute</p>
  </div>
</div>

<?php if ($devices): ?>
<div class="grid grid-tiles" style="margin-bottom:24px">
  <?php foreach ($devices as $d): ?>
    <div class="tile <?= $d['status'] === 'down' ? 'is-down' : '' ?>">
      <?= device_badge($d, 'sm') ?>
      <div style="min-width:0"><div class="t-name"><?= e($d['name']) ?></div>
        <div class="t-sub"><?= $d['status'] === 'up' ? 'up ' . e(fmt_uptime($d['uptime'] ? (int)$d['uptime'] : null)) : e($d['status']) . ($d['last_poll'] ? ' · ' . e(fmt_ago((int)$d['last_poll'])) : '') ?></div></div>
    </div>
  <?php endforeach ?>
</div>
<?php else: ?>
  <div class="card empty-state">No public devices yet. Tick “Show on public status page” in a device’s settings.</div>
<?php endif ?>

<?php if ($dashboards): ?>
  <h2>Dashboards</h2>
  <div class="grid grid-auto">
    <?php foreach ($dashboards as $d): ?>
      <a class="card dev-card" href="index.php?d=<?= (int)$d['id'] ?>"><div class="top"><?= icon('layout') ?><div class="name"><?= e($d['name']) ?></div></div></a>
    <?php endforeach ?>
  </div>
<?php endif ?>
<?php
page_end();
