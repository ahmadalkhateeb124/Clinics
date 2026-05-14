<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('payments.view');

$PageTitle = __('payments');

$q      = trim($_GET['q'] ?? '');
$method = trim($_GET['method'] ?? '');
$from   = trim($_GET['from'] ?? '');
$to     = trim($_GET['to'] ?? '');
$page   = (int)($_GET['page'] ?? 1);
[$perPage, $offset] = paginate_query($page, (int)setting('per_page','25'));

$where = "py.deleted_at IS NULL"; $params = [];
if ($q !== '') {
    $where .= " AND (py.receipt_no LIKE ? OR p.code LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? OR i.invoice_no LIKE ?)";
    $like = "%$q%"; array_push($params,$like,$like,$like,$like,$like);
}
if ($method !== '') { $where .= " AND py.method = ?"; $params[] = $method; }
if ($from !== '')   { $where .= " AND py.paid_at >= ?"; $params[] = $from . ' 00:00:00'; }
if ($to   !== '')   { $where .= " AND py.paid_at <= ?"; $params[] = $to   . ' 23:59:59'; }

$tot = db()->prepare("SELECT COUNT(*), COALESCE(SUM(py.amount),0)
    FROM payments py JOIN patients p ON p.id = py.patient_id LEFT JOIN invoices i ON i.id = py.invoice_id
    WHERE $where");
$tot->execute($params);
[$total, $sumAmt] = $tot->fetch(PDO::FETCH_NUM);

$sql = "SELECT py.*, p.code AS patient_code, p.first_name, p.last_name, i.invoice_no
        FROM payments py
        JOIN patients p ON p.id = py.patient_id
        LEFT JOIN invoices i ON i.id = py.invoice_id
        WHERE $where ORDER BY py.paid_at DESC, py.id DESC
        LIMIT $perPage OFFSET $offset";
$stmt = db()->prepare($sql); $stmt->execute($params);
$rows = $stmt->fetchAll();

// KPI stats
$today = date('Y-m-d');
$kpi = db()->query("
    SELECT
      COALESCE(SUM(amount),0)                                          AS total_amount,
      COALESCE(SUM(CASE WHEN DATE(paid_at)='$today' THEN amount END),0) AS today_amount,
      SUM(DATE(paid_at)='$today')                                      AS today_count,
      SUM(method='cash')                                               AS cash_count
    FROM payments WHERE deleted_at IS NULL AND is_refund=0
")->fetch() ?: ['total_amount'=>0,'today_amount'=>0,'today_count'=>0,'cash_count'=>0];
$activeFilters = ($q !== '') + ($method !== '') + ($from !== '') + ($to !== '');

include BP_PARTIALS . '/header.php';
?>
<div class="page-wrap">
    <!-- Header -->
    <div class="page-header">
        <h4 class="m-0">
            <i class="fa-solid fa-money-bill-wave text-teal me-2"></i><?= __('payments') ?>
            <span class="page-count">(<?= (int)$total ?>)</span>
        </h4>
        <div class="page-header-actions">
            <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/cash-drawer.php">
                <i class="fa-solid fa-cash-register me-1"></i><?= __('cash_drawer') ?>
            </a>
        </div>
    </div>

    <!-- KPI strip -->
    <div class="appt-kpis">
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#0d9488"><i class="fa-solid fa-coins"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('total_collected') ?></div>
                <div class="appt-kpi-value" style="font-size:1.05rem"><?= format_money($kpi['total_amount']) ?></div>
            </div>
        </div>
        <a class="appt-kpi" href="?from=<?= $today ?>&to=<?= $today ?>">
            <div class="appt-kpi-icon" style="background:#10b981"><i class="fa-solid fa-calendar-day"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('today') ?></div>
                <div class="appt-kpi-value" style="font-size:1.05rem"><?= format_money($kpi['today_amount']) ?></div>
            </div>
        </a>
        <a class="appt-kpi" href="?from=<?= $today ?>&to=<?= $today ?>">
            <div class="appt-kpi-icon" style="background:#0ea5e9"><i class="fa-solid fa-receipt"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('receipts_today') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['today_count'] ?></div>
            </div>
        </a>
        <a class="appt-kpi" href="?method=cash">
            <div class="appt-kpi-icon" style="background:#6366f1"><i class="fa-solid fa-money-bill"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('m_cash') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['cash_count'] ?></div>
            </div>
        </a>
    </div>

    <!-- Filters -->
    <form method="get" class="appt-filters">
        <div class="appt-filter-group flex-grow-1">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="<?= __('search_payment_placeholder') ?>">
        </div>
        <div class="appt-filter-group">
            <select name="method" class="form-select form-select-sm">
                <option value=""><?= __('all_methods') ?></option>
                <?php foreach (['cash','card','bank','online','other'] as $m): ?>
                    <option value="<?= $m ?>" <?= $method===$m?'selected':'' ?>><?= __('m_'.$m) ?: $m ?></option>
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
            <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/payments.php" title="<?= __('clear_filters') ?>"><i class="fa-solid fa-xmark"></i></a>
        <?php endif; ?>
    </form>

    <div class="table-card">
        <div class="table-responsive d-none d-md-block">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th><?= __('receipt_no') ?></th><th><?= __('date') ?></th><th><?= __('patients') ?></th><th><?= __('invoice') ?></th>
                <th><?= __('method') ?></th><th><?= __('reference') ?></th><th class="text-end"><?= __('amount') ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ($rows as $py): ?>
                    <tr>
                        <td><code><?= e($py['receipt_no']) ?></code></td>
                        <td class="small"><?= format_date($py['paid_at']) ?></td>
                        <td>
                            <?= e($py['first_name'].' '.$py['last_name']) ?>
                            <code class="small"><?= e($py['patient_code']) ?></code>
                        </td>
                        <td>
                            <?php if ($py['invoice_no']): ?>
                                <a href="<?= BP_URL ?>admin/invoice-view.php?id=<?= (int)$py['invoice_id'] ?>"><code><?= e($py['invoice_no']) ?></code></a>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><span class="badge bg-light text-dark"><?= __('m_'.$py['method']) ?: e($py['method']) ?></span></td>
                        <td class="small"><?= e($py['reference_no']??'—') ?></td>
                        <td class="text-end text-success"><strong><?= format_money($py['amount']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4"><?= __('no_data') ?></td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>

        <!-- Mobile entity card list -->
        <div class="entity-list d-md-none">
            <?php if (!$rows): ?>
                <div class="empty-state"><i class="fa-regular fa-money-bill-1"></i><div><?= __('no_data') ?></div></div>
            <?php else: foreach ($rows as $py):
                $chips = [
                    ['label'=>__('m_'.$py['method']) ?: $py['method'],'icon'=>'fa-credit-card','class'=>'info'],
                ];
                if (!empty($py['invoice_no'])) {
                    $chips[] = ['label'=>$py['invoice_no'],'icon'=>'fa-file-invoice','class'=>'teal','href'=>BP_URL.'admin/invoice-view.php?id='.(int)$py['invoice_id']];
                }
                if (!empty($py['reference_no'])) {
                    $chips[] = ['label'=>$py['reference_no'],'icon'=>'fa-hashtag'];
                }
                echo render_entity_card([
                    'avatar_icon' => 'fa-money-bill-wave',
                    'avatar_class' => 'success',
                    'title' => $py['first_name'].' '.$py['last_name'],
                    'title_right' => '<span style="color:#047857">+'.format_money($py['amount']).'</span>',
                    'code' => $py['receipt_no'],
                    'meta' => [format_date($py['paid_at'],'Y-m-d H:i')],
                    'chips' => $chips,
                ]);
            endforeach; endif; ?>
        </div>

        <div class="p-2"><?= render_pagination($total,$page,$perPage,BP_URL.'admin/payments.php?'.http_build_query(['q'=>$q,'method'=>$method,'from'=>$from,'to'=>$to])) ?></div>
    </div>
</div>
<?php include BP_PARTIALS . '/footer.php'; ?>
