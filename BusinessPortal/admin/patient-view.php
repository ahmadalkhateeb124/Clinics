<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('patients.view');

$id     = (int)($_GET['id'] ?? 0);
$action = $_GET['action'] ?? '';

$s = db()->prepare("SELECT * FROM patients WHERE id=? AND deleted_at IS NULL");
$s->execute([$id]); $patient = $s->fetch();
if (!$patient) { flash('error','Patient not found.'); redirect(BP_URL . 'admin/patients.php'); }

$PageTitle = $patient['first_name'].' '.$patient['last_name'];

// ── Add medical record ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_record') {
    csrf_check();
    require_can('patients.edit');
    $type    = $_POST['record_type'] ?? 'note';
    $title   = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $date    = trim($_POST['record_date'] ?? date('Y-m-d'));
    if ($title === '') { flash('error', __('record_title') . ' ' . __('required')); redirect(BP_URL.'admin/patient-view.php?id='.$id); }

    db()->prepare("INSERT INTO medical_records
        (patient_id,record_type,title,content,record_date,created_by,created_at,updated_at)
        VALUES (?,?,?,?,?,?,NOW(),NOW())")
        ->execute([$id,$type,$title,$content,$date,$_SESSION['user_id']]);
    $rid = (int)db()->lastInsertId();
    log_activity('created','medical_records',"Added record to patient #$id",'medical_record',$rid);
    flash('success', __('add_record'));
    redirect(BP_URL.'admin/patient-view.php?id='.$id);
}

// ── Upload patient file ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload_file') {
    csrf_check();
    require_can('patients.edit');
    $cat = trim($_POST['category'] ?? 'general');
    $f   = $_FILES['file'] ?? null;

    // Diagnose PHP/browser upload errors before calling the helper
    if (!$f || !isset($f['error'])) {
        flash('error', __('no_file_selected'));
        redirect(BP_URL.'admin/patient-view.php?id='.$id.'#tab-files');
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        $errMap = [
            UPLOAD_ERR_INI_SIZE   => __('file_too_large'),
            UPLOAD_ERR_FORM_SIZE  => __('file_too_large'),
            UPLOAD_ERR_PARTIAL    => __('upload_partial'),
            UPLOAD_ERR_NO_FILE    => __('no_file_selected'),
            UPLOAD_ERR_NO_TMP_DIR => __('upload_server_error'),
            UPLOAD_ERR_CANT_WRITE => __('upload_server_error'),
            UPLOAD_ERR_EXTENSION  => __('upload_server_error'),
        ];
        flash('error', $errMap[$f['error']] ?? __('upload_server_error'));
        redirect(BP_URL.'admin/patient-view.php?id='.$id.'#tab-files');
    }

    $up = upload_file($f,'patients/'.$id, ['*'], 50*1024*1024);
    if (!$up) {
        flash('error', __('file_type_not_allowed'));
        redirect(BP_URL.'admin/patient-view.php?id='.$id.'#tab-files');
    }
    db()->prepare("INSERT INTO patient_files
        (patient_id,file_name,original_name,mime_type,size_bytes,category,uploaded_by,created_at)
        VALUES (?,?,?,?,?,?,?,NOW())")
        ->execute([$id,$up['relative_path'],$up['original_name'],$up['mime_type'],$up['size'],$cat,$_SESSION['user_id']]);
    log_activity('uploaded','patient_files',"Uploaded file for patient #$id",'patient_file',(int)db()->lastInsertId());
    flash('success', __('file_uploaded'));
    redirect(BP_URL.'admin/patient-view.php?id='.$id.'#tab-files');
}

// ── Record a payment against a specific invoice ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'pay_invoice') {
    csrf_check();
    require_can('payments.create');
    $invId   = (int)($_POST['invoice_id'] ?? 0);
    $amount  = (float)($_POST['amount'] ?? 0);
    $method  = $_POST['method'] ?? 'cash';
    if (!in_array($method, ['cash','card','bank','online','other'], true)) $method = 'cash';

    if ($invId > 0 && $amount > 0) {
        $inv = db()->prepare("SELECT * FROM invoices WHERE id=? AND patient_id=? AND deleted_at IS NULL");
        $inv->execute([$invId, $id]); $inv = $inv->fetch();
        if ($inv) {
            $remaining = max(0, (float)$inv['balance']);
            $amount    = min($amount, $remaining);
            if ($amount > 0) {
                $cdSession = current_cash_drawer((int)$_SESSION['user_id']);
                db()->prepare("INSERT INTO payments
                    (receipt_no,invoice_id,patient_id,amount,method,paid_at,cash_drawer_session_id,is_refund,created_by,created_at,updated_at)
                    VALUES (?,?,?,?,?,NOW(),?,0,?,NOW(),NOW())")
                    ->execute([next_receipt_no(),$invId,$id,$amount,$method,$cdSession['id'] ?? null,$_SESSION['user_id']]);
                if (function_exists('recompute_invoice')) {
                    recompute_invoice($invId);
                } else {
                    db()->prepare("UPDATE invoices SET paid_amount=paid_amount+?, balance=GREATEST(0,balance-?),
                                   status=CASE WHEN balance-? <= 0 THEN 'paid' ELSE 'partial' END,
                                   updated_at=NOW() WHERE id=?")
                        ->execute([$amount,$amount,$amount,$invId]);
                }
                // Recompute patient outstanding
                db()->prepare("UPDATE patients SET outstanding_balance = (
                        SELECT COALESCE(SUM(balance),0) FROM invoices
                        WHERE patient_id=? AND deleted_at IS NULL AND status IN ('issued','partial')
                    ) WHERE id=?")->execute([$id,$id]);
                log_activity('paid','invoices',"Payment ".format_money($amount)." on invoice #$invId",'invoice',$invId);
                flash('success', __('payment_recorded'));
            }
        }
    }
    redirect(BP_URL.'admin/patient-view.php?id='.$id.'#tab-billing');
}

// ── Record a payment against a patient_package ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'pay_package') {
    csrf_check();
    require_can('payments.create');
    $ppid    = (int)($_POST['patient_package_id'] ?? 0);
    $amount  = (float)($_POST['amount'] ?? 0);
    $method  = $_POST['method'] ?? 'cash';
    if (!in_array($method, ['cash','card','bank','online','other'], true)) $method = 'cash';

    if ($ppid > 0 && $amount > 0) {
        $pp = db()->prepare("SELECT * FROM patient_packages WHERE id=? AND patient_id=? AND deleted_at IS NULL");
        $pp->execute([$ppid, $id]); $pp = $pp->fetch();
        if ($pp) {
            $remaining = max(0, (float)$pp['price'] - (float)$pp['paid_amount']);
            $amount    = min($amount, $remaining);
            if ($amount > 0) {
                $cdSession = current_cash_drawer((int)$_SESSION['user_id']);
                db()->prepare("INSERT INTO payments
                    (receipt_no,patient_id,amount,method,paid_at,cash_drawer_session_id,notes,is_refund,created_by,created_at,updated_at)
                    VALUES (?,?,?,?,NOW(),?,?,0,?,NOW(),NOW())")
                    ->execute([next_receipt_no(),$id,$amount,$method,$cdSession['id'] ?? null,'Package #'.$ppid.': '.$pp['package_id'],$_SESSION['user_id']]);
                db()->prepare("UPDATE patient_packages SET paid_amount = paid_amount + ?, updated_at=NOW() WHERE id=?")
                    ->execute([$amount,$ppid]);
                log_activity('paid','patient_packages',"Payment ".format_money($amount)." on package #$ppid",'patient_package',$ppid);
                flash('success', __('payment_recorded'));
            }
        }
    }
    redirect(BP_URL.'admin/patient-view.php?id='.$id.'#tab-packages');
}

// ── Delete patient file ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_file') {
    csrf_check();
    require_can('patients.edit');
    $fid = (int)($_POST['file_id'] ?? 0);
    if ($fid > 0) {
        $fs = db()->prepare("SELECT file_name FROM patient_files WHERE id=? AND patient_id=? AND deleted_at IS NULL");
        $fs->execute([$fid, $id]);
        if ($row = $fs->fetch()) {
            $path = rtrim(UPLOADS_PATH, '/') . '/' . ltrim($row['file_name'], '/');
            if (is_file($path)) @unlink($path);
            db()->prepare("UPDATE patient_files SET deleted_at=NOW() WHERE id=?")->execute([$fid]);
            log_activity('deleted','patient_files',"Deleted file #$fid for patient #$id",'patient_file',$fid);
            flash('success', __('file_deleted'));
        }
    }
    redirect(BP_URL.'admin/patient-view.php?id='.$id.'#tab-files');
}

