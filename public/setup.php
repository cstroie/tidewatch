<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/view.php';
require_once dirname(__DIR__) . '/lib/data.php';
require_once dirname(__DIR__) . '/lib/devices.php';
require_once dirname(__DIR__) . '/lib/pages/device_form.php';
session_boot();

// The database must open before anything else; if it doesn't, only the check is shown.
$dbError = '';
try {
    db();
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$steps = ['check' => 'System check', 'password' => 'Admin password', 'basics' => 'Basics', 'device' => 'First device', 'cron' => 'Cron'];
$step = (string)($_GET['step'] ?? '');
if ($dbError === '') {
    if (setup_needed()) {
        $step = $step === 'password' ? 'password' : 'check'; // anyone may start setup until a password exists
    } else {
        if (!is_admin()) {
            redirect('login.php');
        }
        if (setup_step() === 'done') {
            redirect('app.php');
        }
        if (!in_array($step, ['basics', 'device', 'cron'], true)) {
            $step = in_array(setup_step(), ['basics', 'device', 'cron'], true) ? setup_step() : 'basics';
        }
    }
} else {
    $step = 'check';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $dbError === '') {
    csrf_check();
    if ($step === 'password' && setup_needed()) {
        $a = (string)($_POST['password'] ?? '');
        if (strlen($a) < 8) {
            flash('The password needs at least 8 characters.', 'err');
            redirect('setup.php?step=password');
        }
        if ($a !== (string)($_POST['repeat'] ?? '')) {
            flash('The two passwords differ.', 'err');
            redirect('setup.php?step=password');
        }
        setting_set('admin_hash', password_hash($a, PASSWORD_DEFAULT));
        setup_step_set('basics');
        auth_sign_in();
        log_event('info', 'Admin password set in the setup wizard');
        flash('Password set; you are signed in.');
        redirect('setup.php?step=basics');
    }
    if ($step === 'basics') {
        $tz = (string)($_POST['timezone'] ?? 'UTC');
        $mail = trim((string)($_POST['mail_to'] ?? ''));
        if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            flash('That alert e-mail address does not look valid.', 'err');
            redirect('setup.php?step=basics');
        }
        setting_set('app_name', mb_substr(trim((string)($_POST['app_name'] ?? '')), 0, 64) ?: 'Tidewatch');
        setting_set('timezone', in_array($tz, DateTimeZone::listIdentifiers(), true) ? $tz : 'UTC');
        setting_set('mail_to', $mail);
        setup_step_set('device');
        redirect('setup.php?step=device');
    }
    if ($step === 'device') {
        if (!empty($_POST['skip'])) {
            setup_step_set('cron');
            redirect('setup.php?step=cron');
        }
        $r = device_save($_POST);
        flash($r['msg'], $r['kind']);
        if (!$r['id']) {
            redirect('setup.php?step=device');
        }
        setup_step_set('cron');
        redirect('setup.php?step=cron');
    }
    if ($step === 'cron') {
        setup_step_set('done');
        db_exec("DELETE FROM meta WHERE k = 'setup_cron_since'");
        $seen = (int)db_val('SELECT MAX(last_poll) FROM devices') > 0;
        flash($seen ? 'Setup finished.' : 'Setup finished. No poll has been seen yet: check the cron lines on the Settings page.', $seen ? 'ok' : 'warn');
        redirect('app.php');
    }
}

// ---- system check ----
function snmp_cli_found(): bool
{
    foreach (explode(PATH_SEPARATOR, (string)getenv('PATH') ?: '/usr/bin:/bin:/usr/local/bin') as $dir) {
        if ($dir !== '' && is_executable("$dir/snmpget")) {
            return true;
        }
    }
    return false;
}
$checks = [];
if ($step === 'check') {
    $mysql = !db_is_sqlite();
    $pdoExt = $mysql ? 'pdo_mysql' : 'pdo_sqlite';
    $data = dirname(__DIR__) . '/data';
    $snmp = class_exists('SNMP') ? ['ok', 'php-snmp extension'] : (snmp_cli_found() ? ['ok', 'net-snmp command-line tools'] : ['fail', 'Install php-snmp or the net-snmp tools (snmpget, snmpwalk).']);
    $checks = [
        ['PHP 8.1 or newer', version_compare(PHP_VERSION, '8.1', '>=') ? 'ok' : 'fail', 'PHP ' . PHP_VERSION],
        [$pdoExt, extension_loaded($pdoExt) ? 'ok' : 'fail', extension_loaded($pdoExt) ? 'Loaded' : "Install the $pdoExt extension."],
        ['Database', $dbError === '' ? 'ok' : 'fail', $dbError === '' ? ($mysql ? 'MariaDB/MySQL connected' : 'SQLite file opened') : $dbError],
        ['data/ writable', is_writable($data) ? 'ok' : 'fail', is_writable($data) ? 'The web server can write it' : 'Give the web server group write access: chmod 2770 data'],
        ['SNMP', $snmp[0], $snmp[1]],
        ['pcntl', 'warn', 'Checked by the poller at its first run; without it, devices are polled one after another.'],
        ['config.php', is_file(dirname(__DIR__) . '/config.php') ? 'ok' : 'warn', is_file(dirname(__DIR__) . '/config.php') ? 'Present' : 'Not found: the defaults of config.php.dist are used.'],
    ];
}
$failed = (bool)array_filter($checks, fn($c) => $c[1] === 'fail');

if ($step === 'cron' && !db_val("SELECT v FROM meta WHERE k = 'setup_cron_since'")) {
    db_upsert('meta', ['k' => 'setup_cron_since', 'v' => (string)time()], ['k']);
}

require dirname(__DIR__) . '/lib/pages/setup.php';
