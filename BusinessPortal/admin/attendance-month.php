<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('attendance.view');

$PageTitle = __('monthly_attendance');

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));
$first = sprintf('%04d-%02d-01', $year, $month);
$last  = date('Y-m-t', strtotime($first));

$rows = db()->prepare("
    SELECT e.id, e.code, e.first_name, e.last_name, e.department,
           SUM(a.status='present')  AS present,
           SUM(a.status='absent')   AS absent,
           SUM(a.status='leave')    AS lvs,
           SUM(a.status='half_day') AS half,
           SUM(a.status='remote')   AS remote,
           COALESCE(SUM(a.late_minutes),0)         AS late_min,
           COALESCE(SUM(a.early_leave_minutes),0)  AS early_min,
           COALESCE(SUM(a.worked_minutes),0)       AS worked_min
    FROM employees e
    LEFT JOIN attendance a
        ON a.employee_id = e.id AND a.deleted_at IS NULL
       AND a.work_date BETWEEN ? AND ?
    WHERE e.deleted_at IS NULL AND e.is_active = 1
    GROUP BY e.id
    ORDER BY e.first_name
");
$rows->execute([$first, $last]);
$rows = $rows->fetchAll();

// KPI aggregates
$kpi = ['present'=>0,'absent'=>0,'late_min'=>0,'worked_min'=>0];
foreach ($rows as $r) {
    $kpi['present']    += (int)$r['present'];
    $kpi['absent']     += (int)$r['absent'];
    $kpi['late_min']   += (int)$r['late_min'];
    $kpi['worked_min'] += (int)$r['worked_min'];
}

include BP_PARTIALS . '/header.php';
?>
<div class="page-wrap">
    <!-- Header -->
    <div class="page-header">
        <h4 class="m-0">
            <i class="fa-regular fa-calendar text-teal me-2"></i><?= __('monthly_attendance') ?>
            <small class="text-muted ms-2" style="font-size:.85rem"><?= sprintf('%04d-%02d', $year, $month) ?></small>
        </h4>
        <div class="page-header-actions">
            <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/attendance.php">
                <i class="fa-solid fa-clock me-1"></i><?= __('daily_attendance') ?>
            </a>
        </div>
    </div>

    <!-- KPI strip -->
    <div class="appt-kpis">
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#10b981"><i class="fa-solid fa-user-check"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('total_present_days') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['present'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#ef4444"><i class="fa-solid fa-user-xmark"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('total_absent_days') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['absent'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#f59e0b"><i class="fa-solid fa-hourglass-half"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('total_late_min') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['late_min'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#0d9488"><i class="fa-solid fa-business-time"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('total_worked_hours') ?></div>
                <div class="appt-kpi-value" style="font-size:1.05rem"><?= number_format($kpi['worked_min']/60, 1) ?></div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <form method="get" class="appt-filters">
        <div class="appt-filter-group">
            <select name="year" class="form-select form-select-sm">
                <?php for ($y = (int)date('Y'); $y >= (int)date('Y')-3; $y--): ?>
                    <option value="<?= $y ?>" <?= $year===$y?'selected':'' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="appt-filter-group">
            <select name="month" class="form-select form-select-sm">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $month===$m?'selected':'' ?>><?= __('month_'.strtolower(date('M', mktime(0,0,0,$m,1)))) ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <button class="btn btn-teal btn-sm"><i class="fa-solid fa-filter me-1"></i><?= __('apply') ?></button>
    </form>

    <div class="card"><div class="card-body p-0"><div class="table-responsive">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th><?= __('code') ?></th><th><?= __('name') ?></th><th><?= __('department') ?></th>
                <th class="text-end"><?= __('present') ?></th><th class="text-end"><?= __('absent') ?></th>
                <th class="text-end"><?= __('half_day') ?></th><th class="text-end"><?= __('leave_label') ?></th>
                <th class="text-end"><?= __('late_min') ?></th><th class="text-end"><?= __('worked_hours') ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><code><?= e($r['code']) ?></code></td>
                        <td><?= e($r['first_name'].' '.$r['last_name']) ?></td>
                        <td class="small text-muted"><?= e($r['department']??'—') ?></td>
                        <td class="text-end"><?= (int)$r['present'] ?></td>
                        <td class="text-end <?= $r['absent']>0?'text-danger':'' ?>"><?= (int)$r['absent'] ?></td>
                        <td class="text-end"><?= (int)$r['half'] ?></td>
                        <td class="text-end"><?= (int)$r['lvs'] ?></td>
                        <td class="text-end <?= $r['late_min']>0?'text-warning':'' ?>"><?= (int)$r['late_min'] ?></td>
                        <td class="text-end"><?= number_format($r['worked_min']/60, 1) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4"><?= __('no_data') ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div></div></div>
</div>
<?php include BP_PARTIALS . '/footer.php'; ?>
