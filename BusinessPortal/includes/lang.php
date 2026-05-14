<?php
// ── Admin language switcher ──────────────────────────────────────────────
if (isset($_GET['lang']) && in_array($_GET['lang'], ['ar','en'], true)) {
    $_SESSION['admin_lang'] = $_GET['lang'];
}
$lang = $_SESSION['admin_lang'] ?? 'ar';
$dir  = ($lang === 'ar') ? 'rtl' : 'ltr';

require_once __DIR__ . '/translations.php';

function __(string $key): string {
    global $TR, $lang;
    return $TR[$lang][$key] ?? ($TR['en'][$key] ?? $key);
}
