<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('invoices.view');

$PageTitle = __('invoices');
$action    = $_GET['action'] ?? 'list';
$id        = (int)($_GET['id'] ?? 0);

// ─── Cancel / Delete ──────────────────────────────────────────────────
if ($action === 'cancel' && $id) {
    csrf_check(); require_can('invoices.edit');
    db()->prepare("UPDATE invoices SET status='cancelled', updated_by=?, updated_at=NOW() WHERE id=?")
        ->execute([$_SESSION['user_id'],$id]);
    $patientId = (int)db()->query("SELECT patient_id FROM invoices WHERE id=$id")->fetchColumn();
    if ($patientId) sync_patient_balance($patientId);
    log_activity('cancelled','invoices',"Cancelled invoice #$id",'invoice',$id);
    flash('success', __('invoice_cancelled'));
    redirect(BP_URL.'admin/invoice-view.php?id='.$id);
}

if ($action === 'delete' && $id) {
    csrf_check(); require_can('invoices.delete');
    $patientId = (int)db()->query("SELECT patient_id FROM invoices WHERE id=$id")->fetchColumn();
    db()->prepare("UPDATE invoices SET deleted_at=NOW() WHERE id=?")->execute([$id]);
    if ($patientId) sync_patient_balance($patientId);
    log_activity('deleted','invoices',"Soft-deleted invoice #$id",'invoice',$id);
    flash('success', __('invoice_deleted'));
    redirect(BP_URL.'admin/invoices.php');
}

if ($action === 'issue' && $id) {
    csrf_check(); require_can('invoices.edit');
    db()->prepare("UPDATE invoices SET status='issued', updated_by=?, updated_at=NOW() WHERE id=? AND status='draft'")
        ->execute([$_SESSION['user_id'],$id]);
    log_activity('issued','invoices',"Issued invoice #$id",'invoice',$id);
    recompute_invoice($id);
    flash('success', __('invoice_issued'));
    redirect(BP_URL.'admin/invoice-view.php?id='.$id);
}

