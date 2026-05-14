<?php
$PageTitle = 'فريقنا — ' . t('site_name');
$escapedDescription = "تعرّف على فريق المعالجين والأطباء في عيادة لمسة نور.";

global $pdo;
$therapists = [];
if ($pdo instanceof PDO) {
    try {
        $therapists = $pdo->query("
            SELECT u.id, u.name, u.avatar, e.job_title, e.department
            FROM users u
            JOIN user_roles ur ON ur.user_id = u.id
            JOIN roles r ON r.id = ur.role_id
            LEFT JOIN employees e ON e.user_id = u.id AND e.deleted_at IS NULL
            WHERE u.deleted_at IS NULL AND u.status='active' AND r.slug IN ('therapist','doctor')
            GROUP BY u.id
            ORDER BY u.name
        ")->fetchAll();
    } catch (Throwable $e) {}
}
?>
<section class="hero-section py-5 text-center">
    <div class="container" data-aos="fade-up">
        <h1 class="text-teal">فريقنا</h1>
        <p class="lead text-muted">معالجون وأطباء معتمدون لخدمتكم</p>
    </div>
</section>

<section class="py-5">
    <div class="container">
        <div class="row g-3">
            <?php foreach ($therapists as $i => $t): ?>
                <div class="col-md-6 col-lg-4" data-aos="fade-up" data-aos-delay="<?= ($i%6)*60 ?>">
                    <div class="card therapist-card text-center p-4 h-100">
                        <?php if ($t['avatar']): ?>
                            <img src="<?= $base_url . 'uploads/' . e($t['avatar']) ?>" class="mx-auto mb-3 rounded-circle" style="width:120px;height:120px;object-fit:cover">
                        <?php else: ?>
                            <div class="mx-auto mb-3 rounded-circle bg-light d-flex align-items-center justify-content-center" style="width:120px;height:120px">
                                <i class="fa-solid fa-user-doctor fa-3x text-muted"></i>
                            </div>
                        <?php endif; ?>
                        <h5 class="mb-1"><?= e($t['name']) ?></h5>
                        <p class="small text-muted mb-3">
                            <?= e($t['job_title'] ?? '—') ?>
                            <?php if (!empty($t['department'])): ?>· <?= e($t['department']) ?><?php endif; ?>
                        </p>
                        <a href="<?= $base_url ?>booking?therapist=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-teal mt-auto">
                            <i class="fa-regular fa-calendar-check me-1"></i>احجز معه/ها
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$therapists): ?>
                <div class="col-12 text-center text-muted py-5">لا يوجد معالجون حالياً.</div>
            <?php endif; ?>
        </div>
    </div>
</section>
