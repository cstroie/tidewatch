<?php
$driver = config('snmp_driver', 'auto');
$eff = $driver === 'auto' ? (class_exists('SNMP') ? 'ext (php-snmp)' : 'cli (net-snmp tools)') : $driver;
$counts = [];
foreach (['devices', 'metrics', 'samples_raw', 'samples_5m', 'samples_1h', 'events'] as $t) {
    $counts[$t] = (int)db_val("SELECT COUNT(*) FROM $t");
}
$lastPoll = (int)db_val('SELECT MAX(last_poll) FROM devices');
$lastRoll = (int)db_val("SELECT v FROM meta WHERE k = 'rollup_samples_5m'");
$dbSize = db_is_sqlite() ? @filesize(substr((string)config('db_dsn'), 7)) : null;
$pcntl = db_val("SELECT v FROM meta WHERE k='poll_pcntl'"); // written by the CLI poller
$root = dirname(__DIR__, 2);
$row = fn($k, $v) => '<tr><td class="muted">' . e($k) . '</td><td>' . $v . '</td></tr>';
page_start('Settings', 'settings');
?>
<div class="page-head"><hgroup><h1>Settings</h1><p>Configuration lives in <code>config.php</code>; this page is read-only.</p></hgroup></div>
<div class="grid grid-2">
  <div class="card flush"><header class="card-head"><hgroup><h2>Collector</h2></hgroup></header><table class="tbl">
    <?= $row('SNMP driver', e($eff) . ($driver === 'auto' ? ' <span class="pill">auto</span>' : '')) ?>
    <?= $row('Timeout / retries', e(config('snmp_timeout')) . 's / ' . e(config('snmp_retries'))) ?>
    <?= $row('Parallel workers', e(config('poll_workers')) . ($pcntl === '0' || $pcntl === 0 ? ' <span class="pill pill-warn">pcntl missing in CLI PHP: sequential</span>' : '')) ?>
    <?= $row('Last poll', $lastPoll ? e(date('Y-m-d H:i:s', $lastPoll)) . ' <span class="muted">(' . e(fmt_ago($lastPoll)) . ')</span>' . (time() - $lastPoll > 180 ? ' <span class="pill pill-crit">cron not running?</span>' : '') : 'never') ?>
    <?= $row('Last rollup', $lastRoll ? e(date('Y-m-d H:i', $lastRoll)) : 'never') ?>
    <?= $row('Alert e-mail', config('mail_to') ? e(config('mail_to')) : '<span class="muted">disabled</span>') ?>
  </table></div>
  <div class="card flush"><header class="card-head"><hgroup><h2>Storage</h2></hgroup></header><table class="tbl">
    <?= $row('Database', e(db_is_sqlite() ? 'SQLite' : 'MariaDB/MySQL') . ($dbSize ? ' · ' . e(fmt_num((float)$dbSize)) . 'B' : '')) ?>
    <?php foreach ($counts as $k => $v): ?><?= $row($k, e(number_format($v))) ?><?php endforeach ?>
    <?= $row('Retention', 'raw ' . intdiv((int)config('keep_raw'), 86400) . 'd · 5-min ' . intdiv((int)config('keep_5m'), 86400) . 'd · hourly ' . intdiv((int)config('keep_1h'), 86400) . 'd') ?>
  </table></div>
</div>
<div class="card mt">
  <header class="card-head"><hgroup><h2>Cron</h2><p>Add these lines to the web server user's crontab.</p></hgroup></header>
  <pre class="mono small cron">* * * * *   php <?= e($root) ?>/bin/poll.php
*/5 * * * * php <?= e($root) ?>/bin/rollup.php</pre>
</div>
<div class="card mt">
  <header class="card-head"><hgroup><h2>Admin password</h2><p>Change it on the server with <code>php bin/passwd.php</code>.</p></hgroup></header>
  <p class="small muted">PHP <?= PHP_VERSION ?> · Tidewatch · uPlot 1.6.30</p>
</div>
<?php
page_end();
