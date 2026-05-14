<?php
// ════════════════════════════════════════════════════════════════════════
// Nour's Touch Clinic — Admin Portal Bootstrap
// ════════════════════════════════════════════════════════════════════════
date_default_timezone_set('Asia/Amman');

if (session_status() === PHP_SESSION_NONE) {
    // Hardened session cookie
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ── Paths & URLs ─────────────────────────────────────────────────────────
$_host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_proto   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$_isLocal = (strpos($_host, 'localhost') !== false || strpos($_host, '127.0.0.1') !== false || PHP_SAPI === 'cli');

// APP_PATH derived from this file → robust under CLI and HTTP both.
define('APP_PATH', dirname(__DIR__, 2) . '/');

if ($_isLocal) {
    define('APP_URL', $_proto . '://' . $_host . '/nourstouch/');
} else {
    define('APP_URL', 'https://nourstouch.com/');
}

define('BP_URL',      APP_URL  . 'BusinessPortal/');
define('BP_PATH',     rtrim(APP_PATH, '/') . '/BusinessPortal');
define('BP_INCLUDES', BP_PATH . '/includes');
define('BP_CONFIG',   BP_PATH . '/config');
define('BP_PARTIALS', BP_PATH . '/partials');
define('BP_ASSETS',   BP_URL  . 'assets/');
define('UPLOADS_URL', APP_URL  . 'uploads/');
define('UPLOADS_PATH',rtrim(APP_PATH, '/') . '/uploads');

define('APP_NAME',     "Nour's Touch Clinic");
define('APP_NAME_AR',  "عيادة لمسة نور");
define('APP_CURRENCY', 'JOD');

// ── Database ─────────────────────────────────────────────────────────────
require_once BP_CONFIG . '/database.php';

// ── Core includes ────────────────────────────────────────────────────────
require_once BP_INCLUDES . '/helpers.php';
require_once BP_INCLUDES . '/csrf.php';
require_once BP_INCLUDES . '/lang.php';
require_once BP_INCLUDES . '/auth.php';
require_once BP_INCLUDES . '/permissions.php';
require_once BP_INCLUDES . '/activity_log.php';
require_once BP_INCLUDES . '/booking.php';
require_once BP_INCLUDES . '/finance.php';
require_once BP_INCLUDES . '/hr.php';
require_once BP_INCLUDES . '/analytics.php';
require_once BP_INCLUDES . '/notifications.php';

// Composer autoload (DomPDF, etc.)
$_autoload = BP_PATH . '/vendor/autoload.php';
if (is_file($_autoload)) require_once $_autoload;
