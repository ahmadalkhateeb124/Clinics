<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('dashboard.view');

$PageTitle = __('dashboard');
$cu = currentUser();

$rangeName = $_GET['range'] ?? 'month';
$range = date_range($rangeName, $_GET['from'] ?? null, $_GET['to'] ?? null);

$revenue   = kpi_revenue($range['from'], $range['to']);
$refunds   = kpi_refunds($range['from'], $range['to']);
$expenses  = kpi_expenses($range['from'], $range['to']);
$netRev    = $revenue - $refunds;
$profit    = $netRev - $expenses;
$margin    = $revenue > 0 ? round($profit / $revenue * 100, 1) : 0;

$appts     = kpi_appointments($range['from'], $range['to']);
$patients  = kpi_patients($range['from'], $range['to']);

$noShowRate    = $appts['total'] > 0 ? round($appts['no_show']   / $appts['total'] * 100, 1) : 0;
$cancelRate    = $appts['total'] > 0 ? round($appts['cancelled'] / $appts['total'] * 100, 1) : 0;
$completeRate  = $appts['total'] > 0 ? round($appts['completed'] / $appts['total'] * 100, 1) : 0;

$prevRevenue   = kpi_revenue($range['prev_from'], $range['prev_to']);
$prevExpenses  = kpi_expenses($range['prev_from'], $range['prev_to']);
$prevAppts     = kpi_appointments($range['prev_from'], $range['prev_to']);
$prevPatients  = kpi_patients($range['prev_from'], $range['prev_to']);

$dRev   = pct_change($revenue, $prevRevenue);
$dExp   = pct_change($expenses, $prevExpenses);
$dAppts = pct_change($appts['total'], $prevAppts['total']);
$dNew   = pct_change($patients['new'], $prevPatients['new']);

$revSeries  = series_revenue_daily($range['from'], $range['to']);
$topSvcs    = top_services($range['from'], $range['to'], 5);
$topThers   = top_therapists($range['from'], $range['to'], 5);
$topPkgs    = top_packages($range['from'], $range['to'], 5);
$heatmap    = peak_heatmap($range['from'], $range['to']);
$aging      = ar_aging();
$pkgUtil    = package_utilization();
$retention  = patient_retention();

$outstanding = (float)db()->query("SELECT COALESCE(SUM(outstanding_balance),0) FROM patients WHERE deleted_at IS NULL")->fetchColumn();
$flagged = (int)db()->query("SELECT COUNT(*) FROM patients WHERE outstanding_balance > 0 AND deleted_at IS NULL")->fetchColumn();

