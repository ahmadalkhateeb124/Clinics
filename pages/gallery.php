<?php
$PageTitle = t('gallery') . ' — ' . t('site_name');
$escapedDescription = "صور من عيادة لمسة نور — جولة بصرية في غرفنا ومرافقنا.";

global $pdo;
$photos = [];
$categories = [];
$activeCat  = trim($_GET['cat'] ?? '');

if ($pdo instanceof PDO) {
    try {
        // Distinct active categories
        $categories = $pdo->query("
            SELECT category, COUNT(*) AS n
            FROM gallery
            WHERE deleted_at IS NULL AND is_active=1 AND category IS NOT NULL AND category <> ''
            GROUP BY category
            ORDER BY category
        ")->fetchAll();

        $sql = "SELECT * FROM gallery WHERE deleted_at IS NULL AND is_active=1";
        $params = [];
        if ($activeCat !== '') { $sql .= " AND category = ?"; $params[] = $activeCat; }
        $sql .= " ORDER BY sort_order, id DESC";
        $st = $pdo->prepare($sql); $st->execute($params);
        $photos = $st->fetchAll();
    } catch (Throwable $e) {}
}

$totalCount = (int)($pdo?->query("SELECT COUNT(*) FROM gallery WHERE deleted_at IS NULL AND is_active=1")->fetchColumn() ?? 0);
$arrow = $dir === 'rtl' ? 'left' : 'right';
?>

<!-- ════════ PAGE HERO ════════ -->
<section class="hero" style="padding-bottom:2rem">
    <div class="container">
        <div class="page-hero reveal">
            <div class="page-hero-content">
                <span class="tag"><i class="fa-regular fa-image"></i><?= t('gallery', 'المعرض') ?></span>
                <h1 class="hero-h1" style="font-size:clamp(2.2rem,4.5vw,3.8rem);margin:1.25rem 0 1.25rem">
                    <?= t('gallery_h', 'جولة بصرية في <em>عيادتنا</em>.') ?>
                </h1>
                <p class="h-lead" style="margin-bottom:0;max-width:62ch">
                    <?= t('gallery_lead', 'لقطات من غرف الجلسات، المرافق، وأجواء العمل اليومية — كلّها مصمّمة لراحتك.') ?>
                </p>
            </div>
            <ul class="page-hero-crumbs">
                <li><a href="<?= $base_url ?>"><?= t('home') ?></a></li>
                <li>·</li>
                <li><?= t('gallery') ?></li>
            </ul>
        </div>
    </div>
</section>

<!-- ════════ CATEGORY FILTER ════════ -->
<?php if ($categories): ?>
<section class="section tight" style="padding:0">
    <div class="container">
        <div class="cat-filter reveal">
            <div class="cat-filter-scroll">
                <a href="<?= $base_url ?>gallery" class="cat-chip <?= $activeCat===''?'is-active':'' ?>">
                    <span class="cat-chip-label"><?= t('all','الكل') ?></span>
                    <span class="cat-chip-count"><?= $totalCount ?></span>
                </a>
                <?php foreach ($categories as $c): ?>
                    <a href="<?= $base_url ?>gallery?cat=<?= urlencode($c['category']) ?>" class="cat-chip <?= $activeCat===$c['category']?'is-active':'' ?>">
                        <span class="cat-chip-label"><?= e($c['category']) ?></span>
                        <span class="cat-chip-count"><?= (int)$c['n'] ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ════════ GALLERY GRID ════════ -->
<section class="section">
    <div class="container">
        <?php if ($photos): ?>
            <div class="gallery-stats reveal">
                <div>
                    <span class="services-stats-num"><?= count($photos) ?></span>
                    <span class="services-stats-label">
                        <?= $activeCat
                            ? sprintf('%s · %s', e($activeCat), t('photos','صورة'))
                            : t('total_photos', 'صورة في المعرض') ?>
                    </span>
                </div>
                <div class="services-stats-divider"></div>
                <div class="services-stats-side">
                    <i class="fa-regular fa-image"></i>
                    <?= t('gallery_tip', 'انقر أي صورة لعرضها بحجم كامل') ?>
                </div>
            </div>

            <div class="gallery-grid reveal" id="galleryGrid">
                <?php foreach ($photos as $i => $p):
                    $url = $base_url . 'uploads/' . $p['image'];
                    // Vary tile size for masonry feel (every 5th is tall)
                    $tall = ($i % 5 === 2);
                ?>
                    <a class="gallery-tile <?= $tall?'is-tall':'' ?>" href="<?= e($url) ?>"
                       data-caption="<?= e($p['title'] ?? '') ?>"
                       data-cat="<?= e($p['category'] ?? '') ?>">
                        <img src="<?= e($url) ?>" alt="<?= e($p['title'] ?? '') ?>" loading="lazy">
                        <div class="gallery-tile-overlay">
                            <?php if (!empty($p['category'])): ?>
                                <div class="gallery-tile-cat"><?= e($p['category']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($p['title'])): ?>
                                <div class="gallery-tile-title"><?= e($p['title']) ?></div>
                            <?php endif; ?>
                            <div class="gallery-tile-expand"><i class="fa-solid fa-expand"></i></div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state reveal">
                <div class="empty-state-icon"><i class="fa-regular fa-image"></i></div>
                <h3><?= t('no_photos_yet', 'لا توجد صور في هذه الفئة حالياً.') ?></h3>
                <p><?= t('photos_check_back', 'تصفّح كل الصور أو زر عيادتنا.') ?></p>
                <a href="<?= $base_url ?>gallery" class="btn btn-teal"><?= t('see_all','كل الصور') ?></a>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- ════════ VISIT CTA ════════ -->
<section class="section" style="padding-top:0">
    <div class="container">
        <div class="visit-card reveal">
            <div class="visit-body">
                <span class="tag"><i class="fa-solid fa-location-dot"></i><?= t('visit','زرنا') ?></span>
                <h2><?= t('gallery_cta_h', 'الصور تروي قصّة — <em>التجربة تكتمل عندك</em>.') ?></h2>
                <p><?= t('gallery_cta_p', 'احجز جلستك واختبر بنفسك الجوّ الذي تراه في هذه الصور.') ?></p>
                <ul class="visit-info">
                    <li><i class="fa-solid fa-location-dot"></i><div><?= e(site_setting('address', 'عمّان، الأردن')) ?></div></li>
                    <li><i class="fa-solid fa-phone"></i><div dir="ltr"><a href="<?= tel_link(site_setting('contact_phone','+962700000000')) ?>" style="color:#fff"><?= e(site_setting('contact_phone', '+962 7 0000 0000')) ?></a></div></li>
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

<!-- ════════ LIGHTBOX ════════ -->
<div id="lightbox" class="lightbox" hidden>
    <button class="lightbox-close" type="button" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    <button class="lightbox-nav lightbox-prev" type="button" aria-label="Previous"><i class="fa-solid fa-chevron-<?= $dir==='rtl'?'right':'left' ?>"></i></button>
    <button class="lightbox-nav lightbox-next" type="button" aria-label="Next"><i class="fa-solid fa-chevron-<?= $dir==='rtl'?'left':'right' ?>"></i></button>
    <figure class="lightbox-frame">
        <img id="lightboxImg" src="" alt="">
        <figcaption id="lightboxCap"></figcaption>
    </figure>
    <div class="lightbox-count" id="lightboxCount"></div>
</div>

<script>
(function(){
    const grid  = document.getElementById('galleryGrid');
    if (!grid) return;
    const tiles = [...grid.querySelectorAll('.gallery-tile')];
    const box   = document.getElementById('lightbox');
    const img   = document.getElementById('lightboxImg');
    const cap   = document.getElementById('lightboxCap');
    const cnt   = document.getElementById('lightboxCount');
    let i = 0;

    function show(idx){
        i = (idx + tiles.length) % tiles.length;
        const t = tiles[i];
        img.src = t.href;
        const c = t.dataset.caption || '';
        const cat = t.dataset.cat || '';
        cap.innerHTML = (cat ? `<span class="lightbox-cap-cat">${cat}</span>` : '') + (c ? `<span class="lightbox-cap-title">${c}</span>` : '');
        cnt.textContent = `${i+1} / ${tiles.length}`;
        box.hidden = false;
        document.body.style.overflow = 'hidden';
    }
    function close(){ box.hidden = true; document.body.style.overflow=''; }

    tiles.forEach((t, idx) => {
        t.addEventListener('click', e => { e.preventDefault(); show(idx); });
    });
    box.querySelector('.lightbox-close').addEventListener('click', close);
    box.addEventListener('click', e => { if (e.target === box) close(); });
    box.querySelector('.lightbox-prev').addEventListener('click', () => show(i-1));
    box.querySelector('.lightbox-next').addEventListener('click', () => show(i+1));
    document.addEventListener('keydown', e => {
        if (box.hidden) return;
        if (e.key === 'Escape') close();
        if (e.key === 'ArrowRight') show(<?= $dir==='rtl'?'i-1':'i+1' ?>);
        if (e.key === 'ArrowLeft')  show(<?= $dir==='rtl'?'i+1':'i-1' ?>);
    });
})();
</script>
