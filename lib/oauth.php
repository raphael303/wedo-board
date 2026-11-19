<?php
declare(strict_types=1);

/*
 * Autorisierungsserver und Tokens (Nutzerverwaltung U1b, Konzept Abschnitt 8).
 *
 * Tafula ist Autorisierungsserver nach OAuth 2.1 für seine Connectoren (MCP-Autorisierung 2025-11-25):
 * Autorisierungscode mit PKCE (S256), selbständige Registrierung öffentlicher Clients (RFC 7591), Metadaten
 * (RFC 8414 und RFC 9728), Bindung des Tokens an die Adresse des Connectors (RFC 8707), Kennzeichnung des
 * Ausstellers (RFC 9207), Widerruf (RFC 7009), kurzlebige Zugriffstokens mit Erneuerungstokens, die bei jeder
 * Benutzung ausgetauscht werden.
 *
 * Drei Arten von Tokens, alle nur als Hash gespeichert, alle über wb_auth_from_token() (lib/auth.php):
 *   tfa_…  Zugriffstoken einer OAuth-Verbindung; gilt nur am Connector, für den die Verbindung erteilt wurde.
 *   tfp_…  persönliches Token für eigene Skripte; gilt nur an /api/ (Header Authorization: Bearer).
 *   (48 Hex-Zeichen) der frühere api_token, Zweck «legacy_connector»; gilt nur an /mcp/<token>, mit Ablauf.
 * Jede dieser Anmeldungen hat kind «token»: nie Systemrechte, nie Rückmeldungen (lib/rights.php).
 */

const OAUTH_SCOPE = 'content';
const OAUTH_CODE_TTL = 120;        // Autorisierungscode: zwei Minuten
const OAUTH_REFRESH_GRACE = 30;    // ein ausgetauschter Erneuerungstoken gilt noch so viele Sekunden (Wiederholung)
const API_TOKEN_MAX = 20;          // persönliche Tokens pro Konto
const API_TOKEN_DAYS = [30, 90, 365];

class OAuthError extends RuntimeException {
    public function __construct(public string $error, string $description = '', public int $status = 400) {
        parent::__construct($description !== '' ? $description : $error);
    }
}

/* ---------- Connector-Adressen (pro Arbeitsbereich eine) ---------- */

/** Adresse des Connectors eines Arbeitsbereichs (null bedeutet «Privat»). */
function mcp_resource_url(?int $orgId): ?string {
    if ($orgId === null) return wb_base_url() . '/mcp/private';
    $o = org_by_id($orgId);
    if (!$o || $o['type'] !== 'school' || $o['disabled_at'] !== null) return null;
    return wb_base_url() . '/mcp/o/' . $o['okey'];
}

/** Zerlegt eine Connector-Adresse: ['org_id' => ?int, 'url' => kanonische Adresse] oder null. */
function mcp_resource_parse(string $url): ?array {
    $url = rtrim(trim($url), '/');
    $base = wb_base_url();
    if ($url === $base . '/mcp/private') return ['org_id' => null, 'url' => $url];
    if (preg_match('~^' . preg_quote($base, '~') . '/mcp/o/([a-z0-9]{16})$~', $url, $m)) {
        $o = db_row("SELECT id FROM orgs WHERE okey = ? AND type = 'school' AND deleted_at IS NULL AND disabled_at IS NULL", [$m[1]]);
        return $o ? ['org_id' => (int)$o['id'], 'url' => $url] : null;
    }
    return null;
}

function mcp_workspace_label(?int $orgId): string {
    return $orgId === null ? 'privat' : (string)(org_by_id($orgId)['name'] ?? '');
}

/** Name, unter dem sich der Connector meldet («Tafula – privat», «Tafula – OSZ»). */
function mcp_server_name(?int $orgId): string {
    return wb_app_name() . ' – ' . mcp_workspace_label($orgId);
}

