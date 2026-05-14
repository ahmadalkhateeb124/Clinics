<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('services.view');

$PageTitle = __('services');
$action    = $_GET['action'] ?? 'list';
$id        = (int)($_GET['id'] ?? 0);

if ($action === 'delete' && $id) {
    require_can('services.delete');
    csrf_check();
    db()->prepare("UPDATE services SET deleted_at=NOW() WHERE id=?")->execute([$id]);
    log_activity('deleted','services',"Deleted service #$id",'service',$id);
    flash('success', __('service_deleted'));
    redirect(BP_URL . 'admin/services.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action,['create','edit'],true)) {
    csrf_check();
    require_can($action==='create'?'services.create':'services.edit');

    $name_ar = trim($_POST['name_ar'] ?? '');
    $name_en = trim($_POST['name_en'] ?? '');
    $cat     = (int)($_POST['category_id'] ?? 0) ?: null;
    $duration = (int)($_POST['duration_minutes'] ?? 60);
    $price   = (float)($_POST['price'] ?? 0);
    $comm    = (float)($_POST['commission_pct'] ?? 0);
    $desc_ar = trim($_POST['description_ar'] ?? '');
    $desc_en = trim($_POST['description_en'] ?? '');
    $isCons  = isset($_POST['is_consultation']) ? 1 : 0;
    $active  = isset($_POST['is_active']) ? 1 : 0;
    $sort    = (int)($_POST['sort_order'] ?? 0);
    $slug    = slugify($name_en !== '' ? $name_en : $name_ar);

    if ($name_ar === '') { flash('error', __('err_name_ar_required')); back(); }
    if ($duration < 5 || $duration > 600) { flash('error', __('err_duration_range')); back(); }
    if ($price < 0) { flash('error', __('err_price_negative')); back(); }

    if ($action === 'create') {
        db()->prepare("INSERT INTO services
            (category_id,name_ar,name_en,slug,description_ar,description_en,duration_minutes,price,
             commission_pct,is_consultation,is_active,sort_order,created_by,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")
            ->execute([$cat,$name_ar,$name_en,$slug,$desc_ar,$desc_en,$duration,$price,$comm,$isCons,$active,$sort,$_SESSION['user_id']]);
        log_activity('created','services',"Created service $name_ar",'service',(int)db()->lastInsertId());
    } else {
        db()->prepare("UPDATE services SET
            category_id=?,name_ar=?,name_en=?,slug=?,description_ar=?,description_en=?,
            duration_minutes=?,price=?,commission_pct=?,is_consultation=?,is_active=?,sort_order=?,
            updated_by=?,updated_at=NOW() WHERE id=?")
            ->execute([$cat,$name_ar,$name_en,$slug,$desc_ar,$desc_en,$duration,$price,$comm,$isCons,$active,$sort,$_SESSION['user_id'],$id]);
        log_activity('updated','services',"Updated service #$id",'service',$id);
    }
    flash('success', __('saved'));
    redirect(BP_URL . 'admin/services.php');
}

if (in_array($action,['create','edit'],true)) {
    require_can($action==='create'?'services.create':'services.edit');
    $svc = ['category_id'=>null,'name_ar'=>'','name_en'=>'','description_ar'=>'','description_en'=>'',
            'duration_minutes'=>60,'price'=>0,'commission_pct'=>0,'is_consultation'=>0,'is_active'=>1,'sort_order'=>0];
    if ($action === 'edit' && $id) {
        $s = db()->prepare("SELECT * FROM services WHERE id=? AND deleted_at IS NULL");
        $s->execute([$id]);
        $svc = $s->fetch() ?: null;
        if (!$svc) { flash('error', __('not_found')); redirect(BP_URL.'admin/services.php'); }
    }
    $cats = db()->query("SELECT id,name_ar FROM service_categories WHERE deleted_at IS NULL ORDER BY sort_order")->fetchAll();
    include BP_PARTIALS . '/header.php';
    ?>
    <div class="page-wrap">
        <h4 class="mb-3"><?= $action==='create'?__('create'):__('edit') ?> — <?= __('services') ?></h4>
        <form method="post" class="card"><div class="card-body">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label"><?= __('name_ar') ?></label>
                    <input name="name_ar" class="form-control" required value="<?= e($svc['name_ar']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?= __('name_en') ?></label>
                    <input name="name_en" class="form-control" value="<?= e($svc['name_en'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label"><?= __('category') ?></label>
                    <select name="category_id" class="form-select">
                        <option value="">—</option>
                        <?php foreach ($cats as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= (int)$svc['category_id']===(int)$c['id']?'selected':'' ?>><?= e($c['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label"><?= __('duration_min') ?></label>
                    <input name="duration_minutes" type="number" min="5" max="600" class="form-control" value="<?= (int)$svc['duration_minutes'] ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('price') ?> (<?= APP_CURRENCY ?>)</label>
                    <input name="price" type="number" step="0.01" min="0" class="form-control" value="<?= e($svc['price']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('commission_label') ?> %</label>
                    <input name="commission_pct" type="number" step="0.01" min="0" max="100" class="form-control" value="<?= e($svc['commission_pct']) ?>">
                </div>
                <div class="col-md-12">
                    <label class="form-label"><?= __('description_ar') ?></label>
                    <textarea name="description_ar" class="form-control" rows="2"><?= e($svc['description_ar'] ?? '') ?></textarea>
                </div>
                <div class="col-md-12">
                    <label class="form-label"><?= __('description_en') ?></label>
                    <textarea name="description_en" class="form-control" rows="2"><?= e($svc['description_en'] ?? '') ?></textarea>
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('sort') ?></label>
                    <input name="sort_order" type="number" class="form-control" value="<?= (int)$svc['sort_order'] ?>">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <label class="form-check">
                        <input type="checkbox" class="form-check-input" name="is_consultation" <?= !empty($svc['is_consultation'])?'checked':'' ?>>
                        <span class="form-check-label"><?= __('is_consultation') ?></span>
                    </label>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <label class="form-check">
                        <input type="checkbox" class="form-check-input" name="is_active" <?= !empty($svc['is_active'])?'checked':'' ?>>
                        <span class="form-check-label"><?= __('active') ?></span>
                    </label>
                </div>
            </div>
            <div class="mt-3 d-flex gap-2">
                <button class="btn btn-teal"><?= __('save') ?></button>
                <a class="btn btn-light" href="<?= BP_URL ?>admin/services.php"><?= __('cancel') ?></a>
            </div>
        </div></form>
    </div>
    <?php include BP_PARTIALS . '/footer.php'; exit;
}

$q = trim($_GET['q'] ?? '');
$catFilter = (int)($_GET['cat'] ?? 0);
$page = (int)($_GET['page'] ?? 1);
[$perPage,$offset] = paginate_query($page,(int)setting('per_page','25'));

$where = "s.deleted_at IS NULL";
$params = [];
if ($q !== '') { $where .= " AND (s.name_ar LIKE ? OR s.name_en LIKE ?)"; $params[]="%$q%"; $params[]="%$q%"; }
if ($catFilter > 0) { $where .= " AND s.category_id = ?"; $params[] = $catFilter; }

$tot = db()->prepare("SELECT COUNT(*) FROM services s WHERE $where");
$tot->execute($params);
$total = (int)$tot->fetchColumn();

$sql = "SELECT s.*, c.name_ar AS cat_name
        FROM services s LEFT JOIN service_categories c ON c.id = s.category_id
        WHERE $where ORDER BY s.sort_order, s.id DESC LIMIT $perPage OFFSET $offset";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$cats = db()->query("SELECT id,name_ar FROM service_categories WHERE deleted_at IS NULL ORDER BY sort_order")->fetchAll();

// KPI stats
$kpi = db()->query("
    SELECT
      COUNT(*)                                          AS total,
      SUM(is_active = 1)                                AS active_count,
      SUM(is_consultation = 1 AND deleted_at IS NULL)   AS consult_count,
      COALESCE(AVG(NULLIF(price,0)),0)                  AS avg_price
    FROM services WHERE deleted_at IS NULL
")->fetch() ?: ['total'=>0,'active_count'=>0,'consult_count'=>0,'avg_price'=>0];
$activeFilters = ($q !== '') + ($catFilter > 0);

include BP_PARTIALS . '/header.php';
?>
<div class="page-wrap">
    <!-- Header -->
    <div class="page-header">
        <h4 class="m-0">
            <i class="fa-solid fa-hand-holding-medical text-teal me-2"></i><?= __('services') ?>
            <span class="page-count">(<?= $total ?>)</span>
        </h4>
        <div class="page-header-actions">
            <a href="<?= BP_URL ?>admin/categories.php" class="btn btn-light btn-sm">
                <i class="fa-solid fa-tags me-1"></i><?= __('categories') ?>
            </a>
            <?php if (can('services.create')): ?>
                <a href="?action=create" class="btn btn-teal btn-sm" data-modal>
                    <i class="fa-solid fa-plus me-1"></i><?= __('new_service') ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- KPI strip -->
    <div class="appt-kpis">
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#0d9488"><i class="fa-solid fa-hand-holding-medical"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('total_services') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['total'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#10b981"><i class="fa-solid fa-circle-check"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('active_services') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['active_count'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#6366f1"><i class="fa-solid fa-stethoscope"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('consultation_services') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['consult_count'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#b45309"><i class="fa-solid fa-coins"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('avg_price') ?></div>
                <div class="appt-kpi-value" style="font-size:1.05rem"><?= format_money($kpi['avg_price']) ?></div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <form method="get" class="appt-filters">
        <div class="appt-filter-group flex-grow-1">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="<?= __('search_service_placeholder') ?>">
        </div>
        <div class="appt-filter-group">
            <select name="cat" class="form-select form-select-sm">
                <option value="0"><?= __('all_categories') ?></option>
                <?php foreach ($cats as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $catFilter===(int)$c['id']?'selected':'' ?>><?= e($c['name_ar']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-teal btn-sm"><i class="fa-solid fa-filter me-1"></i><?= __('apply') ?></button>
        <?php if ($activeFilters): ?>
            <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/services.php" title="<?= __('clear_filters') ?>"><i class="fa-solid fa-xmark"></i></a>
        <?php endif; ?>
    </form>

    <div class="table-card">
        <div class="table-responsive d-none d-md-block">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th>#</th><th><?= __('name_ar') ?></th><th><?= __('category') ?></th>
                <th><?= __('duration') ?></th><th><?= __('price') ?></th><th><?= __('commission_label') ?></th><th><?= __('status') ?></th><th></th>
            </tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="8" class="text-center text-muted py-4"><?= __('no_data') ?></td></tr>
            <?php else: foreach ($rows as $s): ?>
                <tr>
                    <td><?= (int)$s['id'] ?></td>
                    <td>
                        <?= e($s['name_ar']) ?>
                        <?php if ($s['is_consultation']): ?><span class="badge bg-info ms-1"><?= __('is_consultation') ?></span><?php endif; ?>
                    </td>
                    <td class="small text-muted"><?= e($s['cat_name'] ?? '—') ?></td>
                    <td><?= (int)$s['duration_minutes'] ?>m</td>
                    <td><?= format_money($s['price']) ?></td>
                    <td><?= e($s['commission_pct']) ?>%</td>
                    <td><span class="badge bg-<?= $s['is_active']?'success':'secondary' ?>"><?= __($s['is_active']?'active':'inactive') ?></span></td>
                    <td class="text-end">
                        <?= render_actions([
                            (can('services.edit') ? ['icon'=>'fa-pen','label'=>'edit','href'=>'?action=edit&id='.(int)$s['id'],'modal'=>true] : null),
                            (can('services.delete') ? ['icon'=>'fa-trash','label'=>'delete','href'=>'?action=delete&id='.(int)$s['id'].'&_csrf='.e(csrf_token()),'danger'=>true,'divider_before'=>true,'confirm'=>'are_you_sure'] : null),
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
                <div class="empty-state"><i class="fa-regular fa-rectangle-list"></i><div><?= __('no_data') ?></div></div>
            <?php else: foreach ($rows as $s):
                $chips = [
                    ['label'=>__($s['is_active']?'active':'inactive'),'icon'=>'fa-circle-dot','class'=>$s['is_active']?'success':''],
                    ['label'=>(int)$s['duration_minutes'].'m','icon'=>'fa-clock','class'=>'info'],
                    ['label'=>$s['commission_pct'].'%','icon'=>'fa-percent','class'=>'warn'],
                ];
                if (!empty($s['cat_name'])) $chips[] = ['label'=>$s['cat_name'],'icon'=>'fa-tag','class'=>'teal'];
                if (!empty($s['is_consultation'])) $chips[] = ['label'=>__('is_consultation'),'icon'=>'fa-stethoscope','class'=>'info'];
                echo render_entity_card([
                    'avatar_icon' => 'fa-hand-holding-medical',
                    'avatar_class' => 'square ' . ($s['is_active']?'':'slate'),
                    'title' => $s['name_ar'],
                    'title_right' => format_money($s['price']),
                    'chips' => $chips,
                    'actions' => [
                        (can('services.edit') ? ['icon'=>'fa-pen','label'=>'edit','href'=>'?action=edit&id='.(int)$s['id'],'modal'=>true] : null),
                        (can('services.delete') ? ['icon'=>'fa-trash','label'=>'delete','href'=>'?action=delete&id='.(int)$s['id'].'&_csrf='.csrf_token(),'danger'=>true,'divider_before'=>true,'confirm'=>'are_you_sure'] : null),
                    ],
                ]);
            endforeach; endif; ?>
        </div>

        <div class="p-2"><?= render_pagination($total,$page,$perPage,BP_URL.'admin/services.php?'.http_build_query(['q'=>$q,'cat'=>$catFilter])) ?></div>
    </div>
</div>
<?php include BP_PARTIALS . '/footer.php'; ?>