include BP_PARTIALS . '/header.php';
?>
<div class="page-wrap">
    <div class="d-flex justify-content-between flex-wrap mb-3">
        <div>
            <h4 class="mb-1"><?= __('welcome_msg') ?>, <?= e($cu['name']) ?> 👋</h4>
            <small class="text-muted"><?= e($range['label']) ?> · <?= __('prev_period') ?>: <?= e($range['prev_label']) ?></small>
        </div>
        <div class="d-flex gap-2 flex-wrap align-items-end">
            <a class="btn btn-sm btn-light" href="<?= BP_URL ?>admin/report-employees.php"><i class="fa-solid fa-user-tie me-1"></i><?= __('employees') ?></a>
            <a class="btn btn-sm btn-light" href="<?= BP_URL ?>admin/report-retention.php"><i class="fa-solid fa-arrows-rotate me-1"></i><?= __('patient_retention_short') ?></a>
            <a class="btn btn-sm btn-light" href="<?= BP_URL ?>admin/reports.php"><i class="fa-solid fa-chart-line me-1"></i><?= __('pnl_report') ?></a>
            <a class="btn btn-sm btn-outline-teal" target="_blank" href="<?= BP_URL ?>admin/export.php?<?= http_build_query(['type'=>'revenue','range'=>$rangeName,'from'=>$range['from'],'to'=>$range['to']]) ?>">
                <i class="fa-solid fa-file-excel me-1"></i><?= __('export') ?>
            </a>
        </div>
    </div>

    <form method="get" class="row g-2 mb-3">
        <div class="col-md-3">
            <select name="range" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php foreach (['today'=>'today','yesterday'=>'yesterday','week'=>'this_week','month'=>'this_month','3m'=>'last_3_months','year'=>'this_year','custom'=>'custom'] as $k => $tKey): ?>
                    <option value="<?= $k ?>" <?= $rangeName===$k?'selected':'' ?>><?= __($tKey) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3"><input type="date" name="from" value="<?= e($range['from']) ?>" class="form-control form-control-sm" <?= $rangeName!=='custom'?'disabled':'' ?>></div>
        <div class="col-md-3"><input type="date" name="to"   value="<?= e($range['to'])   ?>" class="form-control form-control-sm" <?= $rangeName!=='custom'?'disabled':'' ?>></div>
        <div class="col-md-2"><button class="btn btn-sm btn-teal w-100"><?= __('apply') ?></button></div>
    </form>

    <!-- Top KPIs -->
    <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="kpi-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="kpi-label"><?= __('revenue') ?></div>
                    <div class="kpi-value"><?= format_money($revenue) ?></div>
                </div>
                <?= trend_badge($dRev) ?>
            </div>
            <small class="text-muted"><?= __('previous') ?>: <?= format_money($prevRevenue) ?></small>
        </div></div>
        <div class="col-md-3"><div class="kpi-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="kpi-label"><?= __('expenses_label') ?></div>
                    <div class="kpi-value text-warning"><?= format_money($expenses) ?></div>
                </div>
                <?= trend_badge($dExp ? -$dExp : null) ?>
            </div>
            <small class="text-muted"><?= __('previous') ?>: <?= format_money($prevExpenses) ?></small>
        </div></div>
        <div class="col-md-3"><div class="kpi-card" style="background:var(--nt-teal-soft)">
            <div class="kpi-label"><?= __('net_profit') ?></div>
            <div class="kpi-value <?= $profit<0?'text-danger':'' ?>"><?= format_money($profit) ?></div>
            <small class="text-muted"><?= number_format($margin,1) ?>% <?= __('margin') ?></small>
        </div></div>
        <div class="col-md-3"><div class="kpi-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="kpi-label"><?= __('appointments') ?></div>
                    <div class="kpi-value"><?= (int)$appts['total'] ?></div>
                </div>
                <?= trend_badge($dAppts) ?>
            </div>
            <small class="text-muted"><?= $completeRate ?>% <?= __('completed') ?></small>
        </div></div>
    </div>

    <!-- Secondary KPIs -->
    <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="kpi-card">
            <div class="kpi-label"><?= __('no_show_rate') ?></div>
            <div class="kpi-value <?= $noShowRate>10?'text-danger':'' ?>"><?= $noShowRate ?>%</div>
            <small class="text-muted"><?= (int)$appts['no_show'] ?> / <?= (int)$appts['total'] ?></small>
        </div></div>
        <div class="col-md-3"><div class="kpi-card">
            <div class="kpi-label"><?= __('cancellation_rate') ?></div>
            <div class="kpi-value <?= $cancelRate>15?'text-warning':'' ?>"><?= $cancelRate ?>%</div>
            <small class="text-muted"><?= (int)$appts['cancelled'] ?> <?= __('st_cancelled') ?></small>
        </div></div>
        <div class="col-md-3"><div class="kpi-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="kpi-label"><?= __('new_patients') ?></div>
                    <div class="kpi-value"><?= (int)$patients['new'] ?></div>
                </div>
                <?= trend_badge($dNew) ?>
            </div>
            <small class="text-muted">+ <?= (int)$patients['returning'] ?> <?= __('returning') ?></small>
        </div></div>
        <div class="col-md-3"><div class="kpi-card">
            <div class="kpi-label"><?= __('outstanding') ?> (<?= __('all_time') ?>)</div>
            <div class="kpi-value text-danger"><?= format_money($outstanding) ?></div>
            <small class="text-muted"><?= $flagged ?> <?= __('flagged_patients') ?></small>
        </div></div>
    </div>

    <!-- Charts -->
    <div class="row g-3 mb-3">
        <div class="col-lg-8">
            <div class="card h-100"><div class="card-body">
                <h6 class="text-teal"><?= __('revenue_trend') ?></h6>
                <canvas id="chartRevenue" height="90"></canvas>
            </div></div>
        </div>
        <div class="col-lg-4">
            <div class="card h-100"><div class="card-body">
                <h6 class="text-teal"><?= __('appointment_status') ?></h6>
                <canvas id="chartAppts" height="160"></canvas>
            </div></div>
        </div>
    </div>

    <!-- Top tables -->
    <div class="row g-3 mb-3">
        <div class="col-lg-4">
            <div class="card h-100"><div class="card-body">
                <h6 class="text-teal"><?= __('top_services') ?></h6>
                <table class="table table-sm mb-0">
                    <thead><tr><th><?= __('services') ?></th><th class="text-end"><?= __('times') ?></th><th class="text-end"><?= __('revenue') ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($topSvcs as $s): ?>
                            <tr>
                                <td class="small"><?= e($s['name_ar'] ?? '—') ?></td>
                                <td class="text-end"><?= (int)$s['n'] ?></td>
                                <td class="text-end"><?= format_money($s['revenue']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$topSvcs): ?><tr><td colspan="3" class="text-center text-muted">—</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div></div>
        </div>
        <div class="col-lg-4">
            <div class="card h-100"><div class="card-body">
                <h6 class="text-teal"><?= __('top_therapists') ?></h6>
                <table class="table table-sm mb-0">
                    <thead><tr><th><?= __('name') ?></th><th class="text-end"><?= __('sessions') ?></th><th class="text-end"><?= __('revenue') ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($topThers as $t): ?>
                            <tr>
                                <td class="small"><?= e($t['name']) ?></td>
                                <td class="text-end"><?= (int)$t['sessions'] ?></td>
                                <td class="text-end"><?= format_money($t['revenue']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$topThers): ?><tr><td colspan="3" class="text-center text-muted">—</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div></div>
        </div>
        <div class="col-lg-4">
            <div class="card h-100"><div class="card-body">
                <h6 class="text-teal"><?= __('top_packages') ?></h6>
                <table class="table table-sm mb-0">
                    <thead><tr><th><?= __('package') ?></th><th class="text-end"><?= __('sold') ?></th><th class="text-end"><?= __('revenue') ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($topPkgs as $p): ?>
                            <tr>
                                <td class="small"><?= e($p['name_ar']) ?></td>
                                <td class="text-end"><?= (int)$p['sold'] ?></td>
                                <td class="text-end"><?= format_money($p['revenue']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$topPkgs): ?><tr><td colspan="3" class="text-center text-muted">—</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div></div>
        </div>
    </div>

    <!-- Heatmap + AR aging + retention -->
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card h-100"><div class="card-body">
                <h6 class="text-teal"><?= __('peak_hours') ?> <small class="text-muted">(<?= __('weekday_hour') ?>)</small></h6>
                <div class="table-responsive">
                    <table class="heatmap mb-0">
                        <thead><tr><th></th>
                            <?php for ($h = 8; $h <= 21; $h++): ?>
                                <th class="text-center small text-muted"><?= sprintf('%02d', $h) ?></th>
                            <?php endfor; ?>
                        </tr></thead>
                        <tbody>
                            <?php
                            $dowKeys = [1=>'dow_sun_short',2=>'dow_mon_short',3=>'dow_tue_short',4=>'dow_wed_short',5=>'dow_thu_short',6=>'dow_fri_short',7=>'dow_sat_short'];
                            $maxN = max(1, max($heatmap));
                            foreach ($dowKeys as $d => $tKey): ?>
                                <tr>
                                    <td class="small text-muted pe-2"><?= __($tKey) ?></td>
                                    <?php for ($h = 8; $h <= 21; $h++):
                                        $n = $heatmap[$d.'-'.$h] ?? 0;
                                        $pct = $n / $maxN;
                                        $alpha = $n === 0 ? 0.05 : 0.15 + ($pct * 0.85);
                                    ?>
                                        <td title="<?= $n ?>"
                                            style="width:34px;height:30px;background:rgba(20,184,166,<?= $alpha ?>);text-align:center;">
                                            <small class="<?= $n>0?'text-white':'text-muted' ?>"><?= $n ?: '' ?></small>
                                        </td>
                                    <?php endfor; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div></div>
        </div>

        <div class="col-lg-5">
            <div class="card mb-3"><div class="card-body">
                <h6 class="text-teal"><?= __('receivables_aging') ?></h6>
                <div class="row text-center g-2">
                    <div class="col-3"><div class="kpi-card p-2"><div class="kpi-label small">0-30</div><div class="fw-bold"><?= format_money($aging['b_0_30']) ?></div></div></div>
                    <div class="col-3"><div class="kpi-card p-2"><div class="kpi-label small">31-60</div><div class="fw-bold text-warning"><?= format_money($aging['b_31_60']) ?></div></div></div>
                    <div class="col-3"><div class="kpi-card p-2"><div class="kpi-label small">60+</div><div class="fw-bold text-danger"><?= format_money($aging['b_60_plus']) ?></div></div></div>
                    <div class="col-3"><div class="kpi-card p-2" style="background:var(--nt-teal-soft)"><div class="kpi-label small"><?= __('total') ?></div><div class="fw-bold"><?= format_money($aging['total']) ?></div></div></div>
                </div>
            </div></div>

            <div class="card mb-3"><div class="card-body">
                <h6 class="text-teal"><?= __('package_utilization') ?></h6>
                <div class="d-flex justify-content-between mb-1 small">
                    <span><?= $pkgUtil['active_count'] ?> <?= __('active_packages') ?></span>
                    <span><strong><?= $pkgUtil['used'] ?></strong> / <?= $pkgUtil['total'] ?> <?= __('sessions_used') ?></span>
                </div>
                <div class="progress" style="height:14px">
                    <div class="progress-bar bg-teal" style="width:<?= $pkgUtil['avg_pct'] ?>%; background:var(--nt-teal) !important">
                        <?= $pkgUtil['avg_pct'] ?>%
                    </div>
                </div>
            </div></div>

            <div class="card"><div class="card-body">
                <h6 class="text-teal"><?= __('patient_retention_short') ?> <small class="text-muted">(<?= __('last_90_days') ?>)</small></h6>
                <div class="row text-center g-2">
                    <div class="col-4"><div class="kpi-card p-2"><div class="kpi-label small"><?= __('cohort') ?></div><div class="fw-bold"><?= $retention['cohort'] ?></div></div></div>
                    <div class="col-4"><div class="kpi-card p-2"><div class="kpi-label small"><?= __('retained') ?></div><div class="fw-bold text-success"><?= $retention['retained'] ?></div></div></div>
                    <div class="col-4"><div class="kpi-card p-2" style="background:var(--nt-teal-soft)"><div class="kpi-label small"><?= __('rate') ?></div><div class="fw-bold"><?= $retention['rate'] ?>%</div></div></div>
                </div>
            </div></div>
        </div>
    </div>
