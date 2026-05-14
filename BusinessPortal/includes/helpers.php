<?php
// ── General helpers ──────────────────────────────────────────────────────

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function redirect(string $url): void { header("Location: $url"); exit; }

function back(): void {
    $r = $_SERVER['HTTP_REFERER'] ?? BP_URL;
    redirect($r);
}

function flash(string $type, string $msg): void {
    $_SESSION['_flash'][] = ['type' => $type, 'msg' => $msg];
}

function flashes(): array {
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

function old(string $key, $default = ''): string {
    $val = $_SESSION['_old'][$key] ?? $default;
    return is_string($val) ? e($val) : '';
}

function set_old(array $data): void {
    $_SESSION['_old'] = $data;
}

function clear_old(): void {
    unset($_SESSION['_old']);
}

function client_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

function user_agent(): string {
    return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250);
}

function format_money($amount, ?string $currency = null): string {
    $currency = $currency ?? APP_CURRENCY;
    return number_format((float)$amount, 2) . ' ' . $currency;
}

function format_date(?string $dt, string $fmt = 'Y-m-d H:i'): string {
    if (!$dt) return '—';
    try { return (new DateTime($dt))->format($fmt); } catch (Throwable $e) { return $dt; }
}

function setting(string $key, string $default = ''): string {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $rows = db()->query("SELECT `key`,`value` FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
            $cache = $rows ?: [];
        } catch (Throwable $e) { /* table missing on first run */ }
    }
    $v = $cache[$key] ?? '';
    return $v !== '' ? (string)$v : $default;
}

function paginate_query(int $page, int $perPage = 25): array {
    $page    = max(1, $page);
    $perPage = max(1, min(200, $perPage));
    $offset  = ($page - 1) * $perPage;
    return [$perPage, $offset];
}

/** Slugify (Latin + Arabic safe). */
function slugify(string $text): string {
    $text = trim($text);
    if ($text === '') return '';
    $latin = preg_replace('/[^a-zA-Z0-9\-]+/', '-', $text);
    $latin = trim(strtolower($latin), '-');
    if ($latin !== '' && $latin !== '-') return $latin;
    // Arabic fallback: keep letters & digits, replace spaces
    $clean = preg_replace('/[^\p{L}\p{N}]+/u', '-', $text);
    return trim($clean, '-') ?: 'item-' . substr(md5($text), 0, 6);
}

/** Generate next sequential patient code: NT-00001, NT-00002, … */
function next_patient_code(): string {
    $row = db()->query("SELECT code FROM patients ORDER BY id DESC LIMIT 1")->fetch();
    $next = 1;
    if ($row && preg_match('/(\d+)$/', $row['code'], $m)) $next = ((int)$m[1]) + 1;
    return 'NT-' . str_pad((string)$next, 5, '0', STR_PAD_LEFT);
}

/** Safe file upload to /uploads/<subdir>/.  Returns saved filename or null. */
function upload_file(array $file, string $subdir, array $allowed = ['jpg','jpeg','png','pdf','webp','gif'], int $maxBytes = 8388608): ?array {
    if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > $maxBytes) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    // Wildcard '*' allows any extension/mime. Otherwise enforce the lists.
    $allowAny = in_array('*', $allowed, true);
    if (!$allowAny && !in_array($ext, $allowed, true)) return null;

    // Always block dangerous executable types regardless of "allow any"
    $blocked = ['php','phtml','phar','phps','pl','py','rb','sh','bat','cmd','exe','dll','jsp','asp','aspx'];
    if (in_array($ext, $blocked, true)) return null;

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';
    if (!$allowAny) {
        $allowedMimes = ['image/jpeg','image/png','image/gif','image/webp','application/pdf'];
        if (!in_array($mime, $allowedMimes, true)) return null;
    }

    $dir = rtrim(UPLOADS_PATH, '/') . '/' . trim($subdir, '/');
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) return null;

    $name = bin2hex(random_bytes(8)) . '_' . time() . ($ext !== '' ? '.' . $ext : '');
    $target = $dir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $target)) return null;

    return [
        'filename'      => $name,
        'original_name' => $file['name'],
        'mime_type'     => $mime,
        'size'          => (int)$file['size'],
        'relative_path' => trim($subdir, '/') . '/' . $name,
    ];
}

