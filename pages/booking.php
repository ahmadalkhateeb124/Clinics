<?php
$PageTitle          = t('book_now') . ' — ' . t('site_name');
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

    if ($hp !== '')                                                      $error = t('spam_detected','تم رصد محاولة سبام.');
    elseif ($name === '' || $phone === '')                               $error = t('booking_required','الاسم ورقم الهاتف مطلوبان.');
    elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $error = t('email_invalid','البريد الإلكتروني غير صالح.');
    elseif (!$when || !strtotime($when))                                 $error = t('booking_time_invalid','يرجى اختيار وقت صحيح.');
    elseif (strtotime($when) < time())                                   $error = t('booking_time_past','لا يمكن اختيار وقت في الماضي.');

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
            $error = t('booking_err','حدث خطأ، حاول لاحقاً.');
        }
    }
}

$services = []; $therapists = [];
if ($pdo instanceof PDO) {
    try {
        $services = $pdo->query("
            SELECT s.id, s.name_ar, s.name_en, s.duration_minutes, s.price,
                   c.name_ar AS cat_ar, c.name_en AS cat_en
            FROM services s LEFT JOIN service_categories c ON c.id=s.category_id
            WHERE s.deleted_at IS NULL AND s.is_active=1
            ORDER BY c.sort_order, s.name_ar
        ")->fetchAll();
        $therapists = $pdo->query("
            SELECT u.id, u.name,
                   e.first_name, e.first_name_en, e.last_name, e.last_name_en,
                   e.job_title, e.job_title_en
            FROM employees e
            JOIN users u ON u.id = e.user_id
            WHERE e.deleted_at IS NULL
              AND e.is_active = 1
              AND e.show_on_site = 1
              AND u.deleted_at IS NULL
              AND u.status = 'active'
            ORDER BY e.first_name
        ")->fetchAll();
        foreach ($therapists as &$t) {
            $fn = ($lang === 'en' && !empty($t['first_name_en'])) ? $t['first_name_en'] : $t['first_name'];
            $ln = ($lang === 'en' && !empty($t['last_name_en']))  ? $t['last_name_en']  : $t['last_name'];
            $t['display_name'] = trim($fn . ' ' . $ln) ?: $t['name'];
            $t['display_role'] = ($lang === 'en' && !empty($t['job_title_en'])) ? $t['job_title_en'] : ($t['job_title'] ?? '');
        }
        unset($t);
    } catch (Throwable $e) {}
}

$workingFrom = site_setting('working_hours_from','09:00');
$workingTo   = site_setting('working_hours_to','21:00');
$arrow       = $dir === 'rtl' ? 'left' : 'right';
$minDateTime = date('Y-m-d\TH:i');
?>

<!-- ════════ PAGE HERO ════════ -->
<section class="hero" style="padding-bottom:2rem">
    <div class="container">
        <div class="page-hero reveal">
            <div class="page-hero-content">
                <span class="tag"><i class="fa-regular fa-calendar-check"></i><?= t('booking','احجز موعدك') ?></span>
                <h1 class="hero-h1" style="font-size:clamp(2.2rem,4.5vw,3.8rem);margin:1.25rem 0 1.25rem">
                    <?= t('booking_h', 'احجز جلستك <em>خلال دقيقة</em>.') ?>
                </h1>
                <p class="h-lead" style="margin-bottom:0;max-width:62ch">
                    <?= t('booking_lead', 'اختر الخدمة والمعالج والوقت — وسنتواصل معك لتأكيد الموعد.') ?>
                </p>
            </div>
            <ul class="page-hero-crumbs">
                <li><a href="<?= $base_url ?>"><?= t('home') ?></a></li>
                <li>·</li>
                <li><?= t('book_now') ?></li>
            </ul>
        </div>
    </div>
</section>

<!-- ════════ BOOKING ════════ -->
<section class="section">
    <div class="container">
        <div class="booking-grid">
            <!-- Main form card -->
            <div class="booking-card reveal">
                <?php if ($success): ?>
                    <div class="booking-success">
                        <div class="booking-success-ico"><i class="fa-solid fa-check"></i></div>
                        <h2><?= t('booking_received','تم استلام طلب الحجز') ?></h2>
                        <p><?= t('booking_received_p','شكراً لك — سيتواصل معك فريقنا قريباً لتأكيد الموعد.') ?></p>
                        <div class="booking-success-actions">
                            <a href="<?= $base_url ?>" class="btn btn-teal">
                                <?= t('back_to_home','العودة للرئيسية') ?>
                                <i class="fa-solid fa-arrow-<?= $arrow ?>"></i>
                            </a>
                            <a href="<?= $base_url ?>services" class="btn btn-ghost"><?= t('browse_services','تصفح الخدمات') ?></a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="booking-head">
                        <span class="booking-kicker"><?= t('booking_form','تفاصيل الحجز') ?></span>
                        <h2 class="booking-title"><?= t('booking_form_h','أخبرنا قليلاً <em>عنك</em>.') ?></h2>
                        <p class="booking-sub"><?= t('booking_form_p','نحتاج بضع تفاصيل فقط لتأكيد موعدك.') ?></p>
                    </div>

                    <?php if ($error): ?>
                        <div class="contact-alert err">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            <div><?= e($error) ?></div>
                        </div>
                    <?php endif; ?>

                    <form method="post" id="bookingForm" class="booking-form" novalidate>
                        <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">

                        <!-- Step 1: Your details -->
                        <div class="booking-step">
                            <div class="booking-step-head">
                                <span class="booking-step-num">1</span>
                                <h3><?= t('your_details','بياناتك') ?></h3>
                            </div>
                            <div class="booking-step-body">
                                <div class="booking-row cols-2">
                                    <label class="contact-field">
                                        <span><?= t('full_name','الاسم') ?> <em>*</em></span>
                                        <input name="name" required value="<?= e($_POST['name'] ?? '') ?>">
                                    </label>
                                    <label class="contact-field">
                                        <span><?= t('phone','الهاتف') ?> <em>*</em></span>
                                        <input name="phone" inputmode="tel" required value="<?= e($_POST['phone'] ?? '') ?>">
                                    </label>
                                </div>
                                <label class="contact-field">
                                    <span><?= t('email_optional','البريد الإلكتروني (اختياري)') ?></span>
                                    <input name="email" type="email" value="<?= e($_POST['email'] ?? '') ?>">
                                </label>
                            </div>
                        </div>

                        <!-- Step 2: Service & therapist -->
                        <div class="booking-step">
                            <div class="booking-step-head">
                                <span class="booking-step-num">2</span>
                                <h3><?= t('choose_service','اختر الخدمة') ?></h3>
                            </div>
                            <div class="booking-step-body">
                                <div class="booking-row cols-2">
                                    <label class="contact-field">
                                        <span><?= t('service','الخدمة') ?></span>
                                        <select name="service_id" id="svcSelect">
                                            <option value="">— <?= t('select_service','اختر الخدمة') ?> —</option>
                                            <?php foreach ($services as $s):
                                                $sn = tr($s, 'name');
                                            ?>
                                                <option value="<?= (int)$s['id'] ?>" data-duration="<?= (int)$s['duration_minutes'] ?>" data-price="<?= e($s['price']) ?>"
                                                        <?= $prefService===(int)$s['id']?'selected':'' ?>>
                                                    <?= e($sn) ?> · <?= (int)$s['duration_minutes'] ?> <?= t('min','د') ?> · <?= format_money($s['price']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label class="contact-field">
                                        <span><?= t('therapist_optional','المعالج (اختياري)') ?></span>
                                        <select name="therapist_id" id="thSelect">
                                            <option value=""><?= t('any_therapist','أي معالج متاح') ?></option>
                                            <?php foreach ($therapists as $t): ?>
                                                <option value="<?= (int)$t['id'] ?>" <?= $prefTherapist===(int)$t['id']?'selected':'' ?>>
                                                    <?= e($t['display_name']) ?><?= $t['display_role'] ? ' — '.e($t['display_role']) : '' ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Step 3: When -->
                        <div class="booking-step">
                            <div class="booking-step-head">
                                <span class="booking-step-num">3</span>
                                <h3><?= t('pick_time','حدّد الوقت') ?></h3>
                            </div>
                            <div class="booking-step-body">
                                <div class="booking-row cols-2">
                                    <label class="contact-field">
                                        <span><?= t('date_time','التاريخ والوقت') ?> <em>*</em></span>
                                        <input type="datetime-local" name="requested_at" id="whenInput" required
                                               min="<?= $minDateTime ?>"
                                               value="<?= e($_POST['requested_at'] ?? '') ?>">
                                        <small class="contact-field-hint">
                                            <i class="fa-regular fa-clock"></i>
                                            <?= t('working_hours','ساعات العمل') ?>: <span dir="ltr"><?= e($workingFrom) ?> – <?= e($workingTo) ?></span>
                                        </small>
                                    </label>
                                    <div class="contact-field">
                                        <span><?= t('availability','حالة التوفر') ?></span>
                                        <div id="availability" class="booking-avail idle">
                                            <i class="fa-regular fa-circle-question"></i>
                                            <span><?= t('avail_idle','اختر الخدمة والوقت لفحص التوفر…') ?></span>
                                        </div>
                                    </div>
                                </div>
                                <label class="contact-field">
                                    <span><?= t('notes_optional','ملاحظات (اختياري)') ?></span>
                                    <textarea name="notes" rows="3" placeholder="<?= t('notes_ph','أي تفاصيل تودّ مشاركتها معنا…') ?>"><?= e($_POST['notes'] ?? '') ?></textarea>
                                </label>
                            </div>
                        </div>

                        <div class="booking-foot">
                            <p class="booking-foot-note">
                                <i class="fa-regular fa-circle-check"></i>
                                <?= t('booking_confirm_note','سنتواصل معك خلال ساعات العمل لتأكيد الموعد.') ?>
                            </p>
                            <button type="submit" class="btn btn-teal btn-lg">
                                <i class="fa-regular fa-calendar-check"></i>
                                <?= t('send_request','إرسال طلب الحجز') ?>
                                <i class="fa-solid fa-arrow-<?= $arrow ?>"></i>
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Side: summary + reassurance -->
            <aside class="booking-aside reveal">
                <div class="booking-summary" id="bookingSummary">
                    <span class="booking-summary-eyebrow"><?= t('your_booking','حجزك') ?></span>
                    <h3 class="booking-summary-title"><?= t('booking_summary','ملخّص الموعد') ?></h3>

                    <ul class="booking-summary-list">
                        <li>
                            <span class="booking-summary-label"><?= t('service','الخدمة') ?></span>
                            <span class="booking-summary-value" data-summary="service">—</span>
                        </li>
                        <li>
                            <span class="booking-summary-label"><?= t('therapist','المعالج') ?></span>
                            <span class="booking-summary-value" data-summary="therapist"><?= t('any_therapist','أي معالج متاح') ?></span>
                        </li>
                        <li>
                            <span class="booking-summary-label"><?= t('duration','المدة') ?></span>
                            <span class="booking-summary-value" data-summary="duration">—</span>
                        </li>
                        <li>
                            <span class="booking-summary-label"><?= t('appointment','الموعد') ?></span>
                            <span class="booking-summary-value" data-summary="when">—</span>
                        </li>
                    </ul>

                    <div class="booking-summary-total">
                        <span class="booking-summary-total-label"><?= t('estimated_total','التكلفة المتوقعة') ?></span>
                        <span class="booking-summary-total-value" data-summary="price">—</span>
                    </div>
                </div>

                <div class="booking-reassure">
                    <ul>
                        <li><i class="fa-solid fa-shield-heart"></i><div>
                            <strong><?= t('booking_re1','حجز بدون التزام') ?></strong>
                            <span><?= t('booking_re1_p','نؤكد الموعد قبل التثبيت.') ?></span>
                        </div></li>
                        <li><i class="fa-regular fa-clock"></i><div>
                            <strong><?= t('booking_re2','مرونة في التغيير') ?></strong>
                            <span><?= t('booking_re2_p','يمكن تعديل الموعد حتى ساعتين قبله.') ?></span>
                        </div></li>
                        <li><i class="fa-solid fa-user-doctor"></i><div>
                            <strong><?= t('booking_re3','مختصّون مرخّصون') ?></strong>
                            <span><?= t('booking_re3_p','فريق مدرّب وذو خبرة سريرية.') ?></span>
                        </div></li>
                    </ul>
                </div>

                <a href="<?= wa_link(site_setting('contact_phone','+962700000000')) ?>" target="_blank" rel="noopener" class="booking-wa">
                    <i class="fa-brands fa-whatsapp"></i>
                    <div>
                        <strong><?= t('book_via_wa','احجز عبر واتساب') ?></strong>
                        <span><?= t('book_via_wa_p','نرد خلال دقائق') ?></span>
                    </div>
                    <i class="fa-solid fa-arrow-<?= $arrow ?>"></i>
                </a>
            </aside>
        </div>
    </div>
</section>

<script>
(function(){
    const svcSel   = document.getElementById('svcSelect');
    const thSel    = document.getElementById('thSelect');
    const whenIn   = document.getElementById('whenInput');
    const availDiv = document.getElementById('availability');
    if (!svcSel) return;

    const sumService    = document.querySelector('[data-summary="service"]');
    const sumTherapist  = document.querySelector('[data-summary="therapist"]');
    const sumDuration   = document.querySelector('[data-summary="duration"]');
    const sumWhen       = document.querySelector('[data-summary="when"]');
    const sumPrice      = document.querySelector('[data-summary="price"]');

    const txt = {
        anyTherapist: <?= json_encode(t('any_therapist','أي معالج متاح')) ?>,
        min:          <?= json_encode(t('min','د')) ?>,
        currency:     <?= json_encode(t('currency','د.أ')) ?>,
        dash:         '—',
        availIdle:    <?= json_encode(t('avail_idle','اختر الخدمة والوقت لفحص التوفر…')) ?>,
        availChecking:<?= json_encode(t('avail_checking','جاري الفحص…')) ?>,
        availOk:      <?= json_encode(t('avail_ok','الوقت متاح')) ?>,
        availBad:     <?= json_encode(t('avail_bad','الوقت غير متاح')) ?>,
        availPickTime:<?= json_encode(t('avail_pick_time','اختر الوقت أولاً')) ?>,
        lang:         <?= json_encode($lang) ?>
    };

    function refreshSummary(){
        const opt = svcSel.options[svcSel.selectedIndex];
        if (svcSel.value && opt) {
            sumService.textContent  = opt.textContent.split(' · ')[0];
            sumDuration.textContent = (opt.dataset.duration || '') + ' ' + txt.min;
            const price = parseFloat(opt.dataset.price || 0);
            sumPrice.textContent    = price ? (price.toFixed(2) + ' ' + txt.currency) : txt.dash;
        } else {
            sumService.textContent  = txt.dash;
            sumDuration.textContent = txt.dash;
            sumPrice.textContent    = txt.dash;
        }
        const tOpt = thSel.options[thSel.selectedIndex];
        sumTherapist.textContent = (thSel.value && tOpt) ? tOpt.textContent : txt.anyTherapist;

        if (whenIn.value) {
            try {
                const d = new Date(whenIn.value);
                sumWhen.textContent = d.toLocaleString(txt.lang === 'ar' ? 'ar-EG' : 'en-GB', {
                    dateStyle: 'medium', timeStyle: 'short'
                });
            } catch(e) { sumWhen.textContent = whenIn.value; }
        } else {
            sumWhen.textContent = txt.dash;
        }
    }

    function setAvail(state, msg, icon){
        availDiv.className = 'booking-avail ' + state;
        availDiv.innerHTML = '<i class="' + icon + '"></i><span>' + msg + '</span>';
    }

    let to;
    function checkAvail(){
        if (!whenIn.value) {
            setAvail('idle', txt.availPickTime, 'fa-regular fa-circle-question');
            return;
        }
        const fd = new URLSearchParams();
        fd.set('start', whenIn.value);
        if (svcSel.value) fd.set('service_id', svcSel.value);
        if (thSel.value)  fd.set('therapist_id', thSel.value);

        setAvail('checking', txt.availChecking, 'fa-solid fa-spinner fa-spin');

        fetch('<?= $base_url ?>inc/check_availability.php?' + fd.toString())
            .then(r => r.json())
            .then(j => {
                if (j.available) setAvail('ok',  txt.availOk,  'fa-solid fa-circle-check');
                else             setAvail('bad', (j.reason || txt.availBad), 'fa-solid fa-circle-xmark');
            })
            .catch(() => setAvail('idle', txt.dash, 'fa-regular fa-circle'));
    }

    [svcSel, thSel, whenIn].forEach(el => {
        if (!el) return;
        el.addEventListener('change', () => {
            refreshSummary();
            clearTimeout(to);
            to = setTimeout(checkAvail, 300);
        });
    });

    refreshSummary();
})();
</script>
