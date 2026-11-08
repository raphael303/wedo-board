<?php
declare(strict_types=1);

define('WB_ROOT', dirname(__DIR__));
define('WB_VERSION', '0.9.4');
define('WB_SCHEMA_VERSION', 6);

$configFile = WB_ROOT . '/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "config.php fehlt. Kopiere config.example.php nach config.php und trage die Datenbankdaten ein.\n";
    exit;
}
$GLOBALS['wb_config'] = require $configFile;
date_default_timezone_set($GLOBALS['wb_config']['timezone'] ?? 'Europe/Zurich');
mb_internal_encoding('UTF-8');

function wb_config(string $key, $default = null) {
    return $GLOBALS['wb_config'][$key] ?? $default;
}

/** Anzeigename der App aus der Konfiguration. */
function wb_app_name(): string {
    $n = trim((string)wb_config('app_name', ''));
    return $n !== '' ? $n : 'Board';
}

/**
 * Frühere Domains (config: legacy_hosts) und die www-Variante der Hauptdomain leiten mit 301
 * und unverändertem Pfad auf base_url um. So muss .htaccess bei einem Domainwechsel nicht angepasst werden.
 */
function wb_redirect_legacy_host(): void {
    if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) return;
    $host = strtolower(preg_replace('/:\d+$/', '', (string)$_SERVER['HTTP_HOST']) ?? '');
    $base = rtrim((string)wb_config('base_url', ''), '/');
    $baseHost = strtolower((string)parse_url($base, PHP_URL_HOST));
    if ($host === '' || $baseHost === '' || $host === $baseHost) return;
    $legacy = array_map(fn($h) => strtolower(trim((string)$h)), (array)wb_config('legacy_hosts', []));
    if (!in_array($host, $legacy, true) && $host !== 'www.' . $baseHost) return;
    $path = (string)($_SERVER['REQUEST_URI'] ?? '/');
    if ($path === '' || $path[0] !== '/') $path = '/' . $path;
    header('Location: ' . $base . $path, true, 301);
    header('Cache-Control: no-store');
    exit;
}
wb_redirect_legacy_host();

require WB_ROOT . '/lib/helpers.php';
require WB_ROOT . '/lib/db.php';
require WB_ROOT . '/lib/auth.php';
require WB_ROOT . '/lib/i18n.php';
require WB_ROOT . '/lib/names.php';
require WB_ROOT . '/lib/boards.php';
require WB_ROOT . '/lib/bundles.php';
require WB_ROOT . '/lib/undo.php';
require WB_ROOT . '/lib/blocks.php';
require WB_ROOT . '/lib/ai/ai.php';
require WB_ROOT . '/lib/migrate.php';
