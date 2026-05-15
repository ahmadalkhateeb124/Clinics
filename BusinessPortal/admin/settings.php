<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('settings.view');

$PageTitle = __('settings');

$accountErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $section = $_POST['section'] ?? 'settings';
    $me = currentUser();

    // ── Section: own account (email / password) ────────────────────────
    if ($section === 'account_identity') {
        $email = trim($_POST['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $accountErrors[] = __('err_email_invalid');
        if (!$accountErrors) {
            $chk = db()->prepare("SELECT id FROM users WHERE email = ? AND id <> ? AND deleted_at IS NULL");
            $chk->execute([$email, $me['id']]);
            if ($chk->fetch()) $accountErrors[] = __('err_email_in_use');
        }
        if (!$accountErrors) {
            db()->prepare("UPDATE users SET email = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$email, $me['id']]);
            log_activity('updated', 'users', 'Updated own login email', 'user', $me['id']);
            flash('success', __('account_saved'));
            redirect(BP_URL . 'admin/settings.php#account');
        }
    }

    if ($section === 'account_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $conf    = $_POST['confirm_password'] ?? '';
        if (!password_verify($current, $me['password'])) $accountErrors[] = __('err_current_pw_wrong');
        if (strlen($new) < 8)                              $accountErrors[] = __('err_pw_too_short');
        if (!preg_match('/[A-Z]/', $new) || !preg_match('/[a-z]/', $new) || !preg_match('/\d/', $new)) {
            $accountErrors[] = __('err_pw_complexity');
        }
        if ($new !== $conf) $accountErrors[] = __('err_pw_mismatch');
        if (!$accountErrors) {
            $hash = password_hash($new, PASSWORD_BCRYPT);
            db()->prepare("UPDATE users SET password = ?, must_change_pw = 0, updated_at = NOW() WHERE id = ?")
                ->execute([$hash, $me['id']]);
            log_activity('password_changed', 'auth', 'Password changed', 'user', $me['id']);
            flash('success', __('password_updated'));
            redirect(BP_URL . 'admin/settings.php#account');
        }
    }

    // ── Section: clinic + advanced settings (admin only) ───────────────
    if ($section === 'settings') {
    require_can('settings.edit');

    $payload = $_POST['settings'] ?? [];
    if (!is_array($payload)) $payload = [];

    // ── Logo upload (clinic_logo) — detailed diagnostics ───────────────
    $uploadErr = null;
    $f = $_FILES['clinic_logo'] ?? null;
    if ($f && !empty($f['name'])) {
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $phpErrs = [
                UPLOAD_ERR_INI_SIZE   => 'الملف أكبر من الحدّ المسموح في خادم PHP (upload_max_filesize).',
                UPLOAD_ERR_FORM_SIZE  => 'الملف أكبر من الحدّ المسموح في النموذج.',
                UPLOAD_ERR_PARTIAL    => 'تم رفع جزء فقط من الملف. حاول مرة أخرى.',
                UPLOAD_ERR_NO_TMP_DIR => 'مجلّد رفع مؤقّت غير متاح على الخادم.',
                UPLOAD_ERR_CANT_WRITE => 'تعذّرت الكتابة على القرص.',
                UPLOAD_ERR_EXTENSION  => 'إضافة PHP منعت الرفع.',
            ];
            $uploadErr = $phpErrs[$f['error']] ?? ('خطأ رفع غير معروف (#'.(int)$f['error'].')');
        } else {
            $ext  = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $size = (int)$f['size'];
            $maxBytes = 5*1024*1024;
            $allowedExt = ['jpg','jpeg','png','webp','gif','svg','bmp','ico','avif','heic','heif'];
            // Reject obvious executable / script content even if extension is image
            $blockedMimes = [
                'text/html','application/xhtml+xml','application/x-php','application/x-httpd-php',
                'application/javascript','text/javascript','application/x-msdownload','application/x-sh',
            ];

            if ($size > $maxBytes) {
                $uploadErr = sprintf('الملف كبير جداً (%s). الحدّ الأقصى 5MB.', number_format($size/1024/1024,2).'MB');
            } elseif (!in_array($ext, $allowedExt, true)) {
                $uploadErr = sprintf('امتداد غير مدعوم: .%s — المدعوم: %s', $ext, strtoupper(implode(' / ', $allowedExt)));
            } else {
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: 'application/octet-stream';
                if (in_array($mime, $blockedMimes, true)) {
                    $uploadErr = sprintf('نوع ملف محظور لأسباب أمنية (%s).', $mime);
                } else {
                    $dir = rtrim(UPLOADS_PATH,'/').'/branding';
                    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
                        $uploadErr = 'تعذّر إنشاء مجلد الشعار: '.$dir;
                    } elseif (!is_writable($dir)) {
                        $uploadErr = 'مجلّد الشعار غير قابل للكتابة: '.$dir;
                    } else {
                        $name   = 'logo_'.time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
                        $target = $dir.'/'.$name;
                        if (!move_uploaded_file($f['tmp_name'], $target)) {
                            $uploadErr = 'فشل نقل الملف إلى '.$target;
                        } else {
                            $old = setting('clinic_logo','');
                            if ($old) { $oldAbs = rtrim(UPLOADS_PATH,'/').'/'.$old; if (is_file($oldAbs)) @unlink($oldAbs); }
                            $payload['clinic_logo'] = 'branding/'.$name;
                        }
                    }
                }
            }
        }
    }

    // Remove existing logo
    if (!empty($_POST['remove_logo'])) {
        $old = setting('clinic_logo','');
        if ($old) { $oldAbs = rtrim(UPLOADS_PATH,'/').'/'.$old; if (is_file($oldAbs)) @unlink($oldAbs); }
        $payload['clinic_logo'] = '';
    }

    $upd = db()->prepare("
        INSERT INTO settings (`key`, `value`, `group`, `type`)
        VALUES (?, ?, ?, 'text')
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()
    ");

    $groupFor = [
        'site_name_ar'  => 'general', 'site_name_en' => 'general',
        'contact_phone' => 'contact', 'contact_email' => 'contact', 'address' => 'contact',
        'clinic_logo'   => 'branding',
        // Social media platforms
        'social_whatsapp' => 'social', 'social_email'     => 'social',
        'social_facebook' => 'social', 'social_instagram' => 'social', 'social_x' => 'social',
        'social_youtube'  => 'social', 'social_tiktok'    => 'social', 'social_linkedin'  => 'social',
        'social_snapchat' => 'social', 'social_telegram'  => 'social', 'social_threads'   => 'social',
        'social_pinterest'=> 'social',
    ];

    foreach ($payload as $key => $val) {
        $key = trim((string)$key);
        if ($key === '') continue;
        $g = $groupFor[$key] ?? 'general';
        $upd->execute([$key, (string)$val, $g]);
    }

    log_activity('updated', 'settings', 'Site settings updated');
    if ($uploadErr) flash('error', $uploadErr);
    else            flash('success', __('settings_saved'));
    redirect(BP_URL . 'admin/settings.php');
    }
}

$rows = db()->query("SELECT `key`, `value`, `group` FROM settings ORDER BY `group`, `key`")->fetchAll();
$grouped = [];
foreach ($rows as $r) $grouped[$r['group']][] = $r;

// Clinic-profile and social fields are managed in their own cards; hide from generic tabs
$profileKeys = ['site_name_ar','site_name_en','contact_phone','contact_email','address','clinic_logo'];
foreach ($grouped as $g => &$items) {
    $items = array_values(array_filter($items, fn($r) =>
        !in_array($r['key'], $profileKeys, true)
        && strpos($r['key'], 'social_') !== 0
    ));
}
unset($items);
$grouped = array_filter($grouped, fn($v) => !empty($v));

$cp = [
    'site_name_ar'  => setting('site_name_ar', ''),
    'site_name_en'  => setting('site_name_en', ''),
    'contact_phone' => setting('contact_phone', ''),
    'contact_email' => setting('contact_email', ''),
    'address'       => setting('address', ''),
    'clinic_logo'   => setting('clinic_logo', ''),
];

// Group icon map for tabs
$groupIcons = [
    'general'  => 'fa-sliders',
    'contact'  => 'fa-address-book',
    'mail'     => 'fa-envelope',
    'rules'    => 'fa-scale-balanced',
    'branding' => 'fa-palette',
];

include BP_PARTIALS . '/header.php';
?>
<div class="page-wrap settings-page">
    <!-- Header -->
    <div class="page-header">
        <h4 class="m-0">
            <i class="fa-solid fa-gear text-teal me-2"></i><?= __('settings') ?>
        </h4>
        <div class="page-header-actions">
            <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/system.php">
                <i class="fa-solid fa-stethoscope me-1"></i><?= __('system_health_label') ?>
            </a>
        </div>
    </div>

    <form method="post" enctype="multipart/form-data" id="settings-form">
        <?= csrf_field() ?>
        <input type="hidden" name="section" value="settings">

        <!-- ════════ Clinic profile (hero) ════════ -->
        <div class="settings-hero">
            <div class="settings-hero-side">
                <div class="settings-hero-eyebrow"><i class="fa-solid fa-spa"></i> <?= __('branding') ?></div>
                <h5 class="settings-hero-title"><?= __('clinic_profile') ?></h5>
                <p class="settings-hero-sub"><?= __('clinic_profile_help') ?></p>

                <!-- Where this info appears -->
                <ul class="settings-where">
                    <li><i class="fa-regular fa-credit-card"></i> <?= __('shown_on_invoices') ?></li>
                    <li><i class="fa-regular fa-envelope"></i> <?= __('shown_on_emails') ?></li>
                    <li><i class="fa-solid fa-window-maximize"></i> <?= __('shown_in_topbar') ?></li>
                </ul>
            </div>

            <div class="settings-hero-main">

                <!-- Logo dropzone -->
                <div class="logo-wrap">
                    <label class="logo-dropzone" id="logo-dropzone" for="clinic_logo_input">
                        <input type="file" id="clinic_logo_input" name="clinic_logo" accept="image/*,.svg,.heic,.heif,.avif" hidden>

                        <div class="logo-preview-frame">
                            <?php if (!empty($cp['clinic_logo'])): ?>
                                <img id="logo-preview" src="<?= UPLOADS_URL . e($cp['clinic_logo']) ?>?v=<?= @filemtime(UPLOADS_PATH.'/'.$cp['clinic_logo']) ?>" alt="logo">
                            <?php else: ?>
                                <img id="logo-preview" style="display:none">
                                <div id="logo-placeholder" class="logo-placeholder">
                                    <div class="logo-placeholder-icon"><i class="fa-regular fa-image"></i></div>
                                    <div class="logo-placeholder-title"><?= __('drop_logo_here') ?></div>
                                    <div class="logo-placeholder-sub"><?= __('or_click_to_browse') ?></div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="logo-actions">
                            <span class="btn btn-light btn-sm">
                                <i class="fa-solid fa-upload me-1"></i>
                                <span id="logo-action-text"><?= !empty($cp['clinic_logo']) ? __('replace_logo') : __('choose_logo') ?></span>
                            </span>
                            <?php if (!empty($cp['clinic_logo'])): ?>
                                <label class="btn btn-outline-danger btn-sm m-0" onclick="event.stopPropagation()" for="remove-logo-cb">
                                    <input type="checkbox" name="remove_logo" id="remove-logo-cb" value="1" style="margin-inline-end:.3rem">
                                    <?= __('remove_logo') ?>
                                </label>
                            <?php endif; ?>
                        </div>
                        <div class="logo-specs"><?= __('logo_specs') ?></div>
                    </label>
                </div>

                <!-- Identity grid -->
                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <label class="settings-field-label">
                            <i class="fa-solid fa-signature"></i> <?= __('clinic_name_ar') ?>
                        </label>
                        <input name="settings[site_name_ar]" class="form-control form-control-lg" value="<?= e($cp['site_name_ar']) ?>" placeholder="<?= __('clinic_name_ar_placeholder') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="settings-field-label">
                            <i class="fa-solid fa-signature"></i> <?= __('clinic_name_en') ?>
                        </label>
                        <input name="settings[site_name_en]" class="form-control form-control-lg" value="<?= e($cp['site_name_en']) ?>" placeholder="Clinic Name" dir="ltr">
                    </div>
                </div>

                <!-- Contact strip -->
                <div class="contact-grid mt-3">
                    <div class="contact-field">
                        <div class="contact-icon"><i class="fa-solid fa-phone"></i></div>
                        <div class="contact-body">
                            <label class="contact-label"><?= __('phone') ?></label>
                            <input name="settings[contact_phone]" class="contact-input" value="<?= e($cp['contact_phone']) ?>" placeholder="+962 7 0000 0000" dir="ltr">
                        </div>
                    </div>
                    <div class="contact-field">
                        <div class="contact-icon"><i class="fa-solid fa-envelope"></i></div>
                        <div class="contact-body">
                            <label class="contact-label"><?= __('email') ?></label>
                            <input type="email" name="settings[contact_email]" class="contact-input" value="<?= e($cp['contact_email']) ?>" placeholder="info@clinic.com" dir="ltr">
                        </div>
                    </div>
                    <div class="contact-field contact-field-wide">
                        <div class="contact-icon"><i class="fa-solid fa-location-dot"></i></div>
                        <div class="contact-body">
                            <label class="contact-label"><?= __('address') ?></label>
                            <input name="settings[address]" class="contact-input" value="<?= e($cp['address']) ?>" placeholder="<?= __('address_placeholder') ?>">
                            <div class="contact-help"><?= __('address_help') ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ════════ Social Media ════════ -->
        <?php
        $socialPlatforms = [
            'whatsapp'  => ['icon' => 'fa-brands fa-whatsapp',   'label' => 'WhatsApp',    'ph' => '+962 7 0000 0000  ·  ' . __('uses_contact_phone_if_empty')],
            'email'     => ['icon' => 'fa-regular fa-envelope',  'label' => 'Email',       'ph' => 'info@clinic.com  ·  ' . __('uses_contact_email_if_empty')],
            'facebook'  => ['icon' => 'fa-brands fa-facebook-f', 'label' => 'Facebook',    'ph' => 'https://facebook.com/yourpage'],
            'instagram' => ['icon' => 'fa-brands fa-instagram',  'label' => 'Instagram',   'ph' => 'https://instagram.com/yourpage'],
            'x'         => ['icon' => 'fa-brands fa-x-twitter',  'label' => 'X (Twitter)', 'ph' => 'https://x.com/yourpage'],
            'youtube'   => ['icon' => 'fa-brands fa-youtube',    'label' => 'YouTube',     'ph' => 'https://youtube.com/@yourchannel'],
            'tiktok'    => ['icon' => 'fa-brands fa-tiktok',     'label' => 'TikTok',      'ph' => 'https://tiktok.com/@yourpage'],
            'linkedin'  => ['icon' => 'fa-brands fa-linkedin-in','label' => 'LinkedIn',    'ph' => 'https://linkedin.com/company/yourpage'],
            'snapchat'  => ['icon' => 'fa-brands fa-snapchat',   'label' => 'Snapchat',    'ph' => 'https://snapchat.com/add/yourpage'],
            'telegram'  => ['icon' => 'fa-brands fa-telegram',   'label' => 'Telegram',    'ph' => 'https://t.me/yourpage'],
            'threads'   => ['icon' => 'fa-brands fa-threads',    'label' => 'Threads',     'ph' => 'https://threads.net/@yourpage'],
            'pinterest' => ['icon' => 'fa-brands fa-pinterest',  'label' => 'Pinterest',   'ph' => 'https://pinterest.com/yourpage'],
        ];
        ?>
        <div class="settings-card">
            <div class="settings-card-head">
                <div>
                    <h6 class="m-0"><i class="fa-brands fa-share-nodes text-teal me-1"></i> <?= __('social_media') ?></h6>
                    <p class="text-muted small m-0"><?= __('social_media_help') ?></p>
                </div>
            </div>
            <div class="social-grid">
                <?php foreach ($socialPlatforms as $key => $p):
                    $val = setting('social_' . $key, '');
                ?>
                    <div class="social-row <?= $val ? 'is-on' : '' ?>">
                        <div class="social-icon"><i class="<?= e($p['icon']) ?>"></i></div>
                        <div class="social-body">
                            <label class="social-label"><?= e($p['label']) ?></label>
                            <input type="url" name="settings[social_<?= e($key) ?>]"
                                   class="social-input"
                                   value="<?= e($val) ?>"
                                   placeholder="<?= e($p['ph']) ?>"
                                   dir="ltr">
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ════════ Advanced settings tabs ════════ -->
        <?php if ($grouped): ?>
        <div class="settings-card">
            <div class="settings-card-head">
                <div>
                    <h6 class="m-0"><i class="fa-solid fa-sliders text-teal me-1"></i> <?= __('advanced_settings') ?></h6>
                    <p class="text-muted small m-0"><?= __('advanced_settings_help') ?></p>
                </div>
            </div>

            <!-- Vertical pill tabs (desktop) → horizontal scroll (mobile) -->
            <div class="settings-tabs-wrap">
                <ul class="settings-tabs" role="tablist">
                    <?php $i = 0; foreach (array_keys($grouped) as $g): $icon = $groupIcons[$g] ?? 'fa-circle-dot'; ?>
                        <li>
                            <button type="button" class="settings-tab <?= $i === 0 ? 'active' : '' ?>"
                                    data-bs-toggle="tab" data-bs-target="#tab-<?= e($g) ?>">
                                <i class="fa-solid <?= $icon ?>"></i>
                                <span><?= e(__($g)) ?: e(ucfirst($g)) ?></span>
                                <span class="settings-tab-count"><?= count($grouped[$g]) ?></span>
                            </button>
                        </li>
                    <?php $i++; endforeach; ?>
                </ul>

                <div class="tab-content settings-tabs-content">
                    <?php $i = 0; foreach ($grouped as $g => $items): ?>
                        <div class="tab-pane fade <?= $i === 0 ? 'show active' : '' ?>" id="tab-<?= e($g) ?>">
                            <div class="row g-3">
                                <?php foreach ($items as $row):
                                    $isPass = (strpos($row['key'], 'pass') !== false);
                                    $isLong = (strlen($row['value']) > 80);
                                ?>
                                    <div class="col-md-6">
                                        <label class="settings-field-label">
                                            <i class="fa-solid fa-key text-muted"></i>
                                            <code class="settings-key"><?= e($row['key']) ?></code>
                                        </label>
                                        <?php if ($isLong): ?>
                                            <textarea name="settings[<?= e($row['key']) ?>]" class="form-control" rows="2"><?= e($row['value']) ?></textarea>
                                        <?php else: ?>
                                            <input type="<?= $isPass?'password':'text' ?>" name="settings[<?= e($row['key']) ?>]" class="form-control" value="<?= e($row['value']) ?>">
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php $i++; endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Save bar -->
        <?php if (can('settings.edit')): ?>
            <div class="settings-savebar">
                <div class="text-muted small"><i class="fa-regular fa-clock me-1"></i> <?= __('changes_take_effect_immediately') ?></div>
                <button class="btn btn-teal"><i class="fa-solid fa-save me-1"></i><?= __('save_settings') ?></button>
            </div>
        <?php endif; ?>
    </form>

    <!-- ════════ My account (login email + password) ════════ -->
    <?php $me = currentUser(); ?>
    <div class="settings-card mt-3" id="account">
        <div class="settings-card-head">
            <div>
                <h6 class="m-0"><i class="fa-solid fa-user-gear text-teal me-1"></i> <?= __('my_account') ?></h6>
                <p class="text-muted small m-0"><?= __('my_account_help') ?></p>
            </div>
        </div>
        <div class="p-4">
            <?php if (!empty($me['must_change_pw'])): ?>
                <div class="alert alert-warning small mb-3">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i>
                    <?= __('must_change_pw_warning') ?>
                </div>
            <?php endif; ?>
            <?php foreach ($accountErrors as $err): ?>
                <div class="alert alert-danger small"><?= e($err) ?></div>
            <?php endforeach; ?>

            <div class="row g-4">
                <!-- Identity (name + email) -->
                <div class="col-lg-6">
                    <h6 class="mb-3"><i class="fa-solid fa-id-card text-teal me-2"></i><?= __('login_identity') ?></h6>
                    <p class="text-muted small mb-3"><?= __('login_identity_help') ?></p>

                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="section" value="account_identity">
                        <div class="mb-3">
                            <label class="form-label small text-muted"><?= __('login_email') ?></label>
                            <input type="email" name="email" class="form-control" required value="<?= e($me['email']) ?>" dir="ltr">
                            <div class="small text-muted mt-1"><?= __('email_help') ?></div>
                        </div>
                        <div class="small text-muted mb-3">
                            <i class="fa-solid fa-circle-info me-1"></i>
                            <?= __('display_name_from_clinic') ?>
                        </div>
                        <button class="btn btn-teal btn-sm"><i class="fa-solid fa-save me-1"></i><?= __('save_changes') ?></button>
                    </form>
                </div>

                <!-- Password -->
                <div class="col-lg-6">
                    <h6 class="mb-3"><i class="fa-solid fa-key text-teal me-2"></i><?= __('change_password') ?></h6>
                    <p class="text-muted small mb-3"><?= __('change_password_help') ?></p>

                    <form method="post" id="pw-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="section" value="account_password">
                        <div class="mb-3">
                            <label class="form-label small text-muted"><?= __('current_password') ?></label>
                            <div class="pw-input">
                                <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
                                <button type="button" class="pw-toggle" data-target="current_password"><i class="fa-regular fa-eye"></i></button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-muted"><?= __('new_password') ?></label>
                            <div class="pw-input">
                                <input type="password" name="new_password" id="new_password" class="form-control" minlength="8" required autocomplete="new-password">
                                <button type="button" class="pw-toggle" data-target="new_password"><i class="fa-regular fa-eye"></i></button>
                            </div>
                            <div class="pw-strength mt-2">
                                <div class="pw-strength-bar"><div id="pw-strength-fill"></div></div>
                                <div class="small text-muted mt-1" id="pw-strength-label"><?= __('pw_requirements') ?></div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-muted"><?= __('confirm_password') ?></label>
                            <div class="pw-input">
                                <input type="password" name="confirm_password" id="confirm_password" class="form-control" minlength="8" required autocomplete="new-password">
                                <button type="button" class="pw-toggle" data-target="confirm_password"><i class="fa-regular fa-eye"></i></button>
                            </div>
                            <div class="small mt-1" id="pw-match-label"></div>
                        </div>
                        <button class="btn btn-teal btn-sm"><i class="fa-solid fa-shield-halved me-1"></i><?= __('update_password') ?></button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.settings-page form{max-width:1080px;margin:0 auto}

/* ═══ Hero clinic-profile card ═══ */
.settings-hero{display:grid;grid-template-columns:280px 1fr;gap:0;background:#fff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04);margin-bottom:1.5rem}
.settings-hero-side{background:linear-gradient(160deg,#0d9488 0%,#0f766e 100%);color:#fff;padding:2rem 1.5rem;display:flex;flex-direction:column}
.settings-hero-eyebrow{font-size:.7rem;letter-spacing:.18em;text-transform:uppercase;opacity:.85;font-weight:600}
.settings-hero-title{margin:.5rem 0 .25rem;font-size:1.35rem;font-weight:700}
.settings-hero-sub{font-size:.85rem;opacity:.9;line-height:1.7;margin-bottom:1.5rem}
.settings-where{list-style:none;padding:0;margin:auto 0 0;border-top:1px solid rgba(255,255,255,.18);padding-top:1.25rem}
.settings-where li{font-size:.82rem;margin-bottom:.55rem;display:flex;align-items:center;gap:.6rem;opacity:.95}
.settings-where i{width:18px;text-align:center;opacity:.85}

.settings-hero-main{padding:1.75rem 1.75rem 1.5rem;background:#fff}

/* ═══ Logo dropzone ═══ */
.logo-wrap{margin-bottom:1.25rem}
.logo-dropzone{display:flex;align-items:center;gap:1.25rem;padding:1.1rem;border:2px dashed #cbd5e1;border-radius:14px;background:#f8fafc;cursor:pointer;transition:.18s;margin:0;width:100%}
.logo-dropzone:hover{border-color:#5eead4;background:#f0fdfa}
.logo-dropzone.is-drag{border-color:#0d9488;background:#ecfdf5;transform:scale(1.01)}
.logo-preview-frame{flex:0 0 120px;width:120px;height:120px;border-radius:12px;background:#fff;border:1px solid #e2e8f0;display:flex;align-items:center;justify-content:center;overflow:hidden;padding:8px;position:relative}
.logo-preview-frame img{max-width:100%;max-height:100%;object-fit:contain;display:block}
.logo-placeholder{text-align:center;color:#94a3b8;padding:0 .5rem}
.logo-placeholder-icon{font-size:1.8rem;color:#cbd5e1}
.logo-placeholder-title{font-size:.78rem;font-weight:600;color:#475569;margin-top:.35rem;line-height:1.3}
.logo-placeholder-sub{font-size:.7rem;color:#94a3b8;margin-top:.15rem}
.logo-actions{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center}
.logo-specs{flex-basis:100%;font-size:.72rem;color:#94a3b8;margin-top:.4rem}
.logo-dropzone > div:last-child{flex:1;min-width:0}

/* Wrap layout: dropzone is wide; previewframe on the inline-start, copy+buttons stack */
.logo-dropzone{flex-wrap:wrap}
.logo-dropzone-text{flex:1;min-width:200px}

/* ═══ Field labels ═══ */
.settings-field-label{display:flex;align-items:center;gap:.45rem;font-size:.78rem;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.3rem}
.settings-field-label i{color:#0d9488}

/* ═══ Contact grid ═══ */
.contact-grid{display:grid;grid-template-columns:1fr 1fr;gap:.75rem}
.contact-field{display:flex;gap:.7rem;padding:.85rem 1rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:11px;transition:.15s}
.contact-field:focus-within{border-color:#5eead4;background:#f0fdfa;box-shadow:0 0 0 3px rgba(20,184,166,.08)}
.contact-field-wide{grid-column:1 / -1}
.contact-icon{width:38px;height:38px;flex-shrink:0;border-radius:10px;background:#0d9488;color:#fff;display:flex;align-items:center;justify-content:center;font-size:.95rem}
.contact-body{flex:1;min-width:0}
.contact-label{display:block;font-size:.7rem;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.15rem}
.contact-input{width:100%;border:0;background:transparent;padding:0;font-size:.95rem;color:#0f172a;outline:0}
.contact-input::placeholder{color:#cbd5e1}
.contact-help{font-size:.7rem;color:#94a3b8;margin-top:.15rem}

/* ═══ Social media grid ═══ */
.social-grid{display:grid;grid-template-columns:1fr 1fr;gap:.65rem;padding:1.25rem}
.social-row{display:flex;gap:.7rem;padding:.7rem .9rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:11px;transition:.15s}
.social-row:focus-within{border-color:#5eead4;background:#f0fdfa;box-shadow:0 0 0 3px rgba(20,184,166,.08)}
.social-row.is-on{border-color:#99f6e4;background:#f0fdfa}
.social-icon{width:36px;height:36px;flex-shrink:0;border-radius:10px;background:#0f172a;color:#fff;display:flex;align-items:center;justify-content:center;font-size:.9rem}
.social-row.is-on .social-icon{background:#0d9488}
.social-body{flex:1;min-width:0}
.social-label{display:block;font-size:.7rem;color:#64748b;font-weight:600;letter-spacing:.02em;margin-bottom:.1rem}
.social-input{width:100%;border:0;background:transparent;padding:0;font-size:.88rem;color:#0f172a;outline:0;font-family:inherit}
.social-input::placeholder{color:#cbd5e1}
@media (max-width:768px){.social-grid{grid-template-columns:1fr}}

/* ═══ Advanced settings card ═══ */
.settings-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 1px 3px rgba(0,0,0,.04);overflow:hidden}
.settings-card-head{padding:1rem 1.25rem;border-bottom:1px solid #f1f5f9;background:#fafbfc}

.settings-tabs-wrap{display:grid;grid-template-columns:220px 1fr;min-height:280px}
.settings-tabs{list-style:none;padding:1rem .6rem;margin:0;border-inline-end:1px solid #f1f5f9;background:#fafbfc;display:flex;flex-direction:column;gap:.25rem}
.settings-tab{display:flex;align-items:center;gap:.65rem;width:100%;padding:.65rem .85rem;border:0;border-radius:10px;background:transparent;color:#475569;font-size:.9rem;font-weight:500;text-align:start;cursor:pointer;transition:.15s}
.settings-tab i{width:18px;color:#94a3b8;font-size:.85rem}
.settings-tab span:first-of-type{flex:1}
.settings-tab-count{font-size:.7rem;background:#e2e8f0;color:#64748b;padding:.1rem .5rem;border-radius:99px;font-weight:600}
.settings-tab:hover{background:#fff;color:#0f172a}
.settings-tab.active{background:#0d9488;color:#fff;box-shadow:0 4px 12px rgba(13,148,136,.25)}
.settings-tab.active i{color:#fff}
.settings-tab.active .settings-tab-count{background:rgba(255,255,255,.2);color:#fff}

.settings-tabs-content{padding:1.5rem}
.settings-key{font-size:.7rem;color:#475569;background:#f1f5f9;padding:.1rem .4rem;border-radius:5px}

/* ═══ Save bar (sticky) ═══ */
.settings-savebar{position:sticky;bottom:0;background:rgba(255,255,255,.95);backdrop-filter:blur(8px);border:1px solid #e2e8f0;border-radius:14px;padding:.85rem 1.25rem;display:flex;justify-content:space-between;align-items:center;margin-top:1.5rem;box-shadow:0 4px 16px rgba(0,0,0,.08);z-index:5}

/* ═══ Responsive ═══ */
@media (max-width: 900px){
    .settings-hero{grid-template-columns:1fr}
    .settings-hero-side{padding:1.5rem}
    .settings-where{display:none}
    .settings-tabs-wrap{grid-template-columns:1fr}
    .settings-tabs{flex-direction:row;overflow-x:auto;padding:.6rem;border-inline-end:0;border-bottom:1px solid #f1f5f9;gap:.4rem}
    .settings-tab{flex-shrink:0}
    .contact-grid{grid-template-columns:1fr}
}
@media (max-width: 520px){
    .logo-dropzone{flex-direction:column;text-align:center}
}

/* My account section */
.pw-input{position:relative}
.pw-toggle{position:absolute;top:50%;inset-inline-end:.6rem;transform:translateY(-50%);border:0;background:transparent;color:#94a3b8;cursor:pointer;padding:.25rem}
.pw-toggle:hover{color:#0d9488}
.pw-strength-bar{height:5px;background:#e2e8f0;border-radius:99px;overflow:hidden}
#pw-strength-fill{height:100%;width:0;background:#ef4444;transition:width .25s,background .25s}
</style>

<script>
(function(){
    const dz = document.getElementById('logo-dropzone');
    if (!dz) return;
    const input = document.getElementById('clinic_logo_input');
    const img   = document.getElementById('logo-preview');
    const ph    = document.getElementById('logo-placeholder');
    const txt   = document.getElementById('logo-action-text');

    function showFile(file){
        if (!file || !file.type.startsWith('image/')) return;
        const url = URL.createObjectURL(file);
        if (img){ img.src = url; img.style.display = 'block'; }
        if (ph) ph.style.display = 'none';
        if (txt) txt.textContent = '<?= __('replace_logo') ?>';
    }

    input.addEventListener('change', e => showFile(e.target.files[0]));

    ['dragover','dragenter'].forEach(ev => dz.addEventListener(ev, e => {
        e.preventDefault(); dz.classList.add('is-drag');
    }));
    ['dragleave','drop'].forEach(ev => dz.addEventListener(ev, e => {
        e.preventDefault(); dz.classList.remove('is-drag');
    }));
    dz.addEventListener('drop', e => {
        const f = e.dataTransfer.files && e.dataTransfer.files[0];
        if (!f) return;
        // Transfer the file into the actual input so it submits with the form
        const dt = new DataTransfer();
        dt.items.add(f);
        input.files = dt.files;
        showFile(f);
    });

    // Uncheck "remove logo" automatically if a new file is chosen
    input.addEventListener('change', () => {
        const rm = document.getElementById('remove-logo-cb');
        if (rm) rm.checked = false;
    });
})();

// Account: show/hide password + strength meter
(function(){
    document.querySelectorAll('.pw-toggle').forEach(b => {
        b.addEventListener('click', () => {
            const inp = document.querySelector(`[name="${b.dataset.target}"]`);
            if (!inp) return;
            const isPw = inp.type === 'password';
            inp.type = isPw ? 'text' : 'password';
            b.innerHTML = isPw ? '<i class="fa-regular fa-eye-slash"></i>' : '<i class="fa-regular fa-eye"></i>';
        });
    });

    const np = document.getElementById('new_password');
    const cp = document.getElementById('confirm_password');
    const fill = document.getElementById('pw-strength-fill');
    const label = document.getElementById('pw-strength-label');
    const match = document.getElementById('pw-match-label');
    if (!np) return;
    const i18n = {
        weak:'<?= __('pw_weak') ?>', fair:'<?= __('pw_fair') ?>',
        good:'<?= __('pw_good') ?>', strong:'<?= __('pw_strong') ?>',
        match:'<?= __('pw_match') ?>', nomatch:'<?= __('pw_nomatch') ?>',
    };
    const reqText = '<?= __('pw_requirements') ?>';
    function score(p){
        let s = 0;
        if (p.length >= 8) s++;
        if (p.length >= 12) s++;
        if (/[A-Z]/.test(p) && /[a-z]/.test(p)) s++;
        if (/\d/.test(p)) s++;
        if (/[^A-Za-z0-9]/.test(p)) s++;
        return Math.min(4, s);
    }
    np.addEventListener('input', () => {
        const s = score(np.value);
        const pct = [0,25,50,75,100][s];
        const colors = ['#e2e8f0','#ef4444','#f59e0b','#0ea5e9','#10b981'];
        const txt    = [reqText, i18n.weak, i18n.fair, i18n.good, i18n.strong];
        fill.style.width = pct + '%';
        fill.style.background = colors[s];
        label.textContent = txt[s];
    });
    function checkMatch(){
        if (!cp.value){ match.textContent = ''; return; }
        if (np.value === cp.value) match.innerHTML = '<i class="fa-solid fa-check text-success me-1"></i>'+i18n.match;
        else                        match.innerHTML = '<i class="fa-solid fa-xmark text-danger me-1"></i>'+i18n.nomatch;
    }
    cp.addEventListener('input', checkMatch);
    np.addEventListener('input', checkMatch);
})();
</script>
<?php include BP_PARTIALS . '/footer.php'; ?>
