<?php
declare(strict_types=1);

// Group-writable files so cron (owner) and the web server (group) share the SQLite DB/WAL.
umask(0007);

function config(?string $key = null, $default = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $file = dirname(__DIR__) . '/config.php';
        if (!is_file($file)) {
            $file = dirname(__DIR__) . '/config.php.dist';
        }
        $cfg = require $file;
        date_default_timezone_set($cfg['timezone'] ?? 'UTC');
    }
    return $key === null ? $cfg : ($cfg[$key] ?? $default);
}

/** Close the shared connection (call before pcntl_fork so children open their own). */
function db_close(): void
{
    $GLOBALS['__tw_pdo'] = null;
}

function db(): PDO
{
    if (!empty($GLOBALS['__tw_pdo'])) {
        return $GLOBALS['__tw_pdo'];
    }
    $dsn = config('db_dsn');
    if (str_starts_with($dsn, 'sqlite:')) {
        sqlite_prepare_file(substr($dsn, 7));
    }
    $pdo = $GLOBALS['__tw_pdo'] = new PDO($dsn, config('db_user'), config('db_pass'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    if (db_is_sqlite()) {
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA synchronous=NORMAL');
        $pdo->exec('PRAGMA foreign_keys=ON');
        $pdo->exec('PRAGMA busy_timeout=5000');
    }
    db_migrate($pdo);
    return $pdo;
}

/**
 * SQLite creates files 0644 whatever the umask, so pre-create the database 0660:
 * cron (owner) and the web server (group) must both write it. SQLite gives the
 * -wal/-shm files the same mode as the main file.
 */
function sqlite_prepare_file(string $file): void
{
    if ($file === '' || $file === ':memory:') {
        return;
    }
    if (!file_exists($file)) {
        @touch($file);
    }
    if (is_file($file) && @fileowner($file) === (function_exists('posix_geteuid') ? posix_geteuid() : getmyuid()) && (fileperms($file) & 0660) !== 0660) {
        @chmod($file, 0660);
    }
}

function db_is_sqlite(): bool
{
    return str_starts_with((string)config('db_dsn'), 'sqlite:');
}

function db_migrate(PDO $pdo): void
{
    $version = 5;
    $cur = 0;
    try {
        $cur = (int)$pdo->query("SELECT v FROM meta WHERE k='schema'")->fetchColumn();
        if ($cur >= $version) {
            return;
        }
    } catch (PDOException $e) {
        // fresh database
    }
    if ($cur === 0) {
        // fresh install: the schema files are always current
        $file = dirname(__DIR__) . '/sql/schema.' . (db_is_sqlite() ? 'sqlite' : 'mysql') . '.sql';
        foreach (array_filter(array_map('trim', explode(';', file_get_contents($file)))) as $stmt) {
            $pdo->exec($stmt);
        }
    } else {
        // incremental upgrades
        if ($cur < 2) {
            $pdo->exec('ALTER TABLE devices ADD COLUMN icon ' . (db_is_sqlite() ? 'TEXT' : 'VARCHAR(16)'));
        }
        if ($cur < 3) {
            $pdo->exec('ALTER TABLE devices ADD COLUMN syscontact TEXT');
            $pdo->exec('ALTER TABLE devices ADD COLUMN syslocation TEXT');
        }
        if ($cur < 4) {
            $t = db_is_sqlite();
            $pdo->exec("ALTER TABLE devices ADD COLUMN type " . ($t ? 'TEXT' : 'VARCHAR(8)') . " NOT NULL DEFAULT 'snmp'");
            $pdo->exec("ALTER TABLE devices ADD COLUMN scheme " . ($t ? 'TEXT' : 'VARCHAR(5)') . " NOT NULL DEFAULT 'http'");
            $pdo->exec("ALTER TABLE devices ADD COLUMN path " . ($t ? 'TEXT' : 'VARCHAR(255)') . " NOT NULL DEFAULT '/metrics'");
        }
        if ($cur < 5) {
            $t = db_is_sqlite();
            $pdo->exec('ALTER TABLE metrics ADD COLUMN ov_gauge ' . ($t ? 'INTEGER' : 'TINYINT') . ' NOT NULL DEFAULT 0');
            $pdo->exec('ALTER TABLE metrics ADD COLUMN ov_graph ' . ($t ? 'INTEGER' : 'TINYINT') . ' NOT NULL DEFAULT 0');
            $pdo->exec('ALTER TABLE metrics ADD COLUMN g_min ' . ($t ? 'REAL' : 'DOUBLE'));
            $pdo->exec('ALTER TABLE metrics ADD COLUMN g_max ' . ($t ? 'REAL' : 'DOUBLE'));
        }
    }
    db_upsert('meta', ['k' => 'schema', 'v' => (string)$version], ['k'], $pdo);
}

/** Insert or replace a row, portable between SQLite and MySQL. */
function db_upsert(string $table, array $row, array $keys, ?PDO $pdo = null): void
{
    $pdo = $pdo ?? db();
    $cols = array_keys($row);
    $ph = implode(',', array_fill(0, count($cols), '?'));
    $upd = array_diff($cols, $keys);
    if (db_is_sqlite()) {
        $set = implode(',', array_map(fn($c) => "$c=excluded.$c", $upd));
        $sql = "INSERT INTO $table (" . implode(',', $cols) . ") VALUES ($ph) ON CONFLICT(" . implode(',', $keys) . ")"
             . ($set ? " DO UPDATE SET $set" : ' DO NOTHING');
    } else {
        $set = implode(',', array_map(fn($c) => "$c=VALUES($c)", $upd ?: $keys));
        $sql = "INSERT INTO $table (" . implode(',', $cols) . ") VALUES ($ph) ON DUPLICATE KEY UPDATE $set";
    }
    $pdo->prepare($sql)->execute(array_values($row));
}

function db_all(string $sql, array $args = []): array
{
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

function db_one(string $sql, array $args = []): ?array
{
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetch() ?: null;
}

function db_val(string $sql, array $args = [])
{
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchColumn();
}

function db_exec(string $sql, array $args = []): int
{
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->rowCount();
}

function log_event(string $level, string $msg, ?int $device = null, ?int $metric = null): void
{
    db_exec('INSERT INTO events (ts, device_id, metric_id, level, message) VALUES (?,?,?,?,?)',
        [time(), $device, $metric, $level, $msg]);
}
