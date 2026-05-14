<?php
/**
 * ════════════════════════════════════════════════════════════════════════
 * Daily DB backup script
 *
 *   Dumps the database to /BusinessPortal/backups/ as a gzipped SQL file,
 *   then keeps the last N backups (configurable) and deletes the rest.
 *
 *   Schedule via crontab (daily at 03:00):
 *       0 3 * * * /usr/bin/php /path/to/BusinessPortal/cron/backup-db.php >> /var/log/nourstouch-backup.log 2>&1
 * ════════════════════════════════════════════════════════════════════════
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once __DIR__ . '/../config/config.php';

$backupDir = BP_PATH . '/backups';
@mkdir($backupDir, 0775, true);

// DB credentials
$host = 'localhost'; $user = 'root'; $pass = ''; $name = 'nourstouch'; $port = 3306;
$credFile = BP_CONFIG . '/database.credentials.php';
if (is_file($credFile)) {
    $cfg = require $credFile;
    $host = $cfg['host'] ?? $host;
    $user = $cfg['user'] ?? $user;
    $pass = $cfg['pass'] ?? $pass;
    $name = $cfg['name'] ?? $name;
    $port = $cfg['port'] ?? $port;
}

$timestamp = date('Y-m-d_His');
$file      = "$backupDir/nourstouch_$timestamp.sql.gz";

// Detect mysqldump path
$mysqldump = '/Applications/XAMPP/xamppfiles/bin/mysqldump';
if (!is_file($mysqldump)) {
    $mysqldump = trim(shell_exec('which mysqldump') ?? '') ?: 'mysqldump';
}

// Build the command (use a temp config file so the password isn't in argv)
$confFile = $backupDir . '/.dump.cnf';
file_put_contents($confFile, "[client]\nuser=$user\npassword=$pass\nhost=$host\nport=$port\n");
chmod($confFile, 0600);

$cmd = escapeshellcmd($mysqldump)
     . ' --defaults-extra-file=' . escapeshellarg($confFile)
     . ' --single-transaction --quick --no-tablespaces --routines --events '
     . escapeshellarg($name)
     . ' | gzip > ' . escapeshellarg($file);

echo "[" . date('Y-m-d H:i:s') . "] Starting backup → $file\n";
$rc = 0;
passthru($cmd, $rc);
unlink($confFile);

if ($rc !== 0 || !is_file($file) || filesize($file) < 1024) {
    fwrite(STDERR, "✗ Backup failed (exit=$rc, size=" . (is_file($file) ? filesize($file) : 0) . ")\n");
    @unlink($file);
    exit(1);
}

$size = number_format(filesize($file) / 1024, 1);
echo "✓ Backup complete: " . basename($file) . " ($size KB)\n";

// ────────────────────────────────────────────────────────────────────────
// Retention: keep last 14 backups
// ────────────────────────────────────────────────────────────────────────
$keep = 14;
$files = glob("$backupDir/nourstouch_*.sql.gz") ?: [];
rsort($files);                       // newest first
$old = array_slice($files, $keep);
foreach ($old as $f) {
    @unlink($f);
    echo "  ▸ Deleted old backup: " . basename($f) . "\n";
}
echo "[" . date('Y-m-d H:i:s') . "] Done. " . min(count($files), $keep) . " backup(s) retained.\n";
