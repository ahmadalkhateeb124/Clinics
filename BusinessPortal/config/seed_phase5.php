<?php
// ════════════════════════════════════════════════════════════════════════
// Phase 5 seeder — sample expenses + invoices + payments
// Run: php BusinessPortal/config/seed_phase5.php
// ════════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/config.php';
$pdo = db();

$adminId = (int)$pdo->query("SELECT id FROM users WHERE email='admin@clinic.com' LIMIT 1")->fetchColumn();
if (!$adminId) { fwrite(STDERR, "Run seed.php first.\n"); exit(1); }

// Wipe seed remnants for re-runs
$pdo->exec("DELETE FROM payments  WHERE notes LIKE '[SEED]%'");
$pdo->exec("DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE notes LIKE '[SEED]%')");
$pdo->exec("DELETE FROM invoices  WHERE notes LIKE '[SEED]%'");
$pdo->exec("DELETE FROM expenses  WHERE notes LIKE '[SEED]%'");

echo "▸ Sample expenses…\n";
$catRent     = (int)$pdo->query("SELECT id FROM expense_categories WHERE slug='rent' LIMIT 1")->fetchColumn();
$catUtil     = (int)$pdo->query("SELECT id FROM expense_categories WHERE slug='utilities' LIMIT 1")->fetchColumn();
$catSupplies = (int)$pdo->query("SELECT id FROM expense_categories WHERE slug='supplies' LIMIT 1")->fetchColumn();
$catMarket   = (int)$pdo->query("SELECT id FROM expense_categories WHERE slug='marketing' LIMIT 1")->fetchColumn();

$expenses = [
    [$catRent,     'إيجار العيادة',           600.00, '-30 days', 'bank',   'Landlord LLC'],
    [$catUtil,     'فاتورة الكهرباء',          85.50, '-12 days', 'cash',   'Electric Co'],
    [$catUtil,     'فاتورة الإنترنت',          25.00, '-12 days', 'card',   'Orange'],
    [$catSupplies, 'زيت مساج وأدوات تعقيم',    140.00, '-7 days',  'cash',   'Medical Supplier'],
    [$catSupplies, 'مناشف ومستهلكات',           45.00, '-2 days',  'cash',   'Local market'],
    [$catMarket,   'حملة إنستغرام',            120.00, '-5 days',  'online', 'Meta Ads'],
];

$insE = $pdo->prepare("INSERT INTO expenses
    (category_id,title,amount,expense_date,payment_method,vendor,notes,created_by,created_at,updated_at)
    VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())");
foreach ($expenses as $e) {
    $date = (new DateTime())->modify($e[3])->format('Y-m-d');
    $insE->execute([$e[0] ?: null, $e[1], $e[2], $date, $e[4], $e[5], '[SEED]', $adminId]);
}

echo "▸ Sample invoices from completed appointments…\n";
$completed = $pdo->query("
    SELECT a.id, a.patient_id, a.total_price, a.start_at,
           GROUP_CONCAT(s.name_ar SEPARATOR ', ') AS items_desc
    FROM appointments a
    JOIN appointment_services asv ON asv.appointment_id = a.id
    JOIN services s ON s.id = asv.service_id
    WHERE a.status = 'completed' AND a.deleted_at IS NULL
    GROUP BY a.id
    LIMIT 8
")->fetchAll();

$count = 0;
foreach ($completed as $a) {
    if ((float)$a['total_price'] <= 0) continue;
    $invNo = next_invoice_no();
    $pdo->prepare("INSERT INTO invoices
        (invoice_no,patient_id,appointment_id,issue_date,status,currency,notes,created_by,created_at,updated_at)
        VALUES (?,?,?,?,'issued',?,?,?,NOW(),NOW())")
        ->execute([$invNo, $a['patient_id'], $a['id'], date('Y-m-d', strtotime($a['start_at'])), APP_CURRENCY, '[SEED]', $adminId]);
    $invId = (int)$pdo->lastInsertId();

    // Items snapshot from appointment_services
    $svs = $pdo->prepare("SELECT s.id, s.name_ar, asv.price FROM appointment_services asv
                          JOIN services s ON s.id=asv.service_id WHERE asv.appointment_id=?");
    $svs->execute([$a['id']]);
    $insI = $pdo->prepare("INSERT INTO invoice_items
        (invoice_id,service_id,description,quantity,unit_price,discount,total) VALUES (?,?,?,1,?,0,?)");
    foreach ($svs as $r) {
        $insI->execute([$invId, (int)$r['id'], $r['name_ar'], (float)$r['price'], (float)$r['price']]);
    }

    recompute_invoice($invId);

    // Random payment behaviour: 50% paid full · 25% partial · 25% unpaid
    $rnd = mt_rand(0,3);
    if ($rnd <= 1) {
        $pdo->prepare("INSERT INTO payments
            (receipt_no,invoice_id,patient_id,amount,method,paid_at,notes,created_by,created_at,updated_at)
            VALUES (?,?,?,?,?,?, ?, ?, NOW(), NOW())")
            ->execute([next_receipt_no(), $invId, $a['patient_id'], (float)$a['total_price'], 'cash',
                       $a['start_at'], '[SEED]', $adminId]);
    } elseif ($rnd === 2) {
        $half = round((float)$a['total_price'] / 2, 2);
        $pdo->prepare("INSERT INTO payments
            (receipt_no,invoice_id,patient_id,amount,method,paid_at,notes,created_by,created_at,updated_at)
            VALUES (?,?,?,?,'card',?, ?, ?, NOW(), NOW())")
            ->execute([next_receipt_no(), $invId, $a['patient_id'], $half, $a['start_at'], '[SEED]', $adminId]);
    }
    recompute_invoice($invId);
    $count++;
}

echo "✅ Phase 5 seed complete.\n";
echo "   Expenses: " . count($expenses) . " · Invoices: $count\n";

$totRev = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE deleted_at IS NULL AND is_refund=0")->fetchColumn();
$totExp = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE deleted_at IS NULL")->fetchColumn();
echo "   Revenue: " . format_money($totRev) . " · Expenses: " . format_money($totExp) . " · Net: " . format_money($totRev - $totExp) . "\n";
