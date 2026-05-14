<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('users.view');

$PageTitle = __('users');
$action    = $_GET['action'] ?? 'list';
$id        = (int)($_GET['id'] ?? 0);

// ─── DELETE ──────────────────────────────────────────────────────────
if ($action === 'delete' && $id) {
    require_can('users.delete');
    csrf_check();
    if ($id === (int)($_SESSION['user_id'] ?? 0)) {
        flash('error', 'You cannot delete yourself.');
        redirect(BP_URL . 'admin/users.php');
    }
    db()->prepare("UPDATE users SET deleted_at = NOW() WHERE id = ?")->execute([$id]);
    log_activity('deleted', 'users', "Soft-deleted user #$id", 'user', $id);
    flash('success', 'User deleted.');
    redirect(BP_URL . 'admin/users.php');
}

// ─── CREATE / EDIT POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['create','edit'], true)) {
    csrf_check();
    require_can($action === 'create' ? 'users.create' : 'users.edit');

    $name   = trim($_POST['name'] ?? '');
    $email  = trim($_POST['email'] ?? '');
    $phone  = trim($_POST['phone'] ?? '');
    $status = $_POST['status'] ?? 'active';
    $pass   = $_POST['password'] ?? '';
    $roles  = $_POST['roles'] ?? [];
    $errors = [];

    if (mb_strlen($name) < 2) $errors[] = 'Name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email.';
    if (!in_array($status, ['active','inactive','suspended'], true)) $status = 'active';

    // Email unique
    $check = db()->prepare("SELECT id FROM users WHERE email = ? AND id <> ? AND deleted_at IS NULL");
    $check->execute([$email, $id]);
    if ($check->fetch()) $errors[] = 'Email is already in use.';

    if ($action === 'create' && strlen($pass) < 8) $errors[] = 'Password must be at least 8 chars.';

    if ($errors) {
        foreach ($errors as $e) flash('error', $e);
        set_old(compact('name','email','phone','status'));
        redirect(BP_URL . 'admin/users.php?action=' . $action . ($id ? "&id=$id" : ''));
    }

    if ($action === 'create') {
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        db()->prepare("
            INSERT INTO users (name,email,phone,password,status,must_change_pw,created_by,created_at,updated_at)
            VALUES (?,?,?,?,?,1,?,NOW(),NOW())
        ")->execute([$name,$email,$phone,$hash,$status,$_SESSION['user_id']]);
        $newId = (int)db()->lastInsertId();
        log_activity('created', 'users', "Created user $email", 'user', $newId);
        flash('success', 'User created.');
    } else {
        if ($pass !== '') {
            if (strlen($pass) < 8) {
                flash('error','Password too short.');
                redirect(BP_URL . "admin/users.php?action=edit&id=$id");
            }
            $hash = password_hash($pass, PASSWORD_BCRYPT);
            db()->prepare("UPDATE users SET name=?,email=?,phone=?,password=?,status=?,updated_by=?,updated_at=NOW() WHERE id=?")
                ->execute([$name,$email,$phone,$hash,$status,$_SESSION['user_id'],$id]);
        } else {
            db()->prepare("UPDATE users SET name=?,email=?,phone=?,status=?,updated_by=?,updated_at=NOW() WHERE id=?")
                ->execute([$name,$email,$phone,$status,$_SESSION['user_id'],$id]);
        }
        log_activity('updated', 'users', "Updated user #$id", 'user', $id);
        flash('success', 'User updated.');
    }

    // Sync roles (only Super Admin or users with proper perm)
    $userId = $action === 'create' ? $newId : $id;
    if (!empty($roles) && is_array($roles)) {
        db()->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$userId]);
        $rIns = db()->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)");
        foreach ($roles as $rid) {
            $rid = (int)$rid;
            if ($rid > 0) $rIns->execute([$userId, $rid]);
        }
    }

    redirect(BP_URL . 'admin/users.php');
}

