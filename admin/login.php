<?php
declare(strict_types=1);
require __DIR__ . '/_layout.php';

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Einfache Bremse gegen Durchprobieren
    $_SESSION['login_tries'] = ($_SESSION['login_tries'] ?? 0) + 1;
    if ($_SESSION['login_tries'] > 20) { sleep(2); }
    if (wb_admin_login((string)($_POST['password'] ?? ''))) {
        $next = (string)($_POST['next'] ?? wb_admin_url());
        if (!str_starts_with($next, wb_admin_url())) $next = wb_admin_url();
        header('Location: ' . $next);
        exit;
    }
    $error = 'Das Passwort stimmt nicht.';
}
admin_header('Anmelden');
?>
<section class="card narrow">
  <h1>Anmelden</h1>
  <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
  <form method="post">
    <input type="hidden" name="next" value="<?= e((string)($_GET['next'] ?? wb_admin_url())) ?>">
    <label for="pw">Admin‑Passwort</label>
    <input id="pw" name="password" type="password" autocomplete="current-password" autofocus required>
    <div class="actions"><button class="btn" type="submit">Anmelden</button></div>
  </form>
</section>
<?php admin_footer();
