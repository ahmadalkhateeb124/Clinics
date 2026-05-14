<?php
// ── Authentication helpers ───────────────────────────────────────────────

const REMEMBER_COOKIE = 'bp_remember';
const REMEMBER_TTL    = 60 * 60 * 24 * 30; // 30 days

function isLoggedIn(): bool {
    if (!empty($_SESSION['user_id'])) return true;
    return tryRememberLogin();
}

function rememberCookieParams(): array {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    return [
        'expires'  => time() + REMEMBER_TTL,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function issueRememberCookie(int $userId): void {
    $raw  = bin2hex(random_bytes(32));
    $hash = hash('sha256', $raw);
    db()->prepare("UPDATE users SET remember_token = ? WHERE id = ?")->execute([$hash, $userId]);
    setcookie(REMEMBER_COOKIE, $userId . ':' . $raw, rememberCookieParams());
}

function clearRememberCookie(?int $userId = null): void {
    if ($userId) {
        db()->prepare("UPDATE users SET remember_token = NULL WHERE id = ?")->execute([$userId]);
    }
    if (isset($_COOKIE[REMEMBER_COOKIE])) {
        $p = rememberCookieParams();
        $p['expires'] = time() - 42000;
        setcookie(REMEMBER_COOKIE, '', $p);
        unset($_COOKIE[REMEMBER_COOKIE]);
    }
}

function tryRememberLogin(): bool {
    if (!empty($_SESSION['user_id'])) return true;
    if (empty($_COOKIE[REMEMBER_COOKIE])) return false;

    $parts = explode(':', $_COOKIE[REMEMBER_COOKIE], 2);
    if (count($parts) !== 2) { clearRememberCookie(); return false; }
    [$uid, $raw] = $parts;
    $uid = (int)$uid;
    if ($uid <= 0 || !ctype_xdigit($raw) || strlen($raw) !== 64) { clearRememberCookie(); return false; }

    $stmt = db()->prepare("SELECT * FROM users WHERE id = ? AND deleted_at IS NULL AND status='active' LIMIT 1");
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
    if (!$user || empty($user['remember_token'])) { clearRememberCookie(); return false; }

    if (!hash_equals($user['remember_token'], hash('sha256', $raw))) {
        clearRememberCookie();
        return false;
    }

    $_SESSION['user_id']    = (int)$user['id'];
    $_SESSION['user_name']  = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    session_regenerate_id(true);

    // Rotate token on every auto-login
    issueRememberCookie((int)$user['id']);
    return true;
}

function currentUser(): ?array {
    static $u = false;
    if ($u !== false) return $u;
    if (!isLoggedIn()) { $u = null; return null; }
    $stmt = db()->prepare("SELECT * FROM users WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$_SESSION['user_id']]);
    $u = $stmt->fetch() ?: null;
    return $u;
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        $_SESSION['_intended'] = $_SERVER['REQUEST_URI'] ?? '';
        redirect(BP_URL . 'auth/login.php');
    }
    $u = currentUser();
    if (!$u || $u['status'] !== 'active') {
        session_destroy();
        redirect(BP_URL . 'auth/login.php');
    }
    if (!empty($u['must_change_pw'])
        && strpos($_SERVER['REQUEST_URI'] ?? '', 'settings.php') === false
        && strpos($_SERVER['REQUEST_URI'] ?? '', 'logout') === false) {
        redirect(BP_URL . 'admin/settings.php#account');
    }
}

/** Throttle login attempts: 5 failures per email+ip in 15 minutes. */
function loginThrottled(string $email): bool {
    $stmt = db()->prepare("
        SELECT COUNT(*) FROM login_attempts
        WHERE email = ? AND ip = ? AND success = 0
          AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
    ");
    $stmt->execute([$email, client_ip()]);
    return ((int)$stmt->fetchColumn()) >= 5;
}

function logLoginAttempt(string $email, bool $success): void {
    db()->prepare("INSERT INTO login_attempts (email, ip, success) VALUES (?,?,?)")
        ->execute([$email, client_ip(), $success ? 1 : 0]);
}

function attemptLogin(string $email, string $password, bool $remember = false): array {
    if (loginThrottled($email)) {
        return ['ok' => false, 'msg' => 'Too many failed attempts. Try again in 15 minutes.'];
    }
    $stmt = db()->prepare("SELECT * FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        logLoginAttempt($email, false);
        return ['ok' => false, 'msg' => 'Invalid credentials.'];
    }
    if ($user['status'] !== 'active') {
        logLoginAttempt($email, false);
        return ['ok' => false, 'msg' => 'Account is not active.'];
    }

    // Re-hash if needed
    if (password_needs_rehash($user['password'], PASSWORD_BCRYPT)) {
        $h = password_hash($password, PASSWORD_BCRYPT);
        db()->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$h, $user['id']]);
    }

    $_SESSION['user_id']    = (int)$user['id'];
    $_SESSION['user_name']  = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    session_regenerate_id(true);

    db()->prepare("UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?")
        ->execute([client_ip(), $user['id']]);

    if ($remember) {
        issueRememberCookie((int)$user['id']);
    } else {
        clearRememberCookie((int)$user['id']);
    }

    logLoginAttempt($email, true);
    return ['ok' => true, 'user' => $user];
}

function logout(): void {
    $uid = $_SESSION['user_id'] ?? null;
    clearRememberCookie($uid ? (int)$uid : null);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
