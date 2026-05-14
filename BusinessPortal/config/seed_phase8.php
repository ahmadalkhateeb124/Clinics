<?php
// ════════════════════════════════════════════════════════════════════════
// Phase 8 seeder — sample CMS content
// Run: php BusinessPortal/config/seed_phase8.php
// ════════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/config.php';
$pdo = db();
$adminId = (int)$pdo->query("SELECT id FROM users WHERE email='admin@clinic.com' LIMIT 1")->fetchColumn();
if (!$adminId) { fwrite(STDERR, "Run seed.php first.\n"); exit(1); }

echo "▸ Sliders…\n";
$sl = $pdo->prepare("INSERT IGNORE INTO sliders (title_ar,title_en,subtitle_ar,subtitle_en,link_url,link_text,is_active,sort_order,created_at,updated_at)
    VALUES (?,?,?,?,?,?,1,?,NOW(),NOW())");
$sl->execute(["لمسة نور للعلاج والعناية","Nour's Touch Therapy","علاج طبيعي · مساج · حجامة · تقشير · استشارات","Physical therapy · Massage · Cupping · Scrubs · Consultations","services","تصفّح خدماتنا",1]);
$sl->execute(["باقات الجلسات بأسعار مميزة","Session packages at great prices","وفّر أكثر مع باقاتنا المرنة","Save more with our flexible packages","packages","عرض الباقات",2]);

echo "▸ Blog categories…\n";
$bc = $pdo->prepare("INSERT IGNORE INTO blog_categories (name_ar,name_en,slug,sort_order,is_active,created_at,updated_at) VALUES (?,?,?,?,1,NOW(),NOW())");
$bc->execute(['نصائح صحية','Health tips','health-tips',1]);
$bc->execute(['العلاج الطبيعي','Physical therapy','physical-therapy-blog',2]);
$bc->execute(['المساج والاسترخاء','Massage & relaxation','massage-relax',3]);

$catTip   = (int)$pdo->query("SELECT id FROM blog_categories WHERE slug='health-tips' LIMIT 1")->fetchColumn();
$catPT    = (int)$pdo->query("SELECT id FROM blog_categories WHERE slug='physical-therapy-blog' LIMIT 1")->fetchColumn();
$catMass  = (int)$pdo->query("SELECT id FROM blog_categories WHERE slug='massage-relax' LIMIT 1")->fetchColumn();

echo "▸ Blog posts…\n";
$bp = $pdo->prepare("INSERT IGNORE INTO blog_posts (category_id,author_id,title_ar,title_en,slug,excerpt_ar,content_ar,status,published_at,created_by,created_at,updated_at)
    VALUES (?,?,?,?,?,?,?, 'published', NOW(), ?, NOW(), NOW())");
$bp->execute([$catPT,$adminId,
    "أهمية العلاج الطبيعي بعد الإصابات الرياضية",
    "The importance of physical therapy after sports injuries",
    "physical-therapy-after-sports-injuries",
    "لماذا يعتبر العلاج الطبيعي ضرورياً بعد الإصابات الرياضية؟",
    "<p>يلعب العلاج الطبيعي دوراً محورياً في تعافي الرياضيين بعد الإصابات. تساعد جلسات العلاج الطبيعي على:</p><ul><li>تخفيف الألم والالتهاب</li><li>استعادة المرونة والقوة</li><li>منع الإصابات المستقبلية</li><li>تسريع العودة إلى النشاط الرياضي</li></ul><p>في عيادة لمسة نور، يضع فريقنا خطة علاج مخصصة لكل حالة لضمان أفضل النتائج.</p>",
    $adminId]);
$bp->execute([$catMass,$adminId,
    "فوائد المساج السويدي للاسترخاء",
    "Benefits of Swedish massage for relaxation",
    "swedish-massage-benefits",
    "كيف يساعد المساج السويدي على تخفيف التوتر وتحسين الصحة العامة؟",
    "<p>المساج السويدي من أكثر أنواع المساج شعبية ويتميّز بحركاته اللطيفة والمنتظمة. من فوائده:</p><ul><li>تخفيف توتر العضلات</li><li>تحسين الدورة الدموية</li><li>زيادة مستويات الطاقة</li><li>تحسين النوم وتقليل القلق</li></ul>",
    $adminId]);
$bp->execute([$catTip,$adminId,
    "5 نصائح يومية للحفاظ على صحة الظهر",
    "5 daily tips for back health",
    "5-daily-tips-back-health",
    "نصائح بسيطة لكنها فعّالة لحماية ظهرك من الآلام.",
    "<p>آلام الظهر مشكلة شائعة، إليك خمس نصائح يومية:</p><ol><li>حافظ على وضعية جلوس صحيحة.</li><li>قم بتمارين الإطالة بانتظام.</li><li>اختر وسادة وفراشاً مناسبَين.</li><li>تجنّب رفع الأوزان الثقيلة دون تقنية صحيحة.</li><li>احرص على الحركة كل ساعة عمل.</li></ol>",
    $adminId]);

echo "▸ Testimonials…\n";
$ts = $pdo->prepare("INSERT IGNORE INTO testimonials (author,role,content_ar,rating,is_featured,is_active,sort_order,created_at,updated_at)
    VALUES (?,?,?,?,?,1,?,NOW(),NOW())");
$ts->execute(["نور الحسن","عميلة","تجربة رائعة! المعالجون محترفون والمكان نظيف ومريح. أنصح الجميع بزيارة عيادة لمسة نور.",5,1,1]);
$ts->execute(["أحمد الخطيب","رياضي","ساعدتني جلسات العلاج الطبيعي على التعافي بسرعة بعد إصابة الكتف. شكراً للفريق الرائع.",5,1,2]);
$ts->execute(["سارة عوض","أم جديدة","جلسات تصريف السوائل بعد الولادة كانت مفيدة جداً. خدمة احترافية وأسعار مناسبة.",5,0,3]);
$ts->execute(["مريم الزعبي","مهندسة","المساج العميق ساعدني كثيراً في التخلص من توتر الكتف بسبب العمل المكتبي.",4,0,4]);

echo "▸ FAQs…\n";
$fq = $pdo->prepare("INSERT IGNORE INTO faqs (question_ar,answer_ar,sort_order,is_active,created_at,updated_at) VALUES (?,?,?,1,NOW(),NOW())");
$fq->execute(["كيف أحجز موعد؟","يمكنك الحجز عبر الموقع من صفحة 'احجز الآن' أو الاتصال بنا مباشرة.",1]);
$fq->execute(["ما هي طرق الدفع المتاحة؟","نقبل الدفع نقداً، بالبطاقة، التحويل البنكي، والدفع الإلكتروني.",2]);
$fq->execute(["هل تقدّمون باقات للجلسات المتعددة؟","نعم، لدينا باقات بأسعار مخفّضة. تصفّح صفحة الباقات لمعرفة المزيد.",3]);
$fq->execute(["ما هي ساعات العمل؟","نعمل من " . setting('working_hours_from','09:00') . " إلى " . setting('working_hours_to','21:00') . " يومياً.",4]);
$fq->execute(["هل يمكن إلغاء الموعد؟","نعم، يمكنك الإلغاء أو إعادة جدولة الموعد بالاتصال بنا قبل 24 ساعة.",5]);

echo "▸ Sample contact + booking requests…\n";
$pdo->prepare("INSERT INTO contact_messages (name,email,phone,subject,message,is_read,created_at) VALUES (?,?,?,?,?,0,NOW())")
    ->execute(["زائر تجريبي","visitor@example.com","+962790000099","استفسار","أرغب بمعرفة المزيد عن جلسات الحجامة."]);

$svc = (int)$pdo->query("SELECT id FROM services WHERE deleted_at IS NULL AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO booking_requests (patient_name,phone,email,service_id,requested_at,status,notes,created_at,updated_at)
    VALUES (?,?,?,?,DATE_ADD(NOW(),INTERVAL 2 DAY),'pending',?,NOW(),NOW())")
    ->execute(["لمى جابر","+962790000088","lama@example.com",$svc ?: null,"أتمنى موعداً مسائياً."]);

echo "✅ Phase 8 seed complete.\n";
echo "   Sliders · 3 blog posts · 4 testimonials · 5 FAQs · 1 contact msg · 1 booking req\n";
