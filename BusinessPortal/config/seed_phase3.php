<?php
// ════════════════════════════════════════════════════════════════════════
// Phase 3 seeder — sample therapists + appointments + 1 overdue installment
// Run: php BusinessPortal/config/seed_phase3.php
// ════════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/config.php';
$pdo = db();

$adminId = (int)$pdo->query("SELECT id FROM users WHERE email='admin@clinic.com' LIMIT 1")->fetchColumn();
if (!$adminId) { fwrite(STDERR, "Run seed.php first.\n"); exit(1); }

echo "▸ Seeding sample therapists (3)…\n";
$therapists = [
    ['Dr. Lina Khalil',     'lina@clinic.com',     'doctor'],
    ['Therapist Sami Odeh', 'sami@clinic.com',     'therapist'],
    ['Therapist Reem Saleh','reem@clinic.com',     'therapist'],
];
$insU = $pdo->prepare("INSERT INTO users (name,email,phone,password,status,must_change_pw,created_by,created_at,updated_at)
                       VALUES (?,?,?,?,'active',0,?,NOW(),NOW())
                       ON DUPLICATE KEY UPDATE name = VALUES(name)");
$mapRole = $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id)
                          SELECT ?, id FROM roles WHERE slug = ?");

$thIds = [];
foreach ($therapists as $t) {
    $insU->execute([$t[0], $t[1], '+96279000000'.rand(1,9), password_hash('Therapist@123', PASSWORD_BCRYPT), $adminId]);
    $tid = (int)$pdo->query("SELECT id FROM users WHERE email = " . $pdo->quote($t[1]))->fetchColumn();
    $mapRole->execute([$tid, $t[2]]);
    $thIds[] = $tid;
}

echo "▸ Booking sample appointments…\n";
$pdo->exec("DELETE FROM appointment_services WHERE appointment_id IN (SELECT id FROM appointments WHERE notes LIKE 'SEED:%')");
$pdo->exec("DELETE FROM appointments WHERE notes LIKE 'SEED:%'");

$patients = $pdo->query("SELECT id FROM patients WHERE deleted_at IS NULL ORDER BY id LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
$rooms    = $pdo->query("SELECT id FROM rooms WHERE deleted_at IS NULL ORDER BY id LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
$services = $pdo->query("SELECT id,duration_minutes,price,commission_pct FROM services WHERE deleted_at IS NULL AND is_active=1 ORDER BY id")->fetchAll();

$insA = $pdo->prepare("INSERT INTO appointments
    (patient_id,therapist_id,room_id,start_at,end_at,duration_minutes,status,total_price,notes,created_by,created_at,updated_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");
$insAS = $pdo->prepare("INSERT INTO appointment_services
    (appointment_id,service_id,price,commission_pct,duration_minutes) VALUES (?,?,?,?,?)");

$now    = new DateTime();
$count  = 0;
$states = ['scheduled','confirmed','completed','completed','no_show','cancelled','scheduled'];

for ($i = -7; $i <= 7; $i++) {
    foreach ([10, 13, 15, 17] as $hour) {
        if (rand(0, 1) === 0) continue;
        $pid = $patients[array_rand($patients)];
        $tid = $thIds[array_rand($thIds)];
        $rid = $rooms[array_rand($rooms)];
        $svc = $services[array_rand($services)];
        $start = (clone $now)->modify("$i days")->setTime($hour, 0, 0);
        $end   = (clone $start)->modify('+'.(int)$svc['duration_minutes'].' minutes');
        $status = $i < 0 ? $states[array_rand([2,3,4,5])] : $states[array_rand([0,1,6])];
        $insA->execute([
            $pid, $tid, $rid,
            $start->format('Y-m-d H:i:s'),
            $end->format('Y-m-d H:i:s'),
            (int)$svc['duration_minutes'],
            $status,
            (float)$svc['price'],
            'SEED: sample appointment',
            $adminId
        ]);
        $aid = (int)$pdo->lastInsertId();
        $insAS->execute([$aid, (int)$svc['id'], (float)$svc['price'], (float)$svc['commission_pct'], (int)$svc['duration_minutes']]);
        $count++;
    }
}

echo "▸ Adding an overdue installment to NT-00007 (to demonstrate hard-block)…\n";
$pid = (int)$pdo->query("SELECT id FROM patients WHERE code='NT-00007' LIMIT 1")->fetchColumn();
if ($pid) {
    $pdo->prepare("DELETE FROM installments WHERE patient_id = ? AND notes = 'SEED'")->execute([$pid]);
    $pdo->prepare("INSERT INTO installments
        (patient_id,amount,paid_amount,due_date,status,notes,created_at,updated_at)
        VALUES (?,120.00,0,DATE_SUB(CURDATE(), INTERVAL 14 DAY),'overdue','SEED',NOW(),NOW())
    ")->execute([$pid]);
}

echo "✅ Phase 3 seed complete.\n";
echo "   Therapists: 3 (login: lina@/sami@/reem@clinic.com — pass: Therapist@123)\n";
echo "   Appointments seeded: $count\n";
echo "   Overdue installment + outstanding balance on NT-00007 demonstrates booking block.\n";
