<?php
$PageTitle = t('site_name') . ' — ' . t('home');
$escapedDescription = "عيادة لمسة نور في الأردن — علاج طبيعي، تصريف سوائل، مساج، حجامة، تقشير، استشارات طبية. احجز جلستك الآن.";

global $pdo;

// Pull active sliders
$sliders = [];
$categories = [];
$featuredServices = [];
$testimonials = [];
$activePackages = [];
$latestPosts = [];

if ($pdo instanceof PDO) {
    try {
        $sliders          = $pdo->query("SELECT * FROM sliders WHERE deleted_at IS NULL AND is_active=1 ORDER BY sort_order LIMIT 5")->fetchAll();
        $categories       = $pdo->query("SELECT * FROM service_categories WHERE deleted_at IS NULL AND is_active=1 ORDER BY sort_order LIMIT 8")->fetchAll();
        $featuredServices = $pdo->query("
            SELECT s.*, c.name_ar AS cat
            FROM services s LEFT JOIN service_categories c ON c.id=s.category_id
            WHERE s.deleted_at IS NULL AND s.is_active=1
            ORDER BY s.sort_order LIMIT 6
        ")->fetchAll();
        $testimonials     = $pdo->query("SELECT * FROM testimonials WHERE deleted_at IS NULL AND is_active=1 ORDER BY is_featured DESC, sort_order LIMIT 6")->fetchAll();
        $activePackages   = $pdo->query("SELECT * FROM packages WHERE deleted_at IS NULL AND is_active=1 ORDER BY sort_order LIMIT 3")->fetchAll();
        $latestPosts      = $pdo->query("SELECT * FROM blog_posts WHERE deleted_at IS NULL AND status='published' ORDER BY published_at DESC LIMIT 3")->fetchAll();
    } catch (Throwable $e) { /* tables may be empty */ }
}
?>

<!-- HERO -->
<section class="hero-section text-center">
    <div class="container" data-aos="fade-up">
        <?php if ($sliders && !empty($sliders[0]['title_ar'])): $s = $sliders[0]; ?>
            <h1 class="display-4 text-teal mb-3"><?= e($s['title_ar']) ?></h1>
            <p class="lead mb-4"><?= e($s['subtitle_ar'] ?? '') ?></p>
            <div class="d-flex justify-content-center gap-2 flex-wrap">
                <a href="<?= $base_url ?>booking" class="btn btn-teal btn-lg"><?= t('book_now') ?></a>
                <?php if (!empty($s['link_url'])): ?>
                    <a href="<?= e($s['link_url']) ?>" class="btn btn-outline-teal btn-lg"><?= e($s['link_text'] ?: 'اعرف أكثر') ?></a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <h1 class="display-4 text-teal mb-3"><?= e(t('site_name')) ?></h1>
            <p class="lead mb-4">علاج طبيعي • تصريف سوائل • مساج • حجامة • تقشير • استشارات</p>
            <div class="d-flex justify-content-center gap-2 flex-wrap">
                <a href="<?= $base_url ?>booking"  class="btn btn-teal btn-lg"><?= t('book_now') ?></a>
                <a href="<?= $base_url ?>services" class="btn btn-outline-teal btn-lg"><?= t('services') ?></a>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- CATEGORIES -->
<?php if ($categories): ?>
<section class="py-5 bg-soft">
    <div class="container">
        <div class="text-center" data-aos="fade-up">
            <h2 class="section-title">خدماتنا</h2>
            <p class="section-subtitle">باقة متكاملة من الخدمات العلاجية والتجميلية</p>
        </div>
        <div class="row g-3">
            <?php foreach ($categories as $i => $c): ?>
                <div class="col-6 col-md-3" data-aos="fade-up" data-aos-delay="<?= $i*60 ?>">
                    <a href="<?= $base_url ?>services?cat=<?= (int)$c['id'] ?>" class="text-decoration-none">
                        <div class="card service-card text-center p-4">
                            <div class="service-icon mx-auto mb-3">
                                <i class="fa-solid <?= e($c['icon'] ?: 'fa-spa') ?>"></i>
                            </div>
                            <h6 class="text-dark mb-0"><?= e($c['name_ar']) ?></h6>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- FEATURED SERVICES -->
<?php if ($featuredServices): ?>
<section class="py-5">
    <div class="container">
        <div class="text-center" data-aos="fade-up">
            <h2 class="section-title">خدمات مميزة</h2>
            <p class="section-subtitle">جلسات احترافية بأيدي معالجين معتمدين</p>
        </div>
        <div class="row g-3">
            <?php foreach ($featuredServices as $i => $s): ?>
                <div class="col-md-6 col-lg-4" data-aos="fade-up" data-aos-delay="<?= $i*60 ?>">
                    <div class="card service-card p-4">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h5 class="mb-0"><?= e($s['name_ar']) ?></h5>
                            <span class="badge bg-teal"><?= format_money($s['price']) ?></span>
                        </div>
                        <p class="small text-muted mb-2">
                            <i class="fa-regular fa-clock me-1"></i><?= (int)$s['duration_minutes'] ?> دقيقة
                            <?php if ($s['cat']): ?>· <?= e($s['cat']) ?><?php endif; ?>
                        </p>
                        <?php if ($s['description_ar']): ?>
                            <p class="small text-muted mb-3"><?= e(mb_strimwidth($s['description_ar'],0,120,'…')) ?></p>
                        <?php endif; ?>
                        <a href="<?= $base_url ?>booking?service=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-teal mt-auto"><?= t('book_now') ?></a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- PACKAGES -->
<?php if ($activePackages): ?>
<section class="py-5 bg-soft">
    <div class="container">
        <div class="text-center" data-aos="fade-up">
            <h2 class="section-title">باقاتنا</h2>
            <p class="section-subtitle">وفّر أكثر مع باقات الجلسات المتعددة</p>
        </div>
        <div class="row g-4">
            <?php foreach ($activePackages as $i => $p): ?>
                <div class="col-md-4" data-aos="fade-up" data-aos-delay="<?= $i*80 ?>">
                    <div class="card package-card text-center p-4">
                        <div class="service-icon mx-auto mb-3 pkg-icon"><i class="fa-solid fa-box-open"></i></div>
                        <h5><?= e($p['name_ar']) ?></h5>
                        <div class="pkg-price my-2"><?= format_money($p['price']) ?></div>
                        <p class="text-muted small mb-3">
                            <i class="fa-solid fa-check me-1 text-teal"></i><?= (int)$p['total_sessions'] ?> جلسة<br>
                            <i class="fa-regular fa-calendar me-1 text-teal"></i>صلاحية <?= (int)$p['validity_days'] ?> يوم
                        </p>
                        <a href="<?= $base_url ?>booking?package=<?= (int)$p['id'] ?>" class="btn btn-teal"><?= t('book_now') ?></a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- TESTIMONIALS -->
<?php if ($testimonials): ?>
<section class="py-5">
    <div class="container">
        <div class="text-center" data-aos="fade-up">
            <h2 class="section-title">آراء عملائنا</h2>
            <p class="section-subtitle">ثقتهم تشرّفنا</p>
        </div>
        <div class="row g-3">
            <?php foreach ($testimonials as $i => $t): ?>
                <div class="col-md-4" data-aos="fade-up" data-aos-delay="<?= $i*60 ?>">
                    <div class="testimonial-card">
                        <div class="stars mb-2"><?= str_repeat('★', (int)$t['rating']) . str_repeat('☆', 5 - (int)$t['rating']) ?></div>
                        <p class="small mb-3">"<?= e($t['content_ar']) ?>"</p>
                        <div class="d-flex align-items-center gap-2">
                            <?php if ($t['avatar']): ?>
                                <img src="<?= $base_url . 'uploads/' . e($t['avatar']) ?>" class="rounded-circle" style="width:42px;height:42px;object-fit:cover">
                            <?php else: ?>
                                <div class="bg-light rounded-circle d-flex align-items-center justify-content-center" style="width:42px;height:42px"><i class="fa-solid fa-user text-muted"></i></div>
                            <?php endif; ?>
                            <div>
                                <strong class="d-block small"><?= e($t['author']) ?></strong>
                                <small class="text-muted"><?= e($t['role'] ?? '') ?></small>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- LATEST BLOG POSTS -->
<?php if ($latestPosts): ?>
<section class="py-5 bg-soft">
    <div class="container">
        <div class="text-center" data-aos="fade-up">
            <h2 class="section-title">من المدونة</h2>
            <p class="section-subtitle">نصائح ومقالات حول الصحة والعلاج</p>
        </div>
        <div class="row g-3">
            <?php foreach ($latestPosts as $i => $p): ?>
                <div class="col-md-4" data-aos="fade-up" data-aos-delay="<?= $i*60 ?>">
                    <a href="<?= $base_url . e($p['slug']) ?>" class="text-decoration-none text-dark">
                        <div class="card blog-card h-100">
                            <?php if ($p['featured_image']): ?>
                                <img src="<?= $base_url . 'uploads/' . e($p['featured_image']) ?>" class="card-img-top">
                            <?php else: ?>
                                <div class="bg-light d-flex align-items-center justify-content-center" style="height:200px"><i class="fa-regular fa-image fa-3x text-muted"></i></div>
                            <?php endif; ?>
                            <div class="card-body">
                                <small class="text-muted"><?= format_date($p['published_at'],'Y-m-d') ?></small>
                                <h6 class="mt-1 mb-2"><?= e($p['title_ar']) ?></h6>
                                <p class="small text-muted mb-0"><?= e(mb_strimwidth(strip_tags($p['excerpt_ar'] ?? $p['content_ar'] ?? ''),0,100,'…')) ?></p>
                            </div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- CTA -->
<section class="py-5 text-center" style="background:var(--nt-teal); color:#fff;">
    <div class="container" data-aos="zoom-in">
        <h2 class="mb-3">جاهز تبدأ رحلة التعافي؟</h2>
        <p class="lead mb-4">احجز جلستك الأولى الآن واحصل على تقييم مجاني.</p>
        <a href="<?= $base_url ?>booking" class="btn btn-light btn-lg"><i class="fa-regular fa-calendar-check me-2"></i><?= t('book_now') ?></a>
    </div>
</section>
