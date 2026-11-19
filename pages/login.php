<?php
declare(strict_types=1);
// /login (Adresse und Passwort), /login/code (zweite Stufe), /login/confirm (Passwort erneut vor heiklen
// Handlungen), /login/reset (Passwort vergessen). Eingebunden von page.php.
if (!defined('WB_ROOT')) exit;

$next = ui_safe_next($_POST['next'] ?? $_GET['next'] ?? null, '');
$after = fn(): string => $next !== '' ? $next : wb_admin_url();
$error = null;
$info = null;
ui_check_csrf();
$post = $_SERVER['REQUEST_METHOD'] === 'POST';

/** Nach der letzten Stufe: Konten mit Systemrolle ohne App-Stufe richten sie zuerst ein. */
$finish = function (array $user, string $mfa) use ($after): never {
    login_finish($user, $mfa);
    if (user_system_roles((int)$user['id']) !== [] && !user_has_totp($user)) {
        ui_redirect('/account/totp', tx('Für dein Konto ist die Anmeldung mit einer Authenticator-App Pflicht. Richte sie jetzt ein.'));
    }
    ui_redirect($after());
};

if ($sub === '') {
    if ($u) ui_redirect($after());
    if ($post) {
        $r = login_check_password((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''));
        if ($r['state'] === 'wait') {
            http_response_code(429);
            header('Retry-After: ' . (int)$r['seconds']);
            $error = tx('Zu viele Fehlversuche. Bitte in {n} Minuten wieder versuchen.', ['n' => max(1, (int)ceil($r['seconds'] / 60))]);
        } elseif ($r['state'] !== 'ok') {
            $error = tx('Adresse oder Passwort stimmt nicht, oder die Adresse ist noch nicht bestätigt.');
        } else {
            $factor = login_second_factor($r['user']);
            if ($factor === 'none') $finish($r['user'], 'none');
            login_pending_set((int)$r['user']['id'], $factor);
            if ($factor === 'mail') login_send_mail_code($r['user']);
            ui_redirect('/login/code' . ($next !== '' ? '?next=' . urlencode($next) : ''));
        }
    }
    ui_header(tx('Anmelden'), 'login');
    ?>
<section class="card narrow">
  <h1><?= e(tx('Anmelden')) ?></h1>
  <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <form method="post" class="form">
    <?= ui_csrf_field() ?><input type="hidden" name="next" value="<?= e($next) ?>">
    <div class="row"><label for="email"><?= e(tx('E-Mail-Adresse')) ?></label>
      <input id="email" name="email" type="email" autocomplete="username" inputmode="email" autocapitalize="none" spellcheck="false" value="<?= e((string)($_POST['email'] ?? '')) ?>" autofocus required></div>
    <div class="row"><label for="pw"><?= e(tx('Passwort')) ?></label>
      <input id="pw" name="password" type="password" autocomplete="current-password" required></div>
    <div class="actions"><button class="btn" type="submit"><?= e(tx('Anmelden')) ?></button> <a href="/login/reset"><?= e(tx('Passwort vergessen?')) ?></a></div>
  </form>
  <?php if ($mode === 'open'): ?><p class="hint"><?= e(tx('Noch kein Konto?')) ?> <a href="/signup"><?= e(tx('Registrieren')) ?></a></p><?php endif; ?>
</section>
<?php
    ui_footer();
    exit;
}

if ($sub === 'code') {
    $pending = login_pending();
    if (!$pending) ui_redirect('/login' . ($next !== '' ? '?next=' . urlencode($next) : ''));
    $isTotp = $pending['method'] === 'totp';
    if ($post && ($_POST['action'] ?? '') === 'resend' && !$isTotp) {
        login_send_mail_code($pending['user']);
        $info = tx('Wir haben dir einen neuen Code geschickt.');
    } elseif ($post) {
        if (login_check_second($pending, (string)($_POST['code'] ?? ''))) $finish($pending['user'], $isTotp ? 'totp' : 'mail');
        $error = tx('Dieser Code stimmt nicht oder ist abgelaufen.');
    }
    ui_header(tx('Anmelden'), 'login');
    ?>
<section class="card narrow">
  <h1><?= e(tx('Zweite Stufe')) ?></h1>
  <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <?php if ($info): ?><p class="flash"><?= e($info) ?></p><?php endif; ?>
  <p class="muted"><?= e($isTotp ? tx('Gib den sechsstelligen Code aus deiner Authenticator-App ein oder einen Wiederherstellungscode.') : tx('Wir haben dir einen sechsstelligen Code per Mail geschickt. Er gilt zehn Minuten.')) ?></p>
  <form method="post" class="form">
    <?= ui_csrf_field() ?><input type="hidden" name="next" value="<?= e($next) ?>">
    <div class="row"><label for="code"><?= e(tx('Code')) ?></label>
      <input id="code" name="code" type="text" inputmode="<?= $isTotp ? 'text' : 'numeric' ?>" autocomplete="one-time-code" autocapitalize="none" spellcheck="false" maxlength="20" autofocus required></div>
    <div class="actions"><button class="btn" type="submit"><?= e(tx('Weiter')) ?></button></div>
  </form>
  <?php if (!$isTotp): ?>
  <form method="post" class="form"><?= ui_csrf_field() ?><input type="hidden" name="next" value="<?= e($next) ?>"><input type="hidden" name="action" value="resend">
    <p class="hint"><?= e(tx('Nichts erhalten? Sieh im Spam nach oder')) ?> <button class="link-undo" type="submit"><?= e(tx('fordere einen neuen Code an')) ?></button>.</p></form>
  <?php else: ?>
  <p class="hint"><?= e(tx('App und Wiederherstellungscodes verloren? Schreib an {mail}; das Entfernen der zweiten Stufe dauert aus Sicherheitsgründen 72 Stunden.', ['mail' => 'abuse@tafula.top'])) ?></p>
  <?php endif; ?>
</section>
<?php
    ui_footer();
    exit;
}

