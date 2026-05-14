<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('expenses.view');

$PageTitle = __('expenses');
$action    = $_GET['action'] ?? 'list';
$id        = (int)($_GET['id'] ?? 0);

if ($action === 'delete' && $id) {
    csrf_check();
    require_can('expenses.delete');
    db()->prepare("UPDATE expenses SET deleted_at=NOW() WHERE id=?")->execute([$id]);
    log_activity('deleted','expenses',"Deleted expense #$id",'expense',$id);
    flash('success', __('expense_deleted'));
    redirect(BP_URL.'admin/expenses.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['create','edit'], true)) {
    csrf_check();
    require_can($action==='create'?'expenses.create':'expenses.edit');

    $cat    = (int)($_POST['category_id'] ?? 0) ?: null;
    $title  = trim($_POST['title'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $date   = trim($_POST['expense_date'] ?? date('Y-m-d'));
    $method = $_POST['payment_method'] ?? 'cash';
    $vendor = trim($_POST['vendor'] ?? '');
    $ref    = trim($_POST['reference_no'] ?? '');
    $notes  = trim($_POST['notes'] ?? '');
    if (!in_array($method, ['cash','card','bank','online','other'], true)) $method = 'cash';

    $errors = [];
    if ($title === '') $errors[] = __('err_title_required');
    if ($amount <= 0)  $errors[] = __('err_amount_positive');

    if ($errors) { foreach ($errors as $err) flash('error',$err); set_old($_POST); back(); }

    $att = null;
    if (!empty($_FILES['attachment']['name'])) {
        $up = upload_file($_FILES['attachment'],'expenses',['jpg','jpeg','png','webp','pdf']);
        if ($up) $att = $up['relative_path'];
    }

    if ($action === 'create') {
        db()->prepare("INSERT INTO expenses
            (category_id,title,amount,expense_date,payment_method,vendor,reference_no,attachment,notes,created_by,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")
            ->execute([$cat,$title,$amount,$date,$method,$vendor,$ref,$att,$notes,$_SESSION['user_id']]);
        $eid = (int)db()->lastInsertId();
        log_activity('created','expenses',"Recorded expense $title ".format_money($amount),'expense',$eid);
    } else {
        $sql = "UPDATE expenses SET category_id=?,title=?,amount=?,expense_date=?,payment_method=?,vendor=?,reference_no=?,notes=?,updated_by=?,updated_at=NOW()";
        $params = [$cat,$title,$amount,$date,$method,$vendor,$ref,$notes,$_SESSION['user_id']];
        if ($att) { $sql .= ",attachment=?"; $params[] = $att; }
        $sql .= " WHERE id=?";
        $params[] = $id;
        db()->prepare($sql)->execute($params);
        log_activity('updated','expenses',"Updated expense #$id",'expense',$id);
    }
    flash('success', __('saved'));
    redirect(BP_URL.'admin/expenses.php');
}

if (in_array($action,['create','edit'],true)) {
    require_can($action==='create'?'expenses.create':'expenses.edit');
    $ex = ['category_id'=>0,'title'=>'','amount'=>'','expense_date'=>date('Y-m-d'),
           'payment_method'=>'cash','vendor'=>'','reference_no'=>'','notes'=>'','attachment'=>''];
    if ($action==='edit' && $id) {
        $s = db()->prepare("SELECT * FROM expenses WHERE id=? AND deleted_at IS NULL");
        $s->execute([$id]); $ex = $s->fetch();
        if (!$ex) { flash('error', __('not_found')); redirect(BP_URL.'admin/expenses.php'); }
    }
    $cats = db()->query("SELECT id,name_ar FROM expense_categories WHERE deleted_at IS NULL AND is_active=1 ORDER BY sort_order,name_ar")->fetchAll();

    include BP_PARTIALS . '/header.php';
    ?>
    <div class="page-wrap">
        <h4 class="mb-3"><?= $action==='create'?__('create'):__('edit') ?> — <?= __('expenses') ?></h4>
        <form method="post" enctype="multipart/form-data" class="card"><div class="card-body">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label"><?= __('title') ?> *</label>
                    <input name="title" required class="form-control" value="<?= e(old('title',$ex['title'])) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label"><?= __('amount') ?> (<?= APP_CURRENCY ?>) *</label>
                    <input name="amount" type="number" step="0.01" min="0.01" required class="form-control" value="<?= e($ex['amount']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('date') ?></label>
                    <input name="expense_date" type="date" class="form-control" value="<?= e($ex['expense_date']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('method') ?></label>
                    <select name="payment_method" class="form-select">
                        <?php foreach (['cash','card','bank','online','other'] as $m): ?>
                            <option value="<?= $m ?>" <?= $ex['payment_method']===$m?'selected':'' ?>><?= __('m_'.$m) ?: $m ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('category') ?></label>
                    <select name="category_id" class="form-select">
                        <option value="">—</option>
                        <?php foreach ($cats as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= (int)$ex['category_id']===(int)$c['id']?'selected':'' ?>><?= e($c['name_ar']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('reference') ?></label>
                    <input name="reference_no" class="form-control" value="<?= e($ex['reference_no']??'') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?= __('vendor') ?></label>
                    <input name="vendor" class="form-control" value="<?= e($ex['vendor']??'') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?= __('attachment') ?></label>
                    <input name="attachment" type="file" class="form-control">
                    <?php if (!empty($ex['attachment'])): ?>
                        <small><a target="_blank" href="<?= UPLOADS_URL . e($ex['attachment']) ?>"><?= __('view_current') ?></a></small>
                    <?php endif; ?>
                </div>
                <div class="col-md-12">
                    <label class="form-label"><?= __('notes') ?></label>
                    <textarea name="notes" rows="2" class="form-control"><?= e($ex['notes']??'') ?></textarea>
                </div>
            </div>
            <div class="mt-3 d-flex gap-2">
                <button class="btn btn-teal"><?= __('save') ?></button>
                <a class="btn btn-light" href="<?= BP_URL ?>admin/expenses.php"><?= __('cancel') ?></a>
            </div>
        </div></form>
    </div>
    <?php include BP_PARTIALS . '/footer.php'; clear_old(); exit;
}

// LIST
$q     = trim($_GET['q'] ?? '');
$cat   = (int)($_GET['cat'] ?? 0);
$from  = trim($_GET['from'] ?? '');
$to    = trim($_GET['to'] ?? '');
$page  = (int)($_GET['page'] ?? 1);
[$perPage, $offset] = paginate_query($page, (int)setting('per_page','25'));

$where = "e.deleted_at IS NULL"; $params = [];
if ($q !== '')   { $where .= " AND (e.title LIKE ? OR e.vendor LIKE ?)"; $params[]="%$q%"; $params[]="%$q%"; }
if ($cat > 0)    { $where .= " AND e.category_id = ?"; $params[] = $cat; }
if ($from !== '') { $where .= " AND e.expense_date >= ?"; $params[] = $from; }
if ($to   !== '') { $where .= " AND e.expense_date <= ?"; $params[] = $to; }

$tot = db()->prepare("SELECT COUNT(*), COALESCE(SUM(e.amount),0) FROM expenses e WHERE $where");
$tot->execute($params);
[$total, $sumAmt] = $tot->fetch(PDO::FETCH_NUM);

$sql = "SELECT e.*, c.name_ar AS cat_name FROM expenses e
        LEFT JOIN expense_categories c ON c.id = e.category_id
        WHERE $where ORDER BY e.expense_date DESC, e.id DESC LIMIT $perPage OFFSET $offset";
$stmt = db()->prepare($sql); $stmt->execute($params);
$rows = $stmt->fetchAll();

$cats = db()->query("SELECT id,name_ar FROM expense_categories WHERE deleted_at IS NULL ORDER BY sort_order,name_ar")->fetchAll();

// KPI stats
$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$kpi = db()->query("
    SELECT
      COALESCE(SUM(amount),0)                                                     AS total_amount,
      COALESCE(SUM(CASE WHEN expense_date >= '$monthStart' THEN amount END),0)    AS month_amount,
      SUM(expense_date >= '$monthStart')                                          AS month_count,
      (SELECT COUNT(*) FROM expense_categories WHERE deleted_at IS NULL AND is_active=1) AS cat_count
    FROM expenses WHERE deleted_at IS NULL
")->fetch() ?: ['total_amount'=>0,'month_amount'=>0,'month_count'=>0,'cat_count'=>0];
$activeFilters = ($q !== '') + ($cat > 0) + ($from !== '') + ($to !== '');

include BP_PARTIALS . '/header.php';
?>
<div class="page-wrap">
    <!-- Header -->
    <div class="page-header">
        <h4 class="m-0">
            <i class="fa-solid fa-receipt text-teal me-2"></i><?= __('expenses') ?>
            <span class="page-count">(<?= (int)$total ?>)</span>
        </h4>
        <div class="page-header-actions">
            <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/expense-categories.php">
                <i class="fa-solid fa-tags me-1"></i><?= __('categories') ?>
            </a>
            <?php if (can('expenses.create')): ?>
                <a class="btn btn-teal btn-sm" href="?action=create" data-modal>
                    <i class="fa-solid fa-plus me-1"></i><?= __('new_expense') ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- KPI strip -->
    <div class="appt-kpis">
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#b45309"><i class="fa-solid fa-receipt"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('total_expenses') ?></div>
                <div class="appt-kpi-value" style="font-size:1.05rem"><?= format_money($kpi['total_amount']) ?></div>
            </div>
        </div>
        <a class="appt-kpi" href="?from=<?= $monthStart ?>&to=<?= $today ?>">
            <div class="appt-kpi-icon" style="background:#ef4444"><i class="fa-solid fa-calendar-week"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('this_month') ?></div>
                <div class="appt-kpi-value" style="font-size:1.05rem"><?= format_money($kpi['month_amount']) ?></div>
            </div>
        </a>
        <a class="appt-kpi" href="?from=<?= $monthStart ?>&to=<?= $today ?>">
            <div class="appt-kpi-icon" style="background:#f59e0b"><i class="fa-solid fa-hashtag"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('entries_this_month') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['month_count'] ?></div>
            </div>
        </a>
        <a class="appt-kpi" href="<?= BP_URL ?>admin/expense-categories.php">
            <div class="appt-kpi-icon" style="background:#6366f1"><i class="fa-solid fa-tags"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('categories') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['cat_count'] ?></div>
            </div>
        </a>
    </div>

    <!-- Filters -->
    <form method="get" class="appt-filters">
        <div class="appt-filter-group flex-grow-1">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="<?= __('search_expense_placeholder') ?>">
        </div>
        <div class="appt-filter-group">
            <select name="cat" class="form-select form-select-sm">
                <option value="0"><?= __('all_categories') ?></option>
                <?php foreach ($cats as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $cat===(int)$c['id']?'selected':'' ?>><?= e($c['name_ar']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="appt-filter-group">
            <span class="appt-filter-icon"><i class="fa-regular fa-calendar"></i></span>
            <input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm" title="<?= __('from') ?>">
        </div>
        <div class="appt-filter-group">
            <span class="appt-filter-icon"><i class="fa-regular fa-calendar-check"></i></span>
            <input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm" title="<?= __('to') ?>">
        </div>
        <button class="btn btn-teal btn-sm"><i class="fa-solid fa-filter me-1"></i><?= __('apply') ?></button>
        <?php if ($activeFilters): ?>
            <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/expenses.php" title="<?= __('clear_filters') ?>"><i class="fa-solid fa-xmark"></i></a>
        <?php endif; ?>
    </form>

    <div class="table-card">
        <div class="table-responsive d-none d-md-block">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th>#</th><th><?= __('date') ?></th><th><?= __('title') ?></th><th><?= __('category') ?></th>
                <th><?= __('vendor') ?></th><th><?= __('method') ?></th><th class="text-end"><?= __('amount') ?></th><th></th>
            </tr></thead>
            <tbody>
                <?php foreach ($rows as $e): ?>
                    <tr>
                        <td><?= (int)$e['id'] ?></td>
                        <td class="small"><?= e($e['expense_date']) ?></td>
                        <td><?= e($e['title']) ?></td>
                        <td class="small text-muted"><?= e($e['cat_name']??'—') ?></td>
                        <td class="small"><?= e($e['vendor']??'—') ?></td>
                        <td><span class="badge bg-light text-dark"><?= __('m_'.$e['payment_method']) ?: e($e['payment_method']) ?></span></td>
                        <td class="text-end"><strong><?= format_money($e['amount']) ?></strong></td>
                        <td class="text-end">
                            <?= render_actions([
                                ['icon'=>'fa-paperclip','label'=>'attachment','href'=>UPLOADS_URL . e($e['attachment']),'target'=>'_blank'],
                                (can('expenses.edit') ? ['icon'=>'fa-pen','label'=>'edit','href'=>'?action=edit&id='.(int)$e['id'],'modal'=>true] : null),
                                (can('expenses.delete') ? ['icon'=>'fa-trash','label'=>'delete','href'=>'?action=delete&id='.(int)$e['id'].'&_csrf='.e(csrf_token()),'danger'=>true,'divider_before'=>true,'confirm'=>'are_you_sure'] : null),
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
                <div class="empty-state"><i class="fa-regular fa-money-bill-1"></i><div><?= __('no_data') ?></div></div>
            <?php else: foreach ($rows as $ex):
                $chips = [
                    ['label'=>__('m_'.$ex['payment_method']) ?: $ex['payment_method'],'icon'=>'fa-credit-card','class'=>'info'],
                ];
                if (!empty($ex['cat_name']))  $chips[] = ['label'=>$ex['cat_name'],'icon'=>'fa-tag','class'=>'teal'];
                if (!empty($ex['vendor']))    $chips[] = ['label'=>$ex['vendor'],'icon'=>'fa-shop'];
                if (!empty($ex['attachment']))$chips[] = ['label'=>__('attachment'),'icon'=>'fa-paperclip','href'=>UPLOADS_URL.e($ex['attachment'])];
                echo render_entity_card([
                    'avatar_icon' => 'fa-receipt',
                    'avatar_class' => 'square rose',
                    'title' => $ex['title'],
                    'title_right' => '<span style="color:#b91c1c">-'.format_money($ex['amount']).'</span>',
                    'meta' => [$ex['expense_date']],
                    'chips' => $chips,
                    'actions' => [
                        !empty($ex['attachment']) ? ['icon'=>'fa-paperclip','label'=>'attachment','href'=>UPLOADS_URL.e($ex['attachment']),'target'=>'_blank'] : null,
                        (can('expenses.edit') ? ['icon'=>'fa-pen','label'=>'edit','href'=>'?action=edit&id='.(int)$ex['id'],'modal'=>true] : null),
                        (can('expenses.delete') ? ['icon'=>'fa-trash','label'=>'delete','href'=>'?action=delete&id='.(int)$ex['id'].'&_csrf='.csrf_token(),'danger'=>true,'divider_before'=>true,'confirm'=>'are_you_sure'] : null),
                    ],
                ]);
            endforeach; endif; ?>
        </div>

        <div class="p-2"><?= render_pagination($total,$page,$perPage,BP_URL.'admin/expenses.php?'.http_build_query(['q'=>$q,'cat'=>$cat,'from'=>$from,'to'=>$to])) ?></div>
    </div>
</div>
<?php include BP_PARTIALS . '/footer.php'; ?>
