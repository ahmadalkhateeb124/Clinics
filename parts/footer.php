<footer class="site-footer bg-dark text-white pt-5 pb-3 mt-5">
    <div class="container">
        <div class="row gy-4">
            <div class="col-md-4">
                <h5 class="text-teal"><i class="fa-solid fa-spa me-2"></i><?= e(t('site_name')) ?></h5>
                <p class="small">عيادة متعددة الخدمات: علاج طبيعي، تصريف سوائل، مساج، حجامة، تقشير، استشارات طبية.</p>
                <div class="d-flex gap-2 mt-2">
                    <?php $fb = site_setting('social_facebook',''); if ($fb): ?>
                        <a href="<?= e($fb) ?>" class="text-white-50" target="_blank"><i class="fa-brands fa-facebook fa-lg"></i></a>
                    <?php endif; ?>
                    <?php $ig = site_setting('social_instagram',''); if ($ig): ?>
                        <a href="<?= e($ig) ?>" class="text-white-50" target="_blank"><i class="fa-brands fa-instagram fa-lg"></i></a>
                    <?php endif; ?>
                    <?php $wa = site_setting('contact_phone',''); if ($wa): ?>
                        <a href="<?= wa_link($wa) ?>" class="text-white-50" target="_blank"><i class="fa-brands fa-whatsapp fa-lg"></i></a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-4">
                <h6><?= t('contact') ?></h6>
                <p class="small mb-1"><i class="fa-solid fa-phone me-2"></i>
                    <a class="text-white-50" href="<?= tel_link(site_setting('contact_phone','+962700000000')) ?>"><?= e(site_setting('contact_phone', '+962 7 0000 0000')) ?></a>
                </p>
                <p class="small mb-1"><i class="fa-solid fa-envelope me-2"></i>
                    <a class="text-white-50" href="mailto:<?= e(site_setting('contact_email','info@nourstouch.com')) ?>"><?= e(site_setting('contact_email','info@nourstouch.com')) ?></a>
                </p>
                <p class="small mb-1"><i class="fa-solid fa-location-dot me-2"></i><?= e(site_setting('address', 'Amman, Jordan')) ?></p>
                <p class="small mb-1"><i class="fa-regular fa-clock me-2"></i><?= e(site_setting('working_hours_from','09:00')) ?> – <?= e(site_setting('working_hours_to','21:00')) ?></p>
            </div>
            <div class="col-md-4">
                <h6>روابط</h6>
                <ul class="list-unstyled small">
                    <li><a href="<?= $base_url ?>about"     class="text-white-50"><?= t('about') ?></a></li>
                    <li><a href="<?= $base_url ?>services"  class="text-white-50"><?= t('services') ?></a></li>
                    <li><a href="<?= $base_url ?>packages"  class="text-white-50"><?= t('packages') ?></a></li>
                    <li><a href="<?= $base_url ?>blog"      class="text-white-50"><?= t('blog') ?></a></li>
                    <li><a href="<?= $base_url ?>booking"   class="text-white-50"><?= t('book_now') ?></a></li>
                    <li><a href="<?= $base_url ?>privacy"   class="text-white-50">سياسة الخصوصية</a></li>
                    <li><a href="<?= $base_url ?>terms"     class="text-white-50">الشروط والأحكام</a></li>
                </ul>
            </div>
        </div>
        <hr class="border-secondary">
        <div class="text-center small">© <?= date('Y') ?> <?= e(t('site_name')) ?>. جميع الحقوق محفوظة.</div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/aos@2.3.4/dist/aos.js"></script>
<script>AOS.init({ duration: 700, once: true });</script>
</body>
</html>
