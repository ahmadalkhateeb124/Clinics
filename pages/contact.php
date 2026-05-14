<?php
$PageTitle          = t('contact') . ' — ' . t('site_name');
$escapedDescription = "تواصل مع عيادة لمسة نور — هاتف، إيميل، عنوان، نموذج اتصال مباشر.";

global $pdo;
$success = false; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['name'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $hp      = $_POST['website'] ?? '';   // honeypot

    if ($hp !== '')                                                       $error = t('spam_detected','تم رصد محاولة سبام.');
    elseif ($name === '' || $message === '')                              $error = t('contact_required','الاسم والرسالة مطلوبان.');
    elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))  $error = t('email_invalid','البريد الإلكتروني غير صالح.');
    elseif (mb_strlen($message) < 10)                                     $error = t('message_too_short','الرسالة قصيرة جداً.');

    if (!$error && $pdo instanceof PDO) {
        try {
            $pdo->prepare("INSERT INTO contact_messages (name,email,phone,subject,message,ip,created_at) VALUES (?,?,?,?,?,?,NOW())")
                ->execute([$name, $email ?: null, $phone ?: null, $subject ?: null, $message,
                           $_SERVER['REMOTE_ADDR'] ?? null]);
            $success = true;
        } catch (Throwable $e) {
            $error = t('contact_err','حدث خطأ، حاول لاحقاً.');
        }
    }
}

$phoneSet   = site_setting('contact_phone', '');
$emailSet   = site_setting('contact_email', 'info@nourstouch.com');
$addressSet = site_setting('address', 'عمّان، الأردن');
$hoursFrom  = site_setting('working_hours_from', '09:00');
$hoursTo    = site_setting('working_hours_to', '21:00');
$mapEmbed   = site_setting('google_map_embed', '');
$fb         = site_setting('social_facebook', '');
$ig         = site_setting('social_instagram', '');
$arrow      = $dir === 'rtl' ? 'left' : 'right';
?>

<!-- ════════ PAGE HERO ════════ -->
<section class="hero" style="padding-bottom:2rem">
    <div class="container">
        <div class="page-hero reveal">
            <div class="page-hero-content">
                <span class="tag"><i class="fa-regular fa-envelope"></i><?= t('get_in_touch','تواصل معنا') ?></span>
                <h1 class="hero-h1" style="font-size:clamp(2.2rem,4.5vw,3.8rem);margin:1.25rem 0 1.25rem">
                    <?= t('contact_h', 'نُسعد بسماع <em>صوتك</em>.') ?>
                </h1>
                <p class="h-lead" style="margin-bottom:0;max-width:62ch">
                    <?= t('contact_lead', 'اسأل، استشر، أو احجز — فريقنا يردّ خلال ساعات العمل.') ?>
                </p>
            </div>
            <ul class="page-hero-crumbs">
                <li><a href="<?= $base_url ?>"><?= t('home') ?></a></li>
                <li>·</li>
                <li><?= t('contact') ?></li>
            </ul>
        </div>
    </div>
</section>

<!-- ════════ CONTACT CHANNELS STRIP ════════ -->
<section class="section tight" style="padding:0">
    <div class="container">
        <div class="contact-channels reveal">
            <?php if ($phoneSet): ?>
            <a class="contact-channel" href="<?= tel_link($phoneSet) ?>">
                <div class="contact-channel-ico"><i class="fa-solid fa-phone"></i></div>
                <div>
                    <div class="contact-channel-label"><?= t('call_us','اتصل بنا') ?></div>
                    <div class="contact-channel-value" dir="ltr"><?= e($phoneSet) ?></div>
                </div>
            </a>
            <a class="contact-channel" href="<?= wa_link($phoneSet) ?>" target="_blank" rel="noopener">
                <div class="contact-channel-ico whatsapp"><i class="fa-brands fa-whatsapp"></i></div>
                <div>
                    <div class="contact-channel-label"><?= t('whatsapp_us','واتساب') ?></div>
                    <div class="contact-channel-value"><?= t('quick_reply','ردّ سريع') ?></div>
                </div>
            </a>
            <?php endif; ?>
            <a class="contact-channel" href="mailto:<?= e($emailSet) ?>">
                <div class="contact-channel-ico"><i class="fa-regular fa-envelope"></i></div>
                <div>
                    <div class="contact-channel-label"><?= t('email_us','راسلنا') ?></div>
                    <div class="contact-channel-value" dir="ltr"><?= e($emailSet) ?></div>
                </div>
            </a>
            <div class="contact-channel" style="cursor:default">
                <div class="contact-channel-ico"><i class="fa-regular fa-clock"></i></div>
                <div>
                    <div class="contact-channel-label"><?= t('working_hours','ساعات العمل') ?></div>
                    <div class="contact-channel-value" dir="ltr"><?= e($hoursFrom) ?> – <?= e($hoursTo) ?></div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ════════ FORM + INFO SIDE PANEL ════════ -->