// ─── CREATE / EDIT POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['create','edit'], true)) {
    csrf_check();
    require_can($action==='create'?'invoices.create':'invoices.edit');

    $patientId = (int)($_POST['patient_id'] ?? 0);
    $apptId    = (int)($_POST['appointment_id'] ?? 0) ?: null;
    $csId      = (int)($_POST['consultation_id'] ?? 0) ?: null;
    $issueDate = trim($_POST['issue_date'] ?? date('Y-m-d'));
    $dueDate   = trim($_POST['due_date'] ?? '') ?: null;
    $discount  = (float)($_POST['discount'] ?? 0);
    $tax       = (float)($_POST['tax'] ?? 0);
    $notes     = trim($_POST['notes'] ?? '');
    $items     = $_POST['items'] ?? [];           // [[description, qty, unit, discount, service_id]]

    // Installments
    $installAmounts = $_POST['inst_amount'] ?? [];
    $installDates   = $_POST['inst_due']    ?? [];

    $errors = [];
    if (!$patientId) $errors[] = __('err_pick_patient');
    if (!is_array($items) || !count(array_filter($items, fn($i) => trim($i['description'] ?? '') !== ''))) {
        $errors[] = __('err_add_line_item');
    }

    if ($errors) { foreach ($errors as $e) flash('error',$e); set_old($_POST); back(); }

    db()->beginTransaction();
    try {
        if ($action === 'create') {
            db()->prepare("INSERT INTO invoices
                (invoice_no,patient_id,appointment_id,consultation_id,issue_date,due_date,
                 discount,tax,status,currency,notes,created_by,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?,'draft',?,?,?,NOW(),NOW())")
                ->execute([
                    next_invoice_no(), $patientId, $apptId, $csId,
                    $issueDate, $dueDate, $discount, $tax,
                    APP_CURRENCY, $notes, $_SESSION['user_id']
                ]);
            $invId = (int)db()->lastInsertId();
        } else {
            db()->prepare("UPDATE invoices SET
                patient_id=?,appointment_id=?,consultation_id=?,issue_date=?,due_date=?,
                discount=?,tax=?,notes=?,updated_by=?,updated_at=NOW() WHERE id=?")
                ->execute([
                    $patientId,$apptId,$csId,$issueDate,$dueDate,$discount,$tax,
                    $notes,$_SESSION['user_id'],$id
                ]);
            $invId = $id;
            db()->prepare("DELETE FROM invoice_items WHERE invoice_id=?")->execute([$invId]);
        }

        $insI = db()->prepare("INSERT INTO invoice_items
            (invoice_id, service_id, description, quantity, unit_price, discount, total)
            VALUES (?,?,?,?,?,?,?)");
        foreach ($items as $it) {
            $desc = trim($it['description'] ?? '');
            if ($desc === '') continue;
            $qty  = (float)($it['qty'] ?? 1);
            $unit = (float)($it['unit'] ?? 0);
            $idis = (float)($it['discount'] ?? 0);
            $svId = (int)($it['service_id'] ?? 0) ?: null;
            $line = max(0, $qty * $unit - $idis);
            $insI->execute([$invId, $svId, $desc, $qty, $unit, $idis, $line]);
        }

        // Replace installments
        db()->prepare("DELETE FROM installments WHERE invoice_id=? AND deleted_at IS NULL")->execute([$invId]);
        if (is_array($installAmounts)) {
            $insIns = db()->prepare("INSERT INTO installments
                (patient_id,invoice_id,amount,due_date,status,created_at,updated_at)
                VALUES (?,?,?,?,'pending',NOW(),NOW())");
            foreach ($installAmounts as $idx => $amt) {
                $a = (float)$amt;
                $d = trim($installDates[$idx] ?? '');
                if ($a > 0 && $d !== '') $insIns->execute([$patientId,$invId,$a,$d]);
            }
        }

        recompute_invoice($invId);
        db()->commit();
        log_activity($action==='create'?'created':'updated','invoices',"Saved invoice #$invId",'invoice',$invId);
        flash('success', __('invoice_saved'));
        redirect(BP_URL.'admin/invoice-view.php?id='.$invId);
    } catch (Throwable $e) {
        db()->rollBack();
        flash('error', __('failed') . ': ' . $e->getMessage());
        back();
    }
}

// ─── CREATE / EDIT FORM ───────────────────────────────────────────────
if (in_array($action,['create','edit'],true)) {
    require_can($action==='create'?'invoices.create':'invoices.edit');

    $inv = ['patient_id'=>0,'appointment_id'=>0,'consultation_id'=>0,
            'issue_date'=>date('Y-m-d'),'due_date'=>'','discount'=>0,'tax'=>0,'notes'=>''];
    $items = [];
    $installments = [];
    if ($action==='edit' && $id) {
        $s = db()->prepare("SELECT * FROM invoices WHERE id=? AND deleted_at IS NULL");
        $s->execute([$id]); $inv = $s->fetch();
        if (!$inv) { flash('error', __('not_found')); redirect(BP_URL.'admin/invoices.php'); }
        $i = db()->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id"); $i->execute([$id]); $items = $i->fetchAll();
        $ii = db()->prepare("SELECT * FROM installments WHERE invoice_id=? AND deleted_at IS NULL ORDER BY due_date");
        $ii->execute([$id]); $installments = $ii->fetchAll();
    }

    // Pre-fill from appointment / consultation
    if ($action==='create') {
        if (!empty($_GET['from_appointment'])) {
            $aid = (int)$_GET['from_appointment'];
            $a = db()->prepare("SELECT a.*, p.id AS pid FROM appointments a JOIN patients p ON p.id=a.patient_id WHERE a.id=?");
            $a->execute([$aid]); $row = $a->fetch();
            if ($row) {
                $inv['patient_id'] = (int)$row['pid'];
                $inv['appointment_id'] = $aid;
                $svcs = db()->prepare("SELECT s.id, s.name_ar, asv.price, asv.duration_minutes
                                       FROM appointment_services asv JOIN services s ON s.id=asv.service_id
                                       WHERE asv.appointment_id=?");
                $svcs->execute([$aid]);
                foreach ($svcs as $sv) {
                    $items[] = ['service_id'=>$sv['id'],'description'=>$sv['name_ar'],
                                'quantity'=>1,'unit_price'=>$sv['price'],'discount'=>0,'total'=>$sv['price']];
                }
            }
        }
        if (!empty($_GET['from_consultation'])) {
            $cid = (int)$_GET['from_consultation'];
            $c = db()->prepare("SELECT c.*, sv.name_ar AS svname FROM consultations c LEFT JOIN services sv ON sv.id=c.service_id WHERE c.id=?");
            $c->execute([$cid]); $row = $c->fetch();
            if ($row) {
                $inv['patient_id'] = (int)$row['patient_id'];
                $inv['consultation_id'] = $cid;
                $items[] = [
                    'service_id'=>$row['service_id'],
                    'description'=>$row['svname'] ?: 'Consultation #'.$cid,
                    'quantity'=>1,'unit_price'=>$row['fee'],'discount'=>0,'total'=>$row['fee']
                ];
            }
        }
        if (!empty($_GET['patient_id'])) $inv['patient_id'] = (int)$_GET['patient_id'];
    }
    if (!$items) {
        $items[] = ['service_id'=>'','description'=>'','quantity'=>1,'unit_price'=>0,'discount'=>0,'total'=>0];
    }

    $patients = db()->query("SELECT id,code,first_name,last_name,phone FROM patients WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 500")->fetchAll();
    $services = db()->query("SELECT id,name_ar,price FROM services WHERE deleted_at IS NULL AND is_active=1 ORDER BY name_ar")->fetchAll();

    include BP_PARTIALS . '/header.php';
    ?>
    <div class="page-wrap">
        <h4 class="mb-3"><?= $action==='create'?__('create'):__('edit') ?> — <?= __('invoices') ?></h4>
        <form method="post" class="card"><div class="card-body">
            <?= csrf_field() ?>
            <input type="hidden" name="appointment_id"  value="<?= (int)$inv['appointment_id'] ?>">
            <input type="hidden" name="consultation_id" value="<?= (int)$inv['consultation_id'] ?>">

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label"><?= __('patients') ?> *</label>
                    <select name="patient_id" class="form-select" required>
                        <option value="">— <?= __('pick_patient') ?> —</option>
                        <?php foreach ($patients as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= (int)$inv['patient_id']===(int)$p['id']?'selected':'' ?>>
                                [<?= e($p['code']) ?>] <?= e($p['first_name'].' '.$p['last_name']) ?> · <?= e($p['phone']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('issue_date') ?> *</label>
                    <input type="date" name="issue_date" required class="form-control" value="<?= e($inv['issue_date']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?= __('due_date') ?></label>
                    <input type="date" name="due_date" class="form-control" value="<?= e($inv['due_date']) ?>">
                </div>
            </div>

            <h6 class="mt-4 text-teal"><i class="fa-solid fa-list me-1"></i><?= __('line_items') ?></h6>
            <div class="table-responsive">
                <table class="table table-sm align-middle" id="itemsTable">
                    <thead>
                        <tr>
                            <th style="min-width:180px"><?= __('services') ?></th>
                            <th><?= __('role_description') ?></th>
                            <th style="width:80px"><?= __('qty') ?></th>
                            <th style="width:120px"><?= __('unit_price') ?></th>
                            <th style="width:120px"><?= __('discount') ?></th>
                            <th class="text-end" style="width:120px"><?= __('total') ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $idx => $it): ?>
                            <tr class="item-row">
                                <td>
                                    <select name="items[<?= $idx ?>][service_id]" class="form-select form-select-sm svc-pick">
                                        <option value="">—</option>
                                        <?php foreach ($services as $sv): ?>
                                            <option value="<?= (int)$sv['id'] ?>"
                                                    data-name="<?= e($sv['name_ar']) ?>"
                                                    data-price="<?= e($sv['price']) ?>"
                                                    <?= (int)($it['service_id'] ?? 0)===(int)$sv['id']?'selected':'' ?>>
                                                <?= e($sv['name_ar']) ?> · <?= format_money($sv['price']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input name="items[<?= $idx ?>][description]" class="form-control form-control-sm desc" value="<?= e($it['description']) ?>"></td>
                                <td><input name="items[<?= $idx ?>][qty]" type="number" min="0" step="0.01" class="form-control form-control-sm qty" value="<?= e($it['quantity'] ?? 1) ?>"></td>
                                <td><input name="items[<?= $idx ?>][unit]" type="number" min="0" step="0.01" class="form-control form-control-sm unit" value="<?= e($it['unit_price'] ?? 0) ?>"></td>
                                <td><input name="items[<?= $idx ?>][discount]" type="number" min="0" step="0.01" class="form-control form-control-sm disc" value="<?= e($it['discount'] ?? 0) ?>"></td>
                                <td class="text-end line-total"><?= format_money($it['total'] ?? 0) ?></td>
                                <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove(); updateTotals();"><i class="fa-solid fa-trash"></i></button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button type="button" class="btn btn-sm btn-outline-teal" onclick="addRow()">
                <i class="fa-solid fa-plus me-1"></i><?= __('add_row') ?>
            </button>

            <div class="row mt-4 g-3">
                <div class="col-md-6">
                    <label class="form-label"><?= __('notes') ?></label>
                    <textarea name="notes" rows="3" class="form-control"><?= e($inv['notes']??'') ?></textarea>

                    <h6 class="mt-4 text-teal"><i class="fa-solid fa-calendar-day me-1"></i><?= __('installments') ?> (<?= __('optional') ?>)</h6>
                    <p class="small text-muted"><?= __('installments_hint') ?></p>
                    <div id="installments">
                        <?php foreach ($installments as $i => $row): ?>
                            <div class="row g-2 mb-2">
                                <div class="col-5"><input name="inst_amount[]" type="number" min="0" step="0.01" class="form-control form-control-sm" value="<?= e($row['amount']) ?>" placeholder="<?= __('amount') ?>"></div>
                                <div class="col-5"><input name="inst_due[]" type="date" class="form-control form-control-sm" value="<?= e($row['due_date']) ?>"></div>
                                <div class="col-2"><button type="button" class="btn btn-sm btn-outline-danger w-100" onclick="this.closest('.row').remove()"><i class="fa-solid fa-trash"></i></button></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-teal" onclick="addInstallment()">
                        <i class="fa-solid fa-plus me-1"></i><?= __('add_installment') ?>
                    </button>
                </div>

                <div class="col-md-6">
                    <div class="card border-teal" style="border-color: var(--nt-teal) !important">
                        <div class="card-body">
                            <h6 class="text-teal"><i class="fa-solid fa-calculator me-1"></i><?= __('totals') ?></h6>
                            <table class="table table-sm mb-0">
                                <tr><td><?= __('subtotal') ?></td><td class="text-end" id="sumSubtotal">—</td></tr>
                                <tr>
                                    <td><?= __('discount') ?></td>
                                    <td class="text-end"><input name="discount" type="number" step="0.01" min="0" class="form-control form-control-sm text-end" value="<?= e($inv['discount']) ?>" oninput="updateTotals()" id="discountInput" style="max-width:140px;float:right"></td>
                                </tr>
                                <tr>
                                    <td><?= __('tax') ?></td>
                                    <td class="text-end"><input name="tax" type="number" step="0.01" min="0" class="form-control form-control-sm text-end" value="<?= e($inv['tax']) ?>" oninput="updateTotals()" id="taxInput" style="max-width:140px;float:right"></td>
                                </tr>
                                <tr class="table-teal" style="background:var(--nt-teal-soft)"><th><?= __('total') ?></th><th class="text-end" id="sumTotal">—</th></tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-3 d-flex gap-2">
                <button class="btn btn-teal"><?= __('save') ?></button>
                <a class="btn btn-light" href="<?= BP_URL ?>admin/invoices.php"><?= __('cancel') ?></a>
            </div>
        </div></form>

        <script>
        (function(){
            let rowIdx = <?= count($items) ?>;
            const CUR = <?= json_encode(APP_CURRENCY) ?>;
            const SVC_OPTIONS = <?= json_encode(array_map(fn($sv) => [
                'id' => (int)$sv['id'],
                'name' => $sv['name_ar'],
                'price' => (float)$sv['price'],
            ], $services), JSON_UNESCAPED_UNICODE) ?>;
            const I18N = {
                amount: <?= json_encode(__('amount')) ?>,
            };
            const fmtMoney = v => Number(v||0).toFixed(2) + ' ' + CUR;
            const svcOptionsHtml = (sel) => '<option value="">—</option>' +
                SVC_OPTIONS.map(o => '<option value="'+o.id+'" data-name="'+o.name.replace(/"/g,'&quot;')+'" data-price="'+o.price+'"'+(sel==o.id?' selected':'')+'>'+o.name+' · '+o.price.toFixed(2)+' '+CUR+'</option>').join('');

            window.addRow = function () {
                const tbody = document.querySelector('#itemsTable tbody');
                const html = `<tr class="item-row">
                    <td><select name="items[${rowIdx}][service_id]" class="form-select form-select-sm svc-pick">${svcOptionsHtml(0)}</select></td>
                    <td><input name="items[${rowIdx}][description]" class="form-control form-control-sm desc"></td>
                    <td><input name="items[${rowIdx}][qty]" type="number" min="0" step="0.01" class="form-control form-control-sm qty" value="1"></td>
                    <td><input name="items[${rowIdx}][unit]" type="number" min="0" step="0.01" class="form-control form-control-sm unit" value="0"></td>
                    <td><input name="items[${rowIdx}][discount]" type="number" min="0" step="0.01" class="form-control form-control-sm disc" value="0"></td>
                    <td class="text-end line-total">0</td>
                    <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove(); updateTotals();"><i class="fa-solid fa-trash"></i></button></td>
                </tr>`;
                tbody.insertAdjacentHTML('beforeend', html);
                rowIdx++;
                bindRows();
                updateTotals();
            };
            window.addInstallment = function () {
                const html = `<div class="row g-2 mb-2">
                    <div class="col-5"><input name="inst_amount[]" type="number" min="0" step="0.01" class="form-control form-control-sm" placeholder="${I18N.amount}"></div>
                    <div class="col-5"><input name="inst_due[]" type="date" class="form-control form-control-sm"></div>
                    <div class="col-2"><button type="button" class="btn btn-sm btn-outline-danger w-100" onclick="this.closest('.row').remove()"><i class="fa-solid fa-trash"></i></button></div>
                </div>`;
                document.getElementById('installments').insertAdjacentHTML('beforeend', html);
            };
            function bindRows() {
                document.querySelectorAll('.item-row').forEach(row => {
                    const svc  = row.querySelector('.svc-pick');
                    const desc = row.querySelector('.desc');
                    const qty  = row.querySelector('.qty');
                    const unit = row.querySelector('.unit');
                    const disc = row.querySelector('.disc');
                    if (svc.dataset.bound) return;
                    svc.dataset.bound = '1';
                    svc.addEventListener('change', () => {
                        const opt = svc.options[svc.selectedIndex];
                        if (opt && opt.value) {
                            if (!desc.value) desc.value = opt.dataset.name;
                            if (!parseFloat(unit.value)) unit.value = opt.dataset.price;
                            recalcRow(row);
                        }
                    });
                    [qty, unit, disc].forEach(el => el.addEventListener('input', () => recalcRow(row)));
                });
            }
            function recalcRow(row) {
                const q = parseFloat(row.querySelector('.qty').value)  || 0;
                const u = parseFloat(row.querySelector('.unit').value) || 0;
                const d = parseFloat(row.querySelector('.disc').value) || 0;
                const t = Math.max(0, q*u - d);
                row.querySelector('.line-total').textContent = fmtMoney(t);
                row.dataset.total = t;
                updateTotals();
            }
            window.updateTotals = function () {
                let sub = 0;
                document.querySelectorAll('.item-row').forEach(r => {
                    const q = parseFloat(r.querySelector('.qty').value)  || 0;
                    const u = parseFloat(r.querySelector('.unit').value) || 0;
                    const d = parseFloat(r.querySelector('.disc').value) || 0;
                    sub += Math.max(0, q*u - d);
                });
                const dis = parseFloat(document.getElementById('discountInput').value) || 0;
                const tax = parseFloat(document.getElementById('taxInput').value) || 0;
                document.getElementById('sumSubtotal').textContent = fmtMoney(sub);
                document.getElementById('sumTotal').textContent = fmtMoney(Math.max(0, sub - dis + tax));
            };
            bindRows();
            updateTotals();
        })();
        </script>
    </div>
    <?php include BP_PARTIALS . '/footer.php'; clear_old(); exit;
}

// ─── LIST ─────────────────────────────────────────────────────────────
$q       = trim($_GET['q'] ?? '');
$status  = trim($_GET['status'] ?? '');
$from    = trim($_GET['from'] ?? '');
$to      = trim($_GET['to'] ?? '');
$page    = (int)($_GET['page'] ?? 1);
[$perPage, $offset] = paginate_query($page, (int)setting('per_page','25'));

$where = "i.deleted_at IS NULL"; $params = [];
if ($q !== '') {
    $where .= " AND (i.invoice_no LIKE ? OR p.code LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? OR p.phone LIKE ?)";
    $like = "%$q%"; array_push($params,$like,$like,$like,$like,$like);
}
if ($status !== '') { $where .= " AND i.status = ?"; $params[] = $status; }
if ($from !== '')   { $where .= " AND i.issue_date >= ?"; $params[] = $from; }
if ($to   !== '')   { $where .= " AND i.issue_date <= ?"; $params[] = $to; }

$tot = db()->prepare("SELECT COUNT(*), COALESCE(SUM(i.total),0), COALESCE(SUM(i.balance),0)
    FROM invoices i JOIN patients p ON p.id = i.patient_id WHERE $where");
$tot->execute($params);
[$total, $sumTotal, $sumBalance] = $tot->fetch(PDO::FETCH_NUM);

$sql = "SELECT i.*, p.code AS patient_code, p.first_name, p.last_name
        FROM invoices i JOIN patients p ON p.id = i.patient_id
        WHERE $where ORDER BY i.id DESC LIMIT $perPage OFFSET $offset";
$stmt = db()->prepare($sql); $stmt->execute($params);
$rows = $stmt->fetchAll();

$colors = ['draft'=>'secondary','issued'=>'primary','partial'=>'warning','paid'=>'success','refunded'=>'info','cancelled'=>'danger'];

// KPI stats — overall (not filtered)
$kpi = db()->query("
    SELECT
      COALESCE(SUM(total),0)                                                  AS revenue,
      COALESCE(SUM(paid_amount),0)                                            AS paid,
      COALESCE(SUM(balance),0)                                                AS outstanding,
      SUM(status IN ('issued','partial'))                                     AS unpaid_count
    FROM invoices WHERE deleted_at IS NULL
")->fetch() ?: ['revenue'=>0,'paid'=>0,'outstanding'=>0,'unpaid_count'=>0];
$activeFilters = ($q !== '') + ($status !== '') + ($from !== '') + ($to !== '');

include BP_PARTIALS . '/header.php';
?>
<div class="page-wrap">
    <!-- Header -->
    <div class="page-header">
        <h4 class="m-0">
            <i class="fa-solid fa-file-invoice-dollar text-teal me-2"></i><?= __('invoices') ?>
            <span class="page-count">(<?= (int)$total ?>)</span>
        </h4>
        <div class="page-header-actions">
            <?php if (can('invoices.create')): ?>
                <a class="btn btn-teal btn-sm" href="?action=create" data-modal>
                    <i class="fa-solid fa-plus me-1"></i><?= __('new_invoice') ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- KPI strip -->
    <div class="appt-kpis">
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#0d9488"><i class="fa-solid fa-file-invoice-dollar"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('total_invoiced') ?></div>
                <div class="appt-kpi-value" style="font-size:1.05rem"><?= format_money($kpi['revenue']) ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#10b981"><i class="fa-solid fa-circle-check"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('total_paid') ?></div>
                <div class="appt-kpi-value" style="font-size:1.05rem"><?= format_money($kpi['paid']) ?></div>
            </div>
        </div>
        <a class="appt-kpi" href="?status=issued">
            <div class="appt-kpi-icon" style="background:#ef4444"><i class="fa-solid fa-flag"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('outstanding') ?></div>
                <div class="appt-kpi-value" style="font-size:1.05rem"><?= format_money($kpi['outstanding']) ?></div>
            </div>
        </a>
        <a class="appt-kpi" href="?status=issued">
            <div class="appt-kpi-icon" style="background:#f59e0b"><i class="fa-solid fa-hourglass-half"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('unpaid_invoices') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['unpaid_count'] ?></div>
            </div>
        </a>
    </div>

    <!-- Filters -->
    <form method="get" class="appt-filters">
        <div class="appt-filter-group flex-grow-1">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="<?= __('search_invoice_placeholder') ?>">
        </div>
        <div class="appt-filter-group">
            <select name="status" class="form-select form-select-sm">
                <option value=""><?= __('all_statuses') ?></option>
                <?php foreach (array_keys($colors) as $st): ?>
                    <option value="<?= $st ?>" <?= $status===$st?'selected':'' ?>><?= __('inv_'.$st) ?: $st ?></option>
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
            <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/invoices.php" title="<?= __('clear_filters') ?>"><i class="fa-solid fa-xmark"></i></a>
        <?php endif; ?>
    </form>

    <div class="table-card">
        <div class="table-responsive d-none d-md-block">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th><?= __('invoice_no') ?></th><th><?= __('date') ?></th><th><?= __('patients') ?></th>
                <th class="text-end"><?= __('total') ?></th><th class="text-end"><?= __('paid') ?></th><th class="text-end"><?= __('balance') ?></th>
                <th><?= __('status') ?></th><th></th>
            </tr></thead>
            <tbody>
                <?php foreach ($rows as $i):
                    $color = $colors[$i['status']] ?? 'secondary';
                ?>
                    <tr>
                        <td><code><?= e($i['invoice_no']) ?></code></td>
                        <td class="small"><?= e($i['issue_date']) ?></td>
                        <td>
                            <a href="<?= BP_URL ?>admin/patient-view.php?id=<?= (int)$i['patient_id'] ?>" class="text-decoration-none">
                                <?= e($i['first_name'].' '.$i['last_name']) ?>
                            </a>
                            <code class="small ms-1"><?= e($i['patient_code']) ?></code>
                        </td>
                        <td class="text-end"><?= format_money($i['total']) ?></td>
                        <td class="text-end"><?= format_money($i['paid_amount']) ?></td>
                        <td class="text-end <?= $i['balance']>0?'text-danger fw-bold':'' ?>"><?= format_money($i['balance']) ?></td>
                        <td><span class="badge bg-<?= $color ?>"><?= __('inv_'.$i['status']) ?></span></td>
                        <td class="text-end">
                            <?= render_actions([
                                ['icon'=>'fa-eye','label'=>'view','href'=>BP_URL.'admin/invoice-view.php?id='.(int)$i['id']],
                                ['icon'=>'fa-file-pdf','label'=>'print','href'=>BP_URL.'admin/invoice-pdf.php?id='.(int)$i['id'],'target'=>'_blank'],
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
                <div class="empty-state"><i class="fa-regular fa-file-lines"></i><div><?= __('no_data') ?></div></div>
            <?php else: foreach ($rows as $i):
                $statusMap = ['draft'=>'','issued'=>'info','partial'=>'warn','paid'=>'success','refunded'=>'info','cancelled'=>'danger'];
                $avatarMap = ['draft'=>'slate','issued'=>'indigo','partial'=>'amber','paid'=>'success','refunded'=>'slate','cancelled'=>'danger'];
                $balance = (float)$i['balance'];
                $chips = [
                    ['label'=>__('inv_'.$i['status']),'icon'=>'fa-circle-dot','class'=>$statusMap[$i['status']] ?? ''],
                ];
                if ($balance > 0) {
                    $chips[] = ['label'=>format_money($balance),'icon'=>'fa-flag','class'=>'danger','tooltip'=>__('balance')];
                } else {
                    $chips[] = ['label'=>__('paid'),'icon'=>'fa-circle-check','class'=>'success'];
                }
                echo render_entity_card([
                    'avatar_icon' => 'fa-file-invoice',
                    'avatar_class' => 'square ' . ($avatarMap[$i['status']] ?? ''),
                    'title' => $i['first_name'].' '.$i['last_name'],
                    'title_href' => BP_URL.'admin/invoice-view.php?id='.(int)$i['id'],
                    'title_right' => format_money($i['total']),
                    'code' => $i['invoice_no'],
                    'meta' => [$i['issue_date']],
                    'chips' => $chips,
                    'actions' => [
                        ['icon'=>'fa-eye','label'=>'view','href'=>BP_URL.'admin/invoice-view.php?id='.(int)$i['id']],
                        ['icon'=>'fa-file-pdf','label'=>'print','href'=>BP_URL.'admin/invoice-pdf.php?id='.(int)$i['id'],'target'=>'_blank'],
                    ],
                ]);
            endforeach; endif; ?>
        </div>

        <div class="p-2"><?= render_pagination($total,$page,$perPage,BP_URL.'admin/invoices.php?'.http_build_query(['q'=>$q,'status'=>$status,'from'=>$from,'to'=>$to])) ?></div>
    </div>
</div>
<?php include BP_PARTIALS . '/footer.php'; ?>