function render_pagination(int $total, int $page, int $perPage, string $base): string {
    $pages = max(1, (int)ceil($total / $perPage));
    if ($pages <= 1) return '';
    $sep  = (strpos($base, '?') !== false) ? '&' : '?';
    $prev = max(1, $page - 1);
    $next = min($pages, $page + 1);
    $from = ($page - 1) * $perPage + 1;
    $to   = min($total, $page * $perPage);
    $prevUrl = e($base . $sep . 'page=' . $prev);
    $nextUrl = e($base . $sep . 'page=' . $next);

    // ── Mobile bar: « Prev · Page X / Y · Next » ──────────────────────────
    $m  = '<div class="pager-mobile d-flex d-md-none">';
    $m .= '<a class="pager-btn' . ($page == 1 ? ' disabled' : '') . '" href="' . $prevUrl . '" aria-label="prev">';
    $m .= '<i class="fa-solid fa-chevron-' . ((($_SESSION['admin_lang'] ?? 'ar') === 'ar') ? 'right' : 'left') . '"></i><span>' . __('prev') . '</span></a>';
    $m .= '<div class="pager-info">';
    $m .= '<div class="pager-page">' . $page . ' <span>/</span> ' . $pages . '</div>';
    $m .= '<div class="pager-range">' . $from . '–' . $to . ' ' . __('of') . ' ' . $total . '</div>';
    $m .= '</div>';
    $m .= '<a class="pager-btn' . ($page == $pages ? ' disabled' : '') . '" href="' . $nextUrl . '" aria-label="next">';
    $m .= '<span>' . __('next') . '</span><i class="fa-solid fa-chevron-' . ((($_SESSION['admin_lang'] ?? 'ar') === 'ar') ? 'left' : 'right') . '"></i></a>';
    $m .= '</div>';

    // ── Desktop numbered pagination ───────────────────────────────────────
    $h  = '<nav class="d-none d-md-block"><ul class="pagination pagination-sm mb-0">';
    $h .= '<li class="page-item' . ($page == 1 ? ' disabled' : '') . '">
            <a class="page-link" href="' . $prevUrl . '">«</a></li>';
    for ($i = 1; $i <= $pages; $i++) {
        if ($i == 1 || $i == $pages || abs($i - $page) <= 2) {
            $h .= '<li class="page-item' . ($i == $page ? ' active' : '') . '">
                    <a class="page-link" href="' . e($base . $sep . 'page=' . $i) . '">' . $i . '</a></li>';
        } elseif (abs($i - $page) == 3) {
            $h .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }
    }
    $h .= '<li class="page-item' . ($page == $pages ? ' disabled' : '') . '">
            <a class="page-link" href="' . $nextUrl . '">»</a></li>';
    $h .= '</ul></nav>';

    return $m . $h;
}

/**
 * Render a kebab-menu (⋯) dropdown of row actions inside a table cell.
 *
 *   render_actions([
 *       ['icon'=>'fa-eye',   'label'=>'view',  'href'=>'?id=5'],
 *       ['icon'=>'fa-pen',   'label'=>'edit',  'href'=>'?action=edit&id=5', 'modal'=>true],
 *       ['icon'=>'fa-trash', 'label'=>'delete','href'=>'?action=delete&id=5&_csrf=…',
 *        'confirm'=>'are_you_sure', 'danger'=>true],
 *   ]);
 *
 *   Each item:
 *      icon     — FA class without "fa-"
 *      label    — translation key (passed through __()) or literal text
 *      href     — URL
 *      modal    — true → adds data-modal (opens in modal)
 *      confirm  — translation key OR text → adds data-confirm
 *      danger   — true → red styling
 *      target   — link target (e.g. _blank)
 *      divider_before — adds <hr> divider above this item
 */
function render_actions(array $items): string {
    $items = array_values(array_filter($items));
    if (!$items) return '';

    $h  = '<div class="dropdown action-menu">';
    $h .= '<button class="action-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="actions">';
    $h .= '<i class="fa-solid fa-ellipsis-vertical"></i>';
    $h .= '</button>';
    $h .= '<ul class="dropdown-menu dropdown-menu-end">';

    foreach ($items as $a) {
        if (!empty($a['divider_before'])) {
            $h .= '<li><hr class="dropdown-divider"></li>';
        }
        $cls = 'dropdown-item';
        if (!empty($a['danger'])) $cls .= ' text-danger';

        $attrs = '';
        if (!empty($a['modal']))   $attrs .= ' data-modal';
        if (!empty($a['confirm'])) {
            $msg = is_string($a['confirm']) ? __($a['confirm']) : __('are_you_sure');
            $attrs .= ' data-confirm="' . e($msg) . '"';
        }
        if (!empty($a['target']))  $attrs .= ' target="' . e($a['target']) . '"';

        $label = $a['label'] ?? '';
        // If label looks like a translation key (snake_case), translate it.
        if ($label && preg_match('/^[a-z0-9_]+$/', $label)) $label = __($label);

        $icon = $a['icon'] ?? 'fa-circle';
        $href = $a['href'] ?? '#';

        $h .= '<li><a class="' . $cls . '" href="' . e($href) . '"' . $attrs . '>';
        $h .= '<i class="fa-solid ' . e($icon) . '"></i>';
        $h .= '<span>' . e($label) . '</span>';
        $h .= '</a></li>';
    }

    $h .= '</ul></div>';
    return $h;
}

