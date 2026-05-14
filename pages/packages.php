<?php
$PageTitle = 'الباقات — ' . t('site_name');
$escapedDescription = "باقات الجلسات في عيادة لمسة نور — وفّر أكثر مع باقاتنا المرنة.";

global $pdo;
$packages = [];
if ($pdo instanceof PDO) {
    try {
        $packages = $pdo->query("
            SELECT p.*, COUNT(ps.service_id) AS svc_count
            FROM packages p LEFT JOIN package_services ps ON ps.package_id = p.id
            WHERE p.deleted_at IS NULL AND p.is_active=1
            GROUP BY p.id
            ORDER BY p.sort_order, p.price
        ")->fetchAll();
    } catch (Throwable $e) {}
}
?>
<section class="hero-section py-5 text-center">
    <div class="container" data-aos="fade-up">
        <h1 class="text-teal">باقاتنا</h1>
        <p class="lead text-muted">وفّر أكثر مع باقات الجلسات المتعددة</p>
    </div>
</section>

<section class="py-5">
    <div class="container">
        <div class="row g-4">
            <?php foreach ($packages as $i => $p):
                global $pdo;
                $pkgServices = [];
                try {
                    $st = $pdo->prepare("
                        SELECT s.name_ar, ps.sessions_included
                        FROM package_services ps JOIN services s ON s.id=ps.service_id
                        WHERE ps.package_id = ? AND s.deleted_at IS NULL
                        LIMIT 6
                    ");
                    $st->execute([$p['id']]); $pkgServices = $st->fetchAll();
                } catch (Throwable $e) {}
            ?>
                <div class="col-md-6 col-lg-4" data-aos="fade-up" data-aos-delay="<?= ($i%6)*80 ?>">
                    <div class="card package-card p-4 h-100 d-flex flex-column">
                        <div class="text-center">
                            <div class="service-icon mx-auto mb-3"><i class="fa-solid fa-box-open"></i></div>
                            <h5><?= e($p['name_ar']) ?></h5>
                            <div class="pkg-price my-2"><?= format_money($p['price']) ?></div>
                            <p class="text-muted small">
                                <i class="fa-solid fa-check text-teal me-1"></i><?= (int)$p['total_sessions'] ?> جلسة ·
                                <i class="fa-regular fa-calendar text-teal me-1"></i><?= (int)$p['validity_days'] ?> يوم صلاحية
                            </p>
                        </div>
                        <?php if ($pkgServices): ?>
                            <hr>
                            <p class="small text-muted mb-2"><strong>الخدمات المشمولة:</strong></p>
                            <ul class="small list-unstyled mb-3">
                                <?php foreach ($pkgServices as $sv): ?>
                                    <li><i class="fa-solid fa-circle-check text-teal me-1"></i><?= e($sv['name_ar']) ?> <span class="text-muted">(<?= (int)$sv['sessions_included'] ?>×)</span></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <a href="<?= $base_url ?>booking?package=<?= (int)$p['id'] ?>" class="btn btn-teal mt-auto"><?= t('book_now') ?></a>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$packages): ?>
                <div class="col-12 text-center text-muted py-5">لا توجد باقات حالياً.</div>
            <?php endif; ?>
        </div>
    </div>
</section>
