<?php
// ── Activity log (immutable audit trail) ─────────────────────────────────

function log_activity(
    string $action,
    string $module = '',
    string $description = '',
    ?string $subjectType = null,
    $subjectId = null,
    array $meta = []
): void {
    try {
        $u = currentUser();
        db()->prepare("
            INSERT INTO activity_logs
                (user_id, user_name, action, module, subject_type, subject_id, description, meta, ip, user_agent)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $u['id']   ?? null,
            $u['name'] ?? null,
            $action,
            $module ?: null,
            $subjectType,
            $subjectId !== null ? (int)$subjectId : null,
            $description ?: null,
            $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            client_ip(),
            user_agent(),
        ]);
    } catch (Throwable $e) {
        error_log('activity_log failed: ' . $e->getMessage());
    }
}
