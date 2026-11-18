<?php
$id = (int)($_GET['id'] ?? 0);
$d = db_one('SELECT * FROM dashboards WHERE id = ?', [$id]);
if (!$d) {
    redirect('app.php?p=dashboards');
}
page_start($d['name'], 'dashboards');
?>
<div class="page-head">
  <div><h1><?= e($d['name']) ?></h1><div class="sub"><?= $d['public'] ? 'Visible on the public page' : 'Private' ?></div></div>
  <div class="actions">
    <span data-edit-only hidden><button class="btn btn-sm" data-add>+ Panel</button> <button class="btn btn-ghost btn-sm" data-cancel>Cancel</button> <button class="btn btn-sm" data-save>Save layout</button></span>
    <button class="btn btn-ghost btn-sm" data-edit>Edit</button>
  </div>
</div>
<div class="dash" data-dash data-id="<?= $id ?>" data-editable="1" data-start-edit="<?= isset($_GET['edit']) ? 1 : 0 ?>"></div>
<script type="application/json" id="dash-layout"><?= json_encode(json_decode($d['layout_json'], true) ?: [], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<dialog id="panel-dlg">
  <form method="dialog" id="panel-form">
    <h3 style="margin-bottom:14px">Panel</h3>
    <div class="form-grid">
      <div class="field"><label>Type</label><select name="type"><option value="graph">Graph</option><option value="gauge">Gauge</option><option value="stat">Stat</option><option value="status">Device status</option></select></div>
      <div class="field"><label>Title</label><input type="text" name="title" maxlength="80"></div>
      <div class="field"><label>Width (1–12)</label><input type="number" name="w" min="1" max="12" value="6"></div>
      <div class="field"><label>Height (rows)</label><input type="number" name="h" min="1" max="8" value="3"></div>
      <div class="field" data-for="graph"><label>Time range</label><select name="range"><?php foreach (array_keys(RANGES) as $r): ?><option <?= $r === '24h' ? 'selected' : '' ?>><?= $r ?></option><?php endforeach ?></select></div>
      <div class="field" data-for="gauge"><label>Gauge max</label><input type="number" step="any" name="max" placeholder="100"></div>
    </div>
    <div class="field" data-for="metrics"><label>Metrics <span class="muted small">(Ctrl/⌘-click for several)</span></label>
      <input type="text" placeholder="Filter…" data-filter style="margin-bottom:6px">
      <select name="metrics" multiple size="10"></select></div>
    <div class="dialog-actions"><button class="btn btn-ghost" value="cancel" formnovalidate>Cancel</button><button class="btn" value="ok">OK</button></div>
  </form>
</dialog>
<?php
page_end(array_merge(CHART_JS, ['dash.js']));
