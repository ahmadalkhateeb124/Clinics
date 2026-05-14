<?php
// Live availability endpoint for the public booking form
require_once __DIR__ . '/conn.php';
header('Content-Type: application/json; charset=utf-8');

$start = trim($_GET['start'] ?? '');
$svcId = (int)($_GET['service_id'] ?? 0);
$thId  = (int)($_GET['therapist_id'] ?? 0);

$startTs = $start ? strtotime($start) : false;
if (!$startTs) { echo json_encode(['available'=>false,'reason'=>'وقت غير صالح']); exit; }
if ($startTs < time()) { echo json_encode(['available'=>false,'reason'=>'الوقت في الماضي']); exit; }

// Working hours check
$wFrom = site_setting('working_hours_from','09:00');
$wTo   = site_setting('working_hours_to','21:00');
$timeOnly = (int)date('Hi', $startTs);
$wFromI = (int)str_replace(':','', $wFrom);
$wToI   = (int)str_replace(':','', $wTo);
if ($timeOnly < $wFromI || $timeOnly >= $wToI) {
    echo json_encode(['available'=>false,'reason'=>"خارج ساعات العمل ($wFrom – $wTo)"]);
    exit;
}

// Duration default
$duration = 60;
if ($svcId && $pdo instanceof PDO) {
    try {
        $st = $pdo->prepare("SELECT duration_minutes FROM services WHERE id=? AND deleted_at IS NULL AND is_active=1");
        $st->execute([$svcId]);
        if ($r = $st->fetch()) $duration = (int)$r['duration_minutes'];
    } catch (Throwable $e) {}
}

$endTs    = $startTs + $duration * 60;
$startSql = date('Y-m-d H:i:s', $startTs);
$endSql   = date('Y-m-d H:i:s', $endTs);

// Therapist conflict check (if specified)
if ($thId && $pdo instanceof PDO) {
    try {
        $st = $pdo->prepare("
            SELECT id FROM appointments
            WHERE deleted_at IS NULL AND status IN ('scheduled','confirmed')
              AND therapist_id = ?
              AND start_at < ? AND end_at > ?
            LIMIT 1
        ");
        $st->execute([$thId, $endSql, $startSql]);
        if ($st->fetch()) {
            echo json_encode(['available'=>false,'reason'=>'المعالج مشغول في هذا الوقت']);
            exit;
        }
    } catch (Throwable $e) {}
}

// Pending requests near same time → soft warning (not blocking)
$nearbyPending = 0;
if ($pdo instanceof PDO) {
    try {
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM booking_requests
            WHERE status IN ('pending','contacted')
              AND requested_at BETWEEN DATE_SUB(?, INTERVAL 30 MINUTE) AND DATE_ADD(?, INTERVAL 30 MINUTE)
        ");
        $st->execute([$startSql, $startSql]);
        $nearbyPending = (int)$st->fetchColumn();
    } catch (Throwable $e) {}
}

$reason = 'الوقت متاح للحجز';
if ($nearbyPending > 0) $reason .= " ($nearbyPending طلب حجز قريب)";

echo json_encode([
    'available' => true,
    'reason'    => $reason,
    'duration'  => $duration,
]);
