<?php
declare(strict_types=1);

/*
 * Anmeldung im Verwaltungsteil (seit 0.22.0, Nutzerverwaltung U1a).
 *
 * Ein Aufruf weist sich auf eine von zwei Arten aus:
 *   - mit einer Sitzung aus der Tabelle sessions (Cookie __Host-tafula_s, nur als Hash gespeichert),
 *   - mit einem Token (seit 0.23.0): OAuth-Verbindung eines Connectors, persönliches Token oder der frühere api_token.
 * wb_auth() liefert den Zusammenhang, wb_current_user() das Konto. Rechte entscheidet lib/rights.php.
 */

const WB_SESSION_COOKIE = 'tafula_s';
const WB_DEVICE_COOKIE = 'tafula_d';

function wb_is_https(): bool {
    return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
}

/** Cookies tragen über HTTPS das Präfix __Host- (kein Domain-Attribut, Pfad /, Secure). */
function wb_cookie_name(string $base): string {
    return wb_is_https() ? '__Host-' . $base : $base;
}

function wb_cookie_get(string $base): string {
    return (string)($_COOKIE[wb_cookie_name($base)] ?? '');
}

function wb_cookie_set(string $base, string $value, int $expires): void {
    if (PHP_SAPI === 'cli' || headers_sent()) return;
    setcookie(wb_cookie_name($base), $value, ['expires' => $expires, 'path' => '/', 'secure' => wb_is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    if ($value === '') unset($_COOKIE[wb_cookie_name($base)]); else $_COOKIE[wb_cookie_name($base)] = $value;
}

function wb_client_ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function wb_user_agent(): string {
    return mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

/** Einstellung als Zahl mit Vorgabe (Werte von Sitzungen und Bremsen sind im Betrieb änderbar). */
function wb_setting_int(string $key, int $default): int {
    $v = setting_get($key);
    return $v !== null && preg_match('/^\d+$/', $v) ? (int)$v : $default;
}

/* ---------- Sitzungen ---------- */

function wb_session_limits(bool $staff): array {
    return $staff
        ? ['idle' => wb_setting_int('session_staff_idle_minutes', 12 * 60) * 60, 'max' => wb_setting_int('session_staff_max_days', 14) * 86400]
        : ['idle' => wb_setting_int('session_idle_days', 14) * 86400, 'max' => wb_setting_int('session_max_days', 90) * 86400];
}

/** Legt eine Sitzung an und setzt das Cookie. $mfa: none, mail oder totp; $kind: normal oder test. */
function wb_session_create(int $userId, string $mfa, string $kind = 'normal'): array {
    $token = wb_random_token(32);
    $now = time();
    $limits = wb_session_limits(user_system_roles($userId) !== []);
    if ($kind === 'test') $limits['max'] = min($limits['max'], 12 * 3600);
    $id = db_insert('INSERT INTO sessions (token_hash, user_id, csrf, kind, mfa_level, reauth_at, ip, user_agent, created_at, last_seen_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [wb_token_hash($token), $userId, bin2hex(random_bytes(16)), $kind, $mfa, wb_utc($now), wb_client_ip(), wb_user_agent(), wb_utc($now), wb_utc($now), wb_utc($now + $limits['max'])]);
    wb_cookie_set(WB_SESSION_COOKIE, $token, 0);
    db_exec('UPDATE users SET last_login_at = ?, last_active_at = ? WHERE id = ?', [wb_utc($now), wb_utc($now), $userId]);
    $GLOBALS['wb_auth_cache'] = null;
    return db_row('SELECT * FROM sessions WHERE id = ?', [$id]);
}

/** Die gültige Sitzung dieses Aufrufs samt Konto oder null. */
function wb_session(): ?array {
    if (array_key_exists('wb_session_cache', $GLOBALS)) return $GLOBALS['wb_session_cache'];
    $GLOBALS['wb_session_cache'] = null;
    $token = wb_cookie_get(WB_SESSION_COOKIE);
    if ($token === '' || strlen($token) > 100) return null;
    try {
        $s = db_row('SELECT * FROM sessions WHERE token_hash = ? AND revoked_at IS NULL', [wb_token_hash($token)]);
    } catch (PDOException $e) {
        return null; // Tabelle fehlt vor Migration 15
    }
    if (!$s) return null;
    $user = user_by_id((int)$s['user_id']);
    $now = time();
    $limits = wb_session_limits($user !== null && user_system_roles((int)$user['id']) !== []);
    if (!$user || !user_is_active($user) || wb_ts($s['expires_at']) < $now || wb_ts($s['last_seen_at']) + $limits['idle'] < $now) {
        db_exec('UPDATE sessions SET revoked_at = ? WHERE id = ?', [wb_now(), (int)$s['id']]);
        return null;
    }
    if (wb_ts($s['last_seen_at']) < $now - 60) {
        db_exec('UPDATE sessions SET last_seen_at = ? WHERE id = ?', [wb_utc($now), (int)$s['id']]);
        db_exec('UPDATE users SET last_active_at = ? WHERE id = ?', [wb_utc($now), (int)$user['id']]);
    }
    $s['id'] = (int)$s['id'];
    $s['workspace_org_id'] = $s['workspace_org_id'] !== null ? (int)$s['workspace_org_id'] : null;
    $s['user'] = $user;
    return $GLOBALS['wb_session_cache'] = $s;
}

function wb_session_forget_cache(): void {
    unset($GLOBALS['wb_session_cache']);
    $GLOBALS['wb_auth_cache'] = null;
}

function wb_session_revoke(int $id, int $userId): void {
    db_exec('UPDATE sessions SET revoked_at = ? WHERE id = ? AND user_id = ? AND revoked_at IS NULL', [wb_now(), $id, $userId]);
}

/** Beendet alle Sitzungen eines Kontos, wahlweise ausser einer. */
function wb_sessions_revoke_user(int $userId, ?int $exceptId = null): int {
    return db_exec('UPDATE sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL' . ($exceptId ? ' AND id <> ?' : ''), $exceptId ? [wb_now(), $userId, $exceptId] : [wb_now(), $userId]);
}

function wb_logout(): void {
    $s = wb_session();
    if ($s) wb_session_revoke($s['id'], (int)$s['user_id']);
    wb_cookie_set(WB_SESSION_COOKIE, '', time() - 3600);
    wb_session_forget_cache();
}

/** Die Passwortabfrage vor heiklen Handlungen gilt einige Minuten (Vorgabe 10). */
function wb_reauth_fresh(): bool {
    $s = wb_session();
    return $s !== null && wb_ts($s['reauth_at']) > time() - wb_setting_int('reauth_minutes', 10) * 60;
}

function wb_reauth_mark(): void {
    $s = wb_session();
    if ($s) { db_exec('UPDATE sessions SET reauth_at = ? WHERE id = ?', [wb_now(), $s['id']]); wb_session_forget_cache(); }
}

/** Meldung für die nächste Seite. Mit Sitzung an der Sitzungszeile, sonst in einem kurzlebigen Cookie. */
function wb_flash(?string $msg = null, ?array $extra = null): ?array {
    $s = wb_session();
    if ($msg !== null) {
        $data = json_encode(['msg' => $msg, 'extra' => $extra], JSON_UNESCAPED_UNICODE);
        if ($s) db_exec('UPDATE sessions SET flash_json = ? WHERE id = ?', [$data, $s['id']]);
        else wb_cookie_set('tafula_f', base64_encode(mb_substr($msg, 0, 400)), time() + 60);
        return null;
    }
    if ($s) {
        if (empty($s['flash_json'])) return null;
        db_exec('UPDATE sessions SET flash_json = NULL WHERE id = ?', [$s['id']]);
        $GLOBALS['wb_session_cache']['flash_json'] = null;
        $d = json_decode((string)$s['flash_json'], true);
        return is_array($d) ? $d : null;
    }
    $c = wb_cookie_get('tafula_f');
    if ($c === '') return null;
    wb_cookie_set('tafula_f', '', time() - 3600);
    $m = base64_decode($c, true);
    return $m !== false && $m !== '' ? ['msg' => mb_substr($m, 0, 400), 'extra' => null] : null;
}

/* ---------- CSRF ---------- */

/** Mit Sitzung der Wert der Sitzung, sonst ein HMAC über den Browser-Schlüssel wb_uid (anonyme Formulare). */
function wb_csrf_token(): string {
    $s = wb_session();
    if ($s) return (string)$s['csrf'];
    return substr(wb_hmac('csrf', wb_client_uid()), 0, 32);
}

function wb_csrf_ok(string $given): bool {
    return $given !== '' && hash_equals(wb_csrf_token(), $given);
}

/** Token nur aus dem Header Authorization: Bearer. Der Ersatz ?token= ist seit 0.23.0 entfallen. */
function wb_bearer_token(): ?string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(\S+)$/i', trim((string)$h), $m)) {
        return $m[1];
    }
    return null;
}

/* ---------- Wer ruft auf ---------- */

/**
 * Zusammenhang des Aufrufs: ['kind' => 'session'|'token'|'cli', 'via' => bei Tokens oauth|personal|legacy, 'user' => Konto, 'session' => Zeile|null,
 * 'org_id' => Arbeitsbereich (null bedeutet «Privat»)] oder null.
 */
function wb_auth(): ?array {
    if (!empty($GLOBALS['wb_auth_cache'])) return $GLOBALS['wb_auth_cache'];
    $token = wb_bearer_token();
    if ($token !== null) {
        $a = wb_auth_from_token($token);
        if ($a) return $GLOBALS['wb_auth_cache'] = $a;
    }
    $s = wb_session();
    if ($s) return $GLOBALS['wb_auth_cache'] = ['kind' => 'session', 'user' => $s['user'], 'session' => $s, 'org_id' => wb_valid_workspace($s['user'], $s['workspace_org_id'])];
    return null;
}

/**
 * Die einzige Stelle, die Tokens prüft (seit 0.23.0 gegen die Tabellen oauth_tokens und api_tokens, nur Hashes).
 * Welche Art gilt, bestimmt der Ort des Aufrufs in $GLOBALS['wb_token_context'] = [Ort, Adresse des Connectors].
 * Ohne Ort (Vorgabe seit U1c, S1 N2) gilt kein Token: Seiten wie /<kurzwort>, /b/<schlüssel>/export oder
 * /a/<schlüssel>/print kennen nur die Sitzung. Die Orte setzen api/index.php und mcp/index.php selbst:
 *   «api»    persönliche Tokens (tfp_…),
 *   «mcp»    Zugriffstokens einer OAuth-Verbindung (tfa_…), nur am Connector, für den sie erteilt wurden,
 *   «legacy» der frühere api_token an der alten Connector-Adresse /mcp/<token>.
 * Das Ergebnis hat immer kind «token» (nie Systemrechte, nie Rückmeldungen), dazu via = oauth|personal|legacy.
 * Konto und Zugehörigkeit zum Arbeitsbereich werden bei jedem Aufruf neu geprüft (lib/oauth.php).
 */
function wb_auth_from_token(string $token): ?array {
    [$place, $resource] = $GLOBALS['wb_token_context'] ?? [null, null];
    if ($place === null) return null;
    try {
        if (str_starts_with($token, 'tfr_') || str_starts_with($token, 'tfc_')) return null;
        if (str_starts_with($token, 'tfa_')) return $place === 'mcp' && is_string($resource) ? oauth_access_auth($token, $resource) : null;
        return $place === 'mcp' ? null : api_token_auth($token, (string)$place);
    } catch (Throwable $e) {
        return null;
    }
}

/** Nur Kommandozeile (Testbestand, Cronjob, Tests): als ein Konto in einem Arbeitsbereich handeln. */
function wb_auth_cli(?int $userId, ?int $orgId = null): void {
    if (PHP_SAPI !== 'cli') throw new LogicException('wb_auth_cli nur auf der Kommandozeile.');
    $u = $userId !== null ? user_by_id($userId) : null;
    $GLOBALS['wb_auth_cache'] = $u ? ['kind' => 'cli', 'user' => $u, 'session' => null, 'org_id' => $orgId] : null;
}

function wb_current_user(): ?array {
    return wb_auth()['user'] ?? null;
}

function wb_auth_kind(): ?string {
    return wb_auth()['kind'] ?? null;
}

/** Nummer des angemeldeten Kontos; ohne Anmeldung ein Fehler statt eines stillen Rückfalls auf den Benutzer 1. */
function wb_user_id(): int {
    $u = wb_current_user();
    if ($u === null) throw new RuntimeException('Nicht angemeldet.');
    return (int)$u['id'];
}

/**
 * Schreibende Aufrufe mit Sitzung müssen den CSRF-Wert im Header X-CSRF mitschicken. Aufrufe, die sich mit einem
 * Token ausweisen, brauchen das nicht: Ein fremder Browser kennt den Token nicht.
 */
function wb_check_csrf_header(): void {
    if (wb_auth_kind() === 'token') return;
    if (!wb_csrf_ok((string)($_SERVER['HTTP_X_CSRF'] ?? ''))) wb_error('Ungültige Anfrage (CSRF).', 403);
}
