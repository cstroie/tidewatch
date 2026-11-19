<?php
$id = (int)($_GET['id'] ?? 0);
$d = db_one('SELECT * FROM dashboards WHERE id = ?', [$id]);
if (!$d) {
    redirect('app.php?p=dashboards');
}
page_start($d['name'], 'dashboards');
?>
<div class="page-head">
  <hgroup><h1><?= e($d['name']) ?></h1><p><?= $d['public'] ? 'Visible on the public page' : 'Private' ?> · <span data-save-state>Changes save automatically</span></p></hgroup>
  <div class="actions">
    <button class="btn btn-ghost btn-sm" data-undo disabled>Undo</button>
    <button class="btn btn-ghost btn-sm" data-starter>Add from device</button>
    <button class="btn btn-sm" data-add>Add panel</button>
  </div>
</div>
<div class="dash" data-dash data-id="<?= $id ?>" data-editable="1" data-start-add="<?= isset($_GET['edit']) ? 1 : 0 ?>"></div>
<script type="application/json" id="dash-layout"><?= json_encode(json_decode($d['layout_json'], true) ?: [], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<dialog id="panel-dlg" class="panel-dlg">
  <form method="dialog" id="panel-form">
    <h2 data-dlg-title>Add panel</h2>
    <div class="form-grid">
      <div class="field"><label>Device
        <select data-device><option value="">All devices</option></select>
        <small>Narrows the list below; picks from other devices are kept.</small></label></div>
      <div class="field"><label>Search
        <input type="search" data-filter placeholder="cpu, eth0, toner">
        <small>Matches the label and the kind.</small></label></div>
    </div>
    <div class="field" data-for="metrics">
      <div class="metric-list" data-list role="group" aria-label="Metrics"></div>
      <small class="muted" data-picked>Nothing selected.</small>
    </div>
    <div class="field"><span class="field-label">Type</span>
      <div class="seg seg-radio" role="radiogroup" aria-label="Type">
        <?php foreach (['graph' => 'Graph', 'gauge' => 'Gauge', 'stat' => 'Stat', 'status' => 'Device status'] as $k => $l): ?><label><input type="radio" name="type" value="<?= $k ?>"><span><?= $l ?></span></label><?php endforeach ?>
      </div>
      <small class="muted" data-type-help>Chosen from the metrics you tick; change it if you like.</small></div>
    <div class="form-grid">
      <div class="field"><span class="field-label">Width</span>
        <div class="seg seg-radio" role="radiogroup" aria-label="Width">
          <?php foreach ([3 => 'Small', 6 => 'Half', 9 => 'Wide', 12 => 'Full'] as $k => $l): ?><label><input type="radio" name="w" value="<?= $k ?>"><span><?= $l ?></span></label><?php endforeach ?>
        </div></div>
      <div class="field"><span class="field-label">Height</span>
        <div class="seg seg-radio" role="radiogroup" aria-label="Height">
          <?php foreach ([2 => 'Short', 3 => 'Medium', 5 => 'Tall'] as $k => $l): ?><label><input type="radio" name="h" value="<?= $k ?>"><span><?= $l ?></span></label><?php endforeach ?>
        </div></div>
    </div>
    <div class="form-grid">
      <div class="field"><label>Title
        <input type="text" name="title" maxlength="80" placeholder="Traffic">
        <small>Optional. Left empty, it is named after the metrics.</small></label></div>
      <div class="field" data-for="graph"><label>Time range
        <select name="range"><?php foreach (array_keys(RANGES) as $r): ?><option <?= $r === '24h' ? 'selected' : '' ?>><?= $r ?></option><?php endforeach ?></select>
        <small>How far back the graph goes.</small></label></div>
      <div class="field" data-for="gauge"><label>Gauge max
        <input type="number" step="any" name="max" placeholder="100">
        <small>Optional. Left empty, 100.</small></label></div>
    </div>
    <footer><button class="btn btn-ghost" value="cancel" formnovalidate>Cancel</button><button class="btn" value="ok" data-ok>Add panel</button></footer>
  </form>
</dialog>

<dialog id="starter-dlg">
  <form method="dialog">
    <h2>Add from device</h2>
    <div class="field"><label>Device
      <select name="device" data-starter-device></select>
      <small>Adds its CPU, memory, supplies, traffic and storage panels at the end.</small></label></div>
    <footer><button class="btn btn-ghost" value="cancel" formnovalidate>Cancel</button><button class="btn" value="ok">Add panels</button></footer>
  </form>
</dialog>
<?php
page_end(array_merge(CHART_JS, ['dash.js']));