function oauth_issuer(): string {
    return wb_base_url();
}

/** Adresse der Metadaten eines Connectors (RFC 9728, Pfad des Connectors hinter dem well-known-Teil). */
function mcp_resource_metadata_url(string $resource): string {
    return wb_base_url() . '/.well-known/oauth-protected-resource' . substr($resource, strlen(wb_base_url()));
}

/* ---------- Rücksprungadressen ---------- */

function oauth_is_loopback_uri(string $uri): bool {
    $p = parse_url($uri);
    return $p && strtolower((string)($p['scheme'] ?? '')) === 'http' && in_array(strtolower((string)($p['host'] ?? '')), ['localhost', '127.0.0.1', '[::1]'], true);
}

/** https überall; http nur für die eigene Maschine (RFC 8252, lokale Programme wie Claude Code). Kein Fragment. */
function oauth_redirect_uri_ok(string $uri): bool {
    if ($uri === '' || strlen($uri) > 500 || preg_match('/[\s#<>"\'\\\\`{}|^]/', $uri) || preg_match('/[^\x21-\x7e]/', $uri)) return false;
    $p = parse_url($uri);
    if (!$p || empty($p['scheme']) || empty($p['host']) || isset($p['user']) || isset($p['pass'])) return false;
    if (strtolower($p['scheme']) === 'https') return true;
    return oauth_is_loopback_uri($uri);
}

/**
 * Die angegebene Adresse muss genau einer registrierten entsprechen. Einzige Ausnahme nach RFC 8252: Bei
 * Adressen der eigenen Maschine zählt der Port nicht, weil lokale Programme ihn bei jedem Start neu wählen.
 */
function oauth_redirect_match(array $registered, string $given): bool {
    foreach ($registered as $r) {
        if (!is_string($r)) continue;
        if (hash_equals($r, $given)) return true;
        if (oauth_is_loopback_uri($r) && oauth_is_loopback_uri($given)) {
            $a = parse_url($r); $b = parse_url($given);
            if (strtolower($a['host']) === strtolower($b['host']) && ($a['path'] ?? '') === ($b['path'] ?? '') && ($a['query'] ?? '') === ($b['query'] ?? '')) return true;
        }
    }
    return false;
}

function oauth_redirect_host(string $uri): string {
    $p = parse_url($uri);
    return strtolower((string)($p['host'] ?? '')) . (isset($p['port']) ? ':' . $p['port'] : '');
}

/* ---------- Clients (selbständige Registrierung, RFC 7591) ---------- */

function oauth_client_register(array $in): array {
    $ip = wb_client_ip();
    if (brake_exceeded('oauth-reg|' . $ip, wb_setting_int('oauth_register_per_ip_hour', 10), 60)) throw new OAuthError('too_many_requests', 'Zu viele Registrierungen. Bitte später wieder versuchen.', 429);
    $day = (int)(db_row('SELECT COUNT(*) AS n FROM oauth_clients WHERE created_at > ?', [wb_utc(time() - 86400)])['n'] ?? 0);
    if ($day >= wb_setting_int('oauth_register_per_day', 200)) throw new OAuthError('too_many_requests', 'Zu viele Registrierungen. Bitte später wieder versuchen.', 429);

    $uris = $in['redirect_uris'] ?? null;
    if (!is_array($uris) || !$uris || count($uris) > 5) throw new OAuthError('invalid_redirect_uri', 'redirect_uris: eine bis fünf Adressen.');
    $clean = [];
    foreach ($uris as $u) {
        if (!is_string($u) || !oauth_redirect_uri_ok($u)) throw new OAuthError('invalid_redirect_uri', 'Jede Rücksprungadresse muss mit https beginnen (http nur für localhost) und darf kein Fragment enthalten.');
        $clean[] = $u;
    }
    $method = $in['token_endpoint_auth_method'] ?? 'none';
    if (!is_string($method)) throw new OAuthError('invalid_client_metadata', 'token_endpoint_auth_method ist ungültig.');
    foreach ((array)($in['grant_types'] ?? []) as $g) {
        if (!in_array($g, ['authorization_code', 'refresh_token'], true)) throw new OAuthError('invalid_client_metadata', 'Unterstützt werden nur authorization_code und refresh_token.');
    }
    foreach ((array)($in['response_types'] ?? []) as $r) {
        if ($r !== 'code') throw new OAuthError('invalid_client_metadata', 'Unterstützt wird nur der response_type code.');
    }
    $name = wb_clean_text(is_string($in['client_name'] ?? null) ? $in['client_name'] : '', 80);
    $name = trim(preg_replace('/[\x00-\x1f\x7f<>]/u', '', $name) ?? '');
    $clientId = 'tc_' . wb_random_token(18);
    $now = wb_now();
    db_insert('INSERT INTO oauth_clients (client_id, name, redirect_uris_json, created_ip, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)',
        [$clientId, $name, json_encode(array_values($clean), JSON_UNESCAPED_SLASHES), $ip, $now, wb_utc(time() + wb_setting_int('oauth_client_unused_hours', 24) * 3600)]);
    // Öffentlicher Client: kein Geheimnis, die Sicherheit kommt aus PKCE und der gebundenen Rücksprungadresse.
    return ['client_id' => $clientId, 'client_id_issued_at' => time(), 'client_name' => $name, 'redirect_uris' => array_values($clean),
        'token_endpoint_auth_method' => 'none', 'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code'], 'scope' => OAUTH_SCOPE];
}

function oauth_client_find(string $clientId): ?array {
    if ($clientId === '' || strlen($clientId) > 64) return null;
    $c = db_row('SELECT * FROM oauth_clients WHERE client_id = ? AND disabled_at IS NULL AND expires_at > ?', [$clientId, wb_now()]);
    if (!$c) return null;
    $c['id'] = (int)$c['id'];
    $c['redirect_uris'] = array_values(array_filter((array)json_decode((string)$c['redirect_uris_json'], true), 'is_string'));
    return $c;
}

function oauth_client_touch(int $id): void {
    db_exec('UPDATE oauth_clients SET last_used_at = ?, expires_at = ? WHERE id = ?', [wb_now(), wb_utc(time() + wb_setting_int('oauth_client_idle_days', 90) * 86400), $id]);
}

/* ---------- Autorisierung ---------- */

/**
 * Prüft die Parameter von /oauth/authorize. Fehler vor der Prüfung der Rücksprungadresse dürfen nie zu einer
 * Weiterleitung führen ($e->status 400 mit redirect = false); danach geht der Fehler an den Client zurück.
 * Rückgabe: ['client', 'redirect_uri', 'state', 'challenge', 'resource' => ?array, 'error' => ?OAuthError].
 */
function oauth_authorize_check(array $q): array {
    $str = fn(string $k): string => is_string($q[$k] ?? null) ? (string)$q[$k] : '';
    $client = oauth_client_find($str('client_id'));
    if (!$client) throw new OAuthError('invalid_client', tx('Dieses Programm ist bei {app} nicht (mehr) registriert. Beginne die Verbindung im Programm noch einmal.', ['app' => wb_app_name()]));
    $redirect = $str('redirect_uri');
    if ($redirect === '' && count($client['redirect_uris']) === 1) $redirect = $client['redirect_uris'][0];
    if ($redirect === '' || !oauth_redirect_uri_ok($redirect) || !oauth_redirect_match($client['redirect_uris'], $redirect)) {
        throw new OAuthError('invalid_redirect_uri', tx('Die Rücksprungadresse dieser Anfrage stimmt nicht mit der überein, die das Programm registriert hat. Die Verbindung wurde nicht hergestellt.'));
    }
    $out = ['client' => $client, 'redirect_uri' => $redirect, 'state' => mb_substr($str('state'), 0, 1000), 'challenge' => $str('code_challenge'), 'resource' => null, 'error' => null];
    if ($str('response_type') !== 'code') $out['error'] = new OAuthError('unsupported_response_type', 'Nur response_type=code.');
    elseif (!preg_match('/^[A-Za-z0-9_-]{43}$/', $out['challenge']) || ($str('code_challenge_method') !== 'S256')) $out['error'] = new OAuthError('invalid_request', 'PKCE mit code_challenge_method=S256 ist Pflicht.');
    elseif ($str('resource') !== '') {
        $out['resource'] = mcp_resource_parse($str('resource'));
        if ($out['resource'] === null) $out['error'] = new OAuthError('invalid_target', 'Unbekannte Adresse in resource.');
    }
    return $out;
}

