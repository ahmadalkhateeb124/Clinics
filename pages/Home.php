<?php
$PageTitle = t('site_name') . ' — ' . t('home');
$escapedDescription = "عيادة لمسة نور — علاج طبيعي، تصريف سوائل، مساج، حجامة، تقشير، استشارات.";

global $pdo;

$sliders = $categories = $featuredServices = $activePackages = $latestPosts = $therapists = [];

if ($pdo instanceof PDO) {
    try {
        $sliders = $pdo->query("SELECT * FROM sliders WHERE deleted_at IS NULL AND is_active=1 ORDER BY sort_order LIMIT 5")->fetchAll();
        $categories = $pdo->query("SELECT * FROM service_categories WHERE deleted_at IS NULL AND is_active=1 ORDER BY sort_order LIMIT 6")->fetchAll();
        $featuredServices = $pdo->query("
            SELECT s.*, c.name_ar AS cat_ar, c.name_en AS cat_en, c.icon AS cat_icon
            FROM services s LEFT JOIN service_categories c ON c.id=s.category_id
            WHERE s.deleted_at IS NULL AND s.is_active=1
            ORDER BY s.sort_order LIMIT 6
        ")->fetchAll();
        $activePackages = $pdo->query("SELECT * FROM packages WHERE deleted_at IS NULL AND is_active=1 ORDER BY sort_order LIMIT 3")->fetchAll();
        $latestPosts = $pdo->query("SELECT * FROM blog_posts WHERE deleted_at IS NULL AND status='published' ORDER BY published_at DESC LIMIT 3")->fetchAll();
        $therapists = $pdo->query("
            SELECT first_name, first_name_en, last_name, last_name_en,
                   job_title, job_title_en, department, department_en, avatar
            FROM employees
            WHERE deleted_at IS NULL AND is_active = 1 AND show_on_site = 1
            ORDER BY id DESC LIMIT 4
        ")->fetchAll();
    } catch (Throwable $e) {
    }
}

$statClients = max(150, (int) ($pdo?->query("SELECT COUNT(*) FROM patients WHERE deleted_at IS NULL")->fetchColumn() ?? 0));
$statServices = max(8, (int) ($pdo?->query("SELECT COUNT(*) FROM services WHERE deleted_at IS NULL AND is_active=1")->fetchColumn() ?? 0));
$statYears = max(1, (int) date('Y') - 2018);

$slide = $sliders[0] ?? null;
$arrow = $dir === 'rtl' ? 'left' : 'right';

// Conditions we treat (language-aware)
$conditions = $lang === 'en' ? [
    'Back & neck pain',
    'Sports injuries',
    'Ankle sprain',
    'Joint stiffness',
    'Post-natal recovery',
    'Chronic headaches',
    'Disc herniation',
    'Facial palsy',
    'Stroke rehabilitation',
    'Spinal pain therapy',
    'Lymphatic drainage',
    'Muscle relaxation',
] : [
    'آلام الظهر والرقبة',
    'إصابات الرياضة',
    'التواء الكاحل',
    'تيبّس المفاصل',
    'علاج ما بعد الولادة',
    'الصداع المزمن',
    'انزلاق غضروفي',
    'شلل الوجه النصفي',
    'الجلطات وإعادة التأهيل',
    'علاج آلام العمود الفقري',
    'تصريف الوذمة اللمفاوية',
    'استرخاء العضلات',
];
?>

<!-- ════════ HERO CARD ════════ -->
<section class="hero">
    <div class="container">

        <div class="hero-card reveal">
            <div class="hero-inner">
                <!-- Content -->
                <div class="hero-content">
                    <span class="tag"><i
                            class="fa-solid fa-leaf"></i><?= t('hero_kicker', 'عيادة متعددة التخصصات · عمّان') ?></span>
                    <h1 class="hero-h1">
                        <?php $heroTitle = $slide ? tr($slide, 'title') : '';
                        if ($heroTitle): ?>
                            <?= e($heroTitle) ?>
                        <?php else: ?>
                            رعاية متخصّصة<br>تعيد لك <em>راحتك</em>.
                        <?php endif; ?>
                    </h1>
                    <p>
                        <?php $heroSub = $slide ? tr($slide, 'subtitle') : ''; ?>
                        <?= e($heroSub ?: t('about_lead')) ?>
                    </p>
                    <div class="hero-cta">
                        <a href="<?= $base_url ?>booking" class="btn btn-teal">
                            <i class="fa-regular fa-calendar-check"></i>
                            <?= t('book_now') ?>
                        </a>
                        <a href="<?= wa_link(site_setting('contact_phone', '')) ?>" target="_blank"
                            class="btn btn-outline">
                            <i class="fa-brands fa-whatsapp"></i>
                            <?= t('whatsapp_consult', 'استشارة سريعة') ?>
                        </a>
                    </div>

                    <div class="hero-trust">
                        <div class="hero-trust-icon"><i class="fa-solid fa-shield-halved"></i></div>
                        <div class="hero-trust-text">
                            <strong><?= t('moh_certified', 'عيادة مرخّصة من وزارة الصحة الأردنية') ?></strong>
                            <span><?= t('moh_certified_sub', 'فريق طبي معتمد · أعلى معايير السلامة') ?></span>
                        </div>
                    </div>
                </div>

                <!-- Figure -->
                <div>
                    <figure class="hero-figure">
                        <?php if ($slide && !empty($slide['image'])): ?>
                            <img src="<?= $base_url ?>uploads/<?= e($slide['image']) ?>" alt="">
                        <?php else: ?>
                            <img src="<?= $base_url ?>assets/img/back.webp" alt="" class="hero-figure-img">
                        <?php endif; ?>
                        <div class="hero-figure-stats">
                            <div class="hero-figure-stat">
                                <strong><?= $statYears ?>+</strong>
                                <span><?= t('years_experience', 'سنة خبرة') ?></span>
                            </div>
                            <div class="hero-figure-stat">
                                <strong><?= $statClients ?>+</strong>
                                <span><?= t('happy_clients', 'مراجع') ?></span>
                            </div>
                            <div class="hero-figure-stat">
                                <strong><?= $statServices ?></strong>
                                <span><?= t('services_label', 'تخصص') ?></span>
                            </div>
                        </div>
                    </figure>
                </div>
            </div>
        </div>

        <!-- Trust strip (under hero card) -->
        <div class="trust-strip reveal">
            <div class="trust-cell">
                <div class="trust-cell-icon"><i class="fa-regular fa-calendar-check"></i></div>
                <div>
                    <strong><?= t('online_booking', 'حجز إلكتروني') ?></strong>
                    <span><?= t('online_booking_sub', 'تأكيد خلال ساعة') ?></span>
                </div>
            </div>
            <div class="trust-cell">
                <div class="trust-cell-icon"><i class="fa-regular fa-clock"></i></div>
                <div>
                    <strong><?= t('open_daily', 'مفتوحون يومياً') ?></strong>
                    <span dir="ltr"><?= e(site_setting('working_hours_from', '09:00')) ?> –
                        <?= e(site_setting('working_hours_to', '21:00')) ?></span>
                </div>
            </div>
            <div class="trust-cell">
                <div class="trust-cell-icon"><i class="fa-solid fa-user-doctor"></i></div>
                <div>
                    <strong><?= t('certified_team', 'فريق معتمد') ?></strong>
                    <span><?= t('certified_team_sub', 'خبرات مرخّصة') ?></span>
                </div>
            </div>
            <div class="trust-cell">
                <div class="trust-cell-icon"><i class="fa-solid fa-handshake"></i></div>
                <div>
                    <strong><?= t('free_assess', 'تقييم مجاني') ?></strong>
                    <span><?= t('free_assess_sub', 'مع أول زيارة') ?></span>
                </div>
            </div>
        </div>

    </div>
</section>

<!-- ════════ CONDITIONS WE TREAT ════════ -->
<section class="section">
    <div class="container">
        <div class="conditions-block reveal">
            <div class="conditions-grid">
                <div>
                    <span class="tag warm"><i
                            class="fa-solid fa-circle-info"></i><?= t('what_we_treat', 'الحالات التي نُعالج') ?></span>
                    <h2 class="h-title" style="font-size:clamp(1.6rem,3vw,2.4rem);margin:1.25rem 0 1rem">
                        <?= t('conditions_h', 'إن كنت تعاني من واحدة من <em>هذه الحالات</em>، نحن هنا لك.') ?>
                    </h2>
                    <p class="h-lead" style="margin-bottom:1.5rem">
                        <?= t('conditions_sub', 'نُعالج تشكيلة واسعة من الحالات المزمنة والإصابات، بخطط علاجية مفصّلة على حسب التشخيص.') ?>
                    </p>
                    <a href="<?= $base_url ?>services" class="link-arrow">
                        <?= t('see_all', 'كل التخصصات') ?>
                        <i class="fa-solid fa-arrow-<?= $arrow ?>"></i>
                    </a>
                </div>
                <div class="conditions-cloud">
                    <?php foreach ($conditions as $cond): ?>
                        <a href="<?= $base_url ?>services" class="condition-pill">
                            <i class="fa-solid fa-check"></i><?= e($cond) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ════════ SERVICES (cards with icons) ════════ -->
<?php if ($featuredServices): ?>
    <section class="section" style="padding-top:0">
        <div class="container">
            <div class="heading-block reveal">
                <span class="tag"><i class="fa-solid fa-stethoscope"></i><?= t('our_services', 'خدماتنا') ?></span>
                <h2><?= t('home_services_h', 'علاجات مدروسة، <em>بأيدٍ خبيرة</em>.') ?></h2>
                <p><?= t('home_services_sub', 'كل جلسة تبدأ بفهم حالتك، وتنتهي بخطوة جديدة نحو التعافي.') ?></p>
            </div>

            <div class="svc-grid reveal">
                <?php foreach ($featuredServices as $s):
                    $icon = $s['cat_icon'] ?: 'fa-spa';
                    ?>
                    <a href="<?= $base_url ?>booking?service=<?= (int) $s['id'] ?>" class="svc-card">
                        <div class="svc-card-icon"><i class="fa-solid <?= e($icon) ?>"></i></div>
                        <h5><?= e(tr($s, 'name')) ?></h5>
                        <p>
                            <?= e(mb_strimwidth(tr($s, 'description'), 0, 130, '…')) ?>
                        </p>
                        <div class="svc-card-foot">
                            <div class="svc-card-meta">
                                <i class="fa-regular fa-clock" style="margin-inline-end:.3rem"></i>
                                <?= (int) $s['duration_minutes'] ?>         <?= t('min', 'دقيقة') ?>
                            </div>
                            <div class="svc-card-price"><?= format_money($s['price']) ?></div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<!-- ════════ HOW IT WORKS (process steps) ════════ -->
<section class="section" style="background:var(--c-paper);padding-block:var(--sec-py)">
    <div class="container">
        <div class="heading-block reveal" style="text-align:center;margin-inline:auto">
            <span class="tag"><i class="fa-solid fa-route"></i><?= t('how_it_works', 'كيف نعمل') ?></span>
            <h2><?= t('home_how_h', 'من <em>الحجز</em> إلى <em>التعافي</em> — أربع خطوات.') ?></h2>
            <p><?= t('home_how_sub', 'نتبع منهجاً واضحاً يبدأ بفهمك ويجعل كل جلسة خطوة فعلية للأمام.') ?></p>
        </div>

        <div class="process-grid reveal">
            <div class="process-step">
                <div class="process-step-num">1</div>
                <h5><?= t('step_1', 'احجز موعدك') ?></h5>
                <p><?= t('step_1_p', 'اختر التخصص والوقت الذي يناسبك، عبر الموقع أو الواتساب.') ?></p>
            </div>
            <div class="process-step">
                <div class="process-step-num">2</div>
                <h5><?= t('step_2', 'تقييم مجاني') ?></h5>
                <p><?= t('step_2_p', 'جلسة تقييم مع المعالج لفهم حالتك ووضع خطة مخصّصة لك.') ?></p>
            </div>
            <div class="process-step">
                <div class="process-step-num">3</div>
                <h5><?= t('step_3', 'الجلسات العلاجية') ?></h5>
                <p><?= t('step_3_p', 'نبدأ البرنامج بأحدث التقنيات في بيئة مريحة وخاصة.') ?></p>
            </div>
            <div class="process-step">
                <div class="process-step-num">4</div>
                <h5><?= t('step_4', 'المتابعة') ?></h5>
                <p><?= t('step_4_p', 'متابعة دورية وتعديل الخطة حتى تستعيد عافيتك بالكامل.') ?></p>
            </div>
        </div>
    </div>
</section>

<!-- ════════ WHY US ════════ -->
<section class="section">
    <div class="container">
        <div class="why-grid">
            <div class="reveal">
                <figure class="why-figure">
                    <img src="<?= $base_url ?>assets/img/WhyNoursTouch.avif" alt="" class="why-figure-img">
                </figure>
            </div>
            <div class="reveal">
                <span class="tag"><i class="fa-solid fa-award"></i><?= t('why_us', 'لماذا لمسة نور') ?></span>
                <h2 class="h-title" style="font-size:clamp(1.8rem,3.5vw,2.6rem);margin:1.25rem 0 1rem">
                    <?= t('why_h', 'أكثر من عيادة — <em>تجربة عناية كاملة</em>.') ?>
                </h2>
                <p class="h-lead">
                    <?= t('why_sub', 'نُقدّم لك تجربة فريدة، تجمع بين دقّة الطب والتخصصية، ودفء الاهتمام الإنساني.') ?>
                </p>

                <ul class="why-list">
                    <li>
                        <div class="why-list-icon"><i class="fa-solid fa-user-doctor"></i></div>
                        <div>
                            <h6><?= t('why_1_t', 'فريق متعدد التخصصات') ?></h6>
                            <p><?= t('why_1_p', 'معالجون فيزيائيون، أخصائيو تدليك، ومستشارون طبيون — يعملون معاً لأجلك.') ?>
                            </p>
                        </div>
                    </li>
                    <li>
                        <div class="why-list-icon"><i class="fa-solid fa-heart-pulse"></i></div>
                        <div>
                            <h6><?= t('why_2_t', 'خطة علاج مخصّصة') ?></h6>
                            <p><?= t('why_2_p', 'كل خطّة مبنيّة على تشخيصك أنت، لا قوالب جاهزة.') ?></p>
                        </div>
                    </li>
                    <li>
                        <div class="why-list-icon"><i class="fa-solid fa-shield-halved"></i></div>
                        <div>
                            <h6><?= t('why_3_t', 'بيئة آمنة وخاصة') ?></h6>
                            <p><?= t('why_3_p', 'غرف خاصة، تعقيم صارم، وحرص كامل على راحتك وخصوصيتك.') ?></p>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</section>

<!-- ════════ TEAM ════════ -->
<?php if ($therapists): ?>
    <section class="section" style="background:var(--c-paper)">
        <div class="container">
            <div class="heading-block reveal" style="text-align:center;margin-inline:auto">
                <span class="tag"><i class="fa-solid fa-users"></i><?= t('our_team', 'فريقنا') ?></span>
                <h2><?= t('home_team_h', 'تعرّف على <em>أيدينا الخبيرة</em>.') ?></h2>
                <p><?= t('home_team_sub', 'معالجون من كبار الكفاءات في الأردن.') ?></p>
            </div>

            <div class="team-grid reveal" data-count="<?= min(4, count($therapists)) ?>">
                <?php foreach ($therapists as $tp):
                    $name = trim(tr($tp, 'first_name') . ' ' . tr($tp, 'last_name'));
                    $role = tr($tp, 'job_title') ?: tr($tp, 'department'); ?>
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
        </div>
    </section>
<?php endif; ?>

<!-- ════════ PACKAGES — featured + compact list ════════ -->
<?php if ($activePackages):
    // Pick "featured" (most_sessions) and the rest
    usort($activePackages, fn($a, $b) => ((int) $b['total_sessions']) <=> ((int) $a['total_sessions']));
    $feat = $activePackages[0];
    $others = array_slice($activePackages, 1, 4);
    $perSession = $feat['total_sessions'] > 0 ? round((float) $feat['price'] / (int) $feat['total_sessions']) : 0;
    ?>
    <section class="section">
        <div class="container">
            <div class="heading-block reveal" style="text-align:center;margin-inline:auto">
                <span class="tag"><i class="fa-solid fa-box-archive"></i><?= t('packages', 'الباقات') ?></span>
                <h2><?= t('home_pkg_h_2', 'باقات تجمع بين <em>التوفير</em> و<em>الاستمرارية</em>.') ?></h2>
                <p><?= t('home_pkg_sub', 'لخطط العلاج المستمرّة والعناية الدورية — بأسعار أفضل من الجلسة الواحدة.') ?></p>
            </div>

            <div class="pkg-showcase reveal">
                <!-- Featured (big card, left in LTR) -->
                <div class="pkg-feat-big">
                    <div class="pkg-feat-ribbon">
                        <i class="fa-solid fa-star"></i>
                        <?= t('best_value', 'الأفضل قيمة') ?>
                    </div>

                    <div class="pkg-feat-head">
                        <div class="pkg-feat-icon"><i class="fa-solid fa-box-open"></i></div>
                        <div>
                            <div class="pkg-feat-eyebrow"><?= t('signature_package', 'باقة مميّزة') ?></div>
                            <h3 class="pkg-feat-name"><?= e(tr($feat, 'name')) ?></h3>
                        </div>
                    </div>

                    <div class="pkg-feat-price-row">
                        <div class="pkg-feat-price">
                            <span class="num"><?= number_format((float) $feat['price'], 0) ?></span>
                            <span class="cur"><?= site_setting('currency', 'JOD') ?></span>
                        </div>
                        <?php if ($perSession): ?>
                            <div class="pkg-feat-persess">
                                <span><?= t('per_session', 'للجلسة الواحدة') ?></span>
                                <strong dir="ltr"><?= number_format($perSession, 0) ?>
                                    <?= site_setting('currency', 'JOD') ?></strong>
                            </div>
                        <?php endif; ?>
                    </div>

                    <ul class="pkg-feat-features">
                        <li>
                            <div class="ff-num"><?= (int) $feat['total_sessions'] ?></div>
                            <div>
                                <strong><?= t('sessions_count', 'جلسة كاملة') ?></strong>
                                <span><?= t('flexible_book', 'قابلة للحجز في أي وقت') ?></span>
                            </div>
                        </li>
                        <li>
                            <div class="ff-num"><?= (int) $feat['validity_days'] ?></div>
                            <div>
                                <strong><?= t('valid_days_label', 'يوم صلاحية') ?></strong>
                                <span><?= t('use_within', 'استخدمها على راحتك') ?></span>
                            </div>
                        </li>
                    </ul>

                    <div class="pkg-feat-perks">
                        <span><i class="fa-solid fa-circle-check"></i><?= t('priority_booking', 'حجز ذو أولوية') ?></span>
                        <span><i class="fa-solid fa-circle-check"></i><?= t('free_consultation', 'استشارة مجانية') ?></span>
                        <span><i class="fa-solid fa-circle-check"></i><?= t('progress_report', 'تقرير متابعة') ?></span>
                    </div>

                    <a href="<?= $base_url ?>booking?package=<?= (int) $feat['id'] ?>" class="btn btn-light pkg-feat-cta">
                        <?= t('choose_this_package', 'اختر هذه الباقة') ?>
                        <i class="fa-solid fa-arrow-<?= $arrow ?>"></i>
                    </a>
                </div>

                <!-- Other packages (compact rows on right) -->
                <?php if ($others): ?>
                    <div class="pkg-others">
                        <div class="pkg-others-head">
                            <span class="tag outline"><i
                                    class="fa-solid fa-layer-group"></i><?= t('more_options', 'خيارات أخرى') ?></span>
                        </div>

                        <?php foreach ($others as $p):
                            $per = $p['total_sessions'] > 0 ? round((float) $p['price'] / (int) $p['total_sessions']) : 0;
                            ?>
                            <a class="pkg-mini" href="<?= $base_url ?>booking?package=<?= (int) $p['id'] ?>">
                                <div class="pkg-mini-icon"><i class="fa-solid fa-box-open"></i></div>
                                <div class="pkg-mini-body">
                                    <div class="pkg-mini-name"><?= e(tr($p, 'name')) ?></div>
                                    <div class="pkg-mini-meta">
                                        <span><i class="fa-solid fa-list-check"></i><?= (int) $p['total_sessions'] ?>
                                            <?= t('sessions', 'جلسة') ?></span>
                                        <span><i class="fa-regular fa-calendar"></i><?= (int) $p['validity_days'] ?>
                                            <?= t('day', 'يوم') ?></span>
                                        <?php if ($per): ?>
                                            <span class="pkg-mini-per" dir="ltr"><?= number_format($per, 0) ?>
                                                <?= site_setting('currency', 'JOD') ?>/<?= t('sess', 'جلسة') ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="pkg-mini-price">
                                    <span class="num"><?= number_format((float) $p['price'], 0) ?></span>
                                    <span class="cur"><?= site_setting('currency', 'JOD') ?></span>
                                </div>
                                <div class="pkg-mini-arrow"><i class="fa-solid fa-arrow-<?= $arrow ?>"></i></div>
                            </a>
                        <?php endforeach; ?>

                        <!-- Trust line under packages list -->
                        <div class="pkg-others-foot">
                            <i class="fa-solid fa-shield-halved"></i>
                            <span><?= t('pkg_guarantee', 'استرداد المبلغ المتبقي خلال 14 يوم لأي باقة لم تُستخدم.') ?></span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<!-- ════════ JOURNAL ════════ -->
<?php if ($latestPosts): ?>
    <section class="section" style="background:var(--c-paper)">
        <div class="container">
            <div class="heading-block reveal">
                <span class="tag"><i class="fa-regular fa-newspaper"></i><?= t('journal', 'المدوّنة') ?></span>
                <h2><?= t('home_journal_h', 'نصائح وقراءات من <em>فريقنا</em>.') ?></h2>
                <p><?= t('home_journal_sub', 'مقالات حول الصحة، العلاج، والعناية اليومية.') ?></p>
            </div>

            <div class="journal-grid reveal">
                <?php foreach ($latestPosts as $p): ?>
                    <a class="journal-card" href="<?= $base_url ?>blog-details?slug=<?= e($p['slug']) ?>">
                        <div class="journal-card-img">
                            <?php if (!empty($p['featured_image'])): ?>
                                <img src="<?= $base_url ?>uploads/<?= e($p['featured_image']) ?>" alt="">
                            <?php else: ?>
                                <div class="journal-card-img-fallback"><i class="fa-regular fa-newspaper"></i></div>
                            <?php endif; ?>
                        </div>
                        <div class="journal-card-body">
                            <?php if ($p['published_at']): ?>
                                <div class="journal-card-meta"><?= format_date($p['published_at'], 'F Y') ?></div>
                            <?php endif; ?>
                            <h6><?= e(tr($p, 'title')) ?></h6>
                            <p><?= e(mb_strimwidth(strip_tags(tr($p, 'excerpt') ?: tr($p, 'content')), 0, 110, '…')) ?></p>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<!-- ════════ VISIT US (booking CTA) ════════ -->
<section class="section">
    <div class="container">
        <div class="visit-card reveal">
            <div class="visit-body">
                <span class="tag"><i class="fa-solid fa-location-dot"></i><?= t('visit', 'زرنا') ?></span>
                <h2><?= t('visit_h', 'كل ما تحتاجه <em>تحت سقف واحد</em>.') ?></h2>
                <p><?= t('visit_p', 'احجز موعدك أو زرنا في عيادتنا. فريقنا في الاستقبال جاهز لمساعدتك.') ?></p>

                <ul class="visit-info">
                    <li>
                        <i class="fa-solid fa-location-dot"></i>
                        <div><?= e(site_setting('address', 'عمّان، الأردن')) ?></div>
                    </li>
                    <li>
                        <i class="fa-solid fa-phone"></i>
                        <div dir="ltr"><a href="<?= tel_link(site_setting('contact_phone', '+962700000000')) ?>"
                                style="color:#fff"><?= e(site_setting('contact_phone', '+962 7 0000 0000')) ?></a></div>
                    </li>
                    <li>
                        <i class="fa-regular fa-envelope"></i>
                        <div dir="ltr"><a href="mailto:<?= e(site_setting('contact_email', 'info@nourstouch.com')) ?>"
                                style="color:#fff"><?= e(site_setting('contact_email', 'info@nourstouch.com')) ?></a>
                        </div>
                    </li>
                    <li>
                        <i class="fa-regular fa-clock"></i>
                        <div><?= t('open_daily', 'مفتوحون يومياً') ?> · <span
                                dir="ltr"><?= e(site_setting('working_hours_from', '09:00')) ?> –
                                <?= e(site_setting('working_hours_to', '21:00')) ?></span></div>
                    </li>
                </ul>
            </div>

            <div class="visit-aside">
                <div class="visit-aside-eyebrow"><?= t('start_today', 'ابدأ اليوم') ?></div>
                <h3><?= t('book_session_now', 'احجز جلستك<br>خلال دقيقة.') ?></h3>
                <a href="<?= $base_url ?>booking" class="btn btn-light" style="margin-bottom:.65rem">
                    <i class="fa-regular fa-calendar-check"></i>
                    <?= t('book_now') ?>
                </a>
                <a href="<?= wa_link(site_setting('contact_phone', '')) ?>" target="_blank" class="btn btn-outline"
                    style="border-color:rgba(255,255,255,.4);color:#fff;background:transparent">
                    <i class="fa-brands fa-whatsapp"></i>
                    <?= t('whatsapp_us', 'واتساب') ?>
                </a>
            </div>
        </div>
    </div>
</section>