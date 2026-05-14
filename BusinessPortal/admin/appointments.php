<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('appointments.view');

$PageTitle = __('appointments');
$action    = $_GET['action'] ?? 'list';
$id        = (int)($_GET['id'] ?? 0);

// ─── Status transitions ──────────────────────────────────────────────
if (in_array($action, ['confirm','complete','no_show','cancel'], true) && $id) {
    csrf_check();
    require_can('appointments.edit');

    $a = db()->prepare("SELECT * FROM appointments WHERE id=? AND deleted_at IS NULL");
    $a->execute([$id]); $a = $a->fetch();
    if (!$a) { flash('error','Not found.'); redirect(BP_URL.'admin/appointments.php'); }

    $map = [
        'confirm'  => ['confirmed','confirmed_at'],
        'complete' => ['completed','completed_at'],
        'no_show'  => ['no_show', null],
        'cancel'   => ['cancelled','cancelled_at'],
    ];
    [$newStatus, $tsCol] = $map[$action];

    $reason = trim($_POST['cancel_reason'] ?? '');
    $params = [$newStatus, $_SESSION['user_id'], $id];
    $sql = "UPDATE appointments SET status=?, updated_by=?, updated_at=NOW()";
    if ($tsCol) $sql .= ", $tsCol = NOW()";
    if ($action === 'cancel' && $reason !== '') {
        $sql .= ", cancel_reason = ?";
        $params = [$newStatus, $_SESSION['user_id'], $reason, $id];
        // re-order to match placeholders
        $params = [$newStatus, $_SESSION['user_id'], $reason, $id];
        $sql = "UPDATE appointments SET status=?, updated_by=?, updated_at=NOW()" .
               ($tsCol ? ", $tsCol = NOW()" : '') .
               ", cancel_reason = ? WHERE id = ?";
    } else {
        $sql .= " WHERE id = ?";
    }
    db()->prepare($sql)->execute($params);

    // Auto-create draft invoice when an appointment is completed (only if not already invoiced
    // and not booked against a prepaid package)
    if ($action === 'complete' && empty($a['patient_package_id'])) {
        $existing = db()->prepare("SELECT id FROM invoices WHERE appointment_id = ? AND deleted_at IS NULL LIMIT 1");
        $existing->execute([$id]);
        if (!$existing->fetch()) {
            $svcs = db()->prepare("SELECT asv.*, s.name_ar
                                   FROM appointment_services asv JOIN services s ON s.id=asv.service_id
                                   WHERE asv.appointment_id=?");
            $svcs->execute([$id]); $svcs = $svcs->fetchAll();
            if ($svcs) {
                db()->prepare("INSERT INTO invoices
                    (invoice_no,patient_id,appointment_id,issue_date,status,currency,created_by,created_at,updated_at)
                    VALUES (?,?,?,?, 'issued', ?, ?, NOW(), NOW())")
                    ->execute([next_invoice_no(), $a['patient_id'], $id, date('Y-m-d'), APP_CURRENCY, $_SESSION['user_id']]);
                $invId = (int)db()->lastInsertId();
                $insI = db()->prepare("INSERT INTO invoice_items
                    (invoice_id,service_id,description,quantity,unit_price,discount,total) VALUES (?,?,?,1,?,0,?)");
                foreach ($svcs as $sv) {
                    $insI->execute([$invId, (int)$sv['service_id'], $sv['name_ar'], (float)$sv['price'], (float)$sv['price']]);
                }
                recompute_invoice($invId);
                log_activity('auto_invoiced','invoices',"Auto-issued invoice for completed appointment #$id",'invoice',$invId);
            }
        }
    }

    // Bump used_sessions when completing a package-bound appointment
    if ($action === 'complete' && $a['patient_package_id']) {
        db()->prepare("
            UPDATE patient_packages
            SET used_sessions = LEAST(total_sessions, used_sessions + 1),
                status = CASE WHEN used_sessions + 1 >= total_sessions THEN 'completed' ELSE status END,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$a['patient_package_id']]);
    }

    log_activity($action, 'appointments', "Appointment #$id → $newStatus", 'appointment', $id);
    flash('success', "Appointment marked $newStatus.");
    redirect(BP_URL . 'admin/appointments.php?action=view&id=' . $id);
}

// ─── DELETE ──────────────────────────────────────────────────────────
if ($action === 'delete' && $id) {
    csrf_check();
    require_can('appointments.delete');
    db()->prepare("UPDATE appointments SET deleted_at=NOW() WHERE id=?")->execute([$id]);
    log_activity('deleted','appointments',"Soft-deleted appointment #$id",'appointment',$id);
    flash('success','Appointment deleted.');
    redirect(BP_URL.'admin/appointments.php');
}

// ─── CREATE / EDIT POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['create','edit'], true)) {
    csrf_check();
    require_can($action === 'create' ? 'appointments.create' : 'appointments.edit');

    $patientId   = (int)($_POST['patient_id'] ?? 0);
    $therapistId = (int)($_POST['therapist_id'] ?? 0) ?: null;
    $roomId      = (int)($_POST['room_id'] ?? 0) ?: null;
    $pkgId       = (int)($_POST['patient_package_id'] ?? 0) ?: null;
    $startAt     = trim($_POST['start_at'] ?? '');
    $services    = array_filter(array_map('intval', $_POST['services'] ?? []));
    $notes       = trim($_POST['notes'] ?? '');
    $override    = !empty($_POST['override_block']);
    $overrideReason = trim($_POST['override_reason'] ?? '');

    $errors = [];
    if (!$patientId)      $errors[] = __('err_pick_patient');
    if (!$startAt)        $errors[] = __('err_pick_start');
    if (!$services)       $errors[] = __('err_pick_service');

    // Validate datetime
    $startTs = $startAt ? strtotime($startAt) : false;
    if (!$startTs) $errors[] = __('err_invalid_date');

    // Hard-block rule (only on create)
    if ($action === 'create' && $patientId && !$errors) {
        $elig = booking_eligibility($patientId, $pkgId);
        if (!$elig['eligible']) {
            if (!$override) {
                $msg = $elig['reason'];
                if ($elig['outstanding'] > 0) $msg .= ' — ' . __('outstanding') . ': ' . format_money($elig['outstanding']);
                $errors[] = $msg;
            } elseif (!can('appointments.override_block')) {
                $errors[] = __('err_override_no_permission');
            } elseif ($overrideReason === '') {
                $errors[] = __('err_override_reason_required');
            }
        }
    }

    // Compute summary
    $sum = $errors ? ['duration'=>0,'price'=>0.0,'rows'=>[]] : services_summary($services);
    if (!$errors && $sum['duration'] === 0) $errors[] = __('err_pick_service');
    // When this appointment is booked against a package, services are already paid for
    // via the package — don't add to the appointment's total price.
    if (!$errors && $pkgId) $sum['price'] = 0.0;

    $startSql = $endSql = null;
    if (!$errors) {
        $endTs    = $startTs + $sum['duration'] * 60;
        $startSql = date('Y-m-d H:i:s', $startTs);
        $endSql   = date('Y-m-d H:i:s', $endTs);

        // Conflict check
        $excludeId = $action === 'edit' ? $id : null;
        $conflict = appointment_conflict($startSql, $endSql, $therapistId, $roomId, $excludeId);
        if ($conflict) {
            $errors[] = __('err_time_conflict') . ' #' . (int)$conflict['id'] . ' (' . $conflict['start_at'] . ').';
        }
    }

    if ($errors) {
        foreach ($errors as $e) flash('error', $e);
        set_old($_POST);
        redirect(BP_URL . 'admin/appointments.php?action=' . $action . ($id ? "&id=$id" : ''));
    }

    if ($action === 'create') {
        db()->prepare("INSERT INTO appointments
            (patient_id,therapist_id,room_id,patient_package_id,start_at,end_at,duration_minutes,status,
             total_price,notes,is_block_overridden,override_reason,created_by,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?, 'scheduled', ?, ?, ?, ?, ?, NOW(), NOW())
        ")->execute([
            $patientId, $therapistId, $roomId, $pkgId, $startSql, $endSql,
            $sum['duration'], $sum['price'], $notes,
            $override ? 1 : 0, $override ? $overrideReason : null,
            $_SESSION['user_id']
        ]);
        $apptId = (int)db()->lastInsertId();
    } else {
        db()->prepare("UPDATE appointments SET
            patient_id=?, therapist_id=?, room_id=?, patient_package_id=?,
            start_at=?, end_at=?, duration_minutes=?, total_price=?, notes=?, updated_by=?, updated_at=NOW()
            WHERE id=?
        ")->execute([
            $patientId, $therapistId, $roomId, $pkgId,
            $startSql, $endSql, $sum['duration'], $sum['price'], $notes, $_SESSION['user_id'], $id
        ]);
        $apptId = $id;
        db()->prepare("DELETE FROM appointment_services WHERE appointment_id=?")->execute([$apptId]);
    }

    // Insert services snapshot
    $insSv = db()->prepare("INSERT INTO appointment_services
        (appointment_id, service_id, price, commission_pct, duration_minutes)
        VALUES (?,?,?,?,?)");
    foreach ($sum['rows'] as $r) {
        $insSv->execute([$apptId, (int)$r['id'], (float)$r['price'], (float)$r['commission_pct'], (int)$r['duration_minutes']]);
    }

    log_activity($action === 'create' ? 'created' : 'updated', 'appointments',
        ($action === 'create' ? 'Booked' : 'Updated') . " appointment #$apptId",
        'appointment', $apptId,
        $override ? ['override' => true, 'reason' => $overrideReason] : []);

    if ($override) {
        log_activity('override_block', 'appointments', "Override booking-block: $overrideReason", 'appointment', $apptId);
    }

    flash('success', $action === 'create' ? 'Appointment booked.' : 'Appointment updated.');
    redirect(BP_URL . 'admin/appointments.php?action=view&id=' . $apptId);
}

