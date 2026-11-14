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

/*
 * Bremse gegen Passwort-Durchprobieren (seit 0.19.2). Fehlversuche zählen in rate_limits wie die Rückmeldungen,
 * mit gehashten Schlüsseln: pro IP-Adresse (WB_LOGIN_IP_MAX in WB_LOGIN_IP_MINUTES) und für alle zusammen
 * (WB_LOGIN_GLOBAL_MAX in 60 Minuten, gegen verteilte Angriffe). Ist eine Grenze erreicht, wird das Passwort gar
 * nicht erst geprüft. Damit ein Angriff Raphael nicht aussperrt, gilt die globale Grenze nicht für Browser mit dem
 * Cookie wb_known, das eine erfolgreiche Anmeldung setzt (HMAC mit dem Passwort-Hash, 180 Tage; ein neues
 * Passwort macht alle diese Cookies ungültig).
 */
const WB_LOGIN_IP_MAX = 10;
const WB_LOGIN_IP_MINUTES = 15;
const WB_LOGIN_GLOBAL_MAX = 100;
const WB_LOGIN_KNOWN_DAYS = 180;

function wb_login_key(string $what): string {
    // Gesalzen mit dem Passwort-Hash aus der Datenbank (geheim und in allen PHP-Prozessen gleich; app_secret aus
    // config.php kann kurz nach dem Selbsteintrag je nach Prozess fehlen). Ein neues Passwort setzt die Zähler zurück.
    return substr(hash('sha256', 'login|' . $what . '|' . (string)setting_get('admin_password_hash')), 0, 32);
}

function wb_login_count(string $key, int $minutes): int {
    $minute = intdiv(time(), 60);
    $row = db_row('SELECT COALESCE(SUM(cnt), 0) AS n FROM rate_limits WHERE author_key = ? AND minute > ?', [$key, $minute - $minutes]);
    return (int)($row['n'] ?? 0);
}

/** null, wenn eine Anmeldung versucht werden darf, sonst 'ip' oder 'global'. */
function wb_login_blocked(string $ip): ?string {
    if ($ip !== '' && wb_login_count(wb_login_key('ip|' . $ip), WB_LOGIN_IP_MINUTES) >= WB_LOGIN_IP_MAX) return 'ip';
    if (!wb_login_known_device() && wb_login_count(wb_login_key('all'), 60) >= WB_LOGIN_GLOBAL_MAX) return 'global';
    return null;
}

function wb_login_record_failure(string $ip): void {
    $minute = intdiv(time(), 60);
    $keys = [wb_login_key('all')];
    if ($ip !== '') $keys[] = wb_login_key('ip|' . $ip);
    foreach ($keys as $k) {
        db_exec('INSERT INTO rate_limits (author_key, minute, cnt) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE cnt = cnt + 1', [$k, $minute]);
    }
    if (random_int(0, 20) === 0) db_exec('DELETE FROM rate_limits WHERE minute < ?', [$minute - 61]);
}

function wb_login_known_sig(int $until): string {
    return hash_hmac('sha256', 'known|' . $until, 'wb_known|' . (string)setting_get('admin_password_hash'));
}

function wb_login_known_device(): bool {
    $c = (string)($_COOKIE['wb_known'] ?? '');
    if (!preg_match('/^(\d{10})\.([0-9a-f]{64})$/', $c, $m)) return false;
    return (int)$m[1] > time() && hash_equals(wb_login_known_sig((int)$m[1]), $m[2]);
}

function wb_login_mark_known_device(): void {
    $until = time() + WB_LOGIN_KNOWN_DAYS * 86400;
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    setcookie('wb_known', $until . '.' . wb_login_known_sig($until), [
        'expires' => $until, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax',
    ]);
}

function wb_client_ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
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

/**
 * Schreibende Besitzer-Aufrufe der Board-Seite laufen über die Admin-Sitzung und müssen den CSRF-Token
 * im Header X-CSRF mitschicken. Aufrufe mit gültigem API-Token brauchen das nicht.
 */
function wb_check_csrf_header(): void {
    $token = wb_bearer_token();
    if ($token !== null) {
        $stored = (string)setting_get('api_token');
        if ($stored !== '' && hash_equals($stored, $token)) return;
    }
    $h = (string)($_SERVER['HTTP_X_CSRF'] ?? '');
    if ($h === '' || !hash_equals(wb_csrf_token(), $h)) wb_error('Ungültige Anfrage (CSRF).', 403);
}

function wb_require_can(string $ability, ?array $board = null): void {
    if (!wb_can($ability, $board)) {
        wb_error('Nicht berechtigt.', wb_current_user() === null ? 401 : 403);
    }
}
