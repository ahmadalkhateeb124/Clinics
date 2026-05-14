<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('expenses.view');

$PageTitle = __('expense_categories');
$action    = $_GET['action'] ?? 'list';
$id        = (int)($_GET['id'] ?? 0);

if ($action === 'delete' && $id) {
    csrf_check();
    require_can('expenses.delete');
    db()->prepare("UPDATE expense_categories SET deleted_at=NOW() WHERE id=?")->execute([$id]);
    log_activity('deleted','expense_categories',"Deleted category #$id",'expense_category',$id);
    flash('success', __('category_deleted'));
    redirect(BP_URL.'admin/expense-categories.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['create','edit'], true)) {
    csrf_check();
    require_can($action==='create'?'expenses.create':'expenses.edit');

    $ar  = trim($_POST['name_ar'] ?? '');
    $en  = trim($_POST['name_en'] ?? '');
    $ic  = trim($_POST['icon'] ?? '');
    $sort= (int)($_POST['sort_order'] ?? 0);
    $act = isset($_POST['is_active']) ? 1 : 0;
    $slug= slugify($en !== '' ? $en : $ar);

    if ($ar === '') { flash('error', __('name_required')); back(); }

    if ($action==='create') {
        db()->prepare("INSERT INTO expense_categories (name_ar,name_en,slug,icon,sort_order,is_active,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())")
            ->execute([$ar,$en,$slug,$ic,$sort,$act]);
    } else {
        db()->prepare("UPDATE expense_categories SET name_ar=?,name_en=?,slug=?,icon=?,sort_order=?,is_active=?,updated_at=NOW() WHERE id=?")
            ->execute([$ar,$en,$slug,$ic,$sort,$act,$id]);
    }
    flash('success', __('saved'));
    redirect(BP_URL.'admin/expense-categories.php');
}

if (in_array($action,['create','edit'],true)) {
    require_can($action==='create'?'expenses.create':'expenses.edit');
    $c = ['name_ar'=>'','name_en'=>'','icon'=>'','sort_order'=>0,'is_active'=>1];
    if ($action==='edit' && $id) {
        $s = db()->prepare("SELECT * FROM expense_categories WHERE id=? AND deleted_at IS NULL");
        $s->execute([$id]); $c = $s->fetch();
        if (!$c) { flash('error', __('not_found')); redirect(BP_URL.'admin/expense-categories.php'); }
    }
    include BP_PARTIALS . '/header.php';
    ?>
    <div class="page-wrap">
        <h4 class="mb-3"><?= $action==='create'?__('create'):__('edit') ?> — Expense Category</h4>
        <form method="post" class="card"><div class="card-body">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label"><?= __('name_ar') ?></label>
                    <input name="name_ar" class="form-control" required value="<?= e($c['name_ar']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?= __('name_en') ?></label>
                    <input name="name_en" class="form-control" value="<?= e($c['name_en']??'') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Icon (FA class)</label>
                    <input name="icon" class="form-control" placeholder="fa-house" value="<?= e($c['icon']??'') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('sort') ?></label>
                    <input name="sort_order" type="number" class="form-control" value="<?= (int)$c['sort_order'] ?>">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <label class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" <?= !empty($c['is_active'])?'checked':'' ?>>
                        <span class="form-check-label"><?= __('active') ?></span>
                    </label>
                </div>
            </div>
            <div class="mt-3 d-flex gap-2">
                <button class="btn btn-teal"><?= __('save') ?></button>
                <a class="btn btn-light" href="<?= BP_URL ?>admin/expense-categories.php"><?= __('cancel') ?></a>
            </div>
        </div></form>
    </div>
    <?php include BP_PARTIALS . '/footer.php'; exit;
}

$rows = db()->query("
    SELECT c.*, COUNT(e.id) AS cnt
    FROM expense_categories c
    LEFT JOIN expenses e ON e.category_id = c.id AND e.deleted_at IS NULL
    WHERE c.deleted_at IS NULL
    GROUP BY c.id ORDER BY c.sort_order, c.name_ar
")->fetchAll();

// KPI stats
$kpi = db()->query("
    SELECT
      COUNT(*) AS total,
      SUM(is_active=1) AS active_count,
      (SELECT COUNT(*) FROM expenses WHERE deleted_at IS NULL) AS exp_count
    FROM expense_categories WHERE deleted_at IS NULL
")->fetch() ?: ['total'=>0,'active_count'=>0,'exp_count'=>0];

include BP_PARTIALS . '/header.php';
?>
<div class="page-wrap">
    <!-- Header -->
    <div class="page-header">
        <h4 class="m-0">
            <i class="fa-solid fa-tags text-teal me-2"></i><?= __('expense_categories') ?>
            <span class="page-count">(<?= count($rows) ?>)</span>
        </h4>
        <div class="page-header-actions">
            <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/expenses.php">
                <i class="fa-solid fa-receipt me-1"></i><?= __('expenses') ?>
            </a>
            <?php if (can('expenses.create')): ?>
                <a class="btn btn-teal btn-sm" href="?action=create" data-modal>
                    <i class="fa-solid fa-plus me-1"></i><?= __('new_category') ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- KPI strip -->
    <div class="appt-kpis">
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#b45309"><i class="fa-solid fa-tags"></i></div>
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
            <div class="appt-kpi-icon" style="background:#6366f1"><i class="fa-solid fa-receipt"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('total_expenses') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['exp_count'] ?></div>
            </div>
        </div>
    </div>

    <div class="table-card">
        <div class="table-responsive d-none d-md-block">
        <table class="table mb-0 align-middle">
            <thead><tr><th>#</th><th><?= __('icon') ?></th><th><?= __('name_ar') ?></th><th><?= __('name_en') ?></th><th><?= __('expenses') ?></th><th><?= __('status') ?></th><th></th></tr></thead>
            <tbody>
                <?php foreach ($rows as $c): ?>
                    <tr>
                        <td><?= (int)$c['id'] ?></td>
                        <td><?php if ($c['icon']): ?><i class="fa-solid <?= e($c['icon']) ?> text-teal"></i><?php endif; ?></td>
                        <td><?= e($c['name_ar']) ?></td>
                        <td class="text-muted small"><?= e($c['name_en']??'—') ?></td>
                        <td><span class="badge bg-light text-dark"><?= (int)$c['cnt'] ?></span></td>
                        <td><span class="badge bg-<?= $c['is_active']?'success':'secondary' ?>"><?= __($c['is_active']?'active':'inactive') ?></span></td>
                        <td class="text-end">
                            <?= render_actions([
                                (can('expenses.edit') ? ['icon'=>'fa-pen','label'=>'edit','href'=>'?action=edit&id='.(int)$c['id'],'modal'=>true] : null),
                                (can('expenses.delete') ? ['icon'=>'fa-trash','label'=>'delete','href'=>'?action=delete&id='.(int)$c['id'].'&_csrf='.e(csrf_token()),'danger'=>true,'divider_before'=>true,'confirm'=>'are_you_sure'] : null),
                            ]) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4"><?= __('no_data') ?></td></tr><?php endif; ?>
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
                    'avatar_class' => 'square ' . ($c['is_active']?'rose':'slate'),
                    'title' => $c['name_ar'],
                    'meta' => $c['name_en'] ? [$c['name_en']] : [],
                    'chips' => [
                        ['label'=>__($c['is_active']?'active':'inactive'),'icon'=>'fa-circle-dot','class'=>$c['is_active']?'success':''],
                        ['label'=>(int)$c['cnt'].' '.__('expenses'),'icon'=>'fa-receipt','class'=>'teal'],
                    ],
                    'actions' => [
                        (can('expenses.edit') ? ['icon'=>'fa-pen','label'=>'edit','href'=>'?action=edit&id='.(int)$c['id'],'modal'=>true] : null),
                        (can('expenses.delete') ? ['icon'=>'fa-trash','label'=>'delete','href'=>'?action=delete&id='.(int)$c['id'].'&_csrf='.csrf_token(),'danger'=>true,'divider_before'=>true,'confirm'=>'are_you_sure'] : null),
                    ],
                ]);
            endforeach; endif; ?>
        </div>
    </div>
</div>
<?php include BP_PARTIALS . '/footer.php'; ?>
