<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('reports.view');

$PageTitle = __('patient_retention_short');

// Cohort = patients whose first appointment was in the cohort month
// Retained = at least one more appointment in the next 90 days

$months = (int)($_GET['months'] ?? 6);
$months = max(1, min(24, $months));

$cohorts = [];
for ($i = 0; $i < $months; $i++) {
    $first = (new DateTime('first day of this month'))->modify("-$i months");
    $last  = (clone $first)->modify('last day of this month');
    $key   = $first->format('Y-m');

    // Patients whose earliest appointment fell in this month
    $stmt = db()->prepare("
        SELECT a.patient_id, MIN(a.start_at) AS first_visit
        FROM appointments a
        WHERE a.deleted_at IS NULL AND a.status IN ('completed','confirmed','scheduled')
        GROUP BY a.patient_id
        HAVING DATE(first_visit) BETWEEN ? AND ?
    ");
    $stmt->execute([$first->format('Y-m-d'), $last->format('Y-m-d')]);
    $rows = $stmt->fetchAll();
    $cohortIds = array_column($rows, 'patient_id');
    $cohortSize = count($cohortIds);

    $retained = 0;
    if ($cohortSize > 0) {
        $cutoff = (clone $last)->modify('+90 days')->format('Y-m-d');
        $in = implode(',', array_fill(0, $cohortSize, '?'));
        $params = array_merge($cohortIds, [$last->format('Y-m-d').' 23:59:59', $cutoff.' 23:59:59']);
        $r = db()->prepare("
            SELECT COUNT(DISTINCT patient_id) FROM appointments
            WHERE deleted_at IS NULL AND patient_id IN ($in)
              AND start_at BETWEEN ? AND ?
              AND status IN ('completed','confirmed','scheduled')
        ");
        $r->execute($params);
        $retained = (int)$r->fetchColumn();
    }
    $rate = $cohortSize > 0 ? round($retained / $cohortSize * 100, 1) : 0;
    $cohorts[$key] = ['size' => $cohortSize, 'retained' => $retained, 'churn' => max(0, $cohortSize - $retained), 'rate' => $rate];
}
$cohorts = array_reverse($cohorts, true);

// KPI aggregates
$kpiAgg = ['size'=>0,'retained'=>0,'churn'=>0];
foreach ($cohorts as $c) {
    $kpiAgg['size']     += (int)$c['size'];
    $kpiAgg['retained'] += (int)$c['retained'];
    $kpiAgg['churn']    += (int)$c['churn'];
}
$kpiAgg['rate'] = $kpiAgg['size']>0 ? round($kpiAgg['retained']/$kpiAgg['size']*100,1) : 0;

include BP_PARTIALS . '/header.php';
?>
<div class="page-wrap">
    <!-- Header -->
    <div class="page-header">
        <h4 class="m-0">
            <i class="fa-solid fa-arrows-rotate text-teal me-2"></i><?= __('patient_retention_short') ?>
        </h4>
        <div class="page-header-actions">
            <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/report-employees.php">
                <i class="fa-solid fa-user-tie me-1"></i><?= __('employee_performance_label') ?>
            </a>
            <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/reports.php">
                <i class="fa-solid fa-chart-line me-1"></i><?= __('pnl_report') ?>
            </a>
        </div>
    </div>

    <!-- KPI strip -->
    <div class="appt-kpis">
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#6366f1"><i class="fa-solid fa-user-plus"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('new_patients') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpiAgg['size'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#10b981"><i class="fa-solid fa-user-check"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('retained_90d') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpiAgg['retained'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#ef4444"><i class="fa-solid fa-user-xmark"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('churned') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpiAgg['churn'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#0d9488"><i class="fa-solid fa-chart-pie"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('retention_rate') ?></div>
                <div class="appt-kpi-value"><?= $kpiAgg['rate'] ?>%</div>
            </div>
        </div>
    </div>

    <form method="get" class="row g-2 mb-3">
        <div class="col-md-2">
            <select name="months" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php foreach ([3,6,12,24] as $m): ?>
                    <option value="<?= $m ?>" <?= $months===$m?'selected':'' ?>><?= __('last_'.$m.'_months') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <div class="table-card">
        <div class="table-responsive d-none d-md-block">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th><?= __('cohort_month') ?></th>
                <th class="text-end"><?= __('new_patients') ?></th>
                <th class="text-end"><?= __('retained_90d') ?></th>
                <th class="text-end"><?= __('churned') ?></th>
                <th class="text-end"><?= __('retention_rate') ?></th>
                <th><?= __('trend') ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ($cohorts as $month => $c): ?>
                    <tr>
                        <td><strong><?= e($month) ?></strong></td>
                        <td class="text-end"><?= $c['size'] ?></td>
                        <td class="text-end text-success"><?= $c['retained'] ?></td>
                        <td class="text-end text-danger"><?= $c['churn'] ?></td>
                        <td class="text-end"><strong class="<?= $c['rate']>=60?'text-success':($c['rate']>=30?'text-warning':'text-danger') ?>"><?= $c['rate'] ?>%</strong></td>
                        <td>
                            <div class="progress" style="height:10px;width:200px">
                                <div class="progress-bar bg-success" style="width:<?= $c['rate'] ?>%"></div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <!-- Mobile entity card list -->
        <div class="entity-list d-md-none">
            <?php if (!$cohorts): ?>
                <div class="empty-state"><i class="fa-regular fa-chart-line"></i><div><?= __('no_data') ?></div></div>
            <?php else: foreach ($cohorts as $month => $c):
                $progClass = $c['rate']>=60?'':'warn';
                if ($c['rate'] < 30) $progClass = 'danger';
                echo render_entity_card([
                    'avatar_icon' => 'fa-calendar',
                    'avatar_class' => 'square indigo',
                    'title' => $month,
                    'title_right' => $c['rate'].'%',
                    'chips' => [
                        ['label'=>$c['size'].' '.__('new_patients'),'icon'=>'fa-user-plus','class'=>'info'],
                        ['label'=>$c['retained'].' '.__('retained_90d'),'icon'=>'fa-check','class'=>'success'],
                        ['label'=>$c['churn'].' '.__('churned'),'icon'=>'fa-xmark','class'=>'danger'],
                    ],
                    'progress' => ['value'=>$c['rate'],'class'=>$progClass],
                ]);
            endforeach; endif; ?>
        </div>
    </div>

    <p class="small text-muted mt-3">
        <i class="fa-solid fa-circle-info me-1"></i>
        <?= __('retention_help') ?>
    </p>
</div>
<?php include BP_PARTIALS . '/footer.php'; ?>
