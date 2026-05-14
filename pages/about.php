<?php
$PageTitle = 'من نحن — ' . t('site_name');
$escapedDescription = "تعرّف على عيادة لمسة نور — رؤيتنا، رسالتنا، وفريقنا من المعالجين المعتمدين.";

global $pdo;
$page = null; $faqs = [];
if ($pdo instanceof PDO) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM pages WHERE slug='about' AND is_published=1 AND deleted_at IS NULL LIMIT 1");
        $stmt->execute(); $page = $stmt->fetch();
        $faqs = $pdo->query("SELECT * FROM faqs WHERE deleted_at IS NULL AND is_active=1 ORDER BY sort_order LIMIT 8")->fetchAll();
    } catch (Throwable $e) {}
}
?>
<section class="hero-section py-5 text-center">
    <div class="container" data-aos="fade-up">
        <h1 class="text-teal"><?= e($page['title_ar'] ?? 'من نحن') ?></h1>
        <p class="lead text-muted"><?= e(t('site_name')) ?></p>
    </div>
</section>

<section class="py-5">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-8" data-aos="fade-up">
                <?php if ($page && $page['content_ar']): ?>
                    <?= $page['content_ar'] ?>
                <?php else: ?>
                    <p>عيادة لمسة نور هي عيادة متعددة الخدمات تقدّم العلاج الطبيعي، تصريف السوائل، أنواع المساج المتعددة، الحجامة، التقشير والاستشارات الطبية المتخصصة بأيدي فريق من المعالجين المعتمدين.</p>
                <?php endif; ?>
            </div>
            <div class="col-lg-4">
                <div class="card p-4 bg-soft">
                    <h5 class="text-teal">لماذا تختارنا؟</h5>
                    <ul class="list-unstyled small">
                        <li class="mb-2"><i class="fa-solid fa-check text-teal me-2"></i>معالجون معتمدون وذوو خبرة</li>
                        <li class="mb-2"><i class="fa-solid fa-check text-teal me-2"></i>بيئة آمنة ومعقّمة</li>
                        <li class="mb-2"><i class="fa-solid fa-check text-teal me-2"></i>برامج علاج مخصصة لكل حالة</li>
                        <li class="mb-2"><i class="fa-solid fa-check text-teal me-2"></i>أسعار مناسبة وباقات مرنة</li>
                        <li class="mb-2"><i class="fa-solid fa-check text-teal me-2"></i>مواعيد مرنة وخدمة احترافية</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<?php if ($faqs): ?>
<section class="py-5 bg-soft">
    <div class="container">
        <h2 class="section-title text-center">الأسئلة الشائعة</h2>
        <p class="section-subtitle text-center">إجابات مختصرة على أكثر ما يسأل عنه عملاؤنا</p>
        <div class="accordion mx-auto" id="faqs" style="max-width:800px">
            <?php foreach ($faqs as $i => $f): ?>
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button <?= $i>0?'collapsed':'' ?>" data-bs-toggle="collapse" data-bs-target="#faq<?= (int)$f['id'] ?>">
                            <?= e($f['question_ar']) ?>
                        </button>
                    </h2>
                    <div id="faq<?= (int)$f['id'] ?>" class="accordion-collapse collapse <?= $i===0?'show':'' ?>" data-bs-parent="#faqs">
                        <div class="accordion-body small text-muted"><?= nl2br(e($f['answer_ar'])) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>
