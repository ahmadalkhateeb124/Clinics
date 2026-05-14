<?php
// ════════════════════════════════════════════════════════════════════════
// HR helpers — codes, attendance, payroll, commissions
// ════════════════════════════════════════════════════════════════════════

/** Generate next employee code: EMP-001, EMP-002, … */
function next_employee_code(): string {
    $row = db()->query("SELECT code FROM employees ORDER BY id DESC LIMIT 1")->fetch();
    $next = 1;
    if ($row && preg_match('/(\d+)$/', $row['code'], $m)) $next = ((int)$m[1]) + 1;
    return 'EMP-' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

/** Generate next payslip number: PS-2026-05-001 */
function next_payslip_no(int $year, int $month): string {
    $prefix = sprintf('PS-%04d-%02d-', $year, $month);
    $row = db()->prepare("SELECT payslip_no FROM payslips WHERE payslip_no LIKE ? ORDER BY id DESC LIMIT 1");
    $row->execute([$prefix . '%']);
    $last = $row->fetchColumn();
    $next = 1;
    if ($last && preg_match('/(\d+)$/', $last, $m)) $next = ((int)$m[1]) + 1;
    return $prefix . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

/** Resolve an employee row from the currently-logged-in user (or null). */
function current_employee(): ?array {
    if (!isLoggedIn()) return null;
    static $cached = false;
    if ($cached !== false) return $cached;
    $s = db()->prepare("SELECT * FROM employees WHERE user_id = ? AND deleted_at IS NULL LIMIT 1");
    $s->execute([(int)$_SESSION['user_id']]);
    return $cached = ($s->fetch() ?: null);
}

/**
 * Build a commission ledger from completed appointments in [from..to].
 * Returns rows ready to insert into `commissions` (does NOT insert).
 */
function compute_commissions(int $employeeId, string $from, string $to): array {
    $u = db()->prepare("SELECT user_id, commission_default_pct FROM employees WHERE id = ?");
    $u->execute([$employeeId]);
    $emp = $u->fetch();
    if (!$emp) return [];

    $rows = [];

    // Completed appointments where this user was the therapist
    $sql = "SELECT a.id AS appt_id, asv.service_id, asv.price, asv.commission_pct, DATE(a.start_at) AS d
            FROM appointments a
            JOIN appointment_services asv ON asv.appointment_id = a.id
            WHERE a.therapist_id = ? AND a.status = 'completed' AND a.deleted_at IS NULL
              AND DATE(a.start_at) BETWEEN ? AND ?";
    $stmt = db()->prepare($sql);
    $stmt->execute([(int)$emp['user_id'], $from, $to]);
    foreach ($stmt as $r) {
        $pct = (float)$r['commission_pct'];
        if ($pct == 0) $pct = (float)$emp['commission_default_pct'];
        if ($pct == 0) continue;
        $amt = round((float)$r['price'] * $pct / 100, 2);
        $rows[] = [
            'appointment_id'  => (int)$r['appt_id'],
            'consultation_id' => null,
            'service_id'      => (int)$r['service_id'],
            'earned_on'       => $r['d'],
            'service_price'   => (float)$r['price'],
            'pct'             => $pct,
            'amount'          => $amt,
        ];
    }

    // Completed consultations
    $sql = "SELECT c.id AS cs_id, c.service_id, c.fee AS price, COALESCE(s.commission_pct, 0) AS commission_pct, DATE(c.consultation_date) AS d
            FROM consultations c
            LEFT JOIN services s ON s.id = c.service_id
            WHERE c.doctor_id = ? AND c.status = 'completed' AND c.deleted_at IS NULL
              AND DATE(c.consultation_date) BETWEEN ? AND ?";
    $stmt = db()->prepare($sql);
    $stmt->execute([(int)$emp['user_id'], $from, $to]);
    foreach ($stmt as $r) {
        $pct = (float)$r['commission_pct'];
        if ($pct == 0) $pct = (float)$emp['commission_default_pct'];
        if ($pct == 0) continue;
        $amt = round((float)$r['price'] * $pct / 100, 2);
        $rows[] = [
            'appointment_id'  => null,
            'consultation_id' => (int)$r['cs_id'],
            'service_id'      => (int)$r['service_id'],
            'earned_on'       => $r['d'],
            'service_price'   => (float)$r['price'],
            'pct'             => $pct,
            'amount'          => $amt,
        ];
    }

    return $rows;
}

/** Generate (or refresh) a payslip for the given employee/month. */
function generate_payslip(int $employeeId, int $year, int $month, int $createdBy): int {
    $emp = db()->prepare("SELECT * FROM employees WHERE id = ? AND deleted_at IS NULL");
    $emp->execute([$employeeId]);
    $emp = $emp->fetch();
    if (!$emp) throw new RuntimeException('Employee not found.');

    $first = sprintf('%04d-%02d-01', $year, $month);
    $last  = date('Y-m-t', strtotime($first));
    $workingDays = (int)date('t', strtotime($first));   // simple: full month — adjust per policy

    // Attendance summary
    $att = db()->prepare("
        SELECT
            SUM(status = 'present')                         AS present_d,
            SUM(status = 'absent')                          AS absent_d,
            SUM(status = 'leave')                           AS leave_d,
            SUM(status = 'half_day') * 0.5                  AS half_d,
            COALESCE(SUM(late_minutes), 0)                  AS late_min
        FROM attendance
        WHERE employee_id = ? AND work_date BETWEEN ? AND ? AND deleted_at IS NULL
    ");
    $att->execute([$employeeId, $first, $last]);
    $a = $att->fetch();
    $presentDays = (float)($a['present_d'] ?? 0) + (float)($a['half_d'] ?? 0);
    $absentDays  = (float)($a['absent_d']  ?? 0);
    $leaveDays   = (float)($a['leave_d']   ?? 0);
    $lateMin     = (int)  ($a['late_min']  ?? 0);

    // Pro-rated base — absence reduces salary
    $base   = (float)$emp['base_salary'];
    $perDay = $workingDays > 0 ? $base / $workingDays : 0;
    $absentDeduction = round($perDay * $absentDays, 2);

    // Build (or refresh) the commission ledger
    db()->prepare("
        UPDATE commissions SET payslip_id = NULL
        WHERE employee_id = ? AND earned_on BETWEEN ? AND ?
    ")->execute([$employeeId, $first, $last]);
    db()->prepare("DELETE FROM commissions WHERE employee_id = ? AND earned_on BETWEEN ? AND ? AND payslip_id IS NULL")
        ->execute([$employeeId, $first, $last]);

    $rows = compute_commissions($employeeId, $first, $last);
    $insC = db()->prepare("
        INSERT INTO commissions
            (employee_id, appointment_id, consultation_id, service_id, earned_on, service_price, pct, amount, created_at)
        VALUES (?,?,?,?,?,?,?,?, NOW())
    ");
    $totalCommissions = 0.0;
    foreach ($rows as $r) {
        $insC->execute([
            $employeeId, $r['appointment_id'], $r['consultation_id'], $r['service_id'],
            $r['earned_on'], $r['service_price'], $r['pct'], $r['amount']
        ]);
        $totalCommissions += (float)$r['amount'];
    }

    // Approved advances not yet deducted
    $adv = db()->prepare("
        SELECT id, amount FROM advances
        WHERE employee_id = ? AND status IN ('approved','disbursed') AND payslip_id IS NULL AND deleted_at IS NULL
    ");
    $adv->execute([$employeeId]);
    $advances = $adv->fetchAll();
    $advanceTotal = array_sum(array_column($advances, 'amount'));

    $bonuses    = 0.0;
    $deductions = round($absentDeduction, 2);

    $gross = round($base + $totalCommissions + $bonuses, 2);
    $net   = round($gross - $deductions - $advanceTotal, 2);

    // Upsert payslip
    $existing = db()->prepare("SELECT id FROM payslips WHERE employee_id=? AND period_year=? AND period_month=?");
    $existing->execute([$employeeId, $year, $month]);
    $payslipId = (int)$existing->fetchColumn();

    if ($payslipId) {
        db()->prepare("
            UPDATE payslips SET
                working_days=?, present_days=?, absent_days=?, leave_days=?, late_minutes=?,
                base_salary=?, commissions=?, bonuses=?, deductions=?, advances_deduct=?,
                gross_salary=?, net_salary=?, updated_by=?, updated_at=NOW()
            WHERE id = ?
        ")->execute([
            $workingDays, $presentDays, $absentDays, $leaveDays, $lateMin,
            $base, $totalCommissions, $bonuses, $deductions, $advanceTotal,
            $gross, $net, $createdBy, $payslipId
        ]);
        // wipe non-system components (keep manual adjustments? simpler: wipe all and re-add system rows)
        db()->prepare("DELETE FROM payslip_components WHERE payslip_id = ?")->execute([$payslipId]);
    } else {
        db()->prepare("
            INSERT INTO payslips
                (payslip_no,employee_id,period_year,period_month,working_days,present_days,absent_days,leave_days,late_minutes,
                 base_salary,commissions,bonuses,deductions,advances_deduct,gross_salary,net_salary,status,
                 created_by,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?, ?,?,?,?,?,?,?, 'draft', ?, NOW(), NOW())
        ")->execute([
            next_payslip_no($year,$month), $employeeId, $year, $month,
            $workingDays, $presentDays, $absentDays, $leaveDays, $lateMin,
            $base, $totalCommissions, $bonuses, $deductions, $advanceTotal,
            $gross, $net, $createdBy
        ]);
        $payslipId = (int)db()->lastInsertId();
    }

    // System components for transparency
    $insP = db()->prepare("INSERT INTO payslip_components (payslip_id, kind, label, amount, notes) VALUES (?,?,?,?,?)");
    $insP->execute([$payslipId, 'earning',   'Base salary',     $base,             null]);
    if ($totalCommissions > 0) $insP->execute([$payslipId, 'earning',   'Commissions',     $totalCommissions, count($rows) . ' line(s)']);
    if ($bonuses > 0)          $insP->execute([$payslipId, 'earning',   'Bonuses',         $bonuses,          null]);
    if ($absentDeduction > 0)  $insP->execute([$payslipId, 'deduction', 'Absence',         $absentDeduction,  $absentDays . ' day(s)']);
    if ($advanceTotal > 0)     $insP->execute([$payslipId, 'deduction', 'Advances',        $advanceTotal,     count($advances) . ' advance(s)']);

    // Link the commission rows + advances to this payslip
    db()->prepare("UPDATE commissions SET payslip_id = ? WHERE employee_id = ? AND earned_on BETWEEN ? AND ?")
        ->execute([$payslipId, $employeeId, $first, $last]);
    foreach ($advances as $row) {
        db()->prepare("UPDATE advances SET payslip_id = ?, status = 'deducted' WHERE id = ?")
            ->execute([$payslipId, (int)$row['id']]);
    }

    return $payslipId;
}

/** Mark a payslip as paid → post to expenses. */
function pay_payslip(int $payslipId, string $method, ?string $reference, int $userId): void {
    $ps = db()->prepare("SELECT p.*, e.first_name, e.last_name, e.code FROM payslips p JOIN employees e ON e.id=p.employee_id WHERE p.id=?");
    $ps->execute([$payslipId]);
    $payslip = $ps->fetch();
    if (!$payslip) throw new RuntimeException('Payslip not found.');
    if ($payslip['status'] === 'paid') return;

    // Make sure salaries category exists
    $catId = (int)db()->query("SELECT id FROM expense_categories WHERE slug = 'salaries' LIMIT 1")->fetchColumn();

    db()->prepare("INSERT INTO expenses
        (category_id,title,amount,expense_date,payment_method,vendor,reference_no,linked_payroll_id,notes,created_by,created_at,updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")
        ->execute([
            $catId ?: null,
            'Payslip ' . $payslip['payslip_no'] . ' — ' . $payslip['first_name'] . ' ' . $payslip['last_name'],
            (float)$payslip['net_salary'],
            date('Y-m-d'),
            $method,
            'Employee ' . $payslip['code'],
            $reference,
            $payslipId,
            'Auto-posted from payroll',
            $userId,
        ]);
    $expenseId = (int)db()->lastInsertId();

    db()->prepare("UPDATE payslips SET status='paid', paid_at=NOW(), payment_method=?, reference_no=?, expense_id=?, updated_by=?, updated_at=NOW() WHERE id=?")
        ->execute([$method, $reference, $expenseId, $userId, $payslipId]);
}
