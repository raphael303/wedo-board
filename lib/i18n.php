<?php
declare(strict_types=1);

function wb_langs(): array {
    return ['de', 'en'];
}

function wb_lang_strings(string $lang): array {
    static $cache = [];
    if (!in_array($lang, wb_langs(), true)) $lang = 'de';
    if (!isset($cache[$lang])) {
        $cache[$lang] = require WB_ROOT . '/lang/' . $lang . '.php';
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
