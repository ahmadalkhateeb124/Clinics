<?php
require_once __DIR__ . '/../config/config.php';

if (isLoggedIn()) redirect(BP_URL . 'admin/');

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email    = trim($_POST['email'] ?? '');
    $pass     = $_POST['password'] ?? '';
    $remember = !empty($_POST['remember']);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = __('invalid_email');
    if (strlen($pass) < 6) $errors[] = __('password_short');

    if (!$errors) {
        $r = attemptLogin($email, $pass, $remember);
        if ($r['ok']) {
            log_activity('login', 'auth', "User logged in: {$email}", 'user', $r['user']['id']);
            $intended = $_SESSION['_intended'] ?? (BP_URL . 'admin/');
            unset($_SESSION['_intended']);
            redirect($intended);
        }
        $errors[] = $r['msg'] ?? __('login_failed');
    }
    // Post-Redirect-Get: stash errors + old email, then redirect to GET
    $_SESSION['_login_errors'] = $errors;
    set_old(['email' => $email]);
    redirect(BP_URL . 'auth/login.php');
}

// Pull flashed errors on GET (after PRG redirect)
$errors = $_SESSION['_login_errors'] ?? [];
unset($_SESSION['_login_errors']);

// Site root (one level above BusinessPortal)
$siteRoot   = rtrim(preg_replace('#/BusinessPortal/?$#', '/', BP_URL), '/') . '/';
$assetsBase = $siteRoot . 'assets/';
$webkoitLogo = $assetsBase . 'img/logo-webkoit.png';

// Clinic branding from settings
$clinicLogoFile = setting('clinic_logo', '');
$clinicLogoUrl  = $clinicLogoFile ? ($siteRoot . 'uploads/' . $clinicLogoFile) : '';
$clinicName     = $lang === 'en'
    ? (setting('site_name_en', '') ?: setting('site_name_ar', '') ?: 'Nour\'s Touch Clinic')
    : (setting('site_name_ar', '') ?: setting('site_name_en', '') ?: 'عيادة لمسة نور');

