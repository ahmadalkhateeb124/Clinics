<?php
global $pdo;
$slug = $_GET['puid'] ?? '';
$page = null;
if ($pdo instanceof PDO && $slug !== '') {
    try {
        $st = $pdo->prepare("SELECT * FROM pages WHERE slug = ? AND is_published=1 AND deleted_at IS NULL LIMIT 1");
        $st->execute([$slug]); $page = $st->fetch();
    } catch (Throwable $e) {}
}
if (!$page) { http_response_code(404); include __DIR__ . '/404.php'; return; }

$PageTitle = ($page['meta_title_ar'] ?: $page['title_ar']) . ' — ' . t('site_name');
$escapedDescription = e($page['meta_description_ar'] ?? $page['title_ar']);
?>
<section class="hero-section py-5 text-center">
    <div class="container" data-aos="fade-up">
        <h1 class="text-teal"><?= e($page['title_ar']) ?></h1>
    </div>
</section>
<section class="py-5">
    <div class="container" style="max-width:850px" data-aos="fade-up">
        <div class="post-content"><?= $page['content_ar'] ?></div>
    </div>
</section>
