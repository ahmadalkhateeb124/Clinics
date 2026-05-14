<?php
// ════════════════════════════════════════════════════════════════════════
// Phase 2 seeder — sample categories, services, packages, patients
// Run: php BusinessPortal/config/seed_phase2.php
// ════════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/config.php';
$pdo = db();

$adminId = (int)$pdo->query("SELECT id FROM users WHERE email='admin@clinic.com' LIMIT 1")->fetchColumn();
if (!$adminId) { fwrite(STDERR, "Run seed.php first.\n"); exit(1); }

echo "▸ Seeding service categories…\n";
$cats = [
    ['علاج طبيعي',     'Physical Therapy',     'physical-therapy', 'fa-hand-holding-medical', 1],
    ['تصريف سوائل',    'Lymphatic Drainage',   'lymphatic-drainage','fa-droplet',             2],
    ['مساج',          'Massage',              'massage',          'fa-spa',                  3],
    ['حجامة',         'Cupping / Hijama',     'cupping',          'fa-circle-dot',           4],
    ['تقشير',         'Body Scrubs',          'scrubs',           'fa-hand-sparkles',        5],
    ['استشارات',      'Consultations',        'consultations',    'fa-stethoscope',          6],
];
$ins = $pdo->prepare("INSERT IGNORE INTO service_categories
    (name_ar,name_en,slug,icon,sort_order,is_active,created_by,created_at,updated_at)
    VALUES (?,?,?,?,?,1,?,NOW(),NOW())");
foreach ($cats as $c) $ins->execute([$c[0],$c[1],$c[2],$c[3],$c[4],$adminId]);

$catId = function (string $slug) use ($pdo) {
    return (int)$pdo->query("SELECT id FROM service_categories WHERE slug = " . $pdo->quote($slug))->fetchColumn();
};

echo "▸ Seeding services…\n";
$services = [
    // [cat_slug,         name_ar,                    name_en,                  duration, price, comm%, is_consultation]
    ['physical-therapy', 'جلسة علاج طبيعي عامة',      'Physical Therapy Session','60', 30.00, 20, 0],
    ['physical-therapy', 'إعادة تأهيل بعد الإصابة',   'Post-Injury Rehabilitation','75', 40.00, 22, 0],
    ['lymphatic-drainage','تصريف سوائل كامل',         'Full Lymphatic Drainage', '60', 35.00, 22, 0],
    ['massage',          'مساج سويدي',                'Swedish Massage',         '60', 30.00, 25, 0],
    ['massage',          'مساج عميق للأنسجة',         'Deep Tissue Massage',     '75', 40.00, 25, 0],
    ['massage',          'مساج رياضي',                'Sports Massage',          '60', 35.00, 25, 0],
    ['massage',          'مساج تايلندي',              'Thai Massage',            '90', 45.00, 25, 0],
    ['massage',          'مساج بالحجر الساخن',        'Hot Stone Massage',       '75', 45.00, 25, 0],
    ['cupping',          'حجامة جافة',                'Dry Cupping',             '30', 20.00, 20, 0],
    ['cupping',          'حجامة رطبة',                'Wet Cupping',             '45', 30.00, 22, 0],
    ['cupping',          'حجامة منزلقة',              'Sliding Cupping',         '30', 25.00, 20, 0],
    ['cupping',          'حجامة نارية',               'Fire Cupping',            '30', 30.00, 22, 0],
    ['scrubs',           'تقشير الجسم الكامل',        'Full Body Scrub',         '45', 35.00, 25, 0],
    ['scrubs',           'تقشير القدمين',             'Foot Scrub',              '30', 15.00, 20, 0],
    ['consultations',    'استشارة طبية',              'Medical Consultation',    '30', 25.00, 50, 1],
    ['consultations',    'استشارة عبر الفيديو',       'Online Video Consultation','30', 20.00, 50, 1],
];
$insS = $pdo->prepare("INSERT IGNORE INTO services
    (category_id,name_ar,name_en,slug,duration_minutes,price,commission_pct,is_consultation,is_active,created_by,created_at,updated_at)
    VALUES (?,?,?,?,?,?,?,?,1,?,NOW(),NOW())");
foreach ($services as $s) {
    $cid = $catId($s[0]);
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9-]+/','-', $s[2]));
    $insS->execute([$cid, $s[1], $s[2], $slug, (int)$s[3], (float)$s[4], (float)$s[5], (int)$s[6], $adminId]);
}

echo "▸ Seeding packages…\n";
$packages = [
    ['باقة العلاج الطبيعي 5 جلسات',  'Physical Therapy 5x',     5,  140.00, 60],
    ['باقة العلاج الطبيعي 10 جلسات', 'Physical Therapy 10x',   10,  260.00, 90],
    ['باقة المساج 5 جلسات',          'Massage Pack 5x',         5,  135.00, 60],
    ['باقة المساج الفاخرة 10 جلسات', 'Premium Massage 10x',    10,  280.00, 90],
    ['باقة الحجامة 4 جلسات',         'Cupping Pack 4x',         4,   90.00, 60],
];
$insPk = $pdo->prepare("INSERT IGNORE INTO packages
    (name_ar,name_en,slug,total_sessions,price,validity_days,is_active,created_by,created_at,updated_at)
    VALUES (?,?,?,?,?,?,1,?,NOW(),NOW())");
foreach ($packages as $p) {
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9-]+/','-', $p[1]));
    $insPk->execute([$p[0],$p[1],$slug,(int)$p[2],(float)$p[3],(int)$p[4],$adminId]);
}

// Wire packages to services
echo "▸ Wiring packages to services…\n";
$pdo->exec("DELETE FROM package_services");
$mappings = [
    'Physical Therapy 5x'   => [['Physical Therapy Session', 5]],
    'Physical Therapy 10x'  => [['Physical Therapy Session', 10]],
    'Massage Pack 5x'       => [
        ['Swedish Massage', 5], ['Deep Tissue Massage', 5], ['Sports Massage', 5],
    ],
    'Premium Massage 10x'   => [
        ['Swedish Massage', 10], ['Deep Tissue Massage', 10], ['Hot Stone Massage', 10], ['Thai Massage', 10],
    ],
    'Cupping Pack 4x'       => [
        ['Dry Cupping', 4], ['Wet Cupping', 4], ['Sliding Cupping', 4],
    ],
];
$insMap = $pdo->prepare("INSERT IGNORE INTO package_services (package_id,service_id,sessions_included) VALUES (?,?,?)");
$findPk = $pdo->prepare("SELECT id FROM packages WHERE name_en = ? AND deleted_at IS NULL");
$findSv = $pdo->prepare("SELECT id FROM services WHERE name_en = ? AND deleted_at IS NULL");
foreach ($mappings as $pkgName => $list) {
    $findPk->execute([$pkgName]); $pid = (int)$findPk->fetchColumn();
    if (!$pid) continue;
    foreach ($list as $row) {
        $findSv->execute([$row[0]]); $sid = (int)$findSv->fetchColumn();
        if ($sid) $insMap->execute([$pid,$sid,(int)$row[1]]);
    }
}

echo "▸ Seeding sample patients…\n";
$insP = $pdo->prepare("INSERT INTO patients
    (code,first_name,last_name,gender,dob,phone,email,address,city,country,
     medical_history,allergies,outstanding_balance,created_by,created_at,updated_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
    ON DUPLICATE KEY UPDATE phone = VALUES(phone)");
$samples = [
    ['NT-00001','نور','الحسن','female','1990-05-12','+962790000001','noor@example.com','شارع المدينة المنورة','عمان','Jordan','Lower back pain, recurring',     'None',          0],
    ['NT-00002','أحمد','الخطيب','male', '1985-09-01','+962790000002','ahmad@example.com','شارع مكة','عمان','Jordan','Sports injury — shoulder',          'Penicillin',    25.50],
    ['NT-00003','سارة','عوض','female','1992-12-20','+962790000003','sara@example.com', 'الدوار السابع','عمان','Jordan','Post-pregnancy lymph drainage',     'None',          0],
    ['NT-00004','مريم','الزعبي','female','1988-03-08','+962790000004','mariam@example.com','شفا بدران','عمان','Jordan','Chronic neck tension',              'Latex',         60.00],
    ['NT-00005','يوسف','العمر','male','1978-07-15','+962790000005','yousef@example.com','الجبيهة','عمان','Jordan','Diabetes type 2 — needs gentle massage','Aspirin',       0],
    ['NT-00006','هبة','المومني','female','1995-01-30','+962790000006','heba@example.com','الصويفية','عمان','Jordan','Migraine, stress',                   'None',          0],
    ['NT-00007','خالد','أبو زيد','male','1972-11-04','+962790000007','khaled@example.com','تلاع العلي','عمان','Jordan','Sciatica',                          'Sulfa drugs',  120.00],
    ['NT-00008','ليلى','الشرع','female','1998-08-22','+962790000008','laila@example.com','عبدون','عمان','Jordan','Fitness recovery',                       'None',          0],
    ['NT-00009','عمر','الناصر','male','1980-04-17','+962790000009','omar@example.com',  'مرج الحمام','عمان','Jordan','Frozen shoulder',                    'Iodine',        0],
    ['NT-00010','رنا','حداد','female','1993-06-25','+962790000010','rana@example.com',  'دير غبار','عمان','Jordan','Lymphedema after surgery',            'None',          0],
];
foreach ($samples as $row) {
    $insP->execute([
        $row[0],$row[1],$row[2],$row[3],$row[4],$row[5],$row[6],$row[7],$row[8],$row[9],
        $row[10],$row[11],$row[12],$adminId
    ]);
}

echo "▸ Assigning a sample package to NT-00001…\n";
$pid = (int)$pdo->query("SELECT id FROM patients WHERE code='NT-00001' LIMIT 1")->fetchColumn();
$pkgId = (int)$pdo->query("SELECT id FROM packages WHERE name_en='Physical Therapy 5x' LIMIT 1")->fetchColumn();
if ($pid && $pkgId) {
    $pdo->prepare("INSERT IGNORE INTO patient_packages
        (patient_id,package_id,purchase_date,expiry_date,total_sessions,used_sessions,price,paid_amount,status,created_by)
        VALUES (?,?,?,DATE_ADD(?,INTERVAL 60 DAY),5,2,140.00,140.00,'active',?)")
        ->execute([$pid,$pkgId,date('Y-m-d'),date('Y-m-d'),$adminId]);
}

echo "✅ Phase 2 seed complete.\n";
echo "   Categories: 6 · Services: 16 · Packages: 5 · Patients: 10\n";
