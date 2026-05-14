<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('settings.view');

$PageTitle = __('system_health_label');

// Environment
$phpVersion = PHP_VERSION;
$phpOk      = version_compare($phpVersion, '8.0', '>=');

// PHP extensions
$exts = ['pdo_mysql','mbstring','json','openssl','fileinfo','curl','gd','zip'];
$extStatus = [];
foreach ($exts as $x) $extStatus[$x] = extension_loaded($x);

// Vendors
$vendorsExist = is_file(BP_PATH . '/vendor/autoload.php');
$dompdf       = class_exists('Dompdf\Dompdf');
$phpmailer    = class_exists('PHPMailer\PHPMailer\PHPMailer');

// Writable paths
$writable = [
    'uploads/'                        => UPLOADS_PATH,
    'BusinessPortal/storage/dompdf/'  => BP_PATH . '/storage/dompdf',
    'BusinessPortal/backups/'         => BP_PATH . '/backups',
];

// DB stats
$tables = db()->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$counts = [];
foreach ([
    'users','roles','permissions','patients','services','packages',
    'appointments','consultations','invoices','payments','expenses',
    'employees','attendance','leaves','payslips','commissions',
    'blog_posts','sliders','faqs','gallery',
    'booking_requests','notifications','activity_logs',
] as $t) {
    if (in_array($t, $tables, true)) {
        try { $counts[$t] = (int)db()->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); }
        catch (Throwable $e) { $counts[$t] = 'err'; }
    }
}

// MySQL version
$mysqlVersion = db()->query("SELECT VERSION()")->fetchColumn();

// Disk usage of uploads + backups
function _dir_size(string $dir): int {
    if (!is_dir($dir)) return 0;
    $size = 0;
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $f) if ($f->isFile()) $size += $f->getSize();
    return $size;
}
$diskUploads = _dir_size(UPLOADS_PATH);
$diskBackups = _dir_size(BP_PATH . '/backups');

// Backup files
$backupFiles = glob(BP_PATH . '/backups/nourstouch_*.sql.gz') ?: [];
rsort($backupFiles);
$backupFiles = array_slice($backupFiles, 0, 5);

