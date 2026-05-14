<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('leaves.view');

$PageTitle = __('leaves');
$action    = $_GET['action'] ?? 'list';
$id        = (int)($_GET['id'] ?? 0);
$me        = current_employee();

// ─── Approve / reject / cancel ───────────────────────────────────────
if (in_array($action, ['approve','reject','cancel'], true) && $id) {
    csrf_check();
    if ($action === 'cancel') {
        // Allow employee to cancel own pending request
        $row = db()->prepare("SELECT * FROM leaves WHERE id=?");
        $row->execute([$id]); $lv = $row->fetch();
        if (!$lv) { flash('error','Not found.'); redirect(BP_URL.'admin/leaves.php'); }
        $isMine = $me && (int)$me['id'] === (int)$lv['employee_id'];
        if (!$isMine && !can('leaves.approve')) { http_response_code(403); exit('Forbidden'); }
        db()->prepare("UPDATE leaves SET status='cancelled', updated_at=NOW() WHERE id=?")->execute([$id]);
        log_activity('cancelled','leaves',"Cancelled leave #$id",'leave',$id);
        flash('success','Leave cancelled.');
    } else {
        require_can('leaves.approve');
        $newStatus = $action === 'approve' ? 'approved' : 'rejected';
        $notes = trim($_POST['decision_notes'] ?? '');
        db()->prepare("UPDATE leaves SET status=?, approved_by=?, approved_at=NOW(), decision_notes=?, updated_at=NOW() WHERE id=?")
            ->execute([$newStatus, $_SESSION['user_id'], $notes ?: null, $id]);

        // If approved → mark attendance leave for those days
        if ($newStatus === 'approved') {
            $row = db()->prepare("SELECT * FROM leaves WHERE id=?");
            $row->execute([$id]); $lv = $row->fetch();
            if ($lv) {
                $start = strtotime($lv['start_date']);
                $end   = strtotime($lv['end_date']);
                for ($t = $start; $t <= $end; $t += 86400) {
                    $d = date('Y-m-d', $t);
                    db()->prepare("INSERT INTO attendance (employee_id,work_date,status,source,created_by,created_at,updated_at)
                        VALUES (?,?,'leave','manual',?,NOW(),NOW())
                        ON DUPLICATE KEY UPDATE status='leave', updated_at=NOW()")
                        ->execute([$lv['employee_id'], $d, $_SESSION['user_id']]);
                }
            }
        }

        log_activity($newStatus,'leaves',"Leave #$id $newStatus",'leave',$id);
        flash('success',"Leave $newStatus.");
    }
    redirect(BP_URL.'admin/leaves.php');
}

// ─── Create / request ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    csrf_check(); require_can('leaves.create');
    $empId = (int)($_POST['employee_id'] ?? ($me['id'] ?? 0));
    $type  = $_POST['leave_type'] ?? 'annual';
    $from  = trim($_POST['start_date'] ?? '');
    $to    = trim($_POST['end_date'] ?? '');
    $reason= trim($_POST['reason'] ?? '');

    if (!in_array($type, ['annual','sick','unpaid','maternity','emergency','other'], true)) $type = 'annual';
    if (!$empId || !$from || !$to) { flash('error','Required fields missing.'); back(); }
    if (strtotime($to) < strtotime($from)) { flash('error','End date before start.'); back(); }
    $days = (int)((strtotime($to) - strtotime($from)) / 86400) + 1;

    $att = null;
    if (!empty($_FILES['attachment']['name'])) {
        $up = upload_file($_FILES['attachment'], 'employees/'.$empId, ['jpg','jpeg','png','webp','pdf']);
        if ($up) $att = $up['relative_path'];
    }

    db()->prepare("INSERT INTO leaves
        (employee_id,leave_type,start_date,end_date,days_count,reason,attachment,status,created_at,updated_at)
        VALUES (?,?,?,?,?,?,?, 'pending', NOW(), NOW())")
        ->execute([$empId,$type,$from,$to,$days,$reason,$att]);
    $lid = (int)db()->lastInsertId();
    log_activity('requested','leaves',"Leave request $type ($days days)",'leave',$lid);
    flash('success','Leave request submitted.');
    redirect(BP_URL.'admin/leaves.php');
}

// LIST
$status = trim($_GET['status'] ?? '');
$empF   = (int)($_GET['employee_id'] ?? 0);
$page   = (int)($_GET['page'] ?? 1);
[$perPage, $offset] = paginate_query($page, (int)setting('per_page','25'));

$where = "l.deleted_at IS NULL"; $params = [];
if ($status) { $where .= " AND l.status = ?"; $params[] = $status; }
if ($empF)   { $where .= " AND l.employee_id = ?"; $params[] = $empF; }

$tot = db()->prepare("SELECT COUNT(*) FROM leaves l WHERE $where");
$tot->execute($params); $total = (int)$tot->fetchColumn();

$rows = db()->prepare("
    SELECT l.*, e.code, e.first_name, e.last_name, u.name AS approver
    FROM leaves l
    JOIN employees e ON e.id = l.employee_id
    LEFT JOIN users u ON u.id = l.approved_by
    WHERE $where ORDER BY l.id DESC LIMIT $perPage OFFSET $offset
");
$rows->execute($params); $rows = $rows->fetchAll();

$employees = db()->query("SELECT id,code,first_name,last_name FROM employees WHERE deleted_at IS NULL ORDER BY first_name")->fetchAll();

// KPI stats
$kpi = db()->query("
    SELECT
      SUM(status='pending')  AS pending,
      SUM(status='approved') AS approved,
      SUM(status='rejected') AS rejected,
      COALESCE(SUM(CASE WHEN status='approved' AND YEAR(start_date)=YEAR(CURDATE()) THEN days_count END),0) AS year_days
    FROM leaves WHERE deleted_at IS NULL
")->fetch() ?: ['pending'=>0,'approved'=>0,'rejected'=>0,'year_days'=>0];

include BP_PARTIALS . '/header.php';
?>
<div class="page-wrap">
    <!-- Header -->
    <div class="page-header">
        <h4 class="m-0">
            <i class="fa-solid fa-plane-departure text-teal me-2"></i><?= __('leaves') ?>
            <span class="page-count">(<?= $total ?>)</span>
        </h4>
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
            <div class="appt-kpi-icon" style="background:#10b981"><i class="fa-solid fa-circle-check"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('st_approved') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['approved'] ?></div>
            </div>
        </a>
        <a class="appt-kpi" href="?status=rejected">
            <div class="appt-kpi-icon" style="background:#ef4444"><i class="fa-solid fa-circle-xmark"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('st_rejected') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['rejected'] ?></div>
            </div>
        </a>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#0d9488"><i class="fa-solid fa-calendar-check"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('approved_days_year') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['year_days'] ?></div>
            </div>
        </div>
    </div>

    <?php if (can('leaves.create')): ?>
        <details class="mb-3" <?= $action==='create' ? 'open' : '' ?>>
            <summary class="btn btn-sm btn-outline-teal">+ Request leave</summary>
            <form method="post" action="?action=create" enctype="multipart/form-data" class="card mt-2"><div class="card-body">
                <?= csrf_field() ?>
                <div class="row g-2">
                    <?php if (!$me || can('leaves.approve')): ?>
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
                    <?php else: ?>
                        <input type="hidden" name="employee_id" value="<?= (int)$me['id'] ?>">
                    <?php endif; ?>
                    <div class="col-md-2">
                        <label class="form-label small"><?= __('type') ?></label>
                        <select name="leave_type" class="form-select form-select-sm">
                            <?php foreach (['annual','sick','unpaid','maternity','emergency','other'] as $t): ?>
                                <option value="<?= $t ?>"><?= $t ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2"><label class="form-label small"><?= __('start_label') ?></label>
                        <input type="date" name="start_date" required class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"></div>
                    <div class="col-md-2"><label class="form-label small"><?= __('end_label') ?></label>
                        <input type="date" name="end_date" required class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"></div>
                    <div class="col-md-3"><label class="form-label small"><?= __('attachment') ?></label>
                        <input type="file" name="attachment" class="form-control form-control-sm"></div>
                    <div class="col-md-12"><label class="form-label small"><?= __('reason') ?></label>
                        <textarea name="reason" class="form-control form-control-sm" rows="2"></textarea></div>
                    <div class="col-md-2"><button class="btn btn-sm btn-teal w-100"><?= __('submit') ?></button></div>
                </div>
            </div></form>
        </details>
    <?php endif; ?>

    <form method="get" class="row g-2 mb-3">
        <div class="col-md-2">
            <select name="status" class="form-select form-select-sm">
                <option value=""><?= __('all_statuses') ?></option>
                <?php foreach (['pending','approved','rejected','cancelled'] as $st): ?>
                    <option value="<?= $st ?>" <?= $status===$st?'selected':'' ?>><?= $st ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <select name="employee_id" class="form-select form-select-sm">
                <option value="0"><?= __('all_employees') ?></option>
                <?php foreach ($employees as $em): ?>
                    <option value="<?= (int)$em['id'] ?>" <?= $empF===(int)$em['id']?'selected':'' ?>>[<?= e($em['code']) ?>] <?= e($em['first_name'].' '.$em['last_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><button class="btn btn-sm btn-teal w-100"><?= __('search') ?></button></div>
    </form>

    <div class="table-card">
        <div class="table-responsive d-none d-md-block">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th>#</th><th><?= __('employees') ?></th><th><?= __('type') ?></th><th><?= __('range') ?></th><th><?= __('days_count') ?></th><th><?= __('reason') ?></th><th><?= __('status') ?></th><th><?= __('approver') ?></th><th></th>
            </tr></thead>
            <tbody>
                <?php foreach ($rows as $l):
                    $color = ['pending'=>'warning','approved'=>'success','rejected'=>'danger','cancelled'=>'secondary'][$l['status']];
                ?>
                    <tr>
                        <td><?= (int)$l['id'] ?></td>
                        <td><?= e($l['first_name'].' '.$l['last_name']) ?> <code class="small"><?= e($l['code']) ?></code></td>
                        <td><span class="badge bg-light text-dark"><?= e($l['leave_type']) ?></span></td>
                        <td class="small"><?= e($l['start_date']) ?> → <?= e($l['end_date']) ?></td>
                        <td><?= e($l['days_count']) ?></td>
                        <td class="small text-muted"><?= e(mb_strimwidth($l['reason']??'—',0,60,'…')) ?></td>
                        <td><span class="badge bg-<?= $color ?>"><?= e($l['status']) ?></span></td>
                        <td class="small"><?= e($l['approver']??'—') ?></td>
                        <td class="text-end">
                            <?php if ($l['attachment']): ?>
                                <a class="btn btn-sm btn-light" target="_blank" href="<?= UPLOADS_URL . e($l['attachment']) ?>"><i class="fa-solid fa-paperclip"></i></a>
                            <?php endif; ?>
                            <?php if ($l['status'] === 'pending' && can('leaves.approve')): ?>
                                <form method="post" action="?action=approve&id=<?= (int)$l['id'] ?>" class="d-inline" data-confirm="Approve?">
                                    <?= csrf_field() ?><button class="btn btn-sm btn-success"><i class="fa-solid fa-check"></i></button>
                                </form>
                                <form method="post" action="?action=reject&id=<?= (int)$l['id'] ?>" class="d-inline" data-confirm="Reject?">
                                    <?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-xmark"></i></button>
                                </form>
                            <?php endif; ?>
                            <?php if ($l['status'] === 'pending' && $me && (int)$me['id']===(int)$l['employee_id']): ?>
                                <form method="post" action="?action=cancel&id=<?= (int)$l['id'] ?>" class="d-inline" data-confirm="Cancel my request?">
                                    <?= csrf_field() ?><button class="btn btn-sm btn-light"><i class="fa-solid fa-ban"></i></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4"><?= __('no_data') ?></td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>

        <!-- Mobile entity card list -->
        <div class="entity-list d-md-none">
            <?php if (!$rows): ?>
                <div class="empty-state"><i class="fa-regular fa-calendar-xmark"></i><div><?= __('no_data') ?></div></div>
            <?php else: foreach ($rows as $l):
                $initials = mb_strtoupper(mb_substr($l['first_name'],0,1).mb_substr($l['last_name'],0,1));
                $statusChip = ['pending'=>'warn','approved'=>'success','rejected'=>'danger','cancelled'=>''][$l['status']] ?? '';
                $chips = [
                    ['label'=>__('st_'.$l['status']) ?: $l['status'],'icon'=>'fa-circle-dot','class'=>$statusChip],
                    ['label'=>$l['leave_type'],'icon'=>'fa-tag','class'=>'info'],
                    ['label'=>(int)$l['days_count'].' '.__('days'),'icon'=>'fa-calendar-day','class'=>'teal'],
                ];
                if (!empty($l['attachment'])) $chips[] = ['label'=>__('attachment'),'icon'=>'fa-paperclip','href'=>UPLOADS_URL.e($l['attachment'])];
                echo render_entity_card([
                    'avatar' => $initials,
                    'avatar_class' => 'amber',
                    'title' => $l['first_name'].' '.$l['last_name'],
                    'code' => $l['code'],
                    'meta' => [$l['start_date'].' → '.$l['end_date']],
                    'chips' => $chips,
                ]);
            endforeach; endif; ?>
        </div>

        <div class="p-2"><?= render_pagination($total,$page,$perPage,BP_URL.'admin/leaves.php?'.http_build_query(['status'=>$status,'employee_id'=>$empF])) ?></div>
    </div>
</div>
<?php include BP_PARTIALS . '/footer.php'; ?>
