<?php
declare(strict_types=1);

function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function wb_random_key(int $length = 16): string {
    // Nur Zeichen, die sich nicht verwechseln lassen und in URLs sicher sind.
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $out = '';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

function wb_base_url(): string {
    return rtrim((string)wb_config('base_url', ''), '/');
}

/** Anzeigename der App als Dateinamen-tauglicher Kurzname (für Exporte). */
function wb_app_slug(): string {
    $s = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', wb_app_name()) ?? '', '-'));
    return $s !== '' ? $s : 'board';
}

function wb_board_url(string $key, ?string $slug = null): string {
    return wb_base_url() . '/' . ($slug !== null && $slug !== '' ? $slug : 'b/' . $key);
}

/**
 * Aussprechbares Kurzwort (Konsonant-Vokal-Konsonant-Vokal-Konsonant) ohne verwechselbare Buchstaben.
 */
function wb_short_word(): string {
    $c = 'bdfghkmnprstvwz';
    $v = 'aeiou';
    return $c[random_int(0, 14)] . $v[random_int(0, 4)] . $c[random_int(0, 14)] . $v[random_int(0, 4)] . $c[random_int(0, 14)];
}

/**
 * Pfad der Bibliothek (config: admin_path, Standard «admin»). Ein eigener Pfad versteckt die Bibliothek vor
 * Neugierigen; er ist kein Passwortersatz. Der Ordner heisst weiterhin admin/, .htaccess leitet um.
 */
function wb_admin_path(): string {
    $p = strtolower(trim((string)wb_config('admin_path', 'admin'), "/ \t"));
    return preg_match('/^[a-z0-9][a-z0-9-]{1,30}[a-z0-9]$/', $p) ? $p : 'admin';
}

/** Adresse einer Bibliotheksseite, z. B. wb_admin_url('board.php?key=…'). Endet ohne Argument mit «/». */
function wb_admin_url(string $rest = ''): string {
    return '/' . wb_admin_path() . '/' . ltrim($rest, '/');
}

function wb_reserved_slugs(): array {
    return [wb_admin_path(), 'admin', 'api', 'a', 'b', 'mcp', 'install', 'uploads', 'assets', 'lib', 'lang', 'vendor', 'index', 'board', 'login', 'logout', 'export', 'config', 'www', 'static', 'help', 'about'];
}

function wb_valid_slug(?string $s): ?string {
    $s = strtolower(trim((string)$s));
    if ($s === '') return null;
    if (!preg_match('/^[a-z0-9][a-z0-9-]{1,30}[a-z0-9]$/', $s)) return null;
    if (in_array($s, wb_reserved_slugs(), true)) return null;
    return $s;
}

function wb_now(): string {
    // DATETIME(6) als Versionsstempel.
    return (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
}

function wb_json(mixed $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function wb_error(string $message, int $status = 400, array $extra = []): never {
    wb_json(['ok' => false, 'error' => $message] + $extra, $status);
}

function wb_read_json_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return $_POST;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** Setzt das Cookie wb_uid auf einen vom Browser gemeldeten, früheren Schlüssel (Wiedererkennen über localStorage). */
function wb_set_client_uid(string $uid): bool {
    if (!preg_match('/^[a-f0-9]{32}$/', $uid)) return false;
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    setcookie('wb_uid', $uid, ['expires' => time() + 60 * 60 * 24 * 365 * 2, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    $_COOKIE['wb_uid'] = $uid;
    return true;
}

function wb_client_uid(): string {
    // Jeder Browser erhält einen zufälligen Schlüssel in einem Cookie. Fehlt das Cookie, gilt der Wert,
    // den die Board-Seite aus localStorage im Header X-Guest-Key mitschickt; das Cookie wird dann neu gesetzt.
    $uid = $_COOKIE['wb_uid'] ?? '';
    if (!preg_match('/^[a-f0-9]{32}$/', $uid)) {
        $fromHeader = (string)($_SERVER['HTTP_X_GUEST_KEY'] ?? '');
        if (preg_match('/^[a-f0-9]{32}$/', $fromHeader)) {
            $uid = $fromHeader;
        } else {
            $uid = bin2hex(random_bytes(16));
            $GLOBALS['wb_uid_fresh'] = true;
        }
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie('wb_uid', $uid, [
            'expires' => time() + 60 * 60 * 24 * 365 * 2,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE['wb_uid'] = $uid;
    }
    return $uid;
}

function wb_clean_text(?string $s, int $max): string {
    $s = trim((string)$s);
    $s = preg_replace('/\r\n?/', "\n", $s) ?? $s;
    $s = preg_replace('/[^\P{C}\n\t]/u', '', $s) ?? $s; // Steuerzeichen entfernen
    if (mb_strlen($s) > $max) {
        $s = mb_substr($s, 0, $max);
    }
    return $s;
}

function wb_clean_url(?string $s): ?string {
    $s = trim((string)$s);
    if ($s === '') return null;
    if (!preg_match('~^https?://~i', $s)) {
        $s = 'https://' . $s;
    }
    if (mb_strlen($s) > 1000) return null;
    return filter_var($s, FILTER_VALIDATE_URL) ? $s : null;
}

function wb_backgrounds(): array {
    return ['morgen', 'meer', 'wald', 'daemmerung', 'graphit', 'papier'];
}

/** Alle wählbaren Emojis; pro Board ist eine Auswahl davon erlaubt (settings.emojis). */
function wb_emojis(): array {
    return array_merge(wb_default_emojis(), wb_traffic_emojis());
}

/** Standardauswahl für neue Boards (ohne Ampel). */
function wb_default_emojis(): array {
    return ['👍', '❤️', '😂', '🤔', '👏', '⭐'];
}

/** Ampel: Pro Person und Beitrag (oder Kommentar) gilt höchstens eine der drei Farben. */
function wb_traffic_emojis(): array {
    return ['🔴', '🟡', '🟢'];
}

function wb_default_settings(): array {
    return [
        'comments' => true,
        'reactions' => true,
        'images' => true,
        'links' => true,
        'new_on_top' => true,
        'ai' => true,
        'emojis' => wb_default_emojis(),
    ];
}

function wb_parse_tags(string|array|null $tags): array {
    if (is_array($tags)) {
        $list = $tags;
    } else {
        $list = preg_split('/[,\n]+/', (string)$tags) ?: [];
    }
    $out = [];
    foreach ($list as $t) {
        $t = trim((string)$t);
        $t = preg_replace('/\s+/', ' ', $t) ?? $t;
        if ($t !== '' && mb_strlen($t) <= 40 && !in_array($t, $out, true)) {
            $out[] = $t;
        }
    }
    return array_slice($out, 0, 20);
}

function wb_author_hue(string $name): int {
    return crc32($name) % 360;
}
