<?php
declare(strict_types=1);

/*
 * Teilschlüssel aus app_secret (Nutzerverwaltung, Abschnitt 13). Jeder Zweck erhält einen eigenen Schlüssel;
 * die Verschlüsselung der KI-Schlüssel (lib/ai/ai.php) benutzt weiterhin app_secret selbst.
 * app_secret darf nie gewechselt werden: Geheimnisse der App-Stufe wären unlesbar, und ab U2 hängen die
 * Teilnehmerschlüssel daran.
 */
const WB_SUBKEYS = [
    'totp' => 1,         // Verschlüsselung der Geheimnisse der App-Stufe
    'brake' => 2,        // Schlüssel der Anmeldebremsen in rate_limits
    'csrf' => 3,         // CSRF-Wert anonymer Formulare
    'gate' => 4,         // Zugangscode der Testinstanz (Cookie)
    'participant' => 5,  // abgeleitete Teilnehmerschlüssel (ab U2)
    'pass' => 6,         // signierter Teilnahmeausweis (ab U2)
    'pending' => 7,      // Zwischenstand der Anmeldung vor der zweiten Stufe
    'recovery' => 8,     // Hash der Wiederherstellungscodes
];

function wb_subkey(string $purpose): string {
    static $cache = [];
    if (isset($cache[$purpose])) return $cache[$purpose];
    if (!isset(WB_SUBKEYS[$purpose])) throw new LogicException('Unbekannter Teilschlüssel: ' . $purpose);
    $master = ai_secret_key();
    if ($master === null) throw new RuntimeException('Kein app_secret in config.php.');
    return $cache[$purpose] = sodium_crypto_kdf_derive_from_key(32, WB_SUBKEYS[$purpose], 'tafula__', $master);
}

function wb_hmac(string $purpose, string $data): string {
    return hash_hmac('sha256', $data, wb_subkey($purpose));
}

/** Hash eines zufälligen Tokens (Sitzung, Link in einer Mail, Einladung, Gerät). Gespeichert wird nur er. */
function wb_token_hash(string $token): string {
    return hash('sha256', $token);
}

function wb_random_token(int $bytes = 32): string {
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function wb_seal(string $purpose, string $plain): string {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, wb_subkey($purpose)));
}

function wb_unseal(string $purpose, string $enc): ?string {
    $bin = base64_decode($enc, true);
    if ($bin === false || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
    $plain = sodium_crypto_secretbox_open(substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), wb_subkey($purpose));
    return $plain === false ? null : $plain;
}

/** Signierter Wert «daten.ablauf.signatur» für kurzlebige Cookies ohne Datenbank. */
function wb_signed_make(string $purpose, string $data, int $ttl): string {
    $until = time() + $ttl;
    return $data . '.' . $until . '.' . wb_hmac($purpose, $data . '|' . $until);
}

function wb_signed_read(string $purpose, string $value): ?string {
    if (!preg_match('/^(.*)\.(\d{10})\.([0-9a-f]{64})$/s', $value, $m)) return null;
    if ((int)$m[2] < time()) return null;
    return hash_equals(wb_hmac($purpose, $m[1] . '|' . $m[2]), $m[3]) ? $m[1] : null;
}

/* ---------- Zeit ---------- */

/** Unix-Zeit aus einem DATETIME(6) in UTC. */
function wb_ts(?string $utc): int {
    return $utc === null ? 0 : (int)strtotime(substr($utc, 0, 19) . ' UTC');
}

function wb_utc(int $ts): string {
    return gmdate('Y-m-d H:i:s', $ts) . '.000000';
}

/**
 * Fachliche Zeit: wie time(), auf Sandbox und Testinstanz um clock_offset (Sekunden, config.php) verschoben.
 * Nur für fachliche Fristen (unbestätigte Konten, Einladungen, die 72 Stunden beim Entfernen der zweiten Stufe,
 * später Lizenzen und Löschfristen), nie für Sitzungen, Links, Codes und Bremsen.
 */
function wb_business_time(): int {
    $off = wb_test_helpers_enabled() ? (int)wb_config('clock_offset', 0) : 0;
    return time() + $off;
}