if ($sub === 'confirm') {
    $u = ui_require_login();
    if ($post) {
        $uid = (int)$u['id'];
        if (brake_count('reauth|' . $uid, ACC_WINDOW) >= 10) {
            $error = tx('Zu viele Fehlversuche. Bitte in {n} Minuten wieder versuchen.', ['n' => ACC_WINDOW]);
        } elseif (!password_verify((string)($_POST['password'] ?? ''), (string)$u['password_hash'])) {
            brake_hit('reauth|' . $uid);
            $error = tx('Das Passwort stimmt nicht.');
        } else {
            wb_reauth_mark();
            ui_redirect($next !== '' ? $next : '/account');
        }
    }
    ui_header(tx('Passwort bestätigen'), 'account');
    ?>
<section class="card narrow">
  <h1><?= e(tx('Passwort bestätigen')) ?></h1>
  <p class="muted"><?= e(tx('Für diesen Schritt fragen wir dein Passwort noch einmal ab.')) ?></p>
  <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <form method="post" class="form">
    <?= ui_csrf_field() ?><input type="hidden" name="next" value="<?= e($next) ?>">
    <input type="email" name="email" autocomplete="username" value="<?= e((string)$u['email']) ?>" hidden>
    <div class="row"><label for="pw"><?= e(tx('Passwort')) ?></label><input id="pw" name="password" type="password" autocomplete="current-password" autofocus required></div>
    <div class="actions"><button class="btn" type="submit"><?= e(tx('Weiter')) ?></button></div>
  </form>
</section>
<?php
    ui_footer();
    exit;
}

if ($sub === 'reset') {
    $token = (string)($_POST['t'] ?? $_GET['t'] ?? '');
    $done = false;
    if ($token === '') {
        if ($post) { account_reset_request((string)($_POST['email'] ?? '')); $done = true; }
        ui_header(tx('Passwort vergessen'), 'login');
        ?>
<section class="card narrow">
  <h1><?= e(tx('Passwort vergessen')) ?></h1>
  <?php if ($done): ?>
  <p><?= e(tx('Wenn es zu dieser Adresse ein Konto gibt, haben wir einen Link zum Zurücksetzen geschickt. Er gilt eine Stunde.')) ?></p>
  <p><a href="/login"><?= e(tx('Zur Anmeldung')) ?></a></p>
  <?php else: ?>
  <form method="post" class="form"><?= ui_csrf_field() ?>
    <div class="row"><label for="email"><?= e(tx('E-Mail-Adresse')) ?></label><input id="email" name="email" type="email" autocomplete="username" autofocus required></div>
    <div class="actions"><button class="btn" type="submit"><?= e(tx('Link schicken')) ?></button> <a href="/login"><?= e(tx('Abbrechen')) ?></a></div>
  </form>
  <?php endif; ?>
</section>
<?php
        ui_footer();
        exit;
    }
    $valid = login_token_find($token, 'reset_password') !== null;
    if ($post && $valid) {
        $error = account_reset_finish($token, (string)($_POST['password'] ?? ''), (string)($_POST['password2'] ?? ''));
        if ($error === null) { wb_logout(); ui_redirect('/login', tx('Das Passwort ist geändert. Melde dich jetzt neu an.')); }
    }
    ui_header(tx('Neues Passwort'), 'login');
    ?>
<section class="card narrow">
  <h1><?= e(tx('Neues Passwort')) ?></h1>
  <?php if (!$valid): ?>
  <p class="error" role="alert"><?= e(tx('Dieser Link ist nicht mehr gültig. Fordere einen neuen an.')) ?></p><p><a href="/login/reset"><?= e(tx('Neuen Link anfordern')) ?></a></p>
  <?php else: ?>
  <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <form method="post" class="form"><?= ui_csrf_field() ?><input type="hidden" name="t" value="<?= e($token) ?>">
    <div class="row"><label for="pw"><?= e(tx('Neues Passwort')) ?></label><input id="pw" name="password" type="password" autocomplete="new-password" minlength="12" autofocus required>
      <p class="hint"><?= e(tx('Mindestens zwölf Zeichen. Gut sind vier zufällige Wörter.')) ?></p></div>
    <div class="row"><label for="pw2"><?= e(tx('Passwort wiederholen')) ?></label><input id="pw2" name="password2" type="password" autocomplete="new-password" minlength="12" required></div>
    <div class="actions"><button class="btn" type="submit"><?= e(tx('Passwort setzen')) ?></button></div>
  </form>
  <?php endif; ?>
</section>
<?php
    ui_footer();
    exit;
}
page_not_found();
