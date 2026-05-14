<?php
// ════════════════════════════════════════════════════════════════════════
// Phase 6 seeder — employees + attendance + leaves + payslip generation
// Run: php BusinessPortal/config/seed_phase6.php
// ════════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/config.php';
$pdo = db();

$adminId = (int)$pdo->query("SELECT id FROM users WHERE email='admin@clinic.com' LIMIT 1")->fetchColumn();
if (!$adminId) { fwrite(STDERR, "Run seed.php first.\n"); exit(1); }

// Wipe seed remnants
$pdo->exec("DELETE FROM payslip_components WHERE payslip_id IN (SELECT id FROM payslips WHERE notes='SEED')");
$pdo->exec("UPDATE commissions SET payslip_id = NULL WHERE payslip_id IN (SELECT id FROM payslips WHERE notes='SEED')");
$pdo->exec("DELETE FROM commissions WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");  // safe-ish: only seeded run output
$pdo->exec("DELETE FROM payslips WHERE notes='SEED'");
$pdo->exec("DELETE FROM advances WHERE reason LIKE '[SEED]%'");
$pdo->exec("DELETE FROM leaves   WHERE reason LIKE '[SEED]%'");
$pdo->exec("DELETE FROM attendance WHERE notes LIKE '[SEED]%'");

echo "▸ Linking therapist users to employees…\n";
$users = $pdo->query("
    SELECT u.id, u.name, u.email,
        (SELECT GROUP_CONCAT(r.slug) FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=u.id) AS roles
    FROM users u
    WHERE u.deleted_at IS NULL AND u.email LIKE '%@clinic.com' AND u.email <> 'admin@clinic.com'
")->fetchAll();

$insE = $pdo->prepare("INSERT INTO employees
    (user_id,code,first_name,last_name,job_title,department,phone,hire_date,
     contract_type,base_salary,commission_default_pct,is_active,created_by,created_at,updated_at)
    VALUES (?,?,?,?,?,?,?,?, 'full_time',?,?,1,?,NOW(),NOW())
    ON DUPLICATE KEY UPDATE base_salary=VALUES(base_salary), commission_default_pct=VALUES(commission_default_pct), is_active=1");

$counter = 1;
$empIds  = [];
foreach ($users as $u) {
    $code = 'EMP-' . str_pad((string)$counter, 3, '0', STR_PAD_LEFT);
    $parts = explode(' ', trim(preg_replace('/^(Dr\.|Therapist)\s+/i','',$u['name'])));
    $first = $parts[0] ?? $u['name'];
    $last  = $parts[1] ?? '—';
    $isDoctor = strpos($u['roles'] ?? '','doctor') !== false;
    $job = $isDoctor ? 'Doctor' : 'Therapist';
    $dept = $isDoctor ? 'Medical' : 'Therapy';
    $base = $isDoctor ? 800.00 : 500.00;
    $comm = $isDoctor ? 30.00  : 20.00;
    $insE->execute([
        (int)$u['id'], $code, $first, $last, $job, $dept,
        '+96279' . str_pad((string)mt_rand(1000000,9999999), 7, '0', STR_PAD_LEFT),
        date('Y-m-d', strtotime('-' . rand(180, 720) . ' days')),
        $base, $comm, $adminId
    ]);
    $eid = (int)$pdo->query("SELECT id FROM employees WHERE user_id = " . (int)$u['id'] . " AND deleted_at IS NULL LIMIT 1")->fetchColumn();
    if ($eid) $empIds[] = $eid;
    $counter++;
}
echo "  ▸ " . count($empIds) . " employees linked.\n";

echo "▸ Seeding attendance for last 25 days…\n";
$insA = $pdo->prepare("INSERT INTO attendance
    (employee_id,work_date,check_in,check_out,expected_in,expected_out,late_minutes,worked_minutes,status,source,notes,created_by,created_at,updated_at)
    VALUES (?,?,?,?, '09:00:00','17:00:00', ?,?,?,'manual','[SEED]',?,NOW(),NOW())
    ON DUPLICATE KEY UPDATE check_in=VALUES(check_in), check_out=VALUES(check_out),
        late_minutes=VALUES(late_minutes), worked_minutes=VALUES(worked_minutes),
        status=VALUES(status), updated_at=NOW()");

$today = new DateTime();
$attCount = 0;
foreach ($empIds as $eid) {
    for ($i = 1; $i <= 25; $i++) {
        $d = (clone $today)->modify("-$i days");
        if ((int)$d->format('N') >= 6) continue;   // skip Sat/Sun
        $r = mt_rand(1,10);
        if ($r === 1)      { $status='absent'; $in=null; $out=null; $late=0; $worked=0; }
        elseif ($r === 2)  { $status='leave';  $in=null; $out=null; $late=0; $worked=0; }
        else {
            $status='present';
            $lateMin = mt_rand(0,3) === 0 ? mt_rand(5,40) : 0;
            $startH  = 9; $startM = $lateMin; if ($startM >= 60) { $startH++; $startM -= 60; }
            $in  = $d->format('Y-m-d') . sprintf(' %02d:%02d:00', $startH, $startM);
            $endH = 17 + (mt_rand(0,2)); $endM = mt_rand(0,30);
            $out = $d->format('Y-m-d') . sprintf(' %02d:%02d:00', $endH, $endM);
            $late = $lateMin;
            $worked = (int)round((strtotime($out) - strtotime($in))/60);
        }
        $insA->execute([$eid, $d->format('Y-m-d'), $in, $out, $late, $worked, $status, $adminId]);
        $attCount++;
    }
}
echo "  ▸ $attCount attendance rows seeded.\n";

echo "▸ Sample leave + advance requests…\n";
if ($empIds) {
    $eid = $empIds[0];
    $pdo->prepare("INSERT INTO leaves (employee_id,leave_type,start_date,end_date,days_count,reason,status,created_at,updated_at)
        VALUES (?,'annual', DATE_ADD(CURDATE(), INTERVAL 7 DAY), DATE_ADD(CURDATE(), INTERVAL 9 DAY), 3, '[SEED] vacation', 'pending', NOW(), NOW())")
        ->execute([$eid]);

    $pdo->prepare("INSERT INTO advances (employee_id, amount, request_date, reason, status, created_at, updated_at)
        VALUES (?, 100.00, CURDATE(), '[SEED] sample advance', 'pending', NOW(), NOW())")
        ->execute([$eid]);
}

echo "▸ Generating last month's payslips…\n";
$year = (int)date('Y', strtotime('first day of last month'));
$month = (int)date('n', strtotime('first day of last month'));
$generated = 0;
foreach ($empIds as $eid) {
    try {
        $pid = generate_payslip($eid, $year, $month, $adminId);
        // tag
        $pdo->prepare("UPDATE payslips SET notes='SEED' WHERE id=?")->execute([$pid]);
        $generated++;
    } catch (Throwable $e) {
        fwrite(STDERR, "Payslip fail emp #$eid: " . $e->getMessage() . "\n");
    }
}
echo "  ▸ $generated payslips for $year-$month.\n";

echo "✅ Phase 6 seed complete.\n";
