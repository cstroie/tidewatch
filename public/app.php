<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/view.php';
require dirname(__DIR__) . '/lib/data.php';
require dirname(__DIR__) . '/lib/discover.php';

require_admin();
csrf_check();

$pages = ['overview', 'device', 'devices', 'dashboards', 'dashboard', 'alerts', 'settings'];
$p = (string)($_GET['p'] ?? 'overview');
if (!in_array($p, $pages, true)) {
    http_response_code(404);
    $p = 'overview';
}
require dirname(__DIR__) . "/lib/pages/$p.php";
