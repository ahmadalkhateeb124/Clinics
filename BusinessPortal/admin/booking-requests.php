<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('appointments.view');

$PageTitle = __('online_booking_requests');
$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

// Soft-delete a booking request
if ($id && $action === 'delete') {
    csrf_check(); require_can('appointments.edit');
    db()->prepare("UPDATE booking_requests SET deleted_at=NOW() WHERE id=?")->execute([$id]);
    log_activity('deleted','booking_requests',"Deleted booking request #$id",'booking_request',$id);
    flash('success', __('booking_request_deleted'));
    redirect(BP_URL.'admin/booking-requests.php');
}

// Mark as contacted / rejected
if ($id && in_array($action, ['mark_contacted','reject'], true)) {
    csrf_check(); require_can('appointments.edit');
    $newStatus = $action === 'mark_contacted' ? 'contacted' : 'rejected';
    db()->prepare("UPDATE booking_requests SET status=?, updated_at=NOW() WHERE id=?")
        ->execute([$newStatus, $id]);
    log_activity($newStatus,'booking_requests',"Booking #$id → $newStatus",'booking_request',$id);
    flash('success', __('br_'.$newStatus.'_done'));
    redirect(BP_URL.'admin/booking-requests.php');
}

// Convert request → real appointment (creates patient if not existing)
if ($id && $action === 'convert') {
    csrf_check(); require_can('appointments.create');
    $br = db()->prepare("SELECT * FROM booking_requests WHERE id=? AND deleted_at IS NULL");
    $br->execute([$id]); $req = $br->fetch();
    if (!$req) { flash('error', __('not_found')); redirect(BP_URL.'admin/booking-requests.php'); }
    if ($req['status'] === 'converted') { flash('warning', __('already_converted')); redirect(BP_URL.'admin/booking-requests.php'); }

    // Find or create patient
    $patientId = (int)($req['patient_id'] ?? 0);
    if (!$patientId) {
        $existing = db()->prepare("SELECT id FROM patients WHERE phone=? AND deleted_at IS NULL LIMIT 1");
        $existing->execute([$req['phone']]);
        $patientId = (int)$existing->fetchColumn();
    }
    if (!$patientId) {
        $parts = explode(' ', trim($req['patient_name']), 2);
        $first = $parts[0] ?? $req['patient_name'];
        $last  = $parts[1] ?? '—';
        db()->prepare("INSERT INTO patients (code,first_name,last_name,phone,email,gender,created_by,created_at,updated_at)
            VALUES (?,?,?,?,?, 'female', ?, NOW(), NOW())")
            ->execute([next_patient_code(), $first, $last, $req['phone'], $req['email'] ?: null, $_SESSION['user_id']]);
        $patientId = (int)db()->lastInsertId();
    }

    // Build appointment
    $svcId = (int)($req['service_id'] ?? 0);
    $duration = 60; $price = 0; $svcRow = null;
    if ($svcId) {
        $sv = db()->prepare("SELECT name_ar,duration_minutes,price,commission_pct FROM services WHERE id=? AND deleted_at IS NULL");
        $sv->execute([$svcId]); $svcRow = $sv->fetch();
        if ($svcRow) { $duration = (int)$svcRow['duration_minutes']; $price = (float)$svcRow['price']; }
    }
    $startTs = strtotime($req['requested_at']);
    $endTs   = $startTs + $duration * 60;
    $startSql = date('Y-m-d H:i:s', $startTs);
    $endSql   = date('Y-m-d H:i:s', $endTs);

    db()->prepare("INSERT INTO appointments
        (patient_id,therapist_id,start_at,end_at,duration_minutes,status,total_price,notes,created_by,created_at,updated_at)
        VALUES (?,?,?,?,?, 'scheduled', ?, ?, ?, NOW(), NOW())")
        ->execute([$patientId, $req['therapist_id'] ?: null, $startSql, $endSql, $duration, $price,
                   "Converted from booking request #$id\n" . ($req['notes'] ?? ''), $_SESSION['user_id']]);
    $aid = (int)db()->lastInsertId();

    if ($svcId && $svcRow) {
        db()->prepare("INSERT INTO appointment_services (appointment_id,service_id,price,commission_pct,duration_minutes) VALUES (?,?,?,?,?)")
            ->execute([$aid, $svcId, $price, (float)$svcRow['commission_pct'], $duration]);
    }

    db()->prepare("UPDATE booking_requests SET status='converted', appointment_id=?, patient_id=?, updated_at=NOW() WHERE id=?")
        ->execute([$aid, $patientId, $id]);

    log_activity('converted','booking_requests',"Converted request #$id → appointment #$aid",'booking_request',$id);
    flash('success', __('booking_converted'));
    redirect(BP_URL.'admin/appointments.php?action=view&id='.$aid);
}

$status = trim($_GET['status'] ?? '');
$where = "br.deleted_at IS NULL"; $params = [];
if ($status) { $where .= " AND br.status = ?"; $params[] = $status; }

$rows = db()->prepare("
    SELECT br.*, s.name_ar AS svc, u.name AS ther
    FROM booking_requests br
    LEFT JOIN services s ON s.id = br.service_id
    LEFT JOIN users u ON u.id = br.therapist_id
    WHERE $where ORDER BY br.id DESC LIMIT 100
");
$rows->execute($params); $rows = $rows->fetchAll();

// KPI stats
$kpi = db()->query("
    SELECT
      SUM(status='pending')   AS pending,
      SUM(status='contacted') AS contacted,
      SUM(status='converted') AS converted,
      SUM(DATE(created_at)=CURDATE()) AS today
    FROM booking_requests WHERE deleted_at IS NULL
")->fetch() ?: ['pending'=>0,'contacted'=>0,'converted'=>0,'today'=>0];

include BP_PARTIALS.'/header.php';
?>
<div class="page-wrap">
    <!-- Header -->
    <div class="page-header">
        <h4 class="m-0">
            <i class="fa-regular fa-calendar-check text-teal me-2"></i><?= __('online_booking_requests') ?>
            <span class="page-count">(<?= count($rows) ?>)</span>
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
        <a class="appt-kpi" href="?status=contacted">
            <div class="appt-kpi-icon" style="background:#0ea5e9"><i class="fa-solid fa-phone"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('br_contacted') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['contacted'] ?></div>
            </div>
        </a>
        <a class="appt-kpi" href="?status=converted">
            <div class="appt-kpi-icon" style="background:#10b981"><i class="fa-solid fa-check-double"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('br_converted') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['converted'] ?></div>
            </div>
        </a>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#0d9488"><i class="fa-solid fa-calendar-day"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('today') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['today'] ?></div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <form method="get" class="appt-filters">
        <div class="appt-filter-group flex-grow-1">
            <select name="status" class="form-select form-select-sm">
                <option value=""><?= __('all_statuses') ?></option>
                <?php foreach (['pending','contacted','confirmed','converted','rejected'] as $st): ?>
                    <option value="<?= $st ?>" <?= $status===$st?'selected':'' ?>><?= __('br_'.$st) ?: __('st_'.$st) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-teal btn-sm"><i class="fa-solid fa-filter me-1"></i><?= __('apply') ?></button>
        <?php if ($status !== ''): ?>
            <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/booking-requests.php" title="<?= __('clear_filters') ?>"><i class="fa-solid fa-xmark"></i></a>
        <?php endif; ?>
    </form>

    <!-- Status colors map (same as appointments) -->
    <?php
        $statusBg = ['pending'=>'amber','contacted'=>'indigo','confirmed'=>'info','converted'=>'success','rejected'=>'danger'];
        $statusChip = ['pending'=>'warn','contacted'=>'info','confirmed'=>'info','converted'=>'success','rejected'=>'danger'];
    ?>

    <div class="table-card">
        <!-- Desktop table -->
        <div class="table-responsive d-none d-md-block">
            <table class="table mb-0 align-middle">
                <thead><tr>
                    <th>#</th><th><?= __('submitted') ?></th><th><?= __('patient_name') ?></th><th><?= __('phone') ?></th><th><?= __('services') ?></th>
                    <th><?= __('therapist') ?></th><th><?= __('requested_for') ?></th><th><?= __('status') ?></th><th></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($rows as $r):
                        $col = ['pending'=>'warning','contacted'=>'info','confirmed'=>'primary','converted'=>'success','rejected'=>'danger'][$r['status']];
                    ?>
                        <tr>
                            <td><?= (int)$r['id'] ?></td>
                            <td class="small"><?= format_date($r['created_at'],'Y-m-d H:i') ?></td>
                            <td><?= e($r['patient_name']) ?></td>
                            <td class="small" dir="ltr"><?= e($r['phone']) ?>
                                <?php if ($r['email']): ?><div class="text-muted small"><?= e($r['email']) ?></div><?php endif; ?>
                            </td>
                            <td class="small"><?= e($r['svc']??'—') ?></td>
                            <td class="small"><?= e($r['ther']??'—') ?></td>
                            <td class="small"><?= format_date($r['requested_at'],'Y-m-d H:i') ?></td>
                            <td><span class="badge bg-<?= $col ?>"><?= __('br_'.$r['status']) ?: e($r['status']) ?></span></td>
                            <td class="text-end">
                                <?= render_actions([
                                    (!empty($r['appointment_id'])) ? ['icon'=>'fa-eye','label'=>'view','href'=>BP_URL.'admin/appointments.php?action=view&id='.(int)$r['appointment_id']] : null,
                                    ($r['status']==='pending' && can('appointments.create')) ? ['icon'=>'fa-check-double','label'=>'convert_to_appointment','href'=>'?action=convert&id='.(int)$r['id'].'&_csrf='.csrf_token(),'confirm'=>'confirm_convert'] : null,
                                    (!in_array($r['status'],['converted','rejected'],true) && can('appointments.edit')) ? ['icon'=>'fa-phone','label'=>'mark_contacted','href'=>'?action=mark_contacted&id='.(int)$r['id'].'&_csrf='.csrf_token()] : null,
                                    (!in_array($r['status'],['converted','rejected'],true) && can('appointments.edit')) ? ['icon'=>'fa-xmark','label'=>'reject','href'=>'?action=reject&id='.(int)$r['id'].'&_csrf='.csrf_token(),'confirm'=>'confirm_reject','divider_before'=>true] : null,
                                    (can('appointments.edit')) ? ['icon'=>'fa-trash','label'=>'delete','href'=>'?action=delete&id='.(int)$r['id'].'&_csrf='.csrf_token(),'danger'=>true,'confirm'=>'confirm_delete'] : null,
                                ]) ?>
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
                <div class="empty-state"><i class="fa-regular fa-calendar-check"></i><div><?= __('no_data') ?></div></div>
            <?php else: foreach ($rows as $r):
                $initials = mb_strtoupper(mb_substr($r['patient_name'],0,1));
                $chips = [['label'=>__('br_'.$r['status']) ?: $r['status'],'icon'=>'fa-circle-dot','class'=>$statusChip[$r['status']] ?? '']];
                if (!empty($r['svc']))  $chips[] = ['label'=>$r['svc'],'icon'=>'fa-hand-holding-medical','class'=>'teal'];
                if (!empty($r['ther'])) $chips[] = ['label'=>$r['ther'],'icon'=>'fa-user-doctor'];
                echo render_entity_card([
                    'avatar' => $initials,
                    'avatar_class' => $statusBg[$r['status']] ?? '',
                    'title' => $r['patient_name'],
                    'meta' => [format_date($r['requested_at'],'Y-m-d H:i'), $r['phone']],
                    'chips' => $chips,
                    'actions' => [
                        (!empty($r['appointment_id'])) ? ['icon'=>'fa-eye','label'=>'view','href'=>BP_URL.'admin/appointments.php?action=view&id='.(int)$r['appointment_id']] : null,
                        ($r['status']==='pending' && can('appointments.create')) ? ['icon'=>'fa-check-double','label'=>'convert_to_appointment','href'=>'?action=convert&id='.(int)$r['id'].'&_csrf='.csrf_token(),'confirm'=>'confirm_convert'] : null,
                        (!in_array($r['status'],['converted','rejected'],true) && can('appointments.edit')) ? ['icon'=>'fa-phone','label'=>'mark_contacted','href'=>'?action=mark_contacted&id='.(int)$r['id'].'&_csrf='.csrf_token()] : null,
                        (!in_array($r['status'],['converted','rejected'],true) && can('appointments.edit')) ? ['icon'=>'fa-xmark','label'=>'reject','href'=>'?action=reject&id='.(int)$r['id'].'&_csrf='.csrf_token(),'confirm'=>'confirm_reject','divider_before'=>true] : null,
                        (can('appointments.edit')) ? ['icon'=>'fa-trash','label'=>'delete','href'=>'?action=delete&id='.(int)$r['id'].'&_csrf='.csrf_token(),'danger'=>true,'confirm'=>'confirm_delete'] : null,
                    ],
                ]);
            endforeach; endif; ?>
        </div>
    </div>
</div>
<?php include BP_PARTIALS.'/footer.php'; ?>
