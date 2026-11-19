<?php
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    if ($act === 'create') {
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 128) ?: 'New dashboard';
        $layout = [];
        if (!empty($_POST['device'])) {
            $layout = starter_layout((int)$_POST['device']);
        }
        db_exec('INSERT INTO dashboards (name, public, layout_json, sort) VALUES (?, 0, ?, (SELECT COALESCE(MAX(sort), 0) + 1 FROM dashboards))', [$name, json_encode($layout)]);
        redirect('app.php?p=dashboard&id=' . db()->lastInsertId() . (empty($layout) ? '&edit=1' : ''));
    }
    if ($act === 'update' && $id) {
        db_exec('UPDATE dashboards SET name = ?, public = ? WHERE id = ?', [mb_substr(trim((string)$_POST['name']), 0, 128) ?: 'Dashboard', empty($_POST['public']) ? 0 : 1, $id]);
        flash('Dashboard updated.');
        redirect('app.php?p=dashboards');
    }
    if ($act === 'delete' && $id) {
        db_exec('DELETE FROM dashboards WHERE id = ?', [$id]);
        flash('Dashboard deleted.');
        redirect('app.php?p=dashboards');
    }
}

$dash = db_all('SELECT * FROM dashboards ORDER BY sort, name');
$devs = db_all('SELECT id, name FROM devices ORDER BY name');
page_start('Dashboards', 'dashboards');
?>
<div class="page-head"><hgroup><h1>Dashboards</h1><p>Arrange graphs, gauges and stats; share one on the public page if you like.</p></hgroup></div>
<?= flash() ?>
<?php if (!$dash): ?><p class="empty mb">No dashboards yet. Create one below.</p><?php endif ?>
<div class="grid grid-auto mb" data-sortable="dashboard_order">
<?php foreach ($dash as $d): $n = count(json_decode($d['layout_json'], true) ?: []); ?>
  <div class="card" data-id="<?= $d['id'] ?>">
    <header class="card-head"><span class="grip" data-handle title="Drag to reorder" aria-hidden="true">⠿</span><hgroup><h2><a href="app.php?p=dashboard&id=<?= $d['id'] ?>"><?= e($d['name']) ?></a></h2><p><?= $n ?> panel<?= $n === 1 ? '' : 's' ?></p></hgroup>
      <?php if ($d['public']): ?><div class="actions"><span class="pill pill-accent">public</span></div><?php endif ?></header>
    <form method="post" class="inline-form">
      <?= csrf_field() ?><input type="hidden" name="act" value="update"><input type="hidden" name="id" value="<?= $d['id'] ?>">
      <input type="text" class="input-sm" name="name" value="<?= e($d['name']) ?>" aria-label="Name">
      <label class="check"><input type="checkbox" name="public" value="1" <?= $d['public'] ? 'checked' : '' ?>> Public</label>
      <button class="btn btn-ghost btn-danger btn-sm" form="deld<?= $d['id'] ?>">Delete</button>
      <button class="btn btn-sm">Save</button>
    </form>
    <form id="deld<?= $d['id'] ?>" method="post" data-confirm="Delete dashboard “<?= e($d['name']) ?>”?"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= $d['id'] ?>"></form>
  </div>
<?php endforeach ?>
</div>
<form method="post" class="card narrow">
  <?= csrf_field() ?><input type="hidden" name="act" value="create">
  <header class="card-head"><hgroup><h2>New dashboard</h2></hgroup></header>
  <div class="form-grid">
    <div class="field"><label>Name
      <input type="text" name="name" required placeholder="Lab overview">
      <small>You can rename it later.</small></label></div>
    <div class="field"><label>Start from
      <select name="device"><option value="">Empty</option><?php foreach ($devs as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach ?></select>
      <small>Optional. A device fills it with its CPU, memory and traffic.</small></label></div>
  </div>
  <footer><button class="btn">Create</button></footer>
</form>
<?php
page_end(['sort.js']);
