<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('packages.view');

$PageTitle = __('packages');
$action    = $_GET['action'] ?? 'list';
$id        = (int)($_GET['id'] ?? 0);

if ($action === 'delete' && $id) {
    require_can('packages.delete');
    csrf_check();
    db()->prepare("UPDATE packages SET deleted_at=NOW() WHERE id=?")->execute([$id]);
    log_activity('deleted','packages',"Deleted package #$id",'package',$id);
    flash('success', __('package_deleted'));
    redirect(BP_URL . 'admin/packages.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action,['create','edit'],true)) {
    csrf_check();
    require_can($action==='create'?'packages.create':'packages.edit');

    $name_ar  = trim($_POST['name_ar'] ?? '');
    $name_en  = trim($_POST['name_en'] ?? '');
    $desc_ar  = trim($_POST['description_ar'] ?? '');
    $desc_en  = trim($_POST['description_en'] ?? '');
    $total    = max(1,(int)($_POST['total_sessions'] ?? 1));
    $price    = (float)($_POST['price'] ?? 0);
    $validity = max(1,(int)($_POST['validity_days'] ?? 90));
    $active   = isset($_POST['is_active']) ? 1 : 0;
    $sort     = (int)($_POST['sort_order'] ?? 0);
    $slug     = slugify($name_en !== '' ? $name_en : $name_ar);
    $services = $_POST['services'] ?? [];

    if ($name_ar === '') { flash('error', __('err_name_ar_required')); back(); }

    if ($action === 'create') {
        db()->prepare("INSERT INTO packages
            (name_ar,name_en,slug,description_ar,description_en,total_sessions,price,validity_days,is_active,sort_order,created_by,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")
            ->execute([$name_ar,$name_en,$slug,$desc_ar,$desc_en,$total,$price,$validity,$active,$sort,$_SESSION['user_id']]);
        $pid = (int)db()->lastInsertId();
        log_activity('created','packages',"Created package $name_ar",'package',$pid);
    } else {
        db()->prepare("UPDATE packages SET
            name_ar=?,name_en=?,slug=?,description_ar=?,description_en=?,total_sessions=?,price=?,
            validity_days=?,is_active=?,sort_order=?,updated_by=?,updated_at=NOW() WHERE id=?")
            ->execute([$name_ar,$name_en,$slug,$desc_ar,$desc_en,$total,$price,$validity,$active,$sort,$_SESSION['user_id'],$id]);
        $pid = $id;
        log_activity('updated','packages',"Updated package #$pid",'package',$pid);
    }

    db()->prepare("DELETE FROM package_services WHERE package_id = ?")->execute([$pid]);
    if (is_array($services)) {
        $ins = db()->prepare("INSERT IGNORE INTO package_services (package_id,service_id,sessions_included) VALUES (?,?,?)");
        foreach ($services as $sid => $included) {
            $sid = (int)$sid; $inc = max(0,(int)$included);
            if ($sid > 0 && $inc > 0) $ins->execute([$pid,$sid,$inc]);
        }
    }
    flash('success', __('saved'));
    redirect(BP_URL . 'admin/packages.php');
}

if (in_array($action,['create','edit'],true)) {
    require_can($action==='create'?'packages.create':'packages.edit');
    $pkg = ['name_ar'=>'','name_en'=>'','description_ar'=>'','description_en'=>'',
            'total_sessions'=>5,'price'=>0,'validity_days'=>90,'is_active'=>1,'sort_order'=>0];
    $linked = [];
    if ($action === 'edit' && $id) {
        $s = db()->prepare("SELECT * FROM packages WHERE id=? AND deleted_at IS NULL");
        $s->execute([$id]); $pkg = $s->fetch() ?: null;
        if (!$pkg) { flash('error', __('not_found')); redirect(BP_URL.'admin/packages.php'); }
        $r = db()->prepare("SELECT service_id, sessions_included FROM package_services WHERE package_id=?");
        $r->execute([$id]);
        foreach ($r->fetchAll() as $row) $linked[(int)$row['service_id']] = (int)$row['sessions_included'];
    }
    $services = db()->query("
        SELECT s.id,s.name_ar,s.duration_minutes,s.price,c.name_ar AS cat
        FROM services s LEFT JOIN service_categories c ON c.id = s.category_id
        WHERE s.deleted_at IS NULL AND s.is_active = 1
        ORDER BY c.sort_order, s.sort_order
    ")->fetchAll();
    include BP_PARTIALS . '/header.php';
    ?>
    <div class="page-wrap">
        <h4 class="mb-3"><?= $action==='create'?__('create'):__('edit') ?> — <?= __('packages') ?></h4>
        <form method="post" class="card"><div class="card-body">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label"><?= __('name_ar') ?></label>
                    <input name="name_ar" class="form-control" required value="<?= e($pkg['name_ar']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?= __('name_en') ?></label>
                    <input name="name_en" class="form-control" value="<?= e($pkg['name_en'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('total_sessions') ?></label>
                    <input name="total_sessions" type="number" min="1" class="form-control" value="<?= (int)$pkg['total_sessions'] ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('price') ?> (<?= APP_CURRENCY ?>)</label>
                    <input name="price" type="number" step="0.01" min="0" class="form-control" value="<?= e($pkg['price']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('validity_days') ?></label>
                    <input name="validity_days" type="number" min="1" class="form-control" value="<?= (int)$pkg['validity_days'] ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('sort') ?></label>
                    <input name="sort_order" type="number" class="form-control" value="<?= (int)$pkg['sort_order'] ?>">
                </div>
                <div class="col-md-12">
                    <label class="form-label"><?= __('description_ar') ?></label>
                    <textarea name="description_ar" class="form-control" rows="2"><?= e($pkg['description_ar'] ?? '') ?></textarea>
                </div>
                <div class="col-md-12">
                    <label class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" <?= !empty($pkg['is_active'])?'checked':'' ?>>
                        <span class="form-check-label"><?= __('active') ?></span>
                    </label>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mt-4 mb-2">
                <h6 class="m-0"><i class="fa-solid fa-list-check text-teal me-1"></i><?= __('services_included_sessions') ?></h6>
                <input type="search" id="pkgSvcSearch" class="form-control form-control-sm" style="max-width:240px" placeholder="<?= __('search') ?>…">
            </div>
            <p class="small text-muted mb-2"><?= __('pkg_services_hint') ?></p>

            <!-- Live summary -->
            <div class="pkg-svc-summary mb-2" id="pkgSvcSummary">
                <span><i class="fa-solid fa-circle-check me-1 text-teal"></i><span id="pkgSvcSelectedCount">0</span> <?= __('selected') ?></span>
                <span class="ms-3"><i class="fa-solid fa-list-ol me-1"></i><strong id="pkgSvcTotalSessions">0</strong> <?= __('sessions') ?></span>
                <span class="ms-3"><i class="fa-solid fa-coins me-1"></i><strong id="pkgSvcSuggestedPrice">0.00</strong> <?= __('suggested_price') ?></span>
            </div>

            <!-- Services grid grouped by category -->
            <div class="pkg-svc-picker">
                <?php
                    $svcByCat = [];
                    foreach ($services as $s) $svcByCat[$s['cat'] ?? __('uncategorized')][] = $s;
                    foreach ($svcByCat as $catName => $svcs):
                ?>
                    <div class="pkg-svc-cat">
                        <div class="pkg-svc-cat-title"><i class="fa-solid fa-folder-open me-1"></i><?= e($catName) ?> <span class="text-muted">(<?= count($svcs) ?>)</span></div>
                        <div class="pkg-svc-grid">
                            <?php foreach ($svcs as $s):
                                $inc = (int)($linked[(int)$s['id']] ?? 0);
                                $included = $inc > 0;
                            ?>
                                <div class="pkg-svc-tile <?= $included?'is-included':'' ?>"
                                     data-name="<?= e(mb_strtolower($s['name_ar'])) ?>"
                                     data-price="<?= (float)$s['price'] ?>">
                                    <div class="pkg-svc-tile-info">
                                        <div class="pkg-svc-tile-name"><?= e($s['name_ar']) ?></div>
                                        <div class="pkg-svc-tile-meta">
                                            <span><i class="fa-regular fa-clock"></i><?= (int)$s['duration_minutes'] ?>m</span>
                                            <span><i class="fa-solid fa-coins"></i><?= format_money($s['price']) ?></span>
                                        </div>
                                    </div>
                                    <div class="pkg-svc-tile-control">
                                        <button type="button" class="pkg-svc-btn pkg-svc-dec" tabindex="-1">−</button>
                                        <input type="number" min="0" name="services[<?= (int)$s['id'] ?>]" value="<?= $inc ?>" class="pkg-svc-input">
                                        <button type="button" class="pkg-svc-btn pkg-svc-inc" tabindex="-1">+</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <script>
            (function(){
                const wrap = document.querySelector('.pkg-svc-picker');
                if (!wrap) return;
                const tiles = Array.from(wrap.querySelectorAll('.pkg-svc-tile'));
                const elCount    = document.getElementById('pkgSvcSelectedCount');
                const elSessions = document.getElementById('pkgSvcTotalSessions');
                const elPrice    = document.getElementById('pkgSvcSuggestedPrice');
                const search     = document.getElementById('pkgSvcSearch');

                const recalc = () => {
                    let n=0, ses=0, total=0;
                    tiles.forEach(t => {
                        const v = parseInt(t.querySelector('.pkg-svc-input').value || '0', 10);
                        const p = parseFloat(t.dataset.price) || 0;
                        t.classList.toggle('is-included', v > 0);
                        if (v > 0) { n++; ses += v; total += v * p; }
                    });
                    if (elCount)    elCount.textContent = n;
                    if (elSessions) elSessions.textContent = ses;
                    if (elPrice)    elPrice.textContent = total.toFixed(2);
                };

                tiles.forEach(t => {
                    const input = t.querySelector('.pkg-svc-input');
                    const inc   = t.querySelector('.pkg-svc-inc');
                    const dec   = t.querySelector('.pkg-svc-dec');
                    const set = (v) => {
                        input.value = Math.max(0, v);
                        recalc();
                    };
                    inc.addEventListener('click', () => set((parseInt(input.value||'0',10)) + 1));
                    dec.addEventListener('click', () => set((parseInt(input.value||'0',10)) - 1));
                    input.addEventListener('input', recalc);
                    // Click empty area on tile = +1 (quick add)
                    t.querySelector('.pkg-svc-tile-info').addEventListener('click', () => set((parseInt(input.value||'0',10)) + 1));
                });

                if (search) {
                    search.addEventListener('input', () => {
                        const q = search.value.trim().toLowerCase();
                        tiles.forEach(t => {
                            t.classList.toggle('d-none', q && !(t.dataset.name || '').includes(q));
                        });
                        wrap.querySelectorAll('.pkg-svc-cat').forEach(cat => {
                            const visible = cat.querySelectorAll('.pkg-svc-tile:not(.d-none)').length;
                            cat.classList.toggle('d-none', visible === 0);
                        });
                    });
                }

                recalc();
            })();
            </script>

            <div class="mt-3 d-flex gap-2">
                <button class="btn btn-teal"><?= __('save') ?></button>
                <a class="btn btn-light" href="<?= BP_URL ?>admin/packages.php"><?= __('cancel') ?></a>
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
if ($q !== '') { $where .= " AND (p.name_ar LIKE ? OR p.name_en LIKE ?)"; $params[]="%$q%"; $params[]="%$q%"; }

$tot = db()->prepare("SELECT COUNT(*) FROM packages p WHERE $where");
$tot->execute($params);
$total = (int)$tot->fetchColumn();

$sql = "SELECT p.*, COUNT(ps.service_id) AS service_count
        FROM packages p LEFT JOIN package_services ps ON ps.package_id = p.id
        WHERE $where GROUP BY p.id
        ORDER BY p.sort_order, p.id DESC LIMIT $perPage OFFSET $offset";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// KPI stats
$kpi = db()->query("
    SELECT
      COUNT(*) AS total,
      SUM(is_active=1) AS active_count,
      (SELECT COUNT(*) FROM patient_packages WHERE deleted_at IS NULL AND status='active') AS assigned_count,
      COALESCE(AVG(NULLIF(price,0)),0) AS avg_price
    FROM packages WHERE deleted_at IS NULL
")->fetch() ?: ['total'=>0,'active_count'=>0,'assigned_count'=>0,'avg_price'=>0];

include BP_PARTIALS . '/header.php';
?>
<div class="page-wrap">
    <!-- Header -->
    <div class="page-header">
        <h4 class="m-0">
            <i class="fa-solid fa-box-open text-teal me-2"></i><?= __('packages') ?>
            <span class="page-count">(<?= $total ?>)</span>
        </h4>
        <div class="page-header-actions">
            <?php if (can('packages.create')): ?>
                <a href="?action=create" class="btn btn-teal btn-sm" data-modal>
                    <i class="fa-solid fa-plus me-1"></i><?= __('new_package') ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- KPI strip -->
    <div class="appt-kpis">
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#6366f1"><i class="fa-solid fa-box-open"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('total_packages') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['total'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#10b981"><i class="fa-solid fa-circle-check"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('active_packages') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['active_count'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#0d9488"><i class="fa-solid fa-users"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('assigned_to_patients') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['assigned_count'] ?></div>
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
            <input type="search" name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="<?= __('search_package_placeholder') ?>">
        </div>
        <button class="btn btn-teal btn-sm"><i class="fa-solid fa-filter me-1"></i><?= __('apply') ?></button>
        <?php if ($q !== ''): ?>
            <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/packages.php" title="<?= __('clear_filters') ?>"><i class="fa-solid fa-xmark"></i></a>
        <?php endif; ?>
    </form>

    <div class="table-card">
        <div class="table-responsive d-none d-md-block">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th>#</th><th><?= __('name') ?> (AR)</th>
                <th><?= __('sessions') ?></th><th><?= __('validity_days') ?></th><th><?= __('price') ?></th>
                <th><?= __('services') ?></th><th><?= __('status') ?></th><th></th>
            </tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="8" class="text-center text-muted py-4"><?= __('no_data') ?></td></tr>
            <?php else: foreach ($rows as $p): ?>
                <tr>
                    <td><?= (int)$p['id'] ?></td>
                    <td><?= e($p['name_ar']) ?></td>
                    <td><span class="badge badge-teal"><?= (int)$p['total_sessions'] ?></span></td>
                    <td><?= (int)$p['validity_days'] ?> <?= __('days') ?></td>
                    <td><?= format_money($p['price']) ?></td>
                    <td><span class="badge bg-light text-dark"><?= (int)$p['service_count'] ?></span></td>
                    <td><span class="badge bg-<?= $p['is_active']?'success':'secondary' ?>"><?= __($p['is_active']?'active':'inactive') ?></span></td>
                    <td class="text-end">
                        <?= render_actions([
                            (can('packages.edit') ? ['icon'=>'fa-pen','label'=>'edit','href'=>'?action=edit&id='.(int)$p['id'],'modal'=>true] : null),
                            (can('packages.delete') ? ['icon'=>'fa-trash','label'=>'delete','href'=>'?action=delete&id='.(int)$p['id'].'&_csrf='.e(csrf_token()),'danger'=>true,'divider_before'=>true,'confirm'=>'are_you_sure'] : null),
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
                <div class="empty-state"><i class="fa-regular fa-folder-open"></i><div><?= __('no_data') ?></div></div>
            <?php else: foreach ($rows as $p):
                echo render_entity_card([
                    'avatar_icon' => 'fa-box',
                    'avatar_class' => 'square ' . ($p['is_active'] ? 'indigo' : 'slate'),
                    'title' => $p['name_ar'],
                    'title_right' => format_money($p['price']),
                    'meta' => [(int)$p['validity_days'].' '.__('days')],
                    'chips' => [
                        ['label'=>(int)$p['total_sessions'].' '.__('sessions'),'icon'=>'fa-calendar-check','class'=>'teal'],
                        ['label'=>(int)$p['service_count'].' '.__('services'),'icon'=>'fa-list-check'],
                        ['label'=>__($p['is_active']?'active':'inactive'),'icon'=>'fa-circle-dot','class'=>$p['is_active']?'success':''],
                    ],
                    'actions' => [
                        (can('packages.edit') ? ['icon'=>'fa-pen','label'=>'edit','href'=>'?action=edit&id='.(int)$p['id'],'modal'=>true] : null),
                        (can('packages.delete') ? ['icon'=>'fa-trash','label'=>'delete','href'=>'?action=delete&id='.(int)$p['id'].'&_csrf='.csrf_token(),'danger'=>true,'divider_before'=>true,'confirm'=>'are_you_sure'] : null),
                    ],
                ]);
            endforeach; endif; ?>
        </div>

        <div class="p-2"><?= render_pagination($total,$page,$perPage,BP_URL.'admin/packages.php' . ($q?"?q=$q":'')) ?></div>
    </div>
</div>
<?php include BP_PARTIALS . '/footer.php'; ?>