/** Rücksprungadresse mit Antwortparametern (immer mit iss nach RFC 9207). */
function oauth_redirect_with(string $redirectUri, array $params): string {
    $params = array_filter($params, fn($v) => $v !== null && $v !== '') + ['iss' => oauth_issuer()];
    return $redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function oauth_code_create(array $client, int $userId, ?int $orgId, string $resource, string $redirectUri, string $challenge): string {
    $raw = 'tfc_' . wb_random_token(32);
    db_insert('INSERT INTO oauth_codes (code_hash, client_id, user_id, org_id, redirect_uri, code_challenge, resource, scope, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [wb_token_hash($raw), $client['id'], $userId, $orgId, $redirectUri, $challenge, $resource, OAUTH_SCOPE, wb_now(), wb_utc(time() + OAUTH_CODE_TTL)]);
    return $raw;
}

/* ---------- Verbindungen und Tokens ---------- */

function oauth_grant_revoke(int $grantId, string $reason): bool {
    $n = db_exec('UPDATE oauth_grants SET revoked_at = ?, revoked_reason = ? WHERE id = ? AND revoked_at IS NULL', [wb_now(), mb_substr($reason, 0, 32), $grantId]);
    db_exec('UPDATE oauth_tokens SET revoked_at = ? WHERE grant_id = ? AND revoked_at IS NULL', [wb_now(), $grantId]);
    return $n > 0;
}

/** Gibt ein neues Paar aus Zugriffs- und Erneuerungstoken aus (Antwort des Token-Endpunkts). */
function oauth_issue_tokens(int $grantId): array {
    $access = 'tfa_' . wb_random_token(32);
    $refresh = 'tfr_' . wb_random_token(32);
    $ttl = max(60, wb_setting_int('oauth_access_minutes', 60) * 60);
    $now = wb_now();
    db_exec("INSERT INTO oauth_tokens (grant_id, kind, token_hash, created_at, expires_at) VALUES (?, 'access', ?, ?, ?), (?, 'refresh', ?, ?, ?)",
        [$grantId, wb_token_hash($access), $now, wb_utc(time() + $ttl), $grantId, wb_token_hash($refresh), $now, wb_utc(time() + max(1, wb_setting_int('oauth_refresh_days', 60)) * 86400)]);
    db_exec('UPDATE oauth_grants SET last_used_at = ? WHERE id = ?', [$now, $grantId]);
    return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => $ttl, 'refresh_token' => $refresh, 'scope' => OAUTH_SCOPE];
}

/** Konto aktiv und (bei einer Schule) noch Lehrperson dort? Sonst wirkt keine Verbindung und kein Token mehr. */
function oauth_subject_ok(int $userId, ?int $orgId): ?array {
    $u = user_by_id($userId);
    if (!$u || !user_is_active($u) || empty($u['email_verified_at'])) return null;
    if ($orgId !== null && wb_valid_workspace($u, $orgId) === null) return null;
    return $u;
}

function oauth_verify_pkce(string $verifier, string $challenge): bool {
    if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier)) return false;
    return hash_equals($challenge, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='));
}

