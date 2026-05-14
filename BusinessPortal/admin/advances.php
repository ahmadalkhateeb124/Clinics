<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('payroll.view');

$PageTitle = __('salary_advances');
$action    = $_GET['action'] ?? 'list';
$id        = (int)($_GET['id'] ?? 0);
$me        = current_employee();

if (in_array($action, ['approve','reject','disburse'], true) && $id) {
    csrf_check(); require_can('payroll.edit');

    $row = db()->prepare("SELECT a.*, e.code, e.first_name, e.last_name FROM advances a JOIN employees e ON e.id=a.employee_id WHERE a.id=?");
    $row->execute([$id]); $adv = $row->fetch();
    if (!$adv) { flash('error','Not found.'); redirect(BP_URL.'admin/advances.php'); }

    if ($action === 'approve') {
        db()->prepare("UPDATE advances SET status='approved', approved_by=?, approved_at=NOW(), updated_at=NOW() WHERE id=?")
            ->execute([$_SESSION['user_id'], $id]);
        log_activity('approved','advances',"Approved advance #$id",'advance',$id);
        flash('success','Advance approved.');
    } elseif ($action === 'reject') {
        db()->prepare("UPDATE advances SET status='rejected', approved_by=?, approved_at=NOW(), updated_at=NOW() WHERE id=?")
            ->execute([$_SESSION['user_id'], $id]);
        log_activity('rejected','advances',"Rejected advance #$id",'advance',$id);
        flash('success','Advance rejected.');
    } elseif ($action === 'disburse') {
        // Post to expenses + mark disbursed
        $catId = (int)db()->query("SELECT id FROM expense_categories WHERE slug='salaries' LIMIT 1")->fetchColumn();
        db()->prepare("INSERT INTO expenses
            (category_id,title,amount,expense_date,payment_method,vendor,notes,created_by,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())")
            ->execute([
                $catId ?: null,
                'Salary advance — '.$adv['first_name'].' '.$adv['last_name'].' ['.$adv['code'].']',
                (float)$adv['amount'], date('Y-m-d'), 'cash',
                'Employee '.$adv['code'], 'Salary advance', $_SESSION['user_id']
            ]);
        $eid = (int)db()->lastInsertId();
        db()->prepare("UPDATE advances SET status='disbursed', disbursed_at=NOW(), expense_id=?, updated_at=NOW() WHERE id=?")
            ->execute([$eid, $id]);
        log_activity('disbursed','advances',"Disbursed advance #$id (expense #$eid)",'advance',$id);
        flash('success','Advance disbursed and posted to expenses.');
    }
    redirect(BP_URL.'admin/advances.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    csrf_check(); require_can('payroll.view');
    $empId  = (int)($_POST['employee_id'] ?? ($me['id'] ?? 0));
    $amount = (float)($_POST['amount'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $date   = trim($_POST['request_date'] ?? date('Y-m-d'));
    if (!$empId || $amount <= 0) { flash('error','Required fields missing.'); back(); }

    db()->prepare("INSERT INTO advances (employee_id,amount,request_date,reason,status,created_at,updated_at)
        VALUES (?,?,?,?, 'pending', NOW(), NOW())")
        ->execute([$empId,$amount,$date,$reason]);
    $aid = (int)db()->lastInsertId();
    log_activity('requested','advances',"Advance request ".format_money($amount),'advance',$aid);
    flash('success','Advance requested.');
    redirect(BP_URL.'admin/advances.php');
}

// LIST
$status = trim($_GET['status'] ?? '');
$where  = "a.deleted_at IS NULL"; $params = [];
if ($status) { $where .= " AND a.status=?"; $params[] = $status; }

$rows = db()->prepare("
    SELECT a.*, e.code, e.first_name, e.last_name, u.name AS approver
    FROM advances a JOIN employees e ON e.id=a.employee_id
    LEFT JOIN users u ON u.id=a.approved_by
    WHERE $where ORDER BY a.id DESC
");
$rows->execute($params); $rows = $rows->fetchAll();

$employees = db()->query("SELECT id,code,first_name,last_name FROM employees WHERE deleted_at IS NULL ORDER BY first_name")->fetchAll();

// KPI stats
$kpi = db()->query("
    SELECT
      SUM(status='pending')   AS pending,
      SUM(status='approved')  AS approved,
      SUM(status='disbursed') AS disbursed,
      COALESCE(SUM(CASE WHEN status='disbursed' THEN amount END),0) AS outstanding
    FROM advances WHERE deleted_at IS NULL
")->fetch() ?: ['pending'=>0,'approved'=>0,'disbursed'=>0,'outstanding'=>0];

include BP_PARTIALS . '/header.php';
?>
<div class="page-wrap">
    <!-- Header -->
    <div class="page-header">
        <h4 class="m-0">
            <i class="fa-solid fa-money-bill-trend-up text-teal me-2"></i><?= __('salary_advances') ?>
        </h4>
        <div class="page-header-actions">
            <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/payroll.php">
                <i class="fa-solid fa-wallet me-1"></i><?= __('payroll') ?>
            </a>
        </div>
    </div>

    <!-- KPI strip -->
    <div class="appt-kpis">
        <a class="appt-kpi" href="?status=pending">
            <div class="appt-kpi-icon" style="background:#f59e0b"><i class="fa-solid fa-hourglass-half"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('st_pending') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['pending'] ?></div>
            </div>
        </a>
        <a class="appt-kpi" href="?status=approved">
            <div class="appt-kpi-icon" style="background:#6366f1"><i class="fa-solid fa-circle-check"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('st_approved') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['approved'] ?></div>
            </div>
        </a>
        <a class="appt-kpi" href="?status=disbursed">
            <div class="appt-kpi-icon" style="background:#10b981"><i class="fa-solid fa-hand-holding-dollar"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('st_disbursed') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['disbursed'] ?></div>
            </div>
        </a>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#b45309"><i class="fa-solid fa-coins"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('outstanding_advances') ?></div>
                <div class="appt-kpi-value" style="font-size:1.05rem"><?= format_money($kpi['outstanding']) ?></div>
            </div>
        </div>
    </div>

    <details class="mb-3">
        <summary class="btn btn-sm btn-outline-teal"><i class="fa-solid fa-plus me-1"></i><?= __('request_advance') ?></summary>
        <form method="post" action="?action=create" class="card mt-2"><div class="card-body">
            <?= csrf_field() ?>
            <div class="row g-2">
                <div class="col-md-3">
                    <label class="form-label small"><?= __('employees') ?></label>
                    <select name="employee_id" required class="form-select form-select-sm">
                        <option value="">—</option>
                        <?php foreach ($employees as $em): ?>
                            <option value="<?= (int)$em['id'] ?>" <?= ($me && (int)$me['id']===(int)$em['id'])?'selected':'' ?>>
                                [<?= e($em['code']) ?>] <?= e($em['first_name'].' '.$em['last_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small"><?= __('amount') ?></label>
                    <input type="number" step="0.01" min="0.01" name="amount" required class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small"><?= __('request_date') ?></label>
                    <input type="date" name="request_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small"><?= __('reason') ?></label>
                    <input name="reason" class="form-control form-control-sm">
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button class="btn btn-sm btn-teal w-100"><?= __('submit') ?></button>
                </div>
            </div>
        </div></form>
    </details>

    <form method="get" class="row g-2 mb-3">
        <div class="col-md-2">
            <select name="status" class="form-select form-select-sm">
                <option value=""><?= __('all_statuses') ?></option>
                <?php foreach (['pending','approved','disbursed','rejected','deducted'] as $st): ?>
                    <option value="<?= $st ?>" <?= $status===$st?'selected':'' ?>><?= __('st_'.$st) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><button class="btn btn-sm btn-teal w-100"><?= __('search') ?></button></div>
    </form>

    <div class="table-card">
        <div class="table-responsive d-none d-md-block">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th>#</th><th><?= __('employees') ?></th><th><?= __('date') ?></th><th class="text-end"><?= __('amount') ?></th>
                <th><?= __('reason') ?></th><th><?= __('status') ?></th><th><?= __('approver') ?></th><th></th>
            </tr></thead>
            <tbody>
                <?php foreach ($rows as $a):
                    $col = ['pending'=>'warning','approved'=>'info','disbursed'=>'success','rejected'=>'danger','deducted'=>'secondary'][$a['status']];
                ?>
                    <tr>
                        <td><?= (int)$a['id'] ?></td>
                        <td><?= e($a['first_name'].' '.$a['last_name']) ?> <code class="small"><?= e($a['code']) ?></code></td>
                        <td class="small"><?= e($a['request_date']) ?></td>
                        <td class="text-end"><strong><?= format_money($a['amount']) ?></strong></td>
                        <td class="small text-muted"><?= e($a['reason']??'—') ?></td>
                        <td><span class="badge bg-<?= $col ?>"><?= __('st_'.$a['status']) ?: e($a['status']) ?></span></td>
                        <td class="small"><?= e($a['approver']??'—') ?></td>
                        <td class="text-end">
                            <?php
                            $actions = [];
                            if (can('payroll.edit')) {
                                if ($a['status']==='pending') {
                                    $actions[] = ['icon'=>'fa-check','label'=>'approve','href'=>'?action=approve&id='.(int)$a['id'].'&_csrf='.csrf_token(),'confirm'=>'are_you_sure'];
                                    $actions[] = ['icon'=>'fa-xmark','label'=>'reject','href'=>'?action=reject&id='.(int)$a['id'].'&_csrf='.csrf_token(),'confirm'=>'are_you_sure','danger'=>true];
                                } elseif ($a['status']==='approved') {
                                    $actions[] = ['icon'=>'fa-money-bill-wave','label'=>'disburse','href'=>'?action=disburse&id='.(int)$a['id'].'&_csrf='.csrf_token(),'confirm'=>'are_you_sure'];
                                }
                            }
                            echo render_actions($actions);
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4"><?= __('no_data') ?></td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>

        <!-- Mobile entity card list -->
        <div class="entity-list d-md-none">
            <?php if (!$rows): ?>
                <div class="empty-state"><i class="fa-regular fa-hand"></i><div><?= __('no_data') ?></div></div>
            <?php else: foreach ($rows as $a):
                $initials = mb_strtoupper(mb_substr($a['first_name'],0,1).mb_substr($a['last_name'],0,1));
                $statusChip = ['pending'=>'warn','approved'=>'info','disbursed'=>'success','rejected'=>'danger','deducted'=>''][$a['status']] ?? '';
                echo render_entity_card([
                    'avatar' => $initials,
                    'avatar_class' => 'amber',
                    'title' => $a['first_name'].' '.$a['last_name'],
                    'title_right' => format_money($a['amount']),
                    'code' => $a['code'],
                    'meta' => [$a['request_date']],
                    'chips' => [
                        ['label'=>__('st_'.$a['status']) ?: $a['status'],'icon'=>'fa-circle-dot','class'=>$statusChip],
                    ],
                ]);
            endforeach; endif; ?>
        </div>
    </div>
</div>
<?php include BP_PARTIALS . '/footer.php'; ?>
