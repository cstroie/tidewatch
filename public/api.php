<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/data.php';
require dirname(__DIR__) . '/lib/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out($data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

$admin = is_admin();
$a = (string)($_GET['a'] ?? '');
$ids = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['m'] ?? '')))));
if (!$admin && $ids) {
    $allowed = public_metric_ids();
    if (array_diff($ids, $allowed)) {
        out(['error' => 'forbidden'], 403);
    }
}

switch ($a) {
    case 'series':
        $range = RANGES[$_GET['range'] ?? '24h'] ?? (int)($_GET['range'] ?? 86400);
        $range = max(600, min($range, 366 * 86400));
        out(series(array_slice($ids, 0, 24), $range));

    case 'last':
        $vals = latest_values($ids);
        $meta = [];
        foreach (db_all('SELECT id, label, unit, warn, crit FROM metrics WHERE id IN (' . in_list($ids) . ')') as $m) {
            $meta[$m['id']] = ['label' => $m['label'], 'unit' => $m['unit'], 'warn' => $m['warn'] === null ? null : (float)$m['warn'], 'crit' => $m['crit'] === null ? null : (float)$m['crit']];
        }
        out(['values' => (object)$vals, 'meta' => (object)$meta, 'ts' => time()]);

    case 'status':
        $q = $admin ? 'SELECT id, name, status, last_poll FROM devices WHERE enabled = 1 ORDER BY name'
                    : 'SELECT name, status FROM devices WHERE enabled = 1 AND public = 1 ORDER BY name';
        out(['devices' => db_all($q), 'ts' => time()]);

    case 'metrics':
        if (!$admin) {
            out(['error' => 'forbidden'], 403);
        }
        out(['metrics' => db_all('SELECT m.id, m.device_id, d.name device, m.kind, m.label, m.unit, m.warn, m.crit FROM metrics m JOIN devices d ON d.id = m.device_id WHERE m.enabled = 1 ORDER BY d.name, m.kind, m.id')]);

    case 'dashboard_save':
        if (!$admin || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            out(['error' => 'forbidden'], 403);
        }
        csrf_check();
        $in = json_decode((string)file_get_contents('php://input'), true);
        $layout = [];
        foreach ((array)($in['layout'] ?? []) as $p) {
            $type = in_array($p['type'] ?? '', ['graph', 'gauge', 'stat', 'status'], true) ? $p['type'] : 'graph';
            $layout[] = [
                'type' => $type,
                'title' => mb_substr((string)($p['title'] ?? ''), 0, 80),
                'metrics' => array_slice(array_map('intval', (array)($p['metrics'] ?? [])), 0, 12),
                'w' => max(1, min(12, (int)($p['w'] ?? 6))),
                'h' => max(1, min(8, (int)($p['h'] ?? 3))),
                'range' => isset(RANGES[$p['range'] ?? '']) ? $p['range'] : '24h',
                'max' => isset($p['max']) && is_numeric($p['max']) ? (float)$p['max'] : null,
            ];
        }
        db_exec('UPDATE dashboards SET layout_json = ? WHERE id = ?', [json_encode($layout), (int)($in['id'] ?? 0)]);
        out(['ok' => true]);

    default:
        out(['error' => 'unknown action'], 400);
}
