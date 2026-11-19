<?php
$id = (int)($_GET['id'] ?? 0);
$d = db_one('SELECT * FROM dashboards WHERE id = ?', [$id]);
if (!$d) {
    redirect('app.php?p=dashboards');
}
page_start($d['name'], 'dashboards');
?>
<div class="page-head">
  <hgroup><h1><?= e($d['name']) ?></h1><p><?= $d['public'] ? 'Visible on the public page' : 'Private' ?></p></hgroup>
  <div class="actions">
    <span class="actions" data-edit-only hidden><button class="btn btn-ghost btn-sm" data-add>Add panel</button><button class="btn btn-ghost btn-sm" data-cancel>Cancel</button><button class="btn btn-sm" data-save>Save layout</button></span>
    <button class="btn btn-ghost btn-sm" data-edit>Edit</button>
  </div>
</div>
<div class="dash" data-dash data-id="<?= $id ?>" data-editable="1" data-start-edit="<?= isset($_GET['edit']) ? 1 : 0 ?>"></div>
<script type="application/json" id="dash-layout"><?= json_encode(json_decode($d['layout_json'], true) ?: [], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<dialog id="panel-dlg">
  <form method="dialog" id="panel-form">
    <h2>Panel</h2>
    <div class="form-grid">
      <div class="field"><label>Type
        <select name="type"><option value="graph">Graph</option><option value="gauge">Gauge</option><option value="stat">Stat</option><option value="status">Device status</option></select>
        <small>What the panel draws.</small></label></div>
      <div class="field"><label>Title
        <input type="text" name="title" maxlength="80" placeholder="Traffic">
        <small>Optional. Shown above the panel.</small></label></div>
      <div class="field"><label>Width (columns)
        <input type="number" name="w" min="1" max="12" value="6"><small>1 to 12.</small></label></div>
      <div class="field"><label>Height (rows)
        <input type="number" name="h" min="1" max="8" value="3">
        <small>1 to 8; a row is 80 px.</small></label></div>
      <div class="field" data-for="graph"><label>Time range
        <select name="range"><?php foreach (array_keys(RANGES) as $r): ?><option <?= $r === '24h' ? 'selected' : '' ?>><?= $r ?></option><?php endforeach ?></select>
        <small>How far back the graph goes.</small></label></div>
      <div class="field" data-for="gauge"><label>Gauge max
        <input type="number" step="any" name="max" placeholder="100">
        <small>Optional. Left empty, 100.</small></label></div>
    </div>
    <div class="field" data-for="metrics"><label>Metrics
      <input type="text" placeholder="Filter" data-filter aria-label="Filter metrics">
      <select name="metrics" multiple size="10"></select>
      <small>Ctrl- or ⌘-click to pick several.</small></label></div>
    <footer><button class="btn btn-ghost" value="cancel" formnovalidate>Cancel</button><button class="btn" value="ok">OK</button></footer>
  </form>
</dialog>
<?php
page_end(array_merge(CHART_JS, ['dash.js']));
