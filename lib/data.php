<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/alerts.php';

const RANGES = ['1h' => 3600, '6h' => 21600, '24h' => 86400, '7d' => 604800, '30d' => 2592000, '1y' => 31536000];

function in_list(array $ids): string
{
    return $ids ? implode(',', array_map('intval', $ids)) : '0';
}

/** Latest value per metric id: [id => value]. */
function latest_values(array $ids): array
{
    if (!$ids) {
        return [];
    }
    $in = in_list($ids);
    $rows = db_all("SELECT s.metric_id, s.value FROM samples_raw s
        JOIN (SELECT metric_id, MAX(ts) mt FROM samples_raw WHERE metric_id IN ($in) AND ts > ? GROUP BY metric_id) x
          ON x.metric_id = s.metric_id AND x.mt = s.ts", [time() - 1800]);
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['metric_id']] = $r['value'] === null ? null : (float)$r['value'];
    }
    return $out;
}

/** Pick the storage tier for a time range. */
function series_table(int $range): array
{
    if ($range <= 2 * 86400) {
        return ['samples_raw', 'value', 60];
    }
    return $range <= 30 * 86400 ? ['samples_5m', 'avg', 300] : ['samples_1h', 'avg', 3600];
}

/**
 * Aligned series for uPlot: ['t' => [...], 'series' => [[meta + data], ...]].
 * Timestamps from different devices are bucketed to the tier step.
 */
function series(array $ids, int $range, ?int $end = null): array
{
    $end = $end ?? time();
    $start = $end - $range;
    [$table, $col, $step] = series_table($range);
    $metrics = db_all('SELECT m.id, m.label, m.unit, m.kind, d.name device FROM metrics m JOIN devices d ON d.id = m.device_id WHERE m.id IN (' . in_list($ids) . ')');
    $byId = array_column($metrics, null, 'id');
    $rows = db_all("SELECT metric_id, ts, $col v FROM $table WHERE metric_id IN (" . in_list($ids) . ') AND ts >= ? AND ts <= ? ORDER BY ts', [$start, $end]);
    $multiDevice = count(array_unique(array_column($metrics, 'device'))) > 1;
    $grid = [];
    foreach ($rows as $r) {
        $b = $multiDevice || $table !== 'samples_raw' ? intdiv((int)$r['ts'], $step) * $step : (int)$r['ts'];
        $grid[$b][(int)$r['metric_id']] = $r['v'] === null ? null : round((float)$r['v'], 4);
    }
    ksort($grid);
    $t = array_keys($grid);
    $series = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if (!isset($byId[$id])) {
            continue;
        }
        $m = $byId[$id];
        $data = [];
        foreach ($t as $ts) {
            $data[] = $grid[$ts][$id] ?? null;
        }
        $series[] = ['id' => $id, 'label' => ($multiDevice ? $m['device'] . ' · ' : '') . $m['label'], 'unit' => $m['unit'], 'data' => $data];
    }
    return ['t' => $t, 'step' => $step, 'series' => $series];
}

/** Metric ids referenced by public dashboards (readable without login). */
function public_metric_ids(): array
{
    $ids = [];
    foreach (db_all('SELECT layout_json FROM dashboards WHERE public = 1') as $d) {
        foreach (json_decode($d['layout_json'], true) ?: [] as $p) {
            foreach ((array)($p['metrics'] ?? []) as $m) {
                $ids[(int)$m] = true;
            }
        }
    }
    return array_keys($ids);
}

/** Small inline SVG sparkline from [ts => value]. */
function sparkline(array $points, int $w = 260, int $h = 36, bool $zero = true): string
{
    $vals = array_values(array_filter($points, fn($v) => $v !== null));
    if (count($vals) < 2) {
        return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none"></svg>';
    }
    $ts = array_keys($points);
    $t0 = min($ts);
    $t1 = max($ts) ?: $t0 + 1;
    $max = max($vals) ?: 1;
    $min = $zero ? 0.0 : (float)min($vals); // chosen readings: scale to their own range
    $span = ($zero ? $max : $max - $min) ?: 1;
    $d = '';
    foreach ($points as $t => $v) {
        if ($v === null) {
            continue;
        }
        $x = ($t - $t0) / max(1, $t1 - $t0) * $w;
        $y = $h - 2 - (($v - $min) / $span) * ($h - 4);
        $d .= ($d === '' ? 'M' : 'L') . round($x, 1) . ',' . round($y, 1);
    }
    $area = $d . "L$w,$h L0,$h Z";
    return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none"><path class="area" d="' . $area . '"/><path class="line" d="' . $d . '" vector-effect="non-scaling-stroke"/></svg>';
}

