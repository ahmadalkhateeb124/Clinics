<?php
$PageTitle = 'المدونة — ' . t('site_name');
$escapedDescription = "مقالات ونصائح صحية من خبراء عيادة لمسة نور.";

global $pdo;
$posts = []; $cats = [];
$catFilter = (int)($_GET['cat'] ?? 0);
$page = max(1, (int)($_GET['p'] ?? 1));
$per  = 9; $offset = ($page - 1) * $per;

if ($pdo instanceof PDO) {
    try {
        $cats = $pdo->query("SELECT * FROM blog_categories WHERE deleted_at IS NULL AND is_active=1 ORDER BY sort_order")->fetchAll();
        $where = "p.deleted_at IS NULL AND p.status='published'"; $params = [];
        if ($catFilter > 0) { $where .= " AND p.category_id = ?"; $params[] = $catFilter; }
        $st = $pdo->prepare("SELECT p.*, c.name_ar AS cat
                              FROM blog_posts p LEFT JOIN blog_categories c ON c.id=p.category_id
                              WHERE $where ORDER BY p.published_at DESC LIMIT $per OFFSET $offset");
        $st->execute($params); $posts = $st->fetchAll();
    } catch (Throwable $e) {}
}
?>
<section class="hero-section py-5 text-center">
    <div class="container" data-aos="fade-up">
        <h1 class="text-teal">المدونة</h1>
        <p class="lead text-muted">مقالات ونصائح حول الصحة والعلاج</p>
    </div>
</section>

<section class="py-5">
    <div class="container">
        <?php if ($cats): ?>
            <div class="d-flex flex-wrap gap-2 justify-content-center mb-4">
                <a href="<?= $base_url ?>blog" class="btn btn-sm <?= $catFilter===0?'btn-teal':'btn-outline-teal' ?>">الكل</a>
                <?php foreach ($cats as $c): ?>
                    <a href="<?= $base_url ?>blog?cat=<?= (int)$c['id'] ?>" class="btn btn-sm <?= $catFilter===(int)$c['id']?'btn-teal':'btn-outline-teal' ?>">
                        <?= e($c['name_ar']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="row g-3">
            <?php foreach ($posts as $i => $p): ?>
                <div class="col-md-6 col-lg-4" data-aos="fade-up" data-aos-delay="<?= ($i%6)*60 ?>">
                    <a href="<?= $base_url . e($p['slug']) ?>" class="text-decoration-none text-dark">
                        <div class="card blog-card h-100">
                            <?php if ($p['featured_image']): ?>
                                <img src="<?= $base_url . 'uploads/' . e($p['featured_image']) ?>" class="card-img-top">
                            <?php else: ?>
                                <div class="bg-light d-flex align-items-center justify-content-center" style="height:200px"><i class="fa-regular fa-image fa-3x text-muted"></i></div>
                            <?php endif; ?>
                            <div class="card-body">
                                <?php if ($p['cat']): ?><span class="badge mb-2"><?= e($p['cat']) ?></span><?php endif; ?>
                                <h6><?= e($p['title_ar']) ?></h6>
                                <p class="small text-muted mb-2"><?= e(mb_strimwidth(strip_tags($p['excerpt_ar'] ?? $p['content_ar'] ?? ''),0,120,'…')) ?></p>
                                <small class="text-muted"><i class="fa-regular fa-clock me-1"></i><?= format_date($p['published_at'],'Y-m-d') ?> · <i class="fa-regular fa-eye me-1"></i><?= (int)$p['views'] ?></small>
                            </div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
            <?php if (!$posts): ?>
                <div class="col-12 text-center text-muted py-5">لا توجد مقالات منشورة حالياً.</div>
            <?php endif; ?>
        </div>
    </div>
</section>
