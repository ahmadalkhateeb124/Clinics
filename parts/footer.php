<?php
$footerLogo = site_setting('clinic_logo', '');
$footerName = ($lang === 'en')
    ? (site_setting('site_name_en', '') ?: site_setting('site_name_ar', '') ?: t('site_name'))
    : (site_setting('site_name_ar', '') ?: site_setting('site_name_en', '') ?: t('site_name'));
?>

<footer class="site-footer">
    <div class="container">

        <div class="site-footer-top">
            <!-- Brand & tagline -->
            <div class="footer-brand">
                <?php if ($footerLogo): ?>
                    <img src="<?= $base_url ?>uploads/<?= e($footerLogo) ?>" alt="<?= e($footerName) ?>" class="footer-brand-logo">
                <?php else: ?>
                    <span class="footer-brand-name"><?= e($footerName) ?></span>
                <?php endif; ?>
                <p class="footer-tagline"><?= t('footer_about', 'علاج طبيعي · مساج · حجامة · تقشير · استشارات. كل ذلك بأيدٍ خبيرة، ولمسة دافئة.') ?></p>
                <div class="footer-social">
                    <?php foreach (social_active_links() as $s): ?>
                        <a href="<?= e($s['url']) ?>" target="_blank" rel="noopener" aria-label="<?= e($s['label']) ?>"><i class="<?= e($s['icon']) ?>"></i></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Explore -->
            <div class="footer-col">
                <h6><?= t('explore', 'استكشف') ?></h6>
                <ul>
                    <li><a href="<?= $base_url ?>about"><?= t('about') ?></a></li>
                    <li><a href="<?= $base_url ?>services"><?= t('services') ?></a></li>
                    <li><a href="<?= $base_url ?>therapists"><?= t('therapists') ?></a></li>
                    <li><a href="<?= $base_url ?>packages"><?= t('packages') ?></a></li>
                    <li><a href="<?= $base_url ?>blog"><?= t('blog') ?></a></li>
                </ul>
            </div>

            <!-- Visit -->
            <div class="footer-col">
                <h6><?= t('visit', 'زرنا') ?></h6>
                <ul>
                    <li><a href="<?= $base_url ?>contact"><?= t('contact') ?></a></li>
                    <li><a href="<?= $base_url ?>booking"><?= t('book_now') ?></a></li>
                    <li><a href="<?= $base_url ?>gallery"><?= t('gallery', 'Gallery') ?></a></li>
                    <li><a href="<?= $base_url ?>BusinessPortal/auth/login.php"><i class="fa-solid fa-lock" style="font-size:.72rem;margin-inline-end:.35rem;opacity:.7"></i><?= t('staff_login','تسجيل دخول الفريق') ?></a></li>
                </ul>
            </div>

            <!-- Contact -->
            <div class="footer-col">
                <h6><?= t('contact') ?></h6>
                <?php
                $fAddress = trim(site_setting('address', ''));
                $fPhone   = trim(site_setting('contact_phone', ''));
                $fEmail   = trim(site_setting('contact_email', ''));
                $fFrom    = trim(site_setting('working_hours_from', ''));
                $fTo      = trim(site_setting('working_hours_to', ''));
                ?>
                <ul class="footer-contact">
                    <?php if ($fAddress): ?>
                        <li>
                            <i class="fa-solid fa-location-dot"></i>
                            <?= e($fAddress) ?>
                        </li>
                    <?php endif; ?>
                    <?php if ($fPhone): ?>
                        <li>
                            <i class="fa-solid fa-phone"></i>
                            <a href="<?= tel_link($fPhone) ?>" dir="ltr"><?= e($fPhone) ?></a>
                        </li>
                    <?php endif; ?>
                    <?php if ($fEmail): ?>
                        <li>
                            <i class="fa-regular fa-envelope"></i>
                            <a href="mailto:<?= e($fEmail) ?>" dir="ltr"><?= e($fEmail) ?></a>
                        </li>
                    <?php endif; ?>
                    <?php if ($fFrom && $fTo): ?>
                        <li>
                            <i class="fa-regular fa-clock"></i>
                            <span dir="ltr"><?= e($fFrom) ?> – <?= e($fTo) ?></span>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <div class="site-footer-bottom">
            <div>© <?= date('Y') ?> <?= e($footerName) ?>. <?= t('all_rights_reserved', 'جميع الحقوق محفوظة.') ?></div>
            <a class="footer-powered" href="https://webkoit.com" target="_blank" rel="noopener" aria-label="Webkoit">
                <span><?= t('powered_by', 'مدعوم من') ?></span>
                <img src="<?= $base_url ?>assets/img/logo-webkoit.png" alt="Webkoit">
            </a>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
