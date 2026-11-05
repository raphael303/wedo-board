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

function wb_require_owner(): void {
    if (!wb_is_owner()) {
        wb_error('Nicht berechtigt.', 401);
    }
}
