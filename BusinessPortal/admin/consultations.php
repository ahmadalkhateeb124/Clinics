<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('consultations.view');

$PageTitle = __('consultations');
$action    = $_GET['action'] ?? 'list';
$id        = (int)($_GET['id'] ?? 0);

// ─── DELETE ──────────────────────────────────────────────────────────
if ($action === 'delete' && $id) {
    csrf_check();
    require_can('consultations.delete');
    db()->prepare("UPDATE consultations SET deleted_at=NOW() WHERE id=?")->execute([$id]);
    log_activity('deleted','consultations',"Soft-deleted consultation #$id",'consultation',$id);
    flash('success','Consultation deleted.');
    redirect(BP_URL . 'admin/consultations.php');
}

// ─── CREATE / EDIT POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['create','edit'], true)) {
    csrf_check();
    require_can($action === 'create' ? 'consultations.create' : 'consultations.edit');

    $patientId = (int)($_POST['patient_id'] ?? 0);
    $doctorId  = (int)($_POST['doctor_id'] ?? 0) ?: null;
    $svcId     = (int)($_POST['service_id'] ?? 0) ?: null;
    $apptId    = (int)($_POST['appointment_id'] ?? 0) ?: null;
    $mode      = $_POST['mode'] ?? 'in_clinic';
    $videoLink = trim($_POST['video_link'] ?? '');
    $date      = trim($_POST['consultation_date'] ?? '');
    $duration  = (int)($_POST['duration_minutes'] ?? 30);
    $complaint = trim($_POST['complaint'] ?? '');
    $exam      = trim($_POST['examination'] ?? '');
    $diag      = trim($_POST['diagnosis'] ?? '');
    $reco      = trim($_POST['recommendations'] ?? '');
    $rx        = trim($_POST['prescription'] ?? '');
    $followUp  = trim($_POST['follow_up_date'] ?? '');
    $sessions  = max(0, (int)($_POST['prescribed_sessions'] ?? 0));
    $fee       = (float)($_POST['fee'] ?? 0);
    $status    = $_POST['status'] ?? 'scheduled';

    // Walk-in: no existing patient — auto-create a minimal record
    $walkInName  = trim($_POST['walk_in_name'] ?? '');
    $walkInPhone = trim($_POST['walk_in_phone'] ?? '');
    $createPt    = !empty($_POST['create_patient_record']);

    $errors = [];
    if (!$patientId && $walkInName === '') $errors[] = __('err_pick_patient_or_walk_in');
    if (!$date) $errors[] = __('err_pick_date');
    if (!in_array($mode, ['in_clinic','video','phone'], true)) $mode = 'in_clinic';
    if (!in_array($status, ['scheduled','in_progress','completed','cancelled'], true)) $status = 'scheduled';
    if ($mode === 'video' && $videoLink && !filter_var($videoLink, FILTER_VALIDATE_URL)) {
        $errors[] = __('err_invalid_video_link');
    }
    if ($followUp === '') $followUp = null;

    if ($errors) {
        foreach ($errors as $e) flash('error', $e);
        set_old($_POST);
        redirect(BP_URL . 'admin/consultations.php?action=' . $action . ($id ? "&id=$id" : ''));
    }

    // Auto-create patient on the fly from walk-in info
    if (!$patientId && $walkInName !== '') {
        // Try to match by phone first
        if ($walkInPhone !== '') {
            $ex = db()->prepare("SELECT id FROM patients WHERE phone=? AND deleted_at IS NULL LIMIT 1");
            $ex->execute([$walkInPhone]);
            $patientId = (int)$ex->fetchColumn();
        }
        if (!$patientId) {
            if (!$createPt) {
                // User explicitly chose NOT to save the walk-in as a patient
                flash('error', __('err_walk_in_match_required'));
                set_old($_POST);
                redirect(BP_URL . 'admin/consultations.php?action=' . $action . ($id ? "&id=$id" : ''));
            }
            $parts = explode(' ', $walkInName, 2);
            $first = $parts[0]; $last = $parts[1] ?? '—';
            db()->prepare("INSERT INTO patients (code,first_name,last_name,phone,gender,created_by,created_at,updated_at)
                           VALUES (?,?,?,?, 'female', ?, NOW(), NOW())")
                ->execute([next_patient_code(), $first, $last, $walkInPhone ?: null, $_SESSION['user_id']]);
            $patientId = (int)db()->lastInsertId();
            log_activity('created','patients',"Quick-created from consultation walk-in: $walkInName",'patient',$patientId);
        }
    }

    if ($action === 'create') {
        // Note: fee = 0 is allowed (free consultation). User can clear the auto-filled price.
        db()->prepare("INSERT INTO consultations
            (patient_id,doctor_id,appointment_id,service_id,mode,video_link,consultation_date,
             duration_minutes,complaint,examination,diagnosis,recommendations,prescription,
             follow_up_date,prescribed_sessions,fee,status,created_by,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")
            ->execute([
                $patientId,$doctorId,$apptId,$svcId,$mode,$videoLink ?: null, str_replace('T',' ',$date),
                $duration,$complaint,$exam,$diag,$reco,$rx,
                $followUp,$sessions,$fee,$status,$_SESSION['user_id']
            ]);
        $cid = (int)db()->lastInsertId();
        log_activity('created','consultations',"Created consultation #$cid for patient #$patientId",'consultation',$cid);

        // Auto-create invoice for paid consultations
        if ($fee > 0) {
            db()->prepare("INSERT INTO invoices
                (invoice_no,patient_id,consultation_id,issue_date,status,currency,created_by,created_at,updated_at)
                VALUES (?,?,?,?, 'issued', ?, ?, NOW(), NOW())")
                ->execute([next_invoice_no(), $patientId, $cid, date('Y-m-d'), APP_CURRENCY, $_SESSION['user_id']]);
            $invId = (int)db()->lastInsertId();
            $svName = 'Consultation #'.$cid;
            if ($svcId) {
                $sv = db()->prepare("SELECT name_ar FROM services WHERE id=?");
                $sv->execute([$svcId]);
                if ($r = $sv->fetch()) $svName = $r['name_ar'];
            }
            db()->prepare("INSERT INTO invoice_items
                (invoice_id,service_id,description,quantity,unit_price,discount,total) VALUES (?,?,?,1,?,0,?)")
                ->execute([$invId, $svcId, $svName, $fee, $fee]);
            db()->prepare("UPDATE consultations SET invoice_id = ? WHERE id = ?")->execute([$invId, $cid]);
            recompute_invoice($invId);
            log_activity('auto_invoiced','invoices',"Auto-issued invoice for consultation #$cid",'invoice',$invId);
        }
    } else {
        db()->prepare("UPDATE consultations SET
            patient_id=?,doctor_id=?,appointment_id=?,service_id=?,mode=?,video_link=?,
            consultation_date=?,duration_minutes=?,complaint=?,examination=?,diagnosis=?,
            recommendations=?,prescription=?,follow_up_date=?,prescribed_sessions=?,fee=?,
            status=?,updated_by=?,updated_at=NOW()
            WHERE id=?")
            ->execute([
                $patientId,$doctorId,$apptId,$svcId,$mode,$videoLink ?: null,
                str_replace('T',' ',$date),$duration,$complaint,$exam,$diag,$reco,$rx,
                $followUp,$sessions,$fee,$status,$_SESSION['user_id'],$id
            ]);
        $cid = $id;
        log_activity('updated','consultations',"Updated consultation #$cid",'consultation',$cid);
    }

    // Optional file upload
    if (!empty($_FILES['attachment']['name'])) {
        $up = upload_file($_FILES['attachment'], 'patients/'.$patientId, ['jpg','jpeg','png','webp','pdf']);
        if ($up) {
            db()->prepare("INSERT INTO patient_files
                (patient_id,consultation_id,file_name,original_name,mime_type,size_bytes,category,uploaded_by,created_at)
                VALUES (?,?,?,?,?,?,'consultation',?,NOW())")
                ->execute([$patientId,$cid,$up['relative_path'],$up['original_name'],$up['mime_type'],$up['size'],$_SESSION['user_id']]);
        }
    }

    flash('success','Consultation saved.');
    redirect(BP_URL . 'admin/consultation-view.php?id=' . $cid);
}

// ─── CREATE / EDIT FORM ──────────────────────────────────────────────
if (in_array($action, ['create','edit'], true)) {
    require_can($action === 'create' ? 'consultations.create' : 'consultations.edit');

    $cs = [
        'patient_id'=>0,'doctor_id'=>0,'appointment_id'=>0,'service_id'=>0,
        'mode'=>'in_clinic','video_link'=>'',
        'consultation_date'=>date('Y-m-d\TH:i'),'duration_minutes'=>30,
        'complaint'=>'','examination'=>'','diagnosis'=>'','recommendations'=>'','prescription'=>'',
        'follow_up_date'=>'','prescribed_sessions'=>0,'fee'=>0,'status'=>'scheduled'
    ];
    if ($action === 'edit' && $id) {
        $s = db()->prepare("SELECT * FROM consultations WHERE id=? AND deleted_at IS NULL");
        $s->execute([$id]); $cs = $s->fetch();
        if (!$cs) { flash('error','Not found.'); redirect(BP_URL . 'admin/consultations.php'); }
        $cs['consultation_date'] = date('Y-m-d\TH:i', strtotime($cs['consultation_date']));
    }
    if ($action === 'create' && !empty($_GET['patient_id'])) {
        $cs['patient_id'] = (int)$_GET['patient_id'];
    }
    if ($action === 'create' && !empty($_GET['appointment_id'])) {
        $cs['appointment_id'] = (int)$_GET['appointment_id'];
        $a = db()->prepare("SELECT patient_id, therapist_id, start_at, duration_minutes FROM appointments WHERE id=?");
        $a->execute([$cs['appointment_id']]);
        if ($r = $a->fetch()) {
            $cs['patient_id']        = (int)$r['patient_id'];
            $cs['doctor_id']         = (int)$r['therapist_id'];
            $cs['consultation_date'] = date('Y-m-d\TH:i', strtotime($r['start_at']));
            $cs['duration_minutes']  = (int)$r['duration_minutes'];
        }
    }

    $patients = db()->query("SELECT id,code,first_name,last_name,phone FROM patients WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 500")->fetchAll();
    $doctors  = db()->query("
        SELECT u.id,u.name FROM users u
        JOIN user_roles ur ON ur.user_id = u.id
        JOIN roles r ON r.id = ur.role_id
        WHERE u.deleted_at IS NULL AND u.status='active' AND r.slug IN ('doctor','therapist')
        GROUP BY u.id ORDER BY u.name
    ")->fetchAll();
    $consultationServices = db()->query("SELECT id,name_ar,duration_minutes,price FROM services WHERE deleted_at IS NULL AND is_consultation=1 AND is_active=1 ORDER BY name_ar")->fetchAll();

    include BP_PARTIALS . '/header.php';
    ?>
    <div class="page-wrap">
        <h4 class="mb-3"><?= $action==='create'?__('create'):__('edit') ?> — <?= __('consultations') ?></h4>

        <form method="post" enctype="multipart/form-data" class="card"><div class="card-body">
            <?= csrf_field() ?>

            <!-- ── Section 1: Patient + Doctor ─────────────────────── -->
            <div class="form-section">
                <div class="form-section-head"><i class="fa-solid fa-user-doctor"></i><?= __('patient_and_doctor') ?></div>
                <div class="row g-3">
                    <div class="col-md-7">
                        <label class="form-label"><?= __('patients') ?></label>
                        <div class="patient-picker" id="cnsPtMode">
                            <!-- Segmented tabs at the top of the card -->
                            <div class="patient-picker-tabs">
                                <input type="radio" class="btn-check" name="_pt_mode" id="cnsPtModeExisting" autocomplete="off" <?= !empty($cs['patient_id'])?'checked':'' ?>>
                                <label for="cnsPtModeExisting"><i class="fa-solid fa-user"></i><?= __('existing_patient') ?></label>
                                <input type="radio" class="btn-check" name="_pt_mode" id="cnsPtModeWalkIn" autocomplete="off" <?= empty($cs['patient_id'])?'checked':'' ?>>
                                <label for="cnsPtModeWalkIn"><i class="fa-solid fa-user-plus"></i><?= __('walk_in') ?></label>
                            </div>
                            <!-- Body that swaps based on the active tab -->
                            <div class="patient-picker-body">
                                <div id="cnsPtExisting" class="patient-picker-pane">
                                    <select name="patient_id" class="form-select form-select-flush">
                                        <option value="">— <?= __('pick_patient') ?> —</option>
                                        <?php foreach ($patients as $p): ?>
                                            <option value="<?= (int)$p['id'] ?>" <?= (int)$cs['patient_id']===(int)$p['id']?'selected':'' ?>>
                                                [<?= e($p['code']) ?>] <?= e($p['first_name'].' '.$p['last_name']) ?> · <?= e($p['phone']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div id="cnsPtWalkIn" class="patient-picker-pane d-none">
                                    <div class="patient-picker-walkin">
                                        <span class="patient-picker-walkin-icon"><i class="fa-solid fa-user-plus"></i></span>
                                        <input type="text" name="walk_in_name" class="form-control form-control-flush" placeholder="<?= __('walk_in_name_placeholder') ?>">
                                        <span class="patient-picker-walkin-sep"></span>
                                        <input type="tel" name="walk_in_phone" class="form-control form-control-flush" placeholder="<?= __('phone') ?>" dir="ltr">
                                    </div>
                                    <label class="patient-picker-create">
                                        <input type="checkbox" name="create_patient_record" value="1" checked>
                                        <i class="fa-solid fa-folder-plus"></i>
                                        <span><?= __('create_patient_record') ?></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label"><?= __('doctor_therapist') ?></label>
                        <select name="doctor_id" class="form-select">
                            <option value="">—</option>
                            <?php foreach ($doctors as $d): ?>
                                <option value="<?= (int)$d['id'] ?>" <?= (int)$cs['doctor_id']===(int)$d['id']?'selected':'' ?>><?= e($d['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- ── Section 2: Schedule ─────────────────────────────── -->
            <div class="form-section">
                <div class="form-section-head"><i class="fa-regular fa-calendar"></i><?= __('schedule_section') ?></div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label"><?= __('date') ?> *</label>
                        <input type="datetime-local" name="consultation_date" class="form-control" required value="<?= e($cs['consultation_date']) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><?= __('duration_min') ?></label>
                        <input type="number" name="duration_minutes" min="5" max="240" class="form-control" value="<?= (int)$cs['duration_minutes'] ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= __('mode') ?></label>
                        <select name="mode" class="form-select" onchange="document.getElementById('vlink').classList.toggle('d-none', this.value!=='video')">
                            <option value="in_clinic" <?= $cs['mode']==='in_clinic'?'selected':'' ?>><?= __('mode_in_clinic') ?></option>
                            <option value="video"     <?= $cs['mode']==='video'?'selected':'' ?>><?= __('mode_video') ?></option>
                            <option value="phone"     <?= $cs['mode']==='phone'?'selected':'' ?>><?= __('mode_phone') ?></option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= __('status') ?></label>
                        <select name="status" class="form-select">
                            <?php foreach (['scheduled','in_progress','completed','cancelled'] as $s): ?>
                                <option value="<?= $s ?>" <?= $cs['status']===$s?'selected':'' ?>><?= __('st_'.$s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-12 <?= $cs['mode']!=='video'?'d-none':'' ?>" id="vlink">
                        <label class="form-label"><i class="fa-solid fa-video me-1 text-info"></i><?= __('video_link') ?></label>
                        <input name="video_link" type="url" class="form-control" placeholder="https://meet.example.com/…" value="<?= e($cs['video_link']??'') ?>">
                    </div>
                </div>
            </div>

            <!-- ── Section 3: Service + Fee + Follow-up ────────────── -->
            <div class="form-section">
                <div class="form-section-head"><i class="fa-solid fa-coins"></i><?= __('service_and_fee') ?></div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label"><?= __('consultation_service') ?></label>
                        <select name="service_id" class="form-select" onchange="
                            const opt=this.options[this.selectedIndex];
                            if(opt && opt.dataset.price && document.querySelector('[name=fee]').value==0){
                                document.querySelector('[name=fee]').value = opt.dataset.price;
                            }
                        ">
                            <option value="">—</option>
                            <?php foreach ($consultationServices as $sv): ?>
                                <option value="<?= (int)$sv['id'] ?>"
                                        data-price="<?= e($sv['price']) ?>"
                                        <?= (int)$cs['service_id']===(int)$sv['id']?'selected':'' ?>>
                                    <?= e($sv['name_ar']) ?> · <?= format_money($sv['price']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= __('fee') ?> (<?= APP_CURRENCY ?>)</label>
                        <input type="number" step="0.01" min="0" name="fee" class="form-control" value="<?= e($cs['fee']) ?>">
                        <small class="text-muted"><?= __('free_consultation_hint') ?></small>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><?= __('prescribed_sessions') ?></label>
                        <input type="number" min="0" name="prescribed_sessions" class="form-control" value="<?= (int)$cs['prescribed_sessions'] ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?= __('follow_up_date') ?></label>
                        <input type="date" name="follow_up_date" class="form-control" value="<?= e($cs['follow_up_date']??'') ?>">
                    </div>
                </div>
            </div>

            <!-- ── Section 4: Clinical notes ───────────────────────── -->
            <div class="form-section">
                <div class="form-section-head"><i class="fa-solid fa-notes-medical"></i><?= __('clinical_notes') ?></div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label"><i class="fa-solid fa-comment-dots me-1 text-muted"></i><?= __('chief_complaint') ?></label>
                        <textarea name="complaint" rows="3" class="form-control" placeholder="<?= __('chief_complaint') ?>…"><?= e($cs['complaint']??'') ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><i class="fa-solid fa-stethoscope me-1 text-muted"></i><?= __('examination') ?></label>
                        <textarea name="examination" rows="3" class="form-control" placeholder="<?= __('examination') ?>…"><?= e($cs['examination']??'') ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><i class="fa-solid fa-file-medical me-1 text-muted"></i><?= __('diagnosis') ?></label>
                        <textarea name="diagnosis" rows="3" class="form-control" placeholder="<?= __('diagnosis') ?>…"><?= e($cs['diagnosis']??'') ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><i class="fa-solid fa-lightbulb me-1 text-muted"></i><?= __('recommendations') ?></label>
                        <textarea name="recommendations" rows="3" class="form-control" placeholder="<?= __('recommendations') ?>…"><?= e($cs['recommendations']??'') ?></textarea>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label"><i class="fa-solid fa-prescription me-1 text-muted"></i><?= __('prescription') ?></label>
                        <textarea name="prescription" rows="3" class="form-control" placeholder="<?= __('prescription') ?>…"><?= e($cs['prescription']??'') ?></textarea>
                    </div>
                </div>
            </div>

            <!-- ── Section 5: Attachment ───────────────────────────── -->
            <div class="form-section">
                <div class="form-section-head"><i class="fa-solid fa-paperclip"></i><?= __('attachments') ?></div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label"><?= __('attachment_types') ?></label>
                        <input type="file" name="attachment" class="form-control">
                    </div>
                </div>
            </div>
            <input type="hidden" name="appointment_id" value="<?= (int)$cs['appointment_id'] ?>">

            <div class="mt-3 d-flex gap-2">
                <button class="btn btn-teal"><i class="fa-solid fa-check me-1"></i><?= __('save') ?></button>
                <a class="btn btn-light" href="<?= BP_URL ?>admin/consultations.php"><?= __('cancel') ?></a>
            </div>
        </div></form>

        <script>
        (function(){
            const exist  = document.getElementById('cnsPtExisting');
            const walkIn = document.getElementById('cnsPtWalkIn');
            const rExist = document.getElementById('cnsPtModeExisting');
            const rWalk  = document.getElementById('cnsPtModeWalkIn');
            if (!exist || !walkIn) return;
            const apply = () => {
                const isWalk = rWalk.checked;
                exist.classList.toggle('d-none', isWalk);
                walkIn.classList.toggle('d-none', !isWalk);
                // Clear the inactive side so server-side knows which one to use
                if (isWalk) { exist.querySelector('select').value = ''; }
                else { walkIn.querySelectorAll('input').forEach(i => i.value = ''); }
            };
            rExist.addEventListener('change', apply);
            rWalk.addEventListener('change', apply);
            apply();
        })();
        </script>
    </div>
    <?php include BP_PARTIALS . '/footer.php'; clear_old(); exit;
}

// ─── LIST ────────────────────────────────────────────────────────────
$q       = trim($_GET['q'] ?? '');
$status  = trim($_GET['status'] ?? '');
$mode    = trim($_GET['mode'] ?? '');
$from    = trim($_GET['from'] ?? '');
$to      = trim($_GET['to'] ?? '');
$page    = (int)($_GET['page'] ?? 1);
[$perPage, $offset] = paginate_query($page, (int)setting('per_page','25'));

$where = "c.deleted_at IS NULL"; $params = [];
if ($q !== '') {
    $where .= " AND (p.code LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? OR p.phone LIKE ?)";
    $like = "%$q%"; array_push($params,$like,$like,$like,$like);
}
if ($status !== '') { $where .= " AND c.status = ?"; $params[] = $status; }
if ($mode   !== '') { $where .= " AND c.mode = ?";   $params[] = $mode; }
if ($from   !== '') { $where .= " AND c.consultation_date >= ?"; $params[] = $from . ' 00:00:00'; }
if ($to     !== '') { $where .= " AND c.consultation_date <= ?"; $params[] = $to   . ' 23:59:59'; }

$tot = db()->prepare("SELECT COUNT(*) FROM consultations c JOIN patients p ON p.id = c.patient_id WHERE $where");
$tot->execute($params);
$total = (int)$tot->fetchColumn();

$sql = "SELECT c.*, p.code AS patient_code, p.first_name, p.last_name, p.phone, u.name AS doctor_name
        FROM consultations c
        JOIN patients p ON p.id = c.patient_id
        LEFT JOIN users u ON u.id = c.doctor_id
        WHERE $where ORDER BY c.consultation_date DESC LIMIT $perPage OFFSET $offset";
$stmt = db()->prepare($sql); $stmt->execute($params);
$rows = $stmt->fetchAll();

// KPI stats
$today = date('Y-m-d');
$kpi = db()->query("
    SELECT
      SUM(DATE(consultation_date) = '$today' AND status != 'cancelled') AS today_count,
      SUM(status = 'scheduled')   AS scheduled,
      SUM(status = 'completed')   AS completed,
      COALESCE(SUM(CASE WHEN status='completed' THEN fee ELSE 0 END),0) AS revenue
    FROM consultations WHERE deleted_at IS NULL
")->fetch() ?: ['today_count'=>0,'scheduled'=>0,'completed'=>0,'revenue'=>0];
$activeFilters = ($q !== '') + ($status !== '') + ($mode !== '') + ($from !== '') + ($to !== '');

include BP_PARTIALS . '/header.php';
?>
<div class="page-wrap">
    <!-- Header -->
    <div class="page-header">
        <h4 class="m-0">
            <i class="fa-solid fa-user-doctor text-teal me-2"></i><?= __('consultations') ?>
            <span class="page-count">(<?= $total ?>)</span>
        </h4>
        <div class="page-header-actions">
            <?php if (can('consultations.create')): ?>
                <a href="?action=create" class="btn btn-teal btn-sm" data-modal>
                    <i class="fa-solid fa-plus me-1"></i><?= __('new_consultation') ?>
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
        <a class="appt-kpi" href="?status=completed">
            <div class="appt-kpi-icon" style="background:#10b981"><i class="fa-solid fa-check-double"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('st_completed') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['completed'] ?></div>
            </div>
        </a>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#0d9488"><i class="fa-solid fa-coins"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('revenue') ?></div>
                <div class="appt-kpi-value" style="font-size:1.05rem"><?= format_money($kpi['revenue']) ?></div>
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
                <?php foreach (['scheduled','in_progress','completed','cancelled'] as $st): ?>
                    <option value="<?= $st ?>" <?= $status===$st?'selected':'' ?>><?= __('st_'.$st) ?: str_replace('_',' ',$st) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="appt-filter-group">
            <select name="mode" class="form-select form-select-sm">
                <option value=""><?= __('all_modes') ?></option>
                <?php foreach (['in_clinic','video','phone'] as $m): ?>
                    <option value="<?= $m ?>" <?= $mode===$m?'selected':'' ?>><?= __('mode_'.$m) ?: str_replace('_',' ',$m) ?></option>
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
            <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/consultations.php" title="<?= __('clear_filters') ?>"><i class="fa-solid fa-xmark"></i></a>
        <?php endif; ?>
    </form>

    <div class="table-card">
        <div class="table-responsive d-none d-md-block">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th>#</th><th><?= __('date') ?></th><th><?= __('patients') ?></th><th><?= __('doctor') ?></th>
                <th><?= __('mode') ?></th><th><?= __('fee') ?></th><th><?= __('sessions_rx') ?></th><th><?= __('status') ?></th><th></th>
            </tr></thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4"><?= __('no_data') ?></td></tr>
                <?php else: foreach ($rows as $c):
                    $color = ['scheduled'=>'info','in_progress'=>'warning','completed'=>'success','cancelled'=>'danger'][$c['status']] ?? 'secondary';
                ?>
                    <tr>
                        <td><?= (int)$c['id'] ?></td>
                        <td class="small"><?= format_date($c['consultation_date'],'Y-m-d H:i') ?></td>
                        <td>
                            <a href="<?= BP_URL ?>admin/patient-view.php?id=<?= (int)$c['patient_id'] ?>" class="text-decoration-none">
                                <?= e($c['first_name'].' '.$c['last_name']) ?>
                            </a>
                            <code class="small ms-1"><?= e($c['patient_code']) ?></code>
                        </td>
                        <td class="small"><?= e($c['doctor_name'] ?? '—') ?></td>
                        <td><span class="badge bg-light text-dark"><?= __('mode_'.$c['mode']) ?: str_replace('_',' ',e($c['mode'])) ?></span></td>
                        <td><?= (float)$c['fee'] > 0 ? format_money($c['fee']) : '<span class="badge bg-success">'.__('free').'</span>' ?></td>
                        <td><?= (int)$c['prescribed_sessions'] ?></td>
                        <td><span class="badge bg-<?= $color ?>"><?= __('st_'.$c['status']) ?: str_replace('_',' ',e($c['status'])) ?></span></td>
                        <td class="text-end">
                            <?= render_actions([
                                ['icon'=>'fa-eye','label'=>'view','href'=>BP_URL.'admin/consultation-view.php?id='.(int)$c['id']],
                                (can('consultations.edit') ? ['icon'=>'fa-pen','label'=>'edit','href'=>'?action=edit&id='.(int)$c['id'],'modal'=>true] : null),
                                (can('consultations.delete') ? ['icon'=>'fa-trash','label'=>'delete','href'=>'?action=delete&id='.(int)$c['id'].'&_csrf='.csrf_token(),'danger'=>true,'divider_before'=>true,'confirm'=>'confirm_delete'] : null),
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
                <div class="empty-state"><i class="fa-regular fa-clipboard"></i><div><?= __('no_data') ?></div></div>
            <?php else: foreach ($rows as $c):
                $statusChip = ['scheduled'=>'info','in_progress'=>'warn','completed'=>'success','cancelled'=>'danger'][$c['status']] ?? '';
                $chips = [
                    ['label'=>__('st_'.$c['status']) ?: str_replace('_',' ',$c['status']),'icon'=>'fa-circle-dot','class'=>$statusChip],
                    ['label'=>__('mode_'.$c['mode']) ?: str_replace('_',' ',$c['mode']),'icon'=>'fa-stethoscope'],
                    ((float)$c['fee'] > 0)
                        ? ['label'=>format_money($c['fee']),'icon'=>'fa-coins','class'=>'teal']
                        : ['label'=>__('free'),'icon'=>'fa-gift','class'=>'success'],
                ];
                if ((int)$c['prescribed_sessions'] > 0) {
                    $chips[] = ['label'=>(int)$c['prescribed_sessions'].' '.__('sessions'),'icon'=>'fa-list-ol','class'=>'info'];
                }
                echo render_entity_card([
                    'avatar_icon' => 'fa-user-doctor',
                    'avatar_class' => 'indigo',
                    'title' => $c['first_name'].' '.$c['last_name'],
                    'title_href' => BP_URL.'admin/consultation-view.php?id='.(int)$c['id'],
                    'code' => $c['patient_code'],
                    'meta' => [format_date($c['consultation_date'],'Y-m-d H:i'), $c['doctor_name'] ?? '—'],
                    'chips' => $chips,
                    'actions' => [
                        ['icon'=>'fa-eye','label'=>'view','href'=>BP_URL.'admin/consultation-view.php?id='.(int)$c['id']],
                        (can('consultations.edit') ? ['icon'=>'fa-pen','label'=>'edit','href'=>'?action=edit&id='.(int)$c['id'],'modal'=>true] : null),
                        (can('consultations.delete') ? ['icon'=>'fa-trash','label'=>'delete','href'=>'?action=delete&id='.(int)$c['id'].'&_csrf='.csrf_token(),'danger'=>true,'divider_before'=>true,'confirm'=>'confirm_delete'] : null),
                    ],
                ]);
            endforeach; endif; ?>
        </div>

        <div class="p-2"><?= render_pagination($total,$page,$perPage,BP_URL.'admin/consultations.php?'.http_build_query(['q'=>$q,'status'=>$status,'mode'=>$mode,'from'=>$from,'to'=>$to])) ?></div>
    </div>
</div>
<?php include BP_PARTIALS . '/footer.php'; ?>
