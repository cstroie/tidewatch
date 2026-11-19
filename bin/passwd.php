<?php
declare(strict_types=1);
// Usage: php bin/passwd.php   (prompts, writes admin_hash into config.php)
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
$cfg = "$root/config.php";
if (!is_file($cfg)) {
    copy("$root/config.php.dist", $cfg);
    chmod($cfg, 0640);
    echo "Created config.php from config.php.dist\n";
}
function ask(string $p): string {
    echo $p;
    @system('stty -echo');
    $v = rtrim((string)fgets(STDIN), "\r\n");
    @system('stty echo');
    echo "\n";
    return $v;
}
$a = ask('New admin password: ');
if (strlen($a) < 8) { fwrite(STDERR, "At least 8 characters.\n"); exit(1); }
if ($a !== ask('Repeat: ')) { fwrite(STDERR, "Mismatch.\n"); exit(1); }
require_once "$root/lib/db.php";
setting_set('admin_hash', password_hash($a, PASSWORD_DEFAULT)); // stored in the database; wins over config.php
if (setup_step() !== 'done' && !db_val("SELECT v FROM meta WHERE k = 'setup_step'")) {
    setup_step_set('done'); // set from the shell: no wizard needed
}
echo "Admin password set.\n";
