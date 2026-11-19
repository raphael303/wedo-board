<?php
declare(strict_types=1);
/*
 * Seiten der Nutzerverwaltung unter sprachneutralen Adressen (seit 0.22.0): /login, /logout, /signup, /invite,
 * /account, /manage, /help und auf der Testinstanz /test. .htaccess und dev-router.php leiten hierher
 * (?p=<seite>&s=<unterseite>); die Seiten selbst liegen in pages/ und sind von aussen nicht erreichbar.
 */
require __DIR__ . '/lib/bootstrap.php';

$page = (string)($_GET['p'] ?? '');
$sub = (string)($_GET['s'] ?? '');
$pages = ['login', 'logout', 'signup', 'invite', 'account', 'manage', 'help', 'test'];

function page_not_found(): never {
    http_response_code(404);
    ui_header(tx('Nicht gefunden'));
    echo '<section class="card narrow"><h1>' . e(tx('Nicht gefunden')) . '</h1><p class="muted">' . e(tx('Diese Seite gibt es nicht.')) . '</p></section>';
    ui_footer();
    exit;
}

if (!db_is_installed()) { header('Location: /install.php'); exit; }
if (!in_array($page, $pages, true) || !preg_match('/^[a-z0-9-]{0,40}$/', $sub)) page_not_found();

header('Cache-Control: no-store');
header('Referrer-Policy: same-origin');
$mode = wb_registration_mode();
// Solange die Registrierung auf «aus» steht, gibt es weder Registrierung noch Einladungen.
if (in_array($page, ['signup', 'invite'], true) && $mode === 'off') page_not_found();
// Testhilfen nur auf Sandbox und Testinstanz mit Marker (lib/instance.php).
// Der Zugangscode der Testinstanz ist keine Testhilfe: Seine Eingabe unter /test geht auch ohne Marker.
if ($page === 'test' && !wb_test_helpers_enabled() && !(gate_required() && !gate_ok(1))) page_not_found();
// Auf der Testinstanz schützt ein Zugangscode Anmeldung, Registrierung, Testrollen und Sammelpostfach.
if (in_array($page, ['login', 'signup', 'invite'], true) && !gate_ok(1)) {
    header('Location: /test?next=' . urlencode((string)($_SERVER['REQUEST_URI'] ?? '/login')));
    exit;
}
$u = wb_auth_kind() === 'session' ? wb_current_user() : null;
if ($u) $GLOBALS['wb_ui_lang'] = $u['locale'] ?? 'de';

require __DIR__ . '/pages/' . $page . '.php';
