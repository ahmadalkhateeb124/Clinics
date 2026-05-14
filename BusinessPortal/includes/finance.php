<?php
// ════════════════════════════════════════════════════════════════════════
// Finance helpers — invoice numbering, totals, payments, balance recompute
// ════════════════════════════════════════════════════════════════════════

/** Generate the next invoice number: INV-2026-00001 (resets per year). */
function next_invoice_no(): string {
    $year = date('Y');
    $row = db()->prepare("
        SELECT invoice_no FROM invoices
        WHERE invoice_no LIKE ?
        ORDER BY id DESC LIMIT 1
    ");
    $row->execute(["INV-$year-%"]);
    $last = $row->fetchColumn();
    $next = 1;
    if ($last && preg_match('/(\d+)$/', $last, $m)) $next = ((int)$m[1]) + 1;
    return "INV-$year-" . str_pad((string)$next, 5, '0', STR_PAD_LEFT);
}

/** Generate the next receipt number: RCP-2026-00001 */
function next_receipt_no(): string {
    $year = date('Y');
    $row = db()->prepare("SELECT receipt_no FROM payments WHERE receipt_no LIKE ? ORDER BY id DESC LIMIT 1");
    $row->execute(["RCP-$year-%"]);
    $last = $row->fetchColumn();
    $next = 1;
    if ($last && preg_match('/(\d+)$/', $last, $m)) $next = ((int)$m[1]) + 1;
    return "RCP-$year-" . str_pad((string)$next, 5, '0', STR_PAD_LEFT);
}

/** Generate the next refund / credit-note number: CN-2026-00001 */
function next_refund_no(): string {
    $year = date('Y');
    $row = db()->prepare("SELECT refund_no FROM refunds WHERE refund_no LIKE ? ORDER BY id DESC LIMIT 1");
    $row->execute(["CN-$year-%"]);
    $last = $row->fetchColumn();
    $next = 1;
    if ($last && preg_match('/(\d+)$/', $last, $m)) $next = ((int)$m[1]) + 1;
    return "CN-$year-" . str_pad((string)$next, 5, '0', STR_PAD_LEFT);
}

/**
 * Recompute totals (subtotal, total, paid, balance, status) for an invoice
 * from its items, payments, and refunds. Then sync the patient's outstanding_balance.
 */
function recompute_invoice(int $invoiceId): void {
    $pdo = db();
    $inv = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
    $inv->execute([$invoiceId]);
    $invoice = $inv->fetch();
    if (!$invoice) return;

    $sub = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM invoice_items WHERE invoice_id = $invoiceId")->fetchColumn();
    $discount = (float)$invoice['discount'];
    $tax      = (float)$invoice['tax'];
    $total    = max(0, $sub - $discount + $tax);

    $paid    = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id = $invoiceId AND deleted_at IS NULL AND is_refund = 0")->fetchColumn();
    $refunds = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM refunds  WHERE invoice_id = $invoiceId AND deleted_at IS NULL")->fetchColumn();
    $netPaid = max(0, $paid - $refunds);
    $balance = max(0, $total - $netPaid);

    $status = $invoice['status'];
    if ($status !== 'cancelled') {
        if ($refunds > 0 && $netPaid <= 0)               $status = 'refunded';
        elseif ($balance == 0 && $total > 0)             $status = 'paid';
        elseif ($netPaid > 0 && $balance > 0)            $status = 'partial';
        elseif ($total > 0 && $netPaid == 0 && $invoice['status'] !== 'draft') $status = 'issued';
    }

    $pdo->prepare("
        UPDATE invoices
        SET subtotal = ?, total = ?, paid_amount = ?, balance = ?, status = ?, updated_at = NOW()
        WHERE id = ?
    ")->execute([$sub, $total, $netPaid, $balance, $status, $invoiceId]);

    sync_patient_balance((int)$invoice['patient_id']);
}

/** Recompute the patient's outstanding_balance from open invoices. */
function sync_patient_balance(int $patientId): void {
    $sum = (float)db()->query("
        SELECT COALESCE(SUM(balance), 0)
        FROM invoices
        WHERE patient_id = $patientId
          AND deleted_at IS NULL
          AND status IN ('issued','partial')
    ")->fetchColumn();
    db()->prepare("UPDATE patients SET outstanding_balance = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$sum, $patientId]);
}

/** Update an installment's paid status based on linked payments. */
function recompute_installment(int $installmentId): void {
    $row = db()->prepare("SELECT * FROM installments WHERE id = ?");
    $row->execute([$installmentId]);
    $i = $row->fetch();
    if (!$i) return;
    $paid = (float)db()->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE installment_id = $installmentId AND is_refund=0 AND deleted_at IS NULL")->fetchColumn();
    $status = $i['status'];
    if ($paid >= (float)$i['amount']) {
        $status = 'paid';
    } elseif ($paid > 0) {
        $status = 'pending';
    } elseif (strtotime($i['due_date']) < strtotime(date('Y-m-d'))) {
        $status = 'overdue';
    }
    db()->prepare("UPDATE installments SET paid_amount=?, status=?, paid_at = IF(?='paid', NOW(), paid_at), updated_at=NOW() WHERE id=?")
        ->execute([$paid, $status, $status, $installmentId]);
}

/** Currently open cash drawer session for a user (or null). */
function current_cash_drawer(int $userId): ?array {
    $s = db()->prepare("SELECT * FROM cash_drawer_sessions WHERE user_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1");
    $s->execute([$userId]);
    return $s->fetch() ?: null;
}
