<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('settings.view');

$PageTitle = __('notifications');
$action    = $_GET['action'] ?? 'list';
$id        = (int)($_GET['id'] ?? 0);

// ── View actual sent email body ───────────────────────────────────────
if ($action === 'view' && $id) {
    $row = db()->prepare("SELECT * FROM notifications WHERE id=?");
    $row->execute([$id]); $n = $row->fetch();
    if (!$n) { http_response_code(404); echo 'Not found.'; exit; }
    header('Content-Type: text/html; charset=utf-8');
    echo $n['body'] ?? '<p>(empty body)</p>';
    exit;
}

// ── Preview template with sample data ─────────────────────────────────
if ($action === 'preview') {
    $kind = $_GET['kind'] ?? 'appointment_24h';
    if (!in_array($kind, ['appointment_24h','appointment_2h'], true)) $kind = 'appointment_24h';
    $sample = [
        'start_at'       => date('Y-m-d H:i:s', strtotime($kind==='appointment_24h'?'+24 hours':'+2 hours')),
        'first_name'     => 'سارة',
        'last_name'      => 'الأحمد',
        'svc_names'      => 'جلسة علاج طبيعي · تدليك علاجي',
        'therapist_name' => 'د. ليلى محمد',
        'room_name'      => 'غرفة 3',
    ];
    header('Content-Type: text/html; charset=utf-8');
    echo render_appointment_reminder_email($sample, $kind);
    exit;
}

// Manual resend
if ($id && $action === 'resend' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_can('settings.edit');

    $row = db()->prepare("SELECT * FROM notifications WHERE id=?");
    $row->execute([$id]); $n = $row->fetch();
    if (!$n) { flash('error','Not found.'); redirect(BP_URL.'admin/notifications.php'); }

    // Delete the old log row so the (subject_type, subject_id, kind) UNIQUE doesn't block
    db()->prepare("DELETE FROM notifications WHERE id=?")->execute([$id]);

    $r = send_email_notification(
        $n['recipient'], $n['subject'] ?? '(no subject)', $n['body'] ?? '',
        $n['kind'], $n['subject_type'], $n['subject_id']
    );
    log_activity('resent','notifications',"Resent notification (was #$id)",'notification', $r['log_id']);
    flash($r['ok'] ? 'success':'error', $r['ok'] ? 'Resent.' : 'Failed: '.$r['msg']);
    redirect(BP_URL.'admin/notifications.php');
}

// Filters
$kind   = trim($_GET['kind'] ?? '');
$status = trim($_GET['status'] ?? '');
$q      = trim($_GET['q'] ?? '');
$page   = (int)($_GET['page'] ?? 1);
[$perPage, $offset] = paginate_query($page, (int)setting('per_page','25'));

