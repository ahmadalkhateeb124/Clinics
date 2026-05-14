<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('invoices.view');

$id = (int)($_GET['id'] ?? 0);

$inv = db()->prepare("
    SELECT i.*, p.code AS patient_code, p.first_name, p.last_name, p.phone, p.email, p.address, p.city
    FROM invoices i JOIN patients p ON p.id = i.patient_id
    WHERE i.id = ? AND i.deleted_at IS NULL
");
$inv->execute([$id]); $invoice = $inv->fetch();
if (!$invoice) { http_response_code(404); exit('Invoice not found.'); }

$items = db()->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id");
$items->execute([$id]); $items = $items->fetchAll();

$payments = db()->prepare("SELECT * FROM payments WHERE invoice_id=? AND deleted_at IS NULL ORDER BY paid_at");
$payments->execute([$id]); $payments = $payments->fetchAll();

$siteName = setting('site_name_ar', APP_NAME_AR);
$address  = setting('address','');
$phone    = setting('contact_phone','');
$email    = setting('contact_email','');
$currency = setting('currency', APP_CURRENCY);

$statusLabels = [
    'draft'    => __('st_draft'),
    'issued'   => __('st_issued'),
    'partial'  => __('st_partial'),
    'paid'     => __('st_paid'),
    'refunded' => __('st_refunded'),
    'cancelled'=> __('st_cancelled'),
];
$methodLabels = [
    'cash'   => __('method_cash'),
    'card'   => __('method_card'),
    'bank'   => __('method_bank'),
    'online' => __('method_online'),
    'other'  => __('method_other'),
];

// ── Build HTML ───────────────────────────────────────────────────────
ob_start();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<style>
@page { margin: 14mm 16mm; }
* { box-sizing: border-box; }
body {
    font-family: 'tajawal', sans-serif;
    font-size: 10px;
    color: #1f2937;
    direction: rtl;
    line-height: 1.65;
    margin: 0;
}
.amt { direction: ltr; unicode-bidi: embed; display: inline-block; }
.spacer-1 { height: 6px; }
.spacer-2 { height: 12px; }
.spacer-3 { height: 18px; }
.spacer-4 { height: 26px; }

/* ── COVER HEADER ─────────────────────────────────────────────── */
.cover {
    background: #0d9488;
    color: #fff;
    padding: 22px 26px;
    border-radius: 14px;
    margin-bottom: 20px;
}
.cover-row { width: 100%; border-collapse: collapse; }
.cover-row td { vertical-align: top; padding: 0; color: #fff; }
.brand-name { font-size: 22px; font-weight: 700; letter-spacing: .3px; line-height: 1.1; margin: 0; padding: 0; }
.brand-sub  { font-size: 7px; opacity: .8; letter-spacing: 1.8px; text-transform: uppercase; margin-top: 8px; line-height: 1.2; }
.brand-line { margin-top: 12px; font-size: 9px; opacity: .9; line-height: 1.75; }

.doc { text-align: left; }
.doc-label { font-size: 7.5px; opacity: .8; letter-spacing: 2.5px; text-transform: uppercase; line-height: 1.1; margin: 0; padding: 0; }
.doc-no    { font-size: 21px; font-weight: 700; margin-top: 10px; line-height: 1.1; letter-spacing: .3px; }
.doc-pill  {
    display: inline-block;
    background: rgba(255,255,255,.22);
    padding: 5px 14px;
    font-size: 8px;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    font-weight: 700;
    margin-top: 14px;
    line-height: 1.1;
    border-radius: 100px;
}

/* ── INFO STRIP ───────────────────────────────────────────────── */
.strip { width: 100%; border-collapse: collapse; margin-bottom: 22px; }
.strip td { vertical-align: top; padding: 14px 16px; }
.strip .col-bill { background: #f8fafc; border-radius: 10px; }
.strip .col-gap  { width: 12px; padding: 0; }
.strip .col-date {
    background: #f8fafc;
    border-radius: 10px;
    border-top: 3px solid #0d9488;
}
.k { font-size: 7.5px; color: #94a3b8; letter-spacing: 2px; text-transform: uppercase; line-height: 1.2; }
.v-name { font-size: 14px; font-weight: 700; color: #0f172a; line-height: 1.3; margin-top: 8px; }
.v-code { font-size: 9px; color: #0d9488; font-family: monospace; letter-spacing: 1px; margin-top: 4px; line-height: 1.2; }
.v-meta { font-size: 9.5px; color: #64748b; margin-top: 11px; line-height: 1.85; }
.v-meta strong { color: #94a3b8; font-weight: 500; }

.date-block { margin-bottom: 14px; }
.date-block:last-child { margin-bottom: 0; }
.v-date { font-size: 13px; font-weight: 700; color: #0f172a; margin-top: 6px; line-height: 1.2; }

/* ── ITEMS ───────────────────────────────────────────────────── */
.sec { font-size: 7.5px; color: #94a3b8; letter-spacing: 3px; text-transform: uppercase; margin-bottom: 6px; line-height: 1; }
table.items { width: 100%; border-collapse: collapse; }
table.items thead th {
    background: #f1f5f9;
    color: #475569;
    padding: 9px 11px;
    text-align: right;
    font-size: 8px;
    font-weight: 700;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    line-height: 1;
}
table.items thead th:first-child { border-radius: 0 8px 8px 0; }
table.items thead th:last-child  { border-radius: 8px 0 0 8px; }
table.items td {
    padding: 10px 11px;
    border-bottom: 1px solid #f1f5f9;
    font-size: 10px;
    line-height: 1.4;
}
table.items tbody tr:last-child td { border-bottom: none; }
.num { text-align: center; color: #cbd5e1; font-size: 9px; width: 26px; }
.desc { color: #0f172a; font-weight: 500; }
.col-amt { width: 12%; }
.left { text-align: left; }

/* ── TOTALS CARD ─────────────────────────────────────────────── */
.totals-wrap { margin-top: 14px; }
.totals {
    width: 48%;
    float: left;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    border-collapse: separate;
    overflow: hidden;
}
.totals td { padding: 9px 16px; font-size: 10px; line-height: 1.4; }
.totals .lbl { color: #64748b; }
.totals .val { text-align: left; color: #0f172a; font-weight: 700; }
.totals .row-sub td { border-bottom: 1px solid #f1f5f9; }
.totals .grand td {
    background: #0d9488;
    color: #fff;
    font-size: 11px;
    padding: 12px 16px;
    letter-spacing: .8px;
    font-weight: 700;
    text-transform: uppercase;
    line-height: 1.2;
}
.totals .grand .val { font-size: 14px; text-transform: none; letter-spacing: .3px; }
.totals .paid td { color: #047857; padding-top: 10px; padding-bottom: 6px; }
.totals .due td {
    color: #b91c1c;
    font-size: 11px;
    padding-top: 7px;
    padding-bottom: 10px;
    border-top: 1px dashed #cbd5e1;
    font-weight: 700;
}
.totals .due .val { font-size: 12.5px; }

/* ── PAYMENTS ────────────────────────────────────────────────── */
.payments { margin-top: 22px; clear: both; padding-top: 14px; border-top: 1px solid #e2e8f0; }
table.pay { width: 100%; border-collapse: collapse; }
table.pay th {
    color: #94a3b8;
    padding: 7px 10px;
    text-align: right;
    font-size: 7.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    border-bottom: 1px solid #cbd5e1;
    line-height: 1;
}
table.pay td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; font-size: 9px; line-height: 1.3; }
table.pay tr:last-child td { border-bottom: none; }
.pay-method { color: #475569; font-size: 9px; }

/* ── NOTES ───────────────────────────────────────────────────── */
.notes {
    margin-top: 14px;
    padding: 10px 14px;
    background: #fffbeb;
    border-right: 3px solid #f59e0b;
    border-radius: 4px;
    font-size: 9.5px;
    color: #78350f;
    line-height: 1.6;
}
.notes-k { font-size: 7.5px; color: #b45309; letter-spacing: 2.5px; text-transform: uppercase; font-weight: 700; margin-bottom: 4px; line-height: 1; }

/* ── FOOTER ──────────────────────────────────────────────────── */
.footer { margin-top: 24px; padding-top: 12px; border-top: 1px solid #e2e8f0; text-align: center; line-height: 1.6; }
.footer .thanks { color: #0d9488; font-weight: 700; font-size: 11px; letter-spacing: 1.5px; line-height: 1; margin-bottom: 4px; }
.footer .wish   { color: #64748b; font-size: 9px; line-height: 1; }
.footer .sig    { margin-top: 10px; font-size: 8px; color: #94a3b8; line-height: 1; }
.footer .legal  { margin-top: 5px; font-size: 6.5px; color: #cbd5e1; letter-spacing: 1.5px; text-transform: uppercase; line-height: 1; }
</style>
</head>
<body>

<!-- ════════ COVER HEADER ════════ -->
<div class="cover">
    <table class="cover-row" cellpadding="0" cellspacing="0">
        <tr>
            <td style="width:60%;">
                <div class="brand-name"><?= e($siteName) ?></div>
                <div class="brand-sub">Wellness &middot; Care &middot; Excellence</div>
                <?php if ($address || $phone || $email): ?>
                    <div class="brand-line">
                        <?php if ($address): ?><?= e($address) ?><br><?php endif; ?>
                        <?php if ($phone): ?>هاتف: <span class="amt"><?= e($phone) ?></span><?php endif; ?>
                        <?php if ($phone && $email): ?> &nbsp;&middot;&nbsp; <?php endif; ?>
                        <?php if ($email): ?>بريد: <span class="amt"><?= e($email) ?></span><?php endif; ?>
                    </div>
                <?php endif; ?>
            </td>
            <td class="doc" style="width:40%;">
                <div class="doc-label">فاتورة &middot; INVOICE</div>
                <div class="doc-no"><span class="amt"><?= e($invoice['invoice_no']) ?></span></div>
                <div class="doc-pill"><?= e($statusLabels[$invoice['status']] ?? $invoice['status']) ?></div>
            </td>
        </tr>
    </table>
</div>

<!-- ════════ BILL-TO + DATES STRIP ════════ -->
<table class="strip" cellpadding="0" cellspacing="0">
    <tr>
        <td class="col-bill" style="width:58%;">
            <div class="k">صادرة إلى &middot; Bill to</div>
            <div class="v-name"><?= e($invoice['first_name'].' '.$invoice['last_name']) ?></div>
            <div class="v-code"><span class="amt"><?= e($invoice['patient_code']) ?></span></div>
            <div class="v-meta">
                <?php if ($invoice['phone']): ?><strong>هاتف:</strong> <span class="amt"><?= e($invoice['phone']) ?></span><br><?php endif; ?>
                <?php if ($invoice['email']): ?><strong>بريد:</strong> <span class="amt"><?= e($invoice['email']) ?></span><br><?php endif; ?>
                <?php $addr = trim(($invoice['address']??'').' '.($invoice['city']??'')); if ($addr): ?>
                    <strong>العنوان:</strong> <?= e($addr) ?>
                <?php endif; ?>
            </div>
        </td>
        <td class="col-gap"></td>
        <td class="col-date" style="width:40%;">
            <div class="date-block">
                <div class="k">تاريخ الإصدار</div>
                <div class="v-date"><span class="amt"><?= e($invoice['issue_date']) ?></span></div>
            </div>
            <?php if ($invoice['due_date']): ?>
                <div class="date-block">
                    <div class="k">تاريخ الاستحقاق</div>
                    <div class="v-date"><span class="amt"><?= e($invoice['due_date']) ?></span></div>
                </div>
            <?php endif; ?>
        </td>
    </tr>
</table>

<!-- ════════ LINE ITEMS ════════ -->
<div class="sec">البنود &middot; Line items</div>
<table class="items" cellpadding="0" cellspacing="0">
    <thead>
        <tr>
            <th class="num">#</th>
            <th>الوصف</th>
            <th class="left col-amt">الكمية</th>
            <th class="left col-amt">السعر</th>
            <th class="left col-amt">الخصم</th>
            <th class="left col-amt">المجموع</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($items as $idx => $it): ?>
            <tr>
                <td class="num"><?= $idx+1 ?></td>
                <td class="desc"><?= e($it['description']) ?></td>
                <td class="left"><span class="amt"><?= e($it['quantity']) ?></span></td>
                <td class="left"><span class="amt"><?= number_format($it['unit_price'],2) ?></span></td>
                <td class="left"><?php if ((float)$it['discount']>0): ?><span class="amt">−<?= number_format($it['discount'],2) ?></span><?php else: ?>—<?php endif; ?></td>
                <td class="left"><strong><span class="amt"><?= number_format($it['total'],2) ?></span></strong></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<div class="spacer-1"></div>

<!-- ════════ TOTALS ════════ -->
<div class="totals-wrap">
    <table class="totals" style="margin-right:auto" cellpadding="0" cellspacing="0">
        <tr class="row-sub">
            <td class="lbl">المجموع الفرعي</td>
            <td class="val"><span class="amt"><?= number_format($invoice['subtotal'],2) ?> <?= e($currency) ?></span></td>
        </tr>
        <?php if ((float)$invoice['discount'] > 0): ?>
            <tr class="row-sub">
                <td class="lbl">الخصم</td>
                <td class="val" style="color:#b91c1c"><span class="amt">− <?= number_format($invoice['discount'],2) ?> <?= e($currency) ?></span></td>
            </tr>
        <?php endif; ?>
        <?php if ((float)$invoice['tax'] > 0): ?>
            <tr class="row-sub">
                <td class="lbl">الضريبة</td>
                <td class="val"><span class="amt">+ <?= number_format($invoice['tax'],2) ?> <?= e($currency) ?></span></td>
            </tr>
        <?php endif; ?>
        <tr class="grand">
            <td>الإجمالي</td>
            <td class="val"><span class="amt"><?= number_format($invoice['total'],2) ?> <?= e($currency) ?></span></td>
        </tr>
        <tr class="paid">
            <td class="lbl">المدفوع</td>
            <td class="val"><span class="amt">+ <?= number_format($invoice['paid_amount'],2) ?> <?= e($currency) ?></span></td>
        </tr>
        <?php if ((float)$invoice['balance'] > 0): ?>
            <tr class="due">
                <td class="lbl">المتبقي</td>
                <td class="val"><span class="amt"><?= number_format($invoice['balance'],2) ?> <?= e($currency) ?></span></td>
            </tr>
        <?php endif; ?>
    </table>
    <div style="clear:both"></div>
</div>

<?php if ($payments): ?>
    <!-- ════════ PAYMENTS LOG ════════ -->
    <div class="payments">
        <div class="sec" style="margin-bottom:8px">سجل المدفوعات &middot; Payment history</div>
        <table class="pay" cellpadding="0" cellspacing="0">
            <thead>
                <tr>
                    <th style="width:25%">رقم الإيصال</th>
                    <th style="width:24%">التاريخ</th>
                    <th style="width:18%">الطريقة</th>
                    <th class="left" style="width:18%">المبلغ</th>
                    <th style="width:15%">المرجع</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payments as $p): ?>
                    <tr>
                        <td style="font-family:monospace; color:#0f172a;"><span class="amt"><?= e($p['receipt_no']) ?></span></td>
                        <td style="color:#475569"><span class="amt"><?= e(substr($p['paid_at'],0,16)) ?></span></td>
                        <td><span class="pay-method"><?= e($methodLabels[$p['method']] ?? $p['method']) ?></span></td>
                        <td class="left" style="color:#047857; font-weight:700;"><span class="amt">+ <?= number_format($p['amount'],2) ?></span></td>
                        <td style="color:#94a3b8"><?= e($p['reference_no'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($invoice['notes']): ?>
    <div class="notes">
        <div class="notes-k">ملاحظات &middot; Notes</div>
        <?= nl2br(e($invoice['notes'])) ?>
    </div>
<?php endif; ?>

<!-- ════════ FOOTER ════════ -->
<div class="footer">
    <div class="thanks">شكراً لاختياركم <?= e($siteName) ?></div>
    <div class="wish">نتمنى لكم دوام الصحة والعافية</div>
    <div class="sig">
        <?= e($siteName) ?>
        <?php if ($address): ?> &middot; <?= e($address) ?><?php endif; ?>
        <?php if ($phone): ?> &middot; <span class="amt"><?= e($phone) ?></span><?php endif; ?>
    </div>
    <div class="legal">صدرت هذه الفاتورة إلكترونياً ولا تحتاج توقيعاً &middot; Issued electronically</div>
</div>

</body>
</html>
<?php
$html = ob_get_clean();

// ── Render with mPDF using Tajawal font ──────────────────────────────
if (!class_exists(\Mpdf\Mpdf::class)) {
    http_response_code(500);
    echo 'mPDF not installed. Run: composer require mpdf/mpdf';
    exit;
}

$fontDir = BP_PATH . '/storage/fonts';
$tmpDir  = BP_PATH . '/storage/mpdf';
@mkdir($tmpDir, 0775, true);

$defaultConfig     = (new \Mpdf\Config\ConfigVariables())->getDefaults();
$defaultFontDirs   = $defaultConfig['fontDir'];
$defaultFontConfig = (new \Mpdf\Config\FontVariables())->getDefaults();
$defaultFontData   = $defaultFontConfig['fontdata'];

$mpdf = new \Mpdf\Mpdf([
    'mode'          => 'utf-8',
    'format'        => 'A4',
    'orientation'   => 'P',
    'tempDir'       => $tmpDir,
    'fontDir'       => array_merge($defaultFontDirs, [$fontDir]),
    'fontdata'      => $defaultFontData + [
        'tajawal' => [
            'R'  => 'Tajawal-Regular.ttf',
            'B'  => 'Tajawal-Bold.ttf',
            'M'  => 'Tajawal-Medium.ttf',
            'EB' => 'Tajawal-ExtraBold.ttf',
            'useOTL' => 0xFF,
            'useKashida' => 75,
        ],
    ],
    'default_font'      => 'tajawal',
    'default_font_size' => 10,
    // Don't let mPDF override our chosen font when it sees Arabic script
    'autoScriptToLang'  => false,
    'autoLangToFont'    => false,
    'margin_left'       => 16,
    'margin_right'      => 16,
    'margin_top'        => 14,
    'margin_bottom'     => 14,
]);
$mpdf->SetDirectionality('rtl');
$mpdf->SetTitle('Invoice ' . $invoice['invoice_no']);
$mpdf->WriteHTML($html);

$filename = 'invoice-' . preg_replace('/[^A-Za-z0-9_-]/','_', $invoice['invoice_no']) . '.pdf';
$dest = !empty($_GET['download']) ? \Mpdf\Output\Destination::DOWNLOAD : \Mpdf\Output\Destination::INLINE;
$mpdf->Output($filename, $dest);
exit;