// ── Cancel/remove patient package ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'cancel_package') {
    csrf_check();
    require_can('packages.edit');
    $ppid = (int)($_POST['patient_package_id'] ?? 0);
    if ($ppid > 0) {
        db()->prepare("UPDATE patient_packages
                       SET status='cancelled', updated_by=?, updated_at=NOW()
                       WHERE id=? AND patient_id=?")
            ->execute([$_SESSION['user_id'], $ppid, $id]);
        log_activity('cancelled','patient_packages',"Cancelled package #$ppid for patient #$id",'patient_package',$ppid);
        flash('success', __('package_cancelled'));
    }
    redirect(BP_URL.'admin/patient-view.php?id='.$id.'#tab-packages');
}

// ── Refund a package payment (full or partial) ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'refund_package') {
    csrf_check();
    require_can('payments.create');
    $ppid    = (int)($_POST['patient_package_id'] ?? 0);
    $amount  = (float)($_POST['amount'] ?? 0);
    $method  = $_POST['method'] ?? 'cash';
    if (!in_array($method, ['cash','card','bank','online','other'], true)) $method = 'cash';

    if ($ppid > 0 && $amount > 0) {
        $pp = db()->prepare("SELECT * FROM patient_packages WHERE id=? AND patient_id=? AND deleted_at IS NULL");
        $pp->execute([$ppid, $id]); $pp = $pp->fetch();
        if ($pp) {
            $maxRefund = (float)$pp['paid_amount'];
            $amount    = min($amount, $maxRefund);
            if ($amount > 0) {
                $cdSession = current_cash_drawer((int)$_SESSION['user_id']);
                db()->prepare("INSERT INTO payments
                    (receipt_no,patient_id,amount,method,paid_at,cash_drawer_session_id,notes,is_refund,created_by,created_at,updated_at)
                    VALUES (?,?,?,?,NOW(),?,?,1,?,NOW(),NOW())")
                    ->execute([next_receipt_no(),$id,$amount,$method,$cdSession['id'] ?? null,'Refund for package #'.$ppid,$_SESSION['user_id']]);
                db()->prepare("UPDATE patient_packages SET paid_amount = GREATEST(0, paid_amount - ?), updated_at=NOW() WHERE id=?")
                    ->execute([$amount,$ppid]);
                log_activity('refunded','patient_packages',"Refund ".format_money($amount)." for package #$ppid",'patient_package',$ppid);
                flash('success', __('refund_recorded'));
            }
        }
    }
    redirect(BP_URL.'admin/patient-view.php?id='.$id.'#tab-packages');
}

// ── Reactivate a cancelled package ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'reactivate_package') {
    csrf_check();
    require_can('packages.edit');
    $ppid = (int)($_POST['patient_package_id'] ?? 0);
    if ($ppid > 0) {
        db()->prepare("UPDATE patient_packages
                       SET status='active', updated_by=?, updated_at=NOW()
                       WHERE id=? AND patient_id=? AND status='cancelled'")
            ->execute([$_SESSION['user_id'], $ppid, $id]);
        log_activity('reactivated','patient_packages',"Reactivated package #$ppid for patient #$id",'patient_package',$ppid);
        flash('success', __('package_reactivated'));
    }
    redirect(BP_URL.'admin/patient-view.php?id='.$id.'#tab-packages');
}

// ── Assign package ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'assign_package') {
    csrf_check();
    require_can('packages.edit');
    $pkgId = (int)($_POST['package_id'] ?? 0);
    if ($pkgId <= 0) { flash('error', __('pick_package')); redirect(BP_URL.'admin/patient-view.php?id='.$id); }
    $pkg = db()->prepare("SELECT * FROM packages WHERE id=? AND deleted_at IS NULL");
    $pkg->execute([$pkgId]); $pkg = $pkg->fetch();
    if (!$pkg) { flash('error', __('no_data')); redirect(BP_URL.'admin/patient-view.php?id='.$id); }
    $expiry = (new DateTime())->modify('+'.(int)$pkg['validity_days'].' days')->format('Y-m-d');
    db()->prepare("INSERT INTO patient_packages
        (patient_id,package_id,purchase_date,expiry_date,total_sessions,used_sessions,price,paid_amount,status,created_by,created_at,updated_at)
        VALUES (?,?,?,?,?,0,?,0,'active',?,NOW(),NOW())")
        ->execute([$id,$pkgId,date('Y-m-d'),$expiry,(int)$pkg['total_sessions'],(float)$pkg['price'],$_SESSION['user_id']]);
    log_activity('assigned','patient_packages',"Assigned package #$pkgId to patient #$id",'patient_package',(int)db()->lastInsertId());
    flash('success', __('package_added'));
    redirect(BP_URL.'admin/patient-view.php?id='.$id);
}

// ── Data ─────────────────────────────────────────────────────────────
$records = db()->prepare("SELECT * FROM medical_records WHERE patient_id=? AND deleted_at IS NULL ORDER BY record_date DESC, id DESC LIMIT 100");
$records->execute([$id]); $records = $records->fetchAll();

$files = db()->prepare("SELECT * FROM patient_files WHERE patient_id=? AND deleted_at IS NULL ORDER BY id DESC");
$files->execute([$id]); $files = $files->fetchAll();

