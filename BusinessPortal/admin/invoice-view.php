<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('invoices.view');

$id = (int)($_GET['id'] ?? 0);
$action = $_GET['action'] ?? '';

$inv = db()->prepare("
    SELECT i.*, p.code AS patient_code, p.first_name, p.last_name, p.phone, p.email, p.address
    FROM invoices i JOIN patients p ON p.id = i.patient_id
    WHERE i.id = ? AND i.deleted_at IS NULL
");
$inv->execute([$id]); $invoice = $inv->fetch();
if (!$invoice) { flash('error', __('not_found')); redirect(BP_URL.'admin/invoices.php'); }

$PageTitle = $invoice['invoice_no'];

// ─── Record payment ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'pay') {
    csrf_check(); require_can('payments.create');
    $amount = (float)($_POST['amount'] ?? 0);
    $method = $_POST['method'] ?? 'cash';
    $ref    = trim($_POST['reference_no'] ?? '');
    $date   = trim($_POST['paid_at'] ?? date('Y-m-d H:i:s'));
    $notes  = trim($_POST['notes'] ?? '');
    $instId = (int)($_POST['installment_id'] ?? 0) ?: null;

    if (!in_array($method, ['cash','card','bank','online','other'], true)) $method = 'cash';
    if ($amount <= 0) { flash('error', __('err_amount_positive')); redirect(BP_URL.'admin/invoice-view.php?id='.$id); }
    if ($amount > (float)$invoice['balance'] + 0.01) {
        flash('error', __('err_amount_exceeds_balance'));
        redirect(BP_URL.'admin/invoice-view.php?id='.$id);
    }

    $cdSession = current_cash_drawer((int)$_SESSION['user_id']);
    db()->prepare("INSERT INTO payments
        (receipt_no,invoice_id,patient_id,amount,method,reference_no,paid_at,
         cash_drawer_session_id,notes,installment_id,created_by,created_at,updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")
        ->execute([
            next_receipt_no(), $id, $invoice['patient_id'], $amount, $method, $ref ?: null,
            str_replace('T',' ',$date),
            $cdSession['id'] ?? null, $notes, $instId, $_SESSION['user_id']
        ]);
    $payId = (int)db()->lastInsertId();
    if ($instId) recompute_installment($instId);
    recompute_invoice($id);
    log_activity('payment','payments',"Recorded payment ".format_money($amount)." for invoice {$invoice['invoice_no']}",'payment',$payId);
    flash('success', __('payment_recorded'));
    redirect(BP_URL.'admin/invoice-view.php?id='.$id);
}

// ─── Refund ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'refund') {
    csrf_check(); require_can('invoices.refund');
    $amount = (float)($_POST['amount'] ?? 0);
    $method = $_POST['method'] ?? 'cash';
    $reason = trim($_POST['reason'] ?? '');
    $date   = trim($_POST['refunded_at'] ?? date('Y-m-d H:i:s'));
    if ($amount <= 0) { flash('error', __('err_refund_amount_required')); redirect(BP_URL.'admin/invoice-view.php?id='.$id); }
    if ($amount > (float)$invoice['paid_amount'] + 0.01) {
        flash('error', __('err_refund_exceeds_paid'));
        redirect(BP_URL.'admin/invoice-view.php?id='.$id);
    }
    db()->prepare("INSERT INTO refunds
        (refund_no,invoice_id,patient_id,amount,method,reason,refunded_at,created_by,created_at)
        VALUES (?,?,?,?,?,?,?,?,NOW())")
        ->execute([
            next_refund_no(), $id, $invoice['patient_id'], $amount, $method, $reason,
            str_replace('T',' ',$date), $_SESSION['user_id']
        ]);
    $rid = (int)db()->lastInsertId();
    recompute_invoice($id);
    log_activity('refund','invoices',"Refunded ".format_money($amount)." on invoice {$invoice['invoice_no']}",'refund',$rid);
    flash('success', __('refund_recorded'));
    redirect(BP_URL.'admin/invoice-view.php?id='.$id);
}

$items    = db()->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id");
$items->execute([$id]); $items = $items->fetchAll();

$payments = db()->prepare("SELECT * FROM payments WHERE invoice_id=? AND deleted_at IS NULL ORDER BY paid_at DESC, id DESC");
$payments->execute([$id]); $payments = $payments->fetchAll();

$refunds  = db()->prepare("SELECT * FROM refunds WHERE invoice_id=? AND deleted_at IS NULL ORDER BY refunded_at DESC, id DESC");
$refunds->execute([$id]); $refunds = $refunds->fetchAll();

$insts = db()->prepare("SELECT * FROM installments WHERE invoice_id=? AND deleted_at IS NULL ORDER BY due_date");
$insts->execute([$id]); $insts = $insts->fetchAll();

$statusMeta = [
    'draft'    => ['color'=>'#64748b','icon'=>'fa-file-pen'],
    'issued'   => ['color'=>'#3b82f6','icon'=>'fa-paper-plane'],
    'partial'  => ['color'=>'#f59e0b','icon'=>'fa-hourglass-half'],
    'paid'     => ['color'=>'#10b981','icon'=>'fa-check-double'],
    'refunded' => ['color'=>'#0ea5e9','icon'=>'fa-rotate-left'],
    'cancelled'=> ['color'=>'#ef4444','icon'=>'fa-ban'],
];
$sm = $statusMeta[$invoice['status']] ?? ['color'=>'#64748b','icon'=>'fa-file-invoice-dollar'];
$rtl = (($_SESSION['admin_lang'] ?? 'ar') === 'ar');
$paidPct = $invoice['total'] > 0 ? min(100, round(($invoice['paid_amount']/$invoice['total'])*100)) : 0;
$isOverdueInv = $invoice['due_date'] && $invoice['balance']>0 && strtotime($invoice['due_date']) < strtotime(date('Y-m-d')) && $invoice['status']!=='cancelled';

include BP_PARTIALS . '/header.php';
?>
<div class="page-wrap invview">
    <!-- ═══════ HERO ═══════ -->
    <div class="invview-hero" style="--st-color:<?= $sm['color'] ?>">
        <div class="invview-hero-bg"></div>
        <div class="invview-hero-content">
            <div class="invview-hero-top">
                <div class="invview-hero-mark">
                    <div class="invview-hero-mark-icon"><i class="fa-solid <?= $sm['icon'] ?>"></i></div>
                    <div>
                        <div class="invview-hero-label"><?= __('invoice_no') ?></div>
                        <div class="invview-hero-no"><?= e($invoice['invoice_no']) ?></div>
                    </div>
                </div>
                <div class="invview-hero-actions">
                    <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/invoices.php">
                        <i class="fa-solid fa-arrow-<?= $rtl?'right':'left' ?> me-1"></i><?= __('back_to_list') ?>
                    </a>
                    <a class="btn btn-light btn-sm" target="_blank" href="<?= BP_URL ?>admin/invoice-pdf.php?id=<?= $id ?>">
                        <i class="fa-solid fa-file-pdf me-1"></i>PDF
                    </a>
                    <a class="btn btn-light btn-sm" target="_blank" href="<?= BP_URL ?>admin/invoice-pdf.php?id=<?= $id ?>&download=1">
                        <i class="fa-solid fa-download me-1"></i><?= __('download') ?>
                    </a>
                    <?php if (can('invoices.edit') && in_array($invoice['status'],['draft','issued','partial'])): ?>
                        <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/invoices.php?action=edit&id=<?= $id ?>" data-modal>
                            <i class="fa-solid fa-pen me-1"></i><?= __('edit') ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="invview-hero-chips">
                <span class="invview-chip invview-chip-status"><i class="fa-solid <?= $sm['icon'] ?>"></i><?= __('inv_'.$invoice['status']) ?: $invoice['status'] ?></span>
                <span class="invview-chip"><i class="fa-regular fa-calendar"></i><?= __('issue_date') ?>: <?= e($invoice['issue_date']) ?></span>
                <?php if ($invoice['due_date']): ?>
                    <span class="invview-chip <?= $isOverdueInv?'invview-chip-danger':'' ?>"><i class="fa-regular fa-calendar-check"></i><?= __('due_date') ?>: <?= e($invoice['due_date']) ?><?php if ($isOverdueInv): ?> · <?= __('st_overdue') ?><?php endif; ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ═══════ SUMMARY KPI strip ═══════ -->
    <div class="appt-kpis">
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#6366f1"><i class="fa-solid fa-receipt"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('total') ?></div>
                <div class="appt-kpi-value" style="font-size:1.05rem"><?= format_money($invoice['total']) ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#10b981"><i class="fa-solid fa-money-bill-wave"></i></div>
            <div style="flex:1">
                <div class="appt-kpi-label"><?= __('paid') ?></div>
                <div class="appt-kpi-value text-success" style="font-size:1.05rem"><?= format_money($invoice['paid_amount']) ?></div>
                <div class="invview-progress"><div class="invview-progress-fill" style="width:<?= $paidPct ?>%"></div></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:<?= $invoice['balance']>0?'#ef4444':'#94a3b8' ?>"><i class="fa-solid fa-scale-balanced"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('balance') ?></div>
                <div class="appt-kpi-value <?= $invoice['balance']>0?'text-danger':'' ?>" style="font-size:1.05rem"><?= format_money($invoice['balance']) ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#0d9488"><i class="fa-solid fa-percent"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('paid_pct') ?></div>
                <div class="appt-kpi-value"><?= $paidPct ?>%</div>
            </div>
        </div>
    </div>

    <!-- ═══════ STATUS ACTIONS ═══════ -->
    <?php if (can('invoices.edit') && in_array($invoice['status'],['draft','issued','partial'])): ?>
        <div class="invview-toolbar">
            <?php if ($invoice['status']==='draft'): ?>
                <form method="post" action="?id=<?= $id ?>&action=issue" class="m-0">
                    <?= csrf_field() ?>
                    <button class="btn btn-primary btn-sm"><i class="fa-solid fa-paper-plane me-2"></i><?= __('issue') ?></button>
                </form>
            <?php endif; ?>
            <?php if ($invoice['status'] !== 'cancelled'): ?>
                <form method="post" action="?id=<?= $id ?>&action=cancel" class="m-0">
                    <?= csrf_field() ?>
                    <button class="btn btn-outline-danger btn-sm" data-confirm="<?= __('confirm_cancel_invoice') ?>">
                        <i class="fa-solid fa-ban me-2"></i><?= __('cancel_invoice') ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="row g-3">
        <!-- ═══════ MAIN COLUMN ═══════ -->
        <div class="col-lg-8">

            <!-- From / To card -->
            <div class="invview-card">
                <div class="invview-fromto">
                    <div class="invview-fromto-side">
                        <div class="invview-fromto-eyebrow"><i class="fa-solid fa-building"></i> <?= __('from') ?></div>
                        <div class="invview-fromto-name"><?= e(setting('site_name_ar', APP_NAME_AR)) ?></div>
                        <?php if (setting('address','')): ?>
                            <div class="invview-fromto-line"><i class="fa-solid fa-location-dot text-muted"></i> <?= e(setting('address','')) ?></div>
                        <?php endif; ?>
                        <?php if (setting('contact_phone','')): ?>
                            <div class="invview-fromto-line"><i class="fa-solid fa-phone text-muted"></i> <span dir="ltr"><?= e(setting('contact_phone','')) ?></span></div>
                        <?php endif; ?>
                        <?php if (setting('contact_email','')): ?>
                            <div class="invview-fromto-line"><i class="fa-solid fa-envelope text-muted"></i> <span dir="ltr"><?= e(setting('contact_email','')) ?></span></div>
                        <?php endif; ?>
                    </div>
                    <div class="invview-fromto-arrow"><i class="fa-solid fa-arrow-<?= $rtl?'left':'right' ?>"></i></div>
                    <div class="invview-fromto-side invview-fromto-bill">
                        <div class="invview-fromto-eyebrow"><i class="fa-solid fa-user"></i> <?= __('bill_to') ?></div>
                        <div class="invview-fromto-name"><?= e($invoice['first_name'].' '.$invoice['last_name']) ?> <code class="ms-1 small"><?= e($invoice['patient_code']) ?></code></div>
                        <?php if ($invoice['address']): ?>
                            <div class="invview-fromto-line"><i class="fa-solid fa-location-dot text-muted"></i> <?= e($invoice['address']) ?></div>
                        <?php endif; ?>
                        <?php if ($invoice['phone']): ?>
                            <div class="invview-fromto-line"><i class="fa-solid fa-phone text-muted"></i> <span dir="ltr"><?= e($invoice['phone']) ?></span></div>
                        <?php endif; ?>
                        <?php if ($invoice['email']): ?>
                            <div class="invview-fromto-line"><i class="fa-solid fa-envelope text-muted"></i> <span dir="ltr"><?= e($invoice['email']) ?></span></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Line items + totals -->
            <div class="invview-card mt-3">
                <div class="invview-card-head"><i class="fa-solid fa-list-ul text-teal"></i> <?= __('line_items') ?> <span class="badge bg-light text-dark ms-2"><?= count($items) ?></span></div>
                <div class="table-responsive">
                    <table class="table mb-0 align-middle invview-items">
                        <thead>
                            <tr>
                                <th style="width:40px">#</th>
                                <th><?= __('role_description') ?></th>
                                <th class="text-end" style="width:80px"><?= __('qty') ?></th>
                                <th class="text-end" style="width:110px"><?= __('unit_price') ?></th>
                                <th class="text-end" style="width:100px"><?= __('discount') ?></th>
                                <th class="text-end" style="width:120px"><?= __('total') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $idx => $it): ?>
                                <tr>
                                    <td class="text-muted small"><?= $idx+1 ?></td>
                                    <td><?= e($it['description']) ?></td>
                                    <td class="text-end"><?= e($it['quantity']) ?></td>
                                    <td class="text-end"><?= format_money($it['unit_price']) ?></td>
                                    <td class="text-end <?= $it['discount']>0?'text-danger':'text-muted' ?>"><?= $it['discount']>0?'−':'' ?><?= format_money($it['discount']) ?></td>
                                    <td class="text-end fw-semibold"><?= format_money($it['total']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="invview-totals">
                    <div class="invview-totals-row">
                        <span><?= __('subtotal') ?></span>
                        <strong><?= format_money($invoice['subtotal']) ?></strong>
                    </div>
                    <?php if ((float)$invoice['discount'] > 0): ?>
                        <div class="invview-totals-row text-danger">
                            <span><?= __('discount') ?></span>
                            <strong>−<?= format_money($invoice['discount']) ?></strong>
                        </div>
                    <?php endif; ?>
                    <?php if ((float)$invoice['tax'] > 0): ?>
                        <div class="invview-totals-row">
                            <span><?= __('tax') ?></span>
                            <strong>+<?= format_money($invoice['tax']) ?></strong>
                        </div>
                    <?php endif; ?>
                    <div class="invview-totals-row invview-totals-grand">
                        <span><?= __('total') ?></span>
                        <strong><?= format_money($invoice['total']) ?></strong>
                    </div>
                    <div class="invview-totals-row text-success">
                        <span><?= __('paid') ?></span>
                        <strong>+<?= format_money($invoice['paid_amount']) ?></strong>
                    </div>
                    <div class="invview-totals-row <?= $invoice['balance']>0?'text-danger':'text-muted' ?>">
                        <span><?= __('balance') ?></span>
                        <strong><?= format_money($invoice['balance']) ?></strong>
                    </div>
                </div>

                <?php if ($invoice['notes']): ?>
                    <div class="invview-notes"><i class="fa-regular fa-note-sticky text-muted me-1"></i><strong><?= __('notes') ?>:</strong> <?= nl2br(e($invoice['notes'])) ?></div>
                <?php endif; ?>
            </div>

            <!-- Installments -->
            <?php if ($insts): ?>
                <div class="invview-card mt-3">
                    <div class="invview-card-head"><i class="fa-solid fa-calendar-day text-teal"></i> <?= __('installments') ?> <span class="badge bg-light text-dark ms-2"><?= count($insts) ?></span></div>
                    <div class="table-responsive">
                        <table class="table mb-0 align-middle invview-items">
                            <thead><tr><th style="width:40px">#</th><th><?= __('due_date') ?></th><th class="text-end"><?= __('amount') ?></th><th class="text-end"><?= __('paid') ?></th><th><?= __('status') ?></th></tr></thead>
                            <tbody>
                                <?php foreach ($insts as $idx => $i):
                                    $sc = ['pending'=>'warning','paid'=>'success','overdue'=>'danger','waived'=>'secondary'][$i['status']] ?? 'light';
                                    $isOverdue = $i['status']!=='paid' && $i['status']!=='waived' && strtotime($i['due_date']) < strtotime(date('Y-m-d'));
                                ?>
                                    <tr>
                                        <td class="text-muted small"><?= $idx+1 ?></td>
                                        <td><?= e($i['due_date']) ?></td>
                                        <td class="text-end"><?= format_money($i['amount']) ?></td>
                                        <td class="text-end text-success"><?= format_money($i['paid_amount']) ?></td>
                                        <td>
                                            <span class="badge bg-<?= $sc ?>"><?= __('st_'.$i['status']) ?: $i['status'] ?></span>
                                            <?php if ($isOverdue): ?><span class="badge bg-danger ms-1"><?= __('st_overdue') ?></span><?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Payments -->
            <?php if ($payments): ?>
                <div class="invview-card mt-3">
                    <div class="invview-card-head"><i class="fa-solid fa-money-bill-wave text-teal"></i> <?= __('payments') ?> <span class="badge bg-light text-dark ms-2"><?= count($payments) ?></span></div>
                    <div class="table-responsive">
                        <table class="table mb-0 align-middle invview-items">
                            <thead><tr><th><?= __('receipt_no') ?></th><th><?= __('date') ?></th><th><?= __('method') ?></th><th class="text-end"><?= __('amount') ?></th><th><?= __('reference') ?></th></tr></thead>
                            <tbody>
                                <?php foreach ($payments as $p): ?>
                                    <tr>
                                        <td><code class="small"><?= e($p['receipt_no']) ?></code></td>
                                        <td class="small"><?= format_date($p['paid_at']) ?></td>
                                        <td><span class="badge bg-light text-dark"><?= __('m_'.$p['method']) ?: $p['method'] ?></span></td>
                                        <td class="text-end text-success fw-semibold">+<?= format_money($p['amount']) ?></td>
                                        <td class="small text-muted"><?= e($p['reference_no']??'—') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Refunds -->
            <?php if ($refunds): ?>
                <div class="invview-card mt-3">
                    <div class="invview-card-head"><i class="fa-solid fa-rotate-left text-teal"></i> <?= __('refunds') ?> <span class="badge bg-light text-dark ms-2"><?= count($refunds) ?></span></div>
                    <div class="table-responsive">
                        <table class="table mb-0 align-middle invview-items">
                            <thead><tr><th>#</th><th><?= __('date') ?></th><th><?= __('method') ?></th><th class="text-end"><?= __('amount') ?></th><th><?= __('reason') ?></th></tr></thead>
                            <tbody>
                                <?php foreach ($refunds as $r): ?>
                                    <tr>
                                        <td><code class="small"><?= e($r['refund_no']) ?></code></td>
                                        <td class="small"><?= format_date($r['refunded_at']) ?></td>
                                        <td><span class="badge bg-light text-dark"><?= __('m_'.$r['method']) ?: $r['method'] ?></span></td>
                                        <td class="text-end text-danger fw-semibold">−<?= format_money($r['amount']) ?></td>
                                        <td class="small text-muted"><?= e($r['reason']??'—') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- ═══════ SIDEBAR ═══════ -->
        <div class="col-lg-4">
            <?php if (can('payments.create') && $invoice['balance'] > 0 && $invoice['status'] !== 'cancelled'): ?>
                <div class="invview-card invview-form">
                    <div class="invview-card-head"><i class="fa-solid fa-money-bill-wave text-teal"></i> <?= __('record_payment') ?></div>
                    <div class="invview-card-body">
                        <form method="post" action="?id=<?= $id ?>&action=pay">
                            <?= csrf_field() ?>
                            <div class="invview-amount-input">
                                <label class="form-label small text-muted"><?= __('amount') ?></label>
                                <div class="invview-amount-row">
                                    <input name="amount" type="number" step="0.01" min="0.01" max="<?= e($invoice['balance']) ?>" required class="form-control form-control-lg" value="<?= e($invoice['balance']) ?>">
                                    <span class="invview-amount-curr"><?= APP_CURRENCY ?></span>
                                </div>
                            </div>
                            <div class="row g-2 mt-2">
                                <div class="col-12">
                                    <label class="form-label small text-muted"><?= __('method') ?></label>
                                    <div class="invview-method-tabs" data-target="method-pay">
                                        <?php foreach (['cash'=>'fa-money-bill','card'=>'fa-credit-card','bank'=>'fa-building-columns','online'=>'fa-globe','other'=>'fa-ellipsis'] as $m=>$icon): ?>
                                            <label class="invview-method <?= $m==='cash'?'is-active':'' ?>">
                                                <input type="radio" name="method" value="<?= $m ?>" <?= $m==='cash'?'checked':'' ?> hidden>
                                                <i class="fa-solid <?= $icon ?>"></i>
                                                <span><?= __('m_'.$m) ?: $m ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label small text-muted"><?= __('date') ?></label>
                                    <input type="datetime-local" name="paid_at" class="form-control form-control-sm" value="<?= date('Y-m-d\TH:i') ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label small text-muted"><?= __('reference') ?> #</label>
                                    <input name="reference_no" class="form-control form-control-sm" placeholder="—">
                                </div>
                                <?php if ($insts): ?>
                                    <div class="col-12">
                                        <label class="form-label small text-muted"><?= __('apply_to_installment') ?></label>
                                        <select name="installment_id" class="form-select form-select-sm">
                                            <option value="">—</option>
                                            <?php foreach ($insts as $i): ?>
                                                <?php if ($i['status']==='paid' || $i['status']==='waived') continue; ?>
                                                <option value="<?= (int)$i['id'] ?>">
                                                    <?= e($i['due_date']) ?> · <?= format_money($i['amount']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                <?php endif; ?>
                                <div class="col-12">
                                    <label class="form-label small text-muted"><?= __('notes') ?></label>
                                    <input name="notes" class="form-control form-control-sm" placeholder="—">
                                </div>
                                <div class="col-12 mt-3">
                                    <button class="btn btn-teal w-100"><i class="fa-solid fa-circle-check me-1"></i><?= __('record_payment') ?></button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (can('invoices.refund') && $invoice['paid_amount'] > 0): ?>
                <div class="invview-card invview-form mt-3">
                    <div class="invview-card-head"><i class="fa-solid fa-rotate-left text-teal"></i> <?= __('issue_refund') ?></div>
                    <div class="invview-card-body">
                        <form method="post" action="?id=<?= $id ?>&action=refund">
                            <?= csrf_field() ?>
                            <div class="invview-amount-input">
                                <label class="form-label small text-muted"><?= __('amount') ?></label>
                                <div class="invview-amount-row">
                                    <input name="amount" type="number" step="0.01" min="0.01" max="<?= e($invoice['paid_amount']) ?>" required class="form-control form-control-lg">
                                    <span class="invview-amount-curr"><?= APP_CURRENCY ?></span>
                                </div>
                                <div class="small text-muted mt-1"><?= __('max') ?>: <?= format_money($invoice['paid_amount']) ?></div>
                            </div>
                            <div class="row g-2 mt-2">
                                <div class="col-12">
                                    <label class="form-label small text-muted"><?= __('method') ?></label>
                                    <div class="invview-method-tabs" data-target="method-ref">
                                        <?php foreach (['cash'=>'fa-money-bill','card'=>'fa-credit-card','bank'=>'fa-building-columns','online'=>'fa-globe','other'=>'fa-ellipsis'] as $m=>$icon): ?>
                                            <label class="invview-method <?= $m==='cash'?'is-active':'' ?>">
                                                <input type="radio" name="method" value="<?= $m ?>" <?= $m==='cash'?'checked':'' ?> hidden>
                                                <i class="fa-solid <?= $icon ?>"></i>
                                                <span><?= __('m_'.$m) ?: $m ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label small text-muted"><?= __('date') ?></label>
                                    <input type="datetime-local" name="refunded_at" class="form-control form-control-sm" value="<?= date('Y-m-d\TH:i') ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label small text-muted"><?= __('reason') ?></label>
                                    <input name="reason" class="form-control form-control-sm" placeholder="—">
                                </div>
                                <div class="col-12 mt-3">
                                    <button class="btn btn-outline-danger w-100" data-confirm="<?= __('confirm_refund') ?>">
                                        <i class="fa-solid fa-rotate-left me-1"></i><?= __('issue_refund') ?>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.invview-hero{position:relative;border-radius:18px;overflow:hidden;margin-bottom:1rem;color:#fff;box-shadow:0 10px 30px rgba(15,23,42,.12)}
.invview-hero-bg{position:absolute;inset:0;background:linear-gradient(135deg,var(--st-color) 0%,#0f172a 200%);opacity:1}
.invview-hero-bg::after{content:'';position:absolute;inset:0;background:radial-gradient(circle at 80% 20%,rgba(255,255,255,.12),transparent 50%)}
.invview-hero-content{position:relative;padding:1.75rem 1.75rem 1.5rem;display:flex;flex-direction:column;gap:1.25rem}
.invview-hero-top{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem}
.invview-hero-mark{display:flex;align-items:center;gap:1rem}
.invview-hero-mark-icon{width:56px;height:56px;border-radius:14px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-size:1.5rem;backdrop-filter:blur(8px)}
.invview-hero-label{font-size:.7rem;letter-spacing:.18em;text-transform:uppercase;opacity:.85;font-weight:600}
.invview-hero-no{font-size:1.8rem;font-weight:800;font-family:'SF Mono','Courier New',monospace;letter-spacing:.02em;margin-top:2px}
.invview-hero-actions{display:flex;gap:.4rem;flex-wrap:wrap}
.invview-hero-chips{display:flex;gap:.5rem;flex-wrap:wrap}
.invview-chip{display:inline-flex;align-items:center;gap:.4rem;background:rgba(255,255,255,.18);backdrop-filter:blur(8px);padding:.4rem .8rem;border-radius:999px;font-size:.82rem;font-weight:500}
.invview-chip i{font-size:.75rem;opacity:.9}
.invview-chip-status{background:#fff;color:var(--st-color);font-weight:700}
.invview-chip-danger{background:rgba(239,68,68,.4);color:#fff}

.invview-progress{height:5px;background:rgba(148,163,184,.25);border-radius:99px;overflow:hidden;margin-top:.3rem}
.invview-progress-fill{height:100%;background:linear-gradient(90deg,#10b981,#0d9488);transition:width .5s}

.invview-toolbar{display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap}

.invview-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04)}
.invview-card-head{padding:.85rem 1.1rem;border-bottom:1px solid #f1f5f9;background:#fafbfc;font-weight:600;font-size:.95rem;display:flex;align-items:center;gap:.5rem}
.invview-card-body{padding:1.1rem}

/* From → To */
.invview-fromto{display:grid;grid-template-columns:1fr 40px 1fr;align-items:stretch;padding:1.25rem}
.invview-fromto-side{display:flex;flex-direction:column;gap:.3rem}
.invview-fromto-bill{padding-inline-start:1.25rem;border-inline-start:1px dashed #e2e8f0}
.invview-fromto-eyebrow{font-size:.7rem;letter-spacing:.16em;text-transform:uppercase;color:#0d9488;font-weight:600;display:flex;align-items:center;gap:.4rem;margin-bottom:.5rem}
.invview-fromto-name{font-size:1.05rem;font-weight:700;color:#0f172a;margin-bottom:.3rem}
.invview-fromto-line{font-size:.85rem;color:#475569;display:flex;align-items:center;gap:.5rem;line-height:1.6}
.invview-fromto-line i{width:14px;font-size:.75rem}
.invview-fromto-arrow{display:flex;align-items:center;justify-content:center;color:#cbd5e1;font-size:1.1rem}

/* Items table */
.invview-items thead{background:#f8fafc}
.invview-items thead th{font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0}
.invview-items tbody tr{border-bottom:1px solid #f1f5f9}
.invview-items tbody tr:last-child{border-bottom:0}

/* Totals */
.invview-totals{padding:.75rem 1.25rem;background:#fafbfc;border-top:1px solid #e2e8f0}
.invview-totals-row{display:flex;justify-content:space-between;padding:.35rem 0;font-size:.9rem;color:#475569}
.invview-totals-grand{font-size:1.1rem;color:#0f172a;border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;margin:.4rem 0;padding:.6rem 0}

.invview-notes{padding:.85rem 1.25rem;background:#fef3c7;border-top:1px solid #fde68a;font-size:.85rem;color:#78350f}

/* Sidebar forms */
.invview-amount-input{background:linear-gradient(135deg,#ecfdf5,#f0fdfa);padding:.85rem 1rem;border-radius:11px;border:1px solid #99f6e4}
.invview-amount-row{display:flex;align-items:center;gap:.5rem}
.invview-amount-row input{font-size:1.5rem;font-weight:700;border:0;background:transparent;padding:0;color:#0d9488;direction:ltr}
.invview-amount-row input:focus{box-shadow:none;outline:0}
.invview-amount-curr{font-size:.85rem;color:#64748b;font-weight:600}

.invview-method-tabs{display:grid;grid-template-columns:repeat(5,1fr);gap:.3rem}
.invview-method{display:flex;flex-direction:column;align-items:center;gap:.2rem;padding:.5rem .25rem;border:1px solid #e2e8f0;border-radius:9px;cursor:pointer;background:#fff;font-size:.7rem;color:#64748b;transition:.15s;margin:0}
.invview-method:hover{border-color:#5eead4;background:#f0fdfa}
.invview-method i{font-size:1rem}
.invview-method.is-active{background:#0d9488;color:#fff;border-color:#0d9488}

@media (max-width: 768px){
    .invview-fromto{grid-template-columns:1fr;gap:1rem}
    .invview-fromto-arrow{transform:rotate(90deg)}
    .invview-fromto-bill{padding-inline-start:0;border-inline-start:0;border-top:1px dashed #e2e8f0;padding-top:1rem}
    .invview-method-tabs{grid-template-columns:repeat(5,1fr);font-size:.65rem}
    .invview-method span{font-size:.65rem}
}
</style>
<script>
// Method radio → label highlight
document.querySelectorAll('.invview-method-tabs').forEach(group => {
    group.addEventListener('click', e => {
        const lbl = e.target.closest('.invview-method');
        if (!lbl) return;
        group.querySelectorAll('.invview-method').forEach(l => l.classList.remove('is-active'));
        lbl.classList.add('is-active');
        const inp = lbl.querySelector('input[type=radio]');
        if (inp) inp.checked = true;
    });
});
</script>
<?php include BP_PARTIALS . '/footer.php'; ?>
