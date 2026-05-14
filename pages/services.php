<?php
$PageTitle = t('services') . ' — ' . t('site_name');
$escapedDescription = "تعرّف على جميع خدمات عيادة لمسة نور — علاج طبيعي، تصريف سوائل، مساج، حجامة، تقشير، استشارات طبية.";

global $pdo;
$catFilter  = (int)($_GET['cat'] ?? 0);
$categories = [];
$services   = [];

if ($pdo instanceof PDO) {
    try {
        $categories = $pdo->query("
            SELECT c.*, COUNT(s.id) AS svc_count
            FROM service_categories c
            LEFT JOIN services s ON s.category_id = c.id AND s.deleted_at IS NULL AND s.is_active=1
            WHERE c.deleted_at IS NULL AND c.is_active=1
            GROUP BY c.id
            ORDER BY c.sort_order
        ")->fetchAll();

        $sql = "
            SELECT s.*, c.name_ar AS cat_name_ar, c.name_en AS cat_name_en, c.icon AS cat_icon
            FROM services s
            LEFT JOIN service_categories c ON c.id = s.category_id
            WHERE s.deleted_at IS NULL AND s.is_active=1
        ";
        $params = [];
        if ($catFilter > 0) { $sql .= " AND s.category_id = ?"; $params[] = $catFilter; }
        $sql .= " ORDER BY c.sort_order, s.sort_order, s.name_ar";
        $st = $pdo->prepare($sql); $st->execute($params);
        $services = $st->fetchAll();
    } catch (Throwable $e) {}
}

$activeCat = null;
if ($catFilter > 0) {
    foreach ($categories as $c) if ((int)$c['id'] === $catFilter) { $activeCat = $c; break; }
}
$arrow = $dir === 'rtl' ? 'left' : 'right';
$totalServices = array_sum(array_map(fn($c) => (int)$c['svc_count'], $categories));
?>

<!-- ════════ PAGE HERO ════════ -->
<section class="hero" style="padding-bottom:2rem">
    <div class="container">
        <div class="page-hero reveal">
            <div class="page-hero-content">
                <span class="tag"><i class="fa-solid fa-stethoscope"></i><?= t('our_services','خدماتنا') ?></span>
                <h1 class="hero-h1" style="font-size:clamp(2.2rem,4.5vw,3.8rem);margin:1.25rem 0 1.25rem">
                    <?php if ($activeCat): ?>
                        <?= e(tr($activeCat, 'name')) ?>
                    <?php else: ?>
                        <?= t('services_h', 'علاجات بأيدٍ <em>خبيرة</em>،<br>تستحقّ التجربة.') ?>
                    <?php endif; ?>
                </h1>
                <p class="h-lead" style="margin-bottom:0;max-width:62ch">
                    <?php if ($activeCat && !empty(tr($activeCat,'description'))): ?>
                        <?= e(tr($activeCat, 'description')) ?>
                    <?php else: ?>
                        <?= t('services_lead', 'باقة متكاملة من الجلسات العلاجية والتجميلية. كل خدمة مفصّلة بأسلوب يناسب حالتك، ضمن بيئة هادئة وفريق محترف.') ?>
                    <?php endif; ?>
                </p>
            </div>
            <ul class="page-hero-crumbs">
                <li><a href="<?= $base_url ?>"><?= t('home') ?></a></li>
                <li>·</li>
                <li><a href="<?= $base_url ?>services"><?= t('services') ?></a></li>
                <?php if ($activeCat): ?>
                    <li>·</li>
                    <li><?= e(tr($activeCat, 'name')) ?></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</section>

<!-- ════════ CATEGORY FILTER ════════ -->
<?php if ($categories): ?>
<section class="section tight" style="padding:0">
    <div class="container">
        <div class="cat-filter reveal">
            <div class="cat-filter-scroll">
                <a href="<?= $base_url ?>services" class="cat-chip <?= $catFilter===0?'is-active':'' ?>">
                    <span class="cat-chip-label"><?= t('all','الكل') ?></span>
                    <span class="cat-chip-count"><?= $totalServices ?></span>
                </a>
                <?php foreach ($categories as $c): if (!$c['svc_count']) continue; ?>
                    <a href="<?= $base_url ?>services?cat=<?= (int)$c['id'] ?>" class="cat-chip <?= $catFilter===(int)$c['id']?'is-active':'' ?>">
                        <?php if (!empty($c['icon'])): ?>
                            <i class="fa-solid <?= e($c['icon']) ?> cat-chip-icon"></i>
                        <?php endif; ?>
                        <span class="cat-chip-label"><?= e(tr($c,'name')) ?></span>
                        <span class="cat-chip-count"><?= (int)$c['svc_count'] ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ════════ SERVICES GRID ════════ -->
<section class="section">
    <div class="container">
        <?php if ($services): ?>
            <div class="services-stats reveal">
                <div>
                    <span class="services-stats-num"><?= count($services) ?></span>
                    <span class="services-stats-label">
                        <?= $catFilter
                            ? t('services_in_category', 'خدمة في هذا التخصص')
                            : t('total_services', 'خدمة متاحة الآن') ?>
                    </span>
                </div>
                <div class="services-stats-divider"></div>
                <div class="services-stats-side">
                    <i class="fa-solid fa-shield-halved"></i>
                    <?= t('services_certified_line', 'جميع الجلسات تُقدَّم بأيدي معالجين معتمدين') ?>
                </div>
            </div>

            <div class="svc-grid reveal">
                <?php foreach ($services as $s):
                    $icon = $s['cat_icon'] ?: 'fa-spa';
                    $svcName = tr($s, 'name');
                    $svcDesc = tr($s, 'description');
                    $catName = tr(['name_ar'=>$s['cat_name_ar']??'','name_en'=>$s['cat_name_en']??''], 'name');
                ?>
                    <a href="<?= $base_url ?>booking?service=<?= (int)$s['id'] ?>" class="svc-card">
                        <div class="svc-card-icon"><i class="fa-solid <?= e($icon) ?>"></i></div>
                        <?php if ($catName): ?>
                            <div class="svc-card-cat"><?= e($catName) ?></div>
                        <?php endif; ?>
                        <h5><?= e($svcName) ?></h5>
                        <?php if ($svcDesc): ?>
                            <p><?= e(mb_strimwidth($svcDesc, 0, 150, '…')) ?></p>
                        <?php endif; ?>
                        <div class="svc-card-foot">
                            <div class="svc-card-meta">
                                <i class="fa-regular fa-clock" style="margin-inline-end:.3rem"></i>
                                <?= (int)$s['duration_minutes'] ?> <?= t('min','دقيقة') ?>
                            </div>
                            <div class="svc-card-price"><?= format_money($s['price']) ?></div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state reveal">
                <div class="empty-state-icon"><i class="fa-regular fa-folder-open"></i></div>
                <h3><?= t('no_services_yet','لا توجد خدمات حالياً.') ?></h3>
                <p><?= t('check_back', 'تصفّح كل الخدمات أو تواصل معنا للاستفسار.') ?></p>
                <a href="<?= $base_url ?>services" class="btn btn-teal"><?= t('see_all','كل التخصصات') ?></a>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- ════════ VISIT CTA ════════ -->
<section class="section" style="padding-top:0">
    <div class="container">
        <div class="visit-card reveal">
            <div class="visit-body">
                <span class="tag"><i class="fa-solid fa-circle-question"></i><?= t('not_sure','غير متأكد ما يناسبك؟') ?></span>
                <h2><?= t('services_cta_h', 'دعنا نساعدك في <em>الاختيار</em>.') ?></h2>
                <p><?= t('services_cta_p', 'احجز تقييماً مجانياً مع مختصّينا — نُحدّد معك أنسب جلسة أو باقة بحسب حالتك.') ?></p>

                <ul class="visit-info">
                    <li><i class="fa-solid fa-phone"></i><div dir="ltr"><a href="<?= tel_link(site_setting('contact_phone','+962700000000')) ?>" style="color:#fff"><?= e(site_setting('contact_phone', '+962 7 0000 0000')) ?></a></div></li>
                    <li><i class="fa-regular fa-envelope"></i><div dir="ltr"><a href="mailto:<?= e(site_setting('contact_email','info@nourstouch.com')) ?>" style="color:#fff"><?= e(site_setting('contact_email','info@nourstouch.com')) ?></a></div></li>
                    <li><i class="fa-regular fa-clock"></i><div><span dir="ltr"><?= e(site_setting('working_hours_from','09:00')) ?> – <?= e(site_setting('working_hours_to','21:00')) ?></span></div></li>
                </ul>
            </div>
            <div class="visit-aside">
                <div class="visit-aside-eyebrow"><?= t('free_assessment','تقييم مجاني') ?></div>
                <h3><?= t('book_assessment_h', 'احجز تقييمك<br>المجاني الآن.') ?></h3>
                <a href="<?= $base_url ?>booking" class="btn btn-light">
                    <i class="fa-regular fa-calendar-check"></i>
                    <?= t('book_now') ?>
                </a>
                <a href="<?= wa_link(site_setting('contact_phone','')) ?>" target="_blank" class="btn btn-outline" style="border-color:rgba(255,255,255,.4);color:#fff;background:transparent;margin-top:.5rem">
                    <i class="fa-brands fa-whatsapp"></i>
                    <?= t('whatsapp_us','واتساب') ?>
                </a>
            </div>
        </div>
    </div>
</section>
