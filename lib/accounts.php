<?php
declare(strict_types=1);

/*
 * Abläufe rund ums Konto (Nutzerverwaltung U1a, Abschnitte 6 und 7): Bremsen, Anmeldung in zwei Stufen,
 * bekannte Geräte, Registrierung und Bestätigung, Einladungen des Super-Admins, Zurücksetzen, Adresse ändern,
 * Entfernen der zweiten Stufe mit 72 Stunden Frist. Die Seiten dazu liegen in pages/.
 *
 * Grundsätze: Anmeldung, Registrierung und Zurücksetzen antworten für bekannte und unbekannte Adressen gleich.
 * Links und Codes stehen nur als Hash in login_tokens. Sitzungen, Links, Codes und Bremsen folgen der echten
 * Zeit; fachliche Fristen (dreissig Tage, 72 Stunden, Verfall von Einladungen) der fachlichen (wb_business_time).
 */

const ACC_IP_FAILS = 20;          // Fehlversuche pro IP-Adresse in ACC_WINDOW Minuten, danach 429
const ACC_ACCOUNT_FAILS = 5;      // Fehlversuche pro Konto in ACC_WINDOW Minuten, danach ein Versuch pro Minute
const ACC_DEVICE_FAILS = 10;      // dasselbe für ein bekanntes Gerät, gezählt pro Konto und Gerät (S1, M5)
const ACC_WINDOW = 15;
const ACC_CODE_TTL = 600;         // Mailcode und Zwischenstand der Anmeldung: zehn Minuten
const ACC_CODE_TRIES = 5;
const ACC_DEVICE_DAYS = 180;
const ACC_UNVERIFIED_DAYS = 30;
const ACC_TOTP_REMOVAL_HOURS = 72;
const ACC_EMAIL_REVERT_DAYS = 7;

/* ---------- Bremsen (Tabelle rate_limits, Schlüssel aus einem Teilschlüssel von app_secret) ---------- */

function brake_key(string $what): string {
    return substr(wb_hmac('brake', $what), 0, 32);
}

function brake_count(string $what, int $minutes): int {
    $row = db_row('SELECT COALESCE(SUM(cnt), 0) AS n FROM rate_limits WHERE author_key = ? AND minute > ?', [brake_key($what), intdiv(time(), 60) - $minutes]);
    return (int)($row['n'] ?? 0);
}

function brake_hit(string $what): void {
    db_exec('INSERT INTO rate_limits (author_key, minute, cnt) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE cnt = cnt + 1', [brake_key($what), intdiv(time(), 60)]);
}

/** true, wenn $what in $minutes schon $max-mal gezählt wurde; sonst zählt der Aufruf mit. */
function brake_exceeded(string $what, int $max, int $minutes): bool {
    if (brake_count($what, $minutes) >= $max) return true;
    brake_hit($what);
    return false;
}

/* ---------- Links und Codes ---------- */

/** Legt einen Link oder Code an und gibt den Klartext zurück (er steht nur in der Mail). */
function login_token_create(int $userId, string $purpose, int $ttl, array $payload = [], ?string $code = null): string {
    $raw = $code ?? wb_random_token(32);
    // Frühere offene Links desselben Zwecks verfallen.
    db_exec('UPDATE login_tokens SET used_at = ? WHERE user_id = ? AND purpose = ? AND used_at IS NULL', [wb_now(), $userId, $purpose]);
    db_exec('INSERT INTO login_tokens (user_id, purpose, token_hash, payload_json, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)',
        [$userId, $purpose, wb_token_hash($code !== null ? "code|$userId|$code" : $raw), $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null, wb_now(), wb_utc(time() + $ttl)]);
    return $raw;
}

/** Gültiger, unbenutzter Link dieses Zwecks oder null. */
function login_token_find(string $raw, string $purpose): ?array {
    if ($raw === '' || strlen($raw) > 100) return null;
    $t = db_row('SELECT * FROM login_tokens WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > ?', [wb_token_hash($raw), $purpose, wb_now()]);
    if (!$t) return null;
    $t['payload'] = (array)json_decode((string)($t['payload_json'] ?? '[]'), true);
    return $t;
}

/** Verbraucht einen Link; false, wenn er inzwischen benutzt wurde. */
function login_token_use(int $id): bool {
    return db_exec('UPDATE login_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL', [wb_now(), $id]) === 1;
}

/** Prüft einen sechsstelligen Code für ein Konto; nach ACC_CODE_TRIES Fehlversuchen ist er verbraucht. */
function login_code_check(int $userId, string $purpose, string $code): bool {
    $code = preg_replace('/\D/', '', $code) ?? '';
    $t = db_row('SELECT * FROM login_tokens WHERE user_id = ? AND purpose = ? AND used_at IS NULL AND expires_at > ? ORDER BY id DESC LIMIT 1', [$userId, $purpose, wb_now()]);
    if (!$t) return false;
    if ((int)$t['attempts'] >= ACC_CODE_TRIES) { login_token_use((int)$t['id']); return false; }
    if (strlen($code) === 6 && hash_equals((string)$t['token_hash'], wb_token_hash("code|$userId|$code"))) return login_token_use((int)$t['id']);
    db_exec('UPDATE login_tokens SET attempts = attempts + 1 WHERE id = ?', [(int)$t['id']]);
    return false;
}

