<?php
/** Setup wizard view; variables come from public/setup.php. */
$keys = array_keys($steps);
$at = array_search($step, $keys, true);
page_start('Setup', '', true);
?>
<div class="setup">
<div class="page-head"><hgroup><h1>Set up <?= e(setting('app_name', 'Tidewatch')) ?></h1><p>Step <?= $at + 1 ?> of <?= count($steps) ?>: <?= e($steps[$step]) ?></p></hgroup></div>
<ol class="steps">
  <?php foreach ($keys as $i => $k): ?><li class="<?= $i < $at ? 'is-done' : ($i === $at ? 'is-current' : '') ?>"<?= $i === $at ? ' aria-current="step"' : '' ?>><?= e($steps[$k]) ?></li><?php endforeach ?>
</ol>
<?= flash() ?>

<?php if ($step === 'check'): ?>
  <div class="card flush">
    <header class="card-head"><hgroup><h2>System check</h2><p>What this server needs to poll devices and keep their history.</p></hgroup></header>
    <div class="table-wrap"><table class="tbl">
      <thead><tr><th>Check</th><th>Result</th><th>Detail</th></tr></thead>
      <tbody>
      <?php foreach ($checks as [$name, $state, $detail]): ?>
        <tr><td><?= e($name) ?></td><td><span class="pill pill-<?= $state === 'fail' ? 'crit' : $state ?>"><?= $state === 'ok' ? 'pass' : $state ?></span></td><td class="wrap"><?= e($detail) ?></td></tr>
      <?php endforeach ?>
      </tbody></table></div>
    <footer>
      <a class="btn btn-ghost" href="setup.php">Check again</a>
      <?php if ($failed || $dbError !== ''): ?><button class="btn" type="button" disabled>Next</button>
      <?php else: ?><a class="btn" href="setup.php?step=password">Next</a><?php endif ?>
    </footer>
  </div>

<?php elseif ($step === 'password'): ?>
  <?= notice('Until a password is set, anyone who opens this page can set it. Finish this step now.', 'warn') ?>
  <form method="post" action="setup.php?step=password" class="card">
    <?= csrf_field() ?>
    <header class="card-head"><hgroup><h2>Admin password</h2><p>There is one administrator; the status page stays public.</p></hgroup></header>
    <div class="field"><label>Password
      <input type="password" name="password" required minlength="8" autocomplete="new-password" autofocus>
      <small>At least 8 characters. Change it later with php bin/passwd.php.</small></label></div>
    <div class="field"><label>Repeat
      <input type="password" name="repeat" required minlength="8" autocomplete="new-password">
      <small>The same password again.</small></label></div>
    <footer><a class="btn btn-ghost" href="setup.php">Back</a><button class="btn">Set password</button></footer>
  </form>

<?php elseif ($step === 'basics'): $tzNow = date_default_timezone_get(); ?>
  <form method="post" action="setup.php?step=basics" class="card">
    <?= csrf_field() ?>
    <header class="card-head"><hgroup><h2>Basics</h2><p>Saved in the database; they take precedence over config.php.</p></hgroup></header>
    <div class="form-grid">
      <div class="field"><label>Name
        <input type="text" name="app_name" maxlength="64" value="<?= e(setting('app_name', 'Tidewatch')) ?>" placeholder="Home lab">
        <small>Shown in the top bar and on the status page.</small></label></div>
      <div class="field"><label>Timezone
        <select name="timezone"><?php foreach (DateTimeZone::listIdentifiers() as $tz): ?><option <?= $tz === $tzNow ? 'selected' : '' ?>><?= e($tz) ?></option><?php endforeach ?></select>
        <small>Used for every time and graph axis.</small></label></div>
      <div class="field"><label>Alert e-mail
        <input type="email" name="mail_to" value="<?= e(setting('mail_to', '')) ?>" placeholder="ops@example.org">
        <small>Optional. Left empty, alerts are only shown in the app.</small></label></div>
    </div>
    <footer><button class="btn">Next</button></footer>
  </form>

<?php elseif ($step === 'device'):
    device_form(null, 'setup.php?step=device', '<button class="btn btn-ghost" name="skip" value="1" formnovalidate>Skip for now</button>');
?>

<?php else: // cron
    $since = (int)db_val("SELECT v FROM meta WHERE k = 'setup_cron_since'");
    $last = (int)db_val('SELECT MAX(last_poll) FROM devices');
    $hasDev = (int)db_val('SELECT COUNT(*) FROM devices WHERE enabled = 1') > 0;
?>
  <form method="post" action="setup.php?step=cron" class="card">
    <?= csrf_field() ?>
    <header class="card-head"><hgroup><h2>Cron</h2><p>Add these lines to the crontab of the user that owns data/ (crontab -e). The poller runs every minute, the rollup every five.</p></hgroup></header>
    <pre class="mono small cron"><?= e(cron_lines()) ?></pre>
    <?php if (!$hasDev): ?>
      <?= notice('No device to poll yet; add one from the Devices page once setup is done.', 'info') ?>
    <?php elseif ($last > $since): ?>
      <?= notice('The poller ran at ' . date('H:i:s', $last) . '. Cron is working.', 'ok') ?>
    <?php else: ?>
      <?= notice('Waiting for the first poll from cron. It can take up to a minute after the lines are added.', 'info') ?>
    <?php endif ?>
    <footer><a class="btn btn-ghost" href="setup.php?step=cron">Check again</a><button class="btn">Finish</button></footer>
  </form>
<?php endif ?>
</div>
<?php
page_end();