// ─── CREATE / EDIT FORM ──────────────────────────────────────────────
if (in_array($action, ['create','edit'], true)) {
    require_can($action === 'create' ? 'users.create' : 'users.edit');
    $user = ['name'=>'','email'=>'','phone'=>'','status'=>'active'];
    $userRoleIds = [];
    if ($action === 'edit' && $id) {
        $stmt = db()->prepare("SELECT * FROM users WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if (!$user) { flash('error','User not found.'); redirect(BP_URL . 'admin/users.php'); }
        $rs = db()->prepare("SELECT role_id FROM user_roles WHERE user_id = ?");
        $rs->execute([$id]);
        $userRoleIds = array_column($rs->fetchAll(), 'role_id');
    }
    $allRoles = db()->query("SELECT id,name,slug FROM roles WHERE deleted_at IS NULL ORDER BY name")->fetchAll();

    include BP_PARTIALS . '/header.php';
?>
<div class="page-wrap">
    <h4 class="mb-3"><?= $action === 'create' ? __('create') : __('edit') ?> — <?= __('users') ?></h4>
    <form method="post" class="card"><div class="card-body">
        <?= csrf_field() ?>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label"><?= __('name') ?></label>
                <input name="name" class="form-control" required value="<?= e(old('name', $user['name'])) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= __('email') ?></label>
                <input name="email" type="email" class="form-control" required value="<?= e(old('email', $user['email'])) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= __('phone') ?></label>
                <input name="phone" class="form-control" value="<?= e(old('phone', $user['phone'] ?? '')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= __('status') ?></label>
                <select name="status" class="form-select">
                    <?php foreach (['active','inactive','suspended'] as $s): ?>
                        <option value="<?= $s ?>" <?= ($user['status']??'')===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">
                    <?= __('password') ?> <?= $action==='edit' ? '<small class="text-muted">(leave blank to keep)</small>' : '' ?>
                </label>
                <input name="password" type="password" class="form-control" minlength="8" <?= $action==='create'?'required':'' ?>>
            </div>
            <div class="col-md-12">
                <label class="form-label"><?= __('roles_assigned') ?></label>
                <div class="row g-2">
                    <?php foreach ($allRoles as $r): ?>
                        <div class="col-md-3">
                            <label class="form-check">
                                <input class="form-check-input" type="checkbox"
                                       name="roles[]" value="<?= (int)$r['id'] ?>"
                                       <?= in_array($r['id'], $userRoleIds) ? 'checked' : '' ?>>
                                <span class="form-check-label"><?= e($r['name']) ?></span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="mt-3 d-flex gap-2">
            <button class="btn btn-teal"><?= __('save') ?></button>
            <a href="<?= BP_URL ?>admin/users.php" class="btn btn-light"><?= __('cancel') ?></a>
        </div>
    </div></form>
</div>
<?php include BP_PARTIALS . '/footer.php';
clear_old();
exit;
}

// ─── LIST ─────────────────────────────────────────────────────────────
$q = trim($_GET['q'] ?? '');
$page = (int)($_GET['page'] ?? 1);
[$perPage, $offset] = paginate_query($page, (int)setting('per_page','25'));

$where = "u.deleted_at IS NULL";
$params = [];
if ($q !== '') {
    $where .= " AND (u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $like = "%$q%";
    array_push($params, $like, $like, $like);
}

$total = db()->prepare("SELECT COUNT(*) FROM users u WHERE $where");
$total->execute($params);
$total = (int)$total->fetchColumn();

$sql = "SELECT u.*, GROUP_CONCAT(r.name SEPARATOR ', ') AS roles_list
        FROM users u
        LEFT JOIN user_roles ur ON ur.user_id = u.id
        LEFT JOIN roles r ON r.id = ur.role_id
        WHERE $where
        GROUP BY u.id
        ORDER BY u.id DESC
        LIMIT $perPage OFFSET $offset";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

// KPI stats
$kpi = db()->query("
    SELECT
      COUNT(*) AS total,
      SUM(status='active')    AS active,
      SUM(status='suspended') AS suspended,
      SUM(last_login_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS recent
    FROM users WHERE deleted_at IS NULL
")->fetch() ?: ['total'=>0,'active'=>0,'suspended'=>0,'recent'=>0];

include BP_PARTIALS . '/header.php';
?>
<div class="page-wrap">
    <!-- Header -->
    <div class="page-header">
        <h4 class="m-0">
            <i class="fa-solid fa-users text-teal me-2"></i><?= __('users') ?>
            <small class="text-muted ms-2" style="font-size:.85rem">(<?= $total ?>)</small>
        </h4>
        <div class="page-header-actions">
            <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/roles.php">
                <i class="fa-solid fa-shield-halved me-1"></i><?= __('roles') ?>
            </a>
            <?php if (can('users.create')): ?>
                <a href="?action=create" class="btn btn-teal btn-sm" data-modal>
                    <i class="fa-solid fa-plus me-1"></i><?= __('new_user') ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- KPI strip -->
    <div class="appt-kpis">
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#6366f1"><i class="fa-solid fa-users"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('total_users') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['total'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#10b981"><i class="fa-solid fa-user-check"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('active') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['active'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#ef4444"><i class="fa-solid fa-user-slash"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('suspended') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['suspended'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#0ea5e9"><i class="fa-solid fa-bolt"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('active_7d') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['recent'] ?></div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <form method="get" class="appt-filters">
        <div class="appt-filter-group flex-grow-1">
            <input type="search" name="q" value="<?= e($q) ?>" class="form-control form-control-sm"
                   placeholder="<?= __('search_user_placeholder') ?>">
        </div>
        <button class="btn btn-teal btn-sm"><i class="fa-solid fa-filter me-1"></i><?= __('apply') ?></button>
        <?php if ($q): ?><a class="btn btn-light btn-sm" href="?"><?= __('clear_filters') ?></a><?php endif; ?>
    </form>

    <div class="table-card">
        <div class="table-responsive d-none d-md-block">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr>
                <th>#</th><th><?= __('name') ?></th><th><?= __('email') ?></th>
                <th><?= __('roles_assigned') ?></th><th><?= __('status') ?></th>
                <th><?= __('last_login') ?></th><th class="text-end"><?= __('actions') ?></th>
            </tr></thead>
            <tbody>
            <?php if (!$users): ?>
                <tr><td colspan="7" class="text-center text-muted py-4"><?= __('no_data') ?></td></tr>
            <?php else: foreach ($users as $u): ?>
                <tr>
                    <td><?= (int)$u['id'] ?></td>
                    <td><?= e($u['name']) ?></td>
                    <td><?= e($u['email']) ?></td>
                    <td><small class="text-muted"><?= e($u['roles_list'] ?? '—') ?></small></td>
                    <td>
                        <span class="badge bg-<?= $u['status']==='active'?'success':($u['status']==='suspended'?'danger':'secondary') ?>">
                            <?= e(__($u['status'])) ?: e($u['status']) ?>
                        </span>
                    </td>
                    <td><small><?= format_date($u['last_login_at']) ?></small></td>
                    <td class="text-end">
                        <?= render_actions([
                            (can('users.edit') ? ['icon'=>'fa-pen','label'=>'edit','href'=>'?action=edit&id='.(int)$u['id'],'modal'=>true] : null),
                            ['icon'=>'fa-trash','label'=>'delete','href'=>'?action=delete&id='.(int)$u['id'].'&_csrf='.e(csrf_token()),'danger'=>true,'divider_before'=>true,'confirm'=>'are_you_sure'],
                        ]) ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>

        <!-- Mobile entity card list -->
        <div class="entity-list d-md-none">
            <?php if (!$users): ?>
                <div class="empty-state"><i class="fa-regular fa-user"></i><div><?= __('no_data') ?></div></div>
            <?php else: foreach ($users as $u):
                $initials = mb_strtoupper(mb_substr($u['name'],0,1));
                $statusChip = ['active'=>'success','suspended'=>'danger'][$u['status']] ?? '';
                echo render_entity_card([
                    'avatar' => $initials,
                    'avatar_class' => $u['status']==='active'?'':'slate',
                    'title' => $u['name'],
                    'meta' => [$u['email']],
                    'chips' => [
                        ['label'=>__($u['status']) ?: $u['status'],'icon'=>'fa-circle-dot','class'=>$statusChip],
                        !empty($u['roles_list']) ? ['label'=>$u['roles_list'],'icon'=>'fa-user-shield','class'=>'info'] : null,
                        !empty($u['last_login_at']) ? ['label'=>format_date($u['last_login_at'],'Y-m-d'),'icon'=>'fa-clock'] : null,
                    ],
                    'actions' => [
                        (can('users.edit') ? ['icon'=>'fa-pen','label'=>'edit','href'=>'?action=edit&id='.(int)$u['id'],'modal'=>true] : null),
                        ['icon'=>'fa-trash','label'=>'delete','href'=>'?action=delete&id='.(int)$u['id'].'&_csrf='.csrf_token(),'danger'=>true,'divider_before'=>true,'confirm'=>'are_you_sure'],
                    ],
                ]);
            endforeach; endif; ?>
        </div>

        <div class="p-2"><?= render_pagination($total, $page, $perPage, BP_URL . 'admin/users.php' . ($q ? "?q=$q" : '')) ?></div>
    </div>
</div>
<?php include BP_PARTIALS . '/footer.php'; ?>
