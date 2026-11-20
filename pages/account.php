<?php
declare(strict_types=1);
// /account: Profil, Passwort, Adresse, zweite Stufe, Connector und Verbindungen, persönliche Tokens, Sitzungen,
// bekannte Geräte. Unterseiten: workspace (Wechsel des Arbeitsbereichs), totp (App einrichten), token (neues
// persönliches Token), email, email-revert und keep-totp (Links aus Mails).
if (!defined('WB_ROOT')) exit;
require_once WB_ROOT . '/lib/qr.php';
ui_check_csrf();
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$error = null;

/* ---------- Links aus Mails (ohne Anmeldung benutzbar) ---------- */

if ($sub === 'email') {
    // Bestätigt nur mit Knopf und nur in einer Sitzung desselben Kontos (S1, N5): Ein Mailscanner, der den Link
    // öffnet, bestätigt damit nichts.
    $token = (string)($_POST['t'] ?? $_GET['t'] ?? '');
    if (!$u) ui_redirect('/login?next=' . urlencode('/account/email?t=' . rawurlencode($token)), tx('Melde dich an, um die neue Adresse zu bestätigen.'));
    $pending = login_token_find($token, 'email_change');
    if (!$pending || (int)$pending['user_id'] !== (int)$u['id']) ui_redirect('/account', tx('Dieser Link ist nicht mehr gültig.'));
    if ($post) {
        $ok = account_email_change_confirm($token, (int)$u['id']);
        ui_redirect('/account', $ok ? tx('Die neue Adresse ist bestätigt.') : tx('Dieser Link ist nicht mehr gültig.'));
    }
    ui_header(tx('Neue Adresse bestätigen'), 'account');
    ?>
<section class="card narrow">
  <h1><?= e(tx('Neue Adresse bestätigen')) ?></h1>
  <p><?= e(tx('Soll {new} die neue Adresse deines Kontos werden? Deine bisherige Adresse {old} erhält danach einen Link, mit dem sich die Änderung sieben Tage lang rückgängig machen lässt.', ['new' => (string)($pending['payload']['new'] ?? ''), 'old' => (string)$u['email']])) ?></p>
  <form method="post" class="form"><?= ui_csrf_field() ?><input type="hidden" name="t" value="<?= e($token) ?>"><div class="actions"><button class="btn" type="submit"><?= e(tx('Adresse bestätigen')) ?></button> <a class="btn-ghost" href="/account"><?= e(tx('Abbrechen')) ?></a></div></form>
</section>
<?php
    ui_footer();
    exit;
}
if ($sub === 'keep-totp') {
    $token = (string)($_POST['t'] ?? $_GET['t'] ?? '');
    if ($post) {
        $ok = account_totp_removal_stop($token);
        ui_redirect($u ? '/account' : '/login', $ok ? tx('Gestoppt: Die zweite Stufe bleibt bestehen.') : tx('Dieser Link ist nicht mehr gültig.'));
    }
    ui_header(tx('Zweite Stufe behalten'), 'account');
    ?>
<section class="card narrow">
  <h1><?= e(tx('Zweite Stufe behalten')) ?></h1>
  <p><?= e(tx('Für dein Konto wurde verlangt, die Anmeldung mit der Authenticator-App zu entfernen. Wenn du das nicht warst, stoppe es hier.')) ?></p>
  <form method="post" class="form"><?= ui_csrf_field() ?><input type="hidden" name="t" value="<?= e($token) ?>"><div class="actions"><button class="btn" type="submit"><?= e(tx('Entfernen stoppen')) ?></button></div></form>
</section>
<?php
    ui_footer();
    exit;
}
if ($sub === 'email-revert') {
    $token = (string)($_POST['t'] ?? $_GET['t'] ?? '');
    $valid = login_token_find($token, 'email_revert') !== null;
    if ($post && $valid) {
        $error = account_email_revert($token, (string)($_POST['password'] ?? ''), (string)($_POST['password2'] ?? ''));
        if ($error === null) { wb_logout(); ui_redirect('/login', tx('Die frühere Adresse gilt wieder, und das Passwort ist neu gesetzt. Melde dich jetzt an.')); }
    }
    ui_header(tx('Adressänderung rückgängig machen'), 'account');
    ?>
<section class="card narrow">
  <h1><?= e(tx('Adressänderung rückgängig machen')) ?></h1>
  <?php if (!$valid): ?><p class="error" role="alert"><?= e(tx('Dieser Link ist nicht mehr gültig.')) ?></p>
  <?php else: ?>
  <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <p class="muted"><?= e(tx('Dein Konto erhält wieder die frühere Adresse. Alle Sitzungen werden beendet. Wähle ein neues Passwort.')) ?></p>
  <form method="post" class="form"><?= ui_csrf_field() ?><input type="hidden" name="t" value="<?= e($token) ?>">
    <div class="row"><label for="pw"><?= e(tx('Neues Passwort')) ?></label><input id="pw" name="password" type="password" autocomplete="new-password" minlength="12" required></div>
    <div class="row"><label for="pw2"><?= e(tx('Passwort wiederholen')) ?></label><input id="pw2" name="password2" type="password" autocomplete="new-password" minlength="12" required></div>
    <div class="actions"><button class="btn" type="submit"><?= e(tx('Rückgängig machen')) ?></button></div>
  </form>
  <?php endif; ?>
</section>
<?php
    ui_footer();
    exit;
}

