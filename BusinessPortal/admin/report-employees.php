<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('reports.view');

$PageTitle = __('employee_performance_label');
$rangeName = $_GET['range'] ?? 'month';
$range = date_range($rangeName, $_GET['from'] ?? null, $_GET['to'] ?? null);

$rows = db()->prepare("
    SELECT e.id, e.code, e.first_name, e.last_name, e.department, e.base_salary, u.id AS user_id,
           COALESCE((
               SELECT COUNT(*) FROM appointments a
               WHERE a.therapist_id = u.id AND a.status='completed' AND a.deleted_at IS NULL
                 AND a.start_at BETWEEN ? AND ?
           ), 0) AS sessions,
           COALESCE((
               SELECT SUM(asv.price) FROM appointments a
               JOIN appointment_services asv ON asv.appointment_id = a.id
               WHERE a.therapist_id = u.id AND a.status='completed' AND a.deleted_at IS NULL
                 AND a.start_at BETWEEN ? AND ?
           ), 0) AS revenue,
           COALESCE((
               SELECT SUM(amount) FROM commissions
               WHERE employee_id = e.id AND earned_on BETWEEN ? AND ?
           ), 0) AS commission,
           COALESCE((SELECT COUNT(*) FROM attendance WHERE employee_id=e.id AND status='present' AND work_date BETWEEN ? AND ?), 0) AS present_days,
           COALESCE((SELECT COUNT(*) FROM attendance WHERE employee_id=e.id AND status='absent'  AND work_date BETWEEN ? AND ?), 0) AS absent_days,
           COALESCE((SELECT SUM(late_minutes) FROM attendance WHERE employee_id=e.id AND work_date BETWEEN ? AND ?), 0) AS late_min
    FROM employees e
    LEFT JOIN users u ON u.id = e.user_id
    WHERE e.deleted_at IS NULL AND e.is_active = 1
    ORDER BY revenue DESC
");
$dt1 = $range['from'] . ' 00:00:00'; $dt2 = $range['to'] . ' 23:59:59';
$rows->execute([
    $dt1, $dt2,            // sessions count
    $dt1, $dt2,            // revenue
    $range['from'], $range['to'],   // commission
    $range['from'], $range['to'],   // present
    $range['from'], $range['to'],   // absent
    $range['from'], $range['to'],   // late
]);
$rows = $rows->fetchAll();

// KPI aggregates
$kpi = ['emps'=>count($rows),'sessions'=>0,'revenue'=>0,'commission'=>0];
foreach ($rows as $r) {
    $kpi['sessions']   += (int)$r['sessions'];
    $kpi['revenue']    += (float)$r['revenue'];
    $kpi['commission'] += (float)$r['commission'];
}

include BP_PARTIALS . '/header.php';
?>
<div class="page-wrap">
    <!-- Header -->
    <div class="page-header">
        <h4 class="m-0">
            <i class="fa-solid fa-user-tie text-teal me-2"></i><?= __('employee_performance_label') ?>
            <small class="text-muted ms-2" style="font-size:.85rem"><?= e($range['label']) ?></small>
        </h4>
        <div class="page-header-actions">
            <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/report-retention.php">
                <i class="fa-solid fa-arrows-rotate me-1"></i><?= __('patient_retention_short') ?>
            </a>
            <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/reports.php">
                <i class="fa-solid fa-chart-line me-1"></i><?= __('pnl_report') ?>
            </a>
        </div>
    </div>

    <!-- KPI strip -->
    <div class="appt-kpis">
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#6366f1"><i class="fa-solid fa-users"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('total_employees') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['emps'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#0d9488"><i class="fa-solid fa-list-check"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('sessions') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['sessions'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#10b981"><i class="fa-solid fa-sack-dollar"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('revenue') ?></div>
                <div class="appt-kpi-value" style="font-size:1.05rem"><?= format_money($kpi['revenue']) ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#b45309"><i class="fa-solid fa-coins"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('commission_label') ?></div>
                <div class="appt-kpi-value" style="font-size:1.05rem"><?= format_money($kpi['commission']) ?></div>
            </div>
        </div>
    </div>

    <form method="get" class="row g-2 mb-3">
        <div class="col-md-3">
            <select name="range" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php foreach (['today'=>'today','yesterday'=>'yesterday','week'=>'this_week','month'=>'this_month','3m'=>'last_3_months','year'=>'this_year','custom'=>'custom'] as $k => $tk): ?>
                    <option value="<?= $k ?>" <?= $rangeName===$k?'selected':'' ?>><?= __($tk) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><input type="date" name="from" value="<?= e($range['from']) ?>" class="form-control form-control-sm" <?= $rangeName!=='custom'?'disabled':'' ?>></div>
        <div class="col-md-2"><input type="date" name="to"   value="<?= e($range['to'])   ?>" class="form-control form-control-sm" <?= $rangeName!=='custom'?'disabled':'' ?>></div>
        <div class="col-md-2"><button class="btn btn-sm btn-teal w-100"><?= __('apply') ?></button></div>
        <div class="col-md-3 ms-auto">
            <a class="btn btn-sm btn-outline-teal w-100" target="_blank"
               href="<?= BP_URL ?>admin/export.php?<?= http_build_query(['type'=>'employees','range'=>$rangeName,'from'=>$range['from'],'to'=>$range['to']]) ?>">
                <i class="fa-solid fa-file-excel me-1"></i><?= __('export') ?> CSV
            </a>
        </div>
    </form>

    <div class="table-card">
        <div class="table-responsive d-none d-md-block">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th><?= __('code') ?></th><th><?= __('name') ?></th><th><?= __('department') ?></th>
                <th class="text-end"><?= __('sessions') ?></th>
                <th class="text-end"><?= __('revenue') ?></th>
                <th class="text-end"><?= __('commission_label') ?></th>
                <th class="text-end"><?= __('present') ?></th>
                <th class="text-end"><?= __('absent') ?></th>
                <th class="text-end"><?= __('late_min') ?></th>
                <th class="text-end"><?= __('attendance_pct') ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ($rows as $r):
                    $totalDays = (int)$r['present_days'] + (int)$r['absent_days'];
                    $att = $totalDays > 0 ? round($r['present_days'] / $totalDays * 100, 1) : 0;
                ?>
                    <tr>
                        <td><code><?= e($r['code']) ?></code></td>
                        <td><?= e($r['first_name'].' '.$r['last_name']) ?></td>
                        <td class="small text-muted"><?= e($r['department']??'—') ?></td>
                        <td class="text-end"><?= (int)$r['sessions'] ?></td>
                        <td class="text-end"><?= format_money($r['revenue']) ?></td>
                        <td class="text-end text-success"><?= format_money($r['commission']) ?></td>
                        <td class="text-end"><?= (int)$r['present_days'] ?></td>
                        <td class="text-end <?= $r['absent_days']>0?'text-danger':'' ?>"><?= (int)$r['absent_days'] ?></td>
                        <td class="text-end <?= $r['late_min']>0?'text-warning':'' ?>"><?= (int)$r['late_min'] ?></td>
                        <td class="text-end <?= $att<80?'text-warning':'text-success' ?>"><?= $att ?>%</td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="10" class="text-center text-muted py-4"><?= __('no_data') ?></td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>

        <!-- Mobile entity card list -->
        <div class="entity-list d-md-none">
            <?php if (!$rows): ?>
                <div class="empty-state"><i class="fa-regular fa-chart-bar"></i><div><?= __('no_data') ?></div></div>
            <?php else: foreach ($rows as $r):
                $initials = mb_strtoupper(mb_substr($r['first_name'],0,1).mb_substr($r['last_name'],0,1));
                $totalDays = (int)$r['present_days'] + (int)$r['absent_days'];
                $att = $totalDays > 0 ? round($r['present_days'] / $totalDays * 100, 1) : 0;
                $chips = [
                    ['label'=>(int)$r['sessions'].' '.__('sessions'),'icon'=>'fa-list-check','class'=>'teal'],
                    ['label'=>format_money($r['commission']),'icon'=>'fa-coins','class'=>'success'],
                    ['label'=>$att.'%','icon'=>'fa-chart-pie','class'=>$att<80?'warn':'success'],
                ];
                if ((int)$r['late_min']>0) $chips[] = ['label'=>(int)$r['late_min'].'m','icon'=>'fa-hourglass-half','class'=>'warn'];
                echo render_entity_card([
                    'avatar' => $initials,
                    'avatar_class' => 'indigo',
                    'title' => $r['first_name'].' '.$r['last_name'],
                    'title_right' => format_money($r['revenue']),
                    'code' => $r['code'],
                    'meta' => !empty($r['department']) ? [$r['department']] : [],
                    'chips' => $chips,
                ]);
            endforeach; endif; ?>
        </div>
    </div>
</div>
<?php include BP_PARTIALS . '/footer.php'; ?>
