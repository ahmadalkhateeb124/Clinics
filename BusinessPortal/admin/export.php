<?php
// ════════════════════════════════════════════════════════════════════════
// CSV export endpoint (Excel-compatible, opens directly in Excel/Numbers)
//   ?type=revenue|appointments|invoices|expenses|payments|patients|employees
//   &range=month|... &from=Y-m-d &to=Y-m-d
// ════════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('reports.export');

$type = $_GET['type'] ?? 'revenue';
$rangeName = $_GET['range'] ?? 'month';
$range = date_range($rangeName, $_GET['from'] ?? null, $_GET['to'] ?? null);

$filename = "nourstouch-{$type}-{$range['from']}_to_{$range['to']}.csv";

header('Content-Type: text/csv; charset=utf-8');
header("Content-Disposition: attachment; filename=\"$filename\"");
header('Pragma: no-cache');
header('Expires: 0');

// UTF-8 BOM so Excel reads Arabic correctly
echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');

$dt1 = $range['from'] . ' 00:00:00';
$dt2 = $range['to']   . ' 23:59:59';

switch ($type) {
    case 'revenue':
        fputcsv($out, ['Date','Receipt #','Patient code','Patient','Method','Reference','Amount']);
        $s = db()->prepare("
            SELECT py.paid_at, py.receipt_no, p.code, CONCAT(p.first_name,' ',p.last_name) AS pname,
                   py.method, py.reference_no, py.amount
            FROM payments py JOIN patients p ON p.id = py.patient_id
            WHERE py.deleted_at IS NULL AND py.is_refund=0 AND py.paid_at BETWEEN ? AND ?
            ORDER BY py.paid_at
        ");
        $s->execute([$dt1, $dt2]);
        foreach ($s as $r) fputcsv($out, [$r['paid_at'], $r['receipt_no'], $r['code'], $r['pname'], $r['method'], $r['reference_no'], $r['amount']]);
        break;

    case 'appointments':
        fputcsv($out, ['Date','Patient code','Patient','Therapist','Room','Status','Total']);
        $s = db()->prepare("
            SELECT a.start_at, p.code, CONCAT(p.first_name,' ',p.last_name) AS pname,
                   u.name AS ther, r.name AS room, a.status, a.total_price
            FROM appointments a
            JOIN patients p ON p.id = a.patient_id
            LEFT JOIN users u ON u.id = a.therapist_id
            LEFT JOIN rooms r ON r.id = a.room_id
            WHERE a.deleted_at IS NULL AND a.start_at BETWEEN ? AND ?
            ORDER BY a.start_at
        ");
        $s->execute([$dt1, $dt2]);
        foreach ($s as $r) fputcsv($out, [$r['start_at'], $r['code'], $r['pname'], $r['ther'], $r['room'], $r['status'], $r['total_price']]);
        break;

    case 'invoices':
        fputcsv($out, ['Invoice #','Date','Patient code','Patient','Total','Paid','Balance','Status']);
        $s = db()->prepare("
            SELECT i.invoice_no, i.issue_date, p.code, CONCAT(p.first_name,' ',p.last_name) AS pname,
                   i.total, i.paid_amount, i.balance, i.status
            FROM invoices i JOIN patients p ON p.id = i.patient_id
            WHERE i.deleted_at IS NULL AND i.issue_date BETWEEN ? AND ?
            ORDER BY i.id
        ");
        $s->execute([$range['from'], $range['to']]);
        foreach ($s as $r) fputcsv($out, [$r['invoice_no'], $r['issue_date'], $r['code'], $r['pname'], $r['total'], $r['paid_amount'], $r['balance'], $r['status']]);
        break;

    case 'expenses':
        fputcsv($out, ['Date','Title','Category','Vendor','Method','Amount']);
        $s = db()->prepare("
            SELECT e.expense_date, e.title, c.name_ar AS cat, e.vendor, e.payment_method, e.amount
            FROM expenses e LEFT JOIN expense_categories c ON c.id = e.category_id
            WHERE e.deleted_at IS NULL AND e.expense_date BETWEEN ? AND ?
            ORDER BY e.expense_date
        ");
        $s->execute([$range['from'], $range['to']]);
        foreach ($s as $r) fputcsv($out, [$r['expense_date'], $r['title'], $r['cat'], $r['vendor'], $r['payment_method'], $r['amount']]);
        break;

    case 'patients':
        fputcsv($out, ['Code','Name','Phone','Email','Gender','City','Outstanding','Created']);
        $s = db()->query("SELECT code,first_name,last_name,phone,email,gender,city,outstanding_balance,created_at FROM patients WHERE deleted_at IS NULL ORDER BY id");
        foreach ($s as $r) fputcsv($out, [$r['code'], $r['first_name'].' '.$r['last_name'], $r['phone'], $r['email'], $r['gender'], $r['city'], $r['outstanding_balance'], $r['created_at']]);
        break;

    case 'employees':
        fputcsv($out, ['Code','Name','Department','Sessions','Revenue','Commission','Present days','Absent days','Late min']);
        $s = db()->prepare("
            SELECT e.code, CONCAT(e.first_name,' ',e.last_name) AS name, e.department, u.id AS uid,
                   COALESCE((SELECT COUNT(*) FROM appointments a WHERE a.therapist_id=u.id AND a.status='completed' AND a.start_at BETWEEN ? AND ?), 0) AS sessions,
                   COALESCE((SELECT SUM(asv.price) FROM appointments a JOIN appointment_services asv ON asv.appointment_id=a.id WHERE a.therapist_id=u.id AND a.status='completed' AND a.start_at BETWEEN ? AND ?), 0) AS revenue,
                   COALESCE((SELECT SUM(amount) FROM commissions WHERE employee_id=e.id AND earned_on BETWEEN ? AND ?), 0) AS commission,
                   COALESCE((SELECT COUNT(*) FROM attendance WHERE employee_id=e.id AND status='present' AND work_date BETWEEN ? AND ?), 0) AS present_d,
                   COALESCE((SELECT COUNT(*) FROM attendance WHERE employee_id=e.id AND status='absent'  AND work_date BETWEEN ? AND ?), 0) AS absent_d,
                   COALESCE((SELECT SUM(late_minutes) FROM attendance WHERE employee_id=e.id AND work_date BETWEEN ? AND ?), 0) AS late_min
            FROM employees e LEFT JOIN users u ON u.id=e.user_id
            WHERE e.deleted_at IS NULL ORDER BY e.id
        ");
        $s->execute([$dt1,$dt2,$dt1,$dt2,$range['from'],$range['to'],$range['from'],$range['to'],$range['from'],$range['to'],$range['from'],$range['to']]);
        foreach ($s as $r) fputcsv($out, [$r['code'], $r['name'], $r['department'], $r['sessions'], $r['revenue'], $r['commission'], $r['present_d'], $r['absent_d'], $r['late_min']]);
        break;

    default:
        fputcsv($out, ['Unknown type', $type]);
}

log_activity('exported','reports',"Exported $type ({$range['from']} → {$range['to']})");
fclose($out);
