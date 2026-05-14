<?php
$PageTitle = t('therapists') . ' — ' . t('site_name');
$escapedDescription = "تعرّف على فريق المعالجين والأطباء في عيادة لمسة نور.";

global $pdo;
$therapists = [];
if ($pdo instanceof PDO) {
    try {
        $therapists = $pdo->query("
            SELECT id, first_name, first_name_en, last_name, last_name_en,
                   job_title, job_title_en, department, department_en,
                   bio_ar, bio_en, avatar
            FROM employees
            WHERE deleted_at IS NULL AND is_active = 1 AND show_on_site = 1
            ORDER BY id DESC
        ")->fetchAll();
    } catch (Throwable $e) {}
}

$arrow = $dir === 'rtl' ? 'left' : 'right';
?>

<!-- ════════ PAGE HERO ════════ -->
<section class="hero" style="padding-bottom:2rem">
    <div class="container">
        <div class="page-hero reveal">
            <div class="page-hero-content">
                <span class="tag"><i class="fa-solid fa-user-doctor"></i><?= t('our_team', 'فريقنا') ?></span>
                <h1 class="hero-h1" style="font-size:clamp(2.2rem,4.5vw,3.8rem);margin:1.25rem 0 1.25rem">
                    <?= t('team_h', 'أيدي خبيرة، <em>وقلوب صادقة</em>.') ?>
                </h1>
                <p class="h-lead" style="margin-bottom:0;max-width:62ch">
                    <?= t('team_lead', 'تعرّف على فريقنا من المعالجين والمستشارين — كل واحد منهم يحمل خبرة طويلة وشغفاً صادقاً برحلة شفائك.') ?>
                </p>
            </div>
            <ul class="page-hero-crumbs">
                <li><a href="<?= $base_url ?>"><?= t('home') ?></a></li>
                <li>·</li>
                <li><?= t('therapists') ?></li>
            </ul>
        </div>
    </div>
</section>

<!-- ════════ TEAM GRID ════════ -->
<section class="section" style="padding-top:0">
    <div class="container">
        <?php if ($therapists): ?>
            <!-- Stats line -->
            <div class="services-stats reveal">
                <div>
                    <span class="services-stats-num"><?= count($therapists) ?></span>
                    <span class="services-stats-label">
                        <?= t('team_members_label', 'مختصّاً في خدمتك') ?>
                    </span>
                </div>
                <div class="services-stats-divider"></div>
                <div class="services-stats-side">
                    <i class="fa-solid fa-shield-halved"></i>
                    <?= t('team_certified_line', 'كلهم حاصلون على ترخيص رسمي وخبرة طويلة') ?>
                </div>
            </div>

            <div class="team-cards reveal">
                <?php foreach ($therapists as $tp):
                    $firstName = tr($tp, 'first_name');
                    $lastName  = tr($tp, 'last_name');
                    $name = trim($firstName . ' ' . $lastName) ?: '—';
                    $role = tr($tp, 'job_title') ?: tr($tp, 'department');
                    $bio  = tr($tp, 'bio');
                    $initials = mb_strtoupper(mb_substr($firstName, 0, 1) . mb_substr($lastName, 0, 1));
                ?>
                    <div class="team-card">
                        <div class="team-card-photo">
                            <?php if (!empty($tp['avatar'])): ?>
                                <img src="<?= $base_url ?>uploads/<?= e($tp['avatar']) ?>" alt="<?= e($name) ?>">
                            <?php elseif ($initials): ?>
                                <div class="team-card-photo-initials"><?= e($initials) ?></div>
                            <?php else: ?>
                                <div class="team-card-photo-fallback"><i class="fa-solid fa-user-doctor"></i></div>
                            <?php endif; ?>
                        </div>
                        <div class="team-card-body">
                            <?php if ($role): ?>
                                <div class="team-card-role"><?= e($role) ?></div>
                            <?php endif; ?>
                            <h3 class="team-card-name"><?= e($name) ?></h3>
                            <?php if ($bio): ?>
                                <p class="team-card-bio"><?= e(mb_strimwidth($bio, 0, 180, '…')) ?></p>
                            <?php endif; ?>
                            <div class="team-card-foot">
                                <span class="team-card-badge"><i class="fa-solid fa-check"></i><?= t('certified', 'معتمد') ?></span>
                                <a href="<?= $base_url ?>booking?therapist=<?= (int)$tp['id'] ?>" class="team-card-link">
                                    <?= t('book_with', 'احجز معه') ?>
                                    <i class="fa-solid fa-arrow-<?= $arrow ?>"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state reveal">
                <div class="empty-state-icon"><i class="fa-solid fa-user-doctor"></i></div>
                <h3><?= t('no_team_yet', 'لا يوجد أعضاء فريق معروضون حالياً.') ?></h3>
                <p><?= t('team_check_back', 'تواصل معنا لمعرفة المزيد عن فريق العيادة.') ?></p>
                <a href="<?= $base_url ?>contact" class="btn btn-teal"><?= t('contact') ?></a>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- ════════ VISIT CTA ════════ -->
<section class="section" style="padding-top:0">
    <div class="container">
        <div class="visit-card reveal">
            <div class="visit-body">
                <span class="tag"><i class="fa-solid fa-handshake"></i><?= t('one_team', 'فريق واحد، نتيجة واحدة') ?></span>
                <h2><?= t('team_cta_h', 'هل تبحث عن <em>المعالج المناسب</em>؟') ?></h2>
                <p><?= t('team_cta_p', 'احجز جلسة تقييم مجانية، نُحدّد لك المعالج الأنسب لحالتك.') ?></p>

                <ul class="visit-info">
                    <li><i class="fa-solid fa-phone"></i><div dir="ltr"><a href="<?= tel_link(site_setting('contact_phone','+962700000000')) ?>" style="color:#fff"><?= e(site_setting('contact_phone', '+962 7 0000 0000')) ?></a></div></li>
                    <li><i class="fa-regular fa-envelope"></i><div dir="ltr"><a href="mailto:<?= e(site_setting('contact_email','info@nourstouch.com')) ?>" style="color:#fff"><?= e(site_setting('contact_email','info@nourstouch.com')) ?></a></div></li>
                </ul>
            </div>
            <div class="visit-aside">
                <div class="visit-aside-eyebrow"><?= t('start_today', 'ابدأ اليوم') ?></div>
                <h3><?= t('book_session_now', 'احجز جلستك<br>خلال دقيقة.') ?></h3>
                <a href="<?= $base_url ?>booking" class="btn btn-light">
                    <i class="fa-regular fa-calendar-check"></i>
                    <?= t('book_now') ?>
                </a>
            </div>
        </div>
    </div>
</section>