/**
 * Render a mobile entity card (used inside .entity-list on small screens).
 *
 * Config keys (all optional):
 *   avatar       — text/initials shown in circle
 *   avatar_img   — image URL (overrides text)
 *   avatar_icon  — FA icon class (e.g. 'fa-file-invoice') — overrides text
 *   avatar_class — extra class(es): amber|indigo|rose|slate|danger|success|square
 *   avatar_href  — wrap avatar in a link
 *   title        — main title text
 *   title_href   — wrap title in a link
 *   title_right  — right-aligned secondary content next to title (e.g. amount)
 *   code         — small monospace badge in meta line
 *   meta         — array of plain strings for the meta line
 *   chips        — array of ['label','icon','class','href','dir','tooltip']
 *   progress     — ['value'=>0-100,'class'=>'danger|warn']
 *   actions      — same array format as render_actions()
 */
function render_entity_card(array $c): string {
    $h = '<div class="entity-card">';

    // ── Avatar ────────────────────────────────────────────────
    if (isset($c['avatar']) || isset($c['avatar_img']) || isset($c['avatar_icon'])) {
        $aClass = 'entity-avatar';
        if (!empty($c['avatar_class'])) $aClass .= ' ' . e($c['avatar_class']);
        $tag = !empty($c['avatar_href']) ? 'a' : 'span';
        $hrefAttr = !empty($c['avatar_href']) ? ' href="' . e($c['avatar_href']) . '"' : '';
        $h .= "<{$tag} class=\"{$aClass}\"{$hrefAttr}>";
        if (!empty($c['avatar_img'])) {
            $h .= '<img src="' . e($c['avatar_img']) . '" alt="">';
        } elseif (!empty($c['avatar_icon'])) {
            $h .= '<i class="fa-solid ' . e($c['avatar_icon']) . '"></i>';
        } else {
            $h .= '<span>' . e($c['avatar']) . '</span>';
        }
        $h .= "</{$tag}>";
    }

    // ── Body ──────────────────────────────────────────────────
    $h .= '<div class="entity-body">';

    if (isset($c['title']) || isset($c['title_right'])) {
        $h .= '<div class="d-flex justify-content-between align-items-start gap-2">';
        if (!empty($c['title'])) {
            $titleTag = !empty($c['title_href']) ? 'a' : 'span';
            $titleHref = !empty($c['title_href']) ? ' href="' . e($c['title_href']) . '"' : '';
            $h .= "<{$titleTag} class=\"entity-title\"{$titleHref}>" . e($c['title']) . "</{$titleTag}>";
        }
        if (!empty($c['title_right'])) {
            $h .= '<span class="entity-amount">' . $c['title_right'] . '</span>';
        }
        $h .= '</div>';
    }

    if (!empty($c['code']) || !empty($c['meta'])) {
        $h .= '<div class="entity-meta">';
        if (!empty($c['code'])) {
            $h .= '<span class="entity-code">' . e($c['code']) . '</span>';
        }
        $parts = $c['meta'] ?? [];
        foreach ($parts as $i => $m) {
            if (!empty($c['code']) || $i > 0) $h .= '<span class="dot-sep">·</span>';
            $h .= '<span>' . e($m) . '</span>';
        }
        $h .= '</div>';
    }

    if (!empty($c['chips'])) {
        $chips = array_values(array_filter($c['chips']));
        if ($chips) $h .= '<div class="entity-chips">';
        foreach ($chips as $ch) {
            $cls = 'entity-chip';
            if (!empty($ch['class'])) $cls .= ' ' . e($ch['class']);
            $tag = !empty($ch['href']) ? 'a' : 'span';
            $attrs = '';
            if (!empty($ch['href'])) $attrs .= ' href="' . e($ch['href']) . '"';
            if (!empty($ch['dir']))  $attrs .= ' dir="' . e($ch['dir']) . '"';
            if (!empty($ch['tooltip'])) $attrs .= ' title="' . e($ch['tooltip']) . '"';
            $h .= "<{$tag} class=\"{$cls}\"{$attrs}>";
            if (!empty($ch['icon'])) $h .= '<i class="fa-solid ' . e($ch['icon']) . '"></i>';
            $h .= e($ch['label'] ?? '');
            $h .= "</{$tag}>";
        }
        if ($chips) $h .= '</div>';
    }

    if (!empty($c['progress'])) {
        $val = max(0, min(100, (int)($c['progress']['value'] ?? 0)));
        $pcls = 'entity-progress';
        if (!empty($c['progress']['class'])) $pcls .= ' ' . e($c['progress']['class']);
        $h .= '<div class="' . $pcls . '"><div style="width:' . $val . '%"></div></div>';
    }

    $h .= '</div>'; // .entity-body

    // ── Actions (kebab menu) ──────────────────────────────────
    if (!empty($c['actions'])) {
        $h .= '<div class="entity-actions">' . render_actions($c['actions']) . '</div>';
    }

    $h .= '</div>'; // .entity-card
    return $h;
}
