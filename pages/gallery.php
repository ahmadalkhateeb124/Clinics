<?php
$PageTitle = 'المعرض — ' . t('site_name');
$escapedDescription = "صور من عيادة لمسة نور.";

global $pdo;
$photos = [];
if ($pdo instanceof PDO) {
    try {
        $photos = $pdo->query("SELECT * FROM gallery WHERE deleted_at IS NULL AND is_active=1 ORDER BY sort_order, id DESC")->fetchAll();
    } catch (Throwable $e) {}
}
?>
<section class="hero-section py-5 text-center">
    <div class="container" data-aos="fade-up">
        <h1 class="text-teal">المعرض</h1>
        <p class="lead text-muted">لمحة من العيادة</p>
    </div>
</section>
<section class="py-5">
    <div class="container">
        <div class="row g-3">
            <?php foreach ($photos as $i => $p): ?>
                <div class="col-md-3 col-6" data-aos="zoom-in" data-aos-delay="<?= ($i%6)*60 ?>">
                    <a href="<?= $base_url . 'uploads/' . e($p['image']) ?>" target="_blank">
                        <img src="<?= $base_url . 'uploads/' . e($p['image']) ?>" class="img-fluid rounded shadow-sm" style="aspect-ratio:1; object-fit:cover; width:100%">
                    </a>
                </div>
            <?php endforeach; ?>
            <?php if (!$photos): ?>
                <div class="col-12 text-center text-muted py-5">لا توجد صور حالياً.</div>
            <?php endif; ?>
        </div>
    </div>
</section>
