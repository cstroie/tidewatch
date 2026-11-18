<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

/** Base URL of a Prometheus-type device, e.g. http://10.0.0.5:80/metrics */
function prom_url(array $dev): string
{
    $scheme = ($dev['scheme'] ?? 'http') === 'https' ? 'https' : 'http';
    $path = '/' . ltrim((string)($dev['path'] ?? '') ?: '/metrics', '/');
    $host = str_contains((string)$dev['host'], ':') && $dev['host'][0] !== '[' ? '[' . $dev['host'] . ']' : $dev['host'];
    $port = (int)$dev['port'];
    $default = $scheme === 'https' ? 443 : 80;
    return "$scheme://$host" . ($port && $port !== $default ? ":$port" : '') . $path;
}

/** Fetch the exposition body. Throws RuntimeException on transport or HTTP error. */
function prom_fetch(array $dev): string
{
    $url = prom_url($dev);
    $timeout = (float)config('http_timeout', 5);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int)($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int)($timeout * 1000),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Accept: text/plain;version=0.0.4'],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException("HTTP fetch failed: $err");
        }
        if ($code !== 200) {
            throw new RuntimeException("HTTP $code");
        }
        return (string)$body;
    }
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true, 'header' => "Accept: text/plain\r\n"]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        throw new RuntimeException('HTTP fetch failed');
    }
    $code = isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m) ? (int)$m[1] : 0;
    if ($code !== 200) {
        throw new RuntimeException("HTTP $code");
    }
    return $body;
}

/**
 * Parse Prometheus text exposition format.
 * Returns [key => ['name','labels','value','type','help']] where key is the canonical
 * series identity: name{label="v",...} with labels sorted. Non-finite values are skipped.
 */
function prom_parse(string $body): array
{
    $types = $helps = $out = [];
    foreach (preg_split('/\r?\n/', $body) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if ($line[0] === '#') {
            if (preg_match('/^#\s+(TYPE|HELP)\s+(\S+)\s+(.*)$/', $line, $m)) {
                if ($m[1] === 'TYPE') {
                    $types[$m[2]] = strtolower(trim($m[3]));
                } else {
                    $helps[$m[2]] = trim($m[3]);
                }
            }
            continue;
        }
        if (!preg_match('/^([A-Za-z_:][A-Za-z0-9_:]*)(?:\{((?:[^"}]|"(?:[^"\\\\]|\\\\.)*")*)\})?\s+(\S+)(?:\s+-?\d+)?$/', $line, $m)) {
            continue;
        }
        if (!is_numeric($m[3]) || !is_finite((float)$m[3])) {
            continue; // NaN, +Inf, -Inf
        }
        $labels = [];
        if (($m[2] ?? '') !== '' && preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)\s*=\s*"((?:[^"\\\\]|\\\\.)*)"/', $m[2], $lm, PREG_SET_ORDER)) {
            foreach ($lm as $l) {
                $labels[$l[1]] = $l[2];
            }
            ksort($labels);
        }
        $key = $m[1];
        if ($labels) {
            $key .= '{' . implode(',', array_map(fn($k, $v) => "$k=\"$v\"", array_keys($labels), $labels)) . '}';
        }
        $out[$key] = ['name' => $m[1], 'labels' => $labels, 'value' => (float)$m[3], 'type' => '', 'help' => ''];
    }
    foreach ($out as $k => &$s) {
        $base = $s['name'];
        $s['type'] = $types[$base] ?? '';
        $s['help'] = $helps[$base] ?? '';
    }
    return $out;
}

/** Guess a display label and unit for a series. */
function prom_describe(array $s): array
{
    static $units = [
        'temp' => '°C', 'temperature' => '°C', 'hmdt' => '%', 'humidity' => '%', 'baro' => 'hPa', 'pressure' => 'hPa',
        'lux' => 'lx', 'rssi' => 'dBm', 'vcc' => 'V', 'volt' => 'V', 'voltage' => 'V', 'uptime' => 'min',
        'load' => '%', 'free_ram' => 'B', 'free_stack' => 'B',
    ];
    $name = $s['name'];
    $vn = $s['labels']['valueName'] ?? null;
    $short = preg_replace('/^espeasy_/', '', $name);
    $label = $vn !== null ? preg_replace('/^device_/', '', $short) . ' ' . $vn : $short;
    $last = preg_replace('/^.*_/', '', strtolower($short));
    $unit = $units[strtolower($vn ?? '')] ?? $units[strtolower($short)] ?? ($vn === null ? ($units[$last] ?? null) : null);
    if ($unit === null && preg_match('/_(bytes|seconds|celsius|percent|volts|watts)(_total)?$/', $name, $m)) {
        $unit = ['bytes' => 'B', 'seconds' => 's', 'celsius' => '°C', 'percent' => '%', 'volts' => 'V', 'watts' => 'W'][$m[1]];
    }
    return [str_replace('_', ' ', $label), $unit];
}

/** Prometheus counters that are really monotonic levels (uptime etc.) are stored as gauges. */
function prom_metric_type(array $s): string
{
    if ($s['type'] !== 'counter' || preg_match('/(uptime|connected)/i', $s['name'] . ($s['labels']['valueName'] ?? ''))) {
        return 'gauge';
    }
    return 'counter';
}

