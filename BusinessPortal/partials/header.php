<?php
$cu = currentUser();
$currentPath = basename($_SERVER['PHP_SELF']);

// Form-mode: create/edit URLs render the form alone, no topbar/nav (modal-friendly)
$ntFormMode = isset($_GET['action']) && in_array($_GET['action'], ['create','edit'], true);

// ── Nav groups ──────────────────────────────────────────────────────────
// Each group: ['label_key' => '…', 'icon' => '…', 'items' => [['href','icon','label_key','perm'], ...]]
// Items with `direct => true` render as a single link instead of a dropdown.

$navGroups = [
    [
        'direct' => true,
        'href'   => 'admin/index.php',
        'icon'   => 'fa-gauge-high',
        'label'  => 'dashboard',
        'perm'   => 'dashboard.view',
    ],
    [
        'icon'  => 'fa-user-injured',
        'label' => 'patients',
        'perm'  => 'patients.view',
        'items' => [
            ['admin/patients.php',     'fa-list',        'patients_list',  'patients.view'],
        ],
    ],
    [
        'icon'  => 'fa-calendar-check',
        'label' => 'appointments',
        'perm'  => 'appointments.view',
        'items' => [
            ['admin/appointments.php',  'fa-list',        'appointments_list', 'appointments.view'],
            ['admin/calendar.php',      'fa-calendar',    'calendar',     'appointments.view'],
            ['admin/booking-requests.php','fa-bell',      'booking_requests', 'appointments.view'],
        ],
    ],
    [
        'icon'  => 'fa-stethoscope',
        'label' => 'consultations',
        'perm'  => 'consultations.view',
        'items' => [
            ['admin/consultations.php', 'fa-list',        'consultations_list',  'consultations.view'],
        ],
    ],
    [
        'icon'  => 'fa-hand-holding-heart',
        'label' => 'catalog',
        'perm'  => 'services.view',
        'items' => [
            ['admin/services.php',      'fa-hand-holding-heart','services',     'services.view'],
            ['admin/categories.php',    'fa-tags',         'categories',  'services.view'],
            ['admin/packages.php',      'fa-box-open',     'packages',    'packages.view'],
        ],
    ],
    [
        'icon'  => 'fa-money-bill-wave',
        'label' => 'finance',
        'perm'  => 'invoices.view',
        'items' => [
            ['admin/invoices.php',      'fa-file-invoice-dollar', 'invoices', 'invoices.view'],
            ['admin/payments.php',      'fa-money-bill-wave',  'payments',  'payments.view'],
            ['admin/expenses.php',      'fa-receipt',          'expenses',  'expenses.view'],
            ['admin/expense-categories.php','fa-tags',         'expense_categories','expenses.view'],
            ['admin/cash-drawer.php',   'fa-cash-register',    'cash_drawer','payments.view'],
        ],
    ],
    [
        'icon'  => 'fa-user-tie',
        'label' => 'hr',
        'perm'  => 'employees.view',
        'items' => [
            ['admin/employees.php',     'fa-user-tie',         'employees',    'employees.view'],
            ['admin/attendance.php',    'fa-clock',            'attendance',   'attendance.view'],
            ['admin/attendance-month.php','fa-calendar',       'monthly_attendance','attendance.view'],
            ['admin/leaves.php',        'fa-plane-departure',  'leaves',       'leaves.view'],
            ['admin/payroll.php',       'fa-wallet',           'payroll',      'payroll.view'],
            ['admin/advances.php',      'fa-money-bill-trend-up','salary_advances','payroll.view'],
        ],
    ],
    [
        'icon'  => 'fa-chart-pie',
        'label' => 'reports',
        'perm'  => 'reports.view',
        'items' => [
            ['admin/report-employees.php','fa-user-tie',  'employee_performance', 'reports.view'],
            ['admin/report-retention.php','fa-arrows-rotate','patient_retention','reports.view'],
            ['admin/reports.php',       'fa-chart-line',  'pnl_report',   'reports.view'],
        ],
    ],
    [
        'icon'  => 'fa-newspaper',
        'label' => 'cms',
        'perm'  => 'cms.view',
        'items' => [
            ['admin/blog.php',          'fa-newspaper',       'blog',          'cms.view'],
            ['admin/blog-categories.php','fa-tags',           'blog_categories','cms.view'],
            ['admin/sliders.php',       'fa-images',          'sliders',       'cms.view'],
            ['admin/gallery.php',       'fa-image',           'gallery',       'cms.view'],
            ['admin/faqs.php',          'fa-circle-question', 'faqs',          'cms.view'],
        ],
    ],
    [
        'icon'  => 'fa-shield-halved',
        'label' => 'system',
        'perm'  => 'settings.view',
        'items' => [
            ['admin/users.php',         'fa-users',           'users',         'users.view'],
            ['admin/roles.php',         'fa-shield-halved',   'roles',         'roles.view'],
            ['admin/settings.php',      'fa-gear',            'settings',      'settings.view'],
            ['admin/notifications.php', 'fa-bell',            'notifications', 'settings.view'],
            ['admin/system.php',        'fa-stethoscope',     'system_health', 'settings.view'],
            ['admin/activity.php',      'fa-list-check',      'activity_log',  'activity_log.view'],
        ],
    ],
];

