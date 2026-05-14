<?php
// ════════════════════════════════════════════════════════════════════════
// Analytics helpers — date ranges, KPIs, comparisons
// ════════════════════════════════════════════════════════════════════════

/**
 * Resolve a named range (today/yesterday/week/month/3m/year/custom) into
 *   ['from' => 'Y-m-d', 'to' => 'Y-m-d', 'label' => '…',
 *    'prev_from', 'prev_to', 'prev_label', 'days']
 */
function date_range(string $name, ?string $customFrom = null, ?string $customTo = null): array {
    $today = new DateTime('today');
    switch ($name) {
        case 'today':
            $from = (clone $today); $to = (clone $today);
            break;
        case 'yesterday':
            $from = (clone $today)->modify('-1 day'); $to = clone $from;
            break;
        case 'week':
            // ISO week (Mon..Sun)
            $dow = (int)$today->format('N');
            $from = (clone $today)->modify('-' . ($dow - 1) . ' days');
            $to   = (clone $from)->modify('+6 days');
            break;
        case '3m':
            $from = (clone $today)->modify('-3 months');
            $to   = clone $today;
            break;
        case 'year':
            $from = new DateTime($today->format('Y') . '-01-01');
            $to   = new DateTime($today->format('Y') . '-12-31');
            break;
        case 'custom':
            $from = $customFrom ? new DateTime($customFrom) : new DateTime($today->format('Y-m-01'));
            $to   = $customTo   ? new DateTime($customTo)   : clone $today;
            break;
        case 'month':
        default:
            $from = new DateTime($today->format('Y-m-01'));
            $to   = new DateTime($today->format('Y-m-t'));
            $name = 'month';
            break;
    }
    $days = (int)$from->diff($to)->days + 1;
    $prevTo   = (clone $from)->modify('-1 day');
    $prevFrom = (clone $prevTo)->modify('-' . ($days - 1) . ' days');

    return [
        'name'       => $name,
        'from'       => $from->format('Y-m-d'),
        'to'         => $to->format('Y-m-d'),
        'days'       => $days,
        'prev_from'  => $prevFrom->format('Y-m-d'),
        'prev_to'    => $prevTo->format('Y-m-d'),
        'label'      => $from->format('Y-m-d') . ' → ' . $to->format('Y-m-d'),
        'prev_label' => $prevFrom->format('Y-m-d') . ' → ' . $prevTo->format('Y-m-d'),
    ];
}

/** Sum payments in [from..to] (amount), excluding refunds. */
function kpi_revenue(string $from, string $to): float {
    $s = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM payments
        WHERE deleted_at IS NULL AND is_refund = 0 AND paid_at BETWEEN ? AND ?");
    $s->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
    return (float)$s->fetchColumn();
}

function kpi_refunds(string $from, string $to): float {
    $s = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM refunds
        WHERE deleted_at IS NULL AND refunded_at BETWEEN ? AND ?");
    $s->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
    return (float)$s->fetchColumn();
}

function kpi_expenses(string $from, string $to): float {
    $s = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses
        WHERE deleted_at IS NULL AND expense_date BETWEEN ? AND ?");
    $s->execute([$from, $to]);
    return (float)$s->fetchColumn();
}