// ESPEasy layout: espeasy_<stat> are system stats; espeasy_device_<task>{valueName=...} are task values.
const PROM_SYS_VALUES  = ['rssi', 'load', 'uptime', 'heap', 'mem'];            // a task holding these is telemetry
const PROM_AUX_VALUES  = ['min', 'hour', 'day', 'week', 'lat', 'lon', 'pass', 'idx']; // clock / APRS helpers
const PROM_QUANTITIES  = [ // valueName => [label, unit, gauge min, gauge max]
    'temp' => ['Temperature', '°C', -20, 50], 'temperature' => ['Temperature', '°C', -20, 50],
    'hmdt' => ['Humidity', '%', 0, 100], 'humidity' => ['Humidity', '%', 0, 100],
    'baro' => ['Pressure', 'hPa', 950, 1050], 'pressure' => ['Pressure', 'hPa', 950, 1050],
    'lux' => ['Light', 'lx', null, null], 'power' => ['Power', 'W', null, null], 'current' => ['Current', 'A', null, null],
    'vcc' => ['Supply', 'V', null, null], 'voltage' => ['Voltage', 'V', null, null], 'co2' => ['CO2', 'ppm', 400, 2000],
];

/**
 * Classify every series of a page: [key => ['kind','label','unit','gmin','gmax','task','vn']].
 * kind: 'cpu' (device load), 'sensor' (task readings), 'custom' (system stats, telemetry and helper tasks).
 */
function prom_classify(array $series): array
{
    $tasks = [];
    foreach ($series as $s) {
        if (preg_match('/^espeasy_device_(.+)$/', $s['name'], $m) && isset($s['labels']['valueName'])) {
            $tasks[$m[1]][] = strtolower($s['labels']['valueName']);
        }
    }
    $out = [];
    foreach ($series as $key => $s) {
        [$label, $unit] = prom_describe($s);
        $r = ['kind' => 'custom', 'label' => $label, 'unit' => $unit, 'gmin' => null, 'gmax' => null, 'task' => null, 'vn' => null];
        if (preg_match('/^espeasy_device_(.+)$/', $s['name'], $m) && isset($s['labels']['valueName'])) {
            $task = $m[1];
            $vn = strtolower($s['labels']['valueName']);
            $r['task'] = $task;
            $r['vn'] = $vn;
            if (!array_intersect($tasks[$task], PROM_SYS_VALUES) && !in_array($vn, PROM_AUX_VALUES, true)) {
                $r['kind'] = 'sensor';
                if (isset(PROM_QUANTITIES[$vn])) {
                    [$q, $r['unit'], $r['gmin'], $r['gmax']] = PROM_QUANTITIES[$vn];
                    $r['label'] = "$q ($task)";
                }
            }
        } elseif ($s['name'] === 'espeasy_load') {
            $r = ['kind' => 'cpu', 'label' => 'Load', 'unit' => '%'] + $r;
        } elseif ($s['name'] === 'espeasy_wifi_rssi') {
            $r['label'] = 'WiFi RSSI';
            $r['unit'] = 'dBm';
            $r['gmin'] = -100;
            $r['gmax'] = -30;
        }
        $out[$key] = $r;
    }
    return $out;
}

/**
 * Default overview card for a Prometheus device: [gauge keys (<=3), graph key|null].
 * Prefers the task with the most of temperature/humidity/pressure; falls back to load and RSSI.
 */
function prom_card_defaults(array $cls): array
{
    $env = ['temp' => 1, 'temperature' => 1, 'hmdt' => 1, 'humidity' => 1, 'baro' => 1, 'pressure' => 1];
    $score = [];
    foreach ($cls as $c) {
        if ($c['kind'] === 'sensor' && isset($env[$c['vn']])) {
            $score[$c['task']] = ($score[$c['task']] ?? 0) + 1;
        }
    }
    $gauges = [];
    $graph = null;
    if ($score) {
        $best = array_search(max($score), $score, true); // first task with the top score
        foreach (['temp', 'temperature', 'hmdt', 'humidity', 'baro', 'pressure'] as $vn) {
            foreach ($cls as $k => $c) {
                if ($c['task'] === $best && $c['vn'] === $vn) {
                    $gauges[] = $k;
                    $graph ??= $k;
                }
            }
        }
    }
    foreach ($cls as $k => $c) { // fill up with other recognised readings
        if (count($gauges) < 3 && $c['kind'] === 'sensor' && isset(PROM_QUANTITIES[$c['vn']]) && !in_array($k, $gauges, true)) {
            $gauges[] = $k;
            $graph ??= $k;
        }
    }
    if (!$gauges) {
        foreach (['espeasy_load', 'espeasy_wifi_rssi'] as $k) {
            if (isset($cls[$k])) {
                $gauges[] = $k;
                $graph ??= $k;
            }
        }
    }
    return [array_slice($gauges, 0, 3), $graph];
}

/** Fetch and parse a device; returns the series map. */
function prom_read(array $dev): array
{
    return prom_parse(prom_fetch($dev));
}
