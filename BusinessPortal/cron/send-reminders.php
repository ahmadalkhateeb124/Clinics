<?php
/**
 * ════════════════════════════════════════════════════════════════════════
 * Appointment reminders cron — emails only
 *
 *  Sends two kinds of reminders:
 *    • appointment_24h  → for appointments starting 23–25 hours from now
 *    • appointment_2h   → for appointments starting 1.5–2.5 hours from now
 *
 *  Idempotent: a UNIQUE key on (subject_type, subject_id, kind) in the
 *  notifications table prevents the same reminder being sent twice.
 *
 *  Schedule via crontab (every 15 minutes is enough):
 *      [slash][star]/15 * * * * /usr/bin/php /path/to/BusinessPortal/cron/send-reminders.php >> /var/log/nourstouch-cron.log 2>&1
 *
 *  Or run manually:
 *      php BusinessPortal/cron/send-reminders.php
 *      php BusinessPortal/cron/send-reminders.php --dry      # preview only
 * ════════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/../config/config.php';

$dryRun = in_array('--dry', $argv ?? [], true);

echo "[" . date('Y-m-d H:i:s') . "] Reminder cron start" . ($dryRun ? ' (DRY-RUN)' : '') . PHP_EOL;

$pdo = db();

// Time windows. We give a generous slack so we don't miss appointments
// when the cron runs every 15 minutes.
$windows = [
    'appointment_24h' => [
        'from' => date('Y-m-d H:i:s', strtotime('+23 hours')),
        'to'   => date('Y-m-d H:i:s', strtotime('+25 hours')),
    ],
    'appointment_2h' => [
        'from' => date('Y-m-d H:i:s', strtotime('+90 minutes')),
        'to'   => date('Y-m-d H:i:s', strtotime('+150 minutes')),
    ],
];

$totalSent = 0;
$totalSkipped = 0;
$totalFailed = 0;

foreach ($windows as $kind => $w) {
    echo "  ▸ $kind  ({$w['from']} → {$w['to']})" . PHP_EOL;

    $stmt = $pdo->prepare("
        SELECT a.id, a.start_at, a.end_at,
               p.first_name, p.last_name, p.email,
               u.name AS therapist_name,
               r.name AS room_name,
               (SELECT GROUP_CONCAT(s.name_ar SEPARATOR ', ')
                  FROM appointment_services asv
                  JOIN services s ON s.id = asv.service_id
                 WHERE asv.appointment_id = a.id) AS svc_names
        FROM appointments a
        JOIN patients p ON p.id = a.patient_id
        LEFT JOIN users u ON u.id = a.therapist_id
        LEFT JOIN rooms r ON r.id = a.room_id
        WHERE a.deleted_at IS NULL
          AND a.status IN ('scheduled','confirmed')
          AND a.start_at BETWEEN ? AND ?
    ");
    $stmt->execute([$w['from'], $w['to']]);
    $appts = $stmt->fetchAll();

    echo "      Found " . count($appts) . " appointment(s)." . PHP_EOL;

    foreach ($appts as $appt) {
        if (empty($appt['email'])) {
            echo "      - Appt #{$appt['id']} skipped: patient has no email." . PHP_EOL;
            $totalSkipped++;
            continue;
        }

        $hours = $kind === 'appointment_24h' ? 24 : 2;
        $subject = "تذكير بموعدك في عيادة " . setting('site_name_ar', APP_NAME_AR) . " — بعد {$hours} " . ($hours === 24 ? "ساعة" : "ساعتين");
        $body    = render_appointment_reminder_email($appt, $kind);

        if ($dryRun) {
            echo "      ⌛ [dry] would send → {$appt['email']}  Appt #{$appt['id']}  ({$appt['start_at']})" . PHP_EOL;
            continue;
        }

        $r = send_email_notification(
            $appt['email'], $subject, $body,
            $kind, 'appointment', (int)$appt['id']
        );

        $marker = $r['ok'] ? '✓' : '✗';
        echo "      $marker  Appt #{$appt['id']} → {$appt['email']}  [{$r['status']}]" . ($r['ok'] ? '' : ' — ' . $r['msg']) . PHP_EOL;

        if ($r['status'] === 'sent')      $totalSent++;
        elseif ($r['status'] === 'skipped') $totalSkipped++;
        else                                $totalFailed++;
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Done. Sent=$totalSent  Skipped=$totalSkipped  Failed=$totalFailed" . PHP_EOL;