/** Gauge range for a user-chosen reading: [min, max]. Explicit g_min/g_max win; % is 0..100; else auto. */
function gauge_range(array $m, ?float $v): array
{
    $min = is_numeric($m['g_min'] ?? null) ? (float)$m['g_min'] : null;
    $max = is_numeric($m['g_max'] ?? null) ? (float)$m['g_max'] : null;
    $pct = ($m['unit'] ?? '') === '%';
    $min ??= $pct ? 0.0 : ($v !== null && $v < 0 ? $v * 1.5 : 0.0);
    $max ??= $pct ? 100.0 : ($v !== null && $v < 0 ? 0.0 : max(1.0, ($v ?? 0.0) * 1.5));
    return [$min, $max > $min ? $max : $min + 1];
}

/** Summary gauges for a device card: list of [label, value, max, unit, warn, crit, min]. */
function device_summary(int $id): array
{
    // readings chosen by the user (Metrics tab) replace the automatic picks
    $sel = db_all('SELECT id, label, unit, warn, crit, g_min, g_max FROM metrics WHERE device_id = ? AND enabled = 1 AND ov_gauge = 1 ORDER BY id LIMIT 3', [$id]);
    if ($sel) {
        $v = latest_values(array_column($sel, 'id'));
        $g = [];
        foreach ($sel as $m) {
            $val = isset($v[$m['id']]) ? (float)$v[$m['id']] : null;
            [$min, $max] = gauge_range($m, $val);
            $g[] = [$m['label'], $val, $max, $m['unit'], $m['warn'], $m['crit'], $min];
        }
        return $g;
    }
    $ms = db_all("SELECT id, kind, label, unit, warn, crit FROM metrics WHERE device_id = ? AND enabled = 1 AND kind IN ('cpu','mem','storage','supply')", [$id]);
    $v = latest_values(array_column($ms, 'id'));
    $g = [];
    $cpu = array_filter($ms, fn($m) => $m['kind'] === 'cpu' && isset($v[$m['id']]));
    if ($cpu) {
        $g[] = ['CPU', array_sum(array_map(fn($m) => $v[$m['id']], $cpu)) / count($cpu), 100, '%', 80, 95];
    }
    foreach ($ms as $m) {
        if ($m['kind'] === 'mem' && isset($v[$m['id']])) {
            $g[] = ['Memory', $v[$m['id']], 100, '%', $m['warn'], $m['crit']];
            break;
        }
    }
    $disk = null;
    foreach ($ms as $m) {
        if ($m['kind'] === 'storage' && isset($v[$m['id']]) && ($m['label'] === '/' || $disk === null)) {
            $disk = ['Disk ' . $m['label'], $v[$m['id']], 100, '%', $m['warn'], $m['crit']];
            if ($m['label'] === '/') {
                break;
            }
        }
    }
    if ($disk) {
        $g[] = $disk;
    }
    foreach ($ms as $m) {
        if ($m['kind'] === 'supply' && isset($v[$m['id']]) && count($g) < 3) {
            $g[] = [$m['label'], $v[$m['id']], 100, '%', $m['warn'], $m['crit']];
        }
    }
    return array_slice($g, 0, 3);
}

/**
 * The card's graph: the reading chosen on the Metrics tab, else total interface traffic.
 * Returns ['label','unit','points' => [ts => value],'zero' => bool (zero-based scale)].
 */
