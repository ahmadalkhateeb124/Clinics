<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('attendance.view');

$PageTitle = __('attendance');
$action    = $_GET['action'] ?? 'list';

// ─── Self check-in / check-out ───────────────────────────────────────
if (in_array($action, ['check_in','check_out'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $me = current_employee();
    if (!$me) { flash('error','You are not registered as an employee.'); redirect(BP_URL.'admin/attendance.php'); }

    $today = date('Y-m-d');
    $row = db()->prepare("SELECT * FROM attendance WHERE employee_id=? AND work_date=?");
    $row->execute([(int)$me['id'], $today]);
    $att = $row->fetch();

    $expectedIn  = setting('working_hours_from','09:00');
    $expectedOut = setting('working_hours_to','17:00');
    $now         = date('Y-m-d H:i:s');

    if ($action === 'check_in') {
        if ($att && $att['check_in']) {
            flash('warning','You already checked in today.');
        } else {
            $expectedDt = $today . ' ' . $expectedIn . ':00';
            $late = max(0, (int)round((strtotime($now) - strtotime($expectedDt)) / 60));
            if ($att) {
                db()->prepare("UPDATE attendance SET check_in=?, late_minutes=?, expected_in=?, expected_out=?, source='self', check_in_ip=?, status='present', updated_at=NOW() WHERE id=?")
                    ->execute([$now, $late, $expectedIn.':00', $expectedOut.':00', client_ip(), (int)$att['id']]);
            } else {
                db()->prepare("INSERT INTO attendance (employee_id,work_date,check_in,expected_in,expected_out,late_minutes,status,source,check_in_ip,created_by,created_at,updated_at)
                    VALUES (?,?,?,?,?,?,'present','self',?,?,NOW(),NOW())")
                    ->execute([(int)$me['id'], $today, $now, $expectedIn.':00', $expectedOut.':00', $late, client_ip(), $_SESSION['user_id']]);
            }
            log_activity('check_in','attendance',"Self check-in",'attendance', null);
            flash('success', $late > 0 ? "Checked in (late by $late min)." : 'Checked in on time.');
        }
    } else {
        if (!$att || !$att['check_in']) {
            flash('error','Check in first.');
        } elseif ($att['check_out']) {
            flash('warning','Already checked out.');
        } else {
            $expectedDt = $today . ' ' . $expectedOut . ':00';
            $early = max(0, (int)round((strtotime($expectedDt) - strtotime($now)) / 60));
            $worked = max(0, (int)round((strtotime($now) - strtotime($att['check_in'])) / 60));
            db()->prepare("UPDATE attendance SET check_out=?, early_leave_minutes=?, worked_minutes=?, check_out_ip=?, updated_at=NOW() WHERE id=?")
                ->execute([$now, $early, $worked, client_ip(), (int)$att['id']]);
            log_activity('check_out','attendance',"Self check-out (worked $worked min)",'attendance', (int)$att['id']);
            flash('success',"Checked out. Worked $worked minutes.");
        }
    }
    redirect(BP_URL.'admin/attendance.php');
}

// ─── Manual create / edit (admin) ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'manual_save') {
    csrf_check(); require_can('attendance.create');
    $empId  = (int)($_POST['employee_id'] ?? 0);
    $date   = trim($_POST['work_date'] ?? date('Y-m-d'));
    $in     = trim($_POST['check_in'] ?? '');
    $out    = trim($_POST['check_out'] ?? '');
    $status = $_POST['status'] ?? 'present';
    $notes  = trim($_POST['notes'] ?? '');

    if (!$empId) { flash('error','Employee required.'); back(); }
    if (!in_array($status, ['present','absent','half_day','leave','holiday','remote'], true)) $status = 'present';

    $checkIn  = $in  ? str_replace('T',' ',$in).':00'  : null;
    $checkOut = $out ? str_replace('T',' ',$out).':00' : null;
    $worked = ($checkIn && $checkOut) ? max(0, (int)round((strtotime($checkOut) - strtotime($checkIn)) / 60)) : 0;

    db()->prepare("INSERT INTO attendance
        (employee_id,work_date,check_in,check_out,worked_minutes,status,source,notes,created_by,created_at,updated_at)
        VALUES (?,?,?,?,?,?,'manual',?,?,NOW(),NOW())
        ON DUPLICATE KEY UPDATE check_in=VALUES(check_in), check_out=VALUES(check_out),
            worked_minutes=VALUES(worked_minutes), status=VALUES(status), notes=VALUES(notes),
            updated_by=VALUES(created_by), updated_at=NOW()")
        ->execute([$empId,$date,$checkIn,$checkOut,$worked,$status,$notes,$_SESSION['user_id']]);

    log_activity('manual_attendance','attendance',"Marked $status for emp #$empId on $date",'attendance', null);
    flash('success','Attendance saved.');
    redirect(BP_URL.'admin/attendance.php?date='.$date);
}

// LIST
$date = trim($_GET['date'] ?? date('Y-m-d'));
$empF = (int)($_GET['employee_id'] ?? 0);
$me   = current_employee();

// Today's row for the logged-in employee (used for self-service banner)
$myToday = null;
if ($me) {
    $r = db()->prepare("SELECT * FROM attendance WHERE employee_id=? AND work_date=?");
    $r->execute([(int)$me['id'], date('Y-m-d')]);
    $myToday = $r->fetch();
}

$where = "a.work_date = ? AND a.deleted_at IS NULL"; $params = [$date];
if ($empF) { $where .= " AND a.employee_id = ?"; $params[] = $empF; }

$rows = db()->prepare("
    SELECT a.*, e.code, e.first_name, e.last_name, e.department
    FROM attendance a JOIN employees e ON e.id = a.employee_id
    WHERE $where ORDER BY a.check_in IS NULL, a.check_in
");
$rows->execute($params); $rows = $rows->fetchAll();

$employees = db()->query("SELECT id,code,first_name,last_name FROM employees WHERE deleted_at IS NULL AND is_active=1 ORDER BY first_name")->fetchAll();

// KPI stats for the day
$kpi = ['present'=>0,'absent'=>0,'late'=>0,'remote'=>0];
foreach ($rows as $r) {
    $st = $r['status'];
    if ($st === 'present')  $kpi['present']++;
    if ($st === 'absent')   $kpi['absent']++;
    if ($st === 'remote')   $kpi['remote']++;
    if ((int)$r['late_minutes'] > 0) $kpi['late']++;
}

include BP_PARTIALS . '/header.php';
?>
<div class="page-wrap">
    <!-- Header -->
    <div class="page-header">
        <h4 class="m-0">
            <i class="fa-solid fa-clock text-teal me-2"></i><?= __('attendance') ?>
            <small class="text-muted ms-2" style="font-size:.85rem"><?= e($date) ?></small>
        </h4>
        <div class="page-header-actions">
            <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/attendance-month.php">
                <i class="fa-regular fa-calendar me-1"></i><?= __('monthly_summary') ?>
            </a>
        </div>
    </div>

    <?php if ($me): ?>
        <div class="card mb-3"><div class="card-body">
            <div class="d-flex justify-content-between flex-wrap align-items-center">
                <div>
                    <strong><?= e($me['first_name'].' '.$me['last_name']) ?></strong>
                    <code class="ms-1"><?= e($me['code']) ?></code>
                    <?php if ($myToday && $myToday['check_in']): ?>
                        <div class="small text-muted">
                            <?= __('check_in') ?>: <strong><?= format_date($myToday['check_in'],'H:i') ?></strong>
                            <?php if ((int)$myToday['late_minutes'] > 0): ?>
                                <span class="badge bg-warning ms-1"><?= __('late_label') ?> <?= (int)$myToday['late_minutes'] ?>m</span>
                            <?php endif; ?>
                            <?php if ($myToday['check_out']): ?>
                                · <?= __('check_out') ?>: <strong><?= format_date($myToday['check_out'],'H:i') ?></strong>
                                · <?= __('worked') ?>: <strong><?= (int)$myToday['worked_minutes'] ?>m</strong>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="small text-muted"><?= __('not_checked_in') ?></div>
                    <?php endif; ?>
                </div>
                <div class="d-flex gap-2">
                    <?php if (!$myToday || !$myToday['check_in']): ?>
                        <form method="post" action="?action=check_in"><?= csrf_field() ?>
                            <button class="btn btn-sm btn-success"><i class="fa-solid fa-right-to-bracket me-1"></i><?= __('check_in') ?></button>
                        </form>
                    <?php elseif (!$myToday['check_out']): ?>
                        <form method="post" action="?action=check_out"><?= csrf_field() ?>
                            <button class="btn btn-sm btn-warning"><i class="fa-solid fa-right-from-bracket me-1"></i><?= __('check_out') ?></button>
                        </form>
                    <?php else: ?>
                        <span class="badge bg-success"><i class="fa-solid fa-check me-1"></i><?= __('done_for_today') ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div></div>
    <?php endif; ?>

    <!-- KPI strip -->
    <div class="appt-kpis">
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#10b981"><i class="fa-solid fa-user-check"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('st_present') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['present'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#ef4444"><i class="fa-solid fa-user-xmark"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('st_absent') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['absent'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#f59e0b"><i class="fa-solid fa-hourglass-half"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('late_label') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['late'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#6366f1"><i class="fa-solid fa-house-laptop"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('st_remote') ?: 'Remote' ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['remote'] ?></div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <form method="get" class="appt-filters">
        <div class="appt-filter-group">
            <span class="appt-filter-icon"><i class="fa-regular fa-calendar"></i></span>
            <input type="date" name="date" value="<?= e($date) ?>" class="form-control form-control-sm">
        </div>
        <div class="appt-filter-group flex-grow-1">
            <select name="employee_id" class="form-select form-select-sm">
                <option value="0"><?= __('all_employees') ?></option>
                <?php foreach ($employees as $em): ?>
                    <option value="<?= (int)$em['id'] ?>" <?= $empF===(int)$em['id']?'selected':'' ?>>
                        [<?= e($em['code']) ?>] <?= e($em['first_name'].' '.$em['last_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-teal btn-sm"><i class="fa-solid fa-filter me-1"></i><?= __('apply') ?></button>
    </form>

    <?php if (can('attendance.create')): ?>
        <details class="mb-3">
            <summary class="btn btn-sm btn-outline-teal">+ Mark attendance manually</summary>
            <form method="post" action="?action=manual_save" class="card mt-2"><div class="card-body">
                <?= csrf_field() ?>
                <div class="row g-2">
                    <div class="col-md-3">
                        <label class="form-label small"><?= __('employees') ?></label>
                        <select name="employee_id" required class="form-select form-select-sm">
                            <option value="">—</option>
                            <?php foreach ($employees as $em): ?>
                                <option value="<?= (int)$em['id'] ?>">[<?= e($em['code']) ?>] <?= e($em['first_name'].' '.$em['last_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2"><label class="form-label small"><?= __('date') ?></label>
                        <input type="date" name="work_date" value="<?= e($date) ?>" class="form-control form-control-sm"></div>
                    <div class="col-md-2"><label class="form-label small"><?= __('check_in') ?></label>
                        <input type="datetime-local" name="check_in" class="form-control form-control-sm"></div>
                    <div class="col-md-2"><label class="form-label small"><?= __('check_out') ?></label>
                        <input type="datetime-local" name="check_out" class="form-control form-control-sm"></div>
                    <div class="col-md-2"><label class="form-label small"><?= __('status') ?></label>
                        <select name="status" class="form-select form-select-sm">
                            <?php foreach (['present','absent','half_day','leave','holiday','remote'] as $st): ?>
                                <option value="<?= $st ?>"><?= str_replace('_',' ',$st) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-1 d-flex align-items-end"><button class="btn btn-sm btn-teal w-100"><?= __('save') ?></button></div>
                    <div class="col-md-12"><input name="notes" class="form-control form-control-sm" placeholder="Notes (optional)"></div>
                </div>
            </div></form>
        </details>
    <?php endif; ?>

    <div class="table-card">
        <div class="table-responsive d-none d-md-block">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th><?= __('code') ?></th><th><?= __('name') ?></th><th><?= __('department') ?></th>
                <th><?= __('check_in') ?></th><th><?= __('check_out') ?></th>
                <th class="text-end"><?= __('late_label') ?></th><th class="text-end"><?= __('worked') ?></th>
                <th><?= __('status') ?></th><th><?= __('source') ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ($rows as $a):
                    $col = ['present'=>'success','absent'=>'danger','half_day'=>'warning','leave'=>'info','holiday'=>'secondary','remote'=>'primary'][$a['status']] ?? 'light';
                ?>
                    <tr>
                        <td><code><?= e($a['code']) ?></code></td>
                        <td><?= e($a['first_name'].' '.$a['last_name']) ?></td>
                        <td class="small text-muted"><?= e($a['department']??'—') ?></td>
                        <td class="small"><?= $a['check_in']  ? format_date($a['check_in'],'H:i')  : '—' ?></td>
                        <td class="small"><?= $a['check_out'] ? format_date($a['check_out'],'H:i') : '—' ?></td>
                        <td class="text-end <?= $a['late_minutes']>0?'text-warning':'' ?>"><?= (int)$a['late_minutes'] ?>m</td>
                        <td class="text-end"><?= (int)$a['worked_minutes'] ?>m</td>
                        <td><span class="badge bg-<?= $col ?>"><?= str_replace('_',' ',$a['status']) ?></span></td>
                        <td><span class="badge bg-light text-dark"><?= e($a['source']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4"><?= __('no_data') ?></td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>

        <!-- Mobile entity card list -->
        <div class="entity-list d-md-none">
            <?php if (!$rows): ?>
                <div class="empty-state"><i class="fa-regular fa-clock"></i><div><?= __('no_data') ?></div></div>
            <?php else: foreach ($rows as $a):
                $initials = mb_strtoupper(mb_substr($a['first_name'],0,1).mb_substr($a['last_name'],0,1));
                $statusChip = ['present'=>'success','absent'=>'danger','half_day'=>'warn','leave'=>'info','holiday'=>'','remote'=>'info'][$a['status']] ?? '';
                $avatarColor = ['present'=>'success','absent'=>'danger','half_day'=>'amber','leave'=>'indigo','holiday'=>'slate','remote'=>'indigo'][$a['status']] ?? '';
                $chips = [
                    ['label'=>str_replace('_',' ',$a['status']),'icon'=>'fa-circle-dot','class'=>$statusChip],
                ];
                if ($a['check_in'])  $chips[] = ['label'=>format_date($a['check_in'],'H:i'),'icon'=>'fa-arrow-right-to-bracket','class'=>'success'];
                if ($a['check_out']) $chips[] = ['label'=>format_date($a['check_out'],'H:i'),'icon'=>'fa-arrow-right-from-bracket','class'=>'info'];
                if ((int)$a['late_minutes']>0) $chips[] = ['label'=>(int)$a['late_minutes'].'m','icon'=>'fa-hourglass-half','class'=>'warn'];
                if ((int)$a['worked_minutes']>0) $chips[] = ['label'=>(int)$a['worked_minutes'].'m','icon'=>'fa-clock','class'=>'teal'];
                echo render_entity_card([
                    'avatar' => $initials,
                    'avatar_class' => $avatarColor,
                    'title' => $a['first_name'].' '.$a['last_name'],
                    'code' => $a['code'],
                    'meta' => !empty($a['department']) ? [$a['department']] : [],
                    'chips' => $chips,
                ]);
            endforeach; endif; ?>
        </div>
    </div>
</div>
<?php include BP_PARTIALS . '/footer.php'; ?>