/** grant_type=authorization_code */
function oauth_token_from_code(array $p): array {
    $str = fn(string $k): string => is_string($p[$k] ?? null) ? (string)$p[$k] : '';
    $client = oauth_client_find($str('client_id'));
    if (!$client) throw new OAuthError('invalid_client', 'Unbekannter Client.', 401);
    $code = $str('code') !== '' ? db_row('SELECT * FROM oauth_codes WHERE code_hash = ?', [wb_token_hash($str('code'))]) : null;
    if (!$code || (int)$code['client_id'] !== $client['id']) throw new OAuthError('invalid_grant', 'Der Code ist ungültig.');
    if ($code['used_at'] !== null) {
        // Ein zweites Einlösen deutet auf einen abgefangenen Code: Die daraus entstandene Verbindung endet.
        if ($code['grant_id'] !== null) oauth_grant_revoke((int)$code['grant_id'], 'code_reuse');
        throw new OAuthError('invalid_grant', 'Der Code wurde schon benutzt.');
    }
    if (wb_ts((string)$code['expires_at']) < time()) throw new OAuthError('invalid_grant', 'Der Code ist abgelaufen.');
    if ($str('redirect_uri') !== '' && !hash_equals((string)$code['redirect_uri'], $str('redirect_uri'))) throw new OAuthError('invalid_grant', 'redirect_uri stimmt nicht.');
    if ($str('resource') !== '' && rtrim($str('resource'), '/') !== (string)$code['resource']) throw new OAuthError('invalid_target', 'resource stimmt nicht.');
    if (!oauth_verify_pkce($str('code_verifier'), (string)$code['code_challenge'])) throw new OAuthError('invalid_grant', 'code_verifier stimmt nicht.');
    if (db_exec('UPDATE oauth_codes SET used_at = ? WHERE id = ? AND used_at IS NULL', [wb_now(), (int)$code['id']]) !== 1) throw new OAuthError('invalid_grant', 'Der Code wurde schon benutzt.');
    $org = $code['org_id'] !== null ? (int)$code['org_id'] : null;
    $u = oauth_subject_ok((int)$code['user_id'], $org);
    if (!$u) throw new OAuthError('invalid_grant', 'Das Konto kann diese Verbindung nicht mehr benutzen.');
    $grantId = db_insert('INSERT INTO oauth_grants (client_id, user_id, org_id, resource, scope, created_at) VALUES (?, ?, ?, ?, ?, ?)',
        [$client['id'], (int)$u['id'], $org, (string)$code['resource'], OAUTH_SCOPE, wb_now()]);
    db_exec('UPDATE oauth_codes SET grant_id = ? WHERE id = ?', [$grantId, (int)$code['id']]);
    oauth_client_touch($client['id']);
    audit_log('oauth.grant', 'oauth_grant', $grantId, ['client' => $client['name'], 'redirect' => oauth_redirect_host((string)$code['redirect_uri'])], $org, (int)$u['id'], 'self');
    account_notify($u, tx('Neue Verbindung zu deinem Konto bei {app}', ['app' => wb_app_name()]),
        tx("Das Programm «{client}» wurde soeben mit deinem Konto verbunden (Arbeitsbereich: {ws}). Es kann deine eigenen Boards und Aktivitäten in diesem Arbeitsbereich lesen und ändern.\n\nWenn du das nicht warst, widerrufe die Verbindung sofort und ändere dein Passwort:\n\n{url}",
            ['client' => $client['name'] !== '' ? $client['name'] : tx('ohne Namen'), 'ws' => $org === null ? tx('Privat') : mcp_workspace_label($org), 'url' => wb_base_url() . '/account#verbindungen']));
    return oauth_issue_tokens($grantId);
}

