<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('reports.view');

$PageTitle = __('reports');

$from = trim($_GET['from'] ?? date('Y-m-01'));
$to   = trim($_GET['to']   ?? date('Y-m-t'));
$fromDt = $from . ' 00:00:00';
$toDt   = $to   . ' 23:59:59';

// Revenue (net = paid − refunds in range)
$paidStmt = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM payments
    WHERE deleted_at IS NULL AND is_refund=0 AND paid_at BETWEEN ? AND ?");
$paidStmt->execute([$fromDt, $toDt]);
$paid = (float)$paidStmt->fetchColumn();

$refStmt = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM refunds
    WHERE deleted_at IS NULL AND refunded_at BETWEEN ? AND ?");
$refStmt->execute([$fromDt, $toDt]);
$refunds = (float)$refStmt->fetchColumn();

$grossRevenue = $paid;
$netRevenue   = $paid - $refunds;

// Expenses
$expStmt = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses
    WHERE deleted_at IS NULL AND expense_date BETWEEN ? AND ?");
$expStmt->execute([$from, $to]);
$expenses = (float)$expStmt->fetchColumn();

$netProfit = $netRevenue - $expenses;
$margin    = $grossRevenue > 0 ? ($netProfit / $grossRevenue) * 100 : 0;

// Revenue by method
$byMethod = db()->prepare("SELECT method, COALESCE(SUM(amount),0) AS total, COUNT(*) AS cnt
    FROM payments WHERE deleted_at IS NULL AND is_refund=0 AND paid_at BETWEEN ? AND ?
    GROUP BY method ORDER BY total DESC");
$byMethod->execute([$fromDt, $toDt]); $byMethod = $byMethod->fetchAll();

// Expenses by category
$byCat = db()->prepare("SELECT c.name_ar AS cat, COALESCE(SUM(e.amount),0) AS total, COUNT(*) AS cnt
    FROM expenses e LEFT JOIN expense_categories c ON c.id = e.category_id
    WHERE e.deleted_at IS NULL AND e.expense_date BETWEEN ? AND ?
    GROUP BY e.category_id, c.name_ar ORDER BY total DESC");
$byCat->execute([$from, $to]); $byCat = $byCat->fetchAll();

// AR aging (outstanding invoices grouped by age of issue date)
$aging = db()->query("
    SELECT
        SUM(CASE WHEN DATEDIFF(CURDATE(), issue_date) BETWEEN 0 AND 30  THEN balance ELSE 0 END) AS bucket_0_30,
        SUM(CASE WHEN DATEDIFF(CURDATE(), issue_date) BETWEEN 31 AND 60 THEN balance ELSE 0 END) AS bucket_31_60,
        SUM(CASE WHEN DATEDIFF(CURDATE(), issue_date) > 60               THEN balance ELSE 0 END) AS bucket_60_plus,
        SUM(balance) AS total
    FROM invoices
    WHERE deleted_at IS NULL AND status IN ('issued','partial')
")->fetch();

include BP_PARTIALS . '/header.php';
?>
<div class="page-wrap">
    <!-- Header -->
    <div class="page-header">
        <h4 class="m-0">
            <i class="fa-solid fa-chart-line text-teal me-2"></i><?= __('pnl_receivables') ?>
        </h4>
        <div class="page-header-actions">
            <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/report-employees.php">
                <i class="fa-solid fa-user-tie me-1"></i><?= __('employee_performance_label') ?>
            </a>
            <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/report-retention.php">
                <i class="fa-solid fa-arrows-rotate me-1"></i><?= __('patient_retention_short') ?>
            </a>
        </div>
    </div>

    <!-- KPI strip -->
    <div class="appt-kpis">
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#10b981"><i class="fa-solid fa-sack-dollar"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('gross_revenue') ?></div>
                <div class="appt-kpi-value" style="font-size:1.05rem"><?= format_money($grossRevenue) ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#ef4444"><i class="fa-solid fa-rotate-left"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('refunds') ?></div>
                <div class="appt-kpi-value text-danger" style="font-size:1.05rem">−<?= format_money($refunds) ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#f59e0b"><i class="fa-solid fa-receipt"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('expenses') ?></div>
                <div class="appt-kpi-value text-warning" style="font-size:1.05rem">−<?= format_money($expenses) ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#0d9488"><i class="fa-solid fa-chart-pie"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('net_profit') ?></div>
                <div class="appt-kpi-value <?= $netProfit<0?'text-danger':'' ?>" style="font-size:1.05rem"><?= format_money($netProfit) ?></div>
                <small class="text-muted"><?= number_format($margin,1) ?>% <?= __('margin') ?></small>
            </div>
        </div>
    </div>

    <form method="get" class="row g-2 mb-3">
        <div class="col-6 col-md-3"><label class="form-label small"><?= __('from') ?></label><input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm"></div>
        <div class="col-6 col-md-3"><label class="form-label small"><?= __('to') ?></label><input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm"></div>
        <div class="col-12 col-md-2 d-flex align-items-end"><button class="btn btn-sm btn-teal w-100"><?= __('search') ?></button></div>
        <div class="col-6 col-md-2 d-flex align-items-end">
            <a class="btn btn-sm btn-light w-100" href="?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-t') ?>"><?= __('this_month') ?></a>
        </div>
        <div class="col-6 col-md-2 d-flex align-items-end">
            <a class="btn btn-sm btn-light w-100" href="?from=<?= date('Y-01-01') ?>&to=<?= date('Y-12-31') ?>"><?= __('this_year') ?></a>
        </div>
    </form>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card"><div class="card-body">
                <h6 class="text-teal mb-3"><i class="fa-solid fa-credit-card me-2"></i><?= __('revenue_by_method') ?></h6>
                <?php if (!$byMethod): ?>
                    <div class="empty-state py-3"><i class="fa-regular fa-folder-open"></i><div><?= __('no_data') ?></div></div>
                <?php else: $maxMethod = max(array_column($byMethod,'total')) ?: 1; ?>
                    <div class="report-bars">
                        <?php foreach ($byMethod as $r): $pct = ($r['total']/$maxMethod)*100; ?>
                            <div class="report-bar-row">
                                <div class="report-bar-head">
                                    <span class="report-bar-label"><i class="fa-solid fa-circle-dot me-1 text-teal"></i><?= e($r['method']) ?></span>
                                    <span class="report-bar-value"><?= format_money($r['total']) ?><small class="text-muted ms-1">· <?= (int)$r['cnt'] ?></small></span>
                                </div>
                                <div class="report-bar-track"><div class="report-bar-fill" style="width:<?= $pct ?>%"></div></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div></div>
        </div>
        <div class="col-lg-6">
            <div class="card"><div class="card-body">
                <h6 class="text-teal mb-3"><i class="fa-solid fa-receipt me-2"></i><?= __('expenses_by_cat') ?></h6>
                <?php if (!$byCat): ?>
                    <div class="empty-state py-3"><i class="fa-regular fa-folder-open"></i><div><?= __('no_data') ?></div></div>
                <?php else: $maxCat = max(array_column($byCat,'total')) ?: 1; ?>
                    <div class="report-bars">
                        <?php foreach ($byCat as $r): $pct = ($r['total']/$maxCat)*100; ?>
                            <div class="report-bar-row">
                                <div class="report-bar-head">
                                    <span class="report-bar-label"><i class="fa-solid fa-tag me-1" style="color:#ec4899"></i><?= e($r['cat']??'—') ?></span>
                                    <span class="report-bar-value"><?= format_money($r['total']) ?><small class="text-muted ms-1">· <?= (int)$r['cnt'] ?></small></span>
                                </div>
                                <div class="report-bar-track"><div class="report-bar-fill rose" style="width:<?= $pct ?>%"></div></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div></div>
        </div>

        <div class="col-12">
            <div class="card"><div class="card-body">
                <h6 class="text-teal mb-3"><i class="fa-solid fa-hourglass-half me-2"></i><?= __('ar_aging') ?></h6>
                <div class="row g-2 g-md-3 text-center">
                    <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label"><?= __('aging_0_30') ?></div><div class="kpi-value"><?= format_money($aging['bucket_0_30']??0) ?></div></div></div>
                    <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label"><?= __('aging_31_60') ?></div><div class="kpi-value text-warning"><?= format_money($aging['bucket_31_60']??0) ?></div></div></div>
                    <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label"><?= __('aging_60_plus') ?></div><div class="kpi-value text-danger"><?= format_money($aging['bucket_60_plus']??0) ?></div></div></div>
                    <div class="col-6 col-md-3"><div class="kpi-card" style="background:var(--nt-teal-soft)"><div class="kpi-label"><?= __('total_outstanding') ?></div><div class="kpi-value"><?= format_money($aging['total']??0) ?></div></div></div>
                </div>
            </div></div>
        </div>
    </div>
</div>
<?php include BP_PARTIALS . '/footer.php'; ?>
