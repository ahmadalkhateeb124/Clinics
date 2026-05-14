<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('services.view');

$PageTitle = __('service_categories');
$action    = $_GET['action'] ?? 'list';
$id        = (int)($_GET['id'] ?? 0);

if ($action === 'delete' && $id) {
    require_can('services.delete');
    csrf_check();
    db()->prepare("UPDATE service_categories SET deleted_at=NOW() WHERE id=?")->execute([$id]);
    log_activity('deleted','service_categories',"Deleted category #$id",'service_category',$id);
    flash('success',__('category_deleted'));
    redirect(BP_URL . 'admin/categories.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action,['create','edit'],true)) {
    csrf_check();
    require_can($action==='create'?'services.create':'services.edit');

    $name_ar = trim($_POST['name_ar'] ?? '');
    $name_en = trim($_POST['name_en'] ?? '');
    $icon    = trim($_POST['icon'] ?? '');
    $desc_ar = trim($_POST['description_ar'] ?? '');
    $desc_en = trim($_POST['description_en'] ?? '');
    $sort    = (int)($_POST['sort_order'] ?? 0);
    $active  = isset($_POST['is_active']) ? 1 : 0;
    $slug    = slugify($name_en !== '' ? $name_en : $name_ar);

    if ($name_ar === '') { flash('error',__('name_required')); back(); }

    if ($action === 'create') {
        db()->prepare("INSERT INTO service_categories
            (name_ar,name_en,slug,icon,description_ar,description_en,sort_order,is_active,created_by,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())")
            ->execute([$name_ar,$name_en,$slug,$icon,$desc_ar,$desc_en,$sort,$active,$_SESSION['user_id']]);
        log_activity('created','service_categories',"Created category $name_ar",'service_category',(int)db()->lastInsertId());
    } else {
        db()->prepare("UPDATE service_categories SET
            name_ar=?,name_en=?,slug=?,icon=?,description_ar=?,description_en=?,sort_order=?,is_active=?,updated_by=?,updated_at=NOW()
            WHERE id=?")
            ->execute([$name_ar,$name_en,$slug,$icon,$desc_ar,$desc_en,$sort,$active,$_SESSION['user_id'],$id]);
        log_activity('updated','service_categories',"Updated category #$id",'service_category',$id);
    }
    flash('success',__('saved'));
    redirect(BP_URL . 'admin/categories.php');
}

if (in_array($action,['create','edit'],true)) {
    require_can($action==='create'?'services.create':'services.edit');
    $cat = ['name_ar'=>'','name_en'=>'','icon'=>'','description_ar'=>'','description_en'=>'','sort_order'=>0,'is_active'=>1];
    if ($action === 'edit' && $id) {
        $s = db()->prepare("SELECT * FROM service_categories WHERE id=? AND deleted_at IS NULL");
        $s->execute([$id]);
        $cat = $s->fetch() ?: null;
        if (!$cat) { flash('error',__('not_found')); redirect(BP_URL.'admin/categories.php'); }
    }
    include BP_PARTIALS . '/header.php';
    ?>
    <div class="page-wrap">
        <h4 class="mb-3"><?= $action==='create'?__('create'):__('edit') ?> — <?= __('service_category') ?></h4>
        <form method="post" class="card"><div class="card-body">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label"><?= __('name_ar') ?></label>
                    <input name="name_ar" class="form-control" required value="<?= e($cat['name_ar']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?= __('name_en') ?></label>
                    <input name="name_en" class="form-control" value="<?= e($cat['name_en'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?= __('icon_class') ?></label>
                    <input name="icon" class="form-control" placeholder="fa-spa" value="<?= e($cat['icon'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('sort_order') ?></label>
                    <input name="sort_order" type="number" class="form-control" value="<?= (int)$cat['sort_order'] ?>">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <label class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" <?= !empty($cat['is_active'])?'checked':'' ?>>
                        <span class="form-check-label"><?= __('active') ?></span>
                    </label>
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?= __('description_ar') ?></label>
                    <textarea name="description_ar" class="form-control" rows="3"><?= e($cat['description_ar'] ?? '') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?= __('description_en') ?></label>
                    <textarea name="description_en" class="form-control" rows="3"><?= e($cat['description_en'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="mt-3 d-flex gap-2">
                <button class="btn btn-teal"><?= __('save') ?></button>
                <a class="btn btn-light" href="<?= BP_URL ?>admin/categories.php"><?= __('cancel') ?></a>
            </div>
        </div></form>
    </div>
    <?php include BP_PARTIALS . '/footer.php'; exit;
}

$page = (int)($_GET['page'] ?? 1);
[$perPage,$offset] = paginate_query($page,(int)setting('per_page','25'));

$tot = db()->query("SELECT COUNT(*) FROM service_categories WHERE deleted_at IS NULL")->fetchColumn();
$rows = db()->query("
    SELECT c.*, COUNT(s.id) AS service_count
    FROM service_categories c
    LEFT JOIN services s ON s.category_id = c.id AND s.deleted_at IS NULL
    WHERE c.deleted_at IS NULL
    GROUP BY c.id
    ORDER BY c.sort_order, c.name_ar
    LIMIT $perPage OFFSET $offset
")->fetchAll();

// KPI stats
$kpi = db()->query("
    SELECT
      COUNT(*) AS total,
      SUM(is_active=1) AS active_count,
      (SELECT COUNT(*) FROM services WHERE deleted_at IS NULL AND is_active=1) AS svc_count
    FROM service_categories WHERE deleted_at IS NULL
")->fetch() ?: ['total'=>0,'active_count'=>0,'svc_count'=>0];

include BP_PARTIALS . '/header.php';
?>
<div class="page-wrap">
    <!-- Header -->
    <div class="page-header">
        <h4 class="m-0">
            <i class="fa-solid fa-tags text-teal me-2"></i><?= __('service_categories') ?>
            <span class="page-count">(<?= (int)$tot ?>)</span>
        </h4>
        <div class="page-header-actions">
            <a href="<?= BP_URL ?>admin/services.php" class="btn btn-light btn-sm">
                <i class="fa-solid fa-hand-holding-medical me-1"></i><?= __('services') ?>
            </a>
            <?php if (can('services.create')): ?>
                <a href="?action=create" class="btn btn-teal btn-sm" data-modal>
                    <i class="fa-solid fa-plus me-1"></i><?= __('new_category') ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- KPI strip -->
    <div class="appt-kpis">
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#0d9488"><i class="fa-solid fa-tags"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('total_categories') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['total'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#10b981"><i class="fa-solid fa-circle-check"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('active') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['active_count'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#6366f1"><i class="fa-solid fa-hand-holding-medical"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('total_services') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['svc_count'] ?></div>
            </div>
        </div>
    </div>

    <div class="table-card">
        <div class="table-responsive d-none d-md-block">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th>#</th><th><?= __('icon') ?></th><th><?= __('name_ar') ?></th><th><?= __('name_en') ?></th>
                <th><?= __('services') ?></th><th><?= __('status') ?></th><th></th>
            </tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="7" class="text-center text-muted py-4"><?= __('no_data') ?></td></tr>
            <?php else: foreach ($rows as $c): ?>
                <tr>
                    <td><?= (int)$c['id'] ?></td>
                    <td><?php if ($c['icon']): ?><i class="fa-solid <?= e($c['icon']) ?> text-teal"></i><?php endif; ?></td>
                    <td><?= e($c['name_ar']) ?></td>
                    <td class="text-muted small"><?= e($c['name_en'] ?? '—') ?></td>
                    <td><span class="badge bg-light text-dark"><?= (int)$c['service_count'] ?></span></td>
                    <td>
                        <span class="badge bg-<?= $c['is_active']?'success':'secondary' ?>">
                            <?= __($c['is_active']?'active':'inactive') ?>
                        </span>
                    </td>
                    <td class="text-end">
                        <?= render_actions([
                            (can('services.edit') ? ['icon'=>'fa-pen','label'=>'edit','href'=>'?action=edit&id='.(int)$c['id'],'modal'=>true] : null),
                            (can('services.delete') ? ['icon'=>'fa-trash','label'=>'delete','href'=>'?action=delete&id='.(int)$c['id'].'&_csrf='.e(csrf_token()),'danger'=>true,'divider_before'=>true,'confirm'=>'are_you_sure'] : null),
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
                <div class="empty-state"><i class="fa-regular fa-folder"></i><div><?= __('no_data') ?></div></div>
            <?php else: foreach ($rows as $c):
                echo render_entity_card([
                    'avatar_icon' => !empty($c['icon']) ? $c['icon'] : 'fa-folder',
                    'avatar_class' => 'square ' . ($c['is_active']?'':'slate'),
                    'title' => $c['name_ar'],
                    'meta' => $c['name_en'] ? [$c['name_en']] : [],
                    'chips' => [
                        ['label'=>__($c['is_active']?'active':'inactive'),'icon'=>'fa-circle-dot','class'=>$c['is_active']?'success':''],
                        ['label'=>(int)$c['service_count'].' '.__('services'),'icon'=>'fa-list','class'=>'teal'],
                    ],
                    'actions' => [
                        (can('services.edit') ? ['icon'=>'fa-pen','label'=>'edit','href'=>'?action=edit&id='.(int)$c['id'],'modal'=>true] : null),
                        (can('services.delete') ? ['icon'=>'fa-trash','label'=>'delete','href'=>'?action=delete&id='.(int)$c['id'].'&_csrf='.csrf_token(),'danger'=>true,'divider_before'=>true,'confirm'=>'are_you_sure'] : null),
                    ],
                ]);
            endforeach; endif; ?>
        </div>
    </div>
</div>
<?php include BP_PARTIALS . '/footer.php'; ?>
