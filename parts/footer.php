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
                    <?php $fb = site_setting('social_facebook',''); if ($fb): ?>
                        <a href="<?= e($fb) ?>" target="_blank" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
                    <?php endif; ?>
                    <?php $ig = site_setting('social_instagram',''); if ($ig): ?>
                        <a href="<?= e($ig) ?>" target="_blank" aria-label="Instagram"><i class="fa-brands fa-instagram"></i></a>
                    <?php endif; ?>
                    <?php $wa = site_setting('contact_phone',''); if ($wa): ?>
                        <a href="<?= wa_link($wa) ?>" target="_blank" aria-label="WhatsApp"><i class="fa-brands fa-whatsapp"></i></a>
                    <?php endif; ?>
                    <a href="mailto:<?= e(site_setting('contact_email','info@nourstouch.com')) ?>" aria-label="Email"><i class="fa-regular fa-envelope"></i></a>
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
                <ul class="footer-contact">
                    <li>
                        <i class="fa-solid fa-location-dot"></i>
                        <?= e(site_setting('address', 'Amman, Jordan')) ?>
                    </li>
                    <li>
                        <i class="fa-solid fa-phone"></i>
                        <a href="<?= tel_link(site_setting('contact_phone','+962700000000')) ?>" dir="ltr"><?= e(site_setting('contact_phone', '+962 7 0000 0000')) ?></a>
                    </li>
                    <li>
                        <i class="fa-regular fa-envelope"></i>
                        <a href="mailto:<?= e(site_setting('contact_email','info@nourstouch.com')) ?>" dir="ltr"><?= e(site_setting('contact_email','info@nourstouch.com')) ?></a>
                    </li>
                    <li>
                        <i class="fa-regular fa-clock"></i>
                        <span dir="ltr"><?= e(site_setting('working_hours_from','09:00')) ?> – <?= e(site_setting('working_hours_to','21:00')) ?></span>
                    </li>
                </ul>
            </div>
        </div>

        <div class="site-footer-bottom">
            <div>© <?= date('Y') ?> <?= e($footerName) ?>. <?= t('all_rights_reserved', 'جميع الحقوق محفوظة.') ?></div>
            <div><?= t('crafted_in', 'صُنع بعناية في عمّان.') ?></div>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
