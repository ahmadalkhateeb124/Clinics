<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('payments.view');

$PageTitle = __('cash_drawer');
$action    = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if ($action === 'open') {
        require_can('payments.create');
        $float = (float)($_POST['opening_float'] ?? 0);
        if (current_cash_drawer((int)$_SESSION['user_id'])) {
            flash('error', __('err_shift_already_open'));
        } else {
            db()->prepare("INSERT INTO cash_drawer_sessions (user_id,opened_at,opening_float,status,created_at,updated_at)
                VALUES (?,NOW(),?,'open',NOW(),NOW())")
                ->execute([$_SESSION['user_id'], $float]);
            log_activity('opened','cash_drawer','Opened cash drawer (float '.format_money($float).')','cash_drawer',(int)db()->lastInsertId());
            flash('success', __('shift_opened'));
        }
        redirect(BP_URL.'admin/cash-drawer.php');
    }

    if ($action === 'close') {
        require_can('payments.create');
        $sid = (int)($_POST['session_id'] ?? 0);
        $counted = (float)($_POST['counted_cash'] ?? 0);
        $notes   = trim($_POST['notes'] ?? '');

        $s = db()->prepare("SELECT * FROM cash_drawer_sessions WHERE id=? AND user_id=? AND status='open'");
        $s->execute([$sid, $_SESSION['user_id']]);
        $cd = $s->fetch();
        if (!$cd) { flash('error', __('not_found')); redirect(BP_URL.'admin/cash-drawer.php'); }

        $cashIn = (float)db()->query("
            SELECT COALESCE(SUM(amount),0) FROM payments
            WHERE cash_drawer_session_id = $sid AND method='cash' AND deleted_at IS NULL AND is_refund=0
        ")->fetchColumn();
        $cashOut = (float)db()->query("
            SELECT COALESCE(SUM(amount),0) FROM payments
            WHERE cash_drawer_session_id = $sid AND method='cash' AND deleted_at IS NULL AND is_refund=1
        ")->fetchColumn();

        $expected = (float)$cd['opening_float'] + $cashIn - $cashOut;
        $variance = $counted - $expected;

        db()->prepare("UPDATE cash_drawer_sessions
            SET closed_at=NOW(), expected_cash=?, counted_cash=?, variance=?, notes=?, status='closed', updated_at=NOW()
            WHERE id=?
        ")->execute([$expected, $counted, $variance, $notes, $sid]);

        log_activity('closed','cash_drawer',"Closed shift. Variance: ".format_money($variance),'cash_drawer',$sid);
        flash($variance == 0 ? 'success' : 'warning', __('shift_closed') . ' — ' . __('variance') . ': ' . format_money($variance));
        redirect(BP_URL.'admin/cash-drawer.php');
    }
}

$current  = current_cash_drawer((int)$_SESSION['user_id']);
$expected = 0;
$cashIn   = 0;
$cashOut  = 0;
if ($current) {
    $cashIn = (float)db()->query("
        SELECT COALESCE(SUM(amount),0) FROM payments
        WHERE cash_drawer_session_id = ".(int)$current['id']." AND method='cash' AND deleted_at IS NULL AND is_refund=0
    ")->fetchColumn();
    $cashOut = (float)db()->query("
        SELECT COALESCE(SUM(amount),0) FROM payments
        WHERE cash_drawer_session_id = ".(int)$current['id']." AND method='cash' AND deleted_at IS NULL AND is_refund=1
    ")->fetchColumn();
    $expected = (float)$current['opening_float'] + $cashIn - $cashOut;
}

$past = db()->prepare("SELECT * FROM cash_drawer_sessions WHERE user_id=? ORDER BY id DESC LIMIT 20");
$past->execute([$_SESSION['user_id']]); $past = $past->fetchAll();

include BP_PARTIALS . '/header.php';
?>
<div class="page-wrap">
    <!-- Header -->
    <div class="page-header">
        <h4 class="m-0">
            <i class="fa-solid fa-cash-register text-teal me-2"></i><?= __('cash_drawer') ?>
        </h4>
        <div class="page-header-actions">
            <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/payments.php">
                <i class="fa-solid fa-money-bill-wave me-1"></i><?= __('payments') ?>
            </a>
        </div>
    </div>

    <?php if ($current): ?>
        <div class="card mb-3"><div class="card-body">
            <div class="d-flex justify-content-between flex-wrap">
                <div>
                    <h6 class="text-teal mb-1"><?= __('current_shift') ?></h6>
                    <small class="text-muted"><?= __('opened') ?>: <?= format_date($current['opened_at']) ?></small>
                </div>
                <span class="badge bg-success align-self-start"><?= __('st_open') ?: 'OPEN' ?></span>
            </div>
            <hr>
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="text-muted small"><?= __('opening_float') ?></div>
                    <h5><?= format_money($current['opening_float']) ?></h5>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small"><?= __('cash_collected') ?></div>
                    <h5 class="text-success">+<?= format_money($cashIn) ?></h5>
                </div>
                <?php if ($cashOut > 0): ?>
                    <div class="col-md-2">
                        <div class="text-muted small"><?= __('cash_refunds_out') ?></div>
                        <h5 class="text-danger">−<?= format_money($cashOut) ?></h5>
                    </div>
                <?php endif; ?>
                <div class="col-md-<?= $cashOut > 0 ? '4' : '6' ?>">
                    <div class="text-muted small"><?= __('expected_in_drawer') ?></div>
                    <h5 class="text-teal"><?= format_money($expected) ?></h5>
                </div>
            </div>

            <hr>
            <form method="post" action="?action=close" class="row g-2">
                <?= csrf_field() ?>
                <input type="hidden" name="session_id" value="<?= (int)$current['id'] ?>">
                <div class="col-md-3">
                    <label class="form-label small"><?= __('counted_cash') ?></label>
                    <input type="number" step="0.01" min="0" required name="counted_cash" class="form-control form-control-sm" value="<?= e($expected) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label small"><?= __('notes') ?></label>
                    <input name="notes" class="form-control form-control-sm">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button class="btn btn-sm btn-outline-danger w-100" data-confirm="<?= __('confirm_close_shift') ?>">
                        <i class="fa-solid fa-lock me-1"></i><?= __('close_shift') ?>
                    </button>
                </div>
            </form>
        </div></div>
    <?php else: ?>
        <div class="card mb-3"><div class="card-body">
            <h6 class="text-teal"><?= __('open_new_shift') ?></h6>
            <form method="post" action="?action=open" class="row g-2">
                <?= csrf_field() ?>
                <div class="col-md-4">
                    <label class="form-label small"><?= __('opening_float') ?></label>
                    <input type="number" step="0.01" min="0" required name="opening_float" class="form-control form-control-sm" value="0">
                    <small class="text-muted"><?= __('opening_float_hint') ?></small>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button class="btn btn-sm btn-teal w-100"><i class="fa-solid fa-unlock me-1"></i><?= __('open_shift') ?></button>
                </div>
            </form>
        </div></div>
    <?php endif; ?>

    <h6 class="text-teal mb-2"><i class="fa-solid fa-clock-rotate-left me-1"></i><?= __('past_shifts') ?></h6>
    <div class="table-card">
        <div class="table-responsive d-none d-md-block">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th>#</th><th><?= __('opened') ?></th><th><?= __('closed') ?></th>
                <th class="text-end"><?= __('float') ?></th><th class="text-end"><?= __('expected') ?></th>
                <th class="text-end"><?= __('counted') ?></th><th class="text-end"><?= __('variance') ?></th><th><?= __('notes') ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ($past as $cd): $vc = (float)$cd['variance']; ?>
                    <tr>
                        <td><?= (int)$cd['id'] ?></td>
                        <td class="small"><?= format_date($cd['opened_at']) ?></td>
                        <td class="small"><?= $cd['closed_at'] ? format_date($cd['closed_at']) : '<span class="badge bg-success">'.__('open').'</span>' ?></td>
                        <td class="text-end"><?= format_money($cd['opening_float']) ?></td>
                        <td class="text-end"><?= format_money($cd['expected_cash']) ?></td>
                        <td class="text-end"><?= format_money($cd['counted_cash']) ?></td>
                        <td class="text-end <?= $vc<0?'text-danger':($vc>0?'text-warning':'') ?>"><?= format_money($vc) ?></td>
                        <td class="small"><?= e($cd['notes']??'—') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$past): ?><tr><td colspan="8" class="text-center text-muted py-4"><?= __('no_data') ?></td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>

        <!-- Mobile entity card list -->
        <div class="entity-list d-md-none">
            <?php if (!$past): ?>
                <div class="empty-state"><i class="fa-regular fa-money-bill-1"></i><div><?= __('no_data') ?></div></div>
            <?php else: foreach ($past as $cd):
                $vc = (float)$cd['variance'];
                $varClass = $vc<0?'danger':($vc>0?'warn':'success');
                $chips = [
                    !$cd['closed_at'] ? ['label'=>__('open'),'icon'=>'fa-lock-open','class'=>'success'] : ['label'=>__('closed'),'icon'=>'fa-lock','class'=>''],
                    ['label'=>format_money($cd['opening_float']),'icon'=>'fa-wallet','tooltip'=>__('float')],
                    ['label'=>format_money($cd['counted_cash']),'icon'=>'fa-coins','class'=>'teal','tooltip'=>__('counted')],
                ];
                if (abs($vc) > 0.01) {
                    $chips[] = ['label'=>($vc>0?'+':'').format_money($vc),'icon'=>'fa-scale-balanced','class'=>$varClass,'tooltip'=>__('variance')];
                }
                echo render_entity_card([
                    'avatar_icon' => 'fa-cash-register',
                    'avatar_class' => 'square ' . ($cd['closed_at']?'slate':'success'),
                    'title' => '#'.(int)$cd['id'],
                    'title_right' => format_money($cd['expected_cash']),
                    'meta' => [format_date($cd['opened_at'],'Y-m-d H:i')],
                    'chips' => $chips,
                ]);
            endforeach; endif; ?>
        </div>
    </div>
</div>
<?php include BP_PARTIALS . '/footer.php'; ?>