/** grant_type=refresh_token: tauscht den Erneuerungstoken aus. */
function oauth_token_refresh(array $p): array {
    $str = fn(string $k): string => is_string($p[$k] ?? null) ? (string)$p[$k] : '';
    $raw = $str('refresh_token');
    $t = $raw !== '' ? db_row("SELECT t.*, g.client_id AS g_client, g.user_id, g.org_id, g.resource, g.revoked_at AS g_revoked FROM oauth_tokens t JOIN oauth_grants g ON g.id = t.grant_id WHERE t.token_hash = ? AND t.kind = 'refresh'", [wb_token_hash($raw)]) : null;
    if (!$t) throw new OAuthError('invalid_grant', 'Der Erneuerungstoken ist ungültig.');
    $grantId = (int)$t['grant_id'];
    if ($str('client_id') !== '') {
        $client = oauth_client_find($str('client_id'));
        if (!$client || $client['id'] !== (int)$t['g_client']) throw new OAuthError('invalid_grant', 'Der Erneuerungstoken gehört nicht zu diesem Client.');
    }
    if ($t['g_revoked'] !== null || $t['revoked_at'] !== null) throw new OAuthError('invalid_grant', 'Die Verbindung wurde widerrufen.');
    if (wb_ts((string)$t['expires_at']) < time()) throw new OAuthError('invalid_grant', 'Der Erneuerungstoken ist abgelaufen.');
    if ($t['used_at'] !== null && wb_ts((string)$t['used_at']) < time() - OAUTH_REFRESH_GRACE) {
        // Ein schon ausgetauschter Token taucht wieder auf: Er wurde kopiert. Die ganze Verbindung endet.
        oauth_grant_revoke($grantId, 'refresh_reuse');
        throw new OAuthError('invalid_grant', 'Der Erneuerungstoken wurde schon benutzt; die Verbindung ist widerrufen.');
    }
    if ($str('resource') !== '' && rtrim($str('resource'), '/') !== (string)$t['resource']) throw new OAuthError('invalid_target', 'resource stimmt nicht.');
    $org = $t['org_id'] !== null ? (int)$t['org_id'] : null;
    if (!oauth_subject_ok((int)$t['user_id'], $org)) {
        oauth_grant_revoke($grantId, 'subject');
        throw new OAuthError('invalid_grant', 'Das Konto kann diese Verbindung nicht mehr benutzen.');
    }
    db_exec('UPDATE oauth_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL', [wb_now(), (int)$t['id']]);
    oauth_client_touch((int)$t['g_client']);
    return oauth_issue_tokens($grantId);
}

/** RFC 7009: widerruft die Verbindung, zu der der Token gehört. Unbekannte Tokens sind kein Fehler. */
function oauth_revoke_token(string $raw): void {
    if ($raw === '') return;
    $t = db_row('SELECT grant_id FROM oauth_tokens WHERE token_hash = ?', [wb_token_hash($raw)]);
    if ($t && oauth_grant_revoke((int)$t['grant_id'], 'client')) audit_log('oauth.revoke', 'oauth_grant', (int)$t['grant_id'], ['by' => 'client'], null, null, 'client');
}

