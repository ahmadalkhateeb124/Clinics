<?php
/**
 * ════════════════════════════════════════════════════════════════════════
 * Nour's Touch — One-shot installer
 *
 *   Drops & rebuilds the entire database, then runs every phase seeder
 *   in order. Re-runnable: each seeder uses INSERT IGNORE / UPSERTs.
 *
 *   Usage (CLI only):
 *       php BusinessPortal/config/install.php
 *       php BusinessPortal/config/install.php --schema-only   # no seeds
 *       php BusinessPortal/config/install.php --keep          # don't drop tables
 * ════════════════════════════════════════════════════════════════════════
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/config.php';

$schemaOnly = in_array('--schema-only', $argv ?? [], true);
$keep       = in_array('--keep',        $argv ?? [], true);

$pdo = db();

echo "════════════════════════════════════════════════════════════════════════\n";
echo "  Nour's Touch Clinic — Installer\n";
echo "════════════════════════════════════════════════════════════════════════\n";

if (!$keep) {
    echo "▸ Dropping existing tables…\n";
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        $pdo->exec("DROP TABLE IF EXISTS `$t`");
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "  ▸ " . count($tables) . " tables dropped.\n";
}

// ────────────────────────────────────────────────────────────────────────
// Apply schemas in order
// ────────────────────────────────────────────────────────────────────────
$schemas = [
    'schema.sql'        => 'Phase 1 — auth, roles, settings, audit',
    'schema_phase2.sql' => 'Phase 2 — patients, services, packages',
    'schema_phase3.sql' => 'Phase 3 — appointments, rooms, installments',
    'schema_phase4.sql' => 'Phase 4 — consultations, treatment plans',
    'schema_phase5.sql' => 'Phase 5 — invoices, payments, expenses',
    'schema_phase6.sql' => 'Phase 6 — HR (employees, attendance, payroll)',
    'schema_phase8.sql' => 'Phase 8 — CMS (blog, sliders, FAQs)',
    'schema_phase9.sql' => 'Phase 9 — notifications log',
];

foreach ($schemas as $file => $title) {
    echo "\n▸ Applying $title  [$file]\n";
    $sql = file_get_contents(__DIR__ . '/' . $file);
    if (!$sql) { echo "  ✗ FILE NOT FOUND, skipping\n"; continue; }
    try {
        $pdo->exec($sql);
        echo "  ✓ ok\n";
    } catch (Throwable $e) {
        echo "  ✗ " . $e->getMessage() . "\n";
        exit(1);
    }
}

if ($schemaOnly) {
    echo "\n✅ Schema-only install complete.\n";
    exit(0);
}

// ────────────────────────────────────────────────────────────────────────
// Run every phase seeder
// ────────────────────────────────────────────────────────────────────────
$seeders = [
    'seed.php'        => 'Phase 1 — admin user, roles, permissions, settings',
    'seed_phase2.php' => 'Phase 2 — categories, services, packages, sample patients',
    'seed_phase3.php' => 'Phase 3 — therapists, rooms, sample appointments',
    'seed_phase4.php' => 'Phase 4 — sample consultations',
    'seed_phase5.php' => 'Phase 5 — sample expenses + invoices + payments',
    'seed_phase6.php' => 'Phase 6 — link employees, attendance, payslips',
    'seed_phase8.php' => 'Phase 8 — sliders, blog, testimonials, FAQs',
];

foreach ($seeders as $file => $title) {
    echo "\n▸ Seeding: $title  [$file]\n";
    $path = __DIR__ . '/' . $file;
    if (!is_file($path)) { echo "  ✗ FILE NOT FOUND, skipping\n"; continue; }
    $output = shell_exec(escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($path) . ' 2>&1');
    echo $output;
}

// Mail settings (Phase 9 has no separate seeder file)
echo "\n▸ Email settings…\n";
$pdo->exec("INSERT IGNORE INTO settings (`key`, `value`, `group`, `type`) VALUES
    ('mail_host',      '',                              'mail', 'text'),
    ('mail_port',      '587',                           'mail', 'number'),
    ('mail_user',      '',                              'mail', 'text'),
    ('mail_pass',      '',                              'mail', 'text'),
    ('mail_secure',    'tls',                           'mail', 'text'),
    ('mail_from',      'no-reply@nourstouch.com',       'mail', 'text'),
    ('mail_from_name', \"Nour's Touch Clinic\",          'mail', 'text')");
echo "  ✓ ok\n";

echo "\n════════════════════════════════════════════════════════════════════════\n";
echo "✅ Installation complete.\n";
echo "════════════════════════════════════════════════════════════════════════\n";
echo "\nLogin to admin:\n";
echo "   URL:      " . BP_URL . "\n";
echo "   Email:    admin@clinic.com\n";
echo "   Password: Admin@123  (forced change on first login)\n";
echo "\nPublic site:\n";
echo "   URL:      " . APP_URL . "\n";
echo "\nHealth check:\n";
echo "   URL:      " . BP_URL . "admin/system.php\n";
echo "\n";
