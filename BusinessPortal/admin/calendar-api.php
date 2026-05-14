<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_can('appointments.view');
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

$colors = [
    'scheduled' => '#0dcaf0',
    'confirmed' => '#0d6efd',
    'completed' => '#198754',
    'no_show'   => '#ffc107',
    'cancelled' => '#dc3545',
];

if ($action === 'list') {
    $from = $_GET['from'] ?? date('Y-m-01');
    $to   = $_GET['to']   ?? date('Y-m-t');

    $stmt = db()->prepare("
        SELECT a.id, a.start_at, a.end_at, a.status,
               p.code AS patient_code, p.first_name, p.last_name,
               u.name AS therapist_name, r.name AS room_name
        FROM appointments a
        JOIN patients p ON p.id = a.patient_id
        LEFT JOIN users u ON u.id = a.therapist_id
        LEFT JOIN rooms r ON r.id = a.room_id
        WHERE a.deleted_at IS NULL
          AND a.start_at < ? AND a.end_at > ?
        ORDER BY a.start_at
    ");
    $stmt->execute([$to, $from]);

    $events = [];
    foreach ($stmt as $a) {
        $title = '[' . $a['patient_code'] . '] ' . $a['first_name'] . ' ' . $a['last_name'];
        if ($a['therapist_name']) $title .= ' · ' . $a['therapist_name'];
        $events[] = [
            'id'          => (int)$a['id'],
            'title'       => $title,
            'start'       => str_replace(' ', 'T', $a['start_at']),
            'end'         => str_replace(' ', 'T', $a['end_at']),
            'backgroundColor' => $colors[$a['status']] ?? '#6c757d',
            'borderColor'     => $colors[$a['status']] ?? '#6c757d',
            'editable'    => in_array($a['status'], ['scheduled','confirmed'], true),
            'extendedProps' => [
                'status'    => $a['status'],
                'room'      => $a['room_name'],
                'therapist' => $a['therapist_name'],
            ],
        ];
    }
    echo json_encode($events, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'reschedule') {
    csrf_check();
    require_can('appointments.edit');

    $id    = (int)($_POST['id'] ?? 0);
    $start = $_POST['start'] ?? '';
    $end   = $_POST['end']   ?? '';

    $startTs = strtotime($start);
    $endTs   = strtotime($end);
    if (!$id || !$startTs || !$endTs || $endTs <= $startTs) {
        echo json_encode(['ok'=>false,'msg'=>'Invalid time.']);
        exit;
    }

    $a = db()->prepare("SELECT * FROM appointments WHERE id=? AND deleted_at IS NULL");
    $a->execute([$id]); $a = $a->fetch();
    if (!$a) { echo json_encode(['ok'=>false,'msg'=>'Not found.']); exit; }
    if (!in_array($a['status'], ['scheduled','confirmed'], true)) {
        echo json_encode(['ok'=>false,'msg'=>'Cannot reschedule a '.$a['status'].' appointment.']); exit;
    }

    $startSql = date('Y-m-d H:i:s', $startTs);
    $endSql   = date('Y-m-d H:i:s', $endTs);

    $conflict = appointment_conflict($startSql, $endSql, $a['therapist_id'], $a['room_id'], $id);
    if ($conflict) {
        echo json_encode(['ok'=>false,'msg'=>'Time conflict with appointment #' . (int)$conflict['id']]);
        exit;
    }

    $duration = (int)round(($endTs - $startTs) / 60);
    db()->prepare("
        UPDATE appointments
        SET start_at = ?, end_at = ?, duration_minutes = ?, updated_by = ?, updated_at = NOW()
        WHERE id = ?
    ")->execute([$startSql, $endSql, $duration, $_SESSION['user_id'], $id]);

    log_activity('rescheduled','appointments',"Rescheduled appointment #$id to $startSql",'appointment',$id);
    echo json_encode(['ok'=>true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok'=>false,'msg'=>'Unknown action.']);