/** Zugriffstoken am Connector $resource: Anmeldung der Art «token» oder null. */
function oauth_access_auth(string $raw, string $resource): ?array {
    $t = db_row("SELECT t.id, t.expires_at, t.revoked_at, g.id AS grant_id, g.user_id, g.org_id, g.resource, g.revoked_at AS g_revoked, g.last_used_at, g.client_id
        FROM oauth_tokens t JOIN oauth_grants g ON g.id = t.grant_id WHERE t.token_hash = ? AND t.kind = 'access'", [wb_token_hash($raw)]);
    if (!$t || $t['revoked_at'] !== null || $t['g_revoked'] !== null || wb_ts((string)$t['expires_at']) < time()) return null;
    if (!hash_equals((string)$t['resource'], $resource)) return null;
    $org = $t['org_id'] !== null ? (int)$t['org_id'] : null;
    // Bei jedem Aufruf neu: Konto aktiv, Zugehörigkeit zum Arbeitsbereich besteht (ab U3 auch der Plan).
    $u = oauth_subject_ok((int)$t['user_id'], $org);
    if (!$u) return null;
    if (wb_ts($t['last_used_at']) < time() - 300) {
        db_exec('UPDATE oauth_grants SET last_used_at = ? WHERE id = ?', [wb_now(), (int)$t['grant_id']]);
        db_exec('UPDATE users SET last_active_at = ? WHERE id = ?', [wb_now(), (int)$u['id']]);
    }
    return ['kind' => 'token', 'via' => 'oauth', 'user' => $u, 'session' => null, 'org_id' => $org, 'grant_id' => (int)$t['grant_id']];
}

function oauth_grants_list(int $userId): array {
    return db_all("SELECT g.id, g.org_id, g.resource, g.created_at, g.last_used_at, c.name AS client_name, c.redirect_uris_json
        FROM oauth_grants g JOIN oauth_clients c ON c.id = g.client_id
        WHERE g.user_id = ? AND g.revoked_at IS NULL AND EXISTS (SELECT 1 FROM oauth_tokens t WHERE t.grant_id = g.id AND t.revoked_at IS NULL AND t.expires_at > ?)
        ORDER BY g.created_at DESC LIMIT 100", [$userId, wb_now()]);
}

function oauth_grant_revoke_own(int $grantId, int $userId): bool {
    $g = db_row('SELECT id, org_id FROM oauth_grants WHERE id = ? AND user_id = ? AND revoked_at IS NULL', [$grantId, $userId]);
    if (!$g || !oauth_grant_revoke($grantId, 'user')) return false;
    audit_log('oauth.revoke', 'oauth_grant', $grantId, ['by' => 'user'], $g['org_id'] !== null ? (int)$g['org_id'] : null, $userId, 'self');
    return true;
}

/* ---------- Persönliche Tokens und der frühere api_token ---------- */

/** Legt ein Token an und gibt [Nummer, Klartext] zurück; der Klartext wird nur dieses eine Mal sichtbar. */
function api_token_create(int $userId, ?int $orgId, string $purpose, string $label, ?int $days, ?string $raw = null): array {
    $raw = $raw ?? 'tfp_' . wb_random_token(32);
    $id = db_insert('INSERT INTO api_tokens (user_id, org_id, purpose, label, token_hash, token_prefix, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$userId, $orgId, $purpose, wb_clean_text($label, 120), wb_token_hash($raw), substr($raw, 0, 8), wb_now(), $days !== null ? wb_utc(time() + $days * 86400) : null]);
    return [$id, $raw];
}

/** $context: «api» (persönliche Tokens) oder «legacy» (früherer api_token an /mcp/<token>). */
function api_token_auth(string $raw, string $context): ?array {
    if ($raw === '' || strlen($raw) > 200) return null;
    $t = db_row('SELECT * FROM api_tokens WHERE token_hash = ? AND revoked_at IS NULL', [wb_token_hash($raw)]);
    if (!$t || ($t['expires_at'] !== null && wb_ts((string)$t['expires_at']) < time())) return null;
    $want = ['api' => 'personal', 'legacy' => 'legacy_connector'][$context] ?? null;
    if ($want === null || $t['purpose'] !== $want) return null;
    $org = $t['org_id'] !== null ? (int)$t['org_id'] : null;
    $u = oauth_subject_ok((int)$t['user_id'], $org);
    if (!$u) return null;
    if (wb_ts($t['last_used_at']) < time() - 300) {
        db_exec('UPDATE api_tokens SET last_used_at = ? WHERE id = ?', [wb_now(), (int)$t['id']]);
        db_exec('UPDATE users SET last_active_at = ? WHERE id = ?', [wb_now(), (int)$u['id']]);
    }
    return ['kind' => 'token', 'via' => $want === 'personal' ? 'personal' : 'legacy', 'user' => $u, 'session' => null, 'org_id' => $org, 'api_token_id' => (int)$t['id']];
}

function api_tokens_list(int $userId): array {
    return db_all('SELECT id, org_id, purpose, label, token_prefix, created_at, last_used_at, expires_at FROM api_tokens WHERE user_id = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > ?) ORDER BY created_at DESC', [$userId, wb_now()]);
}

function api_token_revoke(int $id, int $userId): bool {
    $ok = db_exec('UPDATE api_tokens SET revoked_at = ? WHERE id = ? AND user_id = ? AND revoked_at IS NULL', [wb_now(), $id, $userId]) > 0;
    if ($ok) audit_log('token.revoke', 'api_token', $id, [], null, $userId, 'self');
    return $ok;
}

/**
 * Widerruft alle Verbindungen und Tokens eines Kontos (Zurücksetzen des Passworts, Rückgängigmachen der Adresse;
 * später Sperre und Löschung). Gibt die Anzahl zurück.
 */
function tokens_revoke_user(int $userId, string $reason): int {
    $n = 0;
    foreach (db_all('SELECT id FROM oauth_grants WHERE user_id = ? AND revoked_at IS NULL', [$userId]) as $g) {
        if (oauth_grant_revoke((int)$g['id'], $reason)) $n++;
    }
    $n += db_exec('UPDATE api_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [wb_now(), $userId]);
    if ($n > 0) audit_log('token.revoke_all', 'user', $userId, ['reason' => $reason, 'count' => $n], null, $userId, 'system');
    return $n;
}

/** Stündlich vom Cronjob: abgelaufene Codes, Tokens und unbenutzte Clients. */
function oauth_cleanup(): array {
    $now = wb_now();
    $old = wb_utc(time() - 7 * 86400);
    $out = ['clients' => 0];
    db_exec('DELETE FROM oauth_codes WHERE expires_at < ?', [wb_utc(time() - 86400)]);
    db_exec('DELETE FROM oauth_tokens WHERE expires_at < ?', [$old]);
    // Eine Verbindung ohne gültigen Erneuerungstoken ist zu Ende.
    db_exec("UPDATE oauth_grants g SET g.revoked_at = ?, g.revoked_reason = 'expired' WHERE g.revoked_at IS NULL AND g.created_at < ?
        AND NOT EXISTS (SELECT 1 FROM oauth_tokens t WHERE t.grant_id = g.id AND t.kind = 'refresh' AND t.revoked_at IS NULL AND t.expires_at > ?)", [$now, wb_utc(time() - 3600), $now]);
    // Clients verfallen: ohne Verbindung nach 24 Stunden, sonst 90 Tage nach der letzten Benutzung.
    foreach (db_all('SELECT c.id FROM oauth_clients c WHERE c.expires_at < ? AND NOT EXISTS (SELECT 1 FROM oauth_grants g WHERE g.client_id = c.id AND g.revoked_at IS NULL) LIMIT 500', [$now]) as $c) {
        $id = (int)$c['id'];
        db_exec('DELETE t FROM oauth_tokens t JOIN oauth_grants g ON g.id = t.grant_id WHERE g.client_id = ?', [$id]);
        db_exec('DELETE FROM oauth_grants WHERE client_id = ?', [$id]);
        db_exec('DELETE FROM oauth_codes WHERE client_id = ?', [$id]);
        db_exec('DELETE FROM oauth_clients WHERE id = ?', [$id]);
        $out['clients']++;
    }
    db_exec('DELETE FROM api_tokens WHERE (revoked_at IS NOT NULL AND revoked_at < ?) OR (expires_at IS NOT NULL AND expires_at < ?)', [wb_utc(time() - 30 * 86400), wb_utc(time() - 30 * 86400)]);
    return $out;
}
