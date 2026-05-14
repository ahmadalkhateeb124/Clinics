<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('cms.view');

$PageTitle = __('sliders');
$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

if ($action === 'delete' && $id) {
    csrf_check(); require_can('cms.delete');
    db()->prepare("UPDATE sliders SET deleted_at=NOW() WHERE id=?")->execute([$id]);
    flash('success','Deleted.'); redirect(BP_URL.'admin/sliders.php');
}

if ($_SERVER['REQUEST_METHOD']==='POST' && in_array($action,['create','edit'],true)) {
    csrf_check(); require_can($action==='create'?'cms.create':'cms.edit');
    $tAr = trim($_POST['title_ar'] ?? '');
    $tEn = trim($_POST['title_en'] ?? '');
    $sAr = trim($_POST['subtitle_ar'] ?? '');
    $sEn = trim($_POST['subtitle_en'] ?? '');
    $url = trim($_POST['link_url'] ?? '');
    $btn = trim($_POST['link_text'] ?? '');
    $sort= (int)($_POST['sort_order'] ?? 0);
    $act = isset($_POST['is_active']) ? 1 : 0;
    if ($tAr === '') { flash('error','Title required.'); back(); }

    $img = null;
    if (!empty($_FILES['image']['name'])) {
        $up = upload_file($_FILES['image'],'sliders',['jpg','jpeg','png','webp'], 4*1024*1024);
        if ($up) $img = $up['relative_path'];
    }

    if ($action==='create') {
        db()->prepare("INSERT INTO sliders (title_ar,title_en,subtitle_ar,subtitle_en,image,link_url,link_text,is_active,sort_order,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())")
            ->execute([$tAr,$tEn,$sAr,$sEn,$img,$url,$btn,$act,$sort]);
    } else {
        $sql = "UPDATE sliders SET title_ar=?,title_en=?,subtitle_ar=?,subtitle_en=?,link_url=?,link_text=?,is_active=?,sort_order=?,updated_at=NOW()";
        $params = [$tAr,$tEn,$sAr,$sEn,$url,$btn,$act,$sort];
        if ($img) { $sql .= ",image=?"; $params[] = $img; }
        $sql .= " WHERE id=?"; $params[] = $id;
        db()->prepare($sql)->execute($params);
    }
    flash('success','Saved.'); redirect(BP_URL.'admin/sliders.php');
}

if (in_array($action,['create','edit'],true)) {
    require_can($action==='create'?'cms.create':'cms.edit');
    $sl = ['title_ar'=>'','title_en'=>'','subtitle_ar'=>'','subtitle_en'=>'','link_url'=>'','link_text'=>'','sort_order'=>0,'is_active'=>1,'image'=>''];
    if ($action==='edit' && $id) {
        $s = db()->prepare("SELECT * FROM sliders WHERE id=? AND deleted_at IS NULL");
        $s->execute([$id]); $sl = $s->fetch();
    }
    include BP_PARTIALS.'/header.php'; ?>
    <div class="page-wrap"><h4 class="mb-3"><?= $action==='create'?__('create'):__('edit') ?> — Slider</h4>
    <form method="post" enctype="multipart/form-data" class="card"><div class="card-body">
        <?= csrf_field() ?>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label"><?= __('title_ar') ?> *</label><input name="title_ar" required class="form-control" value="<?= e($sl['title_ar']) ?>"></div>
            <div class="col-md-6"><label class="form-label"><?= __('title_en') ?></label><input name="title_en" class="form-control" value="<?= e($sl['title_en']??'') ?>"></div>
            <div class="col-md-6"><label class="form-label"><?= __('subtitle_ar') ?></label><input name="subtitle_ar" class="form-control" value="<?= e($sl['subtitle_ar']??'') ?>"></div>
            <div class="col-md-6"><label class="form-label"><?= __('subtitle_en') ?></label><input name="subtitle_en" class="form-control" value="<?= e($sl['subtitle_en']??'') ?>"></div>
            <div class="col-md-4"><label class="form-label"><?= __('link_url') ?></label><input name="link_url" class="form-control" value="<?= e($sl['link_url']??'') ?>"></div>
            <div class="col-md-4"><label class="form-label"><?= __('button_text') ?></label><input name="link_text" class="form-control" value="<?= e($sl['link_text']??'') ?>"></div>
            <div class="col-md-2"><label class="form-label"><?= __('sort') ?></label><input type="number" name="sort_order" class="form-control" value="<?= (int)$sl['sort_order'] ?>"></div>
            <div class="col-md-2 d-flex align-items-end"><label class="form-check"><input class="form-check-input" type="checkbox" name="is_active" <?= !empty($sl['is_active'])?'checked':'' ?>><span class="form-check-label"><?= __('active') ?></span></label></div>
            <div class="col-md-6"><label class="form-label"><?= __('image') ?></label><input type="file" name="image" class="form-control">
                <?php if (!empty($sl['image'])): ?><img src="<?= UPLOADS_URL.e($sl['image']) ?>" class="mt-2 rounded" style="max-height:80px"><?php endif; ?>
            </div>
        </div>
        <div class="mt-3"><button class="btn btn-teal"><?= __('save') ?></button> <a class="btn btn-light" href="<?= BP_URL ?>admin/sliders.php"><?= __('cancel') ?></a></div>
    </div></form></div>
    <?php include BP_PARTIALS.'/footer.php'; exit;
}