// ─── VIEW ────────────────────────────────────────────────────────────
if ($action === 'view' && $id) {
    $a = db()->prepare("
        SELECT a.*, p.code AS patient_code, p.first_name, p.last_name, p.phone,
               u.name AS therapist_name, r.name AS room_name,
               pk.name_ar AS package_name, pp.total_sessions AS pkg_total, pp.used_sessions AS pkg_used
        FROM appointments a
        JOIN patients p ON p.id = a.patient_id
        LEFT JOIN users u ON u.id = a.therapist_id
        LEFT JOIN rooms r ON r.id = a.room_id
        LEFT JOIN patient_packages pp ON pp.id = a.patient_package_id
        LEFT JOIN packages pk ON pk.id = pp.package_id
        WHERE a.id = ? AND a.deleted_at IS NULL
    ");
    $a->execute([$id]); $appt = $a->fetch();
    if (!$appt) { flash('error','Not found.'); redirect(BP_URL.'admin/appointments.php'); }

    $svcs = db()->prepare("
        SELECT asv.*, s.name_ar
        FROM appointment_services asv
        JOIN services s ON s.id = asv.service_id
        WHERE asv.appointment_id = ?
    ");
    $svcs->execute([$id]); $svcs = $svcs->fetchAll();

    include BP_PARTIALS . '/header.php';
    $statusBg   = ['scheduled'=>'#0ea5e9','confirmed'=>'#3b82f6','completed'=>'#10b981','no_show'=>'#f59e0b','cancelled'=>'#ef4444'][$appt['status']] ?? '#64748b';
    $statusIcon = ['scheduled'=>'fa-calendar-day','confirmed'=>'fa-circle-check','completed'=>'fa-check-double','no_show'=>'fa-circle-xmark','cancelled'=>'fa-ban'][$appt['status']] ?? 'fa-calendar';
    $rtl = (($_SESSION['admin_lang'] ?? 'ar') === 'ar');
    $totalSvcPrice = 0;
    foreach ($svcs as $s) $totalSvcPrice += (float)$s['price'];
    ?>
    <div class="page-wrap">
        <!-- ── HERO BANNER ───────────────────────────────────────── -->
        <div class="appt-hero" style="background:linear-gradient(135deg, <?= $statusBg ?> 0%, <?= $statusBg ?>cc 100%)">
            <div class="appt-hero-left">
                <div class="appt-hero-icon"><i class="fa-solid <?= $statusIcon ?>"></i></div>
                <div>
                    <div class="appt-hero-id"><?= __('appointment_no') ?> #<?= (int)$appt['id'] ?></div>
                    <h3 class="appt-hero-title m-0"><?= __('st_'.$appt['status']) ?></h3>
                    <div class="appt-hero-meta">
                        <span><i class="fa-regular fa-clock me-1"></i><?= format_date($appt['start_at'],'Y-m-d H:i') ?></span>
                        · <span><i class="fa-regular fa-hourglass-half me-1"></i><?= (int)$appt['duration_minutes'] ?>m</span>
                    </div>
                </div>
            </div>
            <div class="appt-hero-actions">
                <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/appointments.php">
                    <i class="fa-solid fa-arrow-<?= $rtl?'right':'left' ?> me-1"></i><?= __('back_to_list') ?>
                </a>
                <?php if (can('appointments.edit') && in_array($appt['status'], ['scheduled','confirmed'])): ?>
                    <a class="btn btn-light btn-sm" href="?action=edit&id=<?= $id ?>" data-modal>
                        <i class="fa-solid fa-pen me-1"></i><?= __('edit') ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($appt['is_block_overridden'])): ?>
            <div class="alert alert-warning small d-flex align-items-center gap-2 mt-3">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div><strong><?= __('override_warning') ?></strong> — <?= e($appt['override_reason']) ?></div>
            </div>
        <?php endif; ?>

        <!-- ── STATUS ACTION TOOLBAR ─────────────────────────────── -->
        <?php if (can('appointments.edit') && in_array($appt['status'], ['scheduled','confirmed'])): ?>
            <div class="appt-actions">
                <?php if ($appt['status'] === 'scheduled'): ?>
                    <form method="post" action="?action=confirm&id=<?= $id ?>" class="m-0">
                        <?= csrf_field() ?>
                        <button class="btn btn-primary"><i class="fa-solid fa-check me-2"></i><?= __('confirm') ?></button>
                    </form>
                <?php endif; ?>
                <form method="post" action="?action=complete&id=<?= $id ?>" class="m-0">
                    <?= csrf_field() ?>
                    <button class="btn btn-success" data-confirm="<?= __('confirm_complete') ?>">
                        <i class="fa-solid fa-check-double me-2"></i><?= __('mark_completed') ?>
                    </button>
                </form>
                <form method="post" action="?action=no_show&id=<?= $id ?>" class="m-0">
                    <?= csrf_field() ?>
                    <button class="btn btn-warning" data-confirm="<?= __('confirm_no_show') ?>">
                        <i class="fa-regular fa-circle-xmark me-2"></i><?= __('mark_no_show') ?>
                    </button>
                </form>
                <form method="post" action="?action=cancel&id=<?= $id ?>" class="appt-cancel m-0">
                    <?= csrf_field() ?>
                    <input name="cancel_reason" class="form-control form-control-sm" placeholder="<?= __('cancellation_reason') ?>…">
                    <button class="btn btn-outline-danger" data-confirm="<?= __('confirm_cancel_appointment') ?>">
                        <i class="fa-solid fa-ban me-1"></i><?= __('cancel') ?>
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <!-- ── MAIN GRID ─────────────────────────────────────────── -->
        <div class="row g-3 mt-1">
            <!-- LEFT: Schedule + Services + Package -->
            <div class="col-lg-8">
                <!-- Schedule details -->
                <div class="info-card mb-3">
                    <div class="info-card-head"><i class="fa-regular fa-calendar"></i><?= __('schedule_section') ?></div>
                    <div class="info-card-grid">
                        <div><span class="info-label"><?= __('start_label') ?></span><span class="info-value"><?= format_date($appt['start_at'],'Y-m-d H:i') ?></span></div>
                        <div><span class="info-label"><?= __('end_label') ?></span><span class="info-value"><?= format_date($appt['end_at'],'Y-m-d H:i') ?></span></div>
                        <div><span class="info-label"><?= __('duration') ?></span><span class="info-value"><?= (int)$appt['duration_minutes'] ?>m</span></div>
                        <div><span class="info-label"><?= __('therapist') ?></span><span class="info-value"><i class="fa-solid fa-user-doctor me-1 text-muted"></i><?= e($appt['therapist_name'] ?? '—') ?></span></div>
                        <div><span class="info-label"><?= __('room') ?></span><span class="info-value"><i class="fa-solid fa-door-open me-1 text-muted"></i><?= e($appt['room_name'] ?? '—') ?></span></div>
                        <div><span class="info-label"><?= __('total') ?></span>
                            <span class="info-value">
                                <?php if (!empty($appt['patient_package_id'])): ?>
                                    <span class="text-success"><i class="fa-solid fa-box-open me-1"></i><?= __('covered_by_package') ?></span>
                                <?php else: ?>
                                    <strong class="text-teal"><?= format_money($appt['total_price']) ?></strong>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                    <?php if ($appt['notes']): ?>
                        <div class="info-card-section">
                            <span class="info-label"><i class="fa-regular fa-note-sticky me-1"></i><?= __('notes') ?></span>
                            <div class="info-value mt-1"><?= nl2br(e($appt['notes'])) ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($appt['cancel_reason']): ?>
                        <div class="info-card-section danger">
                            <span class="info-label text-danger"><i class="fa-solid fa-ban me-1"></i><?= __('cancellation_reason') ?></span>
                            <div class="info-value text-danger mt-1"><?= e($appt['cancel_reason']) ?></div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Services -->
                <div class="info-card mb-3">
                    <div class="info-card-head"><i class="fa-solid fa-list-check"></i><?= __('services_section') ?> <span class="text-muted">(<?= count($svcs) ?>)</span></div>
                    <?php if (!$svcs): ?>
                        <div class="empty-state py-3"><i class="fa-regular fa-folder-open"></i><div><?= __('no_data') ?></div></div>
                    <?php else: ?>
                        <div class="appt-svc-list">
                            <?php foreach ($svcs as $s): ?>
                                <div class="appt-svc-item">
                                    <div class="appt-svc-name"><i class="fa-solid fa-circle-dot me-2 text-teal"></i><?= e($s['name_ar']) ?></div>
                                    <div class="appt-svc-meta">
                                        <span><i class="fa-regular fa-clock"></i><?= (int)$s['duration_minutes'] ?>m</span>
                                        <span><i class="fa-solid fa-coins"></i><?= format_money($s['price']) ?></span>
                                        <?php if ((float)$s['commission_pct'] > 0): ?>
                                            <span class="text-warning"><i class="fa-solid fa-percent"></i><?= e($s['commission_pct']) ?>%</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($appt['patient_package_id'])): ?>
                                <div class="appt-svc-total">
                                    <span><?= __('total') ?></span>
                                    <strong><?= format_money($totalSvcPrice) ?></strong>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($appt['package_name']): ?>
                    <div class="info-card mb-3" style="border-color:#a5b4fc">
                        <div class="info-card-head" style="color:#4338ca"><i class="fa-solid fa-box-open"></i><?= __('linked_package') ?></div>
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong><?= e($appt['package_name']) ?></strong>
                                <div class="small text-muted mt-1">
                                    <?= __('used') ?>: <strong><?= (int)$appt['pkg_used'] ?></strong> / <?= (int)$appt['pkg_total'] ?> <?= __('sessions') ?>
                                </div>
                            </div>
                            <?php $pkPct = $appt['pkg_total']>0 ? round($appt['pkg_used']/$appt['pkg_total']*100) : 0; ?>
                            <div class="text-end" style="min-width:140px">
                                <div class="progress" style="height:8px"><div class="progress-bar" style="width:<?= $pkPct ?>%;background:#6366f1"></div></div>
                                <small class="text-muted"><?= $pkPct ?>%</small>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- RIGHT: Patient card -->
            <div class="col-lg-4">
                <div class="info-card patient-mini-card mb-3">
                    <div class="info-card-head"><i class="fa-solid fa-user"></i><?= __('patient_info') ?></div>
                    <?php $initials = mb_strtoupper(mb_substr($appt['first_name'],0,1).mb_substr($appt['last_name'],0,1)); ?>
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="entity-avatar" style="width:60px;height:60px;font-size:1.3rem"><span><?= e($initials) ?></span></div>
                        <div class="flex-1 min-w-0">
                            <a href="<?= BP_URL ?>admin/patient-view.php?id=<?= (int)$appt['patient_id'] ?>" class="d-block fw-semibold text-dark text-decoration-none">
                                <?= e($appt['first_name'].' '.$appt['last_name']) ?>
                            </a>
                            <code class="small"><?= e($appt['patient_code']) ?></code>
                        </div>
                    </div>
                    <div class="patient-mini-row">
                        <i class="fa-solid fa-phone text-muted"></i>
                        <a href="tel:<?= e($appt['phone']) ?>" dir="ltr" class="text-decoration-none text-dark"><?= e($appt['phone']) ?></a>
                    </div>
                    <a class="btn btn-sm btn-light w-100 mt-3" href="<?= BP_URL ?>admin/patient-view.php?id=<?= (int)$appt['patient_id'] ?>">
                        <i class="fa-solid fa-folder-open me-1"></i><?= __('view_profile') ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php include BP_PARTIALS . '/footer.php'; exit;
}

