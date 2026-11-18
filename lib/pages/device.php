<?php
$id = (int)($_GET['id'] ?? 0);
$dev = db_one('SELECT * FROM devices WHERE id = ?', [$id]);
if (!$dev) {
    redirect('app.php?p=devices');
}
$tab = (string)($_GET['tab'] ?? 'system');
$self = "app.php?p=device&id=$id";

// ---- actions ----
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    if ($act === 'metrics') {
        $st = db()->prepare('UPDATE metrics SET label = ?, warn = ?, crit = ?, enabled = ? WHERE id = ? AND device_id = ?');
        foreach ((array)($_POST['m'] ?? []) as $mid => $f) {
            $num = fn($v) => is_numeric($v) ? (float)$v : null;
            $st->execute([mb_substr(trim((string)($f['label'] ?? '')), 0, 120) ?: 'metric', $num($f['warn'] ?? ''), $num($f['crit'] ?? ''), empty($f['enabled']) ? 0 : 1, (int)$mid, $id]);
        }
        flash('Metrics saved.');
        redirect("$self&tab=metrics");
    }
    if ($act === 'custom') {
        $oid = oid_norm((string)($_POST['oid'] ?? ''));
        if (!preg_match('/^\d+(\.\d+)+$/', $oid)) {
            flash('OID must be numeric, e.g. 1.3.6.1.4.1.2021.4.6.0', 'err');
            redirect("$self&tab=metrics");
        }
        $type = in_array($_POST['type'] ?? '', ['gauge', 'counter'], true) ? $_POST['type'] : 'gauge';
        $try = snmp_open($dev)->get([$oid]);
        if (!isset($try[$oid])) {
            flash("The device returned nothing for $oid.", 'err');
            redirect("$self&tab=metrics");
        }
        try {
            db_exec('INSERT INTO metrics (device_id, kind, oid, label, unit, type, scale) VALUES (?,?,?,?,?,?,?)',
                [$id, 'custom', $oid, trim((string)$_POST['label']) ?: $oid, trim((string)($_POST['unit'] ?? '')) ?: null, $type, is_numeric($_POST['scale'] ?? '') ? (float)$_POST['scale'] : 1]);
            flash('Custom metric added (current value: ' . $try[$oid] . ').');
        } catch (PDOException $e) {
            flash('That OID is already monitored on this device.', 'err');
        }
        redirect("$self&tab=metrics");
    }
    if ($act === 'delete_metric') {
        db_exec('DELETE FROM metrics WHERE id = ? AND device_id = ?', [(int)$_POST['mid'], $id]);
        foreach (['samples_raw', 'samples_5m', 'samples_1h'] as $t) {
            db_exec("DELETE FROM $t WHERE metric_id = ?", [(int)$_POST['mid']]);
        }
        flash('Metric deleted.');
        redirect("$self&tab=metrics");
    }
    if ($act === 'discover') {
        try {
            flash('Discovery added ' . discover_device($id) . ' new metrics.');
        } catch (Throwable $e) {
            flash('Discovery failed: ' . $e->getMessage(), 'err');
        }
        redirect("$self&tab=metrics");
    }
}

$metrics = db_all('SELECT * FROM metrics WHERE device_id = ? ORDER BY kind, id', [$id]);
$byKind = [];
foreach ($metrics as $m) {
    $byKind[$m['kind']][] = $m;
}
$last = latest_values(array_column($metrics, 'id'));
$en = fn($k) => array_values(array_filter($byKind[$k] ?? [], fn($m) => $m['enabled']));
$ids = fn(array $ms) => implode(',', array_column($ms, 'id'));

$tabs = ['system' => 'System', 'interfaces' => 'Interfaces', 'sensors' => 'Sensors & supplies', 'metrics' => 'Metrics', 'settings' => 'Settings'];
page_start($dev['name'], 'devices');
?>
<div class="page-head">
  <?= device_badge($dev, 'lg') ?>
  <div style="min-width:0"><h1><?= e($dev['name']) ?></h1><div class="sub"><?= e($dev['host']) ?> · SNMP v<?= e($dev['version']) ?> · up <?= e(fmt_uptime($dev['uptime'] ? (int)$dev['uptime'] : null)) ?> · polled <?= e(fmt_ago($dev['last_poll'] ? (int)$dev['last_poll'] : null)) ?></div></div>
  <div class="actions"><?= in_array($tab, ['system', 'interfaces', 'sensors'], true) ? range_picker() : '' ?></div>
</div>
<?= flash() ?>
<nav class="tabs"><?php foreach ($tabs as $k => $l): ?><a href="<?= $self ?>&tab=<?= $k ?>" class="<?= $k === $tab ? 'active' : '' ?>"><?= $l ?></a><?php endforeach ?></nav>

<?php if ($tab === 'system'):
    $cpu = $en('cpu'); $load = $en('load'); $mem = $en('mem'); $sto = $en('storage');
    $gauge = array_merge($mem, $sto);