// Detect roles for the user label
$userRoles = $cu ? userRoles((int)$cu['id']) : [];
$primaryRole = $userRoles[0]['name'] ?? '';
?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>" dir="<?= e($dir) ?>" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= isset($PageTitle) ? e($PageTitle) . ' — ' : '' ?><?= APP_NAME ?></title>
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <script>
    window.NT_I18N = <?= json_encode([
        'confirm'        => __('confirm'),
        'cancel'         => __('cancel'),
        'delete'         => __('delete'),
        'are_you_sure'   => __('are_you_sure'),
        'saved_ok'       => __('saved'),
        'review_fields'  => __('review_fields'),
        'submit_failed'  => __('submit_failed'),
        'saving'         => __('saving'),
    ], JSON_UNESCAPED_UNICODE) ?>;
    </script>

    <?php if ($dir === 'rtl'): ?>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
    <?php else: ?>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800&display=swap">
    <link rel="stylesheet" href="<?= BP_ASSETS ?>css/admin.css?v=<?= @filemtime(BP_PATH . '/assets/css/admin.css') ?>">
</head>
<body class="admin-body <?= $ntFormMode ? 'form-mode' : '' ?>">

<?php if (!$ntFormMode): ?>
<!-- ═══════════════ TOP HEADER (dark) ═══════════════ -->
<header class="topbar-dark">
    <div class="container d-flex align-items-center justify-content-between">
        <div class="brand">
            <?php
                $logo = setting('clinic_logo', '');
                $clinicName = ($lang === 'en')
                    ? (setting('site_name_en', '') ?: setting('site_name_ar', APP_NAME_AR))
                    : (setting('site_name_ar', '') ?: setting('site_name_en', APP_NAME));
            ?>
            <?php if ($logo): ?>
                <img src="<?= UPLOADS_URL . e($logo) ?>" alt="" class="brand-logo">
            <?php else: ?>
                <i class="fa-solid fa-spa"></i>
            <?php endif; ?>
            <span class="brand-text"><?= e($clinicName) ?></span>
        </div>

        <div class="topbar-actions d-flex align-items-center gap-2">
            <!-- search -->
            <div class="topbar-search d-none d-md-block">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" placeholder="<?= __('search') ?>…">
            </div>

            <!-- language -->
            <a href="?lang=<?= $lang === 'ar' ? 'en' : 'ar' ?>" class="icon-btn" title="Language">
                <?= $lang === 'ar' ? 'EN' : 'ع' ?>
            </a>

            <!-- notifications -->
            <a class="icon-btn" href="<?= BP_URL ?>admin/notifications.php" title="Notifications">
                <i class="fa-regular fa-bell"></i>
            </a>

            <!-- user -->
            <div class="dropdown user-menu">
                <button class="user-btn" data-bs-toggle="dropdown">
                    <div class="avatar">
                        <?php if (!empty($cu['avatar'])): ?>
                            <img src="<?= UPLOADS_URL . e($cu['avatar']) ?>" alt="">
                        <?php elseif (!empty($logo)): ?>
                            <img src="<?= UPLOADS_URL . e($logo) ?>" alt="" style="object-fit:contain;background:#fff;padding:3px">
                        <?php else: ?>
                            <i class="fa-solid fa-user"></i>
                        <?php endif; ?>
                    </div>
                    <div class="user-info">
                        <span class="user-name"><?= e($clinicName) ?></span>
                        <?php if ($primaryRole): ?><span class="user-role"><?= e($primaryRole) ?></span><?php endif; ?>
                    </div>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="<?= BP_URL ?>admin/settings.php#account"><i class="fa-solid fa-user-gear me-2"></i><?= __('my_account') ?></a></li>
                    <?php if (can('settings.view')): ?>
                        <li><a class="dropdown-item" href="<?= BP_URL ?>admin/settings.php"><i class="fa-solid fa-gear me-2"></i><?= __('settings') ?></a></li>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="<?= BP_URL ?>auth/logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i><?= __('logout') ?></a></li>
                </ul>
            </div>
        </div>
    </div>
</header>