$L = [
    'sign_in'        => __('sign_in'),
    'welcome_back'   => $lang === 'ar' ? 'أهلاً بعودتك' : 'Welcome back',
    'enter_creds'    => $lang === 'ar' ? 'سجّل دخولك للوصول إلى لوحة إدارة العيادة.' : 'Sign in to access the clinic dashboard.',
    'email'          => __('email'),
    'email_ph'       => 'you@example.com',
    'password'       => __('password'),
    'remember'       => $lang === 'ar' ? 'تذكرني على هذا الجهاز' : 'Remember me on this device',
    'back_to_site'   => $lang === 'ar' ? 'العودة للموقع' : 'Back to site',
    'portal_pill'    => $lang === 'ar' ? 'لوحة إدارة العيادة' : 'Clinic Admin Portal',
    'left_h1'        => $lang === 'ar' ? 'أدر عيادتك' : 'Run your clinic',
    'left_h1_em'     => $lang === 'ar' ? 'بكلّ راحة.' : 'with ease.',
    'left_p'         => $lang === 'ar'
        ? 'منصة موحّدة للحجوزات، السجلات، الفواتير، الموظفين، والتقارير — كلّ ما تحتاجه إدارة العيادة في مكان واحد.'
        : 'A unified hub for bookings, records, invoices, staff, and reports — everything your clinic team needs, in one place.',
    'stat1_num'      => $lang === 'ar' ? 'حجوزات' : 'Bookings',
    'stat2_num'      => $lang === 'ar' ? 'مرضى'   : 'Patients',
    'stat3_num'      => $lang === 'ar' ? 'تقارير' : 'Reports',
    'stat1_label'    => $lang === 'ar' ? 'جدولة فورية' : 'Live scheduling',
    'stat2_label'    => $lang === 'ar' ? 'سجلات آمنة' : 'Secure records',
    'stat3_label'    => $lang === 'ar' ? 'رؤى دقيقة'  : 'Sharp insights',
    'ssl'            => $lang === 'ar' ? 'مشفّر SSL' : 'SSL encrypted',
    'csrf'           => $lang === 'ar' ? 'محمي CSRF' : 'CSRF protected',
    'need_help'      => $lang === 'ar' ? 'تحتاج مساعدة؟' : 'Need help?',
    'contact_support'=> $lang === 'ar' ? 'تواصل مع الدعم' : 'Contact support',
    'all_rights'     => $lang === 'ar' ? 'جميع الحقوق محفوظة' : 'All rights reserved',
    'show_pw'        => $lang === 'ar' ? 'إظهار كلمة المرور' : 'Show password',
    'powered_by'     => $lang === 'ar' ? 'مدعوم من' : 'Powered by',
];
?><!DOCTYPE html>
<html lang="<?= e($lang) ?>" dir="<?= e($dir) ?>">
<head>
    <meta charset="UTF-8">
    <title><?= e($L['sign_in']) ?> — <?= e($clinicName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Tajawal:wght@400;500;700;800&display=swap">
    <style>
        :root {
            --wk-ink:        #0b1e2d;
            --wk-ink-2:      #1a3242;
            --wk-mute:       #5f7282;
            --wk-line:       #e3e8ea;
            --wk-bg:         #ffffff;
            --wk-soft:       #f3f6f7;
            --wk-teal:       #0d6e63;
            --wk-teal-deep:  #0a564d;
            --wk-teal-2:     #14a294;
            --wk-teal-soft:  #d9efec;
            --wk-warm:       #f6efe5;
            --wk-sand:       #ead9bf;
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: <?= $lang === 'ar' ? "'Tajawal'" : "'Inter'" ?>, system-ui, -apple-system, sans-serif;
            color: var(--wk-ink);
            background: #fff;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }
        a { color: inherit; text-decoration: none; }

        /* Layout */
        .wk-wrap {
            display: grid;
            grid-template-columns: 1.15fr 1fr;
            min-height: 100vh;
        }

        /* Top bar */
        .wk-topbar {
            position: absolute;
            top: 0; left: 0; right: 0;
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: center;
            padding: 1.6rem 2.25rem;
            z-index: 5;
        }
        .wk-logo { display: inline-flex; align-items: center; gap: .65rem; }
        .wk-logo img { height: 64px; width: auto; display: block; }
        .wk-logo-fallback {
            font-weight: 800;
            font-size: 1.55rem;
            letter-spacing: -.02em;
            color: var(--wk-ink);
        }
        .wk-back {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            font-size: .92rem;
            color: var(--wk-ink);
            font-weight: 500;
        }
        .wk-back:hover { color: var(--wk-teal); }
        .wk-langs {
            justify-self: end;
            display: inline-flex;
            background: var(--wk-soft);
            border-radius: 999px;
            padding: 4px;
            border: 1px solid var(--wk-line);
        }
        .wk-langs a {
            padding: .5rem 1.1rem;
            font-size: .8rem;
            font-weight: 600;
            border-radius: 999px;
            color: var(--wk-ink-2);
            transition: all .2s;
        }
        .wk-langs a.is-active { background: var(--wk-ink); color: #fff; }

        /* Left panel — promo */
        .wk-left {
            position: relative;
            padding: 7.5rem 4.5rem 3rem;
            overflow: hidden;
            background:
                radial-gradient(60% 55% at 18% 32%, rgba(20,162,148,.42) 0%, rgba(20,162,148,0) 70%),
                radial-gradient(50% 50% at 70% 70%, rgba(13,110,99,.38) 0%, rgba(13,110,99,0) 65%),
                radial-gradient(40% 40% at 90% 25%, rgba(234,217,191,.65) 0%, rgba(234,217,191,0) 70%),
                #ffffff;
        }
        .wk-left::before {
            content: "";
            position: absolute; inset: 0;
            background-image:
                linear-gradient(rgba(11,15,23,.04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(11,15,23,.04) 1px, transparent 1px);
            background-size: 36px 36px;
            mask-image: radial-gradient(closest-side at 50% 45%, #000 60%, transparent 100%);
            -webkit-mask-image: radial-gradient(closest-side at 50% 45%, #000 60%, transparent 100%);
            pointer-events: none;
        }
        .wk-left-inner { position: relative; z-index: 1; max-width: 560px; }
        .wk-pill {
            display: inline-flex;
            align-items: center;
            gap: .55rem;
            padding: .5rem 1rem;
            background: rgba(255,255,255,.7);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid rgba(13,110,99,.22);
            border-radius: 999px;
            font-size: .82rem;
            font-weight: 600;
            color: var(--wk-ink);
            margin-bottom: 2rem;
        }
        .wk-pill::before {
            content: "";
            width: 8px; height: 8px;
            border-radius: 50%;
            background: var(--wk-teal);
            box-shadow: 0 0 0 4px rgba(13,110,99,.18);
        }
        .wk-h1 {
            font-size: clamp(2.4rem, 4.4vw, 3.6rem);
            font-weight: 800;
            line-height: 1.05;
            letter-spacing: -.025em;
            color: var(--wk-ink);
            margin: 0 0 1.5rem;
        }
        .wk-h1 em {
            font-style: normal;
            display: block;
            background: linear-gradient(90deg, var(--wk-teal-2) 0%, var(--wk-teal-deep) 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        .wk-lead {
            font-size: 1.02rem;
            line-height: 1.65;
            color: var(--wk-mute);
            max-width: 50ch;
            margin: 0 0 3rem;
        }
        .wk-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
        }
        .wk-stats > div {
            background: rgba(255,255,255,.65);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid rgba(13,110,99,.12);
            border-radius: 14px;
            padding: 1.1rem 1rem;
        }
        .wk-stat-ico {
            width: 36px; height: 36px;
            border-radius: 10px;
            background: var(--wk-teal-soft);
            color: var(--wk-teal-deep);
            display: flex; align-items: center; justify-content: center;
            font-size: .95rem;
            margin-bottom: .75rem;
        }
        .wk-stat-num {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--wk-ink);
            letter-spacing: -.01em;
            line-height: 1.1;
        }
        .wk-stat-label {
            margin-top: .35rem;
            font-size: .72rem;
            font-weight: 600;
            color: var(--wk-mute);
        }

        .wk-left-foot {
            position: absolute;
            bottom: 1.25rem; left: 4.5rem; right: 4.5rem;
            font-size: .78rem;
            color: var(--wk-mute);
            z-index: 1;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .wk-powered {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            color: var(--wk-mute);
            opacity: .85;
            transition: opacity .2s;
        }
        .wk-powered:hover { opacity: 1; color: var(--wk-ink); }
        .wk-powered img { height: 32px; width: auto; display: block; opacity: .85; }
        .wk-powered:hover img { opacity: 1; }

        /* Right panel — form */
        .wk-right {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 7.5rem 4.5rem 3rem;
            background: #fff;
        }
        .wk-form-card { width: 100%; max-width: 420px; }

        .wk-eyebrow {
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: .22em;
            color: var(--wk-teal);
            margin-bottom: .85rem;
        }
        .wk-h2 {
            font-size: clamp(1.9rem, 3vw, 2.4rem);
            font-weight: 800;
            color: var(--wk-ink);
            margin: 0 0 .65rem;
            letter-spacing: -.02em;
            line-height: 1.1;
        }
        .wk-sub {
            color: var(--wk-mute);
            margin: 0 0 2.25rem;
            font-size: .98rem;
        }

        .wk-alert {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
            padding: .85rem 1rem;
            border-radius: 12px;
            font-size: .88rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: flex-start;
            gap: .65rem;
        }
        .wk-alert i { font-size: 1rem; margin-top: .15rem; }
        .wk-alert ul { margin: 0; padding-inline-start: 1rem; }

        .wk-field { margin-bottom: 1.2rem; }
        .wk-label {
            display: block;
            font-size: .88rem;
            font-weight: 600;
            color: var(--wk-ink);
            margin-bottom: .55rem;
        }
        .wk-input-wrap { position: relative; }
        .wk-input-ico {
            position: absolute;
            top: 50%;
            inset-inline-start: 1rem;
            transform: translateY(-50%);
            color: var(--wk-mute);
            font-size: .95rem;
            pointer-events: none;
        }
        .wk-input {
            width: 100%;
            padding: .95rem 1.05rem .95rem 2.85rem;
            border: 1px solid var(--wk-line);
            background: #fff;
            border-radius: 12px;
            font: inherit;
            font-size: .98rem;
            color: var(--wk-ink);
            transition: border-color .2s, box-shadow .2s, background .2s;
        }
        [dir="rtl"] .wk-input { padding: .95rem 2.85rem .95rem 1.05rem; }
        .wk-input:focus {
            outline: none;
            border-color: var(--wk-teal);
            box-shadow: 0 0 0 4px rgba(13,110,99,.14);
        }
        .wk-input::placeholder { color: #aab0bd; }
        .wk-pw-toggle {
            position: absolute;
            top: 50%;
            inset-inline-end: .75rem;
            transform: translateY(-50%);
            background: transparent;
            border: 0;
            color: var(--wk-mute);
            cursor: pointer;
            padding: .5rem;
            border-radius: 8px;
        }
        .wk-pw-toggle:hover { color: var(--wk-ink); background: var(--wk-soft); }

        .wk-remember {
            display: inline-flex;
            align-items: center;
            gap: .65rem;
            margin: .25rem 0 1.5rem;
            cursor: pointer;
            user-select: none;
            font-size: .9rem;
            color: var(--wk-ink-2);
        }
        .wk-remember input { display: none; }
        .wk-remember .box {
            width: 18px; height: 18px;
            border-radius: 5px;
            border: 1.5px solid var(--wk-line);
            background: #fff;
            display: inline-flex;
            align-items: center; justify-content: center;
            transition: all .2s;
        }
        .wk-remember .box::after {
            content: "";
            width: 9px; height: 5px;
            border-inline-start: 2px solid #fff;
            border-bottom: 2px solid #fff;
            transform: rotate(-45deg) scale(0);
            transition: transform .15s;
        }
        .wk-remember input:checked + .box {
            background: var(--wk-ink);
            border-color: var(--wk-ink);
        }
        .wk-remember input:checked + .box::after { transform: rotate(-45deg) scale(1); }

        .wk-btn {
            width: 100%;
            padding: 1rem 1.25rem;
            background: var(--wk-ink);
            color: #fff;
            border: 0;
            border-radius: 14px;
            font: inherit;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .65rem;
            transition: transform .15s, background .2s, box-shadow .2s;
        }
        .wk-btn:hover {
            background: #000;
            transform: translateY(-1px);
            box-shadow: 0 14px 28px -14px rgba(11,15,23,.45);
        }
        .wk-btn .arr { transition: transform .2s; }
        [dir="rtl"] .wk-btn .arr { transform: scaleX(-1); }
        .wk-btn:hover .arr { transform: translateX(3px); }
        [dir="rtl"] .wk-btn:hover .arr { transform: scaleX(-1) translateX(3px); }

        .wk-badges {
            margin-top: 1.5rem;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 1.25rem;
            color: var(--wk-mute);
            font-size: .78rem;
        }
        .wk-badges i { color: var(--wk-ink-2); margin-inline-end: .35rem; }
        .wk-badges .dot { color: #cbd0db; }

        .wk-support {
            text-align: center;
            margin-top: 2.5rem;
            font-size: .88rem;
            color: var(--wk-mute);
        }
        .wk-support a {
            color: var(--wk-ink);
            font-weight: 700;
            border-bottom: 1px dashed rgba(11,15,23,.25);
        }
        .wk-support a:hover { color: var(--wk-teal); border-bottom-color: var(--wk-teal); }

        /* Responsive */
        @media (max-width: 991px) {
            .wk-wrap { grid-template-columns: 1fr; }
            .wk-left { display: none; }
            .wk-right { padding: 6rem 1.5rem 2.5rem; }
            .wk-topbar { padding: 1.2rem 1.25rem; grid-template-columns: auto 1fr auto; gap: .75rem; }
            .wk-back { font-size: .85rem; justify-self: end; }
        }
        @media (max-width: 480px) {
            .wk-topbar { grid-template-columns: auto auto; }
            .wk-back { display: none; }
            .wk-langs a { padding: .4rem .85rem; font-size: .75rem; }
            .wk-right { padding: 5.5rem 1.1rem 2rem; }
            .wk-h2 { font-size: 1.7rem; }
            .wk-input { padding-block: .85rem; }
            .wk-btn { padding: .9rem 1rem; }
        }
    </style>
</head>
<body>
    <header class="wk-topbar">
        <a href="<?= e($siteRoot) ?>" class="wk-logo" aria-label="<?= e($clinicName) ?>">
            <?php if ($clinicLogoUrl): ?>
                <img src="<?= e($clinicLogoUrl) ?>" alt="<?= e($clinicName) ?>">
            <?php else: ?>
                <span class="wk-logo-fallback"><?= e($clinicName) ?></span>
            <?php endif; ?>
        </a>
        <a href="<?= e($siteRoot) ?>" class="wk-back">
            <i class="fa-solid fa-arrow-<?= $dir === 'rtl' ? 'right' : 'left' ?>"></i>
            <?= e($L['back_to_site']) ?>
        </a>
        <div class="wk-langs">
            <a href="?lang=ar" class="<?= $lang === 'ar' ? 'is-active' : '' ?>">عربي</a>
            <a href="?lang=en" class="<?= $lang === 'en' ? 'is-active' : '' ?>">English</a>
        </div>
    </header>

    <div class="wk-wrap">
        <!-- Promo side -->
        <aside class="wk-left" aria-hidden="true">
            <div class="wk-left-inner">
                <span class="wk-pill"><?= e($L['portal_pill']) ?></span>
                <h1 class="wk-h1">
                    <?= e($L['left_h1']) ?>
                    <em><?= e($L['left_h1_em']) ?></em>
                </h1>
                <p class="wk-lead"><?= e($L['left_p']) ?></p>

                <div class="wk-stats">
                    <div>
                        <div class="wk-stat-ico"><i class="fa-regular fa-calendar-check"></i></div>
                        <div class="wk-stat-num"><?= e($L['stat1_num']) ?></div>
                        <div class="wk-stat-label"><?= e($L['stat1_label']) ?></div>
                    </div>
                    <div>
                        <div class="wk-stat-ico"><i class="fa-solid fa-user-shield"></i></div>
                        <div class="wk-stat-num"><?= e($L['stat2_num']) ?></div>
                        <div class="wk-stat-label"><?= e($L['stat2_label']) ?></div>
                    </div>
                    <div>
                        <div class="wk-stat-ico"><i class="fa-solid fa-chart-line"></i></div>
                        <div class="wk-stat-num"><?= e($L['stat3_num']) ?></div>
                        <div class="wk-stat-label"><?= e($L['stat3_label']) ?></div>
                    </div>
                </div>
            </div>
            <div class="wk-left-foot">
                <span>© <?= date('Y') ?> <?= e($clinicName) ?> · <?= e($L['all_rights']) ?></span>
                <a class="wk-powered" href="https://webkoit.com" target="_blank" rel="noopener">
                    <span><?= e($L['powered_by']) ?></span>
                    <img src="<?= e($webkoitLogo) ?>" alt="Webkoit">
                </a>
            </div>
        </aside>

        <!-- Form side -->
        <main class="wk-right">
            <div class="wk-form-card">
                <div class="wk-eyebrow"><?= e(strtoupper($L['sign_in'])) ?></div>
                <h2 class="wk-h2"><?= e($L['welcome_back']) ?></h2>
                <p class="wk-sub"><?= e($L['enter_creds']) ?></p>

                <?php if ($errors): ?>
                    <div class="wk-alert">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <?php if (count($errors) === 1): ?>
                            <div><?= e($errors[0]) ?></div>
                        <?php else: ?>
                            <ul>
                                <?php foreach ($errors as $err): ?>
                                    <li><?= e($err) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form method="post" autocomplete="on" novalidate>
                    <?= csrf_field() ?>

                    <div class="wk-field">
                        <label class="wk-label" for="email"><?= e($L['email']) ?></label>
                        <div class="wk-input-wrap">
                            <i class="wk-input-ico fa-regular fa-envelope"></i>
                            <input id="email" type="email" name="email" class="wk-input" required
                                   placeholder="<?= e($L['email_ph']) ?>"
                                   value="<?= old('email') ?>" autofocus>
                        </div>
                    </div>

                    <div class="wk-field">
                        <label class="wk-label" for="password"><?= e($L['password']) ?></label>
                        <div class="wk-input-wrap">
                            <i class="wk-input-ico fa-solid fa-lock"></i>
                            <input id="password" type="password" name="password" class="wk-input" required minlength="6"
                                   placeholder="••••••••">
                            <button type="button" class="wk-pw-toggle" aria-label="<?= e($L['show_pw']) ?>" onclick="(function(b){var p=document.getElementById('password');var s=p.type==='password';p.type=s?'text':'password';b.querySelector('i').className=s?'fa-regular fa-eye-slash':'fa-regular fa-eye';})(this)">
                                <i class="fa-regular fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <label class="wk-remember">
                        <input type="checkbox" name="remember" value="1">
                        <span class="box"></span>
                        <span><?= e($L['remember']) ?></span>
                    </label>

                    <button type="submit" class="wk-btn">
                        <span><?= e($L['sign_in']) ?></span>
                        <i class="arr fa-solid fa-arrow-right"></i>
                    </button>
                </form>

                <div class="wk-badges">
                    <span><i class="fa-solid fa-shield-halved"></i><?= e($L['ssl']) ?></span>
                    <span class="dot">•</span>
                    <span><i class="fa-solid fa-lock"></i><?= e($L['csrf']) ?></span>
                </div>

                <p class="wk-support">
                    <?= e($L['need_help']) ?>
                    <a href="<?= e($siteRoot) ?>contact"><?= e($L['contact_support']) ?></a>
                </p>
            </div>
        </main>
    </div>
    <?php clear_old(); ?>
</body>
</html>