?>
  <?php if ($dev['sysdescr']): ?><p class="muted small" style="margin-top:-6px"><?= e($dev['sysdescr']) ?></p><?php endif ?>
  <?php if ($cpu || $gauge): ?>
  <div class="card" style="margin-bottom:16px">
    <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(110px,1fr));text-align:center">
      <?php if ($cpu):
          $vals = array_filter(array_map(fn($m) => $last[$m['id']] ?? null, $cpu), fn($v) => $v !== null); ?>
        <div><?= gauge_html('CPU avg', $vals ? array_sum($vals) / count($vals) : null, 100, '%', 80, 95) ?></div>
      <?php endif ?>
      <?php foreach ($gauge as $m): ?><div><?= gauge_html($m['label'], $last[$m['id']] ?? null, 100, '%', $m['warn'], $m['crit']) ?></div><?php endforeach ?>
    </div>
  </div>
  <?php endif ?>
  <div class="grid grid-2">
    <?php if ($cpu): ?><div class="card"><div class="card-head"><h3>CPU</h3></div><div class="chart" data-metrics="<?= $ids($cpu) ?>"></div></div><?php endif ?>
    <?php if ($load): ?><div class="card"><div class="card-head"><h3>Load average</h3></div><div class="chart" data-metrics="<?= $ids($load) ?>"></div></div><?php endif ?>
    <?php if ($mem): ?><div class="card"><div class="card-head"><h3>Memory</h3></div><div class="chart" data-metrics="<?= $ids($mem) ?>"></div></div><?php endif ?>
    <?php if ($sto): ?><div class="card"><div class="card-head"><h3>Storage</h3></div><div class="chart" data-metrics="<?= $ids(array_slice($sto, 0, 6)) ?>"></div></div><?php endif ?>
    <?php if ($c = $en('custom')): ?><div class="card"><div class="card-head"><h3>Custom</h3></div><div class="chart" data-metrics="<?= $ids(array_slice($c, 0, 6)) ?>"></div></div><?php endif ?>
  </div>
  <?php if (!$cpu && !$mem && !$sto && !$en('custom')): ?><div class="card empty-state">No system metrics for this device. Check the <a href="<?= $self ?>&tab=interfaces">interfaces</a> or <a href="<?= $self ?>&tab=sensors">sensors</a> tabs.</div><?php endif ?>

<?php elseif ($tab === 'interfaces'):
    $ifs = [];
    foreach ($metrics as $m) {
        if (str_starts_with($m['kind'], 'if_')) {
            $name = preg_replace('/ (in|out|status|in errors|out errors)$/', '', $m['label']);
            $idx = substr($m['oid'], strrpos($m['oid'], '.') + 1);
            $ifs[$idx]['name'] ??= $name;
            $ifs[$idx][$m['kind'] . (str_contains($m['label'], 'out errors') ? '_out' : '')] = $m;
        }
    }
?>
  <div class="card flush" style="margin-bottom:16px"><div class="table-wrap"><table class="tbl">
    <thead><tr><th>Interface</th><th>Status</th><th class="num">In</th><th class="num">Out</th><th class="num">Errors in/out</th></tr></thead>
    <tbody>
    <?php foreach ($ifs as $i):
        $st = isset($i['if_status']) ? ($last[$i['if_status']['id']] ?? null) : null;
        $lbl = [1 => 'up', 2 => 'down', 3 => 'testing', 5 => 'dormant', 7 => 'lowerLayerDown'][(int)$st] ?? '—';
        $v = fn($k) => isset($i[$k], $last[$i[$k]['id']]) ? fmt_num($last[$i[$k]['id']]) : '—';
    ?>
      <tr><td><?= e($i['name']) ?></td><td><span class="pill pill-<?= $lbl === 'up' ? 'up' : ($lbl === 'down' ? 'down' : '') ?>"><?= $lbl ?></span></td>
        <td class="num"><?= $v('if_in') ?>bps</td><td class="num"><?= $v('if_out') ?>bps</td><td class="num"><?= $v('if_err') ?> / <?= $v('if_err_out') ?></td></tr>
    <?php endforeach ?>
    </tbody></table></div></div>
  <div class="grid grid-2">
  <?php foreach ($ifs as $i): if (empty($i['if_in']['enabled']) && empty($i['if_out']['enabled'])) continue; ?>
    <div class="card"><div class="card-head"><h3><?= e($i['name']) ?></h3></div>
      <div class="chart" data-metrics="<?= $ids(array_filter([$i['if_in'] ?? null, $i['if_out'] ?? null])) ?>"></div></div>
  <?php endforeach ?>
  </div>
  <?php if (!$ifs): ?><div class="card empty-state">No interfaces discovered.</div><?php endif ?>

<?php elseif ($tab === 'sensors'):
    $sens = $en('sensor'); $sup = $en('supply'); $pages = $en('pages');
    $groups = [];
    foreach ($sens as $m) { $groups[$m['unit'] ?: '—'][] = $m; }