// ─── CREATE / EDIT FORM ──────────────────────────────────────────────
if (in_array($action, ['create','edit'], true)) {
    require_can($action === 'create' ? 'appointments.create' : 'appointments.edit');

    $appt = ['patient_id'=>0,'therapist_id'=>0,'room_id'=>0,'patient_package_id'=>0,'start_at'=>'','notes'=>''];
    $selectedSvcs = [];
    $patient = null;

    if ($action === 'edit' && $id) {
        $a = db()->prepare("SELECT * FROM appointments WHERE id=? AND deleted_at IS NULL");
        $a->execute([$id]); $appt = $a->fetch();
        if (!$appt) { flash('error','Not found.'); redirect(BP_URL.'admin/appointments.php'); }
        $appt['start_at'] = date('Y-m-d\TH:i', strtotime($appt['start_at']));
        $sv = db()->prepare("SELECT service_id FROM appointment_services WHERE appointment_id=?");
        $sv->execute([$id]); $selectedSvcs = array_column($sv->fetchAll(), 'service_id');
        $p = db()->prepare("SELECT * FROM patients WHERE id=?");
        $p->execute([$appt['patient_id']]); $patient = $p->fetch();
    }

    // Pre-pick patient via ?patient_id=
    if ($action === 'create' && empty($appt['patient_id']) && !empty($_GET['patient_id'])) {
        $appt['patient_id'] = (int)$_GET['patient_id'];
        $p = db()->prepare("SELECT * FROM patients WHERE id=?");
        $p->execute([$appt['patient_id']]); $patient = $p->fetch();
    }

    $patients   = db()->query("SELECT id,code,first_name,last_name,phone,outstanding_balance FROM patients WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 500")->fetchAll();
    $therapists = db()->query("
        SELECT u.id, u.name FROM users u
        JOIN user_roles ur ON ur.user_id = u.id
        JOIN roles r ON r.id = ur.role_id
        WHERE u.deleted_at IS NULL AND u.status='active' AND r.slug IN ('therapist','doctor')
        GROUP BY u.id ORDER BY u.name
    ")->fetchAll();
    $rooms     = db()->query("SELECT id,name,type FROM rooms WHERE deleted_at IS NULL AND is_active=1 ORDER BY sort_order")->fetchAll();
    $services  = db()->query("
        SELECT s.id,s.name_ar,s.duration_minutes,s.price,c.name_ar AS cat
        FROM services s LEFT JOIN service_categories c ON c.id = s.category_id
        WHERE s.deleted_at IS NULL AND s.is_active=1
        ORDER BY c.sort_order, s.sort_order
    ")->fetchAll();

    // Map: patient_package_id → [service_id, ...]
    $pkgSvcMap = [];
    $rs = db()->query("
        SELECT pp.id AS pp_id, ps.service_id
        FROM patient_packages pp
        JOIN package_services ps ON ps.package_id = pp.package_id
        WHERE pp.deleted_at IS NULL
    ")->fetchAll();
    foreach ($rs as $r) {
        $pkgSvcMap[(int)$r['pp_id']][] = (int)$r['service_id'];
    }

    // Active patient packages for ALL patients (for client-side filtering)
    $allActivePkgs = db()->query("
        SELECT pp.id, pp.patient_id, pk.name_ar, pp.used_sessions, pp.total_sessions, pp.expiry_date
        FROM patient_packages pp
        JOIN packages pk ON pk.id = pp.package_id
        WHERE pp.deleted_at IS NULL AND pp.status='active'
          AND pp.used_sessions < pp.total_sessions
          AND pp.expiry_date >= CURDATE()
    ")->fetchAll();
    $pkgsByPatient = [];
    foreach ($allActivePkgs as $pp) {
        $pkgsByPatient[(int)$pp['patient_id']][] = [
            'id'    => (int)$pp['id'],
            'label' => $pp['name_ar'].' · '.(int)$pp['used_sessions'].'/'.(int)$pp['total_sessions'].' · '.$pp['expiry_date'],
        ];
    }
    $patientPkgs = $pkgsByPatient[(int)$appt['patient_id']] ?? [];

    // Eligibility for the chosen patient (UI hint)
    $eligibility = $appt['patient_id'] ? booking_eligibility((int)$appt['patient_id'], (int)($appt['patient_package_id'] ?? 0) ?: null) : null;

    // Pre-compute eligibility for ALL patients in the dropdown (patient-only check)
    // + for every active package they own (package-aware check) so the JS can show
    // the block notice instantly as the user changes selections.
    $eligMap = ['patient' => [], 'package' => []];
    foreach ($patients as $pt) {
        $r = booking_eligibility((int)$pt['id'], null);
        if (!$r['eligible']) {
            $eligMap['patient'][(int)$pt['id']] = $r['reason'] . ($r['outstanding']>0 ? ' — '.__('outstanding').': '.format_money($r['outstanding']) : '');
        }
    }
    foreach ($allActivePkgs as $pp) {
        $r = booking_eligibility((int)$pp['patient_id'], (int)$pp['id']);
        if (!$r['eligible']) {
            $eligMap['package'][(int)$pp['id']] = $r['reason'];
        }
    }

    include BP_PARTIALS . '/header.php';
    ?>
    <div class="page-wrap">
        <h4 class="mb-3"><?= $action==='create'?__('create'):__('edit') ?> — <?= __('appointments') ?></h4>

        <!-- Live booking-block notice (server-side initial + JS updates on patient/package change) -->
        <div id="apptBlockNotice" class="alert alert-danger align-items-start gap-2 <?= ($eligibility && !$eligibility['eligible']) ? 'd-flex' : 'd-none' ?>">
            <i class="fa-solid fa-ban mt-1"></i>
            <div class="flex-1">
                <strong><?= __('booking_blocked') ?>:</strong>
                <span id="apptBlockReason"><?= $eligibility ? e($eligibility['reason']) : '' ?></span>
                <?php if ($eligibility && $eligibility['outstanding'] > 0): ?>
                    <span class="badge bg-light text-dark ms-2"><?= __('outstanding') ?>: <?= format_money($eligibility['outstanding']) ?></span>
                <?php endif; ?>
            </div>
            <?php if (!empty($appt['patient_id'])): ?>
                <a class="btn btn-sm btn-outline-danger flex-shrink-0" href="<?= BP_URL ?>admin/patient-view.php?id=<?= (int)$appt['patient_id'] ?>#tab-billing">
                    <i class="fa-solid fa-money-bill-wave me-1"></i><?= __('record_payment') ?>
                </a>
            <?php endif; ?>
        </div>

        <form method="post" class="card"><div class="card-body">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label d-flex justify-content-between align-items-center">
                        <span><?= __('patients') ?> *</span>
                        <?php if (can('patients.create')): ?>
                            <a href="<?= BP_URL ?>admin/patients.php?action=create" data-modal class="small text-decoration-none">
                                <i class="fa-solid fa-plus me-1"></i><?= __('new_patient') ?>
                            </a>
                        <?php endif; ?>
                    </label>
                    <select name="patient_id" id="apptPatientId" class="form-select" required>
                        <option value="">— <?= __('pick_patient') ?> —</option>
                        <?php foreach ($patients as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= (int)$appt['patient_id']===(int)$p['id']?'selected':'' ?>>
                                [<?= e($p['code']) ?>] <?= e($p['first_name'].' '.$p['last_name']) ?> · <?= e($p['phone']) ?>
                                <?= ((float)$p['outstanding_balance']>0) ? ' ⚠ ' . format_money($p['outstanding_balance']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('start_label') ?> *</label>
                    <input name="start_at" type="datetime-local" required class="form-control" value="<?= e($appt['start_at']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('therapist') ?></label>
                    <select name="therapist_id" class="form-select">
                        <option value="">—</option>
                        <?php foreach ($therapists as $t): ?>
                            <option value="<?= (int)$t['id'] ?>" <?= (int)$appt['therapist_id']===(int)$t['id']?'selected':'' ?>>
                                <?= e($t['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label"><?= __('room') ?> / <?= __('bed') ?></label>
                    <select name="room_id" class="form-select">
                        <option value="">—</option>
                        <?php foreach ($rooms as $r): ?>
                            <option value="<?= (int)$r['id'] ?>" <?= (int)$appt['room_id']===(int)$r['id']?'selected':'' ?>>
                                <?= e($r['name']) ?> (<?= e($r['type']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-9">
                    <label class="form-label"><?= __('use_active_package') ?></label>
                    <select name="patient_package_id" id="apptPkgSelect" class="form-select">
                        <option value="">— <?= __('no_package') ?> —</option>
                        <?php foreach ($patientPkgs as $pp): ?>
                            <option value="<?= (int)$pp['id'] ?>" <?= (int)$appt['patient_package_id']===(int)$pp['id']?'selected':'' ?>>
                                <?= e($pp['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-12">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label m-0"><?= __('services') ?> *</label>
                        <input type="search" id="svcSearch" class="form-control form-control-sm" style="max-width:240px" placeholder="<?= __('search') ?>…">
                    </div>
                    <div class="svc-picker">
                        <?php
                            $svcByCat = [];
                            foreach ($services as $s) $svcByCat[$s['cat'] ?? __('uncategorized')][] = $s;
                            $catIdx = 0;
                            foreach ($svcByCat as $catName => $svcs): $catIdx++;
                        ?>
                            <div class="svc-cat">
                                <div class="svc-cat-title"><i class="fa-solid fa-folder-open me-2"></i><?= e($catName) ?> <span class="text-muted">(<?= count($svcs) ?>)</span></div>
                                <div class="svc-grid">
                                    <?php foreach ($svcs as $s):
                                        $checked = in_array($s['id'], $selectedSvcs);
                                    ?>
                                        <label class="svc-card <?= $checked?'is-selected':'' ?>" data-name="<?= e(mb_strtolower($s['name_ar'])) ?>">
                                            <input class="svc-card-check" type="checkbox" name="services[]" value="<?= (int)$s['id'] ?>" <?= $checked?'checked':'' ?>>
                                            <div class="svc-card-body">
                                                <div class="svc-card-name"><?= e($s['name_ar']) ?></div>
                                                <div class="svc-card-meta">
                                                    <span><i class="fa-regular fa-clock"></i><?= (int)$s['duration_minutes'] ?>m</span>
                                                    <span><i class="fa-solid fa-coins"></i><?= format_money($s['price']) ?></span>
                                                </div>
                                            </div>
                                            <div class="svc-card-check-icon"><i class="fa-solid fa-check"></i></div>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="svc-summary mt-2">
                        <span><i class="fa-solid fa-circle-check me-1 text-teal"></i><span id="svcSelectedCount">0</span> <?= __('selected') ?></span>
                        <span class="ms-3"><i class="fa-regular fa-clock me-1"></i><span id="svcSelectedDuration">0</span>m</span>
                        <span class="ms-3"><i class="fa-solid fa-coins me-1"></i><strong id="svcSelectedTotal">0.00</strong></span>
                    </div>
                </div>

                <div class="col-md-12">
                    <label class="form-label"><?= __('notes') ?></label>
                    <textarea name="notes" class="form-control" rows="2"><?= e($appt['notes'] ?? '') ?></textarea>
                </div>

                <?php if ($eligibility && !$eligibility['eligible'] && can('appointments.override_block')): ?>
                    <div class="col-12">
                        <div class="border border-warning rounded p-3 bg-light">
                            <label class="form-check mb-2">
                                <input type="checkbox" class="form-check-input" name="override_block" value="1">
                                <span class="form-check-label"><strong>Override booking block</strong> (requires reason)</span>
                            </label>
                            <input name="override_reason" class="form-control form-control-sm" placeholder="Why are you overriding?">
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="mt-3 d-flex gap-2">
                <button class="btn btn-teal" <?= ($eligibility && !$eligibility['eligible'] && !can('appointments.override_block')) ? 'disabled' : '' ?>>
                    <?= __('save') ?>
                </button>
                <a class="btn btn-light" href="<?= BP_URL ?>admin/appointments.php"><?= __('cancel') ?></a>
            </div>
        </div></form>

        <script>
        (function(){
            // ── Active-packages dropdown updates when patient changes ──
            const pkgs = <?= json_encode($pkgsByPatient, JSON_UNESCAPED_UNICODE) ?>;
            const pkgSvc = <?= json_encode($pkgSvcMap) ?>;  // pp_id → [service_id]
            const eligMap = <?= json_encode($eligMap, JSON_UNESCAPED_UNICODE) ?>;
            const noPkg = <?= json_encode('— '.__('no_package').' —') ?>;

            // Live booking-block notice
            const noticeEl = document.getElementById('apptBlockNotice');
            const reasonEl = document.getElementById('apptBlockReason');
            const updateNotice = () => {
                if (!noticeEl) return;
                const pid = parseInt(document.getElementById('apptPatientId')?.value || '0', 10);
                const ppid = parseInt(document.getElementById('apptPkgSelect')?.value || '0', 10);
                let reason = null;
                if (ppid && eligMap.package[ppid]) reason = eligMap.package[ppid];
                else if (pid && eligMap.patient[pid]) reason = eligMap.patient[pid];
                if (reason) {
                    if (reasonEl) reasonEl.textContent = reason;
                    noticeEl.classList.remove('d-none');
                    noticeEl.classList.add('d-flex');
                } else {
                    noticeEl.classList.remove('d-flex');
                    noticeEl.classList.add('d-none');
                }
            };
            const sel = document.getElementById('apptPatientId');
            const pkgSel = document.getElementById('apptPkgSelect');
            if (sel && pkgSel) {
                const refresh = () => {
                    const pid = parseInt(sel.value, 10);
                    const list = pkgs[pid] || [];
                    const current = pkgSel.value;
                    pkgSel.innerHTML = '<option value="">'+noPkg+'</option>' +
                        list.map(p => '<option value="'+p.id+'"'+(String(p.id)===current?' selected':'')+'>'+p.label+'</option>').join('');
                };
                sel.addEventListener('change', () => { refresh(); updateNotice(); });
                if (sel.value) refresh();
            }
            // Also react to package changes
            if (pkgSel) pkgSel.addEventListener('change', updateNotice);
            updateNotice();

            // ── Service picker: toggle selection + live total + search ──
            const picker = document.querySelector('.svc-picker');
            if (!picker) return;
            const cards     = Array.from(picker.querySelectorAll('.svc-card'));
            const elCount   = document.getElementById('svcSelectedCount');
            const elDur     = document.getElementById('svcSelectedDuration');
            const elTotal   = document.getElementById('svcSelectedTotal');
            const search    = document.getElementById('svcSearch');

            // Cache duration & price from the meta spans for live totals
            cards.forEach(c => {
                const metas = c.querySelectorAll('.svc-card-meta span');
                c.dataset.dur   = parseInt(metas[0]?.textContent || '0', 10) || 0;
                c.dataset.price = parseFloat((metas[1]?.textContent || '0').replace(/[^\d.]/g, '')) || 0;
            });

            const pkgLabel = <?= json_encode(__('covered_by_package')) ?>;
            const updateTotals = () => {
                let n=0, dur=0, total=0;
                const usingPkg = !!(pkgSel && parseInt(pkgSel.value || '0', 10) > 0);
                cards.forEach(c => {
                    if (c.querySelector('.svc-card-check').checked) {
                        n++;
                        dur   += parseInt(c.dataset.dur, 10) || 0;
                        if (!usingPkg) total += parseFloat(c.dataset.price) || 0;
                    }
                });
                if (elCount) elCount.textContent = n;
                if (elDur)   elDur.textContent   = dur;
                if (elTotal) {
                    if (usingPkg) {
                        elTotal.innerHTML = '<span class="text-success">'+pkgLabel+'</span>';
                    } else {
                        elTotal.textContent = total.toFixed(2);
                    }
                }
                // Toggle dimmed price on each card to match
                cards.forEach(c => c.classList.toggle('pkg-mode', usingPkg));
            };

            cards.forEach(c => {
                const cb = c.querySelector('.svc-card-check');
                cb.addEventListener('change', () => {
                    c.classList.toggle('is-selected', cb.checked);
                    updateTotals();
                });
            });
            updateTotals();

            const applyFilters = () => {
                const q = (search?.value || '').trim().toLowerCase();
                const ppid = parseInt(pkgSel?.value || '0', 10);
                const allowed = ppid && pkgSvc[ppid] ? new Set(pkgSvc[ppid]) : null;
                cards.forEach(c => {
                    const cb = c.querySelector('.svc-card-check');
                    const sid = parseInt(cb.value, 10);
                    const matchSearch = !q || (c.dataset.name || '').includes(q);
                    const inPackage   = !allowed || allowed.has(sid);
                    const hidden = !matchSearch || !inPackage;
                    c.classList.toggle('is-hidden', hidden);
                    // Auto-uncheck services that fell outside the chosen package
                    if (hidden && cb.checked) {
                        cb.checked = false;
                        c.classList.remove('is-selected');
                    }
                });
                picker.querySelectorAll('.svc-cat').forEach(cat => {
                    const visible = cat.querySelectorAll('.svc-card:not(.is-hidden)').length;
                    cat.classList.toggle('is-empty', visible === 0);
                });
                updateTotals();
            };

            if (search) search.addEventListener('input', applyFilters);
            if (pkgSel) pkgSel.addEventListener('change', applyFilters);
            // Run once on load (covers pre-selected package on edit)
            applyFilters();
        })();
        </script>
    </div>
    <?php include BP_PARTIALS . '/footer.php'; clear_old(); exit;
}

// ─── LIST ────────────────────────────────────────────────────────────
$q       = trim($_GET['q'] ?? '');
$status  = trim($_GET['status'] ?? '');
$from    = trim($_GET['from'] ?? '');
$to      = trim($_GET['to'] ?? '');
$page    = (int)($_GET['page'] ?? 1);
[$perPage, $offset] = paginate_query($page, (int)setting('per_page','25'));

$where = "a.deleted_at IS NULL";
$params = [];
if ($q !== '') {
    $where .= " AND (p.code LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? OR p.phone LIKE ?)";
    $like = "%$q%";
    array_push($params, $like, $like, $like, $like);
}
if ($status !== '') { $where .= " AND a.status = ?"; $params[] = $status; }
if ($from   !== '') { $where .= " AND a.start_at >= ?"; $params[] = $from . ' 00:00:00'; }
if ($to     !== '') { $where .= " AND a.start_at <= ?"; $params[] = $to   . ' 23:59:59'; }

$tot = db()->prepare("SELECT COUNT(*) FROM appointments a JOIN patients p ON p.id = a.patient_id WHERE $where");
$tot->execute($params);
$total = (int)$tot->fetchColumn();

$sql = "SELECT a.*, p.code AS patient_code, p.first_name, p.last_name, p.phone,
               u.name AS therapist_name, r.name AS room_name
        FROM appointments a
        JOIN patients p ON p.id = a.patient_id
        LEFT JOIN users u ON u.id = a.therapist_id
        LEFT JOIN rooms r ON r.id = a.room_id
        WHERE $where
        ORDER BY a.start_at DESC
        LIMIT $perPage OFFSET $offset";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Quick stats for the page header
$today = date('Y-m-d');
$kpiStmt = db()->query("
    SELECT
      SUM(DATE(start_at) = '$today' AND status NOT IN ('cancelled','no_show')) AS today_count,
      SUM(status = 'scheduled')  AS scheduled,
      SUM(status = 'confirmed')  AS confirmed,
      SUM(DATE(start_at) = '$today' AND status = 'completed') AS completed_today
    FROM appointments WHERE deleted_at IS NULL
");
$kpi = $kpiStmt->fetch() ?: ['today_count'=>0,'scheduled'=>0,'confirmed'=>0,'completed_today'=>0];
$activeFilters = ($q !== '') + ($status !== '') + ($from !== '') + ($to !== '');

include BP_PARTIALS . '/header.php';
?>
<div class="page-wrap">
    <!-- Header -->
    <div class="page-header">
        <h4 class="m-0">
            <i class="fa-regular fa-calendar-check text-teal me-2"></i><?= __('appointments') ?>
            <span class="page-count">(<?= $total ?>)</span>
        </h4>
        <div class="page-header-actions">
            <a href="<?= BP_URL ?>admin/calendar.php" class="btn btn-light btn-sm">
                <i class="fa-regular fa-calendar me-1"></i><?= __('calendar') ?>
            </a>
            <?php if (can('appointments.create')): ?>
                <a href="?action=create" class="btn btn-teal btn-sm" data-modal>
                    <i class="fa-solid fa-plus me-1"></i><?= __('new_appointment') ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- KPI strip -->
    <div class="appt-kpis">
        <a class="appt-kpi" href="?from=<?= $today ?>&to=<?= $today ?>">
            <div class="appt-kpi-icon" style="background:#0ea5e9"><i class="fa-solid fa-calendar-day"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('today') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['today_count'] ?></div>
            </div>
        </a>
        <a class="appt-kpi" href="?status=scheduled">
            <div class="appt-kpi-icon" style="background:#6366f1"><i class="fa-solid fa-clock"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('st_scheduled') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['scheduled'] ?></div>
            </div>
        </a>
        <a class="appt-kpi" href="?status=confirmed">
            <div class="appt-kpi-icon" style="background:#3b82f6"><i class="fa-solid fa-circle-check"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('st_confirmed') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['confirmed'] ?></div>
            </div>
        </a>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#10b981"><i class="fa-solid fa-check-double"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('completed_today') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['completed_today'] ?></div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <form method="get" class="appt-filters">
        <div class="appt-filter-group flex-grow-1">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" name="q" value="<?= e($q) ?>" class="form-control form-control-sm"
                   placeholder="<?= __('search_patient_placeholder') ?>">
        </div>
        <div class="appt-filter-group">
            <select name="status" class="form-select form-select-sm">
                <option value=""><?= __('all_statuses') ?></option>
                <?php foreach (['scheduled','confirmed','completed','no_show','cancelled'] as $st): ?>
                    <option value="<?= $st ?>" <?= $status===$st?'selected':'' ?>><?= __('st_'.$st) ?: $st ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="appt-filter-group">
            <span class="appt-filter-icon"><i class="fa-regular fa-calendar"></i></span>
            <input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm" title="<?= __('from') ?>">
        </div>
        <div class="appt-filter-group">
            <span class="appt-filter-icon"><i class="fa-regular fa-calendar-check"></i></span>
            <input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm" title="<?= __('to') ?>">
        </div>
        <button class="btn btn-teal btn-sm"><i class="fa-solid fa-filter me-1"></i><?= __('apply') ?></button>
        <?php if ($activeFilters): ?>
            <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/appointments.php" title="<?= __('clear_filters') ?>">
                <i class="fa-solid fa-xmark"></i>
            </a>
        <?php endif; ?>
    </form>

    <div class="table-card">
        <!-- Desktop table -->
        <div class="table-responsive d-none d-md-block">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th>#</th><th><?= __('start_label') ?></th><th><?= __('patients') ?></th><th><?= __('phone') ?></th>
                <th><?= __('therapist') ?></th><th><?= __('room') ?></th><th><?= __('total') ?></th><th><?= __('status') ?></th><th></th>
            </tr></thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4"><?= __('no_data') ?></td></tr>
                <?php else: foreach ($rows as $a):
                    $color = ['scheduled'=>'info','confirmed'=>'primary','completed'=>'success','no_show'=>'warning','cancelled'=>'danger'][$a['status']] ?? 'secondary';
                ?>
                    <tr>
                        <td><?= (int)$a['id'] ?></td>
                        <td class="small"><?= format_date($a['start_at'],'Y-m-d H:i') ?></td>
                        <td>
                            <a href="<?= BP_URL ?>admin/patient-view.php?id=<?= (int)$a['patient_id'] ?>" class="text-decoration-none">
                                <?= e($a['first_name'].' '.$a['last_name']) ?>
                            </a>
                            <code class="small ms-1"><?= e($a['patient_code']) ?></code>
                        </td>
                        <td class="small"><?= e($a['phone']) ?></td>
                        <td class="small"><?= e($a['therapist_name'] ?? '—') ?></td>
                        <td class="small"><?= e($a['room_name'] ?? '—') ?></td>
                        <td><?= format_money($a['total_price']) ?></td>
                        <td><span class="badge bg-<?= $color ?>"><?= __('st_'.$a['status']) ?></span></td>
                        <td class="text-end">
                            <?= render_actions([
                                ['icon'=>'fa-eye','label'=>'view','href'=>'?action=view&id='.(int)$a['id']],
                                (can('appointments.edit') && in_array($a['status'], ['scheduled','confirmed']))
                                    ? ['icon'=>'fa-pen','label'=>'edit','href'=>'?action=edit&id='.(int)$a['id'],'modal'=>true] : null,
                                (can('appointments.edit') && in_array($a['status'], ['scheduled','confirmed']))
                                    ? ['icon'=>'fa-ban','label'=>'cancel','href'=>'?action=cancel&id='.(int)$a['id'],'confirm'=>'confirm_cancel_appointment','divider_before'=>true] : null,
                                (can('appointments.delete'))
                                    ? ['icon'=>'fa-trash','label'=>'delete','href'=>'?action=delete&id='.(int)$a['id'],'danger'=>true,'confirm'=>'confirm_delete'] : null,
                            ]) ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>

        <!-- Mobile entity card list -->
        <div class="entity-list d-md-none">
            <?php if (!$rows): ?>
                <div class="empty-state"><i class="fa-regular fa-calendar"></i><div><?= __('no_data') ?></div></div>
            <?php else: foreach ($rows as $a):
                $ts = strtotime($a['start_at']);
                $statusChipClass = ['scheduled'=>'info','confirmed'=>'info','completed'=>'success','no_show'=>'warn','cancelled'=>'danger'][$a['status']] ?? '';
                $chips = [
                    ['label'=>__('st_'.$a['status']),'icon'=>'fa-circle-dot','class'=>$statusChipClass],
                    ['label'=>format_money($a['total_price']),'icon'=>'fa-coins','class'=>'teal'],
                ];
                if (!empty($a['therapist_name'])) {
                    $chips[] = ['label'=>$a['therapist_name'],'icon'=>'fa-user-doctor'];
                }
                if (!empty($a['room_name'])) {
                    $chips[] = ['label'=>$a['room_name'],'icon'=>'fa-door-open'];
                }
                echo render_entity_card([
                    'avatar_icon' => 'fa-calendar-day',
                    'avatar_class' => date('Y-m-d',$ts) === date('Y-m-d') ? 'success' : (in_array($a['status'],['cancelled','no_show']) ? 'slate' : ''),
                    'title' => $a['first_name'].' '.$a['last_name'],
                    'title_href' => BP_URL.'admin/patient-view.php?id='.(int)$a['patient_id'],
                    'code' => $a['patient_code'],
                    'meta' => [date('Y-m-d H:i',$ts), $a['phone']],
                    'chips' => $chips,
                    'actions' => [
                        ['icon'=>'fa-eye','label'=>'view','href'=>'?action=view&id='.(int)$a['id']],
                        (can('appointments.edit') && in_array($a['status'], ['scheduled','confirmed']))
                            ? ['icon'=>'fa-pen','label'=>'edit','href'=>'?action=edit&id='.(int)$a['id'],'modal'=>true] : null,
                        (can('appointments.edit') && in_array($a['status'], ['scheduled','confirmed']))
                            ? ['icon'=>'fa-ban','label'=>'cancel','href'=>'?action=cancel&id='.(int)$a['id'],'confirm'=>'confirm_cancel_appointment','divider_before'=>true] : null,
                        (can('appointments.delete'))
                            ? ['icon'=>'fa-trash','label'=>'delete','href'=>'?action=delete&id='.(int)$a['id'],'danger'=>true,'confirm'=>'confirm_delete'] : null,
                    ],
                ]);
            endforeach; endif; ?>
        </div>

        <div class="p-2"><?= render_pagination($total,$page,$perPage,BP_URL.'admin/appointments.php?'.http_build_query(['q'=>$q,'status'=>$status,'from'=>$from,'to'=>$to])) ?></div>
    </div>
</div>
<?php include BP_PARTIALS . '/footer.php'; ?>