<!-- ═══════════════ MAIN NAV (horizontal with dropdowns) ═══════════════ -->
<nav class="mainnav">
    <div class="container">
        <button class="mainnav-toggler d-lg-none" type="button" onclick="document.getElementById('mainnavCollapse').classList.toggle('show')">
            <i class="fa-solid fa-bars"></i> <?= __('menu') ?>
        </button>

        <div class="mainnav-collapse" id="mainnavCollapse">
            <ul class="mainnav-list">
                <?php foreach ($navGroups as $group):
                    // Hide whole group if user has none of the permissions in it
                    if (!empty($group['direct'])) {
                        if (!can($group['perm'])) continue;
                        $href = BP_URL . $group['href'];
                        $isActive = (basename($group['href']) === $currentPath);
                        ?>
                        <li class="mainnav-item">
                            <a class="mainnav-link <?= $isActive?'active':'' ?>" href="<?= $href ?>">
                                <span><?= __($group['label']) ?></span>
                            </a>
                        </li>
                        <?php
                        continue;
                    }

                    // Group with items: check that at least one item is permitted
                    $allowedItems = array_filter($group['items'], fn($it) => can($it[3]));
                    if (!$allowedItems) continue;

                    // Active if any child page matches
                    $isActive = false;
                    foreach ($allowedItems as $it) {
                        if (basename(strtok($it[0], '?')) === $currentPath) { $isActive = true; break; }
                    }
                ?>
                    <li class="mainnav-item dropdown">
                        <a class="mainnav-link dropdown-toggle <?= $isActive?'active':'' ?>" href="#" data-bs-toggle="dropdown">
                            <span><?= __($group['label']) ?></span>
                        </a>
                        <ul class="dropdown-menu">
                            <?php foreach ($allowedItems as [$href, $icon, $key, $perm]):
                                $active = (basename(strtok($href, '?')) === $currentPath) ? 'active' : '';
                            ?>
                                <li>
                                    <a class="dropdown-item <?= $active ?>" href="<?= BP_URL . $href ?>">
                                        <i class="fa-solid <?= e($icon) ?>"></i>
                                        <span><?= __($key) ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</nav>

<!-- ═══════════════ BOTTOM TAB BAR (mobile only — app-like) ═══════════════ -->
<?php
$bottomTabs = [
    ['admin/index.php',        'fa-house',          'dashboard',     'dashboard.view'],
    ['admin/patients.php',     'fa-user-injured',   'patients',      'patients.view'],
    ['admin/appointments.php', 'fa-calendar-check', 'appointments',  'appointments.view'],
    ['admin/invoices.php',     'fa-file-invoice-dollar','invoices',  'invoices.view'],
];
$bottomTabs = array_values(array_filter($bottomTabs, fn($t) => can($t[3])));
?>
<nav class="bottombar d-lg-none">
    <?php foreach ($bottomTabs as [$href, $icon, $key, $perm]):
        $active = (basename($href) === $currentPath) ? 'active' : '';
    ?>
        <a class="bottombar-item <?= $active ?>" href="<?= BP_URL . $href ?>">
            <i class="fa-solid <?= e($icon) ?>"></i>
            <span><?= __($key) ?></span>
        </a>
    <?php endforeach; ?>
    <button type="button" class="bottombar-item" onclick="document.getElementById('moreSheet').classList.add('show')">
        <i class="fa-solid fa-grip"></i>
        <span><?= __('more') ?></span>
    </button>
</nav>

<!-- ═══════════════ MORE SHEET (mobile app drawer) ═══════════════ -->
<div class="more-sheet" id="moreSheet">
    <div class="more-sheet-backdrop" onclick="document.getElementById('moreSheet').classList.remove('show')"></div>
    <div class="more-sheet-panel">
        <div class="more-sheet-header">
            <h6 class="m-0"><?= __('menu') ?></h6>
            <button type="button" class="btn-close" onclick="document.getElementById('moreSheet').classList.remove('show')"></button>
        </div>
        <div class="more-sheet-body">
            <?php
            // Show every group's items in a grid
            foreach ($navGroups as $group):
                if (!empty($group['direct'])) continue;   // direct already in bottom tabs
                $allowedItems = array_filter($group['items'], fn($it) => can($it[3]));
                if (!$allowedItems) continue;
            ?>
                <div class="more-group">
                    <div class="more-group-title">
                        <i class="fa-solid <?= e($group['icon']) ?>"></i> <?= __($group['label']) ?>
                    </div>
                    <div class="more-grid">
                        <?php foreach ($allowedItems as [$href, $icon, $key, $perm]): ?>
                            <a class="more-tile" href="<?= BP_URL . $href ?>">
                                <i class="fa-solid <?= e($icon) ?>"></i>
                                <span><?= __($key) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php endif; /* end !$ntFormMode block (topbar/nav/bottombar) */ ?>

<!-- ═══════════════ TOAST STACK (custom flash messages) ═══════════════ -->
<?php $toasts = flashes(); if ($toasts): ?>
    <div class="nt-toast-stack">
        <?php
        $icons = [
            'success' => 'fa-circle-check',
            'error'   => 'fa-circle-exclamation',
            'warning' => 'fa-triangle-exclamation',
            'info'    => 'fa-circle-info',
        ];
        foreach ($toasts as $f):
            $type = $f['type'] === 'error' ? 'error' : ($f['type'] ?: 'info');
            $icon = $icons[$type] ?? 'fa-circle-info';
        ?>
            <div class="nt-toast nt-toast-<?= e($type) ?>" data-auto-dismiss="6000">
                <div class="nt-toast-icon"><i class="fa-solid <?= e($icon) ?>"></i></div>
                <div class="nt-toast-body"><?= e($f['msg']) ?></div>
                <button type="button" class="nt-toast-close" onclick="this.parentElement.classList.add('closing')">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- ═══════════════ MAIN CONTENT ═══════════════ -->
<main class="content">