$activePkgs = db()->prepare("
    SELECT pp.*, pk.name_ar AS package_name, pk.name_en AS package_name_en
    FROM patient_packages pp
    JOIN packages pk ON pk.id = pp.package_id
    WHERE pp.patient_id = ? AND pp.deleted_at IS NULL
    ORDER BY pp.id DESC
");
$activePkgs->execute([$id]); $activePkgs = $activePkgs->fetchAll();

$availablePkgs = db()->query("SELECT id,name_ar,total_sessions,price FROM packages WHERE deleted_at IS NULL AND is_active=1 ORDER BY name_ar")->fetchAll();

// Quick stats
$stats = db()->prepare("
    SELECT
        COUNT(*) AS total_visits,
        SUM(status IN ('scheduled','confirmed')) AS open_appts
    FROM appointments
    WHERE patient_id = ? AND deleted_at IS NULL
");
$stats->execute([$id]);
$apptStats = $stats->fetch() ?: ['total_visits'=>0,'open_appts'=>0];

$paidStmt = db()->prepare("
    SELECT COALESCE(SUM(amount),0) FROM payments
    WHERE patient_id = ? AND deleted_at IS NULL AND is_refund = 0
");
$paidStmt->execute([$id]);
$paidTotal = (float)$paidStmt->fetchColumn();

$activePkgCount = 0;
$pkgOutstanding = 0;
foreach ($activePkgs as $pp) {
    if ($pp['status'] === 'active') {
        $activePkgCount++;
        $pkgOutstanding += max(0, (float)$pp['price'] - (float)$pp['paid_amount']);
    }
}
// Total outstanding = unpaid invoices + unpaid active packages
$totalOutstanding = (float)$patient['outstanding_balance'] + $pkgOutstanding;

// ── Build a unified timeline (records + packages + appointments + invoices + payments) ──
$tlAppts = db()->prepare("
    SELECT a.id, a.start_at, a.status, a.total_price, a.patient_package_id,
           u.name AS therapist_name, r.name AS room_name,
           GROUP_CONCAT(s.name_ar SEPARATOR ' · ') AS svc_list
    FROM appointments a
    LEFT JOIN users u ON u.id = a.therapist_id
    LEFT JOIN rooms r ON r.id = a.room_id
    LEFT JOIN appointment_services asv ON asv.appointment_id = a.id
    LEFT JOIN services s ON s.id = asv.service_id
    WHERE a.patient_id = ? AND a.deleted_at IS NULL
    GROUP BY a.id
    ORDER BY a.start_at DESC LIMIT 100
");
$tlAppts->execute([$id]); $tlAppts = $tlAppts->fetchAll();

$tlInvoices = db()->prepare("SELECT id,invoice_no,issue_date,total,paid_amount,balance,status FROM invoices
    WHERE patient_id=? AND deleted_at IS NULL ORDER BY issue_date DESC LIMIT 100");
$tlInvoices->execute([$id]); $tlInvoices = $tlInvoices->fetchAll();

$tlPayments = db()->prepare("SELECT id,receipt_no,paid_at,amount,method,is_refund FROM payments
    WHERE patient_id=? AND deleted_at IS NULL ORDER BY paid_at DESC LIMIT 100");
$tlPayments->execute([$id]); $tlPayments = $tlPayments->fetchAll();

$events = [];
foreach ($records as $r) {
    $events[] = ['ts'=>$r['record_date'].' 00:00:00','type'=>'record','data'=>$r];
}
foreach ($activePkgs as $pp) {
    $events[] = ['ts'=>$pp['purchase_date'].' 00:00:00','type'=>'package','data'=>$pp];
}
foreach ($tlAppts as $a) {
    $events[] = ['ts'=>$a['start_at'],'type'=>'appointment','data'=>$a];
}
foreach ($tlInvoices as $iv) {
    $events[] = ['ts'=>$iv['issue_date'].' 00:00:00','type'=>'invoice','data'=>$iv];
}
foreach ($tlPayments as $py) {
    $events[] = ['ts'=>$py['paid_at'],'type'=>'payment','data'=>$py];
}
usort($events, fn($a,$b) => strcmp($b['ts'],$a['ts']));

$age = '';
if ($patient['dob']) $age = (new DateTime($patient['dob']))->diff(new DateTime())->y;

$genderKey = 'g_' . ($patient['gender'] ?: 'other');

include BP_PARTIALS . '/header.php';
?>
<div class="page-wrap">

    <!-- ═══════════════ HERO ═══════════════ -->
    <div class="profile-hero">
        <div class="profile-hero-row">
            <div class="profile-avatar">
                <?php if ($patient['avatar']): ?>
                    <img src="<?= UPLOADS_URL . e($patient['avatar']) ?>" alt="">
                <?php else: ?>
                    <i class="fa-solid fa-user"></i>
                <?php endif; ?>
            </div>
            <div class="profile-info">
                <h1 class="profile-name">
                    <?= e($patient['first_name'].' '.$patient['last_name']) ?>
                    <span class="profile-code"><?= e($patient['code']) ?></span>
                </h1>
                <div class="profile-meta">
                    <span><i class="fa-solid fa-venus-mars me-1"></i><?= e(__($genderKey)) ?: '—' ?></span>
                    <?php if ($age !== ''): ?>
                        <span><i class="fa-solid fa-cake-candles me-1"></i><?= (int)$age ?> <?= __('years') ?></span>
                    <?php endif; ?>
                    <span><i class="fa-solid fa-phone me-1"></i><?= e($patient['phone']) ?></span>
                    <?php if ($patient['email']): ?>
                        <span><i class="fa-solid fa-envelope me-1"></i><?= e($patient['email']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="profile-actions">
                <a class="btn btn-sm" href="<?= BP_URL ?>admin/patients.php">
                    <i class="fa-solid fa-arrow-<?= ((($_SESSION['admin_lang'] ?? 'ar')==='ar')?'right':'left') ?> me-1"></i><?= __('back_to_list') ?>
                </a>
                <?php if (can('patients.edit')): ?>
                    <a class="btn btn-sm" href="<?= BP_URL ?>admin/patients.php?action=edit&id=<?= $id ?>" data-modal>
                        <i class="fa-solid fa-pen me-1"></i><?= __('edit') ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ═══════════════ QUICK STATS ═══════════════ -->
    <div class="profile-stats">
        <div class="profile-stat">
            <div class="profile-stat-icon"><i class="fa-solid fa-calendar-check"></i></div>
            <div>
                <div class="profile-stat-label"><?= __('total_visits') ?></div>
                <div class="profile-stat-value"><?= (int)$apptStats['total_visits'] ?></div>
            </div>
        </div>
        <div class="profile-stat">
            <div class="profile-stat-icon bg-info-soft"><i class="fa-solid fa-clock"></i></div>
            <div>
                <div class="profile-stat-label"><?= __('open_appointments') ?></div>
                <div class="profile-stat-value"><?= (int)$apptStats['open_appts'] ?></div>
            </div>
        </div>
        <div class="profile-stat">
            <div class="profile-stat-icon bg-warning-soft"><i class="fa-solid fa-box"></i></div>
            <div>
                <div class="profile-stat-label"><?= __('active_packages_count') ?></div>
                <div class="profile-stat-value"><?= $activePkgCount ?></div>
            </div>
        </div>
        <div class="profile-stat">
            <?php $invOutstand = (float)$patient['outstanding_balance']; ?>
            <div class="profile-stat-icon <?= ($invOutstand>0)?'bg-danger-soft':'' ?>">
                <i class="fa-solid <?= ($invOutstand>0)?'fa-triangle-exclamation':'fa-circle-check' ?>"></i>
            </div>
            <div>
                <div class="profile-stat-label"><?= __('outstanding_balance') ?></div>
                <div class="profile-stat-value <?= ($invOutstand>0)?'text-danger':'text-success' ?>">
                    <?= ($invOutstand>0) ? format_money($invOutstand) : '—' ?>
                </div>
                <?php if ($pkgOutstanding > 0): ?>
                    <small class="text-warning" style="font-size:.7rem;display:block;margin-top:2px">
                        <i class="fa-solid fa-hourglass-half me-1"></i><?= __('packages') ?>: <?= format_money($pkgOutstanding) ?>
                    </small>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <!-- ═══════════════ SIDEBAR ═══════════════ -->
        <div class="col-lg-4">

            <div class="info-card">
                <div class="info-card-title"><i class="fa-solid fa-id-card"></i><?= __('general_info') ?></div>
                <ul class="info-list">
                    <li><span class="label"><?= __('dob') ?></span><span class="value <?= !$patient['dob']?'muted':'' ?>"><?= e($patient['dob'] ?: '—') ?></span></li>
                    <li><span class="label"><?= __('gender') ?></span><span class="value"><?= e(__($genderKey)) ?: '—' ?></span></li>
                    <li><span class="label"><?= __('national_id') ?></span><span class="value <?= !$patient['national_id']?'muted':'' ?>"><?= e($patient['national_id'] ?: '—') ?></span></li>
                    <li><span class="label"><?= __('patient_since') ?></span><span class="value"><?= format_date($patient['created_at'],'Y-m-d') ?></span></li>
                </ul>
            </div>

            <div class="info-card">
                <div class="info-card-title"><i class="fa-solid fa-address-book"></i><?= __('contact_info') ?></div>
                <ul class="info-list">
                    <li><span class="label"><?= __('phone') ?></span><span class="value"><?= e($patient['phone']) ?></span></li>
                    <li><span class="label"><?= __('email') ?></span><span class="value <?= !$patient['email']?'muted':'' ?>"><?= e($patient['email'] ?: '—') ?></span></li>
                    <li><span class="label"><?= __('address') ?></span><span class="value <?= !$patient['address']?'muted':'' ?>"><?= e($patient['address'] ?: '—') ?></span></li>
                    <li><span class="label"><?= __('city') ?></span><span class="value <?= !$patient['city']?'muted':'' ?>"><?= e($patient['city'] ?: '—') ?></span></li>
                    <li><span class="label"><?= __('country') ?></span><span class="value <?= !$patient['country']?'muted':'' ?>"><?= e($patient['country'] ?: '—') ?></span></li>
                </ul>
            </div>

            <?php if ($patient['emergency_name'] || $patient['emergency_phone']): ?>
            <div class="info-card">
                <div class="info-card-title"><i class="fa-solid fa-bell"></i><?= __('emergency_contact') ?></div>
                <ul class="info-list">
                    <li><span class="label"><?= __('name') ?></span><span class="value <?= !$patient['emergency_name']?'muted':'' ?>"><?= e($patient['emergency_name'] ?: '—') ?></span></li>
                    <li><span class="label"><?= __('phone') ?></span><span class="value <?= !$patient['emergency_phone']?'muted':'' ?>"><?= e($patient['emergency_phone'] ?: '—') ?></span></li>
                </ul>
            </div>
            <?php endif; ?>

            <div class="info-card">
                <div class="info-card-title"><i class="fa-solid fa-stethoscope"></i><?= __('medical_card') ?></div>

                <div class="medical-block">
                    <div class="medical-block-label"><?= __('history') ?></div>
                    <div class="medical-block-value <?= !$patient['medical_history']?'empty':'' ?>">
                        <?= nl2br(e($patient['medical_history'] ?: '—')) ?>
                    </div>
                </div>
                <div class="medical-block">
                    <div class="medical-block-label"><?= __('allergies') ?></div>
                    <div class="medical-block-value <?= !$patient['allergies']?'empty':'' ?>">
                        <?= nl2br(e($patient['allergies'] ?: '—')) ?>
                    </div>
                </div>
                <div class="medical-block">
                    <div class="medical-block-label"><?= __('chronic') ?></div>
                    <div class="medical-block-value <?= !$patient['chronic_conditions']?'empty':'' ?>">
                        <?= nl2br(e($patient['chronic_conditions'] ?: '—')) ?>
                    </div>
                </div>
                <div class="medical-block">
                    <div class="medical-block-label"><?= __('medications') ?></div>
                    <div class="medical-block-value <?= !$patient['current_medications']?'empty':'' ?>">
                        <?= nl2br(e($patient['current_medications'] ?: '—')) ?>
                    </div>
                </div>
            </div>

        </div>

        <!-- ═══════════════ MAIN CONTENT (TABS) ═══════════════ -->
        <div class="col-lg-8">

            <ul class="profile-tabs nav" role="tablist">
                <li class="nav-item">
                    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-timeline" type="button">
                        <i class="fa-solid fa-stream"></i><?= __('timeline') ?>
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-records" type="button">
                        <i class="fa-solid fa-notes-medical"></i><?= __('records') ?>
                        <span class="count"><?= count($records) ?></span>
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-files" type="button">
                        <i class="fa-solid fa-paperclip"></i><?= __('files') ?>
                        <span class="count"><?= count($files) ?></span>
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-packages" type="button">
                        <i class="fa-solid fa-box-open"></i><?= __('packages_tab') ?>
                        <span class="count"><?= count($activePkgs) ?></span>
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-billing" type="button">
                        <i class="fa-solid fa-file-invoice-dollar"></i><?= __('billing') ?>
                        <?php
                            $openInvCount = 0;
                            foreach ($tlInvoices as $iv) if (in_array($iv['status'], ['issued','partial'], true)) $openInvCount++;
                            if ($openInvCount > 0):
                        ?>
                            <span class="count bg-danger text-white"><?= $openInvCount ?></span>
                        <?php endif; ?>
                    </button>
                </li>
            </ul>

            <div class="tab-content profile-tab-content">

                <!-- TIMELINE -->
                <div class="tab-pane fade show active" id="tab-timeline">
                    <?php if (!$events): ?>
                        <div class="empty-state">
                            <i class="fa-solid fa-stream"></i>
                            <div class="empty-state-text"><?= __('no_timeline_yet') ?></div>
                        </div>
                    <?php else: ?>
                        <!-- Financial summary banner -->
                        <?php
                            $totalInvoiced = array_sum(array_column($tlInvoices,'total'));
                            $totalRefunds  = 0;
                            foreach ($tlPayments as $py) if (!empty($py['is_refund'])) $totalRefunds += (float)$py['amount'];
                            $totalPaidNet  = $paidTotal - $totalRefunds;
                            $outstanding = (float)$patient['outstanding_balance']; // invoices only (truly overdue)
                        ?>
                        <div class="timeline-summary">
                            <div class="timeline-summary-item">
                                <div class="timeline-summary-label"><?= __('total_invoiced') ?></div>
                                <div class="timeline-summary-value"><?= format_money($totalInvoiced) ?></div>
                            </div>
                            <div class="timeline-summary-item success">
                                <div class="timeline-summary-label"><?= __('total_paid') ?></div>
                                <div class="timeline-summary-value">+<?= format_money($totalPaidNet) ?></div>
                            </div>
                            <div class="timeline-summary-item <?= $outstanding>0?'danger':'' ?>">
                                <div class="timeline-summary-label"><?= __('outstanding') ?></div>
                                <div class="timeline-summary-value"><?= format_money($outstanding) ?></div>
                            </div>
                        </div>

                        <ul class="timeline list-unstyled">
                        <?php foreach ($events as $ev):
                            $d = $ev['data'];
                            switch ($ev['type']):
                                case 'record':
                                    $rtKey = 'rt_' . $d['record_type'];
                        ?>
                            <li class="timeline-item">
                                <div class="timeline-icon"><i class="fa-solid fa-notes-medical"></i></div>
                                <div class="timeline-card">
                                    <h6 class="timeline-card-title">
                                        <?= e($d['title']) ?>
                                        <span class="badge bg-light"><?= e(__($rtKey)) ?: e($d['record_type']) ?></span>
                                    </h6>
                                    <div class="timeline-card-date"><?= format_date($d['record_date'],'Y-m-d') ?></div>
                                    <?php if ($d['content']): ?>
                                        <div class="timeline-card-body"><?= nl2br(e($d['content'])) ?></div>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php break; case 'package':
                                $statusCol = $d['status']==='active'?'success':($d['status']==='expired'?'warning':'secondary');
                                $stKey = 'st_' . $d['status'];
                        ?>
                            <li class="timeline-item">
                                <div class="timeline-icon" style="background:#6366f1"><i class="fa-solid fa-box-open"></i></div>
                                <div class="timeline-card">
                                    <h6 class="timeline-card-title">
                                        <?= __('package_added') ?>: <?= e($d['package_name']) ?>
                                        <span class="badge bg-<?= $statusCol ?>"><?= e(__($stKey)) ?: e($d['status']) ?></span>
                                    </h6>
                                    <div class="timeline-card-date">
                                        <?= __('purchased') ?>: <?= e($d['purchase_date']) ?>
                                        · <?= __('expires') ?>: <?= e($d['expiry_date']) ?>
                                    </div>
                                    <div class="timeline-card-body">
                                        <strong><?= format_money($d['price']) ?></strong>
                                        · <?= __('used') ?>: <?= (int)$d['used_sessions'] ?>/<?= (int)$d['total_sessions'] ?> <?= __('sessions') ?>
                                    </div>
                                </div>
                            </li>
                        <?php break; case 'appointment':
                                $apptStatusKey = 'st_' . $d['status'];
                                $apptIconCls = ['completed'=>'success','cancelled'=>'danger','no_show'=>'warning','confirmed'=>'primary'][$d['status']] ?? 'info';
                                $apptIcon    = ['completed'=>'fa-check-double','cancelled'=>'fa-ban','no_show'=>'fa-circle-xmark','confirmed'=>'fa-circle-check'][$d['status']] ?? 'fa-calendar-day';
                                $apptBg      = ['completed'=>'#10b981','cancelled'=>'#ef4444','no_show'=>'#f59e0b','confirmed'=>'#3b82f6'][$d['status']] ?? '#0ea5e9';
                                // Headline verb per status (booked / completed / cancelled / no-show / confirmed)
                                $apptHeadKey = ['scheduled'=>'appointment_booked','confirmed'=>'appointment_confirmed','completed'=>'appointment_completed','cancelled'=>'appointment_cancelled','no_show'=>'appointment_no_show'][$d['status']] ?? 'appointment_booked';
                        ?>
                            <li class="timeline-item">
                                <div class="timeline-icon" style="background:<?= $apptBg ?>"><i class="fa-solid <?= $apptIcon ?>"></i></div>
                                <div class="timeline-card">
                                    <h6 class="timeline-card-title">
                                        <a href="<?= BP_URL ?>admin/appointments.php?action=view&id=<?= (int)$d['id'] ?>" class="text-decoration-none text-dark"><?= __($apptHeadKey) ?> #<?= (int)$d['id'] ?></a>
                                        <span class="badge bg-<?= $apptIconCls ?>"><?= e(__($apptStatusKey)) ?: e($d['status']) ?></span>
                                        <?php if (!empty($d['patient_package_id'])): ?>
                                            <span class="badge bg-light text-dark" title="<?= __('covered_by_package') ?>"><i class="fa-solid fa-box-open"></i></span>
                                        <?php endif; ?>
                                    </h6>
                                    <div class="timeline-card-date">
                                        <i class="fa-regular fa-clock me-1"></i><?= format_date($d['start_at'],'Y-m-d H:i') ?>
                                        <?php if (!empty($d['therapist_name'])): ?>
                                            · <i class="fa-solid fa-user-doctor me-1"></i><?= e($d['therapist_name']) ?>
                                        <?php endif; ?>
                                        <?php if (!empty($d['room_name'])): ?>
                                            · <i class="fa-solid fa-door-open me-1"></i><?= e($d['room_name']) ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($d['svc_list'])): ?>
                                        <div class="timeline-card-body small text-muted"><?= e($d['svc_list']) ?></div>
                                    <?php endif; ?>
                                    <?php if ((float)$d['total_price'] > 0): ?>
                                        <div class="timeline-card-body"><strong><?= format_money($d['total_price']) ?></strong></div>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php break; case 'invoice':
                                $invStatusKey = 'inv_' . $d['status'];
                                $invCls = ['paid'=>'success','partial'=>'warning','cancelled'=>'danger','refunded'=>'secondary'][$d['status']] ?? 'info';
                                $invBal = (float)$d['balance'];
                        ?>
                            <li class="timeline-item">
                                <div class="timeline-icon" style="background:#0d9488"><i class="fa-solid fa-file-invoice"></i></div>
                                <div class="timeline-card">
                                    <h6 class="timeline-card-title">
                                        <?= __('invoice_no') ?>: <code><?= e($d['invoice_no']) ?></code>
                                        <span class="badge bg-<?= $invCls ?>"><?= e(__($invStatusKey)) ?: e($d['status']) ?></span>
                                    </h6>
                                    <div class="timeline-card-date"><?= format_date($d['issue_date'],'Y-m-d') ?></div>
                                    <div class="timeline-card-body">
                                        <strong><?= format_money($d['total']) ?></strong>
                                        · <?= __('paid') ?>: <?= format_money($d['paid_amount']) ?>
                                        <?php if ($invBal > 0): ?>
                                            · <span class="text-danger"><?= __('balance') ?>: <?= format_money($invBal) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </li>
                        <?php break; case 'payment':
                                $isRef = !empty($d['is_refund']);
                        ?>
                            <li class="timeline-item">
                                <div class="timeline-icon" style="background:<?= $isRef?'#ef4444':'#10b981' ?>">
                                    <i class="fa-solid <?= $isRef?'fa-rotate-left':'fa-money-bill-wave' ?>"></i>
                                </div>
                                <div class="timeline-card">
                                    <h6 class="timeline-card-title">
                                        <?= $isRef ? __('refund') : __('payment') ?>
                                        <span class="badge bg-light"><?= e($d['method']) ?></span>
                                        <code class="ms-1 small"><?= e($d['receipt_no']) ?></code>
                                    </h6>
                                    <div class="timeline-card-date"><?= format_date($d['paid_at'],'Y-m-d H:i') ?></div>
                                    <div class="timeline-card-body" style="color:<?= $isRef?'#b91c1c':'#047857' ?>;font-weight:700">
                                        <?= $isRef?'−':'+' ?><?= format_money($d['amount']) ?>
                                    </div>
                                </div>
                            </li>
                        <?php break; endswitch; endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <!-- RECORDS -->
                <div class="tab-pane fade" id="tab-records">
                    <?php if (can('patients.edit')): ?>
                        <form method="post" action="?id=<?= $id ?>&action=add_record" class="inline-form">
                            <?= csrf_field() ?>
                            <div class="inline-form-title">
                                <i class="fa-solid fa-circle-plus"></i><?= __('add_record') ?>
                            </div>
                            <div class="row g-2">
                                <div class="col-md-3">
                                    <select name="record_type" class="form-select form-select-sm">
                                        <?php foreach (['note','diagnosis','treatment','prescription','test_result','xray','other'] as $t): ?>
                                            <option value="<?= $t ?>"><?= e(__('rt_'.$t)) ?: $t ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3"><input name="record_date" type="date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"></div>
                                <div class="col-md-6"><input name="title" required class="form-control form-control-sm" placeholder="<?= __('record_title') ?>"></div>
                                <div class="col-md-12"><textarea name="content" class="form-control form-control-sm" rows="2" placeholder="<?= __('record_content') ?>"></textarea></div>
                                <div class="col-md-12"><button class="btn btn-sm btn-teal"><i class="fa-solid fa-plus me-1"></i><?= __('add_record') ?></button></div>
                            </div>
                        </form>
                    <?php endif; ?>

                    <?php if (!$records): ?>
                        <div class="empty-state">
                            <i class="fa-solid fa-notes-medical"></i>
                            <div class="empty-state-text"><?= __('no_records_yet') ?></div>
                        </div>
                    <?php else: foreach ($records as $r):
                        $rtKey = 'rt_' . $r['record_type'];
                    ?>
                        <div class="record-item">
                            <div class="record-item-head">
                                <div>
                                    <span class="record-item-title"><?= e($r['title']) ?></span>
                                    <span class="badge bg-light ms-1"><?= e(__($rtKey)) ?: e($r['record_type']) ?></span>
                                </div>
                                <div class="record-item-date"><i class="fa-regular fa-clock me-1"></i><?= format_date($r['record_date'],'Y-m-d') ?></div>
                            </div>
                            <?php if ($r['content']): ?>
                                <div class="record-item-body"><?= nl2br(e($r['content'])) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; endif; ?>
                </div>

                <!-- FILES -->
                <div class="tab-pane fade" id="tab-files">
                    <?php if (can('patients.edit')): ?>
                        <form method="post" action="?id=<?= $id ?>&action=upload_file" enctype="multipart/form-data" class="inline-form">
                            <?= csrf_field() ?>
                            <div class="inline-form-title">
                                <i class="fa-solid fa-cloud-arrow-up"></i><?= __('upload_file') ?>
                            </div>
                            <div class="row g-2">
                                <div class="col-md-3">
                                    <select name="category" class="form-select form-select-sm">
                                        <?php foreach (['general','xray','report','prescription','photo'] as $c): ?>
                                            <option value="<?= $c ?>"><?= e(__('cat_'.$c)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6"><input type="file" name="file" required class="form-control form-control-sm"></div>
                                <div class="col-md-3"><button class="btn btn-sm btn-teal w-100"><i class="fa-solid fa-upload me-1"></i><?= __('upload') ?></button></div>
                            </div>
                        </form>
                    <?php endif; ?>

                    <?php if (!$files): ?>
                        <div class="empty-state">
                            <i class="fa-solid fa-folder-open"></i>
                            <div class="empty-state-text"><?= __('no_files_yet') ?></div>
                        </div>
                    <?php else: ?>
                        <div class="row g-2">
                            <?php foreach ($files as $f):
                                $isPdf = strpos($f['mime_type'],'pdf') !== false;
                            ?>
                                <div class="col-md-6">
                                    <div class="file-tile-wrap">
                                        <a class="file-tile" href="<?= UPLOADS_URL . e($f['file_name']) ?>" target="_blank">
                                            <div class="file-tile-icon <?= $isPdf?'is-pdf':'' ?>">
                                                <i class="fa-solid <?= $isPdf?'fa-file-pdf':'fa-image' ?>"></i>
                                            </div>
                                            <div>
                                                <div class="file-tile-name"><?= e($f['original_name']) ?></div>
                                                <div class="file-tile-meta">
                                                    <?= e(__('cat_'.$f['category'])) ?: e($f['category']) ?>
                                                    · <?= number_format($f['size_bytes']/1024,1) ?> KB
                                                </div>
                                            </div>
                                        </a>
                                        <?php if (can('patients.edit')): ?>
                                            <form method="post" action="?id=<?= $id ?>&action=delete_file" class="file-tile-del m-0">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="<?= __('delete') ?>" data-confirm="<?= __('confirm_delete_file') ?>">
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- PACKAGES -->
                <div class="tab-pane fade" id="tab-packages">
                    <?php if (can('packages.edit') && $availablePkgs): ?>
                        <form method="post" action="?id=<?= $id ?>&action=assign_package" class="inline-form">
                            <?= csrf_field() ?>
                            <div class="inline-form-title">
                                <i class="fa-solid fa-circle-plus"></i><?= __('assign_package_btn') ?>
                            </div>
                            <div class="row g-2">
                                <div class="col-md-9">
                                    <select name="package_id" class="form-select form-select-sm" required>
                                        <option value="">— <?= __('pick_package') ?> —</option>
                                        <?php foreach ($availablePkgs as $pk): ?>
                                            <option value="<?= (int)$pk['id'] ?>">
                                                <?= e($pk['name_ar']) ?> · <?= (int)$pk['total_sessions'] ?> <?= __('sessions') ?> · <?= format_money($pk['price']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3"><button class="btn btn-sm btn-teal w-100"><i class="fa-solid fa-plus me-1"></i><?= __('assign_package_btn') ?></button></div>
                            </div>
                        </form>
                    <?php endif; ?>

                    <?php if (!$activePkgs): ?>
                        <div class="empty-state">
                            <i class="fa-solid fa-box-open"></i>
                            <div class="empty-state-text"><?= __('no_packages_yet') ?></div>
                        </div>
                    <?php else: foreach ($activePkgs as $pp):
                        $statusCol = $pp['status']==='active'?'success':($pp['status']==='expired'?'warning':'secondary');
                        $stKey = 'st_' . $pp['status'];
                        $sessionsPct = $pp['total_sessions'] > 0 ? round((int)$pp['used_sessions'] / max(1,$pp['total_sessions']) * 100) : 0;
                        $paidPct     = $pp['price']          > 0 ? round((float)$pp['paid_amount'] / max(0.01,(float)$pp['price']) * 100) : 0;
                    ?>
                        <div class="pkg-card">
                            <div class="pkg-card-head">
                                <div>
                                    <h6 class="pkg-card-title"><i class="fa-solid fa-box-open"></i><?= e($pp['package_name']) ?></h6>
                                    <div class="pkg-card-dates">
                                        <?= __('purchased') ?>: <strong><?= e($pp['purchase_date']) ?></strong>
                                        · <?= __('expires') ?>: <strong><?= e($pp['expiry_date']) ?></strong>
                                    </div>
                                    <?php
                                        $pkgRemaining = max(0, (float)$pp['price'] - (float)$pp['paid_amount']);
                                        if ($pp['status']==='active' && $pkgRemaining > 0):
                                    ?>
                                        <div class="mt-2">
                                            <span class="entity-chip warn">
                                                <i class="fa-solid fa-hourglass-half"></i>
                                                <?= __('remaining_balance') ?>: <strong><?= format_money($pkgRemaining) ?></strong>
                                            </span>
                                        </div>
                                    <?php elseif ($pkgRemaining <= 0): ?>
                                        <div class="mt-2">
                                            <span class="entity-chip success">
                                                <i class="fa-solid fa-circle-check"></i>
                                                <?= __('fully_paid') ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="badge bg-<?= $statusCol ?>"><?= e(__($stKey)) ?: e($pp['status']) ?></span>
                                    <?php if ($pp['status']==='active' && can('packages.edit')): ?>
                                        <form method="post" action="?id=<?= $id ?>&action=cancel_package" class="m-0">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="patient_package_id" value="<?= (int)$pp['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="<?= __('cancel_package') ?>" data-confirm="<?= __('confirm_cancel_package') ?>">
                                                <i class="fa-solid fa-ban"></i>
                                            </button>
                                        </form>
                                    <?php elseif ($pp['status']==='cancelled' && can('packages.edit')): ?>
                                        <form method="post" action="?id=<?= $id ?>&action=reactivate_package" class="m-0">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="patient_package_id" value="<?= (int)$pp['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-success" title="<?= __('reactivate_package') ?>">
                                                <i class="fa-solid fa-rotate-right me-1"></i><?= __('reactivate') ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <?php
                                        // Show refund button on any cancelled/expired package that has paid_amount > 0
                                        $pkgPaid = (float)$pp['paid_amount'];
                                        if (in_array($pp['status'], ['cancelled','expired'], true) && $pkgPaid > 0 && can('payments.create')):
                                    ?>
                                        <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="collapse" data-bs-target="#refund-<?= (int)$pp['id'] ?>" title="<?= __('refund') ?>">
                                            <i class="fa-solid fa-rotate-left me-1"></i><?= __('refund') ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="pkg-progress">
                                <div class="pkg-progress-label">
                                    <span><i class="fa-solid fa-circle-check me-1"></i><?= __('sessions_progress') ?></span>
                                    <span><strong><?= (int)$pp['used_sessions'] ?></strong> / <?= (int)$pp['total_sessions'] ?> (<?= $sessionsPct ?>%)</span>
                                </div>
                                <div class="progress">
                                    <div class="progress-bar" style="width:<?= $sessionsPct ?>%"></div>
                                </div>
                            </div>

                            <div class="pkg-progress">
                                <div class="pkg-progress-label">
                                    <span><i class="fa-solid fa-coins me-1"></i><?= __('payment_progress') ?></span>
                                    <span><strong><?= format_money($pp['paid_amount']) ?></strong> / <?= format_money($pp['price']) ?> (<?= $paidPct ?>%)</span>
                                </div>
                                <div class="progress">
                                    <div class="progress-bar bg-paid" style="width:<?= $paidPct ?>%"></div>
                                </div>
                            </div>

                            <?php
                                $remaining = max(0, (float)$pp['price'] - (float)$pp['paid_amount']);
                                if ($pp['status']==='active' && $remaining > 0 && can('payments.create')):
                            ?>
                                <form method="post" action="?id=<?= $id ?>&action=pay_package" class="pkg-pay-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="patient_package_id" value="<?= (int)$pp['id'] ?>">
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text"><i class="fa-solid fa-coins"></i></span>
                                        <input type="number" name="amount" step="0.01" min="0.01" max="<?= $remaining ?>"
                                               value="<?= $remaining ?>" required class="form-control"
                                               placeholder="<?= __('amount') ?>">
                                        <select name="method" class="form-select" style="max-width:120px">
                                            <option value="cash"><?= __('m_cash') ?: 'Cash' ?></option>
                                            <option value="card"><?= __('m_card') ?: 'Card' ?></option>
                                            <option value="bank"><?= __('m_bank') ?: 'Bank' ?></option>
                                            <option value="online"><?= __('m_online') ?: 'Online' ?></option>
                                        </select>
                                        <button class="btn btn-success" type="submit">
                                            <i class="fa-solid fa-plus me-1"></i><?= __('record_payment') ?>
                                        </button>
                                    </div>
                                    <small class="text-muted"><?= __('remaining') ?>: <strong><?= format_money($remaining) ?></strong></small>
                                </form>
                            <?php endif; ?>

                            <?php
                                // Refund form for cancelled/expired packages with paid_amount > 0
                                $pkgPaid = (float)$pp['paid_amount'];
                                if (in_array($pp['status'], ['cancelled','expired'], true) && $pkgPaid > 0 && can('payments.create')):
                            ?>
                                <div class="collapse" id="refund-<?= (int)$pp['id'] ?>">
                                    <form method="post" action="?id=<?= $id ?>&action=refund_package" class="pkg-pay-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="patient_package_id" value="<?= (int)$pp['id'] ?>">
                                        <div class="alert alert-danger py-2 mb-2 small">
                                            <i class="fa-solid fa-rotate-left me-1"></i>
                                            <strong><?= __('refund_to_patient') ?></strong> · <?= __('max_refund') ?>: <?= format_money($pkgPaid) ?>
                                        </div>
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text"><i class="fa-solid fa-rotate-left"></i></span>
                                            <input type="number" name="amount" step="0.01" min="0.01" max="<?= $pkgPaid ?>"
                                                   value="<?= $pkgPaid ?>" required class="form-control"
                                                   placeholder="<?= __('amount') ?>">
                                            <select name="method" class="form-select" style="max-width:120px">
                                                <option value="cash"><?= __('m_cash') ?></option>
                                                <option value="card"><?= __('m_card') ?></option>
                                                <option value="bank"><?= __('m_bank') ?></option>
                                                <option value="online"><?= __('m_online') ?></option>
                                            </select>
                                            <button class="btn btn-danger" type="submit" data-confirm="<?= __('confirm_refund') ?>">
                                                <i class="fa-solid fa-rotate-left me-1"></i><?= __('issue_refund') ?>
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; endif; ?>
                </div>

                <!-- BILLING -->
                <div class="tab-pane fade" id="tab-billing">
                    <?php
                        $billTotal     = array_sum(array_column($tlInvoices,'total'));
                        $billRefunds   = 0;
                        foreach ($tlPayments as $py) if (!empty($py['is_refund'])) $billRefunds += (float)$py['amount'];
                        $billPaid      = $paidTotal - $billRefunds;
                        $billOutstand  = max(0, (float)$patient['outstanding_balance']);
                    ?>
                    <div class="timeline-summary mb-3">
                        <div class="timeline-summary-item">
                            <div class="timeline-summary-label"><?= __('total_invoiced') ?></div>
                            <div class="timeline-summary-value"><?= format_money($billTotal) ?></div>
                        </div>
                        <div class="timeline-summary-item success">
                            <div class="timeline-summary-label"><?= __('total_paid') ?></div>
                            <div class="timeline-summary-value">+<?= format_money($billPaid) ?></div>
                        </div>
                        <div class="timeline-summary-item <?= $billOutstand>0?'danger':'' ?>">
                            <div class="timeline-summary-label"><?= __('outstanding_balance') ?></div>
                            <div class="timeline-summary-value"><?= format_money($billOutstand) ?></div>
                        </div>
                    </div>

                    <!-- Unpaid invoices -->
                    <h6 class="text-teal mt-3 mb-2"><i class="fa-solid fa-file-invoice me-1"></i><?= __('unpaid_invoices') ?></h6>
                    <?php
                        $unpaid = array_filter($tlInvoices, fn($iv) => in_array($iv['status'], ['issued','partial'], true) && (float)$iv['balance'] > 0);
                    ?>
                    <?php if (!$unpaid): ?>
                        <div class="empty-state py-3"><i class="fa-regular fa-circle-check" style="color:#10b981"></i><div><?= __('all_invoices_paid') ?></div></div>
                    <?php else: foreach ($unpaid as $iv):
                        $invBal = (float)$iv['balance'];
                    ?>
                        <div class="pkg-card">
                            <div class="pkg-card-head">
                                <div>
                                    <h6 class="pkg-card-title">
                                        <i class="fa-solid fa-file-invoice"></i>
                                        <code><?= e($iv['invoice_no']) ?></code>
                                    </h6>
                                    <div class="pkg-card-dates"><?= e($iv['issue_date']) ?></div>
                                </div>
                                <span class="badge bg-warning"><?= __('inv_'.$iv['status']) ?: e($iv['status']) ?></span>
                            </div>

                            <div class="pkg-progress">
                                <div class="pkg-progress-label">
                                    <span><i class="fa-solid fa-coins me-1"></i><?= __('total') ?></span>
                                    <span><strong><?= format_money($iv['total']) ?></strong></span>
                                </div>
                                <div class="pkg-progress-label">
                                    <span><?= __('paid') ?></span>
                                    <span style="color:#047857"><strong>+<?= format_money($iv['paid_amount']) ?></strong></span>
                                </div>
                                <div class="pkg-progress-label">
                                    <span><?= __('balance') ?></span>
                                    <span style="color:#b91c1c"><strong><?= format_money($invBal) ?></strong></span>
                                </div>
                            </div>

                            <?php if (can('payments.create')): ?>
                                <form method="post" action="?id=<?= $id ?>&action=pay_invoice" class="pkg-pay-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="invoice_id" value="<?= (int)$iv['id'] ?>">
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text"><i class="fa-solid fa-coins"></i></span>
                                        <input type="number" name="amount" step="0.01" min="0.01" max="<?= $invBal ?>"
                                               value="<?= $invBal ?>" required class="form-control"
                                               placeholder="<?= __('amount') ?>">
                                        <select name="method" class="form-select" style="max-width:130px">
                                            <option value="cash"><?= __('m_cash') ?></option>
                                            <option value="card"><?= __('m_card') ?></option>
                                            <option value="bank"><?= __('m_bank') ?></option>
                                            <option value="online"><?= __('m_online') ?></option>
                                        </select>
                                        <button class="btn btn-success" type="submit">
                                            <i class="fa-solid fa-plus me-1"></i><?= __('record_payment') ?>
                                        </button>
                                    </div>
                                    <small class="text-muted"><?= __('remaining') ?>: <strong><?= format_money($invBal) ?></strong></small>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; endif; ?>

                    <!-- Recent payments -->
                    <h6 class="text-teal mt-4 mb-2"><i class="fa-solid fa-money-bill-wave me-1"></i><?= __('recent_payments') ?></h6>
                    <?php if (!$tlPayments): ?>
                        <div class="empty-state py-3"><i class="fa-regular fa-money-bill-1"></i><div><?= __('no_data') ?></div></div>
                    <?php else: ?>
                        <div class="entity-list">
                            <?php foreach (array_slice($tlPayments, 0, 10) as $py):
                                $isRef = !empty($py['is_refund']);
                                echo render_entity_card([
                                    'avatar_icon' => $isRef ? 'fa-rotate-left' : 'fa-money-bill-wave',
                                    'avatar_class' => $isRef ? 'danger' : 'success',
                                    'title' => $isRef ? __('refund') : __('payment'),
                                    'title_right' => '<span style="color:'.($isRef?'#b91c1c':'#047857').'">'.($isRef?'−':'+').format_money($py['amount']).'</span>',
                                    'code' => $py['receipt_no'],
                                    'meta' => [format_date($py['paid_at'],'Y-m-d H:i')],
                                    'chips' => [
                                        ['label'=>__('m_'.$py['method']) ?: $py['method'],'icon'=>'fa-credit-card','class'=>'info'],
                                    ],
                                ]);
                            endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>
</div>
<?php include BP_PARTIALS . '/footer.php'; ?>
