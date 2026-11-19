<?php
declare(strict_types=1);

/*
 * Zweite Stufe mit einer Authenticator-App (TOTP nach RFC 6238: SHA-1, 30 Sekunden, 6 Ziffern) in reinem PHP.
 * Das Geheimnis liegt mit einem Teilschlüssel aus app_secret verschlüsselt in users.totp_secret_enc. Ein Code
 * gilt für den aktuellen und den vorigen und nächsten Zeitschritt, und jeder Zeitschritt nur einmal
 * (users.totp_last_step). Wiederherstellungscodes liegen als HMAC in users.recovery_codes_json.
 */

const TOTP_STEP = 30;
const TOTP_RECOVERY_COUNT = 10;

function totp_base32_encode(string $bin): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $ch) $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}

function totp_base32_decode(string $b32): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32) ?? '')) as $ch) {
        $bits .= str_pad(decbin((int)strpos($alphabet, $ch)), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}

function totp_new_secret(): string {
    return totp_base32_encode(random_bytes(20));
}

function totp_code(string $secretB32, int $step): string {
    $h = hash_hmac('sha1', pack('J', $step), totp_base32_decode($secretB32), true);
    $o = ord($h[19]) & 0x0f;
    $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Zeitschritt, zu dem $code passt (aktuell, einer davor, einer danach), oder null. */
function totp_match(string $secretB32, string $code, ?int $now = null): ?int {
    $code = preg_replace('/\s+/', '', $code) ?? '';
    if (!preg_match('/^\d{6}$/', $code)) return null;
    $step = intdiv($now ?? time(), TOTP_STEP);
    foreach ([0, -1, 1] as $d) {
        if (hash_equals(totp_code($secretB32, $step + $d), $code)) return $step + $d;
    }
    return null;
}

function totp_uri(string $secretB32, string $email): string {
    $issuer = wb_app_name() . (wb_is_production() ? '' : ' (' . wb_instance() . ')');
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $email) . '?secret=' . $secretB32 . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}

function totp_user_secret(array $user): ?string {
    return empty($user['totp_secret_enc']) ? null : wb_unseal('totp', (string)$user['totp_secret_enc']);
}

/** Prüft einen Code der App für ein Konto und verbraucht den Zeitschritt. */
function totp_verify_user(array $user, string $code): bool {
    $secret = totp_user_secret($user);
    if ($secret === null) return false;
    $step = totp_match($secret, $code);
    if ($step === null) return false;
    // Nur ein neuerer Zeitschritt als der zuletzt benutzte zählt; die Bedingung im UPDATE schliesst gleichzeitige Versuche aus.
    return db_exec('UPDATE users SET totp_last_step = ? WHERE id = ? AND totp_last_step < ?', [$step, (int)$user['id'], $step]) === 1;
}

/** Schaltet die App-Stufe ein und gibt die zehn Wiederherstellungscodes im Klartext zurück (nur dieses eine Mal). */
function totp_enable(int $userId, string $secretB32, int $step): array {
    $codes = totp_recovery_generate();
    db_exec('UPDATE users SET totp_secret_enc = ?, totp_last_step = ?, recovery_codes_json = ?, totp_removal_at = NULL WHERE id = ?',
        [wb_seal('totp', $secretB32), $step, json_encode(array_map('totp_recovery_hash', $codes)), $userId]);
    user_reload($userId);
    return $codes;
}

function totp_disable(int $userId): void {
    db_exec('UPDATE users SET totp_secret_enc = NULL, totp_last_step = 0, recovery_codes_json = NULL, totp_removal_at = NULL WHERE id = ?', [$userId]);
    user_reload($userId);
}

function totp_recovery_generate(): array {
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $out = [];
    for ($i = 0; $i < TOTP_RECOVERY_COUNT; $i++) {
        $c = '';
        for ($j = 0; $j < 12; $j++) $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $out[] = substr($c, 0, 4) . '-' . substr($c, 4, 4) . '-' . substr($c, 8, 4);
    }
    return $out;
}

function totp_recovery_hash(string $code): string {
    return wb_hmac('recovery', strtolower(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? ''));
}

function totp_recovery_regenerate(int $userId): array {
    $codes = totp_recovery_generate();
    db_exec('UPDATE users SET recovery_codes_json = ? WHERE id = ?', [json_encode(array_map('totp_recovery_hash', $codes)), $userId]);
    user_reload($userId);
    return $codes;
}

function totp_recovery_left(array $user): int {
    return count((array)json_decode((string)($user['recovery_codes_json'] ?? '[]'), true));
}

/** Verbraucht einen Wiederherstellungscode. */
function totp_recovery_use(array $user, string $code): bool {
    $hashes = (array)json_decode((string)($user['recovery_codes_json'] ?? '[]'), true);
    if (strlen(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '') !== 12) return false;
    $h = totp_recovery_hash($code);
    foreach ($hashes as $i => $stored) {
        if (is_string($stored) && hash_equals($stored, $h)) {
            unset($hashes[$i]);
            $n = db_exec('UPDATE users SET recovery_codes_json = ? WHERE id = ? AND recovery_codes_json = ?', [json_encode(array_values($hashes)), (int)$user['id'], (string)$user['recovery_codes_json']]);
            user_reload((int)$user['id']);
            return $n === 1;
        }
    }
    return false;
}
