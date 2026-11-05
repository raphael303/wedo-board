<?php
declare(strict_types=1);

define('WB_ROOT', dirname(__DIR__));
define('WB_VERSION', '0.2.0');
define('WB_SCHEMA_VERSION', 1);

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

require WB_ROOT . '/lib/helpers.php';
require WB_ROOT . '/lib/db.php';
require WB_ROOT . '/lib/auth.php';
require WB_ROOT . '/lib/i18n.php';
require WB_ROOT . '/lib/names.php';
require WB_ROOT . '/lib/boards.php';
require WB_ROOT . '/lib/migrate.php';

function wb_config(string $key, $default = null) {
    return $GLOBALS['wb_config'][$key] ?? $default;
}
