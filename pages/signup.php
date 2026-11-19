<?php
declare(strict_types=1);
// /signup (nur bei offener Registrierung) und /signup/verify (Bestätigung der Adresse mit dem gewählten Passwort).
if (!defined('WB_ROOT')) exit;
ui_check_csrf();
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$error = null;

if ($sub === 'verify') {
    $token = (string)($_POST['t'] ?? $_GET['t'] ?? '');
    $valid = login_token_find($token, 'verify_email') !== null;
    if ($post && $valid) {
        $user = account_verify($token, (string)($_POST['password'] ?? ''));
        if ($user) {
            // Das Gerät, auf dem die Adresse bestätigt wird, gilt als bekannt.
            login_finish($user, 'mail');
            ui_redirect(wb_admin_url(), tx('Deine Adresse ist bestätigt. Willkommen bei {app}.', ['app' => wb_app_name()]));
        }
        $error = tx('Das Passwort stimmt nicht.');
    }
    ui_header(tx('Adresse bestätigen'), 'login');
    ?>
<section class="card narrow">
  <h1><?= e(tx('Adresse bestätigen')) ?></h1>
  <?php if (!$valid): ?>
  <p class="error" role="alert"><?= e(tx('Dieser Link ist nicht mehr gültig. Registriere dich noch einmal oder melde dich an, falls die Adresse schon bestätigt ist.')) ?></p>
  <p><a href="/login"><?= e(tx('Zur Anmeldung')) ?></a></p>
  <?php else: ?>
  <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <p class="muted"><?= e(tx('Gib zum Abschluss das Passwort ein, das du bei der Registrierung gewählt hast.')) ?></p>
  <form method="post" class="form"><?= ui_csrf_field() ?><input type="hidden" name="t" value="<?= e($token) ?>">
    <div class="row"><label for="pw"><?= e(tx('Passwort')) ?></label><input id="pw" name="password" type="password" autocomplete="current-password" autofocus required></div>
    <div class="actions"><button class="btn" type="submit"><?= e(tx('Bestätigen und anmelden')) ?></button></div>
  </form>
  <?php endif; ?>
</section>
<?php
    ui_footer();
    exit;
}
if ($sub !== '') page_not_found();
if ($u) ui_redirect(wb_admin_url());

if ($mode !== 'open') {
    ui_header(tx('Registrieren'), 'login');
    echo '<section class="card narrow"><h1>' . e(tx('Registrieren')) . '</h1><p>' . e(tx('Ein Konto gibt es zurzeit nur mit einer Einladung. Wenn du eine erhalten hast, benutze den Link aus der Mail.')) . '</p><p><a href="/login">' . e(tx('Zur Anmeldung')) . '</a></p></section>';
    ui_footer();
    exit;
}
$done = false;
if ($post) {
    $error = account_signup($_POST);
    $done = $error === null;
}
ui_header(tx('Registrieren'), 'login');
$v = fn(string $k): string => e((string)($_POST[$k] ?? ''));
?>
<section class="card narrow">
  <h1><?= e(tx('Registrieren')) ?></h1>
  <?php if ($done): ?>
  <p><?= e(tx('Fast geschafft: Wir haben dir eine Mail geschickt. Öffne den Link darin, um deine Adresse zu bestätigen. Erst danach kannst du dich anmelden.')) ?></p>
  <p class="hint"><?= e(tx('Nichts erhalten? Sieh im Spam nach. Ohne Bestätigung wird die Registrierung nach dreissig Tagen gelöscht.')) ?></p>
  <?php else: ?>
  <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <form method="post" class="form"><?= ui_csrf_field() ?>
    <div class="row two"><div><label for="fn"><?= e(tx('Vorname')) ?></label><input id="fn" name="first_name" type="text" autocomplete="given-name" value="<?= $v('first_name') ?>" required></div>
      <div><label for="ln"><?= e(tx('Nachname')) ?></label><input id="ln" name="last_name" type="text" autocomplete="family-name" value="<?= $v('last_name') ?>" required></div></div>
    <div class="row"><label for="email"><?= e(tx('E-Mail-Adresse')) ?></label><input id="email" name="email" type="email" autocomplete="username" autocapitalize="none" spellcheck="false" value="<?= $v('email') ?>" required></div>
    <div class="row hp" aria-hidden="true"><label for="website">Website</label><input id="website" name="website" type="text" tabindex="-1" autocomplete="off"></div>
    <div class="row"><label for="pw"><?= e(tx('Passwort')) ?></label><input id="pw" name="password" type="password" autocomplete="new-password" minlength="12" required>
      <p class="hint"><?= e(tx('Mindestens zwölf Zeichen. Gut sind vier zufällige Wörter.')) ?></p></div>
    <div class="row"><label for="pw2"><?= e(tx('Passwort wiederholen')) ?></label><input id="pw2" name="password2" type="password" autocomplete="new-password" minlength="12" required></div>
    <div class="row checks col"><label><input type="checkbox" name="age" value="1" <?= !empty($_POST['age']) ? 'checked' : '' ?> required> <?= e(tx('Ich bin mindestens 16 Jahre alt.')) ?></label>
      <label><input type="checkbox" name="terms" value="1" <?= !empty($_POST['terms']) ? 'checked' : '' ?> required> <?= e(tx('Ich habe die Nutzungsbedingungen und die Datenschutzerklärung gelesen und bin einverstanden.')) ?></label></div>
    <div class="actions"><button class="btn" type="submit"><?= e(tx('Konto anlegen')) ?></button> <a href="/login"><?= e(tx('Ich habe schon ein Konto')) ?></a></div>
  </form>
  <?php endif; ?>
</section>
<?php
ui_footer();
