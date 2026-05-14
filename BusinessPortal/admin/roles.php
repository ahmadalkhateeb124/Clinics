<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('roles.view');

$PageTitle = __('roles');
$action    = $_GET['action'] ?? 'list';
$id        = (int)($_GET['id'] ?? 0);

if ($action === 'delete' && $id) {
    require_can('roles.delete');
    csrf_check();
    $r = db()->prepare("SELECT is_system FROM roles WHERE id = ?");
    $r->execute([$id]);
    $row = $r->fetch();
    if ($row && $row['is_system']) {
        flash('error', 'System roles cannot be deleted.');
    } else {
        db()->prepare("UPDATE roles SET deleted_at = NOW() WHERE id = ?")->execute([$id]);
        log_activity('deleted', 'roles', "Deleted role #$id", 'role', $id);
        flash('success', 'Role deleted.');
    }
    redirect(BP_URL . 'admin/roles.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['create','edit'], true)) {
    csrf_check();
    require_can($action === 'create' ? 'roles.create' : 'roles.edit');

    $name = trim($_POST['name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $perms = $_POST['permissions'] ?? [];

    if (!$slug) $slug = strtolower(preg_replace('/[^a-zA-Z0-9-]+/','-', $name));
    if ($name === '' || $slug === '') {
        flash('error','Name and slug required.');
        redirect(BP_URL . 'admin/roles.php?action=' . $action . ($id ? "&id=$id" : ''));
    }

    if ($action === 'create') {
        db()->prepare("INSERT INTO roles (name,slug,description,is_system) VALUES (?,?,?,0)")
            ->execute([$name,$slug,$desc]);
        $rid = (int)db()->lastInsertId();
        log_activity('created','roles',"Created role $slug",'role',$rid);
    } else {
        // Cannot rename system role slug
        $check = db()->prepare("SELECT is_system FROM roles WHERE id = ?");
        $check->execute([$id]);
        $sys = $check->fetchColumn();
        if ($sys) {
            db()->prepare("UPDATE roles SET name=?, description=? WHERE id=?")
                ->execute([$name,$desc,$id]);
        } else {
            db()->prepare("UPDATE roles SET name=?, slug=?, description=? WHERE id=?")
                ->execute([$name,$slug,$desc,$id]);
        }
        $rid = $id;
        log_activity('updated','roles',"Updated role #$rid",'role',$rid);
    }

    db()->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$rid]);
    if (is_array($perms)) {
        $ins = db()->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
        foreach ($perms as $pid) {
            $pid = (int)$pid;
            if ($pid > 0) $ins->execute([$rid, $pid]);
        }
    }
    flash('success','Role saved.');
    redirect(BP_URL . 'admin/roles.php');
}

if (in_array($action, ['create','edit'], true)) {
    require_can($action === 'create' ? 'roles.create' : 'roles.edit');
    $role = ['name'=>'','slug'=>'','description'=>'','is_system'=>0];
    $rolePermIds = [];
    if ($action === 'edit' && $id) {
        $s = db()->prepare("SELECT * FROM roles WHERE id = ? AND deleted_at IS NULL");
        $s->execute([$id]);
        $role = $s->fetch();
        if (!$role) { flash('error','Not found'); redirect(BP_URL.'admin/roles.php'); }
        $r = db()->prepare("SELECT permission_id FROM role_permissions WHERE role_id = ?");
        $r->execute([$id]);
        $rolePermIds = array_column($r->fetchAll(),'permission_id');
    }

    $allPerms = db()->query("SELECT * FROM permissions ORDER BY module, slug")->fetchAll();
    $byModule = [];
    foreach ($allPerms as $p) $byModule[$p['module']][] = $p;
    $totalPerms = count($allPerms);
    $selectedCount = count(array_intersect(array_column($allPerms,'id'), $rolePermIds));

    // Module icons + colors
    $moduleMeta = [
        'dashboard'    => ['icon'=>'fa-gauge-high',          'color'=>'#0d9488'],
        'patients'     => ['icon'=>'fa-user-injured',        'color'=>'#6366f1'],
        'appointments' => ['icon'=>'fa-calendar-check',      'color'=>'#0ea5e9'],
        'consultations'=> ['icon'=>'fa-stethoscope',         'color'=>'#10b981'],
        'services'     => ['icon'=>'fa-hand-holding-heart',  'color'=>'#0d9488'],
        'packages'     => ['icon'=>'fa-box-open',            'color'=>'#b45309'],
        'invoices'     => ['icon'=>'fa-file-invoice-dollar', 'color'=>'#10b981'],
        'payments'     => ['icon'=>'fa-money-bill-wave',     'color'=>'#10b981'],
        'expenses'     => ['icon'=>'fa-receipt',             'color'=>'#ef4444'],
        'employees'    => ['icon'=>'fa-user-tie',            'color'=>'#6366f1'],
        'attendance'   => ['icon'=>'fa-clock',               'color'=>'#0d9488'],
        'leaves'       => ['icon'=>'fa-plane-departure',     'color'=>'#0ea5e9'],
        'payroll'      => ['icon'=>'fa-wallet',              'color'=>'#10b981'],
        'reports'      => ['icon'=>'fa-chart-pie',           'color'=>'#0d9488'],
        'cms'          => ['icon'=>'fa-newspaper',           'color'=>'#0ea5e9'],
        'users'        => ['icon'=>'fa-users',               'color'=>'#6366f1'],
        'roles'        => ['icon'=>'fa-shield-halved',       'color'=>'#0d9488'],
        'settings'     => ['icon'=>'fa-gear',                'color'=>'#64748b'],
        'activity_log' => ['icon'=>'fa-list-check',          'color'=>'#94a3b8'],
    ];
    // Action icons
    $actionIcons = [
        'view'    => 'fa-eye',
        'create'  => 'fa-plus',
        'edit'    => 'fa-pen',
        'delete'  => 'fa-trash',
        'publish' => 'fa-rocket',
        'export'  => 'fa-file-export',
        'manage'  => 'fa-sliders',
    ];

    include BP_PARTIALS . '/header.php';
    ?>
    <div class="page-wrap">
        <div class="page-header mb-3">
            <h4 class="m-0">
                <i class="fa-solid fa-shield-halved text-teal me-2"></i>
                <?= $action==='create'?__('new_role'):__('edit_role') ?>
                <?php if (!empty($role['is_system'])): ?>
                    <span class="badge bg-light text-dark border ms-2"><i class="fa-solid fa-lock me-1"></i><?= __('system_role') ?></span>
                <?php endif; ?>
            </h4>
        </div>

        <form method="post" id="role-form">
            <?= csrf_field() ?>

            <!-- Identity card -->
            <div class="card mb-3 shadow-sm">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><i class="fa-solid fa-tag text-muted me-1"></i><?= __('name') ?> *</label>
                            <input name="name" class="form-control" required value="<?= e($role['name']) ?>" placeholder="<?= __('role_name_placeholder') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold"><i class="fa-solid fa-hashtag text-muted me-1"></i><?= __('slug') ?></label>
                            <input name="slug" class="form-control font-monospace" value="<?= e($role['slug']) ?>"
                                   <?= !empty($role['is_system']) ? 'readonly' : '' ?> placeholder="auto">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold"><i class="fa-solid fa-align-left text-muted me-1"></i><?= __('description') ?></label>
                            <input name="description" class="form-control" value="<?= e($role['description']) ?>" placeholder="<?= __('role_desc_placeholder') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Permissions header / control bar -->
            <div class="perms-bar">
                <div class="perms-summary">
                    <div class="perms-summary-icon"><i class="fa-solid fa-key"></i></div>
                    <div>
                        <div class="perms-summary-label"><?= __('permissions') ?></div>
                        <div class="perms-summary-value">
                            <span id="perm-selected"><?= $selectedCount ?></span>
                            <span class="text-muted">/ <?= $totalPerms ?></span>
                        </div>
                    </div>
                </div>
                <div class="perms-bar-actions">
                    <div class="perm-search">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="search" id="perm-search" class="form-control form-control-sm" placeholder="<?= __('search_permissions') ?>">
                    </div>
                    <button type="button" class="btn btn-light btn-sm" id="perm-all">
                        <i class="fa-solid fa-check-double me-1"></i><?= __('select_all') ?>
                    </button>
                    <button type="button" class="btn btn-light btn-sm" id="perm-none">
                        <i class="fa-solid fa-eraser me-1"></i><?= __('clear_all') ?>
                    </button>
                </div>
            </div>

            <!-- Permissions modules grid -->
            <div class="perms-modules">
                <?php foreach ($byModule as $module => $perms):
                    $meta = $moduleMeta[$module] ?? ['icon'=>'fa-cube','color'=>'#64748b'];
                    $modSelected = count(array_intersect(array_column($perms,'id'),$rolePermIds));
                ?>
                    <div class="perm-mod" data-module="<?= e($module) ?>">
                        <div class="perm-mod-head">
                            <div class="perm-mod-title">
                                <div class="perm-mod-icon" style="background:<?= $meta['color'] ?>"><i class="fa-solid <?= e($meta['icon']) ?>"></i></div>
                                <div>
                                    <div class="perm-mod-name"><?= __($module) ?: ucfirst(str_replace('_',' ',$module)) ?></div>
                                    <div class="perm-mod-count">
                                        <span class="perm-mod-selected"><?= $modSelected ?></span>
                                        <span class="text-muted"> / <?= count($perms) ?></span>
                                    </div>
                                </div>
                            </div>
                            <label class="perm-mod-toggle" title="<?= __('select_all') ?>">
                                <input type="checkbox" class="perm-mod-all" data-module="<?= e($module) ?>"
                                    <?= $modSelected === count($perms) ? 'checked' : '' ?>>
                                <span class="perm-mod-toggle-ui"></span>
                            </label>
                        </div>
                        <div class="perm-mod-grid">
                            <?php foreach ($perms as $p):
                                $action = explode('.', $p['slug'])[1] ?? '';
                                $actIcon = $actionIcons[$action] ?? 'fa-circle-dot';
                                $checked = in_array($p['id'], $rolePermIds);
                            ?>
                                <label class="perm-chip <?= $checked?'is-on':'' ?>" data-search="<?= e($p['slug'].' '.$module) ?>">
                                    <input type="checkbox" class="perm-checkbox perm-<?= e($p['module']) ?>"
                                           name="permissions[]" value="<?= (int)$p['id'] ?>"
                                           data-module="<?= e($module) ?>"
                                           <?= $checked?'checked':'' ?>>
                                    <i class="fa-solid <?= e($actIcon) ?> perm-chip-icon"></i>
                                    <span class="perm-chip-label"><?= e($action ?: $p['slug']) ?></span>
                                    <i class="fa-solid fa-check perm-chip-tick"></i>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Sticky save bar -->
            <div class="role-save-bar">
                <div>
                    <span class="text-muted small"><?= __('selected') ?>:</span>
                    <strong id="perm-selected-2"><?= $selectedCount ?></strong>
                    <span class="text-muted small"> / <?= $totalPerms ?></span>
                </div>
                <div class="d-flex gap-2">
                    <a class="btn btn-light" href="<?= BP_URL ?>admin/roles.php"><?= __('cancel') ?></a>
                    <button class="btn btn-teal"><i class="fa-solid fa-save me-1"></i><?= __('save') ?></button>
                </div>
            </div>
        </form>

    <style>
    .perms-bar{display:flex;align-items:center;gap:1rem;flex-wrap:wrap;background:linear-gradient(135deg,#f0fdfa 0%,#ecfeff 100%);border:1px solid #99f6e4;border-radius:12px;padding:.75rem 1rem;margin-bottom:1rem}
    .perms-summary{display:flex;align-items:center;gap:.75rem}
    .perms-summary-icon{width:42px;height:42px;border-radius:10px;background:#0d9488;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.2rem}
    .perms-summary-label{font-size:.75rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em}
    .perms-summary-value{font-size:1.4rem;font-weight:700;color:#0f172a;line-height:1.1}
    .perms-bar-actions{display:flex;gap:.5rem;align-items:center;margin-inline-start:auto;flex-wrap:wrap}
    .perm-search{position:relative}
    .perm-search i{position:absolute;top:50%;inset-inline-start:.6rem;transform:translateY(-50%);color:#94a3b8;font-size:.85rem;pointer-events:none}
    .perm-search input{padding-inline-start:2rem;min-width:220px}

    .perms-modules{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:1rem}
    .perm-mod{background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04);transition:.2s}
    .perm-mod:hover{box-shadow:0 4px 12px rgba(0,0,0,.06)}
    .perm-mod-head{display:flex;align-items:center;justify-content:space-between;padding:.85rem 1rem;border-bottom:1px solid #f1f5f9;background:#fafafa}
    .perm-mod-title{display:flex;align-items:center;gap:.7rem}
    .perm-mod-icon{width:38px;height:38px;border-radius:10px;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
    .perm-mod-name{font-weight:600;color:#0f172a;font-size:.95rem;text-transform:capitalize}
    .perm-mod-count{font-size:.8rem;font-weight:600;color:#0d9488}
    .perm-mod-toggle{position:relative;display:inline-block;cursor:pointer;margin:0}
    .perm-mod-toggle input{position:absolute;opacity:0;width:0;height:0}
    .perm-mod-toggle-ui{display:inline-block;width:38px;height:22px;border-radius:11px;background:#cbd5e1;position:relative;transition:.2s}
    .perm-mod-toggle-ui::before{content:'';position:absolute;top:2px;inset-inline-start:2px;width:18px;height:18px;border-radius:50%;background:#fff;transition:.2s;box-shadow:0 1px 3px rgba(0,0,0,.2)}
    .perm-mod-toggle input:checked + .perm-mod-toggle-ui{background:#0d9488}
    .perm-mod-toggle input:indeterminate + .perm-mod-toggle-ui{background:#f59e0b}
    .perm-mod-toggle input:indeterminate + .perm-mod-toggle-ui::before{inset-inline-start:9px}
    [dir="ltr"] .perm-mod-toggle input:checked + .perm-mod-toggle-ui::before{transform:translateX(16px)}
    [dir="rtl"] .perm-mod-toggle input:checked + .perm-mod-toggle-ui::before{transform:translateX(-16px)}
    .perm-mod-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:.4rem;padding:.75rem}

    .perm-chip{position:relative;display:flex;align-items:center;gap:.5rem;padding:.55rem .7rem;border:1px solid #e2e8f0;border-radius:9px;cursor:pointer;font-size:.85rem;color:#475569;transition:.15s;background:#fff;margin:0;user-select:none}
    .perm-chip:hover{border-color:#5eead4;background:#f0fdfa}
    .perm-chip input{position:absolute;opacity:0;pointer-events:none}
    .perm-chip-icon{font-size:.8rem;color:#94a3b8;transition:.15s}
    .perm-chip-label{flex:1;font-weight:500;text-transform:capitalize}
    .perm-chip-tick{font-size:.7rem;color:#0d9488;opacity:0;transition:.15s}
    .perm-chip.is-on{background:#0d9488;border-color:#0d9488;color:#fff}
    .perm-chip.is-on .perm-chip-icon{color:#fff}
    .perm-chip.is-on .perm-chip-tick{opacity:1;color:#fff}
    .perm-chip.is-hidden{display:none}
    .perm-mod.is-empty{display:none}

    .role-save-bar{position:sticky;bottom:0;background:#fff;border-top:1px solid #e2e8f0;padding:.75rem 1rem;display:flex;justify-content:space-between;align-items:center;margin:1.5rem -1rem -1rem;z-index:5;box-shadow:0 -4px 12px rgba(0,0,0,.04)}

    @media (max-width:640px){
        .perms-modules{grid-template-columns:1fr}
        .perms-bar-actions{width:100%}
        .perm-search input{min-width:0;width:100%}
        .perm-mod-grid{grid-template-columns:1fr 1fr}
    }
    </style>

    <script>
    (function(){
        const form = document.getElementById('role-form');
        if (!form) return;
        const checkboxes = form.querySelectorAll('.perm-checkbox');
        const selCount  = document.getElementById('perm-selected');
        const selCount2 = document.getElementById('perm-selected-2');
        const search    = document.getElementById('perm-search');

        function recountAll(){
            const total = checkboxes.length;
            const on = Array.from(checkboxes).filter(c => c.checked).length;
            selCount.textContent = on;
            selCount2.textContent = on;
            // Per-module
            document.querySelectorAll('.perm-mod').forEach(mod => {
                const m = mod.dataset.module;
                const inMod = mod.querySelectorAll('.perm-checkbox');
                const onMod = mod.querySelectorAll('.perm-checkbox:checked');
                mod.querySelector('.perm-mod-selected').textContent = onMod.length;
                const allCb = mod.querySelector('.perm-mod-all');
                allCb.checked = onMod.length === inMod.length && inMod.length>0;
                allCb.indeterminate = onMod.length>0 && onMod.length < inMod.length;
            });
        }

        function syncChip(cb){
            const chip = cb.closest('.perm-chip');
            chip.classList.toggle('is-on', cb.checked);
        }

        checkboxes.forEach(cb => {
            cb.addEventListener('change', () => { syncChip(cb); recountAll(); });
        });

        // Module-level select all
        document.querySelectorAll('.perm-mod-all').forEach(cb => {
            cb.addEventListener('change', () => {
                const mod = cb.dataset.module;
                form.querySelectorAll('.perm-'+mod).forEach(box => {
                    box.checked = cb.checked;
                    syncChip(box);
                });
                recountAll();
            });
        });

        // Global select / clear
        document.getElementById('perm-all').addEventListener('click', () => {
            checkboxes.forEach(c => { c.checked = true; syncChip(c); });
            recountAll();
        });
        document.getElementById('perm-none').addEventListener('click', () => {
            checkboxes.forEach(c => { c.checked = false; syncChip(c); });
            recountAll();
        });

        // Search filter (hides chips and empty modules)
        search.addEventListener('input', () => {
            const q = search.value.trim().toLowerCase();
            document.querySelectorAll('.perm-mod').forEach(mod => {
                let visible = 0;
                mod.querySelectorAll('.perm-chip').forEach(chip => {
                    const hay = (chip.dataset.search || '').toLowerCase();
                    const show = !q || hay.includes(q);
                    chip.classList.toggle('is-hidden', !show);
                    if (show) visible++;
                });
                const modName = (mod.dataset.module || '').toLowerCase();
                if (q && modName.includes(q)) {
                    mod.querySelectorAll('.perm-chip').forEach(c => c.classList.remove('is-hidden'));
                    visible = mod.querySelectorAll('.perm-chip').length;
                }
                mod.classList.toggle('is-empty', visible === 0);
            });
        });

        recountAll();
    })();
    </script>
    </div>
    <?php include BP_PARTIALS . '/footer.php'; exit;
}

$rows = db()->query("
    SELECT r.*, COUNT(rp.permission_id) AS perm_count, COUNT(DISTINCT ur.user_id) AS user_count
    FROM roles r
    LEFT JOIN role_permissions rp ON rp.role_id = r.id
    LEFT JOIN user_roles ur ON ur.role_id = r.id
    WHERE r.deleted_at IS NULL
    GROUP BY r.id
    ORDER BY r.is_system DESC, r.name ASC
")->fetchAll();

$kpi = ['total'=>count($rows),'system'=>0,'custom'=>0,'perms'=>0,'users'=>0];
foreach ($rows as $r) {
    if ($r['is_system']) $kpi['system']++; else $kpi['custom']++;
    $kpi['perms'] += (int)$r['perm_count'];
    $kpi['users'] += (int)$r['user_count'];
}
include BP_PARTIALS . '/header.php';
?>
<div class="page-wrap">
    <!-- Header -->
    <div class="page-header">
        <h4 class="m-0">
            <i class="fa-solid fa-shield-halved text-teal me-2"></i><?= __('roles') ?>
        </h4>
        <div class="page-header-actions">
            <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/users.php">
                <i class="fa-solid fa-users me-1"></i><?= __('users') ?>
            </a>
            <?php if (can('roles.create')): ?>
                <a href="?action=create" class="btn btn-teal btn-sm" data-modal>
                    <i class="fa-solid fa-plus me-1"></i><?= __('new_role') ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- KPI strip -->
    <div class="appt-kpis">
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#6366f1"><i class="fa-solid fa-shield-halved"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('total_roles') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['total'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#94a3b8"><i class="fa-solid fa-lock"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('system_roles') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['system'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#0d9488"><i class="fa-solid fa-key"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('total_permissions_assigned') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['perms'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#10b981"><i class="fa-solid fa-users"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('assigned_users') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['users'] ?></div>
            </div>
        </div>
    </div>
    <div class="table-card">
        <div class="table-responsive d-none d-md-block">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th>#</th><th><?= __('name') ?></th><th><?= __('slug') ?></th><th><?= __('description') ?></th>
                <th><?= __('permissions') ?></th><th>Users</th><th></th>
            </tr></thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td>
                        <?= e($r['name']) ?>
                        <?php if ($r['is_system']): ?><span class="badge bg-secondary ms-1">system</span><?php endif; ?>
                    </td>
                    <td><code class="small"><?= e($r['slug']) ?></code></td>
                    <td class="small text-muted"><?= e($r['description']) ?></td>
                    <td><span class="badge bg-info"><?= (int)$r['perm_count'] ?></span></td>
                    <td><span class="badge bg-light text-dark"><?= (int)$r['user_count'] ?></span></td>
                    <td class="text-end">
                        <?= render_actions([
                            (can('roles.edit') ? ['icon'=>'fa-pen','label'=>'edit','href'=>'?action=edit&id='.(int)$r['id'],'modal'=>true] : null),
                        ]) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <!-- Mobile entity card list -->
        <div class="entity-list d-md-none">
            <?php if (!$rows): ?>
                <div class="empty-state"><i class="fa-regular fa-user-shield"></i><div><?= __('no_data') ?></div></div>
            <?php else: foreach ($rows as $r):
                echo render_entity_card([
                    'avatar_icon' => 'fa-user-shield',
                    'avatar_class' => 'square indigo',
                    'title' => $r['name'],
                    'meta' => [$r['slug']],
                    'chips' => [
                        ['label'=>(int)$r['perm_count'].' '.__('permissions'),'icon'=>'fa-key','class'=>'info'],
                        ['label'=>(int)$r['user_count'].' '.__('users'),'icon'=>'fa-users','class'=>'teal'],
                        !empty($r['is_system']) ? ['label'=>'system','icon'=>'fa-shield','class'=>''] : null,
                    ],
                    'actions' => [
                        (can('roles.edit') ? ['icon'=>'fa-pen','label'=>'edit','href'=>'?action=edit&id='.(int)$r['id'],'modal'=>true] : null),
                    ],
                ]);
            endforeach; endif; ?>
        </div>
    </div>
</div>
<?php include BP_PARTIALS . '/footer.php'; ?>
