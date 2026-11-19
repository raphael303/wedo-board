<?php
declare(strict_types=1);

function wb_langs(): array {
    return ['de', 'en'];
}

function wb_lang_strings(string $lang): array {
    static $cache = [];
    if (!in_array($lang, wb_langs(), true)) $lang = 'de';
    if (!isset($cache[$lang])) {
        // {app} in den Sprachdateien steht für den Anzeigenamen aus der Konfiguration.
        $cache[$lang] = array_map(fn($s) => str_replace('{app}', wb_app_name(), (string)$s), require WB_ROOT . '/lang/' . $lang . '.php');
    }
    return $cache[$lang];
}

function t(string $key, string $lang = 'de', array $vars = []): string {
    $s = wb_lang_strings($lang)[$key] ?? (wb_lang_strings('de')[$key] ?? $key);
    foreach ($vars as $k => $v) {
        $s = str_replace('{' . $k . '}', (string)$v, $s);
    }
    return $s;
}

/**
 * Sprache für einen Besucher: ?lang=, sonst Cookie, sonst Board-Sprache.
 */
function wb_resolve_lang(string $boardLang): string {
    $q = $_GET['lang'] ?? null;
    if ($q && in_array($q, wb_langs(), true)) {
        setcookie('wb_lang', $q, ['expires' => time() + 86400 * 365, 'path' => '/', 'samesite' => 'Lax']);
        return $q;
    }
    $c = $_COOKIE['wb_lang'] ?? null;
    if ($c && in_array($c, wb_langs(), true)) return $c;
    return in_array($boardLang, wb_langs(), true) ? $boardLang : 'de';
}

/**
 * Texte des Verwaltungsteils (Anmeldung, Konto, Verwaltung, Mails): Der deutsche Satz ist der Schlüssel.
 * Eine Übersetzung steht in lang/ui.<sprache>.php als Feld «deutscher Satz» => «Übersetzung»; fehlt sie,
 * erscheint der deutsche Text. Sprache: die des angemeldeten Kontos, sonst Deutsch.
 */
function tx(string $de, array $vars = [], ?string $lang = null): string {
    static $maps = [];
    $lang = $lang ?? ($GLOBALS['wb_ui_lang'] ?? 'de');
    $s = $de;
    if ($lang !== 'de' && preg_match('/^[a-z]{2}$/', $lang)) {
        if (!isset($maps[$lang])) {
            $f = WB_ROOT . '/lang/ui.' . $lang . '.php';
            $maps[$lang] = is_file($f) ? (array)require $f : [];
        }
        $s = $maps[$lang][$de] ?? $de;
    }
    foreach ($vars as $k => $v) $s = str_replace('{' . $k . '}', (string)$v, $s);
    return $s;
}