</div>

<style>
.heatmap td, .heatmap th { padding: 2px 4px; }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const teal = '#14b8a6';
const tealSoft = 'rgba(20,184,166,0.15)';

new Chart(document.getElementById('chartRevenue'), {
    type: 'line',
    data: {
        labels: <?= json_encode($revSeries['labels']) ?>,
        datasets: [{
            label: <?= json_encode(__('revenue')) ?>,
            data: <?= json_encode($revSeries['data']) ?>,
            borderColor: teal,
            backgroundColor: tealSoft,
            fill: true,
            tension: 0.3,
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
    }
});

new Chart(document.getElementById('chartAppts'), {
    type: 'doughnut',
    data: {
        labels: [<?= json_encode(__('scheduled')) ?>, <?= json_encode(__('confirmed')) ?>, <?= json_encode(__('completed')) ?>, <?= json_encode(__('no_show')) ?>, <?= json_encode(__('cancelled')) ?>],
        datasets: [{
            data: [<?= (int)$appts['scheduled'] ?>, <?= (int)$appts['confirmed'] ?>, <?= (int)$appts['completed'] ?>, <?= (int)$appts['no_show'] ?>, <?= (int)$appts['cancelled'] ?>],
            backgroundColor: ['#0dcaf0','#0d6efd','#198754','#ffc107','#dc3545']
        }]
    },
    options: { responsive: true }
});
</script>
<?php include BP_PARTIALS . '/footer.php'; ?>
