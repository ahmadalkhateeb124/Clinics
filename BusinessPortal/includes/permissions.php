<?php
// ── Roles & Permissions (Spatie-like, simple SQL-backed) ─────────────────

function userRoles(int $userId): array {
    static $cache = [];
    if (isset($cache[$userId])) return $cache[$userId];
    $stmt = db()->prepare("
        SELECT r.id, r.name, r.slug
        FROM user_roles ur JOIN roles r ON r.id = ur.role_id
        WHERE ur.user_id = ?
    ");
    $stmt->execute([$userId]);
    return $cache[$userId] = $stmt->fetchAll();
}

function userPermissions(int $userId): array {
    static $cache = [];
    if (isset($cache[$userId])) return $cache[$userId];
    $stmt = db()->prepare("
        SELECT DISTINCT p.slug
        FROM user_roles ur
        JOIN role_permissions rp ON rp.role_id = ur.role_id
        JOIN permissions p ON p.id = rp.permission_id
        WHERE ur.user_id = ?
    ");
    $stmt->execute([$userId]);
    return $cache[$userId] = array_column($stmt->fetchAll(), 'slug');
}

function hasRole(string $slug): bool {
    $u = currentUser();
    if (!$u) return false;
    foreach (userRoles((int)$u['id']) as $r) {
        if ($r['slug'] === $slug) return true;
    }
    return false;
}

function isSuperAdmin(): bool { return hasRole('super-admin'); }

function can(string $permissionSlug): bool {
    $u = currentUser();
    if (!$u) return false;
    if (isSuperAdmin()) return true;
    return in_array($permissionSlug, userPermissions((int)$u['id']), true);
}

function require_can(string $permissionSlug): void {
    if (can($permissionSlug)) return;

    // Friendly message that names the missing capability when possible
    [$module, $action] = array_pad(explode('.', $permissionSlug, 2), 2, '');
    $actionLabel = $action ? (__('perm_action_'.$action) ?: $action) : '';
    $moduleLabel = $module ? (__($module) ?: $module) : '';
    $msg = $actionLabel && $moduleLabel
        ? sprintf(__('no_permission_for_action'), $actionLabel, $moduleLabel)
        : __('access_denied');

    $isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

    // POST (delete/edit submit): bounce back to referrer with a flash toast
    if ($isPost) {
        if ($isAjax) {
            // Modal-submitted form: return 403 so admin.js shows the toast
            // and keeps the modal open instead of treating it as success.
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><meta charset="utf-8"><div class="nt-toast-stack"><div class="nt-toast nt-toast-error"><div class="nt-toast-body">'.e($msg).'</div></div></div>';
            exit;
        }
        flash('error', $msg);
        $back = $_SERVER['HTTP_REFERER'] ?? (BP_URL . 'admin/');
        header('Location: ' . $back);
        exit;
    }

    // GET (direct URL): show styled denied page using the app shell
    http_response_code(403);
    global $PageTitle;
    $PageTitle = __('access_denied');
    if (!defined('BP_DENIED_RENDERING')) {
        define('BP_DENIED_RENDERING', true);
        @include BP_PARTIALS . '/header.php';
        echo '<div class="page-wrap"><div class="card shadow-sm" style="max-width:560px;margin:3rem auto"><div class="card-body text-center p-4">
            <div style="width:72px;height:72px;border-radius:50%;background:#fee2e2;color:#dc2626;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.8rem">
                <i class="fa-solid fa-lock"></i>
            </div>
            <h5 class="mb-2">'.__('access_denied').'</h5>
            <p class="text-muted mb-3">'.e($msg).'</p>
            <p class="small text-muted">'.__('required_permission').': <code>'.e($permissionSlug).'</code></p>
            <div class="d-flex gap-2 justify-content-center mt-3">
                <a class="btn btn-light btn-sm" href="javascript:history.back()"><i class="fa-solid fa-arrow-left me-1"></i>'.__('back').'</a>
                <a class="btn btn-teal btn-sm" href="'.BP_URL.'admin/"><i class="fa-solid fa-house me-1"></i>'.__('dashboard').'</a>
            </div>
        </div></div></div>';
        @include BP_PARTIALS . '/footer.php';
    }
    exit;
}
