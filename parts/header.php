<?php
$PageTitle           = $PageTitle ?? (t('site_name') . ' — ' . t('home'));
$escapedDescription  = $escapedDescription ?? "عيادة لمسة نور — علاج طبيعي، تصريف سوائل، مساج، حجامة، تقشير، استشارات.";
$KeyWords            = $KeyWords ?? "علاج طبيعي, مساج, حجامة, تقشير, استشارات, لمسة نور, عمان, الأردن";
$ogImage             = $ogImage  ?? ($base_url . 'assets/img/og-default.jpg');

$currentPath = $_GET['url'] ?? '/';

// Brand from admin settings
$siteLogo = site_setting('clinic_logo', '');
$siteName = ($lang === 'en')
    ? (site_setting('site_name_en', '') ?: site_setting('site_name_ar', '') ?: t('site_name'))
    : (site_setting('site_name_ar', '') ?: site_setting('site_name_en', '') ?: t('site_name'));
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($PageTitle) ?></title>
    <meta name="description" content="<?= $escapedDescription ?>">
    <meta name="keywords" content="<?= $KeyWords ?>">
    <link rel="canonical" href="<?= $base_url . ($currentPath !== '/' ? e($currentPath) : '') ?>">

    <meta property="og:type"        content="website">
    <meta property="og:title"       content="<?= e($PageTitle) ?>">
    <meta property="og:description" content="<?= $escapedDescription ?>">
    <meta property="og:image"       content="<?= e($ogImage) ?>">
    <meta property="og:url"         content="<?= $base_url ?>">
    <meta name="twitter:card"       content="summary_large_image">

    <link rel="icon" href="<?= $base_url ?>assets/img/favicon.ico">

    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "MedicalBusiness",
      "name": "<?= e($siteName) ?>",
      "url": "<?= $base_url ?>",
      "image": "<?= $ogImage ?>",
      "telephone": "<?= e(site_setting('contact_phone', '')) ?>",
      "email": "<?= e(site_setting('contact_email', '')) ?>",
      "address": {
        "@type": "PostalAddress",
        "streetAddress": "<?= e(site_setting('address', '')) ?>",
        "addressCountry": "JO"
      },
      "openingHours": "Mo-Sa <?= e(site_setting('working_hours_from','09:00')) ?>-<?= e(site_setting('working_hours_to','21:00')) ?>",
      "priceRange": "JOD"
    }
    </script>

    <?php if ($dir === 'rtl'): ?>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
    <?php else: ?>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800&family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400;1,500&display=swap">
    <link rel="stylesheet" href="<?= $base_url ?>assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?>">
</head>
<body>

<header class="site-header" id="siteHeader">
    <div class="container">
        <div class="site-header-bar">
        <a class="brand" href="<?= $base_url ?>">
            <?php if ($siteLogo): ?>
                <img src="<?= $base_url ?>uploads/<?= e($siteLogo) ?>" alt="<?= e($siteName) ?>" class="brand-logo">
            <?php else: ?>
                <i class="brand-icon fa-solid fa-spa"></i>
                <span class="brand-name"><?= e($siteName) ?></span>
            <?php endif; ?>
        </a>

        <nav>
            <ul class="site-nav" id="siteNav">
                <?php
                $navItems = [
                    '/'           => t('home'),
                    'about'       => t('about'),
                    'services'    => t('services'),
                    'therapists'  => t('therapists'),
                    'packages'    => t('packages'),
                    'gallery'     => t('gallery', 'Gallery'),
                    'blog'        => t('blog'),
                    'contact'     => t('contact'),
                ];
                foreach ($navItems as $slug => $label):
                    $href = $slug === '/' ? $base_url : $base_url . $slug;
                    $active = ($currentPath === $slug || ($slug === '/' && in_array($currentPath, ['/', 'Home', 'home']))) ? 'active' : '';
                ?>
                    <li><a class="<?= $active ?>" href="<?= $href ?>"><?= e($label) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </nav>

        <div class="header-cta" id="headerCta">
            <div class="lang-dd" id="langDd">
                <button type="button" class="lang-dd-trigger" aria-haspopup="listbox" aria-expanded="false">
                    <span class="lang-dd-current" lang="<?= $lang ?>">
                        <?= $lang === 'ar' ? 'العربية' : 'English' ?>
                    </span>
                    <span class="lang-dd-caret" aria-hidden="true"></span>
                </button>
                <div class="lang-dd-menu" role="listbox">
                    <a href="?lang=ar" class="lang-dd-item <?= $lang === 'ar' ? 'is-active' : '' ?>" lang="ar" role="option">
                        <span class="lang-dd-label">العربية</span>
                        <span class="lang-dd-short">AR</span>
                    </a>
                    <a href="?lang=en" class="lang-dd-item <?= $lang === 'en' ? 'is-active' : '' ?>" lang="en" role="option">
                        <span class="lang-dd-label">English</span>
                        <span class="lang-dd-short">EN</span>
                    </a>
                </div>
            </div>
            <a href="<?= $base_url ?>booking" class="btn-book-mini">
                <?= t('book_now') ?>
            </a>
        </div>

        <button class="nav-toggle" id="navToggle" type="button" aria-label="Menu" aria-expanded="false">
            <i class="fa-solid fa-bars"></i>
        </button>
        </div>
    </div>
</header>

<script>
// Sticky header glass effect
(function(){
    const h = document.getElementById('siteHeader');
    if (!h) return;
    const onScroll = () => h.classList.toggle('is-scrolled', window.scrollY > 30);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
})();
// Mobile drawer toggle
(function(){
    const btn = document.getElementById('navToggle');
    const nav = document.getElementById('siteNav');
    const cta = document.getElementById('headerCta');
    if (!btn || !nav) return;
    btn.addEventListener('click', () => {
        const open = nav.classList.toggle('is-open');
        cta?.classList.toggle('is-open', open);
        btn.classList.toggle('is-open', open);
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        document.body.style.overflow = open ? 'hidden' : '';
    });
    // Close drawer when a link is clicked
    nav.addEventListener('click', e => {
        if (e.target.tagName === 'A') {
            nav.classList.remove('is-open');
            cta?.classList.remove('is-open');
            btn.classList.remove('is-open');
            btn.setAttribute('aria-expanded', 'false');
            document.body.style.overflow = '';
        }
    });
    // Close on resize to desktop
    window.addEventListener('resize', () => {
        if (window.innerWidth > 991 && nav.classList.contains('is-open')) {
            nav.classList.remove('is-open');
            cta?.classList.remove('is-open');
            btn.classList.remove('is-open');
            btn.setAttribute('aria-expanded', 'false');
            document.body.style.overflow = '';
        }
    });
})();
// Language dropdown
(function(){
    const dd = document.getElementById('langDd');
    if (!dd) return;
    const trigger = dd.querySelector('.lang-dd-trigger');
    trigger.addEventListener('click', e => {
        e.stopPropagation();
        const open = dd.classList.toggle('is-open');
        trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('click', e => {
        if (!dd.contains(e.target)) {
            dd.classList.remove('is-open');
            trigger.setAttribute('aria-expanded', 'false');
        }
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && dd.classList.contains('is-open')) {
            dd.classList.remove('is-open');
            trigger.setAttribute('aria-expanded', 'false');
            trigger.focus();
        }
    });
})();
// Reveal on scroll
(function(){
    const io = new IntersectionObserver((entries) => {
        entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); }});
    }, { threshold: 0.12, rootMargin: '0px 0px -60px 0px' });
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.reveal').forEach(el => io.observe(el));
    });
})();
</script>
