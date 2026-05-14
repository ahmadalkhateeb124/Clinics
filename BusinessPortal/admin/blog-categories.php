<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('cms.view');

$PageTitle = __('blog_categories');
$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

if ($action === 'delete' && $id) {
    csrf_check(); require_can('cms.delete');
    db()->prepare("UPDATE blog_categories SET deleted_at=NOW() WHERE id=?")->execute([$id]);
    flash('success','Deleted.'); redirect(BP_URL.'admin/blog-categories.php');
}

if ($_SERVER['REQUEST_METHOD']==='POST' && in_array($action,['create','edit'],true)) {
    csrf_check(); require_can($action==='create'?'cms.create':'cms.edit');
    $ar = trim($_POST['name_ar'] ?? '');
    $en = trim($_POST['name_en'] ?? '');
    $sort = (int)($_POST['sort_order'] ?? 0);
    $active = isset($_POST['is_active']) ? 1 : 0;
    $slug = slugify($en ?: $ar);
    if ($ar === '') { flash('error','Name required.'); back(); }
    if ($action==='create') {
        db()->prepare("INSERT INTO blog_categories (name_ar,name_en,slug,sort_order,is_active,created_at,updated_at) VALUES (?,?,?,?,?,NOW(),NOW())")
            ->execute([$ar,$en,$slug,$sort,$active]);
    } else {
        db()->prepare("UPDATE blog_categories SET name_ar=?,name_en=?,slug=?,sort_order=?,is_active=?,updated_at=NOW() WHERE id=?")
            ->execute([$ar,$en,$slug,$sort,$active,$id]);
    }
    flash('success','Saved.'); redirect(BP_URL.'admin/blog-categories.php');
}

if (in_array($action,['create','edit'],true)) {
    require_can($action==='create'?'cms.create':'cms.edit');
    $c = ['name_ar'=>'','name_en'=>'','sort_order'=>0,'is_active'=>1];
    if ($action==='edit' && $id) {
        $s = db()->prepare("SELECT * FROM blog_categories WHERE id=? AND deleted_at IS NULL");
        $s->execute([$id]); $c = $s->fetch();
    }
    include BP_PARTIALS.'/header.php';
    ?>
    <div class="page-wrap"><h4 class="mb-3"><?= $action==='create'?__('create'):__('edit') ?> — Blog Category</h4>
    <form method="post" class="card"><div class="card-body">
        <?= csrf_field() ?>
        <div class="row g-3">
            <div class="col-md-5"><label class="form-label"><?= __('name_ar') ?></label><input name="name_ar" required class="form-control" value="<?= e($c['name_ar']) ?>"></div>
            <div class="col-md-5"><label class="form-label"><?= __('name_en') ?></label><input name="name_en" class="form-control" value="<?= e($c['name_en']??'') ?>"></div>
            <div class="col-md-2"><label class="form-label"><?= __('sort') ?></label><input name="sort_order" type="number" class="form-control" value="<?= (int)$c['sort_order'] ?>"></div>
            <div class="col-12"><label class="form-check"><input class="form-check-input" type="checkbox" name="is_active" <?= !empty($c['is_active'])?'checked':'' ?>><span class="form-check-label"><?= __('active') ?></span></label></div>
        </div>
        <div class="mt-3"><button class="btn btn-teal"><?= __('save') ?></button> <a class="btn btn-light" href="<?= BP_URL ?>admin/blog-categories.php"><?= __('cancel') ?></a></div>
    </div></form></div>
    <?php include BP_PARTIALS.'/footer.php'; exit;
}

