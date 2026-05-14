<?php
$PageTitle = t('blog') . ' — ' . t('site_name');
$escapedDescription = "مقالات ونصائح صحية من خبراء عيادة لمسة نور.";

global $pdo;
$posts = $cats = [];
$catFilter = (int)($_GET['cat'] ?? 0);
$q         = trim($_GET['q'] ?? '');
$page      = max(1, (int)($_GET['p'] ?? 1));
$per       = 9;
$offset    = ($page - 1) * $per;
$total     = 0;
$featured  = null;

if ($pdo instanceof PDO) {
    try {
        $cats = $pdo->query("
            SELECT c.id, c.name_ar, c.name_en,
                   (SELECT COUNT(*) FROM blog_posts p WHERE p.category_id=c.id AND p.deleted_at IS NULL AND p.status='published') AS n
            FROM blog_categories c
            WHERE c.deleted_at IS NULL AND c.is_active=1
            HAVING n > 0
            ORDER BY c.sort_order, c.name_ar
        ")->fetchAll();

        $where = "p.deleted_at IS NULL AND p.status='published'";
        $params = [];
        if ($catFilter > 0) { $where .= " AND p.category_id = ?"; $params[] = $catFilter; }
        if ($q !== '') {
            $where .= " AND (p.title_ar LIKE ? OR p.title_en LIKE ?)";
            $like = "%$q%"; $params[] = $like; $params[] = $like;
        }

        $cnt = $pdo->prepare("SELECT COUNT(*) FROM blog_posts p WHERE $where");
        $cnt->execute($params); $total = (int)$cnt->fetchColumn();

        // Featured = latest post on page 1, no filter
        if ($page === 1 && $catFilter === 0 && $q === '') {
            $f = $pdo->query("
                SELECT p.*, c.name_ar AS cat_ar, c.name_en AS cat_en
                FROM blog_posts p LEFT JOIN blog_categories c ON c.id=p.category_id
                WHERE p.deleted_at IS NULL AND p.status='published'
                ORDER BY p.published_at DESC LIMIT 1
            ");
            $featured = $f->fetch();
        }

        // Other posts (skip featured on page 1)
        $skipFeatured = $featured ? 1 : 0;
        $st = $pdo->prepare("
            SELECT p.*, c.name_ar AS cat_ar, c.name_en AS cat_en
            FROM blog_posts p LEFT JOIN blog_categories c ON c.id=p.category_id
            WHERE $where
            ORDER BY p.published_at DESC
            LIMIT $per OFFSET ".(int)($offset + $skipFeatured)
        );
        $st->execute($params);
        $posts = $st->fetchAll();
    } catch (Throwable $e) {}
}

$pageCount = max(1, (int)ceil(max(0, $total - ($featured?1:0)) / $per));
$arrow     = $dir === 'rtl' ? 'left' : 'right';

// Helper for query string preservation in pagination
function blog_link($base, $p, $cat, $q) {
    $qs = [];
    if ($cat) $qs['cat'] = $cat;
    if ($q)   $qs['q']   = $q;
    if ($p>1) $qs['p']   = $p;
    return $base . 'blog' . ($qs ? '?'.http_build_query($qs) : '');
}
?>

<!-- ════════ PAGE HERO ════════ -->
<section class="hero" style="padding-bottom:2rem">
    <div class="container">
        <div class="page-hero reveal">
            <div class="page-hero-content">
                <span class="tag"><i class="fa-regular fa-newspaper"></i><?= t('journal','المدوّنة') ?></span>
                <h1 class="hero-h1" style="font-size:clamp(2.2rem,4.5vw,3.8rem);margin:1.25rem 0 1.25rem">
                    <?= t('blog_h', 'قراءات في <em>الصحة والعافية</em>.') ?>
                </h1>
                <p class="h-lead" style="margin-bottom:0;max-width:62ch">
                    <?= t('blog_lead', 'نصائح ومقالات من فريقنا — حول الصحة، العلاج، والعناية اليومية.') ?>
                </p>
            </div>
            <ul class="page-hero-crumbs">
                <li><a href="<?= $base_url ?>"><?= t('home') ?></a></li>
                <li>·</li>
                <li><?= t('blog') ?></li>
            </ul>
        </div>
    </div>
</section>

<!-- ════════ SEARCH + CATEGORY FILTER ════════ -->
<section class="section tight" style="padding:0">
    <div class="container">
        <form method="get" class="blog-search reveal">
            <div class="blog-search-input">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= t('search_articles','ابحث في المقالات…') ?>" autocomplete="off">
                <?php if ($catFilter): ?><input type="hidden" name="cat" value="<?= $catFilter ?>"><?php endif; ?>
            </div>
            <button class="btn btn-teal" type="submit">
                <?= t('search','بحث') ?>
                <i class="fa-solid fa-arrow-<?= $arrow ?>"></i>
            </button>
        </form>

        <?php if ($cats): ?>
        <div class="cat-filter reveal" style="margin-top:.85rem">
            <div class="cat-filter-scroll">
                <a href="<?= $base_url ?>blog<?= $q ? '?q='.urlencode($q) : '' ?>" class="cat-chip <?= $catFilter===0?'is-active':'' ?>">
                    <span class="cat-chip-label"><?= t('all','الكل') ?></span>
                    <span class="cat-chip-count"><?= $total ?></span>
                </a>
                <?php foreach ($cats as $c): ?>
                    <a href="<?= $base_url ?>blog?cat=<?= (int)$c['id'] ?><?= $q ? '&q='.urlencode($q) : '' ?>" class="cat-chip <?= $catFilter===(int)$c['id']?'is-active':'' ?>">
                        <span class="cat-chip-label"><?= e(tr($c,'name')) ?></span>
                        <span class="cat-chip-count"><?= (int)$c['n'] ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- ════════ POSTS ════════ -->
<section class="section">
    <div class="container">
        <?php if ($featured): ?>
            <!-- Featured (full-width hero card) -->
            <a class="blog-feat reveal" href="<?= $base_url . e($featured['slug']) ?>">
                <div class="blog-feat-image">
                    <?php if (!empty($featured['featured_image'])): ?>
                        <img src="<?= $base_url ?>uploads/<?= e($featured['featured_image']) ?>" alt="">
                    <?php else: ?>
                        <div class="blog-feat-image-fallback"><i class="fa-regular fa-newspaper"></i></div>
                    <?php endif; ?>
                </div>
                <div class="blog-feat-body">
                    <span class="tag warm"><i class="fa-solid fa-star"></i><?= t('featured','مميّز') ?></span>
                    <?php $featCat = tr(['name_ar'=>$featured['cat_ar']??'','name_en'=>$featured['cat_en']??''], 'name'); ?>
                    <div class="blog-feat-meta">
                        <?php if ($featCat): ?><span class="blog-feat-cat"><?= e($featCat) ?></span><?php endif; ?>
                        <?php if ($featured['published_at']): ?>
                            <span class="blog-feat-date"><?= format_date($featured['published_at'],'F j, Y') ?></span>
                        <?php endif; ?>
                    </div>
                    <h2 class="blog-feat-title"><?= e(tr($featured,'title')) ?></h2>
                    <p class="blog-feat-excerpt"><?= e(mb_strimwidth(strip_tags(tr($featured,'excerpt') ?: tr($featured,'content')), 0, 200, '…')) ?></p>
                    <span class="link-arrow">
                        <?= t('read_article','اقرأ المقال') ?>
                        <i class="fa-solid fa-arrow-<?= $arrow ?>"></i>
                    </span>
                </div>
            </a>
        <?php endif; ?>

        <?php if ($posts): ?>
            <div class="blog-grid reveal" style="<?= $featured?'margin-top:2.5rem':'' ?>">
                <?php foreach ($posts as $p):
                    $title  = tr($p, 'title');
                    $excerpt = tr($p, 'excerpt') ?: tr($p, 'content');
                    $catNm   = tr(['name_ar'=>$p['cat_ar']??'','name_en'=>$p['cat_en']??''], 'name');
                ?>
                    <a class="blog-card" href="<?= $base_url . e($p['slug']) ?>">
                        <div class="blog-card-image">
                            <?php if (!empty($p['featured_image'])): ?>
                                <img src="<?= $base_url ?>uploads/<?= e($p['featured_image']) ?>" alt="" loading="lazy">
                            <?php else: ?>
                                <div class="blog-card-image-fallback"><i class="fa-regular fa-newspaper"></i></div>
                            <?php endif; ?>
                        </div>
                        <div class="blog-card-body">
                            <?php if ($catNm): ?>
                                <div class="blog-card-cat"><?= e($catNm) ?></div>
                            <?php endif; ?>
                            <h3 class="blog-card-title"><?= e($title) ?></h3>
                            <p class="blog-card-excerpt"><?= e(mb_strimwidth(strip_tags($excerpt), 0, 110, '…')) ?></p>
                            <div class="blog-card-foot">
                                <span class="blog-card-date">
                                    <?php if ($p['published_at']): ?><?= format_date($p['published_at'],'M j, Y') ?><?php endif; ?>
                                </span>
                                <span class="blog-card-views">
                                    <i class="fa-regular fa-eye"></i><?= (int)$p['views'] ?>
                                </span>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($pageCount > 1): ?>
                <nav class="blog-pagination reveal">
                    <?php if ($page > 1): ?>
                        <a href="<?= blog_link($base_url, $page-1, $catFilter, $q) ?>" class="blog-page-btn">
                            <i class="fa-solid fa-arrow-<?= $dir==='rtl'?'right':'left' ?>"></i>
                            <span><?= t('prev','السابق') ?></span>
                        </a>
                    <?php endif; ?>
                    <div class="blog-page-info">
                        <span class="num"><?= $page ?></span>
                        <span class="sep">/</span>
                        <span class="total"><?= $pageCount ?></span>
                    </div>
                    <?php if ($page < $pageCount): ?>
                        <a href="<?= blog_link($base_url, $page+1, $catFilter, $q) ?>" class="blog-page-btn">
                            <span><?= t('next','التالي') ?></span>
                            <i class="fa-solid fa-arrow-<?= $arrow ?>"></i>
                        </a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>

        <?php elseif (!$featured): ?>
            <div class="empty-state reveal">
                <div class="empty-state-icon"><i class="fa-regular fa-newspaper"></i></div>
                <h3><?= $q ? t('no_posts_found','لا توجد نتائج للبحث.') : t('no_posts_yet','لا توجد مقالات منشورة حالياً.') ?></h3>
                <p><?= t('check_back_later','تابعنا قريباً لمحتوى جديد.') ?></p>
                <a href="<?= $base_url ?>blog" class="btn btn-teal"><?= t('see_all','كل المقالات') ?></a>
            </div>
        <?php endif; ?>
    </div>
</section>
