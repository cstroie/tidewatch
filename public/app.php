<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/view.php';
require_once dirname(__DIR__) . '/lib/data.php';
require_once dirname(__DIR__) . '/lib/discover.php';

if (setup_needed()) {
    redirect('setup.php');
}
require_admin();
csrf_check();
if (setup_step() !== 'done') {
    redirect('setup.php');
}

$pages = ['overview', 'device', 'devices', 'device_add', 'dashboards', 'dashboard', 'alerts', 'settings'];
$p = (string)($_GET['p'] ?? 'overview');
if (!in_array($p, $pages, true)) {
    http_response_code(404);
    $p = 'overview';
}
require dirname(__DIR__) . "/lib/pages/$p.php";
