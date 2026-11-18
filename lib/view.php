<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function security_headers(): void
{
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}

function logo_svg(): string
{
    return '<svg class="logo" viewBox="0 0 32 32" aria-hidden="true"><circle cx="16" cy="16" r="15" class="logo-ring"/>'
        . '<path d="M4 18c3-3 6-3 9 0s6 3 9 0 5-3 6-2" class="logo-wave"/>'
        . '<path d="M6 23c2.5-2 5-2 7.5 0s5 2 7.5 0 4-2 5-1.5" class="logo-wave2"/></svg>';
}

function icon(string $name): string
{
    static $p = [
        'grid'   => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'server' => '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01"/>',
        'layout' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>',
        'bell'   => '<path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/>',
        'cog'    => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'sun'    => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon'   => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
        'menu'   => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'out'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'desktop'=> '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>',
        'laptop' => '<rect x="4" y="4" width="16" height="11" rx="1.5"/><path d="M2 19h20"/>',
        'router' => '<rect x="2" y="13" width="20" height="8" rx="2"/><path d="M6 17h.01M10 17h.01M17 13V8M14.5 5.5a3.5 3.5 0 0 1 5 0M12.4 3.4a6.5 6.5 0 0 1 9.2 0"/>',
        'switch' => '<rect x="2" y="7" width="20" height="10" rx="2"/><path d="M6 12h.01M9 12h.01M12 12h.01M15 12h.01M18 12h.01"/>',
        'printer'=> '<path d="M6 9V3h12v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><rect x="6" y="14" width="12" height="7" rx="1"/>',
        'nas'    => '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M8 6h8M8 10h8"/><circle cx="12" cy="16" r="2"/>',
        'sbc'    => '<rect x="6" y="6" width="12" height="12" rx="1.5"/><rect x="9.5" y="9.5" width="5" height="5" rx=".5"/><path d="M9 2v4M15 2v4M9 18v4M15 18v4M2 9h4M2 15h4M18 9h4M18 15h4"/>',
        'radio'  => '<circle cx="12" cy="9" r="2"/><path d="M12 11v11M8.5 5.5a5 5 0 0 0 0 7M15.5 5.5a5 5 0 0 1 0 7M5.6 2.6a9 9 0 0 0 0 12.8M18.4 2.6a9 9 0 0 1 0 12.8"/>',
        'ap'     => '<path d="M5 12.5a10 10 0 0 1 14 0M8.5 16a5 5 0 0 1 7 0M2 8.8a15 15 0 0 1 20 0M12 20h.01"/>',
        'ups'    => '<rect x="2" y="7" width="18" height="10" rx="2"/><path d="M22 11v2M11.5 9.5 9 12.5h4l-2.5 3"/>',
        'globe'  => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
    ];
    return '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}

/** Page shell. $active is the nav key; $public hides the admin nav. */
function page_start(string $title, string $active = '', bool $public = false): void
{
    security_headers();
    $app = e(config('app_name', 'Tidewatch'));
    $nav = $public ? [] : [
        'overview'   => ['grid', 'Overview', 'app.php'],
        'devices'    => ['server', 'Devices', 'app.php?p=devices'],
        'dashboards' => ['layout', 'Dashboards', 'app.php?p=dashboards'],
        'alerts'     => ['bell', 'Alerts', 'app.php?p=alerts'],
        'settings'   => ['cog', 'Settings', 'app.php?p=settings'],
    ];
    $alerts = $public ? 0 : (int)db_val('SELECT COUNT(*) FROM alerts');
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title><?= e($title) ?> · <?= $app ?></title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/vendor/uPlot.min.css">
<?php if (!$public || is_admin()): ?><meta name="csrf" content="<?= csrf_token() ?>"><?php endif ?>
<link rel="stylesheet" href="assets/css/app.css?v=<?= filemtime(dirname(__DIR__) . '/public/assets/css/app.css') ?>">
<script src="assets/js/theme.js"></script>
</head>
<body class="<?= $public ? 'is-public' : '' ?>">
<header class="topbar">
  <?php if (!$public): ?><button class="icon-btn nav-toggle" aria-label="Menu" data-nav-toggle><?= icon('menu') ?></button><?php endif ?>
  <a class="brand" href="<?= $public ? 'index.php' : 'app.php' ?>"><?= logo_svg() ?><span><?= $app ?></span></a>
  <div class="topbar-right">
    <?php if ($public): ?>
      <a class="btn btn-ghost btn-sm" href="login.php">Sign in</a>
    <?php else: ?>
      <a class="icon-btn" href="index.php" title="Public status page"><?= icon('globe') ?></a>
    <?php endif ?>
    <button class="icon-btn" data-theme-toggle aria-label="Toggle theme" title="Toggle theme"><span class="only-light"><?= icon('moon') ?></span><span class="only-dark"><?= icon('sun') ?></span></button>
    <?php if (!$public): ?>
      <form method="post" action="login.php?logout=1" class="inline"><?= csrf_field() ?><button class="icon-btn" title="Sign out" aria-label="Sign out"><?= icon('out') ?></button></form>
    <?php endif ?>
  </div>
</header>
<div class="shell">
<?php if (!$public): ?>
  <nav class="sidenav" data-nav>
    <?php foreach ($nav as $k => [$ic, $label, $href]): ?>
      <a href="<?= $href ?>" class="<?= $k === $active ? 'active' : '' ?>"><?= icon($ic) ?><span><?= $label ?></span><?php if ($k === 'alerts' && $alerts): ?><b class="pill pill-crit"><?= $alerts ?></b><?php endif ?></a>
    <?php endforeach ?>
    <div class="sidenav-foot">Tidewatch · <?= date('H:i') ?></div>
  </nav>
  <div class="scrim" data-nav-toggle></div>
<?php endif ?>
<main class="main">
<?php
}

function page_end(array $scripts = []): void
{
    ?>
</main>
</div>
<script src="assets/js/app.js"></script>
<?php foreach ($scripts as $s): ?><script src="assets/js/<?= e($s) ?>"></script>
<?php endforeach ?>
</body>
</html>
<?php
}

function flash(?string $msg = null, string $type = 'ok'): string
{
    session_boot();
    if ($msg !== null) {
        $_SESSION['flash'] = [$type, $msg];
        return '';
    }
    if (empty($_SESSION['flash'])) {
        return '';
    }
    [$t, $m] = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return '<div class="flash flash-' . e($t) . '">' . e($m) . '</div>';
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

const CHART_JS = ['../vendor/uPlot.iife.min.js', 'gauge.js', 'charts.js'];

function range_picker(): string
{
    $h = '<span class="chart-age" data-chart-age></span><div class="seg" data-range-picker>';
    foreach (array_keys(RANGES) as $r) {
        $h .= '<button type="button" data-range="' . $r . '">' . $r . '</button>';
    }
    return $h . '</div>';
}

const DEVICE_ICONS = ['server' => 'Server', 'desktop' => 'Desktop / thin client', 'laptop' => 'Laptop', 'sbc' => 'Single-board / embedded',
    'router' => 'Router', 'switch' => 'Switch', 'ap' => 'Wireless AP', 'nas' => 'NAS / storage', 'printer' => 'Printer',
    'radio' => 'Radio / SDR', 'ups' => 'UPS / power'];

/** Best guess of a device icon from its sysDescr. */
function device_icon_guess(?string $descr): string
{
    $d = (string)$descr;
    $rules = [
        'printer' => '/printer|printing system|kyocera|laserjet|officejet|brother|epson|canon|ricoh|lexmark|xerox|konica/i',
        'router'  => '/openwrt|lede|routeros|mikrotik|edgeos|pfsense|opnsense|vyos|dd-wrt|fritz!?box/i',
        'ap'      => '/unifi|access point|airos|aironet/i',
        'switch'  => '/switch|procurve|catalyst|junos|netgear gs|tl-sg|cisco ios/i',
        'nas'     => '/synology|diskstation|qnap|truenas|freenas|readynas|nsa3|zyxel nsa/i',
        'ups'     => '/\bups\b|smart-ups|apc web|eaton/i',
        'desktop' => '/windows/i',
        'laptop'  => '/darwin|macos/i',
        'sbc'     => '/armv\d|aarch64|arm64|raspberry|msm\d|mips/i',
    ];
    foreach ($rules as $icon => $re) {
        if (preg_match($re, $d)) {
            return $icon;
        }
    }
    return 'server';
}

function device_icon_name(array $dev): string
{
    $i = (string)($dev['icon'] ?? '');
    return isset(DEVICE_ICONS[$i]) ? $i : device_icon_guess($dev['sysdescr'] ?? null);
}

/** Icon tile with a status dot in the corner. */
function device_badge(array $dev, string $size = ''): string
{
    $st = $dev['status'] ?? 'unknown';
    $cls = $st === 'down' ? ' is-down' : ($st === 'up' ? ' is-up' : '');
    $name = device_icon_name($dev);
    return '<span class="dev-badge' . $cls . ($size ? " dev-badge-$size" : '') . '" title="' . e(DEVICE_ICONS[$name] . ' · ' . $st) . '">'
        . icon($name) . status_dot($st) . '</span>';
}

function status_dot(string $status): string
{
    $s = in_array($status, ['up', 'down', 'warn', 'crit'], true) ? $status : 'unknown';
    return '<span class="dot dot-' . $s . '" title="' . $s . '"></span>';
}

function fmt_uptime(?int $ticks): string
{
    if (!$ticks) {
        return '—';
    }
    $s = intdiv($ticks, 100);
    $d = intdiv($s, 86400);
    $h = intdiv($s % 86400, 3600);
    $m = intdiv($s % 3600, 60);
    return $d ? "{$d}d {$h}h" : ($h ? "{$h}h {$m}m" : "{$m}m");
}

function fmt_ago(?int $ts): string
{
    if (!$ts) {
        return 'never';
    }
    $d = time() - $ts;
    return $d < 60 ? "{$d}s ago" : ($d < 3600 ? intdiv($d, 60) . 'm ago' : ($d < 86400 ? intdiv($d, 3600) . 'h ago' : intdiv($d, 86400) . 'd ago'));
}
