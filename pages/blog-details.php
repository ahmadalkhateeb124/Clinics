<?php
global $pdo;
$slug = $_GET['puid'] ?? '';
$post = null; $related = [];

if ($pdo instanceof PDO && $slug !== '') {
    try {
        $st = $pdo->prepare("
            SELECT p.*, c.name_ar AS cat_ar, c.name_en AS cat_en, c.id AS cat_id,
                   u.name AS author_name, u.avatar AS author_avatar
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
                SELECT p.id, p.slug, p.title_ar, p.title_en, p.excerpt_ar, p.excerpt_en,
                       p.featured_image, p.published_at,
                       c.name_ar AS cat_ar, c.name_en AS cat_en
                FROM blog_posts p
                LEFT JOIN blog_categories c ON c.id = p.category_id
                WHERE p.deleted_at IS NULL AND p.status='published'
                  AND p.id <> ? AND p.category_id = ?
                ORDER BY p.published_at DESC LIMIT 3
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

$title       = tr($post, 'title');
$content     = tr($post, 'content');
$excerpt     = tr($post, 'excerpt');
$catName     = tr(['name_ar'=>$post['cat_ar']??'','name_en'=>$post['cat_en']??''], 'name');
$metaTitle   = $post['meta_title_ar'] ?: $title;
$metaDesc    = $post['meta_description_ar'] ?: ($excerpt ?: $title);

$PageTitle           = $metaTitle . ' — ' . t('site_name');
$escapedDescription  = e(mb_substr(strip_tags($metaDesc), 0, 160));
$KeyWords            = e($post['meta_keywords_ar'] ?? '');
$ogImage             = $post['featured_image']
    ? ($base_url . 'uploads/' . $post['featured_image'])
    : ($base_url . 'assets/img/og-default.jpg');

// Estimate reading time
$wordCount = str_word_count(strip_tags($content));
$readMins  = max(1, (int)ceil($wordCount / 200));
$arrow     = $dir === 'rtl' ? 'left' : 'right';
?>

<!-- ════════ ARTICLE SPLIT HEADER ════════ -->
<section class="article-split">
    <div class="container">
        <!-- Breadcrumbs above the split -->
        <ul class="page-hero-crumbs reveal" style="margin-bottom:2rem">
            <li><a href="<?= $base_url ?>"><?= t('home') ?></a></li>
            <li>·</li>
            <li><a href="<?= $base_url ?>blog"><?= t('blog') ?></a></li>
            <?php if ($catName): ?>
                <li>·</li>
                <li><a href="<?= $base_url ?>blog?cat=<?= (int)$post['cat_id'] ?>"><?= e($catName) ?></a></li>
            <?php endif; ?>
        </ul>

        <div class="article-split-grid reveal">
            <!-- Left: image -->
            <figure class="article-split-image">
                <?php if (!empty($post['featured_image'])): ?>
                    <img src="<?= $base_url ?>uploads/<?= e($post['featured_image']) ?>" alt="<?= e($title) ?>">
                <?php else: ?>
                    <div class="article-split-image-fallback"><i class="fa-regular fa-newspaper"></i></div>
                <?php endif; ?>
                <?php if ($catName): ?>
                    <a href="<?= $base_url ?>blog?cat=<?= (int)$post['cat_id'] ?>" class="article-split-cat">
                        <?= e($catName) ?>
                    </a>
                <?php endif; ?>
            </figure>

            <!-- Right: title + meta + excerpt -->
            <div class="article-split-info">
                <!-- 1. Category kicker -->
                <?php if ($catName): ?>
                    <a href="<?= $base_url ?>blog?cat=<?= (int)$post['cat_id'] ?>" class="article-kicker">
                        <span class="article-kicker-dot"></span>
                        <?= e($catName) ?>
                    </a>
                <?php endif; ?>

                <!-- 2. Title -->
                <h1 class="article-split-title"><?= e($title) ?></h1>

                <!-- 3. Excerpt as a refined deck -->
                <?php if ($excerpt): ?>
                    <p class="article-split-excerpt"><?= e($excerpt) ?></p>
                <?php endif; ?>

                <!-- 4. Hairline divider -->
                <div class="article-split-rule"></div>

                <!-- 5. Clinic byline + meta -->
                <?php
                    $clinicLogo = site_setting('clinic_logo', '');
                    $clinicName = $lang === 'en'
                        ? (site_setting('site_name_en','') ?: site_setting('site_name_ar','') ?: t('site_name'))
                        : (site_setting('site_name_ar','') ?: site_setting('site_name_en','') ?: t('site_name'));
                    $clinicInitial = mb_substr($clinicName, 0, 1);
                ?>
                <div class="article-byline">
                    <div class="article-byline-author">
                        <?php if ($clinicLogo): ?>
                            <img src="<?= $base_url ?>uploads/<?= e($clinicLogo) ?>" alt="<?= e($clinicName) ?>" class="article-byline-avatar logo">
                        <?php else: ?>
                            <div class="article-byline-avatar fallback"><?= e(mb_strtoupper($clinicInitial)) ?></div>
                        <?php endif; ?>
                        <div>
                            <div class="article-byline-by"><?= t('written_by','بقلم') ?></div>
                            <div class="article-byline-name"><?= e($clinicName) ?></div>
                        </div>
                    </div>

                    <div class="article-byline-meta">
                        <?php if ($post['published_at']): ?>
                            <div>
                                <div class="article-byline-label"><?= t('published_on','نُشر في') ?></div>
                                <div class="article-byline-value"><?= format_date($post['published_at'], 'F j, Y') ?></div>
                            </div>
                        <?php endif; ?>
                        <div>
                            <div class="article-byline-label"><?= t('reading_time','مدة القراءة') ?></div>
                            <div class="article-byline-value"><?= $readMins ?> <?= t('min_read','دقيقة') ?></div>
                        </div>
                    </div>
                </div>

                <!-- 6. Share row -->
                <?php $url = urlencode($base_url . $post['slug']); $titleEnc = urlencode($title); ?>
                <div class="article-share-row">
                    <span class="article-share-row-label"><?= t('share_this','شارك المقال') ?></span>
                    <div class="article-share-row-btns">
                        <a href="https://wa.me/?text=<?= $titleEnc ?>%20<?= $url ?>" target="_blank" rel="noopener" aria-label="WhatsApp"><i class="fa-brands fa-whatsapp"></i></a>
                        <a href="https://www.facebook.com/sharer/sharer.php?u=<?= $url ?>" target="_blank" rel="noopener" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
                        <a href="https://twitter.com/intent/tweet?text=<?= $titleEnc ?>&url=<?= $url ?>" target="_blank" rel="noopener" aria-label="Twitter"><i class="fa-brands fa-x-twitter"></i></a>
                        <button type="button" class="copy-link" aria-label="Copy link" data-url="<?= e($base_url . $post['slug']) ?>"><i class="fa-solid fa-link"></i></button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ════════ ARTICLE BODY ════════ -->
<article class="article-body">
    <div class="container">
        <div class="article-content reveal">
            <?= $content ?>
        </div>
    </div>
</article>

<!-- ════════ BACK / NEXT NAV ════════ -->
<section class="section tight" style="padding-top:0">
    <div class="container" style="max-width:820px">
        <div class="article-nav reveal">
            <a href="<?= $base_url ?>blog" class="article-nav-back">
                <i class="fa-solid fa-arrow-<?= $dir==='rtl'?'right':'left' ?>"></i>
                <span><?= t('back_to_blog', 'كل المقالات') ?></span>
            </a>
        </div>
    </div>
</section>

<!-- ════════ RELATED ════════ -->
<?php if ($related): ?>
<section class="section" style="background:var(--c-paper)">
    <div class="container">
        <div class="heading-block reveal" style="text-align:center;margin-inline:auto">
            <span class="tag"><i class="fa-regular fa-newspaper"></i><?= t('related','ذات صلة') ?></span>
            <h2><?= t('related_articles', 'مقالات قد <em>تعجبك</em>.') ?></h2>
            <p><?= t('related_sub', 'استكمل قراءتك بمواضيع من نفس الفئة.') ?></p>
        </div>

        <div class="blog-grid reveal">
            <?php foreach ($related as $r):
                $rtitle = tr($r, 'title');
                $rcat   = tr(['name_ar'=>$r['cat_ar']??'','name_en'=>$r['cat_en']??''], 'name');
                $rex    = tr($r, 'excerpt');
            ?>
                <a class="blog-card" href="<?= $base_url . e($r['slug']) ?>">
                    <div class="blog-card-image">
                        <?php if (!empty($r['featured_image'])): ?>
                            <img src="<?= $base_url ?>uploads/<?= e($r['featured_image']) ?>" alt="">
                        <?php else: ?>
                            <div class="blog-card-image-fallback"><i class="fa-regular fa-newspaper"></i></div>
                        <?php endif; ?>
                    </div>
                    <div class="blog-card-body">
                        <?php if ($rcat): ?><div class="blog-card-cat"><?= e($rcat) ?></div><?php endif; ?>
                        <h3 class="blog-card-title"><?= e($rtitle) ?></h3>
                        <?php if ($rex): ?>
                            <p class="blog-card-excerpt"><?= e(mb_strimwidth(strip_tags($rex), 0, 100, '…')) ?></p>
                        <?php endif; ?>
                        <div class="blog-card-foot">
                            <span class="blog-card-date"><?= format_date($r['published_at'],'M j, Y') ?></span>
                            <span class="link-arrow" style="font-size:.78rem;border:0;padding:0;letter-spacing:0">
                                <?= t('read', 'اقرأ') ?>
                                <i class="fa-solid fa-arrow-<?= $arrow ?>"></i>
                            </span>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ════════ CTA ════════ -->
<section class="section" style="padding-top:0">
    <div class="container">
        <div class="visit-card reveal">
            <div class="visit-body">
                <span class="tag"><i class="fa-solid fa-heart"></i><?= t('liked_article','أعجبك المقال؟') ?></span>
                <h2><?= t('blog_cta_h', 'النصيحة <em>تكتمل</em> بجلسة.') ?></h2>
                <p><?= t('blog_cta_p', 'اقرأ، ثم اختبر بنفسك — احجز جلسة تقييم مجانية مع مختصّينا.') ?></p>
                <ul class="visit-info">
                    <li><i class="fa-solid fa-phone"></i><div dir="ltr"><a href="<?= tel_link(site_setting('contact_phone','+962700000000')) ?>" style="color:#fff"><?= e(site_setting('contact_phone','+962 7 0000 0000')) ?></a></div></li>
                    <li><i class="fa-regular fa-envelope"></i><div dir="ltr"><a href="mailto:<?= e(site_setting('contact_email','info@nourstouch.com')) ?>" style="color:#fff"><?= e(site_setting('contact_email','info@nourstouch.com')) ?></a></div></li>
                </ul>
            </div>
            <div class="visit-aside">
                <div class="visit-aside-eyebrow"><?= t('start_today','ابدأ اليوم') ?></div>
                <h3><?= t('book_session_now','احجز جلستك<br>خلال دقيقة.') ?></h3>
                <a href="<?= $base_url ?>booking" class="btn btn-light">
                    <i class="fa-regular fa-calendar-check"></i>
                    <?= t('book_now') ?>
                </a>
            </div>
        </div>
    </div>
</section>

<script>
// Copy link to clipboard
document.querySelectorAll('.copy-link').forEach(b => {
    b.addEventListener('click', () => {
        navigator.clipboard.writeText(b.dataset.url).then(() => {
            const old = b.innerHTML;
            b.innerHTML = '<i class="fa-solid fa-check"></i>';
            b.style.background = 'var(--c-teal)';
            b.style.color = '#fff';
            b.style.borderColor = 'var(--c-teal)';
            setTimeout(() => {
                b.innerHTML = old;
                b.style.background = '';
                b.style.color = '';
                b.style.borderColor = '';
            }, 1500);
        });
    });
});
</script>