?>
  <?php if ($sup || $sens): ?>
  <div class="card" style="margin-bottom:16px">
    <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(110px,1fr));text-align:center">
      <?php foreach ($sup as $m): ?><div><?= gauge_html($m['label'], $last[$m['id']] ?? null, 100, '%', $m['warn'], $m['crit']) ?></div><?php endforeach ?>
      <?php foreach (array_slice($sens, 0, 12) as $m): ?><div><?= gauge_html($m['label'], $last[$m['id']] ?? null, $m['unit'] === '°C' ? 100 : max(1, ($last[$m['id']] ?? 1) * 1.5), $m['unit'], $m['warn'], $m['crit']) ?></div><?php endforeach ?>
    </div>
  </div>
  <?php endif ?>
  <div class="grid grid-2">
    <?php foreach ($groups as $u => $ms): ?><div class="card"><div class="card-head"><h3>Sensors · <?= e($u) ?></h3></div><div class="chart" data-metrics="<?= $ids(array_slice($ms, 0, 6)) ?>"></div></div><?php endforeach ?>
    <?php if ($sup): ?><div class="card"><div class="card-head"><h3>Supplies</h3></div><div class="chart" data-metrics="<?= $ids($sup) ?>"></div></div><?php endif ?>
    <?php if ($pages): ?><div class="card"><div class="card-head"><h3>Pages printed</h3><div class="right"><b><?= e(number_format((float)($last[$pages[0]['id']] ?? 0))) ?></b></div></div><div class="chart" data-metrics="<?= $ids($pages) ?>"></div></div><?php endif ?>
  </div>
  <?php if (!$sens && !$sup && !$pages): ?><div class="card empty-state">No sensors or supplies discovered on this device.</div><?php endif ?>

<?php elseif ($tab === 'metrics'): ?>
  <form method="post" class="card flush" style="margin-bottom:16px">
    <?= csrf_field() ?><input type="hidden" name="act" value="metrics">
    <div class="card-head" style="padding:14px 16px 0"><h3>All metrics</h3><div class="right"><span class="small muted">If warn &gt; crit, lower values are worse (e.g. toner).</span><button class="btn btn-sm">Save</button></div></div>
    <div class="table-wrap"><table class="tbl">
      <thead><tr><th>On</th><th>Kind</th><th>Label</th><th class="num">Last</th><th>Warn</th><th>Crit</th><th>OID</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($metrics as $m): $f = "m[{$m['id']}]"; ?>
        <tr>
          <td><input type="checkbox" class="check" name="<?= $f ?>[enabled]" value="1" <?= $m['enabled'] ? 'checked' : '' ?>></td>
          <td><span class="pill"><?= e($m['kind']) ?></span></td>
          <td><input type="text" name="<?= $f ?>[label]" value="<?= e($m['label']) ?>" style="width:220px"></td>
          <td class="num"><?= isset($last[$m['id']]) ? e(fmt_num($last[$m['id']])) . ' ' . e($m['unit']) : '—' ?></td>
          <td><input type="number" step="any" name="<?= $f ?>[warn]" value="<?= e($m['warn']) ?>"></td>
          <td><input type="number" step="any" name="<?= $f ?>[crit]" value="<?= e($m['crit']) ?>"></td>
          <td class="mono muted small"><?= e($m['oid']) ?></td>
          <td><button class="btn btn-ghost btn-sm" form="del<?= $m['id'] ?>" title="Delete metric and its history">✕</button></td>
        </tr>
      <?php endforeach ?>
      </tbody></table></div>
  </form>
  <?php foreach ($metrics as $m): ?><form id="del<?= $m['id'] ?>" method="post" data-confirm="Delete this metric and all its history?" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="delete_metric"><input type="hidden" name="mid" value="<?= $m['id'] ?>"></form><?php endforeach ?>
  <div class="grid grid-2">
    <form method="post" class="card">
      <?= csrf_field() ?><input type="hidden" name="act" value="custom">
      <h3>Add custom OID</h3>
      <div class="form-grid">
        <div class="field"><label>OID</label><input type="text" name="oid" required placeholder="1.3.6.1.4.1.2021.4.6.0" class="mono"></div>
        <div class="field"><label>Label</label><input type="text" name="label" placeholder="Free memory"></div>
        <div class="field"><label>Type</label><select name="type"><option value="gauge">Gauge (value as-is)</option><option value="counter">Counter (rate per second)</option></select></div>
        <div class="field"><label>Unit</label><input type="text" name="unit" placeholder="kB, °C, bps…"></div>
        <div class="field"><label>Scale</label><input type="number" step="any" name="scale" value="1"><div class="hint">Multiplier applied to the value (e.g. 8 for bytes → bits).</div></div>
      </div>
      <button class="btn">Add metric</button>
    </form>
    <form method="post" class="card">
      <?= csrf_field() ?><input type="hidden" name="act" value="discover">
      <h3>Rediscover</h3>
      <p class="muted">Walk the device again and add any new interfaces, storage, sensors or supplies. Existing metrics and your edits are kept.</p>
      <button class="btn btn-ghost">Run discovery</button>
    </form>
  </div>

<?php else: // settings
    require __DIR__ . '/device_form.php';
    device_form($dev);
endif;

page_end(CHART_JS);