/* ---------- Ab hier nur angemeldet ---------- */

$u = ui_require_login();
$uid = (int)$u['id'];
$session = wb_session();
$staff = user_system_roles($uid);

if ($sub === 'workspace') {
    if ($post) {
        $org = (int)($_POST['org'] ?? 0);
        wb_workspace_switch($org > 0 ? $org : null);
    }
    ui_redirect(wb_admin_url());
}

if ($sub === 'totp') {
    ui_require_reauth();
    if (user_has_totp($u)) ui_redirect('/account');
    $codes = null;
    $secret = null;
    if ($post) {
        $secret = wb_unseal('pending', (string)($_POST['s'] ?? ''));
        $step = $secret !== null ? totp_match($secret, (string)($_POST['code'] ?? '')) : null;
        if ($secret === null) $error = tx('Die Seite ist abgelaufen. Bitte noch einmal beginnen.');
        elseif ($step === null) $error = tx('Dieser Code stimmt nicht. Prüfe die Uhrzeit deines Geräts und versuche es mit dem nächsten Code.');
        else {
            $codes = totp_enable($uid, $secret, $step);
            db_exec("UPDATE sessions SET mfa_level = 'totp' WHERE id = ?", [$session['id']]);
            wb_sessions_revoke_user($uid, $session['id']);
            wb_session_forget_cache();
            audit_log('account.totp_enable', 'user', $uid, [], null, $uid, 'self');
        }
    }
    if ($secret === null) $secret = totp_new_secret();
    ui_header(tx('Authenticator-App einrichten'), 'account');
    if ($codes !== null): ?>
<section class="card narrow wide">
  <h1><?= e(tx('Die zweite Stufe ist eingerichtet')) ?></h1>
  <p><?= e(tx('Bewahre diese zehn Wiederherstellungscodes sicher auf, zum Beispiel ausgedruckt. Jeder gilt einmal und ersetzt den Code der App, wenn du dein Gerät verlierst. Sie werden nur jetzt angezeigt.')) ?></p>
  <pre class="codes"><?= e(implode("\n", $codes)) ?></pre>
  <div class="actions"><button class="btn-ghost" type="button" data-copy="<?= e(implode("\n", $codes)) ?>"><?= e(tx('Kopieren')) ?></button> <button class="btn-ghost" type="button" onclick="window.print()"><?= e(tx('Drucken')) ?></button> <a class="btn" href="/account"><?= e(tx('Ich habe sie gesichert')) ?></a></div>
</section>
<?php else: ?>
<section class="card narrow wide">
  <h1><?= e(tx('Authenticator-App einrichten')) ?></h1>
  <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <p class="muted"><?= e(tx('Scanne den Code mit einer Authenticator-App (zum Beispiel der Passwörter-App von Apple, Google Authenticator oder 1Password) und gib danach den sechsstelligen Code ein, den die App anzeigt.')) ?></p>
  <div class="qr-box"><div class="qr"><?= wb_qr_svg(totp_uri($secret, (string)$u['email'])) ?></div>
    <div><p class="muted"><?= e(tx('Ohne Kamera: Trage diesen Schlüssel von Hand ein.')) ?></p><p><code class="token"><?= e(trim(chunk_split($secret, 4, ' '))) ?></code></p></div></div>
  <form method="post" class="form"><?= ui_csrf_field() ?><input type="hidden" name="s" value="<?= e(wb_seal('pending', $secret)) ?>">
    <div class="row"><label for="code"><?= e(tx('Code aus der App')) ?></label><input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="8" autofocus required></div>
    <div class="actions"><button class="btn" type="submit"><?= e(tx('Einschalten')) ?></button> <a href="/account"><?= e(tx('Abbrechen')) ?></a></div>
  </form>
</section>
<?php endif;
    ui_footer();
    exit;
}
if ($sub === 'token') {
    // Neues persönliches Token: erst nach erneuter Passwortabfrage; der Klartext erscheint nur in dieser einen Antwort.
    if (!wb_can('api_token.manage')) page_not_found();
    ui_require_reauth();
    $spaces = wb_workspaces($u);
    $raw = null;
    if ($post) {
        $label = wb_clean_text($_POST['label'] ?? '', 80);
        $days = (int)($_POST['days'] ?? 0);
        $wsIn = (string)($_POST['workspace'] ?? 'private');
        $org = $wsIn === 'private' ? null : wb_valid_workspace($u, (int)$wsIn);
        if ($label === '') $error = tx('Gib dem Token eine Bezeichnung, an der du es wiedererkennst.');
        elseif (!in_array($days, API_TOKEN_DAYS, true)) $error = tx('Bitte eine Gültigkeit wählen.');
        elseif ($wsIn !== 'private' && $org === null) $error = tx('Diesen Arbeitsbereich gibt es für dein Konto nicht.');
        elseif (count(api_tokens_list($uid)) >= API_TOKEN_MAX) $error = tx('Du hast schon {n} Tokens. Widerrufe eines, das du nicht mehr brauchst.', ['n' => API_TOKEN_MAX]);
        else {
            [$tid, $raw] = api_token_create($uid, $org, 'personal', $label, $days);
            audit_log('token.create', 'api_token', $tid, ['label' => $label, 'days' => $days], $org, $uid, 'self');
            account_notify($u, tx('Neues persönliches Token für dein Konto bei {app}', ['app' => wb_app_name()]), tx("In deinem Konto wurde soeben das persönliche Token «{label}» für die Schnittstelle erzeugt.\n\nWenn du das nicht warst, widerrufe es sofort und ändere dein Passwort:\n\n{url}", ['label' => $label, 'url' => wb_base_url() . '/account#tokens']));
        }
    }
    ui_header(tx('Persönliches Token'), 'account');
    if ($raw !== null): ?>
<section class="card narrow wide">
  <h1><?= e(tx('Dein neues Token')) ?></h1>
  <p><?= e(tx('Kopiere es jetzt. Es wird nur dieses eine Mal angezeigt; gespeichert ist nur ein Hash.')) ?></p>
  <p><code class="token" style="word-break:break-all"><?= e($raw) ?></code> <button class="btn-ghost" type="button" data-copy="<?= e($raw) ?>"><?= e(tx('Kopieren')) ?></button></p>
  <p class="muted"><?= e(tx('Schick es im Header «Authorization: Bearer <Token>» an {url}. Behandle es wie ein Passwort.', ['url' => wb_base_url() . '/api/'])) ?></p>
  <div class="actions"><a class="btn" href="/account#tokens"><?= e(tx('Ich habe es gesichert')) ?></a></div>
</section>
<?php else: ?>
<section class="card narrow wide">
  <h1><?= e(tx('Neues persönliches Token')) ?></h1>
  <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <p class="muted"><?= e(tx('Ein persönliches Token ist für eigene Skripte an der Schnittstelle gedacht. Es erreicht nur deine eigenen Boards und Aktivitäten im gewählten Arbeitsbereich. Für Claude brauchst du keines: Dort trägst du die Adresse des Connectors ein.')) ?></p>
  <form method="post" class="form"><?= ui_csrf_field() ?>
    <div class="row"><label for="tl"><?= e(tx('Bezeichnung')) ?></label><input id="tl" name="label" type="text" maxlength="80" value="<?= e((string)($_POST['label'] ?? '')) ?>" required autofocus></div>
    <div class="row two"><div><label for="tw"><?= e(tx('Arbeitsbereich')) ?></label><select id="tw" name="workspace"><?php foreach ($spaces as $sp): ?><option value="<?= $sp['org_id'] === null ? 'private' : (int)$sp['org_id'] ?>"><?= e($sp['name']) ?></option><?php endforeach; ?></select></div>
      <div><label for="td"><?= e(tx('Gültig')) ?></label><select id="td" name="days"><?php foreach (API_TOKEN_DAYS as $d): ?><option value="<?= $d ?>" <?= $d === 90 ? 'selected' : '' ?>><?= e(tx('{n} Tage', ['n' => $d])) ?></option><?php endforeach; ?></select></div></div>
    <div class="actions"><button class="btn" type="submit"><?= e(tx('Token erzeugen')) ?></button> <a href="/account#tokens"><?= e(tx('Abbrechen')) ?></a></div>
  </form>
</section>
<?php endif;
    ui_footer();
    exit;
}
if ($sub !== '') page_not_found();

