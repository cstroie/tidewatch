<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

/** Track device up/down transitions; notify on change. */
function alert_device(array $dev, string $status): void
{
    $prev = $dev['status'] ?? 'unknown';
    if ($prev === $status || ($prev === 'unknown' && $status === 'up')) {
        return;
    }
    $msg = "Device {$dev['name']} is " . strtoupper($status);
    log_event($status === 'up' ? 'ok' : 'crit', $msg, (int)$dev['id']);
    alert_set((int)$dev['id'], null, $status === 'up' ? 'ok' : 'crit', $msg);
}

/** Threshold check: higher is worse, unless warn > crit (then lower is worse, e.g. toner). */
function alert_metric(array $dev, array $m, float $v): void
{
    if ($m['warn'] === null && $m['crit'] === null) {
        return;
    }
    $low = $m['warn'] !== null && $m['crit'] !== null && (float)$m['warn'] > (float)$m['crit'];
    $hit = fn($t) => $t !== null && ($low ? $v <= (float)$t : $v >= (float)$t);
    $state = $hit($m['crit']) ? 'crit' : ($hit($m['warn']) ? 'warn' : 'ok');
    $cur = db_val('SELECT state FROM alerts WHERE metric_id=?', [$m['id']]) ?: 'ok';
    if ($cur === $state) {
        return;
    }
    $msg = sprintf('%s / %s: %s (%s %s)', $dev['name'], $m['label'], strtoupper($state), fmt_num($v), $m['unit'] ?? '');
    log_event($state, $msg, (int)$dev['id'], (int)$m['id']);
    alert_set((int)$dev['id'], (int)$m['id'], $state, $msg);
}

function alert_set(int $device, ?int $metric, string $state, string $msg): void
{
    if ($metric === null) {
        db_exec('DELETE FROM alerts WHERE device_id=? AND metric_id IS NULL', [$device]);
    } else {
        db_exec('DELETE FROM alerts WHERE metric_id=?', [$metric]);
    }
    if ($state !== 'ok') {
        db_exec('INSERT INTO alerts (device_id, metric_id, state, since, notified) VALUES (?,?,?,?,0)',
            [$device, $metric, $state, time()]);
    }
    alert_mail($msg);
}

function alert_mail(string $msg): void
{
    $to = config('mail_to');
    if (!$to) {
        return;
    }
    $headers = 'From: ' . config('mail_from') . "\r\nContent-Type: text/plain; charset=UTF-8";
    @mail($to, '[' . config('app_name') . '] ' . $msg, $msg . "\n\n" . date('Y-m-d H:i:s T') . "\n", $headers);
}

function fmt_num(float $v): string
{
    $a = abs($v);
    foreach ([1e12 => 'T', 1e9 => 'G', 1e6 => 'M', 1e3 => 'k'] as $f => $s) {
        if ($a >= $f) {
            return round($v / $f, 2) . $s;
        }
    }
    return (string)round($v, 2);
}
