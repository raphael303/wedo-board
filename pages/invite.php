<?php
declare(strict_types=1);
// /invite?t=…: Einladung des Super-Admins annehmen, mit einem neuen oder einem bestehenden Konto.
if (!defined('WB_ROOT')) exit;
if ($sub !== '') page_not_found();
ui_check_csrf();
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$token = (string)($_POST['t'] ?? $_GET['t'] ?? '');
$inv = invitation_find($token);
$error = null;
$self = '/invite?t=' . rawurlencode($token);

if (!$inv) {
    ui_header(tx('Einladung'), 'login');
    echo '<section class="card narrow"><h1>' . e(tx('Einladung')) . '</h1><p class="error" role="alert">' . e(tx('Diese Einladung ist nicht mehr gültig. Bitte um eine neue.')) . '</p></section>';
    ui_footer();
    exit;
}
$existing = user_by_email((string)$inv['email']);
$hasAccount = $existing !== null && $existing['email_verified_at'] !== null;

if ($hasAccount) {
    // Bestehendes Konto: anmelden und annehmen. Nur das Konto mit der eingeladenen Adresse darf annehmen.
    if (!$u) ui_redirect('/login?next=' . urlencode($self), tx('Melde dich an, um die Einladung anzunehmen.'));
    $mine = (int)$u['id'] === (int)$existing['id'];
    if ($post && $mine) {
        try {
            invitation_apply($inv, (int)$u['id']);
            ui_redirect('/account', tx('Die Einladung ist angenommen.'));
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
    }
    ui_header(tx('Einladung'), 'account');
    ?>
<section class="card narrow">
  <h1><?= e(tx('Einladung')) ?></h1>
  <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <?php if (!$mine): ?>
  <p class="error" role="alert"><?= e(tx('Diese Einladung gilt für eine andere Adresse als die deines Kontos. Melde dich mit dem richtigen Konto an.')) ?></p>
  <?php else: ?>
  <p><?= e(tx('Du bist eingeladen, {app} zu benutzen.', ['app' => wb_app_name()])) ?><?= (int)$inv['teacher_grant'] === 1 ? ' ' . e(tx('Die Einladung gibt dir die Lehrperson-Freigabe.')) : '' ?></p>
  <form method="post" class="form"><?= ui_csrf_field() ?><input type="hidden" name="t" value="<?= e($token) ?>"><div class="actions"><button class="btn" type="submit"><?= e(tx('Einladung annehmen')) ?></button></div></form>
  <?php endif; ?>
</section>
<?php
    ui_footer();
    exit;
}

if ($u) { wb_logout(); ui_redirect($self); }
if ($post) {
    [$user, $error] = invitation_accept_new($inv, $_POST);
    if ($user) {
        login_finish($user, 'mail');
        ui_redirect(wb_admin_url(), tx('Dein Konto ist angelegt. Willkommen bei {app}.', ['app' => wb_app_name()]));
    }
}
ui_header(tx('Einladung'), 'login');
$v = fn(string $k): string => e((string)($_POST[$k] ?? ''));
?>
<section class="card narrow">
  <h1><?= e(tx('Konto anlegen')) ?></h1>
  <p class="muted"><?= e(tx('Du bist eingeladen, {app} zu benutzen. Dein Konto erhält die Adresse {email}.', ['app' => wb_app_name(), 'email' => (string)$inv['email']])) ?></p>
  <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <form method="post" class="form"><?= ui_csrf_field() ?><input type="hidden" name="t" value="<?= e($token) ?>">
    <input type="email" name="email" autocomplete="username" value="<?= e((string)$inv['email']) ?>" hidden>
    <div class="row two"><div><label for="fn"><?= e(tx('Vorname')) ?></label><input id="fn" name="first_name" type="text" autocomplete="given-name" value="<?= $v('first_name') ?>" required></div>
      <div><label for="ln"><?= e(tx('Nachname')) ?></label><input id="ln" name="last_name" type="text" autocomplete="family-name" value="<?= $v('last_name') ?>" required></div></div>
    <div class="row"><label for="pw"><?= e(tx('Passwort')) ?></label><input id="pw" name="password" type="password" autocomplete="new-password" minlength="12" required>
      <p class="hint"><?= e(tx('Mindestens zwölf Zeichen. Gut sind vier zufällige Wörter.')) ?></p></div>
    <div class="row"><label for="pw2"><?= e(tx('Passwort wiederholen')) ?></label><input id="pw2" name="password2" type="password" autocomplete="new-password" minlength="12" required></div>
    <div class="row checks col"><label><input type="checkbox" name="age" value="1" required> <?= e(tx('Ich bin mindestens 16 Jahre alt.')) ?></label>
      <label><input type="checkbox" name="terms" value="1" required> <?= e(tx('Ich habe die Nutzungsbedingungen und die Datenschutzerklärung gelesen und bin einverstanden.')) ?></label></div>
    <div class="actions"><button class="btn" type="submit"><?= e(tx('Konto anlegen')) ?></button></div>
  </form>
</section>
<?php
ui_footer();
