<?php
declare(strict_types=1);
// Server-Sent Events live stream: reads one device over SNMP every few seconds.
// GET live.php?d=<device>&m=<ids>&i=<seconds>. Admin only, capped number of concurrent streams.
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/live.php';

if (!is_admin()) {
    http_response_code(403);
    exit;
}
session_write_close(); // do not hold the session lock for the life of the stream

$dev = db_one('SELECT * FROM devices WHERE id = ? AND enabled = 1', [(int)($_GET['d'] ?? 0)]);
if (!$dev || ($dev['type'] ?? 'snmp') !== 'snmp') { // live mode reads over SNMP only
    http_response_code(404);
    exit;
}
$ids = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['m'] ?? '')))));
$metrics = live_metrics((int)$dev['id'], $ids);
$interval = max(1, min(30, (int)($_GET['i'] ?? 2)));
$maxLife = (int)config('live_stream_secs', 300);

// one flock'd slot file per stream; when all are taken, tell the browser to fall back to polling
$slot = null;
$dir = dirname(__DIR__) . '/data';
for ($n = 0; $n < (int)config('live_streams', 4); $n++) {
    $fh = @fopen("$dir/live-$n.lock", 'c');
    if ($fh && flock($fh, LOCK_EX | LOCK_NB)) {
        $slot = $fh;
        break;
    }
    if ($fh) {
        fclose($fh);
    }
}
db_close(); // no DB needed from here on; do not keep SQLite open

header('Content-Type: text/event-stream');
header('Cache-Control: no-store');
header('X-Accel-Buffering: no'); // nginx
header('X-Content-Type-Options: nosniff');
while (ob_get_level()) {
    ob_end_flush();
}
ignore_user_abort(false);
@set_time_limit($maxLife + 30);

function sse(string $event, $data): void
{
    echo "event: $event\ndata: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n\n";
    // pad so buffering proxies/servers pass small events promptly
    echo ': ' . str_repeat(' ', 256) . "\n\n";
    flush();
}

if (!$slot) {
    echo "retry: 60000\n\n";
    sse('busy', ['streams' => (int)config('live_streams', 4)]);
    exit;
}

echo "retry: 2000\n\n";
sse('meta', ['metrics' => live_meta($metrics), 'interval' => $interval]);
$snmp = snmp_open($dev);
$state = [];
$end = time() + $maxLife;
while (time() < $end && !connection_aborted()) {
    $t0 = microtime(true);
    $s = live_sample($snmp, $metrics, $state);
    sse($s ? 'sample' : 'nodata', $s ?? ['t' => round($t0, 3)]);
    $wait = $interval - (microtime(true) - $t0);
    if ($wait > 0) {
        usleep((int)($wait * 1e6));
    }
}
sse('bye', ['reason' => 'max life']);