$newCodes = null;
if ($post) {
    $action = (string)($_POST['action'] ?? '');
    switch ($action) {
        case 'profile':
            $first = wb_clean_text($_POST['first_name'] ?? '', 80);
            $last = wb_clean_text($_POST['last_name'] ?? '', 80);
            if ($first === '' || $last === '') { $error = tx('Bitte Vor- und Nachnamen angeben.'); break; }
            $locale = in_array($_POST['locale'] ?? '', wb_langs(), true) ? (string)$_POST['locale'] : 'de';
            db_exec('UPDATE users SET first_name = ?, last_name = ?, locale = ? WHERE id = ?', [$first, $last, $locale, $uid]);
            ui_redirect('/account', tx('Profil gespeichert.'));
        case 'password':
            if (brake_count('reauth|' . $uid, ACC_WINDOW) >= 10 || !password_verify((string)($_POST['current'] ?? ''), (string)$u['password_hash'])) {
                brake_hit('reauth|' . $uid);
                $error = tx('Das bisherige Passwort stimmt nicht.');
                break;
            }
            $pw = (string)($_POST['password'] ?? '');
            $error = wb_password_problem($pw, [(string)$u['email'], strstr((string)$u['email'], '@', true), $u['first_name'], $u['last_name']]);
            if ($error === null && $pw !== (string)($_POST['password2'] ?? '')) $error = tx('Die Passwörter stimmen nicht überein.');
            if ($error !== null) break;
            user_set_password($uid, $pw);
            wb_sessions_revoke_user($uid, $session['id']);
            account_notify($u, tx('Dein Passwort für {app} wurde geändert', ['app' => wb_app_name()]), tx('Das Passwort deines Kontos wurde soeben in den Kontoeinstellungen geändert. Wenn du das nicht warst, setze es sofort zurück:') . "\n\n" . wb_base_url() . '/login/reset');
            ui_redirect('/account', tx('Passwort geändert. Alle anderen Sitzungen sind beendet.'));
        case 'email':
            ui_require_reauth();
            $error = account_email_change_request($u, (string)($_POST['email'] ?? ''));
            if ($error === null) ui_redirect('/account', tx('Wir haben einen Link an die neue Adresse geschickt. Die Änderung gilt, sobald du ihn öffnest.'));
            break;
        case 'totp_disable':
            ui_require_reauth();
            if ($staff) { $error = tx('Für dein Konto ist die App-Stufe Pflicht; sie lässt sich nicht ausschalten.'); break; }
            totp_disable($uid);
            audit_log('account.totp_disable', 'user', $uid, [], null, $uid, 'self');
            account_notify($u, tx('Die zweite Stufe deines Kontos bei {app} wurde ausgeschaltet', ['app' => wb_app_name()]), tx('Die Anmeldung mit der Authenticator-App wurde in den Kontoeinstellungen ausgeschaltet. Wenn du das nicht warst, ändere sofort dein Passwort.'));
            ui_redirect('/account', tx('Die App-Stufe ist ausgeschaltet.'));
        case 'recovery':
            ui_require_reauth();
            if (user_has_totp($u)) $newCodes = totp_recovery_regenerate($uid);
            break;
        case 'grant_revoke':
            if (wb_can('connector.connect')) oauth_grant_revoke_own((int)($_POST['id'] ?? 0), $uid);
            ui_redirect('/account#verbindungen', tx('Verbindung widerrufen. Das Programm hat ab sofort keinen Zugang mehr.'));
        case 'token_revoke':
            if (wb_can('api_token.manage')) api_token_revoke((int)($_POST['id'] ?? 0), $uid);
            ui_redirect('/account#tokens', tx('Token widerrufen.'));
        case 'session_revoke':
            wb_session_revoke((int)($_POST['id'] ?? 0), $uid);
            ui_redirect('/account#sitzungen', tx('Sitzung beendet.'));
        case 'sessions_all':
            wb_sessions_revoke_user($uid, $session['id']);
            ui_redirect('/account#sitzungen', tx('Alle anderen Sitzungen sind beendet.'));
        case 'device_forget':
            db_exec('DELETE FROM known_devices WHERE id = ? AND user_id = ?', [(int)($_POST['id'] ?? 0), $uid]);
            ui_redirect('/account#geraete', tx('Gerät vergessen.'));
        case 'devices_all':
            db_exec('DELETE FROM known_devices WHERE user_id = ?', [$uid]);
            ui_redirect('/account#geraete', tx('Alle Geräte vergessen.'));
    }
    $u = user_reload($uid);
}
$sessions = db_all('SELECT id, kind, mfa_level, ip, user_agent, created_at, last_seen_at FROM sessions WHERE user_id = ? AND revoked_at IS NULL AND expires_at > ? ORDER BY last_seen_at DESC LIMIT 50', [$uid, wb_now()]);
$devices = db_all('SELECT id, user_agent, ip, created_at, last_used_at FROM known_devices WHERE user_id = ? AND expires_at > ? ORDER BY last_used_at DESC LIMIT 50', [$uid, wb_now()]);
$roleLabels = ['superadmin' => 'Super-Admin', 'admin' => 'Admin', 'license_manager' => tx('Lizenzverwaltung'), 'presenter' => tx('Vorführung')];
$teacher = user_teacher_state($u);
$uaShort = function (string $ua): string {
    if ($ua === '') return tx('unbekanntes Gerät');
    $os = preg_match('/iPhone|iPad|Android|Macintosh|Windows|Linux|CrOS/', $ua, $m) ? $m[0] : '';
    $br = preg_match('/Edg|OPR|Firefox|Chrome|Safari|curl/', $ua, $m) ? ['Edg' => 'Edge', 'OPR' => 'Opera'][$m[0]] ?? $m[0] : '';
    return trim($br . ($os !== '' ? ' · ' . $os : '')) ?: mb_substr($ua, 0, 40);
};
ui_header(tx('Konto'), 'account');
?>
<div class="page-head"><div><h1><?= e(tx('Konto')) ?></h1><p class="muted"><?= e((string)$u['email']) ?><?php foreach ($staff as $r): ?> <span class="pill fb-approved"><?= e($roleLabels[$r] ?? $r) ?></span><?php endforeach; ?><?= $teacher === 'approved' ? ' <span class="pill status-open">' . e(tx('Lehrperson-Freigabe')) . '</span>' : '' ?></p></div></div>
<?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
<?php if ($newCodes): ?>
<section class="card"><h2><?= e(tx('Neue Wiederherstellungscodes')) ?></h2>
  <p><?= e(tx('Die bisherigen Codes gelten nicht mehr. Diese zehn werden nur jetzt angezeigt.')) ?></p>
  <pre class="codes"><?= e(implode("\n", $newCodes)) ?></pre>
  <div class="actions"><button class="btn-ghost" type="button" data-copy="<?= e(implode("\n", $newCodes)) ?>"><?= e(tx('Kopieren')) ?></button> <button class="btn-ghost" type="button" onclick="window.print()"><?= e(tx('Drucken')) ?></button></div>