$rows = db()->query("SELECT c.*, COUNT(p.id) AS posts FROM blog_categories c
    LEFT JOIN blog_posts p ON p.category_id=c.id AND p.deleted_at IS NULL
    WHERE c.deleted_at IS NULL GROUP BY c.id ORDER BY c.sort_order, c.name_ar")->fetchAll();

$kpi = ['total'=>count($rows),'active'=>0,'inactive'=>0,'posts'=>0];
foreach ($rows as $r) {
    if ($r['is_active']) $kpi['active']++; else $kpi['inactive']++;
    $kpi['posts'] += (int)$r['posts'];
}

include BP_PARTIALS.'/header.php';
?>
<div class="page-wrap">
    <!-- Header -->
    <div class="page-header">
        <h4 class="m-0">
            <i class="fa-solid fa-tags text-teal me-2"></i><?= __('blog_categories') ?>
        </h4>
        <div class="page-header-actions">
            <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/blog.php">
                <i class="fa-solid fa-newspaper me-1"></i><?= __('blog_posts') ?>
            </a>
            <?php if (can('cms.create')): ?>
                <a class="btn btn-teal btn-sm" href="?action=create" data-modal>
                    <i class="fa-solid fa-plus me-1"></i><?= __('new_category') ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- KPI strip -->
    <div class="appt-kpis">
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#6366f1"><i class="fa-solid fa-folder"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('total_categories') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['total'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#10b981"><i class="fa-solid fa-circle-check"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('active') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['active'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#94a3b8"><i class="fa-solid fa-circle-minus"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('inactive') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['inactive'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#0d9488"><i class="fa-solid fa-newspaper"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('total_posts') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['posts'] ?></div>
            </div>
        </div>
    </div>
    <div class="table-card">
        <div class="table-responsive d-none d-md-block">
        <table class="table mb-0 align-middle">
            <thead><tr><th>#</th><th><?= __('name_ar') ?></th><th>EN</th><th><?= __('slug') ?></th><th><?= __('posts') ?></th><th><?= __('status') ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $c): ?>
                <tr>
                    <td><?= (int)$c['id'] ?></td>
                    <td><?= e($c['name_ar']) ?></td>
                    <td class="small text-muted"><?= e($c['name_en']??'—') ?></td>
                    <td><code class="small"><?= e($c['slug']) ?></code></td>
                    <td><span class="badge bg-light text-dark"><?= (int)$c['posts'] ?></span></td>
                    <td><span class="badge bg-<?= $c['is_active']?'success':'secondary' ?>"><?= __($c['is_active']?'active':'inactive') ?></span></td>
                    <td class="text-end">
                        <?= render_actions([
                            (can('cms.edit') ? ['icon'=>'fa-pen','label'=>'edit','href'=>'?action=edit&id='.(int)$c['id'],'modal'=>true] : null),
                            (can('cms.delete') ? ['icon'=>'fa-trash','label'=>'delete','href'=>'?action=delete&id='.(int)$c['id'].'&_csrf='.csrf_token(),'danger'=>true,'divider_before'=>true,'confirm'=>'are_you_sure'] : null),
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
                    'avatar_icon' => 'fa-folder',
                    'avatar_class' => 'square ' . ($c['is_active']?'indigo':'slate'),
                    'title' => $c['name_ar'],
                    'meta' => [$c['slug']],
                    'chips' => [
                        ['label'=>__($c['is_active']?'active':'inactive'),'icon'=>'fa-circle-dot','class'=>$c['is_active']?'success':''],
                        ['label'=>(int)$c['posts'].' '.__('posts'),'icon'=>'fa-newspaper','class'=>'teal'],
                    ],
                    'actions' => [
                        (can('cms.edit') ? ['icon'=>'fa-pen','label'=>'edit','href'=>'?action=edit&id='.(int)$c['id'],'modal'=>true] : null),
                        (can('cms.delete') ? ['icon'=>'fa-trash','label'=>'delete','href'=>'?action=delete&id='.(int)$c['id'].'&_csrf='.csrf_token(),'danger'=>true,'divider_before'=>true,'confirm'=>'are_you_sure'] : null),
                    ],
                ]);
            endforeach; endif; ?>
        </div>
    </div>
</div>
<?php include BP_PARTIALS.'/footer.php'; ?>
