<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('payroll.view');

$PageTitle = __('payroll');
$action    = $_GET['action'] ?? 'list';
$id        = (int)($_GET['id'] ?? 0);

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));

// ─── Generate (or refresh) payslips for the period ───────────────────
if ($action === 'generate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); require_can('payroll.create');
    $empIds = $_POST['employees'] ?? [];
    if (!$empIds) {
        $empIds = db()->query("SELECT id FROM employees WHERE deleted_at IS NULL AND is_active=1")->fetchAll(PDO::FETCH_COLUMN);
    }
    $count = 0;
    foreach ($empIds as $eid) {
        try {
            generate_payslip((int)$eid, $year, $month, (int)$_SESSION['user_id']);
            $count++;
        } catch (Throwable $e) {
            error_log("Payroll fail emp #$eid: " . $e->getMessage());
        }
    }
    log_activity('generated','payroll',"Generated $count payslips for $year-$month",'payslip',null);
    flash('success', "Generated $count payslip(s).");
    redirect(BP_URL.'admin/payroll.php?year='.$year.'&month='.$month);
}

// ─── Approve / pay / cancel / delete a payslip ───────────────────────
if ($id && in_array($action, ['approve','pay','cancel','delete'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($action === 'approve') {
        require_can('payroll.edit');
        db()->prepare("UPDATE payslips SET status='approved', updated_by=?, updated_at=NOW() WHERE id=? AND status='draft'")
            ->execute([$_SESSION['user_id'],$id]);
        log_activity('approved','payroll',"Approved payslip #$id",'payslip',$id);
        flash('success','Payslip approved.');
    } elseif ($action === 'pay') {
        require_can('payroll.create');
        $method = $_POST['method'] ?? 'bank';
        $ref    = trim($_POST['reference_no'] ?? '') ?: null;
        try {
            pay_payslip($id, $method, $ref, (int)$_SESSION['user_id']);
            log_activity('paid','payroll',"Paid payslip #$id via $method",'payslip',$id);
            flash('success','Payslip paid and posted to expenses.');
        } catch (Throwable $e) {
            flash('error','Failed: '.$e->getMessage());
        }
    } elseif ($action === 'cancel') {
        require_can('payroll.edit');
        db()->prepare("UPDATE payslips SET status='cancelled', updated_by=?, updated_at=NOW() WHERE id=?")
            ->execute([$_SESSION['user_id'],$id]);
        log_activity('cancelled','payroll',"Cancelled payslip #$id",'payslip',$id);
        flash('success','Payslip cancelled.');
    } elseif ($action === 'delete') {
        require_can('payroll.delete');
        db()->prepare("UPDATE payslips SET deleted_at=NOW() WHERE id=?")->execute([$id]);
        log_activity('deleted','payroll',"Deleted payslip #$id",'payslip',$id);
        flash('success','Payslip deleted.');
    }
    redirect(BP_URL.'admin/payroll.php?year='.$year.'&month='.$month);
}

// LIST
$rows = db()->prepare("
    SELECT p.*, e.code, e.first_name, e.last_name, e.department
    FROM payslips p JOIN employees e ON e.id = p.employee_id
    WHERE p.deleted_at IS NULL AND p.period_year = ? AND p.period_month = ?
    ORDER BY e.first_name
");
$rows->execute([$year, $month]);
$rows = $rows->fetchAll();

$employees = db()->query("SELECT id,code,first_name,last_name FROM employees WHERE deleted_at IS NULL AND is_active=1 ORDER BY first_name")->fetchAll();

$totals = ['gross'=>0,'net'=>0,'comm'=>0,'ded'=>0,'adv'=>0];
foreach ($rows as $r) {
    $totals['gross'] += (float)$r['gross_salary'];
    $totals['net']   += (float)$r['net_salary'];
    $totals['comm']  += (float)$r['commissions'];
    $totals['ded']   += (float)$r['deductions'];
    $totals['adv']   += (float)$r['advances_deduct'];
}

include BP_PARTIALS . '/header.php';
?>
<div class="page-wrap">
    <!-- Header -->
    <div class="page-header">
        <h4 class="m-0">
            <i class="fa-solid fa-wallet text-teal me-2"></i><?= __('payroll') ?>
            <small class="text-muted ms-2" style="font-size:.85rem"><?= sprintf('%04d-%02d', $year, $month) ?></small>
        </h4>
        <div class="page-header-actions">
            <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/advances.php">
                <i class="fa-solid fa-money-bill-trend-up me-1"></i><?= __('advances') ?>
            </a>
        </div>
    </div>

    <!-- KPI strip -->
    <div class="appt-kpis">
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#0d9488"><i class="fa-solid fa-file-invoice"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('total_payslips') ?></div>
                <div class="appt-kpi-value"><?= count($rows) ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#6366f1"><i class="fa-solid fa-coins"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('gross') ?></div>
                <div class="appt-kpi-value" style="font-size:1.05rem"><?= format_money($totals['gross']) ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#10b981"><i class="fa-solid fa-arrow-up"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('commissions') ?></div>
                <div class="appt-kpi-value" style="font-size:1.05rem">+<?= format_money($totals['comm']) ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#b45309"><i class="fa-solid fa-money-bill-wave"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('net_payable') ?></div>
                <div class="appt-kpi-value" style="font-size:1.05rem"><?= format_money($totals['net']) ?></div>
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

    <?php if (can('payroll.create')): ?>
        <details class="mb-3">
            <summary class="btn btn-sm btn-outline-teal">▶ Generate / refresh payslips for <?= sprintf('%04d-%02d', $year, $month) ?></summary>
            <form method="post" action="?action=generate&year=<?= $year ?>&month=<?= $month ?>" class="card mt-2"
                  data-confirm="Generate payslips? Existing drafts will be refreshed.">
                <div class="card-body">
                    <?= csrf_field() ?>
                    <p class="small text-muted"><?= __('leave_empty_all') ?></p>
                    <div class="row g-2 mb-2">
                        <?php foreach ($employees as $em): ?>
                            <div class="col-md-3">
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="employees[]" value="<?= (int)$em['id'] ?>">
                                    <span class="form-check-label small">[<?= e($em['code']) ?>] <?= e($em['first_name'].' '.$em['last_name']) ?></span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="btn btn-sm btn-teal"><i class="fa-solid fa-bolt me-1"></i><?= __('run') ?></button>
                </div>
            </form>
        </details>
    <?php endif; ?>

    <div class="table-card">
        <div class="table-responsive d-none d-md-block">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th><?= __('payslip_no') ?></th><th><?= __('employees') ?></th>
                <th class="text-end"><?= __('base') ?></th>
                <th class="text-end"><?= __('comm_short') ?></th>
                <th class="text-end"><?= __('bonus') ?></th>
                <th class="text-end"><?= __('deduct') ?></th>
                <th class="text-end"><?= __('advances') ?></th>
                <th class="text-end"><?= __('gross') ?></th>
                <th class="text-end"><?= __('net') ?></th>
                <th><?= __('status') ?></th><th></th>
            </tr></thead>
            <tbody>
                <?php foreach ($rows as $p):
                    $col = ['draft'=>'secondary','approved'=>'info','paid'=>'success','cancelled'=>'danger'][$p['status']];
                ?>
                    <tr>
                        <td><code><?= e($p['payslip_no']) ?></code></td>
                        <td><?= e($p['first_name'].' '.$p['last_name']) ?> <code class="small"><?= e($p['code']) ?></code></td>
                        <td class="text-end"><?= format_money($p['base_salary']) ?></td>
                        <td class="text-end text-success"><?= format_money($p['commissions']) ?></td>
                        <td class="text-end"><?= format_money($p['bonuses']) ?></td>
                        <td class="text-end text-danger">−<?= format_money($p['deductions']) ?></td>
                        <td class="text-end text-warning">−<?= format_money($p['advances_deduct']) ?></td>
                        <td class="text-end"><?= format_money($p['gross_salary']) ?></td>
                        <td class="text-end"><strong><?= format_money($p['net_salary']) ?></strong></td>
                        <td><span class="badge bg-<?= $col ?>"><?= e($p['status']) ?></span></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-light" target="_blank" href="<?= BP_URL ?>admin/payslip-pdf.php?id=<?= (int)$p['id'] ?>"><i class="fa-solid fa-file-pdf"></i></a>
                            <?php if ($p['status']==='draft' && can('payroll.edit')): ?>
                                <form method="post" action="?action=approve&id=<?= (int)$p['id'] ?>&year=<?= $year ?>&month=<?= $month ?>" class="d-inline">
                                    <?= csrf_field() ?><button class="btn btn-sm btn-info"><i class="fa-solid fa-check"></i></button>
                                </form>
                            <?php endif; ?>
                            <?php if (in_array($p['status'],['draft','approved']) && can('payroll.create')): ?>
                                <form method="post" action="?action=pay&id=<?= (int)$p['id'] ?>&year=<?= $year ?>&month=<?= $month ?>" class="d-inline" data-confirm="Pay & post to expenses?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="method" value="bank">
                                    <button class="btn btn-sm btn-success" title="Pay"><i class="fa-solid fa-money-bill-wave"></i></button>
                                </form>
                            <?php endif; ?>
                            <?php if ($p['status']!=='paid' && can('payroll.edit')): ?>
                                <form method="post" action="?action=cancel&id=<?= (int)$p['id'] ?>&year=<?= $year ?>&month=<?= $month ?>" class="d-inline" data-confirm="Cancel payslip?">
                                    <?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-ban"></i></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="11" class="text-center text-muted py-4"><?= __('no_data') ?></td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>

        <!-- Mobile entity card list -->
        <div class="entity-list d-md-none">
            <?php if (!$rows): ?>
                <div class="empty-state"><i class="fa-regular fa-file-lines"></i><div><?= __('no_data') ?></div></div>
            <?php else: foreach ($rows as $p):
                $initials = mb_strtoupper(mb_substr($p['first_name'],0,1).mb_substr($p['last_name'],0,1));
                $statusChip = ['draft'=>'','approved'=>'info','paid'=>'success','cancelled'=>'danger'][$p['status']] ?? '';
                $avatarColor = ['draft'=>'slate','approved'=>'indigo','paid'=>'success','cancelled'=>'danger'][$p['status']] ?? '';
                $chips = [
                    ['label'=>__('st_'.$p['status']) ?: $p['status'],'icon'=>'fa-circle-dot','class'=>$statusChip],
                ];
                if ((float)$p['commissions']>0) $chips[] = ['label'=>'+'.format_money($p['commissions']),'icon'=>'fa-arrow-up','class'=>'success'];
                if ((float)$p['deductions']>0)  $chips[] = ['label'=>'-'.format_money($p['deductions']),'icon'=>'fa-arrow-down','class'=>'danger'];
                if ((float)$p['advances_deduct']>0) $chips[] = ['label'=>'-'.format_money($p['advances_deduct']),'icon'=>'fa-hand-holding-dollar','class'=>'warn'];
                echo render_entity_card([
                    'avatar' => $initials,
                    'avatar_class' => $avatarColor,
                    'title' => $p['first_name'].' '.$p['last_name'],
                    'title_right' => format_money($p['net_salary']),
                    'code' => $p['payslip_no'],
                    'meta' => [$p['code']],
                    'chips' => $chips,
                    'actions' => [
                        ['icon'=>'fa-file-pdf','label'=>'print','href'=>BP_URL.'admin/payslip-pdf.php?id='.(int)$p['id'],'target'=>'_blank'],
                    ],
                ]);
            endforeach; endif; ?>
        </div>
    </div>
</div>
<?php include BP_PARTIALS . '/footer.php'; ?>
