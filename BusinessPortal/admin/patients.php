<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('patients.view');

$PageTitle = __('patients');
$action    = $_GET['action'] ?? 'list';
$id        = (int)($_GET['id'] ?? 0);

if ($action === 'delete' && $id) {
    require_can('patients.delete');
    csrf_check();
    db()->prepare("UPDATE patients SET deleted_at=NOW() WHERE id=?")->execute([$id]);
    log_activity('deleted','patients',"Soft-deleted patient #$id",'patient',$id);
    flash('success','Patient deleted.');
    redirect(BP_URL . 'admin/patients.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action,['create','edit'],true)) {
    csrf_check();
    require_can($action==='create'?'patients.create':'patients.edit');

    $first  = trim($_POST['first_name'] ?? '');
    $last   = trim($_POST['last_name']  ?? '');
    $gender = $_POST['gender'] ?? 'female';
    $dob    = trim($_POST['dob'] ?? '');
    $phone  = trim($_POST['phone'] ?? '');
    $email  = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city   = trim($_POST['city']   ?? '');
    $country= trim($_POST['country']?? 'Jordan');
    $natId  = trim($_POST['national_id'] ?? '');
    $emName = trim($_POST['emergency_name'] ?? '');
    $emPhone= trim($_POST['emergency_phone'] ?? '');
    $hist   = trim($_POST['medical_history'] ?? '');
    $allerg = trim($_POST['allergies'] ?? '');
    $chr    = trim($_POST['chronic_conditions'] ?? '');
    $meds   = trim($_POST['current_medications'] ?? '');
    $notes  = trim($_POST['notes'] ?? '');

    if ($first === '' || $last === '' || $phone === '') {
        flash('error','Name and phone are required.'); back();
    }
    if (!in_array($gender,['male','female','other'],true)) $gender = 'female';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { flash('error','Invalid email.'); back(); }
    if ($dob === '') $dob = null;

    if ($action === 'create') {
        $code = next_patient_code();
        db()->prepare("INSERT INTO patients
            (code,first_name,last_name,gender,dob,phone,email,address,city,country,national_id,
             emergency_name,emergency_phone,medical_history,allergies,chronic_conditions,
             current_medications,notes,created_by,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")
            ->execute([$code,$first,$last,$gender,$dob,$phone,$email,$address,$city,$country,$natId,
                       $emName,$emPhone,$hist,$allerg,$chr,$meds,$notes,$_SESSION['user_id']]);
        $pid = (int)db()->lastInsertId();
        log_activity('created','patients',"Created patient $code: $first $last",'patient',$pid);
    } else {
        db()->prepare("UPDATE patients SET
            first_name=?,last_name=?,gender=?,dob=?,phone=?,email=?,address=?,city=?,country=?,national_id=?,
            emergency_name=?,emergency_phone=?,medical_history=?,allergies=?,chronic_conditions=?,
            current_medications=?,notes=?,updated_by=?,updated_at=NOW() WHERE id=?")
            ->execute([$first,$last,$gender,$dob,$phone,$email,$address,$city,$country,$natId,
                       $emName,$emPhone,$hist,$allerg,$chr,$meds,$notes,$_SESSION['user_id'],$id]);
        $pid = $id;
        log_activity('updated','patients',"Updated patient #$pid",'patient',$pid);
    }

    // Avatar upload
    if (!empty($_FILES['avatar']['name'])) {
        $af = $_FILES['avatar'];
        if ($af['error'] !== UPLOAD_ERR_OK) {
            $msg = in_array($af['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? __('file_too_large') : __('upload_server_error');
            flash('error', __('avatar') . ': ' . $msg);
        } else {
            $up = upload_file($af, 'patients', ['jpg','jpeg','png','webp'], 4*1024*1024);
            if ($up) {
                db()->prepare("UPDATE patients SET avatar=? WHERE id=?")->execute([$up['relative_path'],$pid]);
            } else {
                flash('error', __('avatar') . ': ' . __('file_type_not_allowed'));
            }
        }
    }
    flash('success', __('saved'));
    redirect(BP_URL . 'admin/patient-view.php?id=' . $pid);
}

if (in_array($action,['create','edit'],true)) {
    require_can($action==='create'?'patients.create':'patients.edit');
    $p = ['first_name'=>'','last_name'=>'','gender'=>'female','dob'=>'','phone'=>'','email'=>'',
          'address'=>'','city'=>'','country'=>'Jordan','national_id'=>'','emergency_name'=>'','emergency_phone'=>'',
          'medical_history'=>'','allergies'=>'','chronic_conditions'=>'','current_medications'=>'','notes'=>'','code'=>''];
    if ($action === 'edit' && $id) {
        $s = db()->prepare("SELECT * FROM patients WHERE id=? AND deleted_at IS NULL");
        $s->execute([$id]); $p = $s->fetch() ?: null;
        if (!$p) { flash('error','Not found'); redirect(BP_URL.'admin/patients.php'); }
    }
    include BP_PARTIALS . '/header.php';
    ?>
    <div class="page-wrap">
        <h4 class="mb-3">
            <?= $action==='create'?__('create'):__('edit') ?> — <?= __('patients') ?>
            <?php if ($p['code']): ?><small class="text-muted">[<?= e($p['code']) ?>]</small><?php endif; ?>
        </h4>
        <form method="post" enctype="multipart/form-data" class="card"><div class="card-body">
            <?= csrf_field() ?>

            <h6 class="text-teal"><?= __('basic_info') ?></h6>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label"><?= __('first_name') ?> *</label>
                    <input name="first_name" required class="form-control" value="<?= e($p['first_name']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label"><?= __('last_name') ?> *</label>
                    <input name="last_name" required class="form-control" value="<?= e($p['last_name']) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label"><?= __('gender') ?></label>
                    <select name="gender" class="form-select">
                        <?php foreach (['female'=>'female','male'=>'male','other'=>'other'] as $g => $tk): ?>
                            <option value="<?= $g ?>" <?= ($p['gender']??'')===$g?'selected':'' ?>><?= __('g_'.$tk) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label"><?= __('dob') ?></label>
                    <input name="dob" type="date" class="form-control" value="<?= e($p['dob']??'') ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label"><?= __('phone') ?> *</label>
                    <input name="phone" required class="form-control" value="<?= e($p['phone']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('email') ?></label>
                    <input name="email" type="email" class="form-control" value="<?= e($p['email']??'') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('national_id') ?></label>
                    <input name="national_id" class="form-control" value="<?= e($p['national_id']??'') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('avatar') ?></label>
                    <div class="d-flex align-items-center gap-2">
                        <?php
                            $hasAvatar = !empty($p['avatar']);
                            $initials  = mb_strtoupper(mb_substr($p['first_name']??'',0,1).mb_substr($p['last_name']??'',0,1)) ?: '?';
                        ?>
                        <span class="entity-avatar" id="avatarPreview" style="flex-shrink:0;width:46px;height:46px">
                            <?php if ($hasAvatar): ?>
                                <img src="<?= UPLOADS_URL . e($p['avatar']) ?>" alt="">
                            <?php else: ?>
                                <span><?= e($initials) ?></span>
                            <?php endif; ?>
                        </span>
                        <input name="avatar" type="file" accept="image/jpeg,image/png,image/webp" class="form-control form-control-sm"
                               onchange="(function(i,p){const f=i.files[0];if(!f)return;const r=new FileReader();r.onload=e=>{p.innerHTML='<img src=\''+e.target.result+'\' alt=\'\'>';};r.readAsDataURL(f);})(this,document.getElementById('avatarPreview'))">
                    </div>
                    <small class="text-muted"><?= __('avatar_hint') ?></small>
                </div>

                <div class="col-md-6">
                    <label class="form-label"><?= __('address') ?></label>
                    <input name="address" class="form-control" value="<?= e($p['address']??'') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('city') ?></label>
                    <input name="city" class="form-control" value="<?= e($p['city']??'') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('country') ?></label>
                    <input name="country" class="form-control" value="<?= e($p['country']??'Jordan') ?>">
                </div>
            </div>

            <h6 class="text-teal mt-4"><?= __('emergency_contact') ?></h6>
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label"><?= __('name') ?></label>
                    <input name="emergency_name" class="form-control" value="<?= e($p['emergency_name']??'') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?= __('phone') ?></label>
                    <input name="emergency_phone" class="form-control" value="<?= e($p['emergency_phone']??'') ?>">
                </div>
            </div>

            <h6 class="text-teal mt-4"><?= __('medical_info') ?></h6>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label"><?= __('medical_history') ?></label>
                    <textarea name="medical_history" class="form-control" rows="3"><?= e($p['medical_history']??'') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?= __('allergies') ?></label>
                    <textarea name="allergies" class="form-control" rows="3"><?= e($p['allergies']??'') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?= __('chronic_conditions') ?></label>
                    <textarea name="chronic_conditions" class="form-control" rows="2"><?= e($p['chronic_conditions']??'') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?= __('medications') ?></label>
                    <textarea name="current_medications" class="form-control" rows="2"><?= e($p['current_medications']??'') ?></textarea>
                </div>
                <div class="col-md-12">
                    <label class="form-label"><?= __('notes') ?></label>
                    <textarea name="notes" class="form-control" rows="2"><?= e($p['notes']??'') ?></textarea>
                </div>
            </div>

            <div class="mt-3 d-flex gap-2">
                <button class="btn btn-teal"><?= __('save') ?></button>
                <a class="btn btn-light" href="<?= BP_URL ?>admin/patients.php"><?= __('cancel') ?></a>
            </div>
        </div></form>
    </div>
    <?php include BP_PARTIALS . '/footer.php'; exit;
}

$q = trim($_GET['q'] ?? '');
$page = (int)($_GET['page'] ?? 1);
[$perPage,$offset] = paginate_query($page,(int)setting('per_page','25'));

$where = "p.deleted_at IS NULL";
$params = [];
if ($q !== '') {
    $where .= " AND (p.code LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? OR p.phone LIKE ? OR p.email LIKE ?)";
    $like = "%$q%";
    array_push($params,$like,$like,$like,$like,$like);
}

$tot = db()->prepare("SELECT COUNT(*) FROM patients p WHERE $where");
$tot->execute($params);
$total = (int)$tot->fetchColumn();

$sql = "SELECT * FROM patients p WHERE $where ORDER BY p.id DESC LIMIT $perPage OFFSET $offset";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Quick stats for the header strip
$kpi = db()->query("
    SELECT
      COUNT(*)                                              AS total,
      SUM(outstanding_balance > 0)                          AS owing_count,
      COALESCE(SUM(outstanding_balance),0)                  AS owing_sum,
      SUM(created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS new_30d
    FROM patients WHERE deleted_at IS NULL
")->fetch() ?: ['total'=>0,'owing_count'=>0,'owing_sum'=>0,'new_30d'=>0];

include BP_PARTIALS . '/header.php';
?>
<div class="page-wrap">
    <!-- Header -->
    <div class="page-header">
        <h4 class="m-0">
            <i class="fa-solid fa-user-injured text-teal me-2"></i><?= __('patients') ?>
            <span class="page-count">(<?= $total ?>)</span>
        </h4>
        <div class="page-header-actions">
            <?php if (can('patients.create')): ?>
                <a href="?action=create" class="btn btn-teal btn-sm" data-modal>
                    <i class="fa-solid fa-plus me-1"></i><?= __('new_patient') ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- KPI strip -->
    <div class="appt-kpis">
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#0d9488"><i class="fa-solid fa-users"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('total_patients') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['total'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#10b981"><i class="fa-solid fa-user-plus"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('new_30_days') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['new_30d'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#ef4444"><i class="fa-solid fa-flag"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('with_outstanding') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['owing_count'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#b45309"><i class="fa-solid fa-money-bill-trend-up"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('total_outstanding') ?></div>
                <div class="appt-kpi-value" style="font-size:1.05rem"><?= format_money($kpi['owing_sum']) ?></div>
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
        <button class="btn btn-teal btn-sm"><i class="fa-solid fa-filter me-1"></i><?= __('apply') ?></button>
        <?php if ($q !== ''): ?>
            <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/patients.php" title="<?= __('clear_filters') ?>">
                <i class="fa-solid fa-xmark"></i>
            </a>
        <?php endif; ?>
    </form>

    <div class="table-card">

        <!-- Desktop table -->
        <div class="table-responsive d-none d-md-block">
            <table class="table mb-0 align-middle">
                <thead><tr>
                    <th><?= __('code') ?></th><th><?= __('name') ?></th><th><?= __('phone') ?></th>
                    <th><?= __('email') ?></th><th><?= __('gender') ?></th><th><?= __('outstanding') ?></th><th></th>
                </tr></thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="7"><i class="fa-regular fa-folder-open fa-2x d-block mb-2 text-muted"></i><?= __('no_data') ?></td></tr>
                <?php else: foreach ($rows as $p): ?>
                    <tr>
                        <td><code><?= e($p['code']) ?></code></td>
                        <td>
                            <a href="<?= BP_URL ?>admin/patient-view.php?id=<?= (int)$p['id'] ?>" class="text-decoration-none fw-semibold text-dark">
                                <?= e($p['first_name'].' '.$p['last_name']) ?>
                            </a>
                        </td>
                        <td><?= e($p['phone']) ?></td>
                        <td class="text-muted small"><?= e($p['email']??'—') ?></td>
                        <td><span class="badge bg-light"><?= __('g_'.$p['gender']) ?></span></td>
                        <td>
                            <?php if ((float)$p['outstanding_balance'] > 0): ?>
                                <span class="badge bg-danger" title="<?= __('outstanding_balance') ?>">
                                    <i class="fa-solid fa-flag me-1"></i><?= format_money($p['outstanding_balance']) ?>
                                </span>
                            <?php else: ?>
                                <span class="status-dot success"><?= __('clear_status') ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?= render_actions([
                                ['icon'=>'fa-eye',  'label'=>'view', 'href'=>BP_URL.'admin/patient-view.php?id='.(int)$p['id']],
                                can('patients.edit') ? ['icon'=>'fa-pen','label'=>'edit','href'=>'?action=edit&id='.(int)$p['id'],'modal'=>true] : null,
                                can('patients.delete') ? ['icon'=>'fa-trash','label'=>'delete','danger'=>true,'divider_before'=>true,
                                    'confirm'=>'are_you_sure',
                                    'href'=>'?action=delete&id='.(int)$p['id'].'&_csrf='.csrf_token()] : null,
                            ]) ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile card list -->
        <div class="patient-list d-md-none">
            <?php if (!$rows): ?>
                <div class="empty-state py-5">
                    <i class="fa-regular fa-folder-open"></i>
                    <div><?= __('no_data') ?></div>
                </div>
            <?php else: foreach ($rows as $p):
                $initials = mb_strtoupper(mb_substr($p['first_name'],0,1).mb_substr($p['last_name'],0,1));
                $hasOutstanding = (float)$p['outstanding_balance'] > 0;
                $viewUrl = BP_URL.'admin/patient-view.php?id='.(int)$p['id'];
            ?>
                <div class="patient-card">
                    <a href="<?= $viewUrl ?>" class="patient-card-avatar" aria-label="<?= __('view') ?>">
                        <?php if (!empty($p['avatar'])): ?>
                            <img src="<?= BP_URL.'uploads/'.e($p['avatar']) ?>" alt="">
                        <?php else: ?>
                            <span><?= e($initials) ?></span>
                        <?php endif; ?>
                    </a>
                    <div class="patient-card-body">
                        <a href="<?= $viewUrl ?>" class="patient-card-name"><?= e($p['first_name'].' '.$p['last_name']) ?></a>
                        <div class="patient-card-meta">
                            <span class="patient-code"><?= e($p['code']) ?></span>
                            <span class="dot-sep">·</span>
                            <span><?= __('g_'.$p['gender']) ?></span>
                        </div>
                        <div class="patient-card-contact">
                            <a href="tel:<?= e($p['phone']) ?>" class="patient-chip" dir="ltr">
                                <i class="fa-solid fa-phone"></i><?= e($p['phone']) ?>
                            </a>
                            <?php if ($hasOutstanding): ?>
                                <span class="patient-chip danger">
                                    <i class="fa-solid fa-flag"></i><?= format_money($p['outstanding_balance']) ?>
                                </span>
                            <?php else: ?>
                                <span class="patient-chip success">
                                    <i class="fa-solid fa-circle-check"></i><?= __('clear_status') ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="patient-card-actions">
                        <?= render_actions([
                            ['icon'=>'fa-eye',  'label'=>'view', 'href'=>$viewUrl],
                            can('patients.edit') ? ['icon'=>'fa-pen','label'=>'edit','href'=>'?action=edit&id='.(int)$p['id'],'modal'=>true] : null,
                            can('patients.delete') ? ['icon'=>'fa-trash','label'=>'delete','danger'=>true,'divider_before'=>true,
                                'confirm'=>'are_you_sure',
                                'href'=>'?action=delete&id='.(int)$p['id'].'&_csrf='.csrf_token()] : null,
                        ]) ?>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <?php if ($total > $perPage): ?>
            <div><?= render_pagination($total,$page,$perPage,BP_URL.'admin/patients.php' . ($q?"?q=$q":'')) ?></div>
        <?php endif; ?>
    </div>
</div>
<?php include BP_PARTIALS . '/footer.php'; ?>
