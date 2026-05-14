<?php
// ════════════════════════════════════════════════════════════════════════
// Phase 1 Seeder — roles, permissions, default admin, settings
// Run from CLI:  php BusinessPortal/config/seed.php
// Or via browser: /nourstouch/BusinessPortal/config/seed.php
// ════════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/config.php';

$pdo = db();

echo "▸ Seeding roles…\n";
$roles = [
    ['Super Admin',  'super-admin',  'Full system access',          1],
    ['Admin',        'admin',        'Manages clinic operations',   1],
    ['Doctor',       'doctor',       'Diagnoses and consultations', 1],
    ['Therapist',    'therapist',    'Delivers therapy sessions',   1],
    ['Receptionist', 'receptionist', 'Front desk + bookings',       1],
    ['Accountant',   'accountant',   'Finance + invoicing',         1],
    ['Employee',     'employee',     'Self-service portal',         1],
];
$ins = $pdo->prepare("INSERT IGNORE INTO roles (name,slug,description,is_system) VALUES (?,?,?,?)");
foreach ($roles as $r) $ins->execute($r);

echo "▸ Seeding permissions…\n";
$modules = [
    'dashboard'    => ['view'],
    'patients'     => ['view','create','edit','delete','export'],
    'services'     => ['view','create','edit','delete'],
    'packages'     => ['view','create','edit','delete'],
    'appointments' => ['view','create','edit','delete','override_block','approve'],
    'consultations'=> ['view','create','edit','delete'],
    'invoices'     => ['view','create','edit','delete','export','refund'],
    'payments'     => ['view','create','edit','delete','export'],
    'expenses'     => ['view','create','edit','delete','export'],
    'employees'    => ['view','create','edit','delete'],
    'attendance'   => ['view','create','edit','delete','approve'],
    'leaves'       => ['view','create','edit','delete','approve'],
    'payroll'      => ['view','create','edit','delete','export'],
    'reports'      => ['view','export'],
    'cms'          => ['view','create','edit','delete','publish'],
    'users'        => ['view','create','edit','delete'],
    'roles'        => ['view','create','edit','delete'],
    'settings'     => ['view','edit'],
    'activity_log' => ['view','export'],
];
$insP = $pdo->prepare("INSERT IGNORE INTO permissions (name,slug,module) VALUES (?,?,?)");
$allPerms = [];
foreach ($modules as $module => $actions) {
    foreach ($actions as $action) {
        $slug = "$module.$action";
        $name = ucfirst($action) . ' ' . str_replace('_', ' ', ucwords($module, '_'));
        $insP->execute([$name, $slug, $module]);
        $allPerms[] = $slug;
    }
}

echo "▸ Mapping role → permissions…\n";
$pdo->exec("DELETE FROM role_permissions");
$rp = $pdo->prepare("
    INSERT INTO role_permissions (role_id, permission_id)
    SELECT r.id, p.id FROM roles r, permissions p
    WHERE r.slug = ? AND p.slug = ?
");

// Super Admin → all permissions
foreach ($allPerms as $slug) $rp->execute(['super-admin', $slug]);

// Admin → almost everything except role/user delete + settings limited
foreach ($allPerms as $slug) {
    if (in_array($slug, ['roles.delete','users.delete'])) continue;
    $rp->execute(['admin', $slug]);
}

// Doctor → consultations, patients, appointments, dashboard
$doctorPerms = [
    'dashboard.view',
    'patients.view','patients.edit',
    'appointments.view','appointments.create','appointments.edit',
    'consultations.view','consultations.create','consultations.edit',
];
foreach ($doctorPerms as $slug) $rp->execute(['doctor', $slug]);

// Therapist
$therapistPerms = [
    'dashboard.view',
    'patients.view',
    'appointments.view','appointments.edit',
    'attendance.view','attendance.create',
];
foreach ($therapistPerms as $slug) $rp->execute(['therapist', $slug]);

// Receptionist
$receptionPerms = [
    'dashboard.view',
    'patients.view','patients.create','patients.edit',
    'appointments.view','appointments.create','appointments.edit',
    'invoices.view','invoices.create',
    'payments.view','payments.create',
];
foreach ($receptionPerms as $slug) $rp->execute(['receptionist', $slug]);

// Accountant
$accPerms = [
    'dashboard.view',
    'invoices.view','invoices.create','invoices.edit','invoices.export','invoices.refund',
    'payments.view','payments.create','payments.edit','payments.export',
    'expenses.view','expenses.create','expenses.edit','expenses.export',
    'payroll.view','payroll.create','payroll.edit','payroll.export',
    'reports.view','reports.export',
];
foreach ($accPerms as $slug) $rp->execute(['accountant', $slug]);

// Employee → self only
$rp->execute(['employee', 'dashboard.view']);

echo "▸ Seeding default admin…\n";
$email = getenv('ADMIN_EMAIL') ?: 'admin@clinic.com';
$pass  = getenv('ADMIN_PASSWORD') ?: 'Admin@123';
$hash  = password_hash($pass, PASSWORD_BCRYPT);

$exists = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$exists->execute([$email]);
$row = $exists->fetch();

if ($row) {
    $uid = $row['id'];
    echo "  • admin already exists (id=$uid), skipping.\n";
} else {
    $stmt = $pdo->prepare("
        INSERT INTO users (name,email,password,status,must_change_pw,created_at,updated_at)
        VALUES ('Super Admin', ?, ?, 'active', 1, NOW(), NOW())
    ");
    $stmt->execute([$email, $hash]);
    $uid = (int)$pdo->lastInsertId();
    echo "  • admin created (id=$uid).\n";
}

$pdo->prepare("
    INSERT IGNORE INTO user_roles (user_id, role_id)
    SELECT ?, id FROM roles WHERE slug = 'super-admin'
")->execute([$uid]);

echo "▸ Seeding default settings…\n";
$settings = [
    ['site_name_ar',     "لمسة نور",          'general', 'text'],
    ['site_name_en',     "Nour's Touch",      'general', 'text'],
    ['contact_phone',    '+962 7 0000 0000',  'contact', 'text'],
    ['contact_email',    'info@nourstouch.com','contact','text'],
    ['address',          'Amman, Jordan',     'contact', 'text'],
    ['currency',         'JOD',               'general', 'text'],
    ['currency_symbol',  'د.أ',                'general', 'text'],
    ['default_lang',     'ar',                'general', 'text'],
    ['per_page',         '25',                'general', 'number'],
    ['booking_block_unpaid', '1',             'rules',   'boolean'],
    ['working_hours_from','09:00',            'general', 'time'],
    ['working_hours_to', '21:00',             'general', 'time'],
];
$ss = $pdo->prepare("INSERT IGNORE INTO settings (`key`,`value`,`group`,`type`) VALUES (?,?,?,?)");
foreach ($settings as $s) $ss->execute($s);

echo "✅ Seed complete.\n";
echo "   Login: $email\n   Password: $pass  (must change on first login)\n";
