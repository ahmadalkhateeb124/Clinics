<?php
// ════════════════════════════════════════════════════════════════════════
// Phase 4 seeder — sample consultations
// Run: php BusinessPortal/config/seed_phase4.php
// ════════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/config.php';
$pdo = db();

$adminId = (int)$pdo->query("SELECT id FROM users WHERE email='admin@clinic.com' LIMIT 1")->fetchColumn();
$doctorId = (int)$pdo->query("
    SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id
    WHERE r.slug='doctor' AND u.deleted_at IS NULL LIMIT 1
")->fetchColumn();
if (!$adminId || !$doctorId) { fwrite(STDERR, "Run earlier seeders first.\n"); exit(1); }

$consultSvc = (int)$pdo->query("SELECT id FROM services WHERE is_consultation=1 AND deleted_at IS NULL LIMIT 1")->fetchColumn();

$samples = [
    ['NT-00001','in_clinic','completed','Lower back pain for 3 weeks',  'Mild lumbar tenderness',     'Lumbar strain',         '5 PT sessions + ergonomic advice', 5,  25.00, '+7 days'],
    ['NT-00003','video',    'completed','Post-pregnancy swelling',      'Mild lymphedema',            'Post-partum lymphedema','Lymphatic drainage 8 sessions',     8,  20.00, '+14 days'],
    ['NT-00006','in_clinic','scheduled','Stress migraines, neck tension','—',                          '—',                     '—',                                 0,  25.00, '+3 days'],
    ['NT-00009','in_clinic','completed','Frozen shoulder, limited ROM', 'Reduced ROM (60°), tender',  'Adhesive capsulitis',   'PT + deep tissue 10 sessions',     10, 25.00, '+10 days'],
];

$pdo->exec("DELETE FROM consultations WHERE complaint LIKE '%[SEED]%'");
$ins = $pdo->prepare("INSERT INTO consultations
    (patient_id,doctor_id,service_id,mode,consultation_date,duration_minutes,
     complaint,examination,diagnosis,recommendations,prescribed_sessions,fee,
     follow_up_date,status,created_by,created_at,updated_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");

$count = 0;
foreach ($samples as $row) {
    $pid = (int)$pdo->query("SELECT id FROM patients WHERE code = " . $pdo->quote($row[0]))->fetchColumn();
    if (!$pid) continue;
    $date  = (new DateTime())->modify('-'.rand(1,20).' days')->setTime(rand(10,17), 0)->format('Y-m-d H:i:s');
    $follow = (new DateTime($date))->modify($row[9])->format('Y-m-d');

    $ins->execute([
        $pid, $doctorId, $consultSvc ?: null, $row[1],
        $date, 30,
        '[SEED] ' . $row[3], $row[4], $row[5], $row[6],
        (int)$row[7], (float)$row[8],
        $follow, $row[2],
        $adminId
    ]);
    $count++;
}

echo "✅ Phase 4 seed complete. Consultations: $count\n";
