<?php
$PageTitle = 'الخدمات — ' . t('site_name');
$escapedDescription = "تعرّف على جميع خدمات عيادة لمسة نور — علاج طبيعي، تصريف سوائل، مساج، حجامة، تقشير، استشارات طبية.";

global $pdo;
$catFilter = (int)($_GET['cat'] ?? 0);
$categories = []; $services = [];
if ($pdo instanceof PDO) {
    try {
        $categories = $pdo->query("SELECT * FROM service_categories WHERE deleted_at IS NULL AND is_active=1 ORDER BY sort_order")->fetchAll();
        $sql = "SELECT s.*, c.name_ar AS cat_name, c.icon AS cat_icon
                FROM services s LEFT JOIN service_categories c ON c.id=s.category_id
                WHERE s.deleted_at IS NULL AND s.is_active=1";
        $params = [];
        if ($catFilter > 0) { $sql .= " AND s.category_id = ?"; $params[] = $catFilter; }
        $sql .= " ORDER BY c.sort_order, s.sort_order, s.name_ar";
        $st = $pdo->prepare($sql); $st->execute($params);
        $services = $st->fetchAll();
    } catch (Throwable $e) {}
}
?>
<section class="hero-section py-5 text-center">
    <div class="container" data-aos="fade-up">
        <h1 class="text-teal">خدماتنا</h1>
        <p class="lead text-muted">باقة متكاملة من الجلسات العلاجية والتجميلية</p>
    </div>
</section>

<section class="py-5">
    <div class="container">
        <!-- Category filter -->
        <div class="d-flex flex-wrap gap-2 justify-content-center mb-4">
            <a href="<?= $base_url ?>services" class="btn btn-sm <?= $catFilter===0?'btn-teal':'btn-outline-teal' ?>">الكل</a>
            <?php foreach ($categories as $c): ?>
                <a href="<?= $base_url ?>services?cat=<?= (int)$c['id'] ?>" class="btn btn-sm <?= $catFilter===(int)$c['id']?'btn-teal':'btn-outline-teal' ?>">
                    <?php if ($c['icon']): ?><i class="fa-solid <?= e($c['icon']) ?> me-1"></i><?php endif; ?>
                    <?= e($c['name_ar']) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="row g-3">
            <?php foreach ($services as $i => $s): ?>
                <div class="col-md-6 col-lg-4" data-aos="fade-up" data-aos-delay="<?= ($i % 6)*60 ?>">
                    <div class="card service-card p-4 h-100 d-flex flex-column">
                        <?php if ($s['cat_icon']): ?>
                            <div class="service-icon mb-3"><i class="fa-solid <?= e($s['cat_icon']) ?>"></i></div>
                        <?php endif; ?>
                        <h5><?= e($s['name_ar']) ?></h5>
                        <small class="text-muted mb-2">
                            <i class="fa-regular fa-clock me-1"></i><?= (int)$s['duration_minutes'] ?> دقيقة
                            <?php if ($s['cat_name']): ?>· <?= e($s['cat_name']) ?><?php endif; ?>
                        </small>
                        <?php if ($s['description_ar']): ?>
                            <p class="small text-muted"><?= e($s['description_ar']) ?></p>
                        <?php endif; ?>
                        <div class="d-flex justify-content-between align-items-center mt-auto pt-3">
                            <span class="pkg-price" style="font-size:1.3rem"><?= format_money($s['price']) ?></span>
                            <a href="<?= $base_url ?>booking?service=<?= (int)$s['id'] ?>" class="btn btn-sm btn-teal"><?= t('book_now') ?></a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$services): ?>
                <div class="col-12 text-center text-muted py-5">لا توجد خدمات حالياً.</div>
            <?php endif; ?>
        </div>
    </div>
</section>
