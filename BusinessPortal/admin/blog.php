<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('cms.view');

$PageTitle = __('blog');
$action    = $_GET['action'] ?? 'list';
$id        = (int)($_GET['id'] ?? 0);

if ($action === 'delete' && $id) {
    csrf_check(); require_can('cms.delete');
    db()->prepare("UPDATE blog_posts SET deleted_at=NOW() WHERE id=?")->execute([$id]);
    log_activity('deleted','cms',"Deleted post #$id",'blog_post',$id);
    flash('success','Post deleted.'); redirect(BP_URL.'admin/blog.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action,['create','edit'],true)) {
    csrf_check(); require_can($action==='create'?'cms.create':'cms.edit');

    $catId   = (int)($_POST['category_id'] ?? 0) ?: null;
    $titleAr = trim($_POST['title_ar'] ?? '');
    $titleEn = trim($_POST['title_en'] ?? '');
    $slug    = trim($_POST['slug'] ?? '') ?: slugify($titleEn ?: $titleAr);
    $excAr   = trim($_POST['excerpt_ar'] ?? '');
    $excEn   = trim($_POST['excerpt_en'] ?? '');
    $cntAr   = $_POST['content_ar'] ?? '';
    $cntEn   = $_POST['content_en'] ?? '';
    $metaT   = trim($_POST['meta_title_ar'] ?? '');
    $metaD   = trim($_POST['meta_description_ar'] ?? '');
    $metaK   = trim($_POST['meta_keywords_ar'] ?? '');
    $status  = $_POST['status'] ?? 'draft';

    if ($titleAr === '') { flash('error','Title required.'); back(); }
    if (!in_array($status, ['draft','published','archived'], true)) $status = 'draft';
    if ($status === 'published' && !can('cms.publish')) $status = 'draft';

    $img = null;
    if (!empty($_FILES['featured_image']['name'])) {
        $up = upload_file($_FILES['featured_image'], 'blog', ['jpg','jpeg','png','webp'], 4*1024*1024);
        if ($up) $img = $up['relative_path'];
    }

    if ($action === 'create') {
        db()->prepare("INSERT INTO blog_posts
            (category_id,author_id,title_ar,title_en,slug,excerpt_ar,excerpt_en,content_ar,content_en,
             featured_image,meta_title_ar,meta_description_ar,meta_keywords_ar,status,published_at,
             created_by,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, IF(?='published',NOW(),NULL), ?,NOW(),NOW())")
            ->execute([$catId,$_SESSION['user_id'],$titleAr,$titleEn,$slug,$excAr,$excEn,$cntAr,$cntEn,
                       $img,$metaT,$metaD,$metaK,$status,$status,$_SESSION['user_id']]);
        $pid = (int)db()->lastInsertId();
        log_activity('created','cms',"Created post #$pid",'blog_post',$pid);
    } else {
        $sql = "UPDATE blog_posts SET category_id=?,title_ar=?,title_en=?,slug=?,excerpt_ar=?,excerpt_en=?,
                content_ar=?,content_en=?,meta_title_ar=?,meta_description_ar=?,meta_keywords_ar=?,
                status=?, published_at = IF(?='published' AND published_at IS NULL, NOW(), published_at),
                updated_by=?,updated_at=NOW()";
        $params = [$catId,$titleAr,$titleEn,$slug,$excAr,$excEn,$cntAr,$cntEn,$metaT,$metaD,$metaK,$status,$status,$_SESSION['user_id']];
        if ($img) { $sql .= ",featured_image=?"; $params[] = $img; }
        $sql .= " WHERE id=?";
        $params[] = $id;
        db()->prepare($sql)->execute($params);
        log_activity('updated','cms',"Updated post #$id",'blog_post',$id);
    }
    flash('success','Post saved.');
    redirect(BP_URL.'admin/blog.php');
}

if (in_array($action,['create','edit'],true)) {
    require_can($action==='create'?'cms.create':'cms.edit');
    $p = ['category_id'=>0,'title_ar'=>'','title_en'=>'','slug'=>'','excerpt_ar'=>'','excerpt_en'=>'',
          'content_ar'=>'','content_en'=>'','meta_title_ar'=>'','meta_description_ar'=>'','meta_keywords_ar'=>'',
          'status'=>'draft','featured_image'=>''];
    if ($action==='edit' && $id) {
        $s = db()->prepare("SELECT * FROM blog_posts WHERE id=? AND deleted_at IS NULL");
        $s->execute([$id]); $p = $s->fetch();
        if (!$p) { flash('error','Not found.'); redirect(BP_URL.'admin/blog.php'); }
    }
    $cats = db()->query("SELECT id,name_ar FROM blog_categories WHERE deleted_at IS NULL AND is_active=1 ORDER BY sort_order")->fetchAll();
    include BP_PARTIALS . '/header.php';
    ?>
    <div class="page-wrap">
        <h4 class="mb-3"><?= $action==='create'?__('create'):__('edit') ?> — Blog post</h4>
        <form method="post" enctype="multipart/form-data" class="card"><div class="card-body">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label">Title (AR) *</label>
                    <input name="title_ar" required class="form-control" value="<?= e($p['title_ar']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label"><?= __('status') ?></label>
                    <select name="status" class="form-select">
                        <?php foreach (['draft','published','archived'] as $s): ?>
                            <option value="<?= $s ?>" <?= $p['status']===$s?'selected':'' ?>><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?= __('title_en') ?></label>
                    <input name="title_en" class="form-control" value="<?= e($p['title_en']??'') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('slug') ?></label>
                    <input name="slug" class="form-control" placeholder="auto" value="<?= e($p['slug']??'') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('category') ?></label>
                    <select name="category_id" class="form-select">
                        <option value="">—</option>
                        <?php foreach ($cats as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= (int)$p['category_id']===(int)$c['id']?'selected':'' ?>><?= e($c['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label"><?= __('excerpt_ar') ?></label>
                    <textarea name="excerpt_ar" rows="2" class="form-control"><?= e($p['excerpt_ar']??'') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?= __('excerpt_en') ?></label>
                    <textarea name="excerpt_en" rows="2" class="form-control"><?= e($p['excerpt_en']??'') ?></textarea>
                </div>

                <div class="col-md-6">
                    <label class="form-label"><?= __('content_ar') ?></label>
                    <textarea name="content_ar" id="contentAr" rows="10" class="form-control"><?= e($p['content_ar']??'') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?= __('content_en') ?></label>
                    <textarea name="content_en" id="contentEn" rows="10" class="form-control"><?= e($p['content_en']??'') ?></textarea>
                </div>

                <div class="col-md-4">
                    <label class="form-label"><?= __('featured_image') ?></label>
                    <input type="file" name="featured_image" class="form-control">
                    <?php if (!empty($p['featured_image'])): ?>
                        <img src="<?= UPLOADS_URL . e($p['featured_image']) ?>" class="mt-2 rounded" style="max-height:80px">
                    <?php endif; ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><?= __('meta_title') ?></label>
                    <input name="meta_title_ar" class="form-control" value="<?= e($p['meta_title_ar']??'') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label"><?= __('meta_keywords') ?></label>
                    <input name="meta_keywords_ar" class="form-control" value="<?= e($p['meta_keywords_ar']??'') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label"><?= __('meta_description') ?></label>
                    <textarea name="meta_description_ar" rows="2" class="form-control"><?= e($p['meta_description_ar']??'') ?></textarea>
                </div>
            </div>

            <div class="mt-3 d-flex gap-2">
                <button class="btn btn-teal"><?= __('save') ?></button>
                <a class="btn btn-light" href="<?= BP_URL ?>admin/blog.php"><?= __('cancel') ?></a>
            </div>
        </div></form>
    </div>

    <!-- TinyMCE WYSIWYG -->
    <script src="https://cdn.tiny.cloud/1/no-api-key/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
    <script>
    tinymce.init({
        selector: '#contentAr, #contentEn',
        height: 360,
        menubar: false,
        plugins: 'lists link image table code',
        toolbar: 'undo redo | bold italic underline | alignleft aligncenter alignright | bullist numlist | link image table | code',
        directionality: '#contentAr' === document.activeElement?.id ? 'rtl' : 'ltr',
    });
    </script>
    <?php include BP_PARTIALS . '/footer.php'; exit;
}

// LIST
$q       = trim($_GET['q'] ?? '');
$status  = trim($_GET['status'] ?? '');
$page    = (int)($_GET['page'] ?? 1);
[$perPage, $offset] = paginate_query($page, (int)setting('per_page','25'));

$where = "p.deleted_at IS NULL"; $params = [];
if ($q) { $where .= " AND p.title_ar LIKE ?"; $params[] = "%$q%"; }
if ($status) { $where .= " AND p.status = ?"; $params[] = $status; }

$tot = db()->prepare("SELECT COUNT(*) FROM blog_posts p WHERE $where");
$tot->execute($params); $total = (int)$tot->fetchColumn();

$rows = db()->prepare("
    SELECT p.*, c.name_ar AS cat
    FROM blog_posts p LEFT JOIN blog_categories c ON c.id = p.category_id
    WHERE $where ORDER BY p.id DESC LIMIT $perPage OFFSET $offset
");
$rows->execute($params); $rows = $rows->fetchAll();

// KPI stats
$kpi = db()->query("
    SELECT
      COUNT(*) AS total,
      SUM(status='published') AS published,
      SUM(status='draft')     AS drafts,
      COALESCE(SUM(views),0)  AS views
    FROM blog_posts WHERE deleted_at IS NULL
")->fetch() ?: ['total'=>0,'published'=>0,'drafts'=>0,'views'=>0];

include BP_PARTIALS . '/header.php';
?>
<div class="page-wrap">
    <!-- Header -->
    <div class="page-header">
        <h4 class="m-0">
            <i class="fa-solid fa-newspaper text-teal me-2"></i><?= __('blog_posts') ?>
            <small class="text-muted ms-2" style="font-size:.85rem">(<?= $total ?>)</small>
        </h4>
        <div class="page-header-actions">
            <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/blog-categories.php">
                <i class="fa-solid fa-tags me-1"></i><?= __('categories') ?>
            </a>
            <?php if (can('cms.create')): ?>
                <a class="btn btn-teal btn-sm" href="?action=create" data-modal>
                    <i class="fa-solid fa-plus me-1"></i><?= __('new_post') ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- KPI strip -->
    <div class="appt-kpis">
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#6366f1"><i class="fa-solid fa-newspaper"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('total_posts') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['total'] ?></div>
            </div>
        </div>
        <a class="appt-kpi" href="?status=published">
            <div class="appt-kpi-icon" style="background:#10b981"><i class="fa-solid fa-circle-check"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('st_published') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['published'] ?></div>
            </div>
        </a>
        <a class="appt-kpi" href="?status=draft">
            <div class="appt-kpi-icon" style="background:#94a3b8"><i class="fa-solid fa-pen-ruler"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('st_draft') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['drafts'] ?></div>
            </div>
        </a>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#0ea5e9"><i class="fa-solid fa-eye"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('total_views') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['views'] ?></div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <form method="get" class="appt-filters">
        <div class="appt-filter-group flex-grow-1">
            <input name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="<?= __('search_post_placeholder') ?>">
        </div>
        <div class="appt-filter-group">
            <select name="status" class="form-select form-select-sm">
                <option value=""><?= __('all_statuses') ?></option>
                <?php foreach (['draft','published','archived'] as $st): ?>
                    <option value="<?= $st ?>" <?= $status===$st?'selected':'' ?>><?= __('st_'.$st) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-teal btn-sm"><i class="fa-solid fa-filter me-1"></i><?= __('apply') ?></button>
        <?php if ($q||$status): ?><a class="btn btn-light btn-sm" href="?"><?= __('clear_filters') ?></a><?php endif; ?>
    </form>

    <div class="table-card">
        <div class="table-responsive d-none d-md-block">
        <table class="table mb-0 align-middle">
            <thead><tr><th>#</th><th><?= __('title') ?></th><th><?= __('category') ?></th><th><?= __('status') ?></th><th><?= __('views') ?></th><th><?= __('published') ?></th><th></th></tr></thead>
            <tbody>
                <?php foreach ($rows as $p):
                    $col = ['draft'=>'secondary','published'=>'success','archived'=>'warning'][$p['status']];
                ?>
                    <tr>
                        <td><?= (int)$p['id'] ?></td>
                        <td><?= e($p['title_ar']) ?>
                            <div class="small text-muted"><?= e($p['slug']) ?></div>
                        </td>
                        <td class="small"><?= e($p['cat']??'—') ?></td>
                        <td><span class="badge bg-<?= $col ?>"><?= __('st_'.$p['status']) ?: e($p['status']) ?></span></td>
                        <td><?= (int)$p['views'] ?></td>
                        <td class="small"><?= $p['published_at']?format_date($p['published_at'],'Y-m-d'):'—' ?></td>
                        <td class="text-end">
                            <?= render_actions([
                                ['icon'=>'fa-eye','label'=>'view','href'=>APP_URL.'blog/'.e($p['slug']),'target'=>'_blank'],
                                (can('cms.edit') ? ['icon'=>'fa-pen','label'=>'edit','href'=>'?action=edit&id='.(int)$p['id'],'modal'=>true] : null),
                                (can('cms.delete') ? ['icon'=>'fa-trash','label'=>'delete','href'=>'?action=delete&id='.(int)$p['id'].'&_csrf='.e(csrf_token()),'danger'=>true,'divider_before'=>true,'confirm'=>'are_you_sure'] : null),
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
                <div class="empty-state"><i class="fa-regular fa-newspaper"></i><div><?= __('no_data') ?></div></div>
            <?php else: foreach ($rows as $p):
                $statusChip = ['draft'=>'','published'=>'success','archived'=>'warn'][$p['status']] ?? '';
                $avatarColor = ['draft'=>'slate','published'=>'success','archived'=>'amber'][$p['status']] ?? '';
                $chips = [
                    ['label'=>__('st_'.$p['status']) ?: $p['status'],'icon'=>'fa-circle-dot','class'=>$statusChip],
                    ['label'=>(int)$p['views'].' '.__('views'),'icon'=>'fa-eye','class'=>'info'],
                ];
                if (!empty($p['cat'])) $chips[] = ['label'=>$p['cat'],'icon'=>'fa-tag','class'=>'teal'];
                echo render_entity_card([
                    'avatar_icon' => 'fa-newspaper',
                    'avatar_class' => 'square '.$avatarColor,
                    'title' => $p['title_ar'],
                    'meta' => [$p['slug'], $p['published_at']?format_date($p['published_at'],'Y-m-d'):'—'],
                    'chips' => $chips,
                    'actions' => [
                        ['icon'=>'fa-eye','label'=>'view','href'=>APP_URL.'blog/'.e($p['slug']),'target'=>'_blank'],
                        (can('cms.edit') ? ['icon'=>'fa-pen','label'=>'edit','href'=>'?action=edit&id='.(int)$p['id'],'modal'=>true] : null),
                        (can('cms.delete') ? ['icon'=>'fa-trash','label'=>'delete','href'=>'?action=delete&id='.(int)$p['id'].'&_csrf='.csrf_token(),'danger'=>true,'divider_before'=>true,'confirm'=>'are_you_sure'] : null),
                    ],
                ]);
            endforeach; endif; ?>
        </div>

        <div class="p-2"><?= render_pagination($total,$page,$perPage,BP_URL.'admin/blog.php?'.http_build_query(['q'=>$q,'status'=>$status])) ?></div>
    </div>
</div>
<?php include BP_PARTIALS . '/footer.php'; ?>
