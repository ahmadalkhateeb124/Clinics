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

/**
 * Returns the list of supported social platforms with their icon, label,
 * setting key, and the URL the admin entered (empty if not configured).
 * Use social_active_links() to get only the platforms that have a URL.
 */
function social_platforms(): array {
    return [
        'whatsapp'  => ['icon' => 'fa-brands fa-whatsapp',   'label' => 'WhatsApp'],
        'email'     => ['icon' => 'fa-regular fa-envelope',  'label' => 'Email'],
        'facebook'  => ['icon' => 'fa-brands fa-facebook-f', 'label' => 'Facebook'],
        'instagram' => ['icon' => 'fa-brands fa-instagram',  'label' => 'Instagram'],
        'x'         => ['icon' => 'fa-brands fa-x-twitter',  'label' => 'X (Twitter)'],
        'youtube'   => ['icon' => 'fa-brands fa-youtube',    'label' => 'YouTube'],
        'tiktok'    => ['icon' => 'fa-brands fa-tiktok',     'label' => 'TikTok'],
        'linkedin'  => ['icon' => 'fa-brands fa-linkedin-in','label' => 'LinkedIn'],
        'snapchat'  => ['icon' => 'fa-brands fa-snapchat',   'label' => 'Snapchat'],
        'telegram'  => ['icon' => 'fa-brands fa-telegram',   'label' => 'Telegram'],
        'threads'   => ['icon' => 'fa-brands fa-threads',    'label' => 'Threads'],
        'pinterest' => ['icon' => 'fa-brands fa-pinterest',  'label' => 'Pinterest'],
    ];
}

function social_active_links(): array {
    $out = [];
    foreach (social_platforms() as $key => $p) {
        $raw = trim(site_setting('social_' . $key, ''));
        if ($raw === '') continue;
        if ($key === 'whatsapp') {
            $url = (stripos($raw, 'http') === 0) ? $raw : wa_link($raw);
        } elseif ($key === 'email') {
            $url = (stripos($raw, 'mailto:') === 0 || stripos($raw, 'http') === 0) ? $raw : ('mailto:' . $raw);
        } else {
            $url = $raw;
        }
        $out[] = ['key' => $key, 'url' => $url, 'icon' => $p['icon'], 'label' => $p['label']];
    }
    return $out;
}
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function format_money($amount): string {
    $symbol = site_setting('currency_symbol', site_setting('currency', 'JOD'));
    return number_format((float)$amount, 2) . ' ' . $symbol;
}

function format_date($date, string $fmt = 'Y-m-d'): string {
    if (!$date) return '';
    $ts = is_numeric($date) ? (int)$date : strtotime((string)$date);
    return $ts ? date($fmt, $ts) : '';
}

/**
 * Pick the right language column from a DB row.
 * Supports two naming patterns:
 *   • `name_ar` + `name_en`      (services, packages, faqs, blog_posts…)
 *   • `first_name` + `first_name_en`  (employees — Arabic is the base column)
 *
 * Example: tr($service, 'name')   → returns name_en if EN+non-empty, else name_ar
 *          tr($employee, 'first_name') → returns first_name_en if EN+non-empty,
 *                                        else first_name
 */
function tr(array $row, string $base): string {
    global $lang;
    $en = $row[$base.'_en'] ?? '';
    // Arabic value: try `_ar` suffix first, then fall back to the base column
    $ar = $row[$base.'_ar'] ?? ($row[$base] ?? '');
    if ($lang === 'en') return ($en !== '' ? $en : $ar);
    return ($ar !== '' ? $ar : $en);
}

// ── Language ─────────────────────────────────────────────────────────────
require_once __DIR__ . '/lang_function.php';
