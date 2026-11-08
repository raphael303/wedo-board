<?php
declare(strict_types=1);

/**
 * KI-Schicht (Etappe 5d). Die einzige Funktion, die Fachlogik aufruft, ist ai_structured(): Sie schickt
 * System- und Benutzertext an den Adapter des Anbieters, erzwingt eine strukturierte Antwort nach einem
 * JSON-Schema und liefert die geprüften Daten mit Token, Modell und Dauer zurück. Fehler kommen als
 * AiException mit einer von wenigen Fehlerarten (kind): invalid_key, rate_limit, overloaded, timeout,
 * invalid_response, network.
 *
 * Adapter liegen als lib/ai/<provider>.php mit der Funktion ai_<provider>_structured(...) und optional
 * ai_<provider>_models(string $apiKey): array. Gebaut sind anthropic und fake; OpenAI und Google müssen
 * ohne Umbau dazukommen können (gleiche Signatur, gleiches Schema, Feld provider in ai_credentials).
 */

class AiException extends RuntimeException {
    public string $kind;
    public function __construct(string $kind, string $message, ?Throwable $previous = null) {
        parent::__construct($message, 0, $previous);
        $this->kind = $kind;
    }
}

const AI_PROVIDERS = ['anthropic' => 'Anthropic', 'fake' => 'Fake (nur für Tests)'];
const AI_DEFAULT_MODEL = ['anthropic' => 'claude-sonnet-4-5', 'fake' => 'fake-1'];

const AI_DEFAULT_AUTO_PROMPT = "Ordne die Beiträge nach Themen, so wie eine Lehrperson sie nach einer Sammelrunde an der Wandtafel gruppieren würde. Fasse Beiträge zusammen, die denselben Gedanken ausdrücken, auch wenn sie verschieden formuliert sind. Bilde so wenige Bündel wie möglich und so viele wie nötig, meist drei bis sieben. Gib jedem Bündel einen kurzen, treffenden Titel und eine Zusammenfassung in einem Satz, die das Gemeinsame benennt.";

/* ---------- Aufruf ---------- */

/**
 * @param array $credential  Zeile aus ai_credentials mit entschlüsseltem 'api_key'.
 * @param array $schema      JSON-Schema (type object) für die Antwort.
 * @param array $options     tool_name, tool_description, max_tokens, timeout (Sekunden), model (überschreibt).
 * @return array{data: array, input_tokens: int, output_tokens: int, model: string, duration_ms: int}
 */
function ai_structured(array $credential, string $system, string $user, array $schema, array $options = []): array {
    $provider = (string)($credential['provider'] ?? '');
    $fn = 'ai_' . $provider . '_structured';
    if (!isset(AI_PROVIDERS[$provider]) || !function_exists($fn)) {
        throw new AiException('invalid_response', 'Unbekannter KI-Anbieter: ' . $provider);
    }
    $options += ['tool_name' => 'ergebnis', 'tool_description' => 'Strukturiertes Ergebnis.', 'max_tokens' => 4096, 'timeout' => 55];
    $start = hrtime(true);
    $result = $fn($credential, $system, $user, $schema, $options);
    $result['duration_ms'] = (int)((hrtime(true) - $start) / 1_000_000);
    if (!is_array($result['data'] ?? null)) throw new AiException('invalid_response', 'Die Antwort des Modells war nicht lesbar.');
    return $result;
}

/** Verständliche Meldung zu einer Fehlerart in der Sprache des Boards. */
function ai_error_message(AiException $e, string $lang): string {
    return t('ai_error_' . $e->kind, $lang);
}

/* ---------- Anbieter laden ---------- */

foreach (array_keys(AI_PROVIDERS) as $p) {
    require_once __DIR__ . '/' . $p . '.php';
}
require_once __DIR__ . '/organize.php';

/* ---------- Geheimnis und Verschlüsselung ---------- */

/**
 * Der Geheimwert app_secret aus config.php (Base64, 32 Byte). Fehlt er, wird er einmalig erzeugt und in
 * config.php eingetragen, wenn die Datei beschreibbar ist; sonst gibt ai_secret_missing_line() die Zeile aus.
 */
function ai_secret_key(): ?string {
    static $key = false;
    if ($key !== false) return $key;
    $raw = (string)wb_config('app_secret', '');
    $bin = $raw !== '' ? base64_decode($raw, true) : false;
    if ($bin !== false && strlen($bin) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) return $key = $bin;
    $file = WB_ROOT . '/config.php';
    $src = @file_get_contents($file);
    // Die Datei kann neuer sein als die geladene Konfiguration (OPcache): erst dort nachsehen, nie doppelt eintragen.
    if ($src !== false && preg_match("/'app_secret'\\s*=>\\s*'([^']+)'/", $src, $m)) {
        $bin = base64_decode($m[1], true);
        if ($bin !== false && strlen($bin) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) { $GLOBALS['wb_config']['app_secret'] = $m[1]; return $key = $bin; }
    }
    $bin = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $line = "    'app_secret' => '" . base64_encode($bin) . "',";
    if ($src !== false && is_writable($file) && preg_match('/\n\];\s*$/', $src)) {
        $new = preg_replace('/\n\];\s*$/', "\n    // Geheimwert für verschlüsselte KI-Schlüssel (automatisch erzeugt, nicht ändern).\n" . $line . "\n];\n", $src, 1);
        if ($new !== null && @file_put_contents($file, $new) !== false) {
            $GLOBALS['wb_config']['app_secret'] = base64_encode($bin);
            return $key = $bin;
        }
    }
    $GLOBALS['wb_app_secret_line'] = $line;
    return $key = null;
}

