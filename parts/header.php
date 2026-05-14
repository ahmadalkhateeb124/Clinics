<?php
$PageTitle           = $PageTitle ?? (t('site_name') . ' — ' . t('home'));
$escapedDescription  = $escapedDescription ?? "عيادة لمسة نور — علاج طبيعي، تصريف سوائل، مساج، حجامة، تقشير، استشارات.";
$KeyWords            = $KeyWords ?? "علاج طبيعي, مساج, حجامة, تقشير, استشارات, لمسة نور, عمان, الأردن";
$ogImage             = $ogImage  ?? ($base_url . 'assets/img/og-default.jpg');

// Current path used for active nav highlighting
$currentPath = $_GET['url'] ?? '/';
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

    <!-- Schema.org MedicalBusiness -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "MedicalBusiness",
      "name": "<?= e(t('site_name')) ?>",
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

    <!-- Bootstrap 5 RTL/LTR -->
    <?php if ($dir === 'rtl'): ?>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
    <?php else: ?>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800&display=swap">
    <link rel="stylesheet" href="https://unpkg.com/aos@2.3.4/dist/aos.css">
    <link rel="stylesheet" href="<?= $base_url ?>assets/css/style.css">
</head>
<body>

<header class="site-header sticky-top bg-white shadow-sm">
    <nav class="navbar navbar-expand-lg container py-2">
        <a class="navbar-brand fw-bold text-teal" href="<?= $base_url ?>">
            <i class="fa-solid fa-spa me-2"></i><?= e(t('site_name')) ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav mx-auto">
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
                    <li class="nav-item">
                        <a class="nav-link <?= $active ?>" href="<?= $href ?>"><?= e($label) ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="d-flex align-items-center gap-2">
                <a href="?lang=<?= $lang === 'ar' ? 'en' : 'ar' ?>" class="btn btn-sm btn-outline-secondary">
                    <?= $lang === 'ar' ? 'EN' : 'ع' ?>
                </a>
                <a href="<?= $base_url ?>booking" class="btn btn-teal btn-sm">
                    <i class="fa-regular fa-calendar-check me-1"></i><?= t('book_now') ?>
                </a>
            </div>
        </div>
    </nav>
</header>
