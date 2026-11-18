<?php
declare(strict_types=1);
require_once __DIR__ . '/metrics.php';
require_once __DIR__ . '/data.php';

/*
 * Live mode: read a device's metrics directly over SNMP, outside the poller.
 * Nothing is stored; counter state travels in $state (kept by the SSE loop,
 * or round-tripped by the browser in polling mode).
 */

const LIVE_KINDS = ['cpu', 'mem', 'if_in', 'if_out', 'load'];
const LIVE_MAX_METRICS = 32;

/** Metrics of a device eligible for live mode, limited to the requested ids (all eligible if empty). */
function live_metrics(int $device, array $ids = []): array
{
    $ms = db_all('SELECT * FROM metrics WHERE device_id = ? AND enabled = 1 AND kind IN (' . implode(',', array_fill(0, count(LIVE_KINDS), '?')) . ') ORDER BY kind, id', array_merge([$device], LIVE_KINDS));
    if ($ids) {
        $ms = array_values(array_filter($ms, fn($m) => in_array((int)$m['id'], $ids, true)));
    }
    return array_slice($ms, 0, LIVE_MAX_METRICS);
}

/**
 * One live sample. $state maps metric id => [t, raw string] for counters.
 * Returns ['t' => float seconds, 'v' => [id => ?float]] or null if the device did not answer.
 */
function live_sample(SnmpDriver $snmp, array $metrics, array &$state): ?array
{
    $oids = [];
    foreach ($metrics as $m) {
        foreach (preg_split('#[/,]#', $m['oid']) as $o) {
            $oids[$o] = true;
        }
    }
    try {
        $vals = $snmp->get(array_keys($oids));
    } catch (Throwable $e) {
        return null;
    }
    if (!$vals) {
        return null;
    }
    $t = microtime(true);
    $out = [];
    foreach ($metrics as $m) {
        $id = (int)$m['id'];
        $out[$id] = null;
        if ($m['type'] === 'counter' || $m['type'] === 'cpu_raw') {
            $parts = explode(',', $m['oid']);
            $cur = [];
            foreach ($parts as $o) {
                if (!isset($vals[$o]) || !is_numeric($vals[$o])) {
                    continue 2;
                }
                $cur[] = (string)$vals[$o];
            }
            $prev = $state[$id] ?? null;
            // agents cache counters for a few seconds (net-snmp ifTable): an unchanged raw value
            // means "not refreshed yet", so keep the old reference point instead of reporting 0
            if (is_array($prev) && $prev[1] === implode(',', $cur) && $t - (float)$prev[0] < 6) {
                continue;
            }
            $state[$id] = [$t, implode(',', $cur)];
            if (!is_array($prev) || count($prev) !== 2) {
                continue;
            }
            $dt = $t - (float)$prev[0];
            $old = explode(',', (string)$prev[1]);
            if ($dt <= 0.2 || $dt > 600 || count($old) !== count($cur)) {
                continue;
            }
            $d = [];
            foreach ($cur as $k => $v) {
                $d[$k] = (float)$v - (float)$old[$k];
                if ($d[$k] < 0) {
                    $d[$k] += (float)$old[$k] <= 4294967295 ? 4294967296.0 : 18446744073709551616.0;
                }
            }
            if ($m['type'] === 'counter') {
                if ($d[0] > 1.25e11 * $dt) {
                    continue; // counter reset
                }
                $out[$id] = round($d[0] / $dt * (float)$m['scale'], 3);
            } else {
                $tot = array_sum($d);
                $out[$id] = $tot > 0 ? round(100 * (1 - $d[0] / $tot), 2) : null;
            }
        } else {
            $out[$id] = metric_value($m, $vals, (int)$t, false); // gauge-like types do not touch the DB
        }
    }
    return ['t' => round($t, 3), 'v' => $out];
}

/** Metadata the browser needs to draw live charts. */
function live_meta(array $metrics): array
{
    return array_map(fn($m) => ['id' => (int)$m['id'], 'kind' => $m['kind'], 'label' => $m['label'], 'unit' => $m['unit']], $metrics);
}