function ai_secret_missing_line(): ?string {
    if (ai_secret_key() !== null) return null;
    return $GLOBALS['wb_app_secret_line'] ?? null;
}

function ai_encrypt(string $plain): string {
    $key = ai_secret_key();
    if ($key === null) throw new RuntimeException('Kein app_secret in config.php.');
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
}

function ai_decrypt(string $enc): ?string {
    $key = ai_secret_key();
    if ($key === null) return null;
    $bin = base64_decode($enc, true);
    if ($bin === false || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
    $nonce = substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open(substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
    return $plain === false ? null : $plain;
}

/** Maskierte Darstellung: Anfang und die letzten vier Zeichen. */
function ai_mask_key(string $key): string {
    $len = mb_strlen($key);
    if ($len <= 8) return str_repeat('•', $len);
    $prefix = str_starts_with($key, 'sk-ant-') ? 'sk-ant-' : mb_substr($key, 0, 3);
    return $prefix . '…' . mb_substr($key, -4);
}

/* ---------- Zugänge ---------- */

function ai_credential_row_public(array $c): array {
    $plain = ai_decrypt((string)$c['api_key_enc']);
    return [
        'id' => (int)$c['id'],
        'provider' => $c['provider'],
        'provider_label' => AI_PROVIDERS[$c['provider']] ?? $c['provider'],
        'label' => $c['label'],
        'model' => $c['model'],
        'masked_key' => $plain !== null ? ai_mask_key($plain) : '(nicht entschlüsselbar)',
        'is_default' => (bool)$c['is_default'],
        'last_test_at' => $c['last_test_at'],
        'last_test_ok' => $c['last_test_ok'] === null ? null : (bool)$c['last_test_ok'],
        'created_at' => $c['created_at'],
        'updated_at' => $c['updated_at'],
    ];
}

/** Der Standardzugang des Benutzers mit entschlüsseltem Schlüssel, oder null. */
function ai_credential_default(?int $userId = null): ?array {
    $userId = $userId ?? (int)(wb_current_user()['id'] ?? 1);
    $c = db_row('SELECT * FROM ai_credentials WHERE user_id = ? ORDER BY is_default DESC, id DESC LIMIT 1', [$userId]);
    if (!$c) return null;
    $c['api_key'] = ai_decrypt((string)$c['api_key_enc']);
    if ($c['api_key'] === null) return null;
    return $c;
}

/** Speichert oder ersetzt den Zugang eines Anbieters; ein leerer Schlüssel behält den gespeicherten. */
function ai_credential_save(string $provider, ?string $apiKey, ?string $model, ?string $label = null, ?int $userId = null): array {
    if (!isset(AI_PROVIDERS[$provider])) throw new InvalidArgumentException('Unbekannter Anbieter.');
    $userId = $userId ?? (int)(wb_current_user()['id'] ?? 1);
    $now = wb_now();
    $existing = db_row('SELECT * FROM ai_credentials WHERE user_id = ? AND provider = ? ORDER BY id DESC LIMIT 1', [$userId, $provider]);
    $apiKey = trim((string)$apiKey);
    $model = wb_clean_text($model ?? '', 120);
    if ($model === '') $model = $existing['model'] ?? AI_DEFAULT_MODEL[$provider];
    $label = wb_clean_text($label ?? ($existing['label'] ?? AI_PROVIDERS[$provider]), 80);
    if ($existing) {
        $fields = ['model = ?', 'label = ?', 'updated_at = ?', 'is_default = 1'];
        $params = [$model, $label, $now];
        if ($apiKey !== '') { $fields[] = 'api_key_enc = ?'; $params[] = ai_encrypt($apiKey); $fields[] = 'last_test_at = NULL'; $fields[] = 'last_test_ok = NULL'; }
        $params[] = (int)$existing['id'];
        db_exec('UPDATE ai_credentials SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
        $id = (int)$existing['id'];
    } else {
        if ($apiKey === '') throw new InvalidArgumentException('API-Schlüssel fehlt.');
        $id = db_insert('INSERT INTO ai_credentials (user_id, provider, label, api_key_enc, model, is_default, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?)',
            [$userId, $provider, $label, ai_encrypt($apiKey), $model, $now, $now]);
    }
    db_exec('UPDATE ai_credentials SET is_default = 0 WHERE user_id = ? AND id <> ?', [$userId, $id]);
    return db_row('SELECT * FROM ai_credentials WHERE id = ?', [$id]);
}

function ai_credential_delete(int $id, ?int $userId = null): void {
    $userId = $userId ?? (int)(wb_current_user()['id'] ?? 1);
    db_exec('DELETE FROM ai_credentials WHERE id = ? AND user_id = ?', [$id, $userId]);
}

/** Prüft die Verbindung über die Modell-Liste des Anbieters und merkt sich das Ergebnis. */
function ai_credential_test(array $credential): array {
    $ok = false; $message = ''; $models = [];
    try {
        $models = ai_models($credential);
        $ok = true;
        $message = count($models) . ' Modelle verfügbar.';
    } catch (AiException $e) {
        $message = t('ai_error_' . $e->kind, 'de');
    }
    db_exec('UPDATE ai_credentials SET last_test_at = ?, last_test_ok = ? WHERE id = ?', [wb_now(), $ok ? 1 : 0, (int)$credential['id']]);
    if ($ok) setting_set('ai_models_' . $credential['provider'], json_encode(['at' => time(), 'models' => $models], JSON_UNESCAPED_UNICODE));
    return ['ok' => $ok, 'message' => $message, 'models' => $models];
}

/** Modell-Liste des Anbieters: [{id, name}]. */
function ai_models(array $credential): array {
    $fn = 'ai_' . $credential['provider'] . '_models';
    if (!function_exists($fn)) return [];
    return $fn((string)$credential['api_key']);
}

/** Zwischengespeicherte Modell-Liste (aus dem letzten Test), damit keine Modellnamen im Code veralten. */
function ai_models_cached(string $provider): array {
    $raw = setting_get('ai_models_' . $provider);
    $d = $raw ? json_decode($raw, true) : null;
    return is_array($d['models'] ?? null) ? $d['models'] : [];
}

/* ---------- Schalter und Prompts ---------- */

function ai_enabled(): bool {
    return setting_get('ai_enabled', '0') === '1';
}

function ai_auto_prompt(): string {
    $p = setting_get('ai_auto_prompt');
    return $p !== null && trim($p) !== '' ? $p : AI_DEFAULT_AUTO_PROMPT;
}

/** Ist die KI für dieses Board nutzbar? Liefert null oder einen Grund-Code: disabled, no_credential, board_off, forbidden. */
function ai_unavailable_reason(array $board): ?string {
    if (!wb_can('ai.use', $board)) return 'forbidden';
    if (!ai_enabled()) return 'disabled';
    if (ai_credential_default((int)$board['owner_user_id']) === null) return 'no_credential';
    if (empty($board['settings']['ai'])) return 'board_off';
    return null;
}

/* ---------- Protokoll der Aufrufe ---------- */

function ai_usage_log(?int $boardId, string $feature, array $credential, ?array $result, ?Throwable $error, ?int $userId = null): int {
    return db_insert('INSERT INTO ai_usage (user_id, board_id, feature, provider, model, input_tokens, output_tokens, duration_ms, ok, error, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $userId ?? (int)(wb_current_user()['id'] ?? 1), $boardId, $feature, (string)$credential['provider'],
        (string)($result['model'] ?? $credential['model'] ?? ''),
        (int)($result['input_tokens'] ?? 0), (int)($result['output_tokens'] ?? 0), (int)($result['duration_ms'] ?? 0),
        $error === null ? 1 : 0, $error === null ? null : mb_substr(($error instanceof AiException ? $error->kind . ': ' : '') . $error->getMessage(), 0, 500), wb_now(),
    ]);
}

/** Token des laufenden und des letzten Monats (nach lokaler Zeit). */
function ai_usage_summary(?int $userId = null): array {
    $userId = $userId ?? (int)(wb_current_user()['id'] ?? 1);
    $tz = new DateTimeZone((string)wb_config('timezone', 'Europe/Zurich'));
    $startThis = (new DateTime('first day of this month 00:00', $tz));
    $startLast = (clone $startThis)->modify('-1 month');
    $utc = fn(DateTime $d) => (clone $d)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $q = fn(string $from, ?string $to) => db_row('SELECT COUNT(*) AS calls, SUM(ok = 0) AS errors, COALESCE(SUM(input_tokens),0) AS input_tokens, COALESCE(SUM(output_tokens),0) AS output_tokens FROM ai_usage WHERE user_id = ? AND created_at >= ?' . ($to ? ' AND created_at < ?' : ''), $to ? [$userId, $from, $to] : [$userId, $from]);
    $fmt = fn(array $r) => ['calls' => (int)$r['calls'], 'errors' => (int)$r['errors'], 'input_tokens' => (int)$r['input_tokens'], 'output_tokens' => (int)$r['output_tokens']];
    return [
        'this_month' => $fmt($q($utc($startThis), null)) + ['label' => $startThis->format('m/Y')],
        'last_month' => $fmt($q($utc($startLast), $utc($startThis))) + ['label' => $startLast->format('m/Y')],
    ];
}

function ai_usage_recent(int $limit = 20, ?int $userId = null): array {
    $userId = $userId ?? (int)(wb_current_user()['id'] ?? 1);
    return db_all('SELECT u.*, b.name AS board_name FROM ai_usage u LEFT JOIN boards b ON b.id = u.board_id WHERE u.user_id = ? ORDER BY u.id DESC LIMIT ' . max(1, min(100, $limit)), [$userId]);
}