/** Appointments in range, grouped by status. */
function kpi_appointments(string $from, string $to): array {
    $s = db()->prepare("
        SELECT status, COUNT(*) AS n
        FROM appointments
        WHERE deleted_at IS NULL AND start_at BETWEEN ? AND ?
        GROUP BY status
    ");
    $s->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
    $out = ['scheduled'=>0,'confirmed'=>0,'completed'=>0,'no_show'=>0,'cancelled'=>0,'total'=>0];
    foreach ($s as $r) {
        $out[$r['status']] = (int)$r['n'];
        $out['total']     += (int)$r['n'];
    }
    return $out;
}

/** New vs returning patients in the period. */
function kpi_patients(string $from, string $to): array {
    $newCount = (int)(function() use ($from, $to) {
        $s = db()->prepare("SELECT COUNT(*) FROM patients WHERE deleted_at IS NULL AND DATE(created_at) BETWEEN ? AND ?");
        $s->execute([$from, $to]);
        return $s->fetchColumn();
    })();
    $totalSeen = (int)(function() use ($from, $to) {
        $s = db()->prepare("
            SELECT COUNT(DISTINCT patient_id) FROM appointments
            WHERE deleted_at IS NULL AND status IN ('completed','confirmed') AND start_at BETWEEN ? AND ?
        ");
        $s->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
        return $s->fetchColumn();
    })();
    $returning = max(0, $totalSeen - $newCount);
    return ['new' => $newCount, 'returning' => $returning, 'total_seen' => $totalSeen];
}

/** Helper: percent change between two numbers (returns null when prev is 0). */
function pct_change(float $cur, float $prev): ?float {
    if ($prev <= 0) return $cur > 0 ? 100.0 : null;
    return round((($cur - $prev) / $prev) * 100, 1);
}

/** Render a small "+12.3% vs prev" badge. */
function trend_badge(?float $delta): string {
    if ($delta === null) return '<span class="badge bg-light text-muted">—</span>';
    $cls = $delta > 0 ? 'success' : ($delta < 0 ? 'danger' : 'secondary');
    $arrow = $delta > 0 ? '▲' : ($delta < 0 ? '▼' : '•');
    return '<span class="badge bg-' . $cls . '">' . $arrow . ' ' . number_format(abs($delta),1) . '%</span>';
}

/** Daily revenue series for a chart (returns ['labels'=>[…], 'data'=>[…]]). */
function series_revenue_daily(string $from, string $to): array {
    $s = db()->prepare("
        SELECT DATE(paid_at) AS d, COALESCE(SUM(amount),0) AS total
        FROM payments
        WHERE deleted_at IS NULL AND is_refund = 0 AND paid_at BETWEEN ? AND ?
        GROUP BY DATE(paid_at)
    ");
    $s->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
    $byDay = [];
    foreach ($s as $r) $byDay[$r['d']] = (float)$r['total'];

    $labels = []; $data = [];
    $cur = new DateTime($from); $end = new DateTime($to);
    while ($cur <= $end) {
        $k = $cur->format('Y-m-d');
        $labels[] = $cur->format('M d');
        $data[]   = $byDay[$k] ?? 0;
        $cur->modify('+1 day');
    }
    return ['labels' => $labels, 'data' => $data];
}

/** Top N services by revenue (paid invoices' items). */
function top_services(string $from, string $to, int $limit = 5): array {
    $s = db()->prepare("
        SELECT s.id, s.name_ar, COUNT(*) AS n, COALESCE(SUM(ii.total),0) AS revenue
        FROM invoice_items ii
        JOIN invoices i ON i.id = ii.invoice_id
        LEFT JOIN services s ON s.id = ii.service_id
        WHERE i.deleted_at IS NULL AND i.status IN ('issued','partial','paid')
          AND i.issue_date BETWEEN ? AND ?
        GROUP BY s.id, s.name_ar
        ORDER BY revenue DESC
        LIMIT $limit
    ");
    $s->execute([$from, $to]);
    return $s->fetchAll();
}

/** Top N therapists by completed appointments / commission. */
function top_therapists(string $from, string $to, int $limit = 5): array {
    $s = db()->prepare("
        SELECT u.id, u.name,
               COUNT(DISTINCT a.id) AS sessions,
               COALESCE(SUM(asv.price),0) AS revenue,
               COALESCE(SUM(asv.price * asv.commission_pct / 100), 0) AS commission
        FROM appointments a
        JOIN users u ON u.id = a.therapist_id
        JOIN appointment_services asv ON asv.appointment_id = a.id
        WHERE a.deleted_at IS NULL AND a.status = 'completed'
          AND a.start_at BETWEEN ? AND ?
        GROUP BY u.id, u.name
        ORDER BY revenue DESC
        LIMIT $limit
    ");
    $s->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
    return $s->fetchAll();
}

/** Top N packages by patient assignments. */
function top_packages(string $from, string $to, int $limit = 5): array {
    $s = db()->prepare("
        SELECT pk.id, pk.name_ar, COUNT(*) AS sold, COALESCE(SUM(pp.price),0) AS revenue
        FROM patient_packages pp
        JOIN packages pk ON pk.id = pp.package_id
        WHERE pp.deleted_at IS NULL
          AND pp.purchase_date BETWEEN ? AND ?
        GROUP BY pk.id, pk.name_ar
        ORDER BY sold DESC, revenue DESC
        LIMIT $limit
    ");
    $s->execute([$from, $to]);
    return $s->fetchAll();
}

/** Peak hours heatmap (day-of-week × hour → appointment count). */
function peak_heatmap(string $from, string $to): array {
    $s = db()->prepare("
        SELECT DAYOFWEEK(start_at) AS dow, HOUR(start_at) AS h, COUNT(*) AS n
        FROM appointments
        WHERE deleted_at IS NULL AND start_at BETWEEN ? AND ?
        GROUP BY DAYOFWEEK(start_at), HOUR(start_at)
    ");
    $s->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
    // dow: 1=Sun..7=Sat (MySQL convention)
    $grid = [];
    for ($d = 1; $d <= 7; $d++) for ($h = 8; $h <= 21; $h++) $grid["$d-$h"] = 0;
    foreach ($s as $r) $grid[(int)$r['dow'].'-'.(int)$r['h']] = (int)$r['n'];
    return $grid;
}

/** AR aging (issued + partial only). */
function ar_aging(): array {
    return db()->query("
        SELECT
            COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), issue_date) BETWEEN 0  AND 30 THEN balance ELSE 0 END), 0) AS b_0_30,
            COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), issue_date) BETWEEN 31 AND 60 THEN balance ELSE 0 END), 0) AS b_31_60,
            COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), issue_date) > 60               THEN balance ELSE 0 END), 0) AS b_60_plus,
            COALESCE(SUM(balance), 0) AS total
        FROM invoices
        WHERE deleted_at IS NULL AND status IN ('issued','partial')
    ")->fetch() ?: ['b_0_30'=>0,'b_31_60'=>0,'b_60_plus'=>0,'total'=>0];
}

