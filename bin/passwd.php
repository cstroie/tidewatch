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
$hash = password_hash($a, PASSWORD_DEFAULT);
$src = file_get_contents($cfg);
$src = preg_replace_callback("/'admin_hash'\s*=>\s*'[^']*'/", fn() => "'admin_hash' => " . var_export($hash, true), $src, 1, $n);
if (!$n) { fwrite(STDERR, "admin_hash key not found in config.php\n"); exit(1); }
file_put_contents($cfg, $src);
echo "Admin password set.\n";
