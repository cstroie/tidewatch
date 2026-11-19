<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/view.php';
session_boot();

if (isset($_GET['logout'])) {
    csrf_check();
    logout();
    header('Location: index.php');
    exit;
}

$next = (string)($_GET['next'] ?? $_POST['next'] ?? 'app.php');
// only allow local relative redirects into the app
if (!preg_match('#^(/[^/\\\\]|app\.php)#', $next) || str_contains($next, '//')) {
    $next = 'app.php';
}
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    if (login_attempt((string)($_POST['password'] ?? ''))) {
        header('Location: ' . $next);
        exit;
    }
    $error = config('admin_hash') ? 'Wrong password, or too many attempts. Try again shortly.' : 'No admin password set. Run: php bin/passwd.php';
}
if (is_admin()) {
    header('Location: app.php');
    exit;
}
page_start('Sign in', '', true);
?>
<div class="login-wrap">
  <form class="card login" method="post" autocomplete="off">
    <div class="brand-big"><?= logo_svg() ?><h1><?= e(config('app_name')) ?></h1><span class="muted small">Administrator sign in</span></div>
    <?php if ($error): ?><?= notice($error, 'error') ?><?php endif ?>
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <div class="field"><label>Password
      <input type="password" name="password" autofocus required autocomplete="current-password">
      <small>Set on the server with php bin/passwd.php.</small></label></div>
    <button class="btn">Sign in</button>
    <p class="small muted back"><a href="index.php">← Public status</a></p>
  </form>
</div>
<?php
page_end();
