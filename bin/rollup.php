<?php
declare(strict_types=1);
// Cron: */5 * * * * php /path/to/tidewatch/bin/rollup.php
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/lib/db.php';

$lock = fopen(dirname(__DIR__) . '/data/rollup.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$now = time();
$replace = db_is_sqlite() ? 'INSERT OR REPLACE' : 'REPLACE';
$div = db_is_sqlite() ? '/' : 'DIV';

/** Aggregate complete buckets of $step seconds from $src into $dst. */
function rollup(string $src, string $dst, int $step, bool $fromRaw): void
{
    global $now, $replace, $div;
    $key = "rollup_$dst";
    $last = db_val('SELECT v FROM meta WHERE k=?', [$key]);
    if ($last === false || $last === null) {
        $last = (int)db_val("SELECT MIN(ts) FROM $src");
        if (!$last) {
            return;
        }
    }
    $from = intdiv((int)$last, $step) * $step;
    $to = intdiv($now, $step) * $step; // exclude current, incomplete bucket
    if ($to <= $from) {
        return;
    }
    $agg = $fromRaw ? 'AVG(value), MIN(value), MAX(value)' : 'AVG(avg), MIN(min), MAX(max)';
    db_exec("$replace INTO $dst (metric_id, ts, avg, min, max)
             SELECT metric_id, (ts $div $step) * $step AS b, $agg FROM $src
             WHERE ts >= ? AND ts < ? GROUP BY metric_id, b", [$from, $to]);
    db_upsert('meta', ['k' => $key, 'v' => (string)$to], ['k']);
}

rollup('samples_raw', 'samples_5m', 300, true);
rollup('samples_5m', 'samples_1h', 3600, false);

db_exec('DELETE FROM samples_raw WHERE ts < ?', [$now - (int)config('keep_raw')]);
db_exec('DELETE FROM samples_5m WHERE ts < ?', [$now - (int)config('keep_5m')]);
db_exec('DELETE FROM samples_1h WHERE ts < ?', [$now - (int)config('keep_1h')]);
db_exec('DELETE FROM events WHERE ts < ?', [$now - (int)config('keep_1h')]);
db_exec('DELETE FROM last_counter WHERE metric_id NOT IN (SELECT id FROM metrics)');
