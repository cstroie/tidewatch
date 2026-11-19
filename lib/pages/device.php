<?php
$id = (int)($_GET['id'] ?? 0);
$dev = db_one('SELECT * FROM devices WHERE id = ?', [$id]);
if (!$dev) {
    redirect('app.php?p=devices');
}
$tab = (string)($_GET['tab'] ?? 'system');
$self = "app.php?p=device&id=$id";
$prom = ($dev['type'] ?? 'snmp') === 'prom';
if (!isset($_GET['tab']) && $prom) {
    $tab = 'sensors';
}

// ---- actions ----
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    if ($act === 'metrics') {
        $st = db()->prepare('UPDATE metrics SET label = ?, warn = ?, crit = ?, enabled = ?, ov_gauge = ?, ov_graph = ?, g_min = ?, g_max = ? WHERE id = ? AND device_id = ?');
        $rows = (array)($_POST['m'] ?? []);
        ksort($rows);
        $graphId = (int)($_POST['graph'] ?? 0);
        $ng = 0;
        $dropped = false;
        foreach ($rows as $mid => $f) {
            $num = fn($v) => is_numeric($v) ? (float)$v : null;
            $gauge = !empty($f['gauge']);
            if ($gauge && $ng >= 3) {
                $gauge = false; // at most 3 gauges on the overview card
                $dropped = true;
            }
            $ng += $gauge ? 1 : 0;
            $st->execute([mb_substr(trim((string)($f['label'] ?? '')), 0, 120) ?: 'metric', $num($f['warn'] ?? ''), $num($f['crit'] ?? ''), empty($f['enabled']) ? 0 : 1,
                $gauge ? 1 : 0, (int)$mid === $graphId ? 1 : 0, $num($f['gmin'] ?? ''), $num($f['gmax'] ?? ''), (int)$mid, $id]);
        }
        $dropped ? flash('Metrics saved. Only 3 gauges fit on the overview card; the extra ones were not selected.', 'warn') : flash('Metrics saved.');
        redirect("$self&tab=metrics");
    }
    if ($act === 'custom' && !$prom) {
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

$tabs = $prom ? ['sensors' => 'Sensors', 'system' => 'System', 'metrics' => 'Metrics', 'settings' => 'Settings']
    : ['live' => 'Live', 'system' => 'System', 'interfaces' => 'Interfaces', 'sensors' => 'Sensors & supplies', 'metrics' => 'Metrics', 'settings' => 'Settings'];
if (!isset($tabs[$tab])) {
    $tab = $prom ? 'sensors' : 'system';
}
page_start($dev['name'], 'devices');
?>
<div class="page-head">
  <?= device_badge($dev, 'lg') ?>
  <hgroup><h1><?= e($dev['name']) ?></h1><p><?php if ($prom): require_once dirname(__DIR__) . '/prom.php'; ?><?= e(prom_url($dev)) ?> · every <?= (int)$dev['poll_interval'] ?>s<?php else: ?><?= e($dev['host']) ?> · SNMP v<?= e($dev['version']) ?> · up <?= e(fmt_uptime($dev['uptime'] ? (int)$dev['uptime'] : null)) ?><?php endif ?> · polled <?= e(fmt_ago($dev['last_poll'] ? (int)$dev['last_poll'] : null)) ?></p></hgroup>
  <div class="actions"><?= in_array($tab, ['system', 'interfaces', 'sensors'], true) ? range_picker() : '' ?></div>
</div>
<?= flash() ?>
<nav class="tabs"><?php foreach ($tabs as $k => $l): ?><a href="<?= $self ?>&tab=<?= $k ?>" class="<?= $k === $tab ? 'active' : '' ?>"><?= $l ?></a><?php endforeach ?></nav>

<?php if ($tab === 'system' && !$prom):
    $cpu = $en('cpu'); $load = $en('load'); $mem = $en('mem'); $sto = $en('storage');
    $gauge = array_merge($mem, $sto);
?>
  <?php if ($dev['sysdescr']): ?><p class="muted small lead"><?= e($dev['sysdescr']) ?></p><?php endif ?>
  <?php $info = array_filter(['Hostname' => $dev['sysname'] ?? '', 'Location' => $dev['syslocation'] ?? '', 'Contact' => $dev['syscontact'] ?? '']); ?>
  <?php if ($info): ?><p class="small lead"><?php foreach ($info as $k => $v): ?><span><span class="muted"><?= e($k) ?></span> <?= e($v) ?></span><?php endforeach ?></p><?php endif ?>
  <?php if ($cpu || $gauge): ?>
  <div class="card mb">
    <div class="grid gauge-row">
      <?php if ($cpu):
          $vals = array_filter(array_map(fn($m) => $last[$m['id']] ?? null, $cpu), fn($v) => $v !== null); ?>
        <div><?= gauge_html('CPU avg', $vals ? array_sum($vals) / count($vals) : null, 100, '%', 80, 95) ?></div>
      <?php endif ?>
      <?php foreach ($gauge as $m): ?><div><?= gauge_html($m['label'], $last[$m['id']] ?? null, 100, '%', $m['warn'], $m['crit']) ?></div><?php endforeach ?>
    </div>
  </div>
  <?php endif ?>
  <div class="grid grid-2">
    <?php if ($cpu): ?><div class="card"><header class="card-head"><hgroup><h2>CPU</h2></hgroup></header><div class="chart" data-metrics="<?= $ids($cpu) ?>"></div></div><?php endif ?>
    <?php if ($load): ?><div class="card"><header class="card-head"><hgroup><h2>Load average</h2></hgroup></header><div class="chart" data-metrics="<?= $ids($load) ?>"></div></div><?php endif ?>
    <?php if ($mem): ?><div class="card"><header class="card-head"><hgroup><h2>Memory</h2></hgroup></header><div class="chart" data-metrics="<?= $ids($mem) ?>"></div></div><?php endif ?>
    <?php if ($sto): ?><div class="card"><header class="card-head"><hgroup><h2>Storage</h2></hgroup></header><div class="chart" data-metrics="<?= $ids(array_slice($sto, 0, 6)) ?>"></div></div><?php endif ?>
    <?php if ($c = $en('custom')): ?><div class="card"><header class="card-head"><hgroup><h2>Custom</h2></hgroup></header><div class="chart" data-metrics="<?= $ids(array_slice($c, 0, 6)) ?>"></div></div><?php endif ?>
  </div>
  <?php if (!$cpu && !$mem && !$sto && !$en('custom')): ?><p class="empty">No system metrics for this device. Check the <a href="<?= $self ?>&tab=interfaces">interfaces</a> or <a href="<?= $self ?>&tab=sensors">sensors</a> tabs.</p><?php endif ?>

<?php elseif ($tab === 'live'):
    require_once dirname(__DIR__) . '/live.php';
    $lm = live_metrics($id);
?>
  <div id="live" data-device="<?= $id ?>" data-metrics="<?= $ids($lm) ?>">
    <div class="live-bar card">
      <span class="live-status" data-live-status>Starting…</span>
      <div class="seg" title="Stream: one long connection (SSE). Poll: a request per sample."><button type="button" data-live-mode="stream">Stream</button><button type="button" data-live-mode="poll">Poll</button></div>
      <div class="seg" title="Seconds between reads"><button type="button" data-live-int="1">1s</button><button type="button" data-live-int="2">2s</button><button type="button" data-live-int="5">5s</button></div>
      <button type="button" class="btn btn-ghost btn-sm" data-live-toggle>Pause</button>
    </div>
    <p class="muted small">Read directly from the device and not stored. Shows the last 5 minutes.</p>
    <div class="grid grid-2" data-live-grid></div>
  </div>

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
  <?php if ($ifs): ?><div class="card flush mb"><div class="table-wrap"><table class="tbl">
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
    </tbody></table></div></div><?php endif ?>
  <div class="grid grid-2">
  <?php foreach ($ifs as $i): if (empty($i['if_in']['enabled']) && empty($i['if_out']['enabled'])) continue; ?>
    <div class="card"><header class="card-head"><hgroup><h2><?= e($i['name']) ?></h2></hgroup></header>
      <div class="chart" data-metrics="<?= $ids(array_filter([$i['if_in'] ?? null, $i['if_out'] ?? null])) ?>"></div></div>
  <?php endforeach ?>
  </div>
  <?php if (!$ifs): ?><p class="empty">No interfaces discovered.</p><?php endif ?>

<?php elseif ($tab === 'sensors' || $tab === 'system'): // prom 'system' = load, stats, telemetry, grouped by unit
    $sens = $tab === 'system' ? array_merge($en('cpu'), $en('custom')) : $en('sensor'); $sup = $en('supply'); $pages = $en('pages');
    $groups = [];
    foreach ($sens as $m) { $groups[$m['unit'] ?: '—'][] = $m; }
    if ($prom) { // many series per unit: split into charts of 6
        $chunks = [];
        foreach ($groups as $u => $ms) {
            foreach (array_chunk($ms, 6) as $n => $c) { $chunks[$u . ($n ? ' (' . ($n + 1) . ')' : '')] = $c; }
        }
        $groups = $chunks;
    }
?>
  <?php if ($sup || $sens): ?>
  <div class="card mb">
    <div class="grid gauge-row">
      <?php foreach ($sup as $m): ?><div><?= gauge_html($m['label'], $last[$m['id']] ?? null, 100, '%', $m['warn'], $m['crit']) ?></div><?php endforeach ?>
      <?php foreach (array_slice($sens, 0, 12) as $m): ?><div><?= gauge_html($m['label'], $last[$m['id']] ?? null, $m['unit'] === '°C' ? 100 : max(1, ($last[$m['id']] ?? 1) * 1.5), $m['unit'], $m['warn'], $m['crit']) ?></div><?php endforeach ?>
    </div>
  </div>
  <?php endif ?>
  <div class="grid grid-2">
    <?php foreach ($groups as $u => $ms): ?><div class="card"><header class="card-head"><hgroup><h2>Sensors · <?= e($u) ?></h2></hgroup></header><div class="chart" data-metrics="<?= $ids(array_slice($ms, 0, 6)) ?>"></div></div><?php endforeach ?>
    <?php if ($sup): ?><div class="card"><header class="card-head"><hgroup><h2>Supplies</h2></hgroup></header><div class="chart" data-metrics="<?= $ids($sup) ?>"></div></div><?php endif ?>
    <?php if ($pages): ?><div class="card"><header class="card-head"><hgroup><h2>Pages printed</h2></hgroup><b><?= e(number_format((float)($last[$pages[0]['id']] ?? 0))) ?></b></header><div class="chart" data-metrics="<?= $ids($pages) ?>"></div></div><?php endif ?>
  </div>
  <?php if (!$sens && !$sup && !$pages): ?><p class="empty">No sensors or supplies discovered on this device.</p><?php endif ?>

<?php elseif ($tab === 'metrics'): ?>
  <form method="post" class="card flush mb">
    <?= csrf_field() ?><input type="hidden" name="act" value="metrics">
    <header class="card-head"><hgroup><h2>All metrics</h2><p>Up to 3 gauges and one graph show on the overview card. If warn is above crit, lower values are worse (toner).</p></hgroup></header>
    <div class="table-wrap"><table class="tbl">
      <thead><tr><th>On</th><th>Kind</th><th>Label</th><th class="num">Last</th><th title="Gauge on the overview card (max 3)">Gauge</th><th>Min</th><th>Max</th><th title="Graph on the overview card (one)">Graph</th><th>Warn</th><th>Crit</th><th>OID</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($metrics as $m): $f = "m[{$m['id']}]"; $al = fn($w) => 'aria-label="' . e($w . ': ' . $m['label']) . '"'; ?>
        <tr>
          <td><input type="checkbox" class="check" name="<?= $f ?>[enabled]" value="1" <?= $m['enabled'] ? 'checked' : '' ?> <?= $al('On') ?>></td>
          <td><span class="pill"><?= e($m['kind']) ?></span></td>
          <td><input type="text" class="w-label" name="<?= $f ?>[label]" value="<?= e($m['label']) ?>" <?= $al('Label') ?>></td>
          <td class="num"><?= isset($last[$m['id']]) ? e(fmt_unit($last[$m['id']], $m['unit'])) : '—' ?></td>
          <td><input type="checkbox" class="check" name="<?= $f ?>[gauge]" value="1" <?= $m['ov_gauge'] ? 'checked' : '' ?> <?= $al('Gauge') ?>></td>
          <td><input type="number" step="any" name="<?= $f ?>[gmin]" value="<?= e($m['g_min']) ?>" placeholder="auto" <?= $al('Gauge minimum') ?>></td>
          <td><input type="number" step="any" name="<?= $f ?>[gmax]" value="<?= e($m['g_max']) ?>" placeholder="auto" <?= $al('Gauge maximum') ?>></td>
          <td><input type="radio" name="graph" value="<?= $m['id'] ?>" <?= $m['ov_graph'] ? 'checked' : '' ?> <?= $al('Graph') ?>></td>
          <td><input type="number" step="any" name="<?= $f ?>[warn]" value="<?= e($m['warn']) ?>" <?= $al('Warn') ?>></td>
          <td><input type="number" step="any" name="<?= $f ?>[crit]" value="<?= e($m['crit']) ?>" <?= $al('Crit') ?>></td>
          <td class="mono muted small"><?= e($m['oid']) ?></td>
          <td><button class="btn btn-quiet btn-sm" form="del<?= $m['id'] ?>" title="Delete metric and its history" aria-label="Delete <?= e($m['label']) ?>">✕</button></td>
        </tr>
      <?php endforeach ?>
      </tbody></table></div>
    <?php if (!$metrics): ?><p class="empty">No metrics yet. Run discovery below.</p><?php endif ?>
    <?php if ($metrics): ?><footer><button class="btn">Save</button></footer><?php endif ?>
  </form>
  <?php foreach ($metrics as $m): ?><form id="del<?= $m['id'] ?>" method="post" data-confirm="Delete this metric and all its history?" class="inline"><?= csrf_field() ?><input type="hidden" name="act" value="delete_metric"><input type="hidden" name="mid" value="<?= $m['id'] ?>"></form><?php endforeach ?>
  <div class="grid grid-2">
    <?php if (!$prom): ?><form method="post" class="card" data-busy>
      <?= csrf_field() ?><input type="hidden" name="act" value="custom">
      <header class="card-head"><hgroup><h2>Add custom OID</h2><p>The device is asked for the OID once before it is added.</p></hgroup></header>
      <div class="form-grid">
        <div class="field"><label>OID
          <input type="text" name="oid" required placeholder="1.3.6.1.4.1.2021.4.6.0" class="mono">
          <small>Numeric, with no leading dot.</small></label></div>
        <div class="field"><label>Label
          <input type="text" name="label" placeholder="Free memory"><small>Left empty, the OID is the label.</small></label></div>
        <div class="field"><label>Type
          <select name="type"><option value="gauge">Gauge</option><option value="counter">Counter</option></select>
          <small>A gauge is stored as read; a counter as a rate per second.</small></label></div>
        <div class="field"><label>Unit
          <input type="text" name="unit" placeholder="kB">
          <small>Optional. Shown after the value; kB, MB and B get SI scaling.</small></label></div>
        <div class="field"><label>Scale
          <input type="number" step="any" name="scale" value="1"><small>Multiplies the value: 8 turns bytes into bits.</small></label></div>
      </div>
      <footer><button class="btn">Add metric</button></footer>
    </form><?php endif ?>
    <form method="post" class="card" data-busy>
      <?= csrf_field() ?><input type="hidden" name="act" value="discover">
      <header class="card-head"><hgroup><h2>Rediscover</h2><p><?= $prom ? 'Fetch the metrics page again and add any new series.' : 'Walk the device again and add any new interfaces, storage, sensors or supplies.' ?> Existing metrics and your edits are kept.</p></hgroup></header>
      <footer><button class="btn">Run discovery</button></footer>
    </form>
  </div>

<?php else: // settings
    require __DIR__ . '/device_form.php';
    device_form($dev);
endif;

page_end($tab === 'live' ? array_merge(CHART_JS, ['live.js']) : CHART_JS);
