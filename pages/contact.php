<?php
$PageTitle = 'تواصل معنا — ' . t('site_name');
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

    if ($hp !== '')               $error = 'Spam detected.';
    elseif ($name === '' || $message === '') $error = 'الاسم والرسالة مطلوبان.';
    elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'البريد الإلكتروني غير صالح.';
    elseif (mb_strlen($message) < 10) $error = 'الرسالة قصيرة جداً.';

    if (!$error && $pdo instanceof PDO) {
        try {
            $pdo->prepare("INSERT INTO contact_messages (name,email,phone,subject,message,ip,created_at) VALUES (?,?,?,?,?,?,NOW())")
                ->execute([$name, $email ?: null, $phone ?: null, $subject ?: null, $message,
                           $_SERVER['REMOTE_ADDR'] ?? null]);
            $success = true;
        } catch (Throwable $e) {
            $error = 'حدث خطأ، حاول لاحقاً.';
        }
    }
}
?>
<section class="hero-section py-5 text-center">
    <div class="container" data-aos="fade-up">
        <h1 class="text-teal">تواصل معنا</h1>
        <p class="lead text-muted">نسعد بخدمتكم — اتصلوا بنا أو راسلونا</p>
    </div>
</section>

<section class="py-5">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-5" data-aos="fade-up">
                <div class="card p-4 h-100">
                    <h5 class="text-teal mb-3">معلومات التواصل</h5>
                    <p class="mb-2"><i class="fa-solid fa-location-dot text-teal me-2"></i><?= e(site_setting('address','Amman, Jordan')) ?></p>
                    <p class="mb-2"><i class="fa-solid fa-phone text-teal me-2"></i>
                        <a class="text-dark text-decoration-none" href="<?= tel_link(site_setting('contact_phone','+962700000000')) ?>"><?= e(site_setting('contact_phone','+962 7 0000 0000')) ?></a>
                    </p>
                    <p class="mb-2"><i class="fa-solid fa-envelope text-teal me-2"></i>
                        <a class="text-dark text-decoration-none" href="mailto:<?= e(site_setting('contact_email','info@nourstouch.com')) ?>"><?= e(site_setting('contact_email','info@nourstouch.com')) ?></a>
                    </p>
                    <p class="mb-2"><i class="fa-regular fa-clock text-teal me-2"></i><?= e(site_setting('working_hours_from','09:00')) ?> – <?= e(site_setting('working_hours_to','21:00')) ?></p>
                    <hr>
                    <a class="btn btn-success" target="_blank" href="<?= wa_link(site_setting('contact_phone','+962700000000')) ?>">
                        <i class="fa-brands fa-whatsapp me-2"></i>راسلنا على واتساب
                    </a>
                </div>
            </div>

            <div class="col-lg-7" data-aos="fade-up" data-aos-delay="100">
                <div class="card p-4 h-100">
                    <h5 class="text-teal mb-3">أرسل رسالة</h5>
                    <?php if ($success): ?>
                        <div class="alert alert-success">شكراً لك — وصلتنا رسالتك وسنعود إليك قريباً.</div>
                    <?php elseif ($error): ?>
                        <div class="alert alert-danger small"><?= e($error) ?></div>
                    <?php endif; ?>

                    <form method="post">
                        <input type="text" name="website" style="display:none">
                        <div class="row g-3">
                            <div class="col-md-6"><label class="form-label">الاسم *</label>
                                <input name="name" required class="form-control" value="<?= e($_POST['name'] ?? '') ?>"></div>
                            <div class="col-md-6"><label class="form-label">الهاتف</label>
                                <input name="phone" class="form-control" value="<?= e($_POST['phone'] ?? '') ?>"></div>
                            <div class="col-md-6"><label class="form-label">البريد الإلكتروني</label>
                                <input name="email" type="email" class="form-control" value="<?= e($_POST['email'] ?? '') ?>"></div>
                            <div class="col-md-6"><label class="form-label">الموضوع</label>
                                <input name="subject" class="form-control" value="<?= e($_POST['subject'] ?? '') ?>"></div>
                            <div class="col-12"><label class="form-label">الرسالة *</label>
                                <textarea name="message" required rows="5" class="form-control"><?= e($_POST['message'] ?? '') ?></textarea></div>
                        </div>
                        <button class="btn btn-teal mt-3"><i class="fa-regular fa-paper-plane me-2"></i>إرسال</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>
