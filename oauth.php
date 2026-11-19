<?php
declare(strict_types=1);
/*
 * Autorisierungsserver (seit 0.23.0, Nutzerverwaltung U1b). .htaccess und dev-router.php leiten hierher:
 *
 *   /.well-known/oauth-authorization-server              ?r=as-meta   Metadaten des Servers (RFC 8414)
 *   /.well-known/oauth-protected-resource/mcp/…          ?r=pr-meta   Metadaten eines Connectors (RFC 9728)
 *   /oauth/register                                      ?r=register  Registrierung eines Clients (RFC 7591)
 *   /oauth/authorize                                     ?r=authorize Anmeldung und Zustimmungsseite
 *   /oauth/token                                         ?r=token     Code einlösen, Token erneuern
 *   /oauth/revoke                                        ?r=revoke    Widerruf (RFC 7009)
 *
 * Die Fachlogik steht in lib/oauth.php.
 */
require __DIR__ . '/lib/bootstrap.php';

$route = (string)($_GET['r'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];
header('Cache-Control: no-store');
header('Pragma: no-cache');

function oauth_json(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function oauth_fail(OAuthError $e): never {
    oauth_json(['error' => $e->error, 'error_description' => $e->getMessage()], $e->status);
}

/** Die Endpunkte für Programme tragen keine Cookies und dürfen deshalb von jeder Herkunft aufgerufen werden. */
function oauth_cors(string $methods): void {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: ' . $methods . ', OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, MCP-Protocol-Version');
    header('Access-Control-Max-Age: 600');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
}

if (!db_is_installed()) { http_response_code(404); exit; }

switch ($route) {
    case 'as-meta':
        oauth_cors('GET');
        $b = wb_base_url();
        oauth_json([
            'issuer' => oauth_issuer(),
            'authorization_endpoint' => $b . '/oauth/authorize',
            'token_endpoint' => $b . '/oauth/token',
            'registration_endpoint' => $b . '/oauth/register',
            'revocation_endpoint' => $b . '/oauth/revoke',
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'revocation_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => [OAUTH_SCOPE],
            'authorization_response_iss_parameter_supported' => true,
            'service_documentation' => $b . '/help/connector',
        ]);

    case 'pr-meta':
        oauth_cors('GET');
        $res = mcp_resource_parse(wb_base_url() . '/' . (string)($_GET['res'] ?? ''));
        if (!$res) oauth_json(['error' => 'not_found'], 404);
        oauth_json([
            'resource' => $res['url'],
            'authorization_servers' => [oauth_issuer()],
            'scopes_supported' => [OAUTH_SCOPE],
            'bearer_methods_supported' => ['header'],
            'resource_name' => mcp_server_name($res['org_id']),
            'resource_documentation' => wb_base_url() . '/help/connector',
        ]);

    case 'register':
        oauth_cors('POST');
        if ($method !== 'POST') oauth_json(['error' => 'invalid_request', 'error_description' => 'Nur POST.'], 405);
        $raw = (string)file_get_contents('php://input', false, null, 0, 16385);
        $in = strlen($raw) <= 16384 ? json_decode($raw, true) : null;
        if (!is_array($in)) oauth_json(['error' => 'invalid_client_metadata', 'error_description' => 'Erwartet wird JSON nach RFC 7591.'], 400);
        try {
            oauth_json(oauth_client_register($in), 201);
        } catch (OAuthError $e) {
            oauth_fail($e);
        }

    case 'token':
        oauth_cors('POST');
        if ($method !== 'POST') oauth_json(['error' => 'invalid_request', 'error_description' => 'Nur POST.'], 405);
        $ip = wb_client_ip();
        if (brake_count('oauth-token|' . $ip, 15) >= wb_setting_int('oauth_token_fails_per_ip', 200)) oauth_json(['error' => 'slow_down', 'error_description' => 'Zu viele Fehlversuche.'], 429);
        try {
            $grant = is_string($_POST['grant_type'] ?? null) ? $_POST['grant_type'] : '';
            if ($grant === 'authorization_code') oauth_json(oauth_token_from_code($_POST));
            if ($grant === 'refresh_token') oauth_json(oauth_token_refresh($_POST));
            throw new OAuthError($grant === '' ? 'invalid_request' : 'unsupported_grant_type', 'Unterstützt werden authorization_code und refresh_token (application/x-www-form-urlencoded).');
        } catch (OAuthError $e) {
            brake_hit('oauth-token|' . $ip);
            oauth_fail($e);
        }

    case 'revoke':
        oauth_cors('POST');
        if ($method !== 'POST') oauth_json(['error' => 'invalid_request'], 405);
        oauth_revoke_token(is_string($_POST['token'] ?? null) ? $_POST['token'] : '');
        http_response_code(200);
        exit;

    case 'authorize':
        break;

    default:
        http_response_code(404);
        exit;
}

/* ---------- /oauth/authorize: Anmeldung, erneute Passwortabfrage, Zustimmungsseite ---------- */

header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
if (!db_schema_current()) ui_maintenance();
$post = $method === 'POST';
if (!in_array($method, ['GET', 'POST'], true)) { http_response_code(405); exit; }
$q = $post ? $_POST : $_GET;

function oauth_page(string $title, string $html, int $status = 200): never {
    http_response_code($status);
    ui_header($title, 'account');
    echo '<section class="card narrow wide">' . $html . '</section>';
    ui_footer();
    exit;
}

/** Zurück zum Programm: als Seite mit sofortiger Weiterleitung, damit keine Formularregel des Browsers sie aufhält. */
function oauth_leave(string $url, string $message): never {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="robots" content="noindex"><meta name="referrer" content="no-referrer">'
        . '<meta http-equiv="refresh" content="0;url=' . e($url) . '"><title>' . e(wb_app_name()) . '</title>'
        . '<link rel="stylesheet" href="/assets/admin.css"></head><body><main class="container"><section class="card narrow"><h1>' . e($message) . '</h1>'
        . '<p class="muted">' . e(tx('Du wirst zum Programm zurückgeleitet.')) . '</p><p><a class="btn" href="' . e($url) . '">' . e(tx('Weiter')) . '</a></p></section></main></body></html>';
    exit;
}

try {
    $req = oauth_authorize_check($q);
} catch (OAuthError $e) {
    // Unbekannter Client oder falsche Rücksprungadresse: nie weiterleiten, nur anzeigen.
    $GLOBALS['wb_ui_lang'] = wb_current_user()['locale'] ?? 'de';
    oauth_page(tx('Verbindung nicht möglich'), '<h1>' . e(tx('Verbindung nicht möglich')) . '</h1><p class="error" role="alert">' . e($e->getMessage()) . '</p>', 400);
}
if ($req['error'] !== null) {
    oauth_leave(oauth_redirect_with($req['redirect_uri'], ['error' => $req['error']->error, 'error_description' => $req['error']->getMessage(), 'state' => $req['state']]), tx('Die Anfrage des Programms ist unvollständig.'));
}

// Auf der Testinstanz liegt die Anmeldung hinter dem Zugangscode; /login leitet dafür selbst nach /test.
$u = ui_require_login();
$uid = (int)$u['id'];
ui_check_csrf();
if (!wb_can('connector.connect')) oauth_page(tx('Verbindung nicht möglich'), '<h1>' . e(tx('Verbindung nicht möglich')) . '</h1>', 403);
// Vor dem Erzeugen einer Verbindung fragt Tafula das Passwort erneut ab (Abschnitt 6).
if (!wb_reauth_fresh()) {
    header('Location: /login/confirm?next=' . urlencode('/oauth/authorize?' . http_build_query(array_filter([
        'response_type' => 'code', 'client_id' => $req['client']['client_id'], 'redirect_uri' => $req['redirect_uri'], 'state' => $req['state'],
        'code_challenge' => $req['challenge'], 'code_challenge_method' => 'S256', 'resource' => $req['resource']['url'] ?? null,
    ], fn($v) => $v !== null && $v !== ''))));
    exit;
}

$spaces = wb_workspaces($u);
$client = $req['client'];
$clientName = $client['name'] !== '' ? $client['name'] : tx('Programm ohne Namen');
$error = null;

// Der Arbeitsbereich folgt aus der Adresse des Connectors. Nennt das Programm keine (ältere Programme),
// wählt die Person ihn auf dieser Seite selbst.
$fixed = $req['resource'] !== null;
if ($fixed) {
    $org = $req['resource']['org_id'];
    if ($org !== null && wb_valid_workspace($u, $org) === null) {
        oauth_page(tx('Verbindung nicht möglich'), '<h1>' . e(tx('Verbindung nicht möglich')) . '</h1><p class="error" role="alert">'
            . e(tx('Dieser Connector gehört zu einem Arbeitsbereich, dem dein Konto ({email}) nicht angehört.', ['email' => (string)$u['email']])) . '</p>'
            . '<p><a class="btn-ghost" href="' . e(oauth_redirect_with($req['redirect_uri'], ['error' => 'access_denied', 'state' => $req['state']])) . '">' . e(tx('Zurück zum Programm')) . '</a></p>', 403);
    }
} else {
    $org = null;
    if ($post && (string)($_POST['workspace'] ?? '') !== '' && $_POST['workspace'] !== 'private') {
        $org = wb_valid_workspace($u, (int)$_POST['workspace']);
        if ($org === null) $error = tx('Diesen Arbeitsbereich gibt es für dein Konto nicht.');
    }
}

if ($post && $error === null) {
    if ((string)($_POST['decision'] ?? '') !== 'allow') {
        audit_log('oauth.deny', 'oauth_client', $client['id'], ['client' => $client['name']], $org, $uid, 'self');
        oauth_leave(oauth_redirect_with($req['redirect_uri'], ['error' => 'access_denied', 'state' => $req['state']]), tx('Nicht verbunden.'));
    }
    $resource = mcp_resource_url($org);
    if ($resource === null) oauth_page(tx('Verbindung nicht möglich'), '<h1>' . e(tx('Verbindung nicht möglich')) . '</h1>', 400);
    $code = oauth_code_create($client, $uid, $org, $resource, $req['redirect_uri'], $req['challenge']);
    oauth_leave(oauth_redirect_with($req['redirect_uri'], ['code' => $code, 'state' => $req['state']]), tx('Verbunden.'));
}

$loopback = oauth_is_loopback_uri($req['redirect_uri']);
$host = oauth_redirect_host($req['redirect_uri']);
$wsName = $org === null ? tx('Privat') : mcp_workspace_label($org);
ui_header(tx('Verbindung erlauben'), 'account');
?>
<section class="card narrow wide">
  <h1><?= e(tx('Verbindung erlauben?')) ?></h1>
  <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <p><?= e(tx('Ein Programm möchte in deinem Namen auf {app} zugreifen.', ['app' => wb_app_name()])) ?></p>
  <table class="results plain">
    <tr><th><?= e(tx('Programm')) ?></th><td><strong><?= e($clientName) ?></strong><br><span class="muted"><?= e(tx('Den Namen gibt das Programm selbst an; {app} kann ihn nicht prüfen.', ['app' => wb_app_name()])) ?></span></td></tr>
    <tr><th><?= e(tx('Rücksprungadresse')) ?></th><td><strong><?= e($host) ?></strong><br><code class="token" style="word-break:break-all"><?= e($req['redirect_uri']) ?></code><br><span class="muted"><?= e(tx('Dorthin geht der Zugang nach deiner Zustimmung. Erlaube nur, wenn du diese Adresse erwartest.')) ?></span></td></tr>
    <tr><th><?= e(tx('Konto')) ?></th><td><?= e(user_name($u)) ?> (<?= e((string)$u['email']) ?>)</td></tr>
    <?php if ($fixed): ?><tr><th><?= e(tx('Arbeitsbereich')) ?></th><td><strong><?= e($wsName) ?></strong></td></tr><?php endif; ?>
  </table>
  <?php if ($loopback): ?><p class="error"><?= e(tx('Achtung: Die Rücksprungadresse zeigt auf diesen Computer. Jedes Programm, das hier läuft, könnte sie benutzen. Erlaube nur, wenn du die Verbindung eben selbst in einem Programm auf diesem Computer begonnen hast.')) ?></p><?php endif; ?>
  <p><?= e(tx('Das Programm kann danach deine eigenen Boards und Aktivitäten in diesem Arbeitsbereich lesen, anlegen, ändern und löschen. Es erreicht keine Inhalte anderer Personen, keine Rückmeldungen, keine Kontoeinstellungen und nichts aus der Verwaltung. Du kannst die Verbindung jederzeit im Konto widerrufen.')) ?></p>
  <form method="post" action="/oauth/authorize" class="form">
    <?= ui_csrf_field() ?>
    <input type="hidden" name="response_type" value="code">
    <input type="hidden" name="client_id" value="<?= e($client['client_id']) ?>">
    <input type="hidden" name="redirect_uri" value="<?= e($req['redirect_uri']) ?>">
    <input type="hidden" name="state" value="<?= e($req['state']) ?>">
    <input type="hidden" name="code_challenge" value="<?= e($req['challenge']) ?>">
    <input type="hidden" name="code_challenge_method" value="S256">
    <?php if ($fixed): ?><input type="hidden" name="resource" value="<?= e($req['resource']['url']) ?>">
    <?php else: ?>
    <div class="row"><label for="ws"><?= e(tx('Arbeitsbereich')) ?></label><select id="ws" name="workspace"><?php foreach ($spaces as $s): ?><option value="<?= $s['org_id'] === null ? 'private' : (int)$s['org_id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select>
      <p class="hint"><?= e(tx('Die Verbindung gilt nur für diesen Arbeitsbereich und nur an seinem Connector.')) ?></p></div>
    <?php endif; ?>
    <div class="actions"><button class="btn" type="submit" name="decision" value="allow"><?= e(tx('Erlauben')) ?></button> <button class="btn-ghost" type="submit" name="decision" value="deny"><?= e(tx('Ablehnen')) ?></button></div>
  </form>
</section>
<?php
ui_footer();
