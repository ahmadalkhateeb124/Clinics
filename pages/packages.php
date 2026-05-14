<?php
$PageTitle = t('packages') . ' — ' . t('site_name');
$escapedDescription = "باقات الجلسات في عيادة لمسة نور — وفّر أكثر مع باقاتنا المرنة.";

global $pdo;
$packages = [];
if ($pdo instanceof PDO) {
    try {
        $packages = $pdo->query("
            SELECT p.*, COUNT(ps.service_id) AS svc_count
            FROM packages p
            LEFT JOIN package_services ps ON ps.package_id = p.id
            WHERE p.deleted_at IS NULL AND p.is_active = 1
            GROUP BY p.id
            ORDER BY p.total_sessions DESC, p.price ASC
        ")->fetchAll();
    } catch (Throwable $e) {}
}

// Featured = biggest package (most sessions); rest = others
$feat   = $packages[0] ?? null;
$others = $feat ? array_slice($packages, 1) : [];

// Pre-fetch services for each package
$pkgServicesMap = [];
if ($pdo instanceof PDO && $packages) {
    try {
        $ids = array_column($packages, 'id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("
            SELECT ps.package_id, ps.sessions_included,
                   s.name_ar, s.name_en
            FROM package_services ps
            JOIN services s ON s.id = ps.service_id
            WHERE ps.package_id IN ($in) AND s.deleted_at IS NULL
            ORDER BY ps.package_id
        ");
        $st->execute($ids);
        foreach ($st->fetchAll() as $r) {
            $pkgServicesMap[(int)$r['package_id']][] = $r;
        }
    } catch (Throwable $e) {}
}

$arrow = $dir === 'rtl' ? 'left' : 'right';
?>

<!-- ════════ PAGE HERO ════════ -->
<section class="hero" style="padding-bottom:2rem">
    <div class="container">
        <div class="page-hero reveal">
            <div class="page-hero-content">
                <span class="tag"><i class="fa-solid fa-box-archive"></i><?= t('packages', 'الباقات') ?></span>
                <h1 class="hero-h1" style="font-size:clamp(2.2rem,4.5vw,3.8rem);margin:1.25rem 0 1.25rem">
                    <?= t('packages_page_h', 'وفّر أكثر مع <em>باقات الجلسات</em>.') ?>
                </h1>
                <p class="h-lead" style="margin-bottom:0;max-width:62ch">
                    <?= t('packages_page_lead', 'باقات مدروسة لاستمرارية النتائج. اختر ما يناسب خطّتك العلاجية، بأسعار أفضل من الجلسة المفردة.') ?>
                </p>
            </div>
            <ul class="page-hero-crumbs">
                <li><a href="<?= $base_url ?>"><?= t('home') ?></a></li>
                <li>·</li>
                <li><?= t('packages') ?></li>
            </ul>
        </div>
    </div>
</section>

<?php if ($packages): ?>
    <!-- ════════ FEATURED PACKAGE ════════ -->
    <?php if ($feat):
        $featPer = $feat['total_sessions'] > 0 ? round((float)$feat['price'] / (int)$feat['total_sessions']) : 0;
        $featServices = $pkgServicesMap[(int)$feat['id']] ?? [];
    ?>
    <section class="section" style="padding-top:0">
        <div class="container">
            <div class="pkg-page-feat reveal">
                <div class="pkg-page-feat-ribbon">
                    <i class="fa-solid fa-star"></i>
                    <?= t('best_value', 'الأفضل قيمة') ?>
                </div>

                <div class="pkg-page-feat-grid">
                    <!-- Left: hero info -->
                    <div class="pkg-page-feat-info">
                        <div class="pkg-page-feat-icon"><i class="fa-solid fa-box-open"></i></div>
                        <div class="pkg-page-feat-eyebrow"><?= t('signature_package', 'باقة مميّزة') ?></div>
                        <h2 class="pkg-page-feat-name"><?= e(tr($feat, 'name')) ?></h2>

                        <div class="pkg-page-feat-stats">
                            <div>
                                <strong><?= (int)$feat['total_sessions'] ?></strong>
                                <span><?= t('sessions_count', 'جلسة كاملة') ?></span>
                            </div>
                            <div>
                                <strong><?= (int)$feat['validity_days'] ?></strong>
                                <span><?= t('valid_days_label', 'يوم صلاحية') ?></span>
                            </div>
                            <?php if ($featPer): ?>
                            <div>
                                <strong dir="ltr"><?= number_format($featPer, 0) ?></strong>
                                <span><?= t('per_session_label', 'للجلسة') ?> · <?= site_setting('currency','JOD') ?></span>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php $desc = tr($feat, 'description'); if ($desc): ?>
                            <p class="pkg-page-feat-desc"><?= e($desc) ?></p>
                        <?php endif; ?>
                    </div>

                    <!-- Right: price + CTA + included services -->
                    <div class="pkg-page-feat-aside">
                        <div class="pkg-page-feat-price">
                            <span class="num"><?= number_format((float)$feat['price'], 0) ?></span>
                            <span class="cur"><?= site_setting('currency','JOD') ?></span>
                        </div>

                        <a href="<?= $base_url ?>booking?package=<?= (int)$feat['id'] ?>" class="btn btn-light pkg-page-feat-cta">
                            <?= t('choose_this_package', 'اختر هذه الباقة') ?>
                            <i class="fa-solid fa-arrow-<?= $arrow ?>"></i>
                        </a>

                        <?php if ($featServices): ?>
                            <div class="pkg-page-feat-included">
                                <div class="pkg-page-feat-included-title">
                                    <?= t('included_services', 'الخدمات المشمولة') ?>
                                </div>
                                <ul>
                                    <?php foreach ($featServices as $sv): ?>
                                        <li>
                                            <i class="fa-solid fa-circle-check"></i>
                                            <span><?= e(tr($sv, 'name')) ?></span>
                                            <span class="x"><?= (int)$sv['sessions_included'] ?>×</span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- ════════ OTHER PACKAGES GRID ════════ -->
    <?php if ($others): ?>
    <section class="section" style="padding-top:0">
        <div class="container">
            <div class="heading-block reveal">
                <span class="tag outline"><i class="fa-solid fa-layer-group"></i><?= t('more_options', 'خيارات أخرى') ?></span>
                <h2><?= t('more_packages_h', 'باقات إضافية <em>تناسب احتياجك</em>.') ?></h2>
                <p><?= t('more_packages_sub', 'مجموعة من الباقات الأصغر بأحجام مختلفة لتختار ما يناسب جلساتك.') ?></p>
            </div>

            <div class="pkg-page-grid reveal">
                <?php foreach ($others as $p):
                    $per = $p['total_sessions'] > 0 ? round((float)$p['price'] / (int)$p['total_sessions']) : 0;
                    $svcs = $pkgServicesMap[(int)$p['id']] ?? [];
                ?>
                    <div class="pkg-page-card">
                        <div class="pkg-page-card-head">
                            <div class="pkg-page-card-icon"><i class="fa-solid fa-box-open"></i></div>
                            <div>
                                <div class="pkg-page-card-name"><?= e(tr($p, 'name')) ?></div>
                                <div class="pkg-page-card-meta">
                                    <span><i class="fa-solid fa-list-check"></i><?= (int)$p['total_sessions'] ?> <?= t('sessions','جلسة') ?></span>
                                    <span><i class="fa-regular fa-calendar"></i><?= (int)$p['validity_days'] ?> <?= t('day','يوم') ?></span>
                                </div>
                            </div>
                        </div>

                        <?php if ($svcs): ?>
                            <ul class="pkg-page-card-services">
                                <?php foreach (array_slice($svcs, 0, 4) as $sv): ?>
                                    <li>
                                        <i class="fa-solid fa-circle-check"></i>
                                        <span><?= e(tr($sv, 'name')) ?></span>
                                        <span class="x"><?= (int)$sv['sessions_included'] ?>×</span>
                                    </li>
                                <?php endforeach; ?>
                                <?php if (count($svcs) > 4): ?>
                                    <li class="more">+ <?= count($svcs) - 4 ?> <?= t('more','أخرى') ?></li>
                                <?php endif; ?>
                            </ul>
                        <?php endif; ?>

                        <div class="pkg-page-card-foot">
                            <div class="pkg-page-card-price">
                                <span class="num"><?= number_format((float)$p['price'], 0) ?></span>
                                <span class="cur"><?= site_setting('currency','JOD') ?></span>
                                <?php if ($per): ?>
                                    <small dir="ltr"><?= number_format($per,0) ?>/<?= t('sess','جلسة') ?></small>
                                <?php endif; ?>
                            </div>
                            <a href="<?= $base_url ?>booking?package=<?= (int)$p['id'] ?>" class="btn btn-outline btn-sm">
                                <?= t('book_now') ?>
                                <i class="fa-solid fa-arrow-<?= $arrow ?>"></i>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- ════════ GUARANTEE STRIP ════════ -->
    <section class="section tight" style="padding-top:0">
        <div class="container">
            <div class="guarantee-strip reveal">
                <div class="guarantee-item">
                    <div class="guarantee-icon"><i class="fa-solid fa-shield-halved"></i></div>
                    <div>
                        <strong><?= t('refund_g', 'استرداد آمن') ?></strong>
                        <span><?= t('refund_g_p', 'استرداد المبلغ المتبقي خلال 14 يوم للباقات غير المُستخدمة.') ?></span>
                    </div>
                </div>
                <div class="guarantee-item">
                    <div class="guarantee-icon"><i class="fa-solid fa-calendar-check"></i></div>
                    <div>
                        <strong><?= t('priority_g', 'حجز ذو أولوية') ?></strong>
                        <span><?= t('priority_g_p', 'احجز جلستك في الأوقات المفضّلة قبل غيرك.') ?></span>
                    </div>
                </div>
                <div class="guarantee-item">
                    <div class="guarantee-icon"><i class="fa-solid fa-user-doctor"></i></div>
                    <div>
                        <strong><?= t('consult_g', 'استشارة مجانية') ?></strong>
                        <span><?= t('consult_g_p', 'جلسة تقييم مبدئية مع مختصّينا قبل البدء.') ?></span>
                    </div>
                </div>
                <div class="guarantee-item">
                    <div class="guarantee-icon"><i class="fa-solid fa-rotate"></i></div>
                    <div>
                        <strong><?= t('flexible_g', 'مرونة كاملة') ?></strong>
                        <span><?= t('flexible_g_p', 'إعادة جدولة أي جلسة بإشعار قبل 24 ساعة.') ?></span>
                    </div>
                </div>
            </div>
        </div>
    </section>
<?php else: ?>
    <section class="section">
        <div class="container">
            <div class="empty-state reveal">
                <div class="empty-state-icon"><i class="fa-solid fa-box-open"></i></div>
                <h3><?= t('no_packages_yet', 'لا توجد باقات نشطة حالياً.') ?></h3>
                <p><?= t('check_services', 'تصفّح خدماتنا الفردية، أو تواصل معنا للاستفسار.') ?></p>
                <a href="<?= $base_url ?>services" class="btn btn-teal"><?= t('services') ?></a>
            </div>
        </div>
    </section>
<?php endif; ?>

<!-- ════════ VISIT CTA ════════ -->
<section class="section" style="padding-top:0">
    <div class="container">
        <div class="visit-card reveal">
            <div class="visit-body">
                <span class="tag"><i class="fa-solid fa-circle-question"></i><?= t('not_sure_pkg', 'محتار أي باقة تناسبك؟') ?></span>
                <h2><?= t('packages_cta_h', 'استشرنا — <em>نختار لك</em>.') ?></h2>
                <p><?= t('packages_cta_p', 'تواصل معنا، نُساعدك في اختيار الباقة الأنسب لحالتك وخطّتك العلاجية.') ?></p>
                <ul class="visit-info">
                    <li><i class="fa-solid fa-phone"></i><div dir="ltr"><a href="<?= tel_link(site_setting('contact_phone','+962700000000')) ?>" style="color:#fff"><?= e(site_setting('contact_phone', '+962 7 0000 0000')) ?></a></div></li>
                    <li><i class="fa-regular fa-envelope"></i><div dir="ltr"><a href="mailto:<?= e(site_setting('contact_email','info@nourstouch.com')) ?>" style="color:#fff"><?= e(site_setting('contact_email','info@nourstouch.com')) ?></a></div></li>
                </ul>
            </div>
            <div class="visit-aside">
                <div class="visit-aside-eyebrow"><?= t('start_today', 'ابدأ اليوم') ?></div>
                <h3><?= t('book_session_now', 'احجز جلستك<br>خلال دقيقة.') ?></h3>
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