function account_link(string $path, string $token): string {
    return wb_base_url() . $path . '?t=' . rawurlencode($token);
}

/* ---------- Bekannte Geräte ---------- */

/** Kennung des bekannten Geräts für die Bremse (Hash des Cookies) oder ''. */
function known_device_key(): string {
    $c = wb_cookie_get(WB_DEVICE_COOKIE);
    return $c === '' || strlen($c) > 100 ? '' : wb_token_hash($c);
}

/** Konto, dem dieses Gerät als bekannt gilt, oder 0. */
function known_device_user(): int {
    $c = wb_cookie_get(WB_DEVICE_COOKIE);
    if ($c === '' || strlen($c) > 100) return 0;
    $d = db_row('SELECT id, user_id FROM known_devices WHERE token_hash = ? AND expires_at > ?', [wb_token_hash($c), wb_now()]);
    return $d ? (int)$d['user_id'] : 0;
}

function known_device_mark(int $userId): void {
    $now = time();
    if (known_device_user() === $userId) {
        db_exec('UPDATE known_devices SET last_used_at = ?, ip = ? WHERE token_hash = ?', [wb_utc($now), wb_client_ip(), wb_token_hash(wb_cookie_get(WB_DEVICE_COOKIE))]);
        return;
    }
    $token = wb_random_token(32);
    db_exec('INSERT INTO known_devices (user_id, token_hash, user_agent, ip, created_at, last_used_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$userId, wb_token_hash($token), wb_user_agent(), wb_client_ip(), wb_utc($now), wb_utc($now), wb_utc($now + ACC_DEVICE_DAYS * 86400)]);
    wb_cookie_set(WB_DEVICE_COOKIE, $token, $now + ACC_DEVICE_DAYS * 86400);
}

/* ---------- Anmeldung ---------- */

/**
 * Erste Stufe: Adresse und Passwort. Ergebnis: ['state' => 'ok', 'user' => Konto] oder 'fail' (falsch,
 * unbekannt, unbestätigt oder gesperrt, ohne Unterschied) oder 'wait' (Bremse, mit 'seconds').
 *
 * Ein bekanntes Gerät erspart den Mailcode, aber nicht die Bremse (S1, M5): Seine Fehlversuche zählen pro Konto
 * und Gerät mit einer höheren Schwelle und weder auf die IP-Adresse noch auf das Konto, damit sie niemanden
 * sonst aussperren. Danach bleibt ein Versuch pro Minute.
 */
function login_check_password(string $email, string $password): array {
    $ip = wb_client_net();
    $norm = wb_email_normalize($email) ?? 'ungueltig';
    $user = user_by_email($email);
    $known = $user !== null && known_device_user() === (int)$user['id'];
    $devBrake = $known ? 'login-dev|' . $norm . '|' . known_device_key() : '';
    if ($known) {
        if (brake_count($devBrake, ACC_WINDOW) >= ACC_DEVICE_FAILS && brake_count('login-try|' . $norm, 1) >= 1) return ['state' => 'wait', 'seconds' => 60];
    } else {
        if ($ip !== '' && brake_count('login-ip|' . $ip, ACC_WINDOW) >= ACC_IP_FAILS) return ['state' => 'wait', 'seconds' => ACC_WINDOW * 60];
        // Verlangsamen, nie aussperren: nach einigen Fehlversuchen noch ein Versuch pro Minute und Konto.
        if (brake_count('login-acct|' . $norm, ACC_WINDOW) >= ACC_ACCOUNT_FAILS && brake_count('login-try|' . $norm, 1) >= 1) return ['state' => 'wait', 'seconds' => 60];
    }
    brake_hit('login-try|' . $norm);
    // Auch ohne Konto wird ein Hash geprüft, damit die Antwortzeit nichts verrät.
    $hash = $user['password_hash'] ?? '$2y$10$CwTycUXWue0Thq9StjUM0uJ8Zf5aSsZL1T8ZSyv7nO3Xv2aQhG2bK';
    $ok = password_verify($password, (string)$hash);
    // Testinstanz (S1, M4): Testkonten mit Systemrolle melden sich nur über /test mit dem zweiten Code an,
    // weil ihr Passwort und das App-Geheimnis des Test-Admins öffentlich in tools/seed.php stehen.
    if ($ok && $user !== null && gate_required() && str_ends_with((string)$user['email'], '@' . WB_TEST_DOMAIN) && user_system_roles((int)$user['id']) !== []) $ok = false;
    if (!$ok || $user === null || !user_is_active($user)) {
        if ($known) {
            brake_hit($devBrake);
        } else {
            brake_hit('login-acct|' . $norm);
            if ($ip !== '') brake_hit('login-ip|' . $ip);
        }
        return ['state' => 'fail'];
    }
    if (password_needs_rehash((string)$user['password_hash'], PASSWORD_DEFAULT)) user_set_password((int)$user['id'], $password);
    return ['state' => 'ok', 'user' => $user];
}

/**
 * Welche zweite Stufe braucht diese Anmeldung? 'totp' mit eingerichteter App; 'mail' ohne App auf einem
 * unbekannten Gerät und immer für Konten mit Systemrolle (sie müssen danach die App einrichten); sonst 'none'.
 */
function login_second_factor(array $user): string {
    account_totp_removal_apply($user);
    $user = user_reload((int)$user['id']);
    if (user_has_totp($user)) return 'totp';
    if (user_system_roles((int)$user['id']) !== []) return 'mail';
    return known_device_user() === (int)$user['id'] ? 'none' : 'mail';
}

/** Merkt sich zwischen erster und zweiter Stufe, wer sich anmeldet (signiertes Cookie, zehn Minuten). */
function login_pending_set(int $userId, string $method): void {
    wb_cookie_set('tafula_p', wb_signed_make('pending', $userId . '|' . $method, ACC_CODE_TTL), time() + ACC_CODE_TTL);
}

function login_pending(): ?array {
    $v = wb_signed_read('pending', wb_cookie_get('tafula_p'));
    if ($v === null || !preg_match('/^(\d+)\|(totp|mail)$/', $v, $m)) return null;
    $u = user_by_id((int)$m[1]);
    return $u && user_is_active($u) ? ['user' => $u, 'method' => $m[2]] : null;
}

function login_pending_clear(): void {
    wb_cookie_set('tafula_p', '', time() - 3600);
}

function login_send_mail_code(array $user): void {
    $uid = (int)$user['id'];
    if (brake_exceeded('mailcode|' . $uid, 5, 60)) return;
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    login_token_create($uid, 'mail_code', ACC_CODE_TTL, [], $code);
    mail_enqueue((string)$user['email'], 'login_code', tx('Dein Anmeldecode für {app}', ['app' => wb_app_name()]),
        tx("Hallo {name}\n\nDein Code für die Anmeldung bei {app} lautet:\n\n    {code}\n\nEr gilt zehn Minuten. Wenn du dich nicht gerade anmeldest, kennt jemand dein Passwort: Ändere es dann in deinem Konto.",
            ['name' => user_name($user), 'app' => wb_app_name(), 'code' => $code]), $uid);
}

/** Zweite Stufe prüfen: App-Code, Wiederherstellungscode oder Mailcode. */
function login_check_second(array $pending, string $code): bool {
    $user = user_reload((int)$pending['user']['id']);
    $uid = (int)$user['id'];
    if (brake_count('second|' . $uid, ACC_WINDOW) >= 10) return false;
    $ok = $pending['method'] === 'totp'
        ? (totp_verify_user($user, $code) || totp_recovery_use($user, $code))
        : login_code_check($uid, 'mail_code', $code);
    if (!$ok) brake_hit('second|' . $uid);
    return $ok;
}

/** Sitzung anlegen, Gerät merken, Zwischenstand löschen. */
function login_finish(array $user, string $mfa): array {
    login_pending_clear();
    $s = wb_session_create((int)$user['id'], $mfa);
    known_device_mark((int)$user['id']);
    return $s;
}

/* ---------- Registrierung und Bestätigung ---------- */

function account_terms_version(): string {
    return (string)setting_get('terms_version', 'entwurf-2026-11');
}

function account_record_terms(int $userId, bool $age): void {
    $now = wb_now();
    $rows = [['terms', tx('Ich habe die Nutzungsbedingungen und die Datenschutzerklärung gelesen und bin einverstanden.', [], 'de')]];
    if ($age) $rows[] = ['age', tx('Ich bin mindestens 16 Jahre alt.', [], 'de')];
    foreach ($rows as [$kind, $wording]) {
        db_exec('INSERT INTO terms_acceptances (user_id, kind, version, wording, ip, accepted_at) VALUES (?, ?, ?, ?, ?, ?)', [$userId, $kind, account_terms_version(), $wording, wb_client_ip(), $now]);
    }
}

/**
 * Registrierung. Prüft die Eingaben und gibt eine Fehlermeldung zurück oder null. Bei null ist entweder ein
 * unbestätigtes Konto samt Bestätigungsmail entstanden, oder die Adresse gehört schon einem bestätigten Konto
 * und dessen Inhaberin hat einen Hinweis erhalten; die Seite zeigt in beiden Fällen dasselbe.
 */
function account_signup(array $in): ?string {
    if (trim((string)($in['website'] ?? '')) !== '') return null; // Fangfeld: so tun, als ob
    $email = wb_email_normalize((string)($in['email'] ?? ''));
    if ($email === null) return tx('Diese Adresse ist ungültig.');
    if (wb_email_is_card($email)) return tx('Mit dieser Adresse kann man sich nicht registrieren.');
    $first = wb_clean_text($in['first_name'] ?? '', 80);
    $last = wb_clean_text($in['last_name'] ?? '', 80);
    if ($first === '' || $last === '') return tx('Bitte Vor- und Nachnamen angeben.');
    if (empty($in['age'])) return tx('Die Registrierung ist ab 16 Jahren möglich. Bitte bestätige dein Alter.');
    if (empty($in['terms'])) return tx('Bitte stimme den Nutzungsbedingungen zu.');
    $pw = (string)($in['password'] ?? '');
    if (($p = wb_password_problem($pw, [$email, strstr($email, '@', true), $first, $last])) !== null) return $p;
    if ($pw !== (string)($in['password2'] ?? '')) return tx('Die Passwörter stimmen nicht überein.');
    if (brake_exceeded('signup-ip|' . wb_client_net(), 5, 60)) return tx('Von diesem Anschluss kamen zu viele Registrierungen. Bitte später wieder versuchen.');
    // Gesamtgrenze für Mails an fremde Adressen aus dem öffentlichen Formular (S1, N8).
    if (brake_exceeded('signup-all', wb_setting_int('signup_mails_per_hour', 30), 60)) return tx('Zurzeit kommen zu viele Registrierungen. Bitte später wieder versuchen.');

    $existing = user_by_email($email);
    if ($existing && $existing['email_verified_at'] !== null) {
        // Gleich viel rechnen wie beim Anlegen eines Kontos, damit die Antwortzeit nichts verrät (S1, M6).
        password_hash($pw, PASSWORD_DEFAULT);
        mail_enqueue($email, 'notice', tx('Registrierung bei {app}', ['app' => wb_app_name()]),
            tx("Hallo {name}\n\nJemand hat versucht, sich mit deiner Adresse bei {app} zu registrieren. Du hast schon ein Konto. Wenn du das warst, melde dich einfach an:\n\n{url}\n\nHast du dein Passwort vergessen, kannst du es dort zurücksetzen. Wenn du das nicht warst, musst du nichts tun.",
                ['name' => user_name($existing), 'app' => wb_app_name(), 'url' => wb_base_url() . '/login']), (int)$existing['id']);
        return null;
    }
    // Eine unbestätigte Adresse reserviert nichts: Die neue Registrierung ersetzt die alte.
    if ($existing) user_delete_unverified((int)$existing['id']);
    $u = user_create(['email' => $email, 'password' => $pw, 'first_name' => $first, 'last_name' => $last, 'locale' => $in['locale'] ?? 'de', 'age_confirmed' => true]);
    account_record_terms((int)$u['id'], true);
    account_send_verification($u);
    return null;
}

function account_send_verification(array $u): void {
    $token = login_token_create((int)$u['id'], 'verify_email', 3 * 86400);
    mail_enqueue((string)$u['email'], 'verify', tx('Bitte bestätige deine Adresse für {app}', ['app' => wb_app_name()]),
        tx("Guten Tag\n\nMit diesem Link bestätigst du deine Adresse und schliesst die Registrierung bei {app} ab. Du brauchst dazu das Passwort, das du bei der Registrierung gewählt hast:\n\n{url}\n\nDer Link gilt drei Tage. Wenn du dich nicht registriert hast, musst du nichts tun; ohne Bestätigung wird das Konto gelöscht.",
            ['name' => user_name($u), 'app' => wb_app_name(), 'url' => account_link('/signup/verify', $token)]), (int)$u['id']);
}

/**
 * Schliesst die Bestätigung ab. Verlangt das bei der Registrierung gewählte Passwort, damit eine fremde
 * Registrierung mit dieser Adresse der Inhaberin kein Konto mit fremdem Passwort unterschieben kann.
 */
function account_verify(string $rawToken, string $password): ?array {
    $t = login_token_find($rawToken, 'verify_email');
    if (!$t) return null;
    $u = user_reload((int)$t['user_id']);
    if (!$u || $u['disabled_at'] !== null) return null;
    if (brake_count('verify|' . $u['id'], ACC_WINDOW) >= 10) return null;
    if (!password_verify($password, (string)$u['password_hash'])) { brake_hit('verify|' . $u['id']); return null; }
    if (!login_token_use((int)$t['id'])) return null;
    db_exec('UPDATE users SET email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?', [wb_now(), (int)$u['id']]);
    return user_reload((int)$u['id']);
}

/* ---------- Einladungen des Super-Admins ---------- */

function invitation_create(string $email, bool $teacherGrant, int $days, int $by): array {
    $email = wb_email_normalize($email);
    if ($email === null || wb_email_is_card($email)) throw new InvalidArgumentException(tx('Diese Adresse ist ungültig.'));
    $days = max(1, min(90, $days));
    $raw = wb_random_token(32);
    $now = wb_business_time();
    db_exec("UPDATE invitations SET revoked_at = ? WHERE email = ? AND kind = 'system' AND accepted_at IS NULL AND revoked_at IS NULL", [wb_now(), $email]);
    $id = db_insert("INSERT INTO invitations (kind, scope_id, email, token_hash, teacher_grant, created_by, created_at, expires_at) VALUES ('system', 0, ?, ?, ?, ?, ?, ?)",
        [$email, wb_token_hash($raw), $teacherGrant ? 1 : 0, $by, wb_utc($now), wb_utc($now + $days * 86400)]);
    $inviter = user_by_id($by);
    mail_enqueue($email, 'invite', tx('Einladung zu {app}', ['app' => wb_app_name()]),
        tx("Guten Tag\n\n{inviter} lädt dich ein, {app} zu benutzen. Mit diesem Link legst du dein Konto an (oder nimmst die Einladung mit einem bestehenden Konto an):\n\n{url}\n\nDie Einladung gilt {days} Tage. Wenn du sie nicht erwartet hast, musst du nichts tun.",
            ['inviter' => $inviter ? user_name($inviter) : wb_app_name(), 'app' => wb_app_name(), 'url' => account_link('/invite', $raw), 'days' => $days]));
    audit_log('invite.create', 'invitation', $id, ['email' => $email, 'teacher_grant' => $teacherGrant, 'days' => $days]);
    return db_row('SELECT * FROM invitations WHERE id = ?', [$id]);
}

function invitation_find(string $raw): ?array {
    if ($raw === '' || strlen($raw) > 100) return null;
    $i = db_row('SELECT * FROM invitations WHERE token_hash = ? AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at > ?', [wb_token_hash($raw), wb_utc(wb_business_time())]);
    return $i ?: null;
}

function invitation_revoke(int $id): void {
    if (db_exec('UPDATE invitations SET revoked_at = ? WHERE id = ? AND accepted_at IS NULL AND revoked_at IS NULL', [wb_now(), $id])) audit_log('invite.revoke', 'invitation', $id);
}

function invitations_list(int $limit = 100): array {
    return db_all("SELECT * FROM invitations WHERE kind = 'system' ORDER BY id DESC LIMIT " . max(1, min(500, $limit)));
}

function invitation_apply(array $inv, int $userId): void {
    $now = wb_now();
    if (db_exec('UPDATE invitations SET accepted_at = ?, accepted_user_id = ? WHERE id = ? AND accepted_at IS NULL AND revoked_at IS NULL', [$now, $userId, (int)$inv['id']]) !== 1) {
        throw new InvalidArgumentException(tx('Diese Einladung ist nicht mehr gültig.'));
    }
    db_exec('UPDATE users SET invited_by = COALESCE(invited_by, ?) WHERE id = ?', [(int)$inv['created_by'], $userId]);
    if ((int)$inv['teacher_grant'] === 1) {
        db_exec('UPDATE users SET teacher_requested_at = COALESCE(teacher_requested_at, ?), teacher_approved_at = ?, teacher_approved_by = ?, teacher_revoked_at = NULL WHERE id = ?', [$now, $now, (int)$inv['created_by'], $userId]);
    }
    audit_log('invite.accept', 'user', $userId, ['invitation' => (int)$inv['id'], 'teacher_grant' => (int)$inv['teacher_grant'] === 1], null, $userId, 'invitee');
    user_reload($userId);
}

/**
 * Nimmt eine Einladung mit einem neuen Konto an. Die Adresse gilt mit dem Link als bestätigt. Ein unbestätigtes
 * Konto mit dieser Adresse wird nie übernommen: Es verfällt, und ein neues entsteht.
 * Gibt [Konto, null] oder [null, Fehlermeldung] zurück.
 */
function invitation_accept_new(array $inv, array $in): array {
    $email = (string)$inv['email'];
    $existing = user_by_email($email);
    if ($existing && $existing['email_verified_at'] !== null) return [null, tx('Zu dieser Adresse gibt es schon ein Konto. Melde dich an, um die Einladung anzunehmen.')];
    $first = wb_clean_text($in['first_name'] ?? '', 80);
    $last = wb_clean_text($in['last_name'] ?? '', 80);
    if ($first === '' || $last === '') return [null, tx('Bitte Vor- und Nachnamen angeben.')];
    if (empty($in['age'])) return [null, tx('Die Registrierung ist ab 16 Jahren möglich. Bitte bestätige dein Alter.')];
    if (empty($in['terms'])) return [null, tx('Bitte stimme den Nutzungsbedingungen zu.')];
    $pw = (string)($in['password'] ?? '');
    if (($p = wb_password_problem($pw, [$email, strstr($email, '@', true), $first, $last])) !== null) return [null, $p];
    if ($pw !== (string)($in['password2'] ?? '')) return [null, tx('Die Passwörter stimmen nicht überein.')];
    if ($existing) user_delete_unverified((int)$existing['id']);
    $u = user_create(['email' => $email, 'password' => $pw, 'first_name' => $first, 'last_name' => $last, 'verified' => true, 'age_confirmed' => true, 'invited_by' => (int)$inv['created_by']]);
    account_record_terms((int)$u['id'], true);
    try {
        invitation_apply($inv, (int)$u['id']);
    } catch (InvalidArgumentException $e) {
        db_exec('UPDATE users SET email_verified_at = NULL WHERE id = ?', [(int)$u['id']]);
        user_delete_unverified((int)$u['id']);
        return [null, $e->getMessage()];
    }
    return [user_reload((int)$u['id']), null];
}

/* ---------- Passwort zurücksetzen ---------- */

/** Antwortet nie mit einem Unterschied: Die Seite zeigt immer denselben Satz. */
function account_reset_request(string $email): void {
    $norm = wb_email_normalize($email) ?? 'ungueltig';
    if (brake_exceeded('reset-ip|' . wb_client_net(), 5, 60) || brake_exceeded('reset-acct|' . $norm, 3, 60)) return;
    $u = user_by_email($email);
    if (!$u || !user_is_active($u)) return;
    $token = login_token_create((int)$u['id'], 'reset_password', 3600);
    mail_enqueue((string)$u['email'], 'reset', tx('Passwort für {app} zurücksetzen', ['app' => wb_app_name()]),
        tx("Hallo {name}\n\nMit diesem Link setzt du ein neues Passwort für {app}:\n\n{url}\n\nEr gilt eine Stunde und nur einmal. Danach sind alle anderen Sitzungen beendet. Wenn du das nicht verlangt hast, musst du nichts tun; dein Passwort bleibt, wie es ist.",
            ['name' => user_name($u), 'app' => wb_app_name(), 'url' => account_link('/login/reset', $token)]), (int)$u['id']);
}

/** Setzt das Passwort neu und beendet alle Sitzungen. Die zweite Stufe bleibt. Fehlermeldung oder null. */
function account_reset_finish(string $rawToken, string $pw, string $pw2): ?string {
    $t = login_token_find($rawToken, 'reset_password');
    $u = $t ? user_reload((int)$t['user_id']) : null;
    if (!$u || !user_is_active($u)) return tx('Dieser Link ist nicht mehr gültig. Fordere einen neuen an.');
    if (($p = wb_password_problem($pw, [(string)$u['email'], strstr((string)$u['email'], '@', true), $u['first_name'], $u['last_name']])) !== null) return $p;
    if ($pw !== $pw2) return tx('Die Passwörter stimmen nicht überein.');
    if (!login_token_use((int)$t['id'])) return tx('Dieser Link ist nicht mehr gültig. Fordere einen neuen an.');
    user_set_password((int)$u['id'], $pw);
    wb_sessions_revoke_user((int)$u['id']);
    tokens_revoke_user((int)$u['id'], 'password_reset');
    db_exec('DELETE FROM known_devices WHERE user_id = ?', [(int)$u['id']]);
    account_notify($u, tx('Dein Passwort für {app} wurde geändert', ['app' => wb_app_name()]),
        tx("Das Passwort deines Kontos wurde soeben über einen Link per Mail neu gesetzt. Alle Sitzungen wurden beendet.\n\nWenn du das nicht warst, setze das Passwort sofort noch einmal zurück:\n\n{url}", ['url' => wb_base_url() . '/login/reset']));
    return null;
}

function account_notify(array $u, string $subject, string $body): void {
    mail_enqueue((string)$u['email'], 'notice', $subject, tx('Hallo {name}', ['name' => user_name($u)]) . "\n\n" . $body, (int)$u['id']);
}

/* ---------- Adresse ändern ---------- */

/** Verschickt den Bestätigungslink an die neue Adresse. Fehlermeldung oder null (auch wenn die Adresse vergeben ist). */
function account_email_change_request(array $u, string $new): ?string {
    $new = wb_email_normalize($new);
    if ($new === null || wb_email_is_card($new)) return tx('Diese Adresse ist ungültig.');
    if ($new === $u['email']) return tx('Das ist schon deine Adresse.');
    if (brake_exceeded('emailchange|' . $u['id'], 5, 60)) return tx('Zu viele Versuche. Bitte später wieder versuchen.');
    $taken = user_by_email($new);
    if ($taken && $taken['email_verified_at'] !== null) return null; // nicht verraten, dass es das Konto gibt
    $token = login_token_create((int)$u['id'], 'email_change', 86400, ['new' => $new]);
    mail_enqueue($new, 'verify', tx('Neue Adresse für {app} bestätigen', ['app' => wb_app_name()]),
        tx("Hallo {name}\n\nMit diesem Link bestätigst du diese Adresse als neue Adresse deines Kontos bei {app}:\n\n{url}\n\nDer Link gilt einen Tag. Wenn du das nicht verlangt hast, musst du nichts tun.",
            ['name' => user_name($u), 'app' => wb_app_name(), 'url' => account_link('/account/email', $token)]));
    return null;
}

/**
 * Bestätigt die neue Adresse; die alte erhält einen Link, der die Änderung sieben Tage lang rückgängig macht.
 * $userId ist das angemeldete Konto; der Link gilt nur für dieses (S1, N5).
 */
function account_email_change_confirm(string $rawToken, int $userId): bool {
    $t = login_token_find($rawToken, 'email_change');
    if ($t && (int)$t['user_id'] !== $userId) return false;
    $u = $t ? user_reload((int)$t['user_id']) : null;
    $new = wb_email_normalize((string)($t['payload']['new'] ?? ''));
    if (!$u || !user_is_active($u) || $new === null) return false;
    $taken = user_by_email($new);
    if ($taken && (int)$taken['id'] !== (int)$u['id']) {
        if ($taken['email_verified_at'] !== null) return false;
        user_delete_unverified((int)$taken['id']);
    }
    if (!login_token_use((int)$t['id'])) return false;
    $old = (string)$u['email'];
    db_exec('UPDATE users SET email = ? WHERE id = ?', [$new, (int)$u['id']]);
    $revert = login_token_create((int)$u['id'], 'email_revert', ACC_EMAIL_REVERT_DAYS * 86400, ['old' => $old, 'new' => $new]);
    mail_enqueue($old, 'notice', tx('Die Adresse deines Kontos bei {app} wurde geändert', ['app' => wb_app_name()]),
        tx("Hallo {name}\n\nDie Adresse deines Kontos bei {app} wurde von {old} auf {new} geändert.\n\nWenn du das nicht warst, mach die Änderung mit diesem Link rückgängig. Er gilt sieben Tage, beendet alle Sitzungen und verlangt ein neues Passwort:\n\n{url}",
            ['name' => user_name($u), 'app' => wb_app_name(), 'old' => $old, 'new' => $new, 'url' => account_link('/account/email-revert', $revert)]), (int)$u['id']);
    audit_log('account.email_change', 'user', (int)$u['id'], [], null, (int)$u['id'], 'self');
    user_reload((int)$u['id']);
    return true;
}

/** Macht eine Adressänderung rückgängig, beendet alle Sitzungen und setzt ein neues Passwort. Fehlermeldung oder null. */
function account_email_revert(string $rawToken, string $pw, string $pw2): ?string {
    $t = login_token_find($rawToken, 'email_revert');
    $u = $t ? user_reload((int)$t['user_id']) : null;
    $old = wb_email_normalize((string)($t['payload']['old'] ?? ''));
    if (!$u || $old === null || $u['deleted_at'] !== null) return tx('Dieser Link ist nicht mehr gültig.');
    if (($p = wb_password_problem($pw, [$old, strstr($old, '@', true), $u['first_name'], $u['last_name']])) !== null) return $p;
    if ($pw !== $pw2) return tx('Die Passwörter stimmen nicht überein.');
    $taken = user_by_email($old);
    if ($taken && (int)$taken['id'] !== (int)$u['id']) {
        if ($taken['email_verified_at'] !== null) return tx('Die frühere Adresse gehört inzwischen einem anderen Konto. Wende dich an {mail}.', ['mail' => 'abuse@tafula.top']);
        user_delete_unverified((int)$taken['id']);
    }
    if (!login_token_use((int)$t['id'])) return tx('Dieser Link ist nicht mehr gültig.');
    db_exec('UPDATE users SET email = ? WHERE id = ?', [$old, (int)$u['id']]);
    user_set_password((int)$u['id'], $pw);
    wb_sessions_revoke_user((int)$u['id']);
    tokens_revoke_user((int)$u['id'], 'password_reset');
    db_exec('DELETE FROM known_devices WHERE user_id = ?', [(int)$u['id']]);
    db_exec("UPDATE login_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL", [wb_now(), (int)$u['id']]);
    audit_log('account.email_revert', 'user', (int)$u['id'], [], null, (int)$u['id'], 'self');
    return null;
}

/* ---------- Entfernen der zweiten Stufe durch das Personal (72 Stunden) ---------- */

function account_totp_removal_start(array $target): bool {
    if (!user_has_totp($target) || !empty($target['totp_removal_at'])) return false;
    $at = wb_business_time() + ACC_TOTP_REMOVAL_HOURS * 3600;
    db_exec('UPDATE users SET totp_removal_at = ? WHERE id = ? AND totp_removal_at IS NULL', [wb_utc($at), (int)$target['id']]);
    $stop = login_token_create((int)$target['id'], 'totp_removal_stop', (ACC_TOTP_REMOVAL_HOURS + 24) * 3600);
    account_notify($target, tx('Die zweite Stufe deines Kontos bei {app} wird entfernt', ['app' => wb_app_name()]),
        tx("Für dein Konto wurde verlangt, die Anmeldung mit der Authenticator-App zu entfernen, zum Beispiel weil du App und Wiederherstellungscodes verloren hast. Das wird in {hours} Stunden wirksam.\n\nWenn du das nicht verlangt hast, stoppe es mit diesem Link:\n\n{url}",
            ['hours' => ACC_TOTP_REMOVAL_HOURS, 'url' => account_link('/account/keep-totp', $stop)]));
    audit_log('totp_removal.start', 'user', (int)$target['id'], ['effective_at' => wb_utc($at)]);
    user_reload((int)$target['id']);
    return true;
}

function account_totp_removal_stop(string $rawToken): bool {
    $t = login_token_find($rawToken, 'totp_removal_stop');
    if (!$t || !login_token_use((int)$t['id'])) return false;
    $n = db_exec('UPDATE users SET totp_removal_at = NULL WHERE id = ? AND totp_removal_at IS NOT NULL', [(int)$t['user_id']]);
    if ($n) audit_log('totp_removal.stop', 'user', (int)$t['user_id'], [], null, (int)$t['user_id'], 'self');
    user_reload((int)$t['user_id']);
    return $n === 1;
}

/** Macht eine fällige Entfernung wirksam (bei der Anmeldung und im Cronjob). */
function account_totp_removal_apply(array $u): bool {
    if (empty($u['totp_removal_at']) || wb_ts((string)$u['totp_removal_at']) > wb_business_time()) return false;
    totp_disable((int)$u['id']);
    wb_sessions_revoke_user((int)$u['id']);
    db_exec("UPDATE login_tokens SET used_at = ? WHERE user_id = ? AND purpose = 'totp_removal_stop' AND used_at IS NULL", [wb_now(), (int)$u['id']]);
    audit_log('totp_removal.effective', 'user', (int)$u['id'], [], null, null, 'system');
    account_notify($u, tx('Die zweite Stufe deines Kontos bei {app} wurde entfernt', ['app' => wb_app_name()]),
        tx('Die Anmeldung mit der Authenticator-App ist für dein Konto jetzt ausgeschaltet. Richte sie in deinem Konto wieder ein.'));
    return true;
}

/* ---------- Aufräumen (Cronjob) ---------- */

function accounts_cleanup(): array {
    $out = ['unverified' => 0, 'totp_removed' => 0];
    $limit = wb_utc(wb_business_time() - ACC_UNVERIFIED_DAYS * 86400);
    foreach (db_all('SELECT id FROM users WHERE email_verified_at IS NULL AND created_at < ? AND id <> 1 LIMIT 200', [$limit]) as $r) {
        user_delete_unverified((int)$r['id']);
        $out['unverified']++;
    }
    foreach (db_all('SELECT * FROM users WHERE totp_removal_at IS NOT NULL AND totp_removal_at <= ?', [wb_utc(wb_business_time())]) as $u) {
        if (account_totp_removal_apply(user_normalize($u))) $out['totp_removed']++;
    }
    $old = wb_utc(time() - 30 * 86400);
    db_exec('DELETE FROM sessions WHERE (revoked_at IS NOT NULL AND revoked_at < ?) OR expires_at < ?', [$old, $old]);
    db_exec('DELETE FROM login_tokens WHERE expires_at < ?', [$old]);
    db_exec('DELETE FROM known_devices WHERE expires_at < ?', [wb_now()]);
    db_exec('DELETE FROM rate_limits WHERE minute < ?', [intdiv(time(), 60) - 61]);
    $out['oauth_clients'] = oauth_cleanup()['clients'];
    return $out;
}

/* ---------- Zugangscode der Testinstanz (Abschnitt 17) ---------- */

/** Stufe 1 schützt Anmeldung, Registrierung, Testrollen und Sammelpostfach; Stufe 2 die Testrollen mit Systemrolle. */
function gate_required(): bool {
    return wb_instance() === 'test';
}

function gate_ok(int $level = 1): bool {
    if (!gate_required()) return true;
    $v = wb_signed_read('gate', wb_cookie_get('tafula_g' . $level));
    return $v !== null && $v === 'gate' . $level . '|' . substr((string)setting_get('test_gate' . $level . '_hash', ''), -12);
}

function gate_enter(int $level, string $code): bool {
    $hash = (string)setting_get('test_gate' . $level . '_hash', '');
    if ($hash === '' || brake_count('gate|' . wb_client_net(), 15) >= 10) return false;
    if (!password_verify(trim($code), $hash)) { brake_hit('gate|' . wb_client_net()); return false; }
    // An den Hash gebunden: Ein neuer Code macht alle gemerkten Zugänge ungültig.
    wb_cookie_set('tafula_g' . $level, wb_signed_make('gate', 'gate' . $level . '|' . substr($hash, -12), 30 * 86400), time() + 30 * 86400);
    return true;
}

function gate_set_code(int $level, string $code): void {
    setting_set('test_gate' . $level . '_hash', password_hash(trim($code), PASSWORD_DEFAULT));
}
