<?php
$PageTitle = t('about_title','من نحن') . ' — ' . t('site_name');
$escapedDescription = "تعرّف على عيادة لمسة نور — رؤيتنا، رسالتنا، وفريقنا من المعالجين المعتمدين.";

global $pdo;
$faqs = $therapists = [];
$statClients = $statSessions = $statServices = $statYears = 0;

if ($pdo instanceof PDO) {
    try {
        // Only show FAQs that have content for the current language
        if ($lang === 'en') {
            $faqs = $pdo->query("
                SELECT * FROM faqs
                WHERE deleted_at IS NULL AND is_active=1
                  AND question_en IS NOT NULL AND question_en <> ''
                  AND answer_en   IS NOT NULL AND answer_en   <> ''
                ORDER BY sort_order LIMIT 8
            ")->fetchAll();
        } else {
            $faqs = $pdo->query("SELECT * FROM faqs WHERE deleted_at IS NULL AND is_active=1 ORDER BY sort_order LIMIT 8")->fetchAll();
        }
        $therapists = $pdo->query("
            SELECT first_name, first_name_en, last_name, last_name_en,
                   job_title, job_title_en, department, department_en, avatar
            FROM employees
            WHERE deleted_at IS NULL AND is_active = 1 AND show_on_site = 1
            ORDER BY id DESC LIMIT 4
        ")->fetchAll();

        $statClients  = max(150, (int)$pdo->query("SELECT COUNT(*) FROM patients WHERE deleted_at IS NULL")->fetchColumn());
        $statSessions = max(500, (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE deleted_at IS NULL AND status='completed'")->fetchColumn());
        $statServices = max(8,   (int)$pdo->query("SELECT COUNT(*) FROM services WHERE deleted_at IS NULL AND is_active=1")->fetchColumn());
    } catch (Throwable $e) {}
}
$statYears = max(1, (int)date('Y') - 2018);
$arrow = $dir === 'rtl' ? 'left' : 'right';
?>

<!-- ════════ PAGE HERO ════════ -->
<section class="hero" style="padding-bottom:2.5rem">
    <div class="container">
        <div class="page-hero reveal">
            <div class="page-hero-content">
                <span class="tag"><i class="fa-solid fa-spa"></i><?= t('our_story_kicker', 'قصتنا · رؤيتنا') ?></span>
                <h1 class="hero-h1" style="font-size:clamp(2.2rem,4.5vw,3.8rem);margin:1.25rem 0 1.25rem">
                    <?= t('about_h', 'لمسة <em>تُحدِث الفرق</em>،<br>عناية تستحقها.') ?>
                </h1>
                <p class="h-lead" style="margin-bottom:0;max-width:62ch">
                    <?= t('about_lead', 'في لمسة نور، نؤمن أن الشفاء ليس عمليّة طبية فقط، بل تجربة إنسانية كاملة. منذ تأسيسنا ونحن نمزج بين العلم والاهتمام، لنقدّم لكل مريض رعاية تشبهه.') ?>
                </p>
            </div>
            <ul class="page-hero-crumbs">
                <li><a href="<?= $base_url ?>"><?= t('home') ?></a></li>
                <li>·</li>
                <li><?= t('about') ?></li>
            </ul>
        </div>
    </div>
</section>

<!-- ════════ STATS STRIP ════════ -->
<section class="section" style="padding-top:0">
    <div class="container">
        <div class="stat-strip reveal">
            <div class="stat-cell">
                <div class="stat-num"><?= $statYears ?>+</div>
                <div class="stat-label"><?= t('years_practice', 'سنوات من الممارسة') ?></div>
            </div>
            <div class="stat-cell">
                <div class="stat-num"><?= $statClients ?>+</div>
                <div class="stat-label"><?= t('patients_treated', 'مريض اعتمد علينا') ?></div>
            </div>
            <div class="stat-cell">
                <div class="stat-num"><?= $statSessions ?>+</div>
                <div class="stat-label"><?= t('sessions_completed', 'جلسة مكتملة') ?></div>
            </div>
            <div class="stat-cell">
                <div class="stat-num"><?= $statServices ?></div>
                <div class="stat-label"><?= t('specialties', 'تخصصاً علاجياً') ?></div>
            </div>
        </div>
    </div>
</section>

<!-- ════════ STORY / PHILOSOPHY ════════ -->
<section class="section" style="padding-top:0">
    <div class="container">
        <div class="why-grid reveal">
            <div>
                <span class="tag warm"><i class="fa-solid fa-quote-left"></i><?= t('our_philosophy', 'فلسفتنا') ?></span>
                <h2 class="h-title" style="font-size:clamp(1.8rem,3.5vw,2.6rem);margin:1.25rem 0 1.25rem">
                    <?= t('phil_h', 'نُؤمن أن الشفاء يبدأ <em>بالإصغاء</em>.') ?>
                </h2>
                <p class="h-lead" style="margin-bottom:1.25rem">
                    <?= t('phil_p1', 'كل مريض يحمل قصة، وكل جسد له إيقاع مختلف. نقضي مع كل عميل وقتاً لفهم حالته جيداً قبل اقتراح أي بروتوكول علاجي.') ?>
                </p>
                <p class="h-lead">
                    <?= t('phil_p2', 'نجمع بين أحدث ما توصّل إليه الطب التأهيلي وأساليب العلاج التقليدية، في بيئة هادئة، خاصّة، تشبه راحة المنزل.') ?>
                </p>
                <div class="signature-line">
                    <div class="signature-line-mark">— <?= t('founders', 'فريق التأسيس') ?></div>
                </div>
            </div>
            <div>
                <figure class="why-figure">
                    <img src="<?= $base_url ?>assets/img/WhyNoursTouch.avif" alt="" class="why-figure-img">
                </figure>
            </div>
        </div>
    </div>
</section>

<!-- ════════ VALUES (3 cards) ════════ -->
<section class="section" style="background:var(--c-paper);padding-block:var(--sec-py)">
    <div class="container">
        <div class="heading-block reveal" style="text-align:center;margin-inline:auto">
            <span class="tag"><i class="fa-solid fa-compass"></i><?= t('our_values', 'قيمنا') ?></span>
            <h2><?= t('values_h', 'ما يُوجّه كل <em>جلسة</em> نقدّمها.') ?></h2>
            <p><?= t('values_sub', 'أربع قيم تختصر هويّتنا — ندافع عنها في كل تفصيل، ومن أول دقيقة حضورك.') ?></p>
        </div>

        <div class="values-grid reveal">
            <div class="value-card">
                <div class="value-icon"><i class="fa-solid fa-user-doctor"></i></div>
                <h5><?= t('value_1_h', 'احترافية معتمدة') ?></h5>
                <p><?= t('value_1_p', 'فريقنا من معالجين فيزيائيين، أخصائيي تدليك، ومستشارين طبيين — كلّهم حاصلون على ترخيص رسمي وخبرة طويلة.') ?></p>
            </div>
            <div class="value-card">
                <div class="value-icon"><i class="fa-solid fa-hand-holding-heart"></i></div>
                <h5><?= t('value_2_h', 'اهتمام صادق') ?></h5>
                <p><?= t('value_2_p', 'نفهم أن العلاج لا يكتمل بالأدوات وحدها — نُصغي إليك، نُجيب على أسئلتك، ونرافقك في كل خطوة.') ?></p>
            </div>
            <div class="value-card">
                <div class="value-icon"><i class="fa-solid fa-shield-halved"></i></div>
                <h5><?= t('value_3_h', 'سلامة وخصوصية') ?></h5>
                <p><?= t('value_3_p', 'بروتوكولات تعقيم صارمة، غرف خاصّة لكل عميل، وحرص كامل على سرية معلوماتك ومعطياتك الطبية.') ?></p>
            </div>
            <div class="value-card">
                <div class="value-icon"><i class="fa-solid fa-seedling"></i></div>
                <h5><?= t('value_4_h', 'تطوّر مستمر') ?></h5>
                <p><?= t('value_4_p', 'نتابع آخر الأبحاث ونتدرّب باستمرار، لأن ما يُعتبر "أحدث" اليوم قد يصبح غداً تقليدياً.') ?></p>
            </div>
        </div>
    </div>
</section>

<!-- ════════ TEAM PREVIEW ════════ -->
<?php if ($therapists): ?>
<section class="section">
    <div class="container">
        <div class="heading-block reveal">
            <span class="tag"><i class="fa-solid fa-users"></i><?= t('our_team', 'الفريق') ?></span>
            <h2><?= t('about_team_h', 'وجوه ستلتقيها في <em>زيارتك</em>.') ?></h2>
            <p><?= t('about_team_sub', 'مختصّون يعملون بانسجام لتقديم تجربة علاج متكاملة.') ?></p>
        </div>

        <div class="team-grid reveal">
            <?php foreach ($therapists as $tp):
                $name = trim(tr($tp,'first_name').' '.tr($tp,'last_name'));
                $role = tr($tp,'job_title') ?: tr($tp,'department'); ?>
                <a href="<?= $base_url ?>therapists" class="clinician">
                    <div class="clinician-photo">
                        <?php if (!empty($tp['avatar'])): ?>
                            <img src="<?= $base_url ?>uploads/<?= e($tp['avatar']) ?>" alt="<?= e($name) ?>">
                        <?php else: ?>
                            <div class="clinician-photo-fallback"><i class="fa-solid fa-user-doctor"></i></div>
                        <?php endif; ?>
                    </div>
                    <div class="clinician-name"><?= e($name) ?: '—' ?></div>
                    <div class="clinician-role"><?= e($role) ?></div>
                    <div class="clinician-badge"><i class="fa-solid fa-check"></i><?= t('certified', 'معتمد') ?></div>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="text-center mt-4 reveal">
            <a href="<?= $base_url ?>therapists" class="link-arrow">
                <?= t('meet_the_team', 'الفريق كاملاً') ?>
                <i class="fa-solid fa-arrow-<?= $arrow ?>"></i>
            </a>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ════════ FAQ ════════ -->
<?php if ($faqs): ?>
<section class="section" style="background:var(--c-paper)">
    <div class="container">
        <div class="faq-wrap reveal">
            <div class="faq-side">
                <span class="tag"><i class="fa-solid fa-circle-question"></i><?= t('faqs', 'الأسئلة الشائعة') ?></span>
                <h2 class="h-title" style="font-size:clamp(1.7rem,3vw,2.4rem);margin:1rem 0 1.25rem">
                    <?= t('faq_h', 'إجابات سريعة على <em>أكثر</em> الأسئلة شيوعاً.') ?>
                </h2>
                <p class="h-lead" style="margin-bottom:1.5rem">
                    <?= t('faq_sub', 'لم تجد ما تبحث عنه؟ تواصل معنا مباشرة — جاهزون لمساعدتك.') ?>
                </p>
                <a href="<?= wa_link(site_setting('contact_phone','')) ?>" target="_blank" class="btn btn-teal">
                    <i class="fa-brands fa-whatsapp"></i>
                    <?= t('whatsapp_us', 'تواصل واتساب') ?>
                </a>
            </div>
            <div class="faq-list">
                <?php foreach ($faqs as $i => $f): ?>
                    <details class="faq-item" <?= $i===0?'open':'' ?>>
                        <summary>
                            <span><?= e(tr($f, 'question')) ?></span>
                            <i class="fa-solid fa-plus faq-icon"></i>
                        </summary>
                        <div class="faq-body"><?= nl2br(e(tr($f, 'answer'))) ?></div>
                    </details>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ════════ VISIT CTA ════════ -->
<section class="section">
    <div class="container">
        <div class="visit-card reveal">
            <div class="visit-body">
                <span class="tag"><i class="fa-solid fa-location-dot"></i><?= t('visit', 'زرنا') ?></span>
                <h2><?= t('about_visit_h', 'نتطلع <em>للقائك</em>.') ?></h2>
                <p><?= t('about_visit_p', 'احجز موعدك أو زرنا في عيادتنا — نحن في انتظارك.') ?></p>

                <ul class="visit-info">
                    <li><i class="fa-solid fa-location-dot"></i><div><?= e(site_setting('address', 'عمّان، الأردن')) ?></div></li>
                    <li><i class="fa-solid fa-phone"></i><div dir="ltr"><a href="<?= tel_link(site_setting('contact_phone','+962700000000')) ?>" style="color:#fff"><?= e(site_setting('contact_phone', '+962 7 0000 0000')) ?></a></div></li>
                    <li><i class="fa-regular fa-clock"></i><div><span dir="ltr"><?= e(site_setting('working_hours_from','09:00')) ?> – <?= e(site_setting('working_hours_to','21:00')) ?></span></div></li>
                </ul>
            </div>
            <div class="visit-aside">
                <div class="visit-aside-eyebrow"><?= t('start_today', 'ابدأ اليوم') ?></div>
                <h3><?= t('book_session_now', 'احجز جلستك<br>خلال دقيقة.') ?></h3>
                <a href="<?= $base_url ?>booking" class="btn btn-light">
                    <i class="fa-regular fa-calendar-check"></i>
                    <?= t('book_now') ?>
                </a>
            </div>
        </div>
    </div>
</section>
