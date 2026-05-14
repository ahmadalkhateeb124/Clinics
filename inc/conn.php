<?php
// ── Nour's Touch Clinic — Public Site Connection & Helpers ───────────────
date_default_timezone_set('Asia/Amman');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$url      = $_SERVER['HTTP_HOST'] ?? 'localhost';
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$_isLocal = (strpos($url, 'localhost') !== false || strpos($url, '127.0.0.1') !== false);

if ($_isLocal) {
    $base_url  = $protocol . '://' . $url . '/nourstouch/';
    $base_path = $_SERVER['DOCUMENT_ROOT'] . '/nourstouch/';
} else {
    $base_url  = 'https://nourstouch.com/';
    $base_path = $_SERVER['DOCUMENT_ROOT'] . '/';
}

// ── Database ─────────────────────────────────────────────────────────────
if ($_isLocal) {
    $_db = ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'name' => 'nourstouch', 'port' => 3306];
} else {
    $_credFile = __DIR__ . '/db.credentials.php';
    $_db = is_file($_credFile)
        ? require $_credFile
        : ['host' => 'localhost', 'user' => '', 'pass' => '', 'name' => '', 'port' => 3306];
}

// mysqli (legacy compat)
$conn = @new mysqli($_db['host'], $_db['user'], $_db['pass'], $_db['name'], $_db['port']);
if ($conn->connect_error) {
    error_log("NoursTouch DB: " . $conn->connect_error);
} else {
    $conn->set_charset('utf8mb4');
}

// PDO (preferred)
try {
    $pdo = new PDO(
        "mysql:host={$_db['host']};port={$_db['port']};dbname={$_db['name']};charset=utf8mb4",
        $_db['user'], $_db['pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (Throwable $e) {
    error_log("NoursTouch PDO: " . $e->getMessage());
    $pdo = null;
}

// ── Site settings cache ──────────────────────────────────────────────────
function site_setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        global $pdo;
        $cache = [];
        if ($pdo instanceof PDO) {
            try {
                $rows = $pdo->query('SELECT `key`, `value` FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
                $cache = $rows ?: [];
            } catch (Throwable $e) { /* table may not exist yet */ }
        }
    }
    return ($cache[$key] ?? '') !== '' ? (string)$cache[$key] : $default;
}

// ── Helpers ──────────────────────────────────────────────────────────────
function tel_link(string $phone): string {
    return 'tel:' . preg_replace('/[^\d+]/', '', $phone);
}
function wa_link(string $phone): string {
    return 'https://wa.me/' . preg_replace('/\D/', '', $phone);
}
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ── Language ─────────────────────────────────────────────────────────────
require_once __DIR__ . '/lang_function.php';
