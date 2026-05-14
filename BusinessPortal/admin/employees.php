<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('employees.view');

$PageTitle = __('employees');
$action    = $_GET['action'] ?? 'list';
$id        = (int)($_GET['id'] ?? 0);

if ($action === 'delete' && $id) {
    csrf_check(); require_can('employees.delete');
    db()->prepare("UPDATE employees SET deleted_at=NOW() WHERE id=?")->execute([$id]);
    log_activity('deleted','employees',"Soft-deleted employee #$id",'employee',$id);
    flash('success','Employee deleted.');
    redirect(BP_URL.'admin/employees.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['create','edit'], true)) {
    csrf_check();
    require_can($action==='create'?'employees.create':'employees.edit');

    $userId    = (int)($_POST['user_id'] ?? 0);
    $first     = trim($_POST['first_name'] ?? '');
    $firstEn   = trim($_POST['first_name_en'] ?? '');
    $last      = trim($_POST['last_name'] ?? '');
    $lastEn    = trim($_POST['last_name_en'] ?? '');
    $job       = trim($_POST['job_title'] ?? '');
    $jobEn     = trim($_POST['job_title_en'] ?? '');
    $dept      = trim($_POST['department'] ?? '');
    $deptEn    = trim($_POST['department_en'] ?? '');
    $bioAr     = trim($_POST['bio_ar'] ?? '');
    $bioEn     = trim($_POST['bio_en'] ?? '');
    $showSite  = isset($_POST['show_on_site']) ? 1 : 0;

    // Avatar upload (optional)
    $avatar = null;
    if (!empty($_FILES['avatar']['name']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $up = upload_file($_FILES['avatar'], 'employees', ['jpg','jpeg','png','webp'], 4*1024*1024);
        if ($up) $avatar = $up['relative_path'];
    }
    $natId     = trim($_POST['national_id'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $dob       = trim($_POST['dob'] ?? '') ?: null;
    $gender    = $_POST['gender'] ?? null;
    $address   = trim($_POST['address'] ?? '');
    $hireDate  = trim($_POST['hire_date'] ?? date('Y-m-d'));
    $term      = trim($_POST['termination_date'] ?? '') ?: null;
    $contract  = $_POST['contract_type'] ?? 'full_time';
    $base      = (float)($_POST['base_salary'] ?? 0);
    $commPct   = (float)($_POST['commission_default_pct'] ?? 0);
    $bankName  = trim($_POST['bank_name'] ?? '');
    $bankAcc   = trim($_POST['bank_account'] ?? '');
    $iban      = trim($_POST['iban'] ?? '');
    $emName    = trim($_POST['emergency_name'] ?? '');
    $emPhone   = trim($_POST['emergency_phone'] ?? '');
    $notes     = trim($_POST['notes'] ?? '');
    $active    = isset($_POST['is_active']) ? 1 : 0;

    $errors = [];
    if (!$userId) $errors[] = 'Pick the linked user account.';
    if ($first === '' || $last === '') $errors[] = 'Name is required.';
    if (!in_array($contract, ['full_time','part_time','contract','intern'], true)) $contract = 'full_time';
    if ($gender && !in_array($gender, ['male','female','other'], true)) $gender = null;

    // user_id must be unique across employees
    $u = db()->prepare("SELECT id FROM employees WHERE user_id=? AND id<>? AND deleted_at IS NULL");
    $u->execute([$userId, $id]);
    if ($u->fetch()) $errors[] = 'This user already has an employee record.';

    if ($errors) { foreach ($errors as $err) flash('error',$err); set_old($_POST); back(); }

    if ($action === 'create') {
        db()->prepare("INSERT INTO employees
            (user_id,code,first_name,first_name_en,last_name,last_name_en,job_title,job_title_en,
             department,department_en,avatar,national_id,phone,dob,gender,address,
             hire_date,termination_date,contract_type,base_salary,commission_default_pct,
             bank_name,bank_account,iban,emergency_name,emergency_phone,notes,bio_ar,bio_en,
             is_active,show_on_site,created_by,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")
            ->execute([
                $userId, next_employee_code(), $first,$firstEn,$last,$lastEn,$job,$jobEn,$dept,$deptEn,
                $avatar,$natId,$phone,$dob,$gender,$address,
                $hireDate,$term,$contract,$base,$commPct,
                $bankName,$bankAcc,$iban,$emName,$emPhone,$notes,$bioAr,$bioEn,
                $active,$showSite,$_SESSION['user_id']
            ]);
        $eid = (int)db()->lastInsertId();
        log_activity('created','employees',"Created employee #$eid ($first $last)",'employee',$eid);
    } else {
        // Build SET clause + params in lock-step order
        $set = [
            'user_id=?'                => $userId,
            'first_name=?'             => $first,
            'first_name_en=?'          => $firstEn,
            'last_name=?'              => $last,
            'last_name_en=?'           => $lastEn,
            'job_title=?'              => $job,
            'job_title_en=?'           => $jobEn,
            'department=?'             => $dept,
            'department_en=?'          => $deptEn,
            'national_id=?'            => $natId,
            'phone=?'                  => $phone,
            'dob=?'                    => $dob,
            'gender=?'                 => $gender,
            'address=?'                => $address,
            'hire_date=?'              => $hireDate,
            'termination_date=?'       => $term,
            'contract_type=?'          => $contract,
            'base_salary=?'            => $base,
            'commission_default_pct=?' => $commPct,
            'bank_name=?'              => $bankName,
            'bank_account=?'           => $bankAcc,
            'iban=?'                   => $iban,
            'emergency_name=?'         => $emName,
            'emergency_phone=?'        => $emPhone,
            'notes=?'                  => $notes,
            'bio_ar=?'                 => $bioAr,
            'bio_en=?'                 => $bioEn,
            'is_active=?'              => $active,
            'show_on_site=?'           => $showSite,
            'updated_by=?'             => $_SESSION['user_id'],
        ];
        if ($avatar !== null) $set['avatar=?'] = $avatar;

        $sql    = "UPDATE employees SET " . implode(', ', array_keys($set)) . ", updated_at=NOW() WHERE id=?";
        $params = array_values($set);
        $params[] = $id;

        db()->prepare($sql)->execute($params);
        log_activity('updated','employees',"Updated employee #$id",'employee',$id);
    }
    flash('success','Employee saved.');
    redirect(BP_URL.'admin/employees.php');
}

if (in_array($action, ['create','edit'], true)) {
    require_can($action==='create'?'employees.create':'employees.edit');
    $emp = ['user_id'=>0,'code'=>'',
            'first_name'=>'','first_name_en'=>'','last_name'=>'','last_name_en'=>'',
            'job_title'=>'','job_title_en'=>'','department'=>'','department_en'=>'',
            'national_id'=>'','phone'=>'','dob'=>'','gender'=>'','address'=>'',
            'hire_date'=>date('Y-m-d'),'termination_date'=>'','contract_type'=>'full_time',
            'base_salary'=>0,'commission_default_pct'=>0,
            'bank_name'=>'','bank_account'=>'','iban'=>'','emergency_name'=>'','emergency_phone'=>'',
            'notes'=>'','bio_ar'=>'','bio_en'=>'','is_active'=>1,'show_on_site'=>1];
    if ($action==='edit' && $id) {
        $s = db()->prepare("SELECT * FROM employees WHERE id=? AND deleted_at IS NULL");
        $s->execute([$id]); $emp = $s->fetch();
        if (!$emp) { flash('error','Not found.'); redirect(BP_URL.'admin/employees.php'); }
    }
    // Users that aren't already linked to an employee (or self-edit)
    $users = db()->prepare("
        SELECT u.id, u.name, u.email
        FROM users u
        LEFT JOIN employees e ON e.user_id = u.id AND e.deleted_at IS NULL
        WHERE u.deleted_at IS NULL AND (e.id IS NULL OR u.id = ?)
        ORDER BY u.name
    ");
    $users->execute([(int)$emp['user_id']]);
    $users = $users->fetchAll();

    include BP_PARTIALS . '/header.php';
    ?>
    <div class="page-wrap">
        <h4 class="mb-3">
            <?= $action==='create'?__('create'):__('edit') ?> — <?= __('employees') ?>
            <?php if (!empty($emp['code'])): ?><small class="text-muted">[<?= e($emp['code']) ?>]</small><?php endif; ?>
        </h4>

        <form method="post" enctype="multipart/form-data" class="card"><div class="card-body">
            <?= csrf_field() ?>

            <h6 class="text-teal"><?= __('identity') ?></h6>
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label"><?= __('user_account') ?> *</label>
                    <select name="user_id" class="form-select" required>
                        <option value="">— <?= __('pick') ?> —</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>" <?= (int)$emp['user_id']===(int)$u['id']?'selected':'' ?>>
                                <?= e($u['name']) ?> · <?= e($u['email']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted"><?= __('user_account_help') ?></small>
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('first_name') ?> (AR) *</label>
                    <input name="first_name" required class="form-control" value="<?= e($emp['first_name']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('first_name') ?> (EN)</label>
                    <input name="first_name_en" class="form-control" value="<?= e($emp['first_name_en'] ?? '') ?>" dir="ltr">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('last_name') ?> (AR) *</label>
                    <input name="last_name" required class="form-control" value="<?= e($emp['last_name']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('last_name') ?> (EN)</label>
                    <input name="last_name_en" class="form-control" value="<?= e($emp['last_name_en'] ?? '') ?>" dir="ltr">
                </div>

                <div class="col-md-3">
                    <label class="form-label"><?= __('job_title') ?> (AR)</label>
                    <input name="job_title" class="form-control" value="<?= e($emp['job_title']??'') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('job_title') ?> (EN)</label>
                    <input name="job_title_en" class="form-control" value="<?= e($emp['job_title_en'] ?? '') ?>" dir="ltr">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('department') ?> (AR)</label>
                    <input name="department" class="form-control" value="<?= e($emp['department']??'') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('department') ?> (EN)</label>
                    <input name="department_en" class="form-control" value="<?= e($emp['department_en'] ?? '') ?>" dir="ltr">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('national_id') ?></label>
                    <input name="national_id" class="form-control" value="<?= e($emp['national_id']??'') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('phone') ?></label>
                    <input name="phone" class="form-control" value="<?= e($emp['phone']??'') ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label"><?= __('date_of_birth') ?></label>
                    <input type="date" name="dob" class="form-control" value="<?= e($emp['dob']??'') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('gender') ?></label>
                    <select name="gender" class="form-select">
                        <option value="">—</option>
                        <?php foreach (['female','male','other'] as $g): ?>
                            <option value="<?= $g ?>" <?= ($emp['gender']??'')===$g?'selected':'' ?>><?= __('gender_'.$g) ?: ucfirst($g) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?= __('address') ?></label>
                    <input name="address" class="form-control" value="<?= e($emp['address']??'') ?>">
                </div>
            </div>

            <h6 class="text-teal mt-4"><?= __('contract') ?></h6>
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <label class="form-label"><?= __('hire_date') ?> *</label>
                    <input type="date" name="hire_date" required class="form-control" value="<?= e($emp['hire_date']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('termination_date') ?></label>
                    <input type="date" name="termination_date" class="form-control" value="<?= e($emp['termination_date']??'') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('contract_type') ?></label>
                    <select name="contract_type" class="form-select">
                        <?php foreach (['full_time','part_time','contract','intern'] as $c): ?>
                            <option value="<?= $c ?>" <?= ($emp['contract_type']??'full_time')===$c?'selected':'' ?>><?= __('contract_'.$c) ?: str_replace('_',' ',$c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <label class="form-check">
                        <input type="checkbox" class="form-check-input" name="is_active" <?= !empty($emp['is_active'])?'checked':'' ?>>
                        <span class="form-check-label"><?= __('active') ?></span>
                    </label>
                </div>

                <div class="col-md-3">
                    <label class="form-label"><?= __('base_salary') ?> (<?= APP_CURRENCY ?>)</label>
                    <input type="number" step="0.01" min="0" name="base_salary" class="form-control" value="<?= e($emp['base_salary']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('default_commission_pct') ?></label>
                    <input type="number" step="0.01" min="0" max="100" name="commission_default_pct" class="form-control" value="<?= e($emp['commission_default_pct']) ?>">
                    <small class="text-muted"><?= __('default_commission_help') ?></small>
                </div>
            </div>

            <h6 class="text-teal mt-4"><?= __('banking') ?></h6>
            <div class="row g-3 mb-3">
                <div class="col-md-4"><label class="form-label"><?= __('bank') ?></label>
                    <input name="bank_name" class="form-control" value="<?= e($emp['bank_name']??'') ?>"></div>
                <div class="col-md-4"><label class="form-label"><?= __('account_number') ?></label>
                    <input name="bank_account" class="form-control" value="<?= e($emp['bank_account']??'') ?>"></div>
                <div class="col-md-4"><label class="form-label"><?= __('iban') ?></label>
                    <input name="iban" class="form-control" value="<?= e($emp['iban']??'') ?>"></div>
            </div>

            <h6 class="text-teal mt-4"><?= __('emergency_contact') ?></h6>
            <div class="row g-3 mb-3">
                <div class="col-md-6"><label class="form-label"><?= __('name') ?></label>
                    <input name="emergency_name" class="form-control" value="<?= e($emp['emergency_name']??'') ?>"></div>
                <div class="col-md-6"><label class="form-label"><?= __('phone') ?></label>
                    <input name="emergency_phone" class="form-control" value="<?= e($emp['emergency_phone']??'') ?>"></div>
            </div>

            <div class="mb-3">
                <label class="form-label"><?= __('notes') ?></label>
                <textarea name="notes" rows="2" class="form-control"><?= e($emp['notes']??'') ?></textarea>
            </div>

            <!-- Public profile (shown on the website team page) -->
            <h6 class="text-teal mt-4"><?= __('public_profile') ?></h6>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label"><?= __('avatar') ?: 'الصورة' ?></label>
                    <input type="file" name="avatar" accept="image/*" class="form-control">
                    <?php if (!empty($emp['avatar'])): ?>
                        <img src="<?= UPLOADS_URL . e($emp['avatar']) ?>" class="mt-2 rounded" style="height:90px;width:90px;object-fit:cover">
                    <?php endif; ?>
                    <small class="text-muted d-block mt-1"><?= __('avatar_help') ?: 'صورة مربعة بحجم 800×800 يُفضّل' ?></small>
                </div>
                <div class="col-md-8">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label"><?= __('bio') ?> (AR)</label>
                            <textarea name="bio_ar" rows="3" class="form-control" placeholder="نبذة قصيرة تظهر في صفحة الفريق على الموقع"><?= e($emp['bio_ar'] ?? '') ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?= __('bio') ?> (EN)</label>
                            <textarea name="bio_en" rows="3" class="form-control" placeholder="Short bio shown on the public team page" dir="ltr"><?= e($emp['bio_en'] ?? '') ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-check">
                                <input type="checkbox" class="form-check-input" name="show_on_site" <?= !empty($emp['show_on_site'])?'checked':'' ?>>
                                <span class="form-check-label"><?= __('show_on_site') ?></span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button class="btn btn-teal"><?= __('save') ?></button>
                <a class="btn btn-light" href="<?= BP_URL ?>admin/employees.php"><?= __('cancel') ?></a>
            </div>
        </div></form>
    </div>
    <?php include BP_PARTIALS . '/footer.php'; clear_old(); exit;
}

// LIST
$q     = trim($_GET['q'] ?? '');
$dept  = trim($_GET['dept'] ?? '');
$page  = (int)($_GET['page'] ?? 1);
[$perPage, $offset] = paginate_query($page, (int)setting('per_page','25'));

$where = "e.deleted_at IS NULL"; $params = [];
if ($q !== '') {
    $where .= " AND (e.code LIKE ? OR e.first_name LIKE ? OR e.last_name LIKE ? OR e.phone LIKE ? OR u.email LIKE ?)";
    $like = "%$q%"; array_push($params,$like,$like,$like,$like,$like);
}
if ($dept !== '') { $where .= " AND e.department = ?"; $params[] = $dept; }

$tot = db()->prepare("SELECT COUNT(*) FROM employees e LEFT JOIN users u ON u.id=e.user_id WHERE $where");
$tot->execute($params); $total = (int)$tot->fetchColumn();

$sql = "SELECT e.*, u.email,
               (SELECT GROUP_CONCAT(r.name SEPARATOR ', ') FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=u.id) AS roles
        FROM employees e LEFT JOIN users u ON u.id=e.user_id
        WHERE $where ORDER BY e.id DESC LIMIT $perPage OFFSET $offset";
$stmt = db()->prepare($sql); $stmt->execute($params);
$rows = $stmt->fetchAll();

$depts = db()->query("SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department <> '' AND deleted_at IS NULL ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);

// KPI stats
$kpi = db()->query("
    SELECT
      COUNT(*) AS total,
      SUM(is_active=1) AS active_count,
      COUNT(DISTINCT department) AS dept_count,
      COALESCE(SUM(CASE WHEN is_active=1 THEN base_salary ELSE 0 END),0) AS payroll
    FROM employees WHERE deleted_at IS NULL
")->fetch() ?: ['total'=>0,'active_count'=>0,'dept_count'=>0,'payroll'=>0];
$activeFilters = ($q !== '') + ($dept !== '');

include BP_PARTIALS . '/header.php';
?>
<div class="page-wrap">
    <!-- Header -->
    <div class="page-header">
        <h4 class="m-0">
            <i class="fa-solid fa-id-badge text-teal me-2"></i><?= __('employees') ?>
            <span class="page-count">(<?= $total ?>)</span>
        </h4>
        <div class="page-header-actions">
            <?php if (can('employees.create')): ?>
                <a href="?action=create" class="btn btn-teal btn-sm" data-modal>
                    <i class="fa-solid fa-plus me-1"></i><?= __('new_employee') ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- KPI strip -->
    <div class="appt-kpis">
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#0d9488"><i class="fa-solid fa-users"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('total_employees') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['total'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#10b981"><i class="fa-solid fa-user-check"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('active') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['active_count'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#6366f1"><i class="fa-solid fa-sitemap"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('departments') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['dept_count'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#b45309"><i class="fa-solid fa-money-bill-trend-up"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('monthly_payroll') ?></div>
                <div class="appt-kpi-value" style="font-size:1.05rem"><?= format_money($kpi['payroll']) ?></div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <form method="get" class="appt-filters">
        <div class="appt-filter-group flex-grow-1">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="<?= __('search_employee_placeholder') ?>">
        </div>
        <div class="appt-filter-group">
            <select name="dept" class="form-select form-select-sm">
                <option value=""><?= __('all_departments') ?></option>
                <?php foreach ($depts as $d): ?>
                    <option value="<?= e($d) ?>" <?= $dept===$d?'selected':'' ?>><?= e($d) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-teal btn-sm"><i class="fa-solid fa-filter me-1"></i><?= __('apply') ?></button>
        <?php if ($activeFilters): ?>
            <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/employees.php" title="<?= __('clear_filters') ?>"><i class="fa-solid fa-xmark"></i></a>
        <?php endif; ?>
    </form>

    <div class="table-card">
        <div class="table-responsive d-none d-md-block">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th><?= __('code') ?></th><th><?= __('name') ?></th><th><?= __('job') ?></th><th><?= __('department') ?></th>
                <th><?= __('roles') ?></th><th class="text-end"><?= __('base_salary') ?></th><th><?= __('status') ?></th><th></th>
            </tr></thead>
            <tbody>
                <?php foreach ($rows as $emp): ?>
                    <tr>
                        <td><code><?= e($emp['code']) ?></code></td>
                        <td><?= e($emp['first_name'].' '.$emp['last_name']) ?>
                            <?php if ($emp['email']): ?><div class="small text-muted"><?= e($emp['email']) ?></div><?php endif; ?>
                        </td>
                        <td class="small"><?= e($emp['job_title']??'—') ?></td>
                        <td class="small"><?= e($emp['department']??'—') ?></td>
                        <td class="small text-muted"><?= e($emp['roles']??'—') ?></td>
                        <td class="text-end"><?= format_money($emp['base_salary']) ?></td>
                        <td><span class="badge bg-<?= $emp['is_active']?'success':'secondary' ?>"><?= __($emp['is_active']?'active':'inactive') ?></span></td>
                        <td class="text-end">
                            <?= render_actions([
                                (can('employees.edit') ? ['icon'=>'fa-pen','label'=>'edit','href'=>'?action=edit&id='.(int)$emp['id'],'modal'=>true] : null),
                                (can('employees.delete') ? ['icon'=>'fa-trash','label'=>'delete','href'=>'?action=delete&id='.(int)$emp['id'].'&_csrf='.e(csrf_token()),'danger'=>true,'divider_before'=>true,'confirm'=>'are_you_sure'] : null),
                            ]) ?>
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
                <div class="empty-state"><i class="fa-regular fa-id-badge"></i><div><?= __('no_data') ?></div></div>
            <?php else: foreach ($rows as $emp):
                $initials = mb_strtoupper(mb_substr($emp['first_name'],0,1).mb_substr($emp['last_name'],0,1));
                $chips = [
                    ['label'=>__($emp['is_active']?'active':'inactive'),'icon'=>'fa-circle-dot','class'=>$emp['is_active']?'success':''],
                ];
                if (!empty($emp['job_title']))   $chips[] = ['label'=>$emp['job_title'],'icon'=>'fa-briefcase'];
                if (!empty($emp['department']))  $chips[] = ['label'=>$emp['department'],'icon'=>'fa-sitemap','class'=>'info'];
                echo render_entity_card([
                    'avatar' => $initials,
                    'avatar_class' => 'indigo',
                    'title' => $emp['first_name'].' '.$emp['last_name'],
                    'title_right' => format_money($emp['base_salary']),
                    'code' => $emp['code'],
                    'meta' => !empty($emp['email']) ? [$emp['email']] : [],
                    'chips' => $chips,
                    'actions' => [
                        (can('employees.edit') ? ['icon'=>'fa-pen','label'=>'edit','href'=>'?action=edit&id='.(int)$emp['id'],'modal'=>true] : null),
                        (can('employees.delete') ? ['icon'=>'fa-trash','label'=>'delete','href'=>'?action=delete&id='.(int)$emp['id'].'&_csrf='.csrf_token(),'danger'=>true,'divider_before'=>true,'confirm'=>'are_you_sure'] : null),
                    ],
                ]);
            endforeach; endif; ?>
        </div>

        <div class="p-2"><?= render_pagination($total,$page,$perPage,BP_URL.'admin/employees.php?'.http_build_query(['q'=>$q,'dept'=>$dept])) ?></div>
    </div>
</div>
<?php include BP_PARTIALS . '/footer.php'; ?>
