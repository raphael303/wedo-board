<?php
// Nur für die lokale Entwicklung: php -S localhost:8080 dev-router.php
// Bildet die Regeln aus .htaccess nach. Domains stehen nur in config.php (base_url, legacy_hosts);
// die Weiterleitung früherer Hosts macht lib/bootstrap.php, auch hier (Test: curl -H 'Host: alt.example' …).
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('~^/b/([a-z0-9]{16})/export/?$~', $uri, $m)) {
    $_GET['key'] = $m[1];
    require __DIR__ . '/export.php';
    return true;
}
if (preg_match('~^/a/([a-z0-9]{16})/print/?$~', $uri, $m)) {
    $_GET['key'] = $m[1];
    require __DIR__ . '/exam_print.php';
    return true;
}
if (preg_match('~^/a/([a-z0-9]{16})/?$~', $uri, $m)) {
    $_GET['key'] = $m[1];
    require __DIR__ . '/activity.php';
    return true;
}
if (preg_match('~^/b/([a-z0-9]{16})/?$~', $uri, $m)) {
    $_GET['key'] = $m[1];
    require __DIR__ . '/board.php';
    return true;
}
if (preg_match('~^/api/(.*)$~', $uri, $m)) {
    $_GET['route'] = $m[1];
    require __DIR__ . '/api/index.php';
    return true;
}
if (preg_match('~^/mcp/([a-f0-9]{48})/?$~', $uri, $m)) {
    $_GET['token'] = $m[1];
    require __DIR__ . '/mcp/index.php';
    return true;
}
if (preg_match('~^/([a-z0-9][a-z0-9-]{1,30}[a-z0-9])/([a-z_]+\.php)$~', $uri, $m) && !is_dir(__DIR__ . '/' . $m[1]) && file_exists(__DIR__ . '/admin/' . $m[2])) {
    require __DIR__ . '/admin/' . $m[2];
    return true;
}
if (preg_match('~^/([a-z0-9][a-z0-9-]{1,30}[a-z0-9])/?$~', $uri, $m) && !is_dir(__DIR__ . '/' . $m[1]) && !file_exists(__DIR__ . '/' . $m[1])) {
    $_GET['slug'] = $m[1];
    require __DIR__ . '/board.php';
    return true;
}
if (preg_match('~^/(lib|lang|vendor|tools|\.git|\.cyon)(/|$)~', $uri) || preg_match('~^/(config.*\.php|.*\.md)$~', $uri)) {
    http_response_code(404);
    return true;
}
if (is_dir(__DIR__ . $uri) && file_exists(__DIR__ . rtrim($uri, '/') . '/index.php')) {
    require __DIR__ . rtrim($uri, '/') . '/index.php';
    return true;
}
return false;