<section class="section">
    <div class="container">
        <div class="contact-grid">
            <!-- Form card -->
            <div class="contact-form-card reveal">
                <div class="contact-form-head">
                    <span class="contact-form-kicker"><?= t('send_message','أرسل رسالة') ?></span>
                    <h2 class="contact-form-title"><?= t('contact_form_h', 'اكتب لنا — <em>سنعود إليك</em>.') ?></h2>
                    <p class="contact-form-sub"><?= t('contact_form_p', 'سواء كانت استشارة، استفسار عن خدمة، أو ملاحظة — كلّ رسالة تصلنا، ونحرص على الرد خلال 24 ساعة.') ?></p>
                </div>

                <?php if ($success): ?>
                    <div class="contact-alert ok">
                        <i class="fa-solid fa-circle-check"></i>
                        <div>
                            <strong><?= t('thanks','شكراً لك') ?></strong>
                            <span><?= t('contact_ok','وصلتنا رسالتك — سنعود إليك قريباً.') ?></span>
                        </div>
                    </div>
                <?php elseif ($error): ?>
                    <div class="contact-alert err">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <div><?= e($error) ?></div>
                    </div>
                <?php endif; ?>

                <form method="post" class="contact-form" novalidate>
                    <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">

                    <div class="contact-form-row">
                        <label class="contact-field">
                            <span><?= t('full_name','الاسم') ?> <em>*</em></span>
                            <input name="name" required value="<?= e($_POST['name'] ?? '') ?>">
                        </label>
                        <label class="contact-field">
                            <span><?= t('phone','الهاتف') ?></span>
                            <input name="phone" inputmode="tel" value="<?= e($_POST['phone'] ?? '') ?>">
                        </label>
                    </div>

                    <div class="contact-form-row">
                        <label class="contact-field">
                            <span><?= t('email','البريد الإلكتروني') ?></span>
                            <input name="email" type="email" value="<?= e($_POST['email'] ?? '') ?>">
                        </label>
                        <label class="contact-field">
                            <span><?= t('subject','الموضوع') ?></span>
                            <input name="subject" value="<?= e($_POST['subject'] ?? '') ?>">
                        </label>
                    </div>

                    <label class="contact-field">
                        <span><?= t('message','الرسالة') ?> <em>*</em></span>
                        <textarea name="message" required rows="6" placeholder="<?= t('message_ph','اكتب رسالتك هنا…') ?>"><?= e($_POST['message'] ?? '') ?></textarea>
                    </label>

                    <div class="contact-form-foot">
                        <p class="contact-form-note"><?= t('contact_privacy','بياناتك خاصة — لا نشاركها مع أي طرف ثالث.') ?></p>
                        <button type="submit" class="btn btn-teal">
                            <i class="fa-regular fa-paper-plane"></i>
                            <?= t('send','إرسال') ?>
                            <i class="fa-solid fa-arrow-<?= $arrow ?>"></i>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Info aside -->
            <aside class="contact-aside reveal">
                <div class="contact-aside-card">
                    <span class="contact-aside-eyebrow"><?= t('visit_us','زورنا') ?></span>
                    <h3 class="contact-aside-title"><?= t('contact_visit_h','نحن هنا.') ?></h3>

                    <ul class="contact-info-list">
                        <li>
                            <i class="fa-solid fa-location-dot"></i>
                            <div>
                                <div class="contact-info-label"><?= t('address','العنوان') ?></div>
                                <div class="contact-info-value"><?= e($addressSet) ?></div>
                            </div>
                        </li>
                        <?php if ($phoneSet): ?>
                        <li>
                            <i class="fa-solid fa-phone"></i>
                            <div>
                                <div class="contact-info-label"><?= t('phone','الهاتف') ?></div>
                                <div class="contact-info-value" dir="ltr"><a href="<?= tel_link($phoneSet) ?>"><?= e($phoneSet) ?></a></div>
                            </div>
                        </li>
                        <?php endif; ?>
                        <li>
                            <i class="fa-regular fa-envelope"></i>
                            <div>
                                <div class="contact-info-label"><?= t('email','البريد الإلكتروني') ?></div>
                                <div class="contact-info-value" dir="ltr"><a href="mailto:<?= e($emailSet) ?>"><?= e($emailSet) ?></a></div>
                            </div>
                        </li>
                        <li>
                            <i class="fa-regular fa-clock"></i>
                            <div>
                                <div class="contact-info-label"><?= t('working_hours','ساعات العمل') ?></div>
                                <div class="contact-info-value" dir="ltr"><?= e($hoursFrom) ?> – <?= e($hoursTo) ?></div>
                            </div>
                        </li>
                    </ul>

                    <?php if ($fb || $ig || $phoneSet): ?>
                    <div class="contact-aside-social">
                        <span class="contact-aside-social-label"><?= t('follow_us','تابعنا') ?></span>
                        <div class="contact-aside-social-btns">
                            <?php if ($phoneSet): ?>
                                <a href="<?= wa_link($phoneSet) ?>" target="_blank" rel="noopener" aria-label="WhatsApp"><i class="fa-brands fa-whatsapp"></i></a>
                            <?php endif; ?>
                            <?php if ($fb): ?>
                                <a href="<?= e($fb) ?>" target="_blank" rel="noopener" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
                            <?php endif; ?>
                            <?php if ($ig): ?>
                                <a href="<?= e($ig) ?>" target="_blank" rel="noopener" aria-label="Instagram"><i class="fa-brands fa-instagram"></i></a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <a href="<?= $base_url ?>booking" class="contact-cta-card">
                    <div>
                        <div class="contact-cta-eyebrow"><?= t('start_today','ابدأ اليوم') ?></div>
                        <div class="contact-cta-title"><?= t('book_a_session','احجز جلسة') ?></div>
                        <div class="contact-cta-sub"><?= t('book_a_session_sub','الحجز يأخذ دقيقة واحدة فقط.') ?></div>
                    </div>
                    <i class="fa-solid fa-arrow-<?= $arrow ?>"></i>
                </a>
            </aside>
        </div>
    </div>
</section>

<?php if ($mapEmbed): ?>
<!-- ════════ MAP ════════ -->
<section class="section" style="padding-top:0">
    <div class="container">
        <div class="contact-map reveal">
            <?= $mapEmbed /* trusted from settings (admin only) */ ?>
        </div>
    </div>
</section>
<?php endif; ?>
