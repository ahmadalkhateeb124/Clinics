<?php
// ────────────────────────────────────────────────────────────────────────
// PDO singleton for the admin portal
// ────────────────────────────────────────────────────────────────────────

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $isLocal = (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false);

    if ($isLocal) {
        $cfg = ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'name' => 'nourstouch', 'port' => 3306];
    } else {
        $f = __DIR__ . '/database.credentials.php';
        $cfg = is_file($f) ? require $f : ['host'=>'localhost','user'=>'','pass'=>'','name'=>'','port'=>3306];
    }

    try {
        $pdo = new PDO(
            "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']};charset=utf8mb4",
            $cfg['user'], $cfg['pass'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ]
        );
    } catch (Throwable $e) {
        error_log('NoursTouch admin PDO: ' . $e->getMessage());
        die('Database connection failed.');
    }
    return $pdo;
}
