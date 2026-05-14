<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('payroll.view');

$id = (int)($_GET['id'] ?? 0);
$ps = db()->prepare("
    SELECT p.*, e.code, e.first_name, e.last_name, e.job_title, e.department,
           e.bank_name, e.bank_account, e.iban
    FROM payslips p JOIN employees e ON e.id = p.employee_id
    WHERE p.id = ? AND p.deleted_at IS NULL
");
$ps->execute([$id]);
$payslip = $ps->fetch();
if (!$payslip) { http_response_code(404); exit('Payslip not found.'); }

// Restrict employees to their own payslips
$me = current_employee();
if ($me && (int)$me['id'] !== (int)$payslip['employee_id'] && !can('payroll.edit') && !can('payroll.create')) {
    http_response_code(403); exit('Forbidden.');
}

$comps = db()->prepare("SELECT * FROM payslip_components WHERE payslip_id=? ORDER BY id");
$comps->execute([$id]); $comps = $comps->fetchAll();

$siteName = setting('site_name_ar', APP_NAME_AR);
$address  = setting('address','');
$phone    = setting('contact_phone','');
$currency = setting('currency', APP_CURRENCY);
$periodLabel = sprintf('%04d-%02d', (int)$payslip['period_year'], (int)$payslip['period_month']);

ob_start();
?>
<!DOCTYPE html><html lang="ar" dir="rtl"><head>
<meta charset="UTF-8">
<style>
    @page { margin: 22mm 18mm; }
    body { font-family: 'dejavu sans', sans-serif; font-size: 11px; color: #1f2937; direction: rtl; }
    h1   { color: #0d9488; margin: 0 0 4px; font-size: 20px; }
    .muted { color: #64748b; font-size: 10px; }
    .header { border-bottom: 2px solid #14b8a6; padding-bottom: 10px; margin-bottom: 14px; }
    .pill { background: #ccfbf1; color: #0d9488; padding: 4px 10px; border-radius: 6px; font-weight: bold; }
    table.info { width: 100%; margin: 12px 0; }
    table.info td { padding: 4px; vertical-align: top; }
    table.lines { width: 100%; border-collapse: collapse; margin-top: 10px; }
    table.lines th { background: #f0fdfa; color: #0d9488; padding: 8px; text-align: right; border-bottom: 2px solid #14b8a6; }
    table.lines td { padding: 7px 8px; border-bottom: 1px solid #e2e8f0; }
    .left  { text-align: left; }
    .right { text-align: right; }
    .totals { width: 50%; float: left; margin-top: 10px; }
    .totals td { padding: 5px 8px; }
    .totals .grand { background: #ccfbf1; font-weight: bold; font-size: 14px; color: #0d9488; }
    .stamp-paid     { background: #d1fae5; color: #065f46; padding: 3px 8px; border-radius: 4px; font-weight: bold; font-size: 10px; }
    .stamp-draft    { background: #f1f5f9; color: #475569; padding: 3px 8px; border-radius: 4px; font-weight: bold; font-size: 10px; }
    .stamp-approved { background: #dbeafe; color: #1e40af; padding: 3px 8px; border-radius: 4px; font-weight: bold; font-size: 10px; }
    .stamp-cancelled{ background: #fee2e2; color: #991b1b; padding: 3px 8px; border-radius: 4px; font-weight: bold; font-size: 10px; }
    .footer { clear: both; margin-top: 30px; border-top: 1px solid #e2e8f0; padding-top: 8px; color: #94a3b8; font-size: 9px; text-align: center; }
</style>
</head><body>

<table class="header" style="width:100%">
    <tr>
        <td style="width:60%">
            <h1><?= e($siteName) ?></h1>
            <div class="muted"><?= e($address) ?> · <?= e($phone) ?></div>
        </td>
        <td class="left" style="width:40%">
            <span class="pill">قسيمة راتب <?= e($payslip['payslip_no']) ?></span>
            <div class="muted" style="margin-top:6px">
                الفترة: <?= e($periodLabel) ?>
                <br><span class="stamp-<?= e($payslip['status']) ?>"><?= e($payslip['status']) ?></span>
            </div>
        </td>
    </tr>
</table>

<table class="info">
    <tr>
        <td>
            <strong>الموظف:</strong> <?= e($payslip['first_name'].' '.$payslip['last_name']) ?>
            (<?= e($payslip['code']) ?>)<br>
            <span class="muted"><?= e($payslip['job_title']??'') ?> · <?= e($payslip['department']??'') ?></span>
        </td>
        <td class="left">
            <?php if ($payslip['bank_name']): ?>
                <strong>البنك:</strong> <?= e($payslip['bank_name']) ?><br>
                <span class="muted">الحساب: <?= e($payslip['bank_account']??'—') ?></span><br>
                <?php if ($payslip['iban']): ?><span class="muted">IBAN: <?= e($payslip['iban']) ?></span><?php endif; ?>
            <?php endif; ?>
        </td>
    </tr>
</table>

<table class="info">
    <tr>
        <td>أيام العمل: <strong><?= e($payslip['working_days']) ?></strong></td>
        <td>حضور: <strong><?= e($payslip['present_days']) ?></strong></td>
        <td>غياب: <strong><?= e($payslip['absent_days']) ?></strong></td>
        <td>إجازات: <strong><?= e($payslip['leave_days']) ?></strong></td>
        <td>دقائق التأخير: <strong><?= (int)$payslip['late_minutes'] ?></strong></td>
    </tr>
</table>

<table class="lines">
    <thead><tr>
        <th>البيان</th>
        <th class="left">المبلغ</th>
    </tr></thead>
    <tbody>
        <?php foreach ($comps as $c): ?>
            <tr>
                <td>
                    <?= e($c['label']) ?>
                    <?php if ($c['notes']): ?> <span class="muted">— <?= e($c['notes']) ?></span><?php endif; ?>
                </td>
                <td class="left <?= $c['kind']==='deduction'?'':'' ?>">
                    <?= ($c['kind']==='deduction' ? '−' : '') ?><?= number_format($c['amount'],2) ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<table class="totals" style="margin-right:auto">
    <tr><td>إجمالي:</td><td class="left"><?= number_format($payslip['gross_salary'],2) ?> <?= e($currency) ?></td></tr>
    <tr><td>إجمالي الخصومات:</td><td class="left">−<?= number_format($payslip['deductions'] + $payslip['advances_deduct'],2) ?></td></tr>
    <tr class="grand"><td>الصافي:</td><td class="left"><?= number_format($payslip['net_salary'],2) ?> <?= e($currency) ?></td></tr>
</table>

<div class="footer">
    <?= e($siteName) ?> — قسيمة راتب صادرة آلياً.
</div>
</body></html>
<?php
$html = ob_get_clean();

if (!class_exists('Dompdf\Dompdf')) {
    http_response_code(500); echo "DomPDF not installed."; exit;
}

$bundledFonts = BP_PATH . '/vendor/dompdf/dompdf/lib/fonts';
$cacheDir     = BP_PATH . '/storage/dompdf/fonts';
$tempDir      = BP_PATH . '/storage/dompdf/temp';
@mkdir($cacheDir, 0775, true); @mkdir($tempDir, 0775, true);

$options = new \Dompdf\Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isFontSubsettingEnabled', false);
$options->set('defaultFont', 'dejavu sans');
$options->set('fontDir',   $bundledFonts);
$options->set('fontCache', $cacheDir);
$options->set('tempDir',   $tempDir);
$options->set('chroot',    [BP_PATH, APP_PATH]);

$dompdf = new \Dompdf\Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$filename = 'payslip-' . preg_replace('/[^A-Za-z0-9_-]/','_', $payslip['payslip_no']) . '.pdf';
$dompdf->stream($filename, ['Attachment' => !empty($_GET['download']) ? 1 : 0]);
exit;
