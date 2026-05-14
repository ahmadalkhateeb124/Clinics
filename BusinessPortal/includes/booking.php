<?php
// ════════════════════════════════════════════════════════════════════════
// Phase 3 — Booking eligibility (HARD BLOCK rule from spec)
//
// A patient CANNOT book a new appointment when:
//   1. patients.outstanding_balance > 0, OR
//   2. they have any installment with status='overdue', OR
//   3. they have any installment past due_date and not fully paid.
//
// Override requires permission: appointments.override_block
// ════════════════════════════════════════════════════════════════════════

/**
 * Check whether a patient is eligible to book a new appointment.
 *
 * @return array{eligible: bool, reason: string, outstanding: float, overdue_count: int}
 */
function booking_eligibility(int $patientId, ?int $patientPackageId = null): array
{
    $row = db()->prepare("SELECT outstanding_balance, is_blocked FROM patients WHERE id = ? AND deleted_at IS NULL");
    $row->execute([$patientId]);
    $p = $row->fetch();

    if (!$p) {
        return ['eligible' => false, 'reason' => __('err_patient_not_found') ?: 'Patient not found.', 'outstanding' => 0, 'overdue_count' => 0];
    }

    if ((int)$p['is_blocked'] === 1) {
        return [
            'eligible'      => false,
            'reason'        => __('err_blocked_outstanding') ?: 'Outstanding payment required.',
            'outstanding'   => (float)$p['outstanding_balance'],
            'overdue_count' => 0,
        ];
    }

    // ── Per-package check: 1 paid session ahead required ──────────────
    // After each used session, the patient must have paid at least that fraction
    // of the package price before booking the next one.
    if ($patientPackageId) {
        $pp = db()->prepare("SELECT price, total_sessions, used_sessions, paid_amount, status
                             FROM patient_packages
                             WHERE id = ? AND patient_id = ? AND deleted_at IS NULL");
        $pp->execute([$patientPackageId, $patientId]);
        $pp = $pp->fetch();
        if ($pp && $pp['status'] === 'active' && (int)$pp['total_sessions'] > 0) {
            $sessionPrice = (float)$pp['price'] / (int)$pp['total_sessions'];
            // The next session about-to-be-booked is (used_sessions + 1).
            // Required minimum paid before booking it = used_sessions * sessionPrice
            // (i.e. you may book session #1 without paying, but #2 onward needs prior session paid).
            $minPaid = (int)$pp['used_sessions'] * $sessionPrice;
            $paid    = (float)$pp['paid_amount'];
            if ($paid + 0.005 < $minPaid) {
                $shortBy = $minPaid - $paid;
                return [
                    'eligible'      => false,
                    'reason'        => (__('err_package_payment_required') ?: 'Record a payment for the package before booking the next session.') . ' (' . format_money($shortBy) . ')',
                    'outstanding'   => (float)$p['outstanding_balance'],
                    'overdue_count' => 0,
                ];
            }
        }
    }

    $outstanding = (float)$p['outstanding_balance'];

    $stmt = db()->prepare("
        SELECT COUNT(*) FROM installments
        WHERE patient_id = ?
          AND deleted_at IS NULL
          AND status <> 'paid' AND status <> 'waived'
          AND (status = 'overdue' OR due_date < CURDATE())
    ");
    $stmt->execute([$patientId]);
    $overdue = (int)$stmt->fetchColumn();

    if ($outstanding > 0 || $overdue > 0) {
        return [
            'eligible'      => false,
            'reason'        => __('err_outstanding_required') ?: 'Outstanding payment required.',
            'outstanding'   => $outstanding,
            'overdue_count' => $overdue,
        ];
    }

    return ['eligible' => true, 'reason' => '', 'outstanding' => 0.0, 'overdue_count' => 0];
}

/**
 * Detect appointment time conflict for a therapist or a room.
 * Returns the conflicting appointment row, or null if free.
 */
function appointment_conflict(string $startAt, string $endAt, ?int $therapistId, ?int $roomId, ?int $excludeId = null): ?array
{
    $sql = "SELECT id, patient_id, start_at, end_at
            FROM appointments
            WHERE deleted_at IS NULL
              AND status IN ('scheduled','confirmed')
              AND start_at < ? AND end_at > ?
              AND (";
    $params = [$endAt, $startAt];
    $or = [];
    if ($therapistId) { $or[] = "therapist_id = ?"; $params[] = $therapistId; }
    if ($roomId)      { $or[] = "room_id = ?";      $params[] = $roomId; }
    if (!$or) return null;
    $sql .= implode(' OR ', $or) . ")";
    if ($excludeId) { $sql .= " AND id <> ?"; $params[] = $excludeId; }
    $sql .= " LIMIT 1";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Compute total duration & price from a list of service IDs. */
function services_summary(array $serviceIds): array
{
    $serviceIds = array_filter(array_map('intval', $serviceIds));
    if (!$serviceIds) return ['duration' => 0, 'price' => 0.0, 'rows' => []];
    $in = implode(',', array_fill(0, count($serviceIds), '?'));
    $stmt = db()->prepare("SELECT id,name_ar,duration_minutes,price,commission_pct
                           FROM services WHERE id IN ($in) AND deleted_at IS NULL");
    $stmt->execute($serviceIds);
    $rows = $stmt->fetchAll();
    $duration = 0; $price = 0.0;
    foreach ($rows as $r) {
        $duration += (int)$r['duration_minutes'];
        $price    += (float)$r['price'];
    }
    return ['duration' => $duration, 'price' => $price, 'rows' => $rows];
}