$rows = db()->query("SELECT * FROM sliders WHERE deleted_at IS NULL ORDER BY sort_order, id")->fetchAll();
$kpi = ['total'=>count($rows),'active'=>0,'inactive'=>0,'with_link'=>0];
foreach ($rows as $r) {
    if ($r['is_active']) $kpi['active']++; else $kpi['inactive']++;
    if (!empty($r['link_url'])) $kpi['with_link']++;
}
include BP_PARTIALS.'/header.php';
?>
<div class="page-wrap">
    <!-- Header -->
    <div class="page-header">
        <h4 class="m-0">
            <i class="fa-solid fa-images text-teal me-2"></i><?= __('sliders') ?>
        </h4>
        <div class="page-header-actions">
            <?php if (can('cms.create')): ?>
                <a class="btn btn-teal btn-sm" href="?action=create" data-modal>
                    <i class="fa-solid fa-plus me-1"></i><?= __('new_slider') ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- KPI strip -->
    <div class="appt-kpis">
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#6366f1"><i class="fa-solid fa-images"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('total_sliders') ?></div>
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
            <div class="appt-kpi-icon" style="background:#0ea5e9"><i class="fa-solid fa-link"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('with_link') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['with_link'] ?></div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <?php foreach ($rows as $sl): ?>
            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <?php if ($sl['image']): ?>
                        <img src="<?= UPLOADS_URL.e($sl['image']) ?>" class="card-img-top" style="height:160px;object-fit:cover">
                    <?php else: ?>
                        <div class="d-flex align-items-center justify-content-center bg-light text-muted" style="height:160px"><i class="fa-regular fa-image fa-2x"></i></div>
                    <?php endif; ?>
                    <div class="card-body">
                        <div class="d-flex align-items-start justify-content-between mb-2">
                            <h6 class="m-0"><?= e($sl['title_ar']) ?></h6>
                            <span class="badge bg-<?= $sl['is_active']?'success':'secondary' ?>"><?= __($sl['is_active']?'active':'inactive') ?></span>
                        </div>
                        <p class="small text-muted mb-2"><?= e($sl['subtitle_ar']??'') ?></p>
                        <?php if (!empty($sl['link_url'])): ?>
                            <div class="small mb-2"><i class="fa-solid fa-link text-muted me-1"></i><code class="small text-truncate d-inline-block" style="max-width:200px"><?= e($sl['link_url']) ?></code></div>
                        <?php endif; ?>
                        <div class="d-flex gap-2 mt-2">
                            <?php if (can('cms.edit')): ?><a class="btn btn-sm btn-light flex-grow-1" href="?action=edit&id=<?= (int)$sl['id'] ?>" data-modal><i class="fa-solid fa-pen me-1"></i><?= __('edit') ?></a><?php endif; ?>
                            <?php if (can('cms.delete')): ?><a class="btn btn-sm btn-outline-danger" data-confirm="<?= __('are_you_sure') ?>" href="?action=delete&id=<?= (int)$sl['id'] ?>&_csrf=<?= e(csrf_token()) ?>"><i class="fa-solid fa-trash"></i></a><?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <div class="col-12">
                <div class="empty-state py-5"><i class="fa-regular fa-images"></i><div><?= __('no_data') ?></div></div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php include BP_PARTIALS.'/footer.php'; ?>