</section>
<?php endif; ?>
<div class="grid-2">
<section class="card"><h2><?= e(tx('Profil')) ?></h2>
  <form method="post" class="form"><?= ui_csrf_field() ?><input type="hidden" name="action" value="profile">
    <div class="row two"><div><label for="fn"><?= e(tx('Vorname')) ?></label><input id="fn" name="first_name" type="text" autocomplete="given-name" value="<?= e((string)$u['first_name']) ?>" required></div>
      <div><label for="ln"><?= e(tx('Nachname')) ?></label><input id="ln" name="last_name" type="text" autocomplete="family-name" value="<?= e((string)$u['last_name']) ?>" required></div></div>
    <div class="row"><label for="loc"><?= e(tx('Sprache')) ?></label><select id="loc" name="locale"><?php foreach (['de' => 'Deutsch', 'en' => 'English'] as $k => $l): ?><option value="<?= $k ?>" <?= $u['locale'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
      <p class="hint"><?= e(tx('Die Oberfläche der Verwaltung ist vorerst deutsch.')) ?></p></div>
    <div class="actions"><button class="btn" type="submit"><?= e(tx('Speichern')) ?></button></div>
  </form>
</section>
<section class="card"><h2><?= e(tx('E-Mail-Adresse')) ?></h2>
  <p class="muted"><?= e(tx('Die Adresse ist dein Benutzername. Eine neue Adresse gilt erst, wenn du den Link öffnest, den wir dorthin schicken; die bisherige erhält eine Mitteilung und kann die Änderung sieben Tage lang rückgängig machen.')) ?></p>
  <form method="post" class="form"><?= ui_csrf_field() ?><input type="hidden" name="action" value="email">
    <div class="row"><label for="em"><?= e(tx('Neue Adresse')) ?></label><input id="em" name="email" type="email" autocomplete="off" autocapitalize="none" spellcheck="false" required></div>
    <div class="actions"><button class="btn-ghost" type="submit"><?= e(tx('Adresse ändern')) ?></button></div>
  </form>
</section>
<section class="card"><h2><?= e(tx('Passwort')) ?></h2>
  <form method="post" class="form"><?= ui_csrf_field() ?><input type="hidden" name="action" value="password">
    <input type="email" name="username" autocomplete="username" value="<?= e((string)$u['email']) ?>" hidden>
    <div class="row"><label for="cur"><?= e(tx('Bisheriges Passwort')) ?></label><input id="cur" name="current" type="password" autocomplete="current-password" required></div>
    <div class="row"><label for="pw"><?= e(tx('Neues Passwort')) ?></label><input id="pw" name="password" type="password" autocomplete="new-password" minlength="12" required>
      <p class="hint"><?= e(tx('Mindestens zwölf Zeichen. Gut sind vier zufällige Wörter.')) ?></p></div>
    <div class="row"><label for="pw2"><?= e(tx('Passwort wiederholen')) ?></label><input id="pw2" name="password2" type="password" autocomplete="new-password" minlength="12" required></div>
    <div class="actions"><button class="btn-ghost" type="submit"><?= e(tx('Passwort ändern')) ?></button></div>
  </form>
</section>
<section class="card"><h2><?= e(tx('Zweite Stufe')) ?></h2>
  <?php if (user_has_totp($u)): ?>
  <p><span class="pill status-open"><?= e(tx('Authenticator-App eingerichtet')) ?></span> <span class="muted"><?= e(tx('{n} Wiederherstellungscodes übrig', ['n' => totp_recovery_left($u)])) ?></span></p>
  <?php if (!empty($u['totp_removal_at'])): ?><p class="error"><?= e(tx('Für dieses Konto läuft ein Antrag, die App-Stufe zu entfernen. Er wird am {t} wirksam. Wenn du das nicht warst, benutze den Link aus der Mail.', ['t' => (string)wb_local_time((string)$u['totp_removal_at'])])) ?></p><?php endif; ?>
  <div class="action-grid">
    <form method="post"><?= ui_csrf_field() ?><input type="hidden" name="action" value="recovery"><button class="btn-ghost" type="submit"><?= e(tx('Neue Wiederherstellungscodes')) ?></button></form>
    <?php if (!$staff): ?><form method="post" data-confirm="<?= e(tx('App-Stufe ausschalten? Danach schützt nur noch der Mailcode auf unbekannten Geräten.')) ?>"><?= ui_csrf_field() ?><input type="hidden" name="action" value="totp_disable"><button class="btn-ghost danger" type="submit"><?= e(tx('Ausschalten')) ?></button></form><?php endif; ?>
  </div>
  <?php else: ?>
  <p class="muted"><?= e($staff ? tx('Für dein Konto ist die Anmeldung mit einer Authenticator-App Pflicht. Ohne sie bleibt die Verwaltung gesperrt.') : tx('Ohne App schicken wir dir bei der Anmeldung von einem unbekannten Gerät einen Code per Mail. Mit einer Authenticator-App ist dein Konto besser geschützt.')) ?></p>
  <div class="actions"><a class="btn" href="/account/totp"><?= e(tx('Authenticator-App einrichten')) ?></a></div>
  <?php endif; ?>
</section>
</div>
<?php
$spaces = wb_workspaces($u);
$grants = oauth_grants_list($uid);
$tokens = api_tokens_list($uid);
$spaceName = function (?string $org) use ($spaces): string {
    foreach ($spaces as $sp) if (($sp['org_id'] === null && $org === null) || ($org !== null && $sp['org_id'] === (int)$org)) return $sp['name'];
    return (string)(org_by_id((int)$org)['name'] ?? '?');
};
?>
<section class="card" id="verbindungen"><h2><?= e(tx('Connector für Claude')) ?></h2>
  <p class="muted"><?= e(tx('Trage in Claude unter «Connectoren» die Adresse deines Arbeitsbereichs als eigenen Connector ein. Claude leitet dich danach hierher, du meldest dich an und stimmst der Verbindung zu. Jeder Arbeitsbereich hat seinen eigenen Connector; du kannst mehrere nebeneinander verbinden.')) ?> <a href="/help/connector"><?= e(tx('Mehr dazu in der Hilfe')) ?></a></p>
  <table class="results plain"><tr><th><?= e(tx('Arbeitsbereich')) ?></th><th><?= e(tx('Adresse des Connectors')) ?></th></tr>
  <?php foreach ($spaces as $sp): $url = (string)mcp_resource_url($sp['org_id']); ?>
    <tr><td><?= e($sp['name']) ?></td><td><code class="token" style="word-break:break-all"><?= e($url) ?></code> <button class="btn-ghost" type="button" data-copy="<?= e($url) ?>"><?= e(tx('Kopieren')) ?></button></td></tr>
  <?php endforeach; ?></table>
  <h3><?= e(tx('Verbindungen')) ?></h3>
  <?php if (!$grants): ?><p class="muted"><?= e(tx('Kein Programm ist mit deinem Konto verbunden.')) ?></p><?php else: ?>
  <table class="results plain"><tr><th><?= e(tx('Programm')) ?></th><th><?= e(tx('Arbeitsbereich')) ?></th><th><?= e(tx('Verbunden')) ?></th><th><?= e(tx('Zuletzt benutzt')) ?></th><th></th></tr>
  <?php foreach ($grants as $g): $ru = (array)json_decode((string)$g['redirect_uris_json'], true); ?>
    <tr><td><?= e($g['client_name'] !== '' ? (string)$g['client_name'] : tx('Programm ohne Namen')) ?><br><span class="muted"><?= e(oauth_redirect_host((string)($ru[0] ?? ''))) ?></span></td>
      <td><?= e($spaceName($g['org_id'])) ?></td><td><?= e((string)wb_local_time((string)$g['created_at'])) ?></td><td><?= e($g['last_used_at'] ? (string)wb_local_time((string)$g['last_used_at']) : '–') ?></td>
      <td><form method="post" class="inline" data-confirm="<?= e(tx('Verbindung widerrufen? Das Programm verliert den Zugang sofort.')) ?>"><?= ui_csrf_field() ?><input type="hidden" name="action" value="grant_revoke"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>"><button class="link-danger" type="submit"><?= e(tx('Widerrufen')) ?></button></form></td></tr>
  <?php endforeach; ?></table>
  <?php endif; ?>
</section>
<section class="card" id="tokens"><h2><?= e(tx('Persönliche Tokens')) ?></h2>
  <p class="muted"><?= e(tx('Für eigene Skripte an der Schnittstelle. Ein Token erreicht nur deine eigenen Inhalte in seinem Arbeitsbereich und nie die Verwaltung.')) ?></p>
  <?php if ($tokens): ?>
  <table class="results plain"><tr><th><?= e(tx('Bezeichnung')) ?></th><th><?= e(tx('Arbeitsbereich')) ?></th><th><?= e(tx('Erzeugt')) ?></th><th><?= e(tx('Zuletzt benutzt')) ?></th><th><?= e(tx('Gültig bis')) ?></th><th></th></tr>
  <?php foreach ($tokens as $t): ?>
    <tr><td><?= e((string)$t['label']) ?><?= $t['purpose'] === 'legacy_connector' ? ' <span class="pill status-closed">' . e(tx('alter Connector')) . '</span>' : '' ?><br><code class="muted"><?= e((string)$t['token_prefix']) ?>…</code></td>
      <td><?= e($spaceName($t['org_id'])) ?></td><td><?= e((string)wb_local_time((string)$t['created_at'])) ?></td><td><?= e($t['last_used_at'] ? (string)wb_local_time((string)$t['last_used_at']) : '–') ?></td><td><?= e($t['expires_at'] ? (string)wb_local_time((string)$t['expires_at']) : tx('ohne Ablauf')) ?></td>
      <td><form method="post" class="inline" data-confirm="<?= e(tx('Token widerrufen? Skripte mit diesem Token verlieren den Zugang sofort.')) ?>"><?= ui_csrf_field() ?><input type="hidden" name="action" value="token_revoke"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="link-danger" type="submit"><?= e(tx('Widerrufen')) ?></button></form></td></tr>
  <?php endforeach; ?></table>
  <?php endif; ?>
  <div class="actions"><a class="btn-ghost" href="/account/token"><?= e(tx('Neues Token')) ?></a></div>
</section>
<section class="card" id="sitzungen"><h2><?= e(tx('Sitzungen')) ?></h2>
  <table class="results plain"><tr><th><?= e(tx('Gerät')) ?></th><th><?= e(tx('IP-Adresse')) ?></th><th><?= e(tx('Angemeldet')) ?></th><th><?= e(tx('Zuletzt aktiv')) ?></th><th></th></tr>
  <?php foreach ($sessions as $s): $cur = (int)$s['id'] === $session['id']; ?>
    <tr><td><?= e($uaShort((string)$s['user_agent'])) ?><?= $cur ? ' <span class="pill status-open">' . e(tx('diese Sitzung')) . '</span>' : '' ?><?= $s['kind'] === 'test' ? ' <span class="pill status-closed">Testrolle</span>' : '' ?></td>
      <td><?= e((string)$s['ip']) ?></td><td><?= e((string)wb_local_time((string)$s['created_at'])) ?></td><td><?= e((string)wb_local_time((string)$s['last_seen_at'])) ?></td>
      <td><?php if (!$cur): ?><form method="post" class="inline"><?= ui_csrf_field() ?><input type="hidden" name="action" value="session_revoke"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="link-danger" type="submit"><?= e(tx('Beenden')) ?></button></form><?php endif; ?></td></tr>
  <?php endforeach; ?></table>
  <?php if (count($sessions) > 1): ?><form method="post" class="actions"><?= ui_csrf_field() ?><input type="hidden" name="action" value="sessions_all"><button class="btn-ghost" type="submit"><?= e(tx('Überall sonst abmelden')) ?></button></form><?php endif; ?>
</section>
<section class="card" id="geraete"><h2><?= e(tx('Bekannte Geräte')) ?></h2>
  <p class="muted"><?= e(tx('Auf einem bekannten Gerät fragen wir ohne App-Stufe keinen Mailcode ab. Ein Gerät gilt 180 Tage als bekannt.')) ?></p>
  <?php if (!$devices): ?><p class="muted"><?= e(tx('Keine.')) ?></p><?php else: ?>
  <table class="results plain"><tr><th><?= e(tx('Gerät')) ?></th><th><?= e(tx('IP-Adresse')) ?></th><th><?= e(tx('Seit')) ?></th><th><?= e(tx('Zuletzt benutzt')) ?></th><th></th></tr>
  <?php foreach ($devices as $d): ?>
    <tr><td><?= e($uaShort((string)$d['user_agent'])) ?></td><td><?= e((string)$d['ip']) ?></td><td><?= e((string)wb_local_time((string)$d['created_at'])) ?></td><td><?= e((string)wb_local_time((string)$d['last_used_at'])) ?></td>
      <td><form method="post" class="inline"><?= ui_csrf_field() ?><input type="hidden" name="action" value="device_forget"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="link-danger" type="submit"><?= e(tx('Vergessen')) ?></button></form></td></tr>
  <?php endforeach; ?></table>
  <form method="post" class="actions"><?= ui_csrf_field() ?><input type="hidden" name="action" value="devices_all"><button class="btn-ghost" type="submit"><?= e(tx('Alle vergessen')) ?></button></form>
  <?php endif; ?>
</section>
<?php
ui_footer();
