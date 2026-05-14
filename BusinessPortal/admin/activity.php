<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('activity_log.view');

$PageTitle = __('activity_log');

$q       = trim($_GET['q'] ?? '');
$module  = trim($_GET['module'] ?? '');
$action  = trim($_GET['action_filter'] ?? '');
$page    = (int)($_GET['page'] ?? 1);
[$perPage, $offset] = paginate_query($page, 50);

$where = "1=1"; $params = [];
if ($q)      { $where .= " AND (description LIKE ? OR user_name LIKE ? OR ip LIKE ?)"; array_push($params, "%$q%", "%$q%", "%$q%"); }
if ($module) { $where .= " AND module = ?";   $params[] = $module; }
if ($action) { $where .= " AND action = ?";   $params[] = $action; }

$tot = db()->prepare("SELECT COUNT(*) FROM activity_logs WHERE $where");
$tot->execute($params);
$total = (int)$tot->fetchColumn();

$sql = "SELECT * FROM activity_logs WHERE $where ORDER BY id DESC LIMIT $perPage OFFSET $offset";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$modules = db()->query("SELECT DISTINCT module FROM activity_logs WHERE module IS NOT NULL ORDER BY module")->fetchAll(PDO::FETCH_COLUMN);
$actions = db()->query("SELECT DISTINCT action FROM activity_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

// KPI stats
$kpi = db()->query("
    SELECT
      COUNT(*) AS total,
      SUM(created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY))  AS d1,
      SUM(created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))  AS d7,
      COUNT(DISTINCT user_id) AS users
    FROM activity_logs
")->fetch() ?: ['total'=>0,'d1'=>0,'d7'=>0,'users'=>0];

include BP_PARTIALS . '/header.php';
?>
<div class="page-wrap">
    <!-- Header -->
    <div class="page-header">
        <h4 class="m-0">
            <i class="fa-solid fa-list-check text-teal me-2"></i><?= __('activity_log') ?>
            <small class="text-muted ms-2" style="font-size:.85rem">(<?= $total ?>)</small>
        </h4>
    </div>

    <!-- KPI strip -->
    <div class="appt-kpis">
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#6366f1"><i class="fa-solid fa-list-check"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('total_events') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['total'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#0d9488"><i class="fa-solid fa-bolt"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('last_24h') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['d1'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#10b981"><i class="fa-solid fa-calendar-week"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('last_7d') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['d7'] ?></div>
            </div>
        </div>
        <div class="appt-kpi">
            <div class="appt-kpi-icon" style="background:#0ea5e9"><i class="fa-solid fa-users"></i></div>
            <div>
                <div class="appt-kpi-label"><?= __('active_users') ?></div>
                <div class="appt-kpi-value"><?= (int)$kpi['users'] ?></div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <form method="get" class="appt-filters">
        <div class="appt-filter-group flex-grow-1">
            <input type="search" name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="<?= __('search') ?>…">
        </div>
        <div class="appt-filter-group">
            <select name="module" class="form-select form-select-sm">
                <option value=""><?= __('all_modules') ?></option>
                <?php foreach ($modules as $m): ?>
                    <option value="<?= e($m) ?>" <?= $module===$m?'selected':'' ?>><?= e($m) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="appt-filter-group">
            <select name="action_filter" class="form-select form-select-sm">
                <option value=""><?= __('all_actions') ?></option>
                <?php foreach ($actions as $a): ?>
                    <option value="<?= e($a) ?>" <?= $action===$a?'selected':'' ?>><?= e($a) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-teal btn-sm"><i class="fa-solid fa-filter me-1"></i><?= __('apply') ?></button>
        <?php if ($q||$module||$action): ?><a class="btn btn-light btn-sm" href="?"><?= __('clear_filters') ?></a><?php endif; ?>
    </form>

    <div class="table-card">
        <div class="table-responsive d-none d-md-block">
        <table class="table mb-0 align-middle">
            <thead><tr>
                <th>#</th><th><?= __('user') ?></th><th><?= __('action') ?></th>
                <th><?= __('module') ?></th><th><?= __('description') ?></th>
                <th><?= __('ip') ?></th><th><?= __('created_at') ?></th>
            </tr></thead>
            <tbody>
                <?php if (!$logs): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4"><?= __('no_data') ?></td></tr>
                <?php else: foreach ($logs as $l): ?>
                    <tr>
                        <td><?= (int)$l['id'] ?></td>
                        <td class="small"><?= e($l['user_name'] ?? '—') ?></td>
                        <td><span class="badge bg-light text-dark"><?= e($l['action']) ?></span></td>
                        <td class="small"><?= e($l['module']) ?></td>
                        <td class="small text-muted"><?= e($l['description']) ?></td>
                        <td class="small"><code><?= e($l['ip']) ?></code></td>
                        <td class="small"><?= format_date($l['created_at']) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>

        <!-- Mobile entity card list -->
        <div class="entity-list d-md-none">
            <?php if (!$logs): ?>
                <div class="empty-state"><i class="fa-regular fa-rectangle-list"></i><div><?= __('no_data') ?></div></div>
            <?php else: foreach ($logs as $l):
                $initials = mb_strtoupper(mb_substr($l['user_name'] ?? '?',0,1));
                echo render_entity_card([
                    'avatar' => $initials,
                    'avatar_class' => 'slate',
                    'title' => $l['user_name'] ?? '—',
                    'meta' => [format_date($l['created_at'],'Y-m-d H:i'), $l['ip']],
                    'chips' => [
                        ['label'=>$l['action'],'icon'=>'fa-bolt','class'=>'info'],
                        ['label'=>$l['module'],'icon'=>'fa-cube','class'=>'teal'],
                        !empty($l['description']) ? ['label'=>mb_strimwidth($l['description'],0,40,'…'),'icon'=>'fa-quote-right'] : null,
                    ],
                ]);
            endforeach; endif; ?>
        </div>

        <div class="p-2">
            <?= render_pagination($total, $page, $perPage, BP_URL . 'admin/activity.php?' . http_build_query(['q'=>$q,'module'=>$module,'action_filter'=>$action])) ?>
        </div>
    </div>
</div>
<?php include BP_PARTIALS . '/footer.php'; ?>
