<?php
// ── Language switcher (AR default RTL, EN LTR) ───────────────────────────
if (isset($_GET['lang']) && in_array($_GET['lang'], ['ar', 'en'], true)) {
    $_SESSION['lang'] = $_GET['lang'];
}
$lang = $_SESSION['lang'] ?? 'ar';
$dir  = ($lang === 'ar') ? 'rtl' : 'ltr';

$_langFile = __DIR__ . '/' . $lang . '.php';
$L = is_file($_langFile) ? require $_langFile : [];

function t(string $key, string $fallback = ''): string {
    global $L;
    return $L[$key] ?? ($fallback !== '' ? $fallback : $key);
}
