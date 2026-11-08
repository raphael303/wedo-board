<?php
declare(strict_types=1);

function wb_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('wb_admin');
    session_start();
}

function wb_is_admin_session(): bool {
    wb_session_start();
    return !empty($_SESSION['admin']) && ($_SESSION['admin_hash'] ?? '') === (string)setting_get('admin_password_hash');
}

function wb_admin_login(string $password): bool {
    $hash = setting_get('admin_password_hash');
    if ($hash && password_verify($password, $hash)) {
        wb_session_start();
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['admin_hash'] = $hash;
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        return true;
    }
    return false;
}

function wb_admin_logout(): void {
    wb_session_start();
    $_SESSION = [];
    session_destroy();
}

function wb_csrf_token(): string {
    wb_session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function wb_bearer_token(): ?string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(.+)$/i', trim($h), $m)) {
        return trim($m[1]);
    }
    if (!empty($_GET['token'])) {
        return (string)$_GET['token'];
    }
    return null;
}

/**
 * Besitzerrechte hat, wer den API-Token schickt oder als Admin angemeldet ist.
 */
function wb_is_owner(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    $token = wb_bearer_token();
    if ($token !== null) {
        $stored = (string)setting_get('api_token');
        $cached = $stored !== '' && hash_equals($stored, $token);
        if ($cached) return true;
    }
    $cached = wb_is_admin_session();
    return $cached;
}

/**
 * Der angemeldete Benutzer: bei gültiger Admin-Sitzung oder gültigem Token der Benutzer 1 (Super-Admin),
 * sonst null. Vorbereitung für mehrere Benutzer mit Rollen; heute gibt es nur diesen einen.
 */
function wb_current_user(): ?array {
    static $cached = false;
    if ($cached !== false) return $cached;
    $cached = null;
    if (wb_is_owner()) {
        try {
            $u = db_row('SELECT * FROM users WHERE id = 1 AND disabled_at IS NULL');
        } catch (PDOException $e) {
            // Die Tabelle users entsteht erst mit Migration 3, die nach dieser Prüfung läuft.
            $u = null;
        }
        if (!$u) {
            $u = ['id' => 1, 'role' => 'superadmin', 'display_name' => 'Lehrperson', 'email' => null, 'created_at' => null, 'disabled_at' => null];
        }
        $u['id'] = (int)$u['id'];
        $cached = $u;
    }
    return $cached;
}

/**
 * Zentrale Rechteprüfung. Fähigkeiten: board.manage, bundle.manage, ai.use, guest.block, undo, settings.system.
 * Heute darf der Super-Admin alles und sonst niemand etwas; später entscheiden Rolle und Board-Besitz.
 */
function wb_can(string $ability, ?array $board = null): bool {
    $u = wb_current_user();
    if ($u === null) return false;
    if ($u['role'] === 'superadmin') return true;
    // Vorgesehen: Lehrpersonen auf eigenen Boards (owner_user_id), Admins auf Boards ihrer Organisation.
    return false;
}

function wb_require_owner(): void {
    if (!wb_can('board.manage')) {
        wb_error('Nicht berechtigt.', 401);
    }
}

function wb_require_can(string $ability, ?array $board = null): void {
    if (!wb_can($ability, $board)) {
        wb_error('Nicht berechtigt.', wb_current_user() === null ? 401 : 403);
    }
}