$where = "1=1"; $params = [];
if ($kind   !== '') { $where .= " AND kind = ?";        $params[] = $kind; }
if ($status !== '') { $where .= " AND status = ?";      $params[] = $status; }
if ($q      !== '') { $where .= " AND (recipient LIKE ? OR subject LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; }

$tot = db()->prepare("SELECT COUNT(*) FROM notifications WHERE $where");
$tot->execute($params);
$total = (int)$tot->fetchColumn();

$rows = db()->prepare("SELECT * FROM notifications WHERE $where ORDER BY id DESC LIMIT $perPage OFFSET $offset");
$rows->execute($params);
$rows = $rows->fetchAll();

// Aggregate counts (for the badges)
$counts = db()->query("
    SELECT status, COUNT(*) AS n FROM notifications
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY status
")->fetchAll(PDO::FETCH_KEY_PAIR);

include BP_PARTIALS . '/header.php';
?>
<div class="page-wrap">
    <!-- Header -->
    <div class="page-header">
        <h4 class="m-0">
            <i class="fa-regular fa-bell text-teal me-2"></i><?= __('notifications_log') ?>
            <small class="text-muted ms-2" style="font-size:.85rem">(<?= $total ?>)</small>
        </h4>
        <div class="page-header-actions">
            <button type="button" class="btn btn-light btn-sm" onclick="ntPreviewEmail('appointment_24h')">
                <i class="fa-solid fa-eye me-1"></i><?= __('preview_template') ?>: 24<?= __('h') ?>
            </button>
            <button type="button" class="btn btn-light btn-sm" onclick="ntPreviewEmail('appointment_2h')">
                <i class="fa-solid fa-eye me-1"></i><?= __('preview_template') ?>: 2<?= __('h') ?>
            </button>
            <a class="btn btn-light btn-sm" href="<?= BP_URL ?>admin/settings.php">
                <i class="fa-solid fa-gear me-1"></i><?= __('settings') ?>
            </a>
        </div>
    </div>

    <!-- KPI strip (last 30 days) -->
    <div class="appt-kpis">
        <a class="appt-kpi" href="?status=sent">
            <div class="appt-kpi-icon" style="background:#10b981"><i class="fa-solid fa-paper-plane"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('st_sent') ?> · 30<?= __('d') ?></div>
                <div class="appt-kpi-value"><?= (int)($counts['sent']??0) ?></div>
            </div>
        </a>
        <a class="appt-kpi" href="?status=queued">
            <div class="appt-kpi-icon" style="background:#f59e0b"><i class="fa-solid fa-hourglass-half"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('st_queued') ?></div>
                <div class="appt-kpi-value"><?= (int)($counts['queued']??0) ?></div>
            </div>
        </a>
        <a class="appt-kpi" href="?status=skipped">
            <div class="appt-kpi-icon" style="background:#94a3b8"><i class="fa-solid fa-forward"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('st_skipped') ?></div>
                <div class="appt-kpi-value"><?= (int)($counts['skipped']??0) ?></div>
            </div>
        </a>
        <a class="appt-kpi" href="?status=failed">
            <div class="appt-kpi-icon" style="background:#ef4444"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('st_failed') ?></div>
                <div class="appt-kpi-value"><?= (int)($counts['failed']??0) ?></div>
            </div>
        </a>
    </div>

    <!-- Filters -->
    <form method="get" class="appt-filters">
        <div class="appt-filter-group flex-grow-1">
            <input type="search" name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="<?= __('search_notification_placeholder') ?>">
        </div>
        <div class="appt-filter-group">
            <select name="kind" class="form-select form-select-sm">
                <option value=""><?= __('all_kinds') ?></option>
                <?php foreach (['appointment_24h','appointment_2h','generic'] as $k): ?>
                    <option value="<?= $k ?>" <?= $kind===$k?'selected':'' ?>><?= $k ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="appt-filter-group">
            <select name="status" class="form-select form-select-sm">
                <option value=""><?= __('all_statuses') ?></option>
                <?php foreach (['queued','sent','failed','skipped'] as $st): ?>
                    <option value="<?= $st ?>" <?= $status===$st?'selected':'' ?>><?= __('st_'.$st) ?: $st ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-teal btn-sm"><i class="fa-solid fa-filter me-1"></i><?= __('apply') ?></button>
        <?php if ($q||$kind||$status): ?><a class="btn btn-light btn-sm" href="?"><?= __('clear_filters') ?></a><?php endif; ?>
    </form>

    <div class="table-card">
        <div class="table-responsive d-none d-md-block">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th>#</th><th><?= __('date') ?></th><th><?= __('kind') ?></th><th>To</th><th><?= __('title') ?></th>
                <th><?= __('subject_ref') ?></th><th><?= __('status') ?></th><th><?= __('sent_at') ?></th><th></th>
            </tr></thead>
            <tbody>
                <?php foreach ($rows as $n):
                    $col = ['queued'=>'warning','sent'=>'success','failed'=>'danger','skipped'=>'secondary'][$n['status']];
                ?>
                    <tr>
                        <td><?= (int)$n['id'] ?></td>
                        <td class="small"><?= format_date($n['created_at']) ?></td>
                        <td><span class="badge bg-light text-dark"><?= e($n['kind']) ?></span></td>
                        <td class="small"><?= e($n['recipient']) ?></td>
                        <td class="small text-muted" style="max-width:280px"><?= e(mb_strimwidth($n['subject']??'',0,80,'…')) ?></td>
                        <td class="small">
                            <?php if ($n['subject_type'] === 'appointment' && $n['subject_id']): ?>
                                <a href="<?= BP_URL ?>admin/appointments.php?action=view&id=<?= (int)$n['subject_id'] ?>"><?= e($n['subject_type']) ?> #<?= (int)$n['subject_id'] ?></a>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><span class="badge bg-<?= $col ?>"><?= __('st_'.$n['status']) ?: e($n['status']) ?></span></td>
                        <td class="small"><?= $n['sent_at'] ? format_date($n['sent_at']) : '—' ?></td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-light" title="<?= __('view_email') ?>" onclick="ntViewSentEmail(<?= (int)$n['id'] ?>)">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                            <?php if ($n['error']): ?>
                                <button class="btn btn-sm btn-outline-danger" title="<?= e($n['error']) ?>" data-bs-toggle="tooltip"><i class="fa-solid fa-circle-exclamation"></i></button>
                            <?php endif; ?>
                            <?php if (in_array($n['status'], ['failed','skipped']) && can('settings.edit')): ?>
                                <form method="post" action="?action=resend&id=<?= (int)$n['id'] ?>" class="d-inline" data-confirm="<?= __('resend_email_confirm') ?>">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-sm btn-light"><i class="fa-solid fa-rotate-right"></i></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4"><?= __('no_data') ?></td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>

        <!-- Mobile entity card list -->
        <div class="entity-list d-md-none">
            <?php if (!$rows): ?>
                <div class="empty-state"><i class="fa-regular fa-bell"></i><div><?= __('no_data') ?></div></div>
            <?php else: foreach ($rows as $n):
                $statusChip = ['queued'=>'warn','sent'=>'success','failed'=>'danger','skipped'=>''][$n['status']] ?? '';
                $avatarColor = ['queued'=>'amber','sent'=>'success','failed'=>'danger','skipped'=>'slate'][$n['status']] ?? '';
                echo render_entity_card([
                    'avatar_icon' => 'fa-envelope',
                    'avatar_class' => 'square '.$avatarColor,
                    'title' => $n['recipient'],
                    'meta' => [format_date($n['created_at'],'Y-m-d H:i')],
                    'chips' => [
                        ['label'=>$n['status'],'icon'=>'fa-circle-dot','class'=>$statusChip],
                        ['label'=>$n['kind'],'icon'=>'fa-paper-plane'],
                        !empty($n['error']) ? ['label'=>__('error'),'icon'=>'fa-circle-exclamation','class'=>'danger','tooltip'=>$n['error']] : null,
                    ],
                    'actions' => [
                        ['icon'=>'fa-eye','label'=>'view_email','href'=>'javascript:ntViewSentEmail('.(int)$n['id'].')'],
                        (in_array($n['status'], ['failed','skipped']) && can('settings.edit'))
                            ? ['icon'=>'fa-rotate-right','label'=>'resend','href'=>'?action=resend&id='.(int)$n['id'],'confirm'=>'are_you_sure'] : null,
                    ],
                ]);
            endforeach; endif; ?>
        </div>

        <div class="p-2"><?= render_pagination($total,$page,$perPage,BP_URL.'admin/notifications.php?'.http_build_query(['q'=>$q,'kind'=>$kind,'status'=>$status])) ?></div>
    </div>

    <div class="alert alert-info mt-3 small">
        <i class="fa-solid fa-circle-info me-1"></i>
        <strong><?= __('cron_schedule') ?>:</strong> <?= __('cron_help') ?>
        <pre class="mb-0 mt-2 bg-white p-2 rounded"><code>*/15 * * * * /usr/bin/php <?= BP_PATH ?>/cron/send-reminders.php &gt;&gt; /var/log/nourstouch-cron.log 2&gt;&amp;1</code></pre>
    </div>
</div>

<!-- Email preview modal -->
<div id="email-preview" class="email-preview" hidden>
    <div class="email-preview-backdrop" onclick="ntClosePreview()"></div>
    <div class="email-preview-panel">
        <div class="email-preview-head">
            <div class="d-flex align-items-center gap-2">
                <i class="fa-regular fa-envelope-open text-teal"></i>
                <strong id="email-preview-title"><?= __('email_preview') ?></strong>
                <span id="email-preview-sub" class="badge bg-light text-dark border"></span>
            </div>
            <div class="d-flex gap-2">
                <a id="email-preview-open" href="#" target="_blank" class="btn btn-light btn-sm" title="<?= __('open_in_tab') ?>">
                    <i class="fa-solid fa-up-right-from-square"></i>
                </a>
                <button type="button" class="btn-close" onclick="ntClosePreview()"></button>
            </div>
        </div>
        <iframe id="email-preview-frame" src="about:blank"></iframe>
    </div>
</div>

<style>
.email-preview{position:fixed;inset:0;z-index:1080;display:flex;align-items:center;justify-content:center}
.email-preview[hidden]{display:none}
.email-preview-backdrop{position:absolute;inset:0;background:rgba(15,23,42,.55);backdrop-filter:blur(2px)}
.email-preview-panel{position:relative;background:#fff;width:min(680px,92vw);height:min(82vh,820px);border-radius:14px;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.25)}
.email-preview-head{display:flex;align-items:center;justify-content:space-between;padding:.7rem 1rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;flex-shrink:0}
.email-preview iframe{flex:1;border:0;background:#f6fbfa}
</style>

<script>
function ntPreviewEmail(kind){
    const url = '<?= BP_URL ?>admin/notifications.php?action=preview&kind=' + encodeURIComponent(kind);
    const labels = {'appointment_24h':'<?= __('tpl_appt_24h') ?>','appointment_2h':'<?= __('tpl_appt_2h') ?>'};
    document.getElementById('email-preview-title').textContent = '<?= __('email_preview') ?>';
    document.getElementById('email-preview-sub').textContent = labels[kind] || kind;
    document.getElementById('email-preview-frame').src = url;
    document.getElementById('email-preview-open').href = url;
    document.getElementById('email-preview').hidden = false;
}
function ntViewSentEmail(id){
    const url = '<?= BP_URL ?>admin/notifications.php?action=view&id=' + id;
    document.getElementById('email-preview-title').textContent = '<?= __('sent_email') ?> #' + id;
    document.getElementById('email-preview-sub').textContent = '';
    document.getElementById('email-preview-frame').src = url;
    document.getElementById('email-preview-open').href = url;
    document.getElementById('email-preview').hidden = false;
}
function ntClosePreview(){
    document.getElementById('email-preview').hidden = true;
    document.getElementById('email-preview-frame').src = 'about:blank';
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') ntClosePreview(); });
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));
</script>
<?php include BP_PARTIALS . '/footer.php'; ?>
