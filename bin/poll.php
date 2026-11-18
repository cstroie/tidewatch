<?php
declare(strict_types=1);
// Cron: * * * * * php /path/to/tidewatch/bin/poll.php
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/lib/metrics.php';

$lock = fopen(dirname(__DIR__) . '/data/poll.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "poll already running\n");
    exit(0);
}

$now = time();
$force = in_array('-f', $argv, true);
$devices = array_values(array_filter(
    db_all('SELECT * FROM devices WHERE enabled=1'),
    fn($d) => $force || !$d['last_poll'] || $now - (int)$d['last_poll'] >= (int)$d['poll_interval'] - 10
));
if (!$devices) {
    exit(0);
}

$workers = max(1, (int)config('poll_workers', 8));
$t0 = microtime(true);

if ($workers > 1 && function_exists('pcntl_fork') && count($devices) > 1) {
    db_close();
    $running = 0;
    foreach ($devices as $dev) {
        if ($running >= $workers) {
            pcntl_wait($st);
            $running--;
        }
        $pid = pcntl_fork();
        if ($pid === 0) {
            poll_one($dev);
            exit(0);
        }
        if ($pid > 0) {
            $running++;
        } else {
            poll_one($dev); // fork failed
        }
    }
    while ($running-- > 0) {
        pcntl_wait($st);
    }
} else {
    foreach ($devices as $dev) {
        poll_one($dev);
    }
}

if (in_array('-v', $argv, true)) {
    printf("polled %d devices in %.2fs\n", count($devices), microtime(true) - $t0);
}

function poll_one(array $dev): void
{
    try {
        poll_device($dev);
    } catch (Throwable $e) {
        fwrite(STDERR, "{$dev['name']}: {$e->getMessage()}\n");
    }
}