function device_graph(int $id, int $range = 21600): array
{
    $m = db_one('SELECT id, label, unit FROM metrics WHERE device_id = ? AND enabled = 1 AND ov_graph = 1 ORDER BY id LIMIT 1', [$id]);
    if (!$m) {
        return ['label' => 'Traffic', 'unit' => 'bps', 'points' => device_traffic($id, $range), 'zero' => true];
    }
    $pts = [];
    foreach (db_all('SELECT ts, value FROM samples_raw WHERE metric_id = ? AND ts > ? AND value IS NOT NULL ORDER BY ts', [$m['id'], time() - $range]) as $r) {
        $pts[(int)$r['ts']] = (float)$r['value'];
    }
    return ['label' => $m['label'], 'unit' => (string)$m['unit'], 'points' => $pts, 'zero' => false];
}

/** Total interface in+out traffic for the sparkline: [ts => bps]. */
function device_traffic(int $id, int $range = 21600): array
{
    $rows = db_all("SELECT s.ts, SUM(s.value) v FROM samples_raw s JOIN metrics m ON m.id = s.metric_id
        WHERE m.device_id = ? AND m.kind IN ('if_in','if_out') AND m.enabled = 1 AND s.ts > ? GROUP BY s.ts ORDER BY s.ts", [$id, time() - $range]);
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['ts']] = (float)$r['v'];
    }
    return $out;
}

function gauge_html(string $label, ?float $value, float $max, ?string $unit, $warn = null, $crit = null, float $min = 0.0): string
{
    return '<div class="gauge" data-gauge data-value="' . ($value === null ? '' : round($value, 2)) . '" data-max="' . $max . '" data-min="' . $min
        . '" data-unit="' . htmlspecialchars((string)$unit) . '" data-warn="' . ($warn ?? '') . '" data-crit="' . ($crit ?? '')
        . '"></div><div class="glabel" title="' . htmlspecialchars($label) . '">' . htmlspecialchars($label) . '</div>';
}

/** A sensible starting layout for one device. */
function starter_layout(int $dev): array
{
    $ms = db_all('SELECT id, kind, label FROM metrics WHERE device_id = ? AND enabled = 1 ORDER BY id', [$dev]);
    $k = fn($kind) => array_column(array_filter($ms, fn($m) => $m['kind'] === $kind), 'id');
    $L = [['type' => 'status', 'title' => 'Devices', 'metrics' => [], 'w' => 3, 'h' => 3, 'range' => '24h']];
    foreach (array_slice($k('cpu'), 0, 1) as $id) $L[] = ['type' => 'gauge', 'title' => 'CPU 0', 'metrics' => [$id], 'w' => 3, 'h' => 3, 'range' => '24h'];
    foreach (array_slice($k('mem'), 0, 1) as $id) $L[] = ['type' => 'gauge', 'title' => 'Memory', 'metrics' => [$id], 'w' => 3, 'h' => 3, 'range' => '24h'];
    foreach (array_slice($k('supply'), 0, 2) as $id) $L[] = ['type' => 'gauge', 'title' => 'Toner', 'metrics' => [$id], 'w' => 3, 'h' => 3, 'range' => '24h'];
    foreach (array_slice($k('pages'), 0, 1) as $id) $L[] = ['type' => 'stat', 'title' => 'Pages printed', 'metrics' => [$id], 'w' => 3, 'h' => 3, 'range' => '24h'];
    if ($k('cpu')) $L[] = ['type' => 'graph', 'title' => 'CPU', 'metrics' => array_slice($k('cpu'), 0, 6), 'w' => 6, 'h' => 4, 'range' => '24h'];
    $in = $k('if_in'); $out = $k('if_out');
    if ($in) $L[] = ['type' => 'graph', 'title' => 'Traffic', 'metrics' => array_slice(array_merge(array_slice($in, 0, 2), array_slice($out, 0, 2)), 0, 4), 'w' => 6, 'h' => 4, 'range' => '24h'];
    if ($k('storage')) $L[] = ['type' => 'graph', 'title' => 'Storage', 'metrics' => array_slice($k('storage'), 0, 4), 'w' => 6, 'h' => 4, 'range' => '7d'];
    return $L;
}