/** Package utilization (avg used/total across active packages). */
function package_utilization(): array {
    $row = db()->query("
        SELECT
            COUNT(*) AS active_count,
            COALESCE(AVG(used_sessions / NULLIF(total_sessions,0)) * 100, 0) AS avg_pct,
            COALESCE(SUM(used_sessions),0) AS used,
            COALESCE(SUM(total_sessions),0) AS total
        FROM patient_packages
        WHERE deleted_at IS NULL AND status = 'active'
    ")->fetch();
    return [
        'active_count' => (int)$row['active_count'],
        'avg_pct'      => round((float)$row['avg_pct'], 1),
        'used'         => (int)$row['used'],
        'total'        => (int)$row['total'],
    ];
}

/**
 * Patient retention: of patients first seen >= 90 days ago, what % had any
 * appointment in the last 90 days?
 */
function patient_retention(): array {
    $cohortCutoff = (new DateTime('-90 days'))->format('Y-m-d');
    $cohort = (int)db()->prepare("
        SELECT COUNT(*) FROM patients
        WHERE deleted_at IS NULL AND DATE(created_at) <= ?
    ")->execute([$cohortCutoff]) ? null : null;

    $stmt = db()->prepare("SELECT COUNT(*) FROM patients WHERE deleted_at IS NULL AND DATE(created_at) <= ?");
    $stmt->execute([$cohortCutoff]);
    $cohort = (int)$stmt->fetchColumn();

    $stmt = db()->prepare("
        SELECT COUNT(DISTINCT a.patient_id) FROM appointments a
        JOIN patients p ON p.id = a.patient_id
        WHERE p.deleted_at IS NULL AND DATE(p.created_at) <= ?
          AND a.deleted_at IS NULL AND a.start_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
          AND a.status IN ('completed','confirmed','scheduled')
    ");
    $stmt->execute([$cohortCutoff]);
    $retained = (int)$stmt->fetchColumn();
    $rate = $cohort > 0 ? round($retained / $cohort * 100, 1) : 0;
    return ['cohort' => $cohort, 'retained' => $retained, 'churn' => max(0,$cohort - $retained), 'rate' => $rate];
}
