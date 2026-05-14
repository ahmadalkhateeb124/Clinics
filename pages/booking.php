<?php
$PageTitle = 'احجز موعدك — ' . t('site_name');
$escapedDescription = "احجز موعدك في عيادة لمسة نور — اختر الخدمة، المعالج، الوقت المناسب.";

global $pdo;
$success = false; $error = '';
$prefService   = (int)($_GET['service']   ?? 0);
$prefTherapist = (int)($_GET['therapist'] ?? 0);
$prefPackage   = (int)($_GET['package']   ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name        = trim($_POST['name'] ?? '');
    $phone       = trim($_POST['phone'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $serviceId   = (int)($_POST['service_id'] ?? 0) ?: null;
    $therapistId = (int)($_POST['therapist_id'] ?? 0) ?: null;
    $when        = trim($_POST['requested_at'] ?? '');
    $notes       = trim($_POST['notes'] ?? '');
    $hp          = $_POST['website'] ?? '';

    if ($hp !== '')                         $error = 'Spam detected.';
    elseif ($name === '' || $phone === '')  $error = 'الاسم ورقم الهاتف مطلوبان.';
    elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'البريد الإلكتروني غير صالح.';
    elseif (!$when || !strtotime($when))    $error = 'يرجى اختيار وقت صحيح.';
    elseif (strtotime($when) < time())      $error = 'لا يمكن اختيار وقت في الماضي.';

    if (!$error && $pdo instanceof PDO) {
        try {
            $pdo->prepare("INSERT INTO booking_requests
                (patient_name, phone, email, service_id, therapist_id, requested_at, notes, status, ip, created_at, updated_at)
                VALUES (?,?,?,?,?,?,?, 'pending', ?, NOW(), NOW())")
                ->execute([$name, $phone, $email ?: null, $serviceId, $therapistId,
                           date('Y-m-d H:i:s', strtotime($when)), $notes ?: null,
                           $_SERVER['REMOTE_ADDR'] ?? null]);
            $success = true;
        } catch (Throwable $e) {
            $error = 'حدث خطأ، حاول لاحقاً.';
        }
    }
}

$services = []; $therapists = [];
if ($pdo instanceof PDO) {
    try {
        $services   = $pdo->query("
            SELECT s.id, s.name_ar, s.duration_minutes, s.price, c.name_ar AS cat
            FROM services s LEFT JOIN service_categories c ON c.id=s.category_id
            WHERE s.deleted_at IS NULL AND s.is_active=1
            ORDER BY c.sort_order, s.name_ar
        ")->fetchAll();
        $therapists = $pdo->query("
            SELECT u.id, u.name FROM users u
            JOIN user_roles ur ON ur.user_id = u.id
            JOIN roles r ON r.id = ur.role_id
            WHERE u.deleted_at IS NULL AND u.status='active' AND r.slug IN ('therapist','doctor')
            GROUP BY u.id ORDER BY u.name
        ")->fetchAll();
    } catch (Throwable $e) {}
}

$workingFrom = site_setting('working_hours_from','09:00');
$workingTo   = site_setting('working_hours_to','21:00');
?>
<section class="hero-section py-5 text-center">
    <div class="container" data-aos="fade-up">
        <h1 class="text-teal"><?= t('book_now') ?></h1>
        <p class="lead text-muted">اختر الخدمة والوقت — وسنتواصل معك لتأكيد الحجز</p>
    </div>
</section>

<section class="py-5">
    <div class="container" style="max-width:760px" data-aos="fade-up">
        <div class="booking-card">
            <?php if ($success): ?>
                <div class="text-center py-4">
                    <i class="fa-regular fa-circle-check fa-4x text-success mb-3"></i>
                    <h4>تم استلام طلب الحجز</h4>
                    <p class="text-muted">شكراً لك، سيتواصل معك فريقنا قريباً لتأكيد الموعد.</p>
                    <a href="<?= $base_url ?>" class="btn btn-teal mt-2"><?= t('home') ?></a>
                </div>
            <?php else: ?>
                <h4 class="text-teal mb-3">معلومات الحجز</h4>
                <?php if ($error): ?>
                    <div class="alert alert-danger small"><?= e($error) ?></div>
                <?php endif; ?>

                <form method="post" id="bookingForm">
                    <input type="text" name="website" style="display:none">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">الاسم الكامل *</label>
                            <input name="name" required class="form-control" value="<?= e($_POST['name'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">الهاتف *</label>
                            <input name="phone" required class="form-control" value="<?= e($_POST['phone'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">البريد (اختياري)</label>
                            <input name="email" type="email" class="form-control" value="<?= e($_POST['email'] ?? '') ?>">
                        </div>

                        <div class="col-md-8">
                            <label class="form-label">الخدمة</label>
                            <select name="service_id" id="svcSelect" class="form-select">
                                <option value="">— اختر الخدمة —</option>
                                <?php foreach ($services as $s): ?>
                                    <option value="<?= (int)$s['id'] ?>" data-duration="<?= (int)$s['duration_minutes'] ?>" data-price="<?= e($s['price']) ?>"
                                            <?= $prefService===(int)$s['id']?'selected':'' ?>>
                                        <?= e($s['name_ar']) ?> · <?= (int)$s['duration_minutes'] ?>د · <?= format_money($s['price']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">المعالج (اختياري)</label>
                            <select name="therapist_id" id="thSelect" class="form-select">
                                <option value="">أي معالج</option>
                                <?php foreach ($therapists as $t): ?>
                                    <option value="<?= (int)$t['id'] ?>" <?= $prefTherapist===(int)$t['id']?'selected':'' ?>><?= e($t['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">التاريخ والوقت *</label>
                            <input type="datetime-local" name="requested_at" id="whenInput" required class="form-control"
                                   min="<?= date('Y-m-d\TH:i') ?>"
                                   value="<?= e($_POST['requested_at'] ?? '') ?>">
                            <small class="text-muted">ساعات الدوام: <?= e($workingFrom) ?> – <?= e($workingTo) ?></small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">حالة التوفر</label>
                            <div id="availability" class="form-control bg-light small text-muted">اختر الخدمة والوقت لفحص التوفر…</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">ملاحظات (اختياري)</label>
                            <textarea name="notes" rows="3" class="form-control"><?= e($_POST['notes'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <div class="d-grid mt-4">
                        <button class="btn btn-teal btn-lg"><i class="fa-regular fa-calendar-check me-2"></i>إرسال طلب الحجز</button>
                    </div>
                    <p class="small text-muted text-center mt-3 mb-0">سنتواصل معك خلال ساعات العمل لتأكيد الموعد.</p>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>

<script>
// Live availability check (debounced)
const svcSel = document.getElementById('svcSelect');
const thSel  = document.getElementById('thSelect');
const whenIn = document.getElementById('whenInput');
const availDiv = document.getElementById('availability');
let to;

function checkAvail() {
    if (!whenIn || !whenIn.value) {
        availDiv.textContent = 'اختر الوقت أولاً';
        availDiv.className = 'form-control bg-light small text-muted';
        return;
    }
    const fd = new URLSearchParams();
    fd.set('start', whenIn.value);
    if (svcSel.value) fd.set('service_id', svcSel.value);
    if (thSel.value)  fd.set('therapist_id', thSel.value);

    availDiv.textContent = 'جاري الفحص…';
    availDiv.className = 'form-control bg-light small text-muted';

    fetch('<?= $base_url ?>inc/check_availability.php?' + fd.toString())
        .then(r => r.json())
        .then(j => {
            if (j.available) {
                availDiv.textContent = '✓ الوقت متاح';
                availDiv.className = 'form-control bg-success-subtle text-success small';
            } else {
                availDiv.textContent = '✗ ' + (j.reason || 'الوقت غير متاح');
                availDiv.className = 'form-control bg-danger-subtle text-danger small';
            }
        })
        .catch(() => {
            availDiv.textContent = '—';
            availDiv.className = 'form-control bg-light small text-muted';
        });
}

[svcSel, thSel, whenIn].forEach(el => {
    if (!el) return;
    el.addEventListener('change', () => { clearTimeout(to); to = setTimeout(checkAvail, 300); });
});
</script>
