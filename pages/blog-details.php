<?php
global $pdo;
$slug = $_GET['puid'] ?? '';
$post = null; $related = [];

if ($pdo instanceof PDO && $slug !== '') {
    try {
        $st = $pdo->prepare("
            SELECT p.*, c.name_ar AS cat, u.name AS author_name
            FROM blog_posts p
            LEFT JOIN blog_categories c ON c.id = p.category_id
            LEFT JOIN users u ON u.id = p.author_id
            WHERE p.slug = ? AND p.status='published' AND p.deleted_at IS NULL
            LIMIT 1
        ");
        $st->execute([$slug]); $post = $st->fetch();
        if ($post) {
            $pdo->prepare("UPDATE blog_posts SET views = views + 1 WHERE id = ?")->execute([$post['id']]);
            $r = $pdo->prepare("
                SELECT id, slug, title_ar, featured_image, published_at
                FROM blog_posts
                WHERE deleted_at IS NULL AND status='published' AND id <> ? AND category_id = ?
                ORDER BY published_at DESC LIMIT 3
            ");
            $r->execute([$post['id'], $post['category_id']]);
            $related = $r->fetchAll();
        }
    } catch (Throwable $e) {}
}

if (!$post) {
    http_response_code(404);
    include __DIR__ . '/404.php';
    return;
}

$PageTitle = ($post['meta_title_ar'] ?: $post['title_ar']) . ' — ' . t('site_name');
$escapedDescription = e(mb_substr(strip_tags($post['meta_description_ar'] ?: $post['excerpt_ar'] ?: $post['title_ar']), 0, 160));
$KeyWords = e($post['meta_keywords_ar'] ?? '');
$ogImage  = $post['featured_image'] ? ($base_url . 'uploads/' . e($post['featured_image'])) : ($base_url . 'assets/img/og-default.jpg');
?>
<article class="py-5">
    <div class="container" style="max-width:800px">
        <nav class="small mb-3 text-muted">
            <a href="<?= $base_url ?>" class="text-muted text-decoration-none"><?= t('home') ?></a> ›
            <a href="<?= $base_url ?>blog" class="text-muted text-decoration-none"><?= t('blog') ?></a> ›
            <span><?= e($post['title_ar']) ?></span>
        </nav>

        <?php if ($post['cat']): ?><span class="badge mb-2" style="background:var(--nt-teal-soft); color:var(--nt-teal)"><?= e($post['cat']) ?></span><?php endif; ?>
        <h1 class="mb-2"><?= e($post['title_ar']) ?></h1>
        <p class="text-muted small mb-4">
            <i class="fa-regular fa-clock me-1"></i><?= format_date($post['published_at'],'Y-m-d') ?>
            <?php if ($post['author_name']): ?>· <i class="fa-regular fa-user me-1"></i><?= e($post['author_name']) ?><?php endif; ?>
            · <i class="fa-regular fa-eye me-1"></i><?= (int)$post['views'] ?>
        </p>

        <?php if ($post['featured_image']): ?>
            <img src="<?= $base_url . 'uploads/' . e($post['featured_image']) ?>" class="img-fluid rounded mb-4">
        <?php endif; ?>

        <div class="post-content">
            <?= $post['content_ar'] ?>
        </div>
    </div>
</article>

<?php if ($related): ?>
<section class="py-5 bg-soft">
    <div class="container">
        <h4 class="mb-3 text-teal">مقالات ذات صلة</h4>
        <div class="row g-3">
            <?php foreach ($related as $r): ?>
                <div class="col-md-4">
                    <a href="<?= $base_url . e($r['slug']) ?>" class="text-decoration-none text-dark">
                        <div class="card blog-card h-100">
                            <?php if ($r['featured_image']): ?>
                                <img src="<?= $base_url . 'uploads/' . e($r['featured_image']) ?>" class="card-img-top">
                            <?php endif; ?>
                            <div class="card-body">
                                <h6><?= e($r['title_ar']) ?></h6>
                                <small class="text-muted"><?= format_date($r['published_at'],'Y-m-d') ?></small>
                            </div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>
