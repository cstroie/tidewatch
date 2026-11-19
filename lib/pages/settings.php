<?php
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['act'] ?? '') === 'password') {
    $cur = (string)($_POST['current'] ?? '');
    $new = (string)($_POST['password'] ?? '');
    if (!password_verify($cur, (string)setting('admin_hash', ''))) {
        usleep(500000);
        log_event('warn', 'Password change refused: wrong current password');
        flash('The current password is wrong.', 'err');
    } elseif (strlen($new) < 8) {
        flash('The new password needs at least 8 characters.', 'err');
    } elseif ($new !== (string)($_POST['repeat'] ?? '')) {
        flash('The two new passwords differ.', 'err');
    } else {
        setting_set('admin_hash', password_hash($new, PASSWORD_DEFAULT));
        auth_sign_in(); // new session id after a credential change
        log_event('info', 'Admin password changed');
        flash('Password changed.');
    }
    redirect('app.php?p=settings');
}
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
$row = fn($k, $v) => '<tr><td class="muted">' . e($k) . '</td><td>' . $v . '</td></tr>';
page_start('Settings', 'settings');
?>
<div class="page-head"><hgroup><h1>Settings</h1><p>Configuration lives in <code>config.php</code>; only the password is changed here.</p></hgroup></div>
<?= flash() ?>
<div class="grid grid-2">
  <div class="card flush"><header class="card-head"><hgroup><h2>Collector</h2></hgroup></header><table class="tbl">
    <?= $row('SNMP driver', e($eff) . ($driver === 'auto' ? ' <span class="pill">auto</span>' : '')) ?>
    <?= $row('Timeout / retries', e(config('snmp_timeout')) . 's / ' . e(config('snmp_retries'))) ?>
    <?= $row('Parallel workers', e(config('poll_workers')) . ($pcntl === '0' || $pcntl === 0 ? ' <span class="pill pill-warn">pcntl missing in CLI PHP: sequential</span>' : '')) ?>
    <?= $row('Last poll', $lastPoll ? e(date('Y-m-d H:i:s', $lastPoll)) . ' <span class="muted">(' . e(fmt_ago($lastPoll)) . ')</span>' . (time() - $lastPoll > 180 ? ' <span class="pill pill-crit">cron not running?</span>' : '') : 'never') ?>
    <?= $row('Last rollup', $lastRoll ? e(date('Y-m-d H:i', $lastRoll)) : 'never') ?>
    <?= $row('Alert e-mail', setting('mail_to') ? e(setting('mail_to')) : '<span class="muted">disabled</span>') ?>
  </table></div>
  <div class="card flush"><header class="card-head"><hgroup><h2>Storage</h2></hgroup></header><table class="tbl">
    <?= $row('Database', e(db_is_sqlite() ? 'SQLite' : 'MariaDB/MySQL') . ($dbSize ? ' · ' . e(fmt_num((float)$dbSize)) . 'B' : '')) ?>
    <?php foreach ($counts as $k => $v): ?><?= $row($k, e(number_format($v))) ?><?php endforeach ?>
    <?= $row('Retention', 'raw ' . intdiv((int)config('keep_raw'), 86400) . 'd · 5-min ' . intdiv((int)config('keep_5m'), 86400) . 'd · hourly ' . intdiv((int)config('keep_1h'), 86400) . 'd') ?>
  </table></div>
</div>
<div class="card mt">
  <header class="card-head"><hgroup><h2>Cron</h2><p>Add these lines to the web server user's crontab.</p></hgroup></header>
  <pre class="mono small cron"><?= e(cron_lines()) ?></pre>
</div>
<form method="post" class="card mt narrow">
  <?= csrf_field() ?><input type="hidden" name="act" value="password">
  <header class="card-head"><hgroup><h2>Admin password</h2><p>Forgotten it? Set a new one on the server with <code>php bin/passwd.php</code>.</p></hgroup></header>
  <div class="field"><label>Current password
    <input type="password" name="current" required autocomplete="current-password">
    <small>The one you signed in with.</small></label></div>
  <div class="form-grid">
    <div class="field"><label>New password
      <input type="password" name="password" required minlength="8" autocomplete="new-password">
      <small>At least 8 characters.</small></label></div>
    <div class="field"><label>Repeat
      <input type="password" name="repeat" required minlength="8" autocomplete="new-password">
      <small>The new password again.</small></label></div>
  </div>
  <footer><button class="btn">Change password</button></footer>
</form>
<div class="card mt">
  <p class="small muted">PHP <?= PHP_VERSION ?> · Tidewatch · uPlot 1.6.30</p>
</div>
<?php
page_end();