// Recent notifications health
$notifStats = db()->query("
    SELECT status, COUNT(*) AS n FROM notifications
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY status
")->fetchAll(PDO::FETCH_KEY_PAIR);

// Health KPIs
$extOk = count(array_filter($extStatus));
$extTotal = count($extStatus);
$pathsOk = 0; $pathsTotal = count($writable);
foreach ($writable as $p) { if (is_dir($p) && is_writable($p)) $pathsOk++; }
$vendorsOk = ($vendorsExist?1:0) + ($dompdf?1:0) + ($phpmailer?1:0);
$diskTotal = $diskUploads + $diskBackups;

include BP_PARTIALS . '/header.php';
?>
<div class="page-wrap">
    <!-- Header -->
    <div class="page-header">
        <h4 class="m-0">
            <i class="fa-solid fa-stethoscope text-teal me-2"></i><?= __('system_health_label') ?>
        </h4>
        <div class="page-header-actions">
            <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/settings.php">
                <i class="fa-solid fa-gear me-1"></i><?= __('settings') ?>
            </a>
            <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/activity.php">
                <i class="fa-solid fa-list-check me-1"></i><?= __('activity_log') ?>
            </a>
        </div>
    </div>

    <!-- KPI strip -->
    <div class="appt-kpis">
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:<?= $phpOk?'#10b981':'#ef4444' ?>"><i class="fa-brands fa-php"></i></div>
            <div>
                <div class="appt-kpi-label">PHP</div>
                <div class="appt-kpi-value" style="font-size:1rem"><?= e($phpVersion) ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:<?= $extOk===$extTotal?'#10b981':'#f59e0b' ?>"><i class="fa-solid fa-puzzle-piece"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('php_extensions') ?></div>
                <div class="appt-kpi-value"><?= $extOk ?>/<?= $extTotal ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:<?= $pathsOk===$pathsTotal?'#10b981':'#ef4444' ?>"><i class="fa-solid fa-folder-open"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('writable_paths') ?></div>
                <div class="appt-kpi-value"><?= $pathsOk ?>/<?= $pathsTotal ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#0d9488"><i class="fa-solid fa-hard-drive"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('disk_usage') ?></div>
                <div class="appt-kpi-value" style="font-size:1.05rem"><?= number_format($diskTotal/1024/1024,1) ?> MB</div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <!-- Environment -->
        <div class="col-lg-6">
            <div class="card"><div class="card-body">
                <h6 class="text-teal"><?= __('environment') ?></h6>
                <table class="table table-sm mb-0">
                    <tr><td><?= __('app_version') ?></td><td><strong>1.0.0</strong></td></tr>
                    <tr><td>PHP</td><td><?= e($phpVersion) ?> <?= $phpOk?'<span class="badge bg-success">ok</span>':'<span class="badge bg-danger">need 8.0+</span>' ?></td></tr>
                    <tr><td>MySQL</td><td><?= e($mysqlVersion) ?></td></tr>
                    <tr><td><?= __('server') ?></td><td class="small"><?= e($_SERVER['SERVER_SOFTWARE'] ?? '—') ?></td></tr>
                    <tr><td><?= __('timezone') ?></td><td><?= e(date_default_timezone_get()) ?></td></tr>
                    <tr><td><?= __('app_url_label') ?></td><td class="small"><code><?= e(APP_URL) ?></code></td></tr>
                    <tr><td><?= __('app_path') ?></td><td class="small"><code><?= e(APP_PATH) ?></code></td></tr>
                </table>
            </div></div>
        </div>

        <div class="col-lg-6">
            <div class="card"><div class="card-body">
                <h6 class="text-teal"><?= __('php_extensions') ?></h6>
                <div class="row g-2 small">
                    <?php foreach ($extStatus as $name => $loaded): ?>
                        <div class="col-md-6">
                            <i class="fa-solid <?= $loaded?'fa-check text-success':'fa-xmark text-danger' ?> me-1"></i>
                            <code><?= e($name) ?></code>
                        </div>
                    <?php endforeach; ?>
                </div>
                <hr>
                <h6 class="text-teal"><?= __('vendors') ?></h6>
                <p class="small mb-1">
                    <i class="fa-solid <?= $vendorsExist?'fa-check text-success':'fa-xmark text-danger' ?> me-1"></i>
                    <code>vendor/autoload.php</code>
                </p>
                <p class="small mb-1">
                    <i class="fa-solid <?= $dompdf?'fa-check text-success':'fa-xmark text-danger' ?> me-1"></i>
                    <code>Dompdf\Dompdf</code>  <small class="text-muted">(invoice + payslip PDF)</small>
                </p>
                <p class="small mb-0">
                    <i class="fa-solid <?= $phpmailer?'fa-check text-success':'fa-xmark text-danger' ?> me-1"></i>
                    <code>PHPMailer</code>  <small class="text-muted">(email reminders)</small>
                </p>
            </div></div>
        </div>

        <!-- Writable paths -->
        <div class="col-lg-6">
            <div class="card"><div class="card-body">
                <h6 class="text-teal"><?= __('writable_paths') ?></h6>
                <table class="table table-sm mb-0">
                    <?php foreach ($writable as $label => $p):
                        $exists = is_dir($p);
                        $w = $exists && is_writable($p);
                    ?>
                        <tr>
                            <td><code class="small"><?= e($label) ?></code></td>
                            <td>
                                <?php if (!$exists): ?>
                                    <span class="badge bg-warning">missing</span>
                                <?php elseif (!$w): ?>
                                    <span class="badge bg-danger">not writable</span>
                                <?php else: ?>
                                    <span class="badge bg-success">writable</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <hr class="my-2">
                <small class="text-muted">
                    Uploads disk: <strong><?= number_format($diskUploads/1024/1024, 2) ?> MB</strong> ·
                    Backups disk: <strong><?= number_format($diskBackups/1024/1024, 2) ?> MB</strong>
                </small>
            </div></div>
        </div>

        <!-- Notifications health -->
        <div class="col-lg-6">
            <div class="card"><div class="card-body">
                <h6 class="text-teal"><?= __('notifications_7d') ?></h6>
                <div class="d-flex gap-2 flex-wrap mb-2">
                    <span class="badge bg-success"><?= __('st_sent') ?> <?= (int)($notifStats['sent']??0) ?></span>
                    <span class="badge bg-warning"><?= __('st_queued') ?> <?= (int)($notifStats['queued']??0) ?></span>
                    <span class="badge bg-secondary"><?= __('st_skipped') ?> <?= (int)($notifStats['skipped']??0) ?></span>
                    <span class="badge bg-danger"><?= __('st_failed') ?> <?= (int)($notifStats['failed']??0) ?></span>
                </div>
                <small class="text-muted">
                    <?= __('configure_smtp') ?> <a href="<?= BP_URL ?>admin/settings.php"><?= __('settings') ?> → <?= __('mail') ?></a><br>
                    <?= __('view_log_at') ?> <a href="<?= BP_URL ?>admin/notifications.php"><?= __('notifications') ?></a>
                </small>
                <hr>
                <h6 class="text-teal"><?= __('recent_backups') ?></h6>
                <?php if ($backupFiles): ?>
                    <ul class="list-unstyled small mb-0">
                        <?php foreach ($backupFiles as $f):
                            $size = number_format(filesize($f)/1024, 1);
                            $when = date('Y-m-d H:i', filemtime($f));
                        ?>
                            <li><i class="fa-solid fa-file-zipper text-teal me-1"></i><code><?= e(basename($f)) ?></code> <span class="text-muted">· <?= $size ?> KB · <?= $when ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="small text-muted mb-0">No backups yet. Run <code>php BusinessPortal/cron/backup-db.php</code></p>
                <?php endif; ?>
            </div></div>
        </div>

        <!-- Database -->
        <div class="col-12">
            <div class="card"><div class="card-body">
                <h6 class="text-teal"><?= __('db_tables_count') ?> (<?= count($tables) ?>)</h6>
                <div class="row g-2 small">
                    <?php foreach ($counts as $t => $n): ?>
                        <div class="col-md-3 col-sm-6">
                            <code class="small"><?= e($t) ?></code>
                            <span class="badge bg-light text-dark float-end"><?= e($n) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div></div>
        </div>

        <!-- Cron commands -->
        <div class="col-12">
            <div class="card"><div class="card-body">
                <h6 class="text-teal"><?= __('cron_schedule') ?></h6>
                <p class="small text-muted"><?= __('add_to_crontab') ?></p>
<pre class="bg-light p-3 rounded small mb-2"><code># Send appointment reminder emails every 15 minutes
[*]/15 * * * * /usr/bin/php <?= BP_PATH ?>/cron/send-reminders.php &gt;&gt; /var/log/nourstouch-cron.log 2&gt;&amp;1

# Daily DB backup at 03:00
0 3 * * * /usr/bin/php <?= BP_PATH ?>/cron/backup-db.php &gt;&gt; /var/log/nourstouch-backup.log 2&gt;&amp;1
</code></pre>
                <small class="text-muted"><i class="fa-solid fa-circle-info me-1"></i>Replace <code>[*]</code> with <code>*</code> in the actual crontab.</small>
            </div></div>
        </div>
    </div>
</div>
<?php include BP_PARTIALS . '/footer.php'; ?>
