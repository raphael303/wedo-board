<?php
declare(strict_types=1);
require __DIR__ . '/_layout.php';

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Bremse gegen Durchprobieren: Fehlversuche pro IP-Adresse und global (lib/auth.php, rate_limits)
    $ip = wb_client_ip();
    $blocked = wb_login_blocked($ip);
    if ($blocked !== null) {
        http_response_code(429);
        header('Retry-After: ' . ($blocked === 'ip' ? WB_LOGIN_IP_MINUTES * 60 : 3600));
        $error = $blocked === 'ip'
            ? 'Zu viele Fehlversuche von diesem Anschluss. Bitte in ' . WB_LOGIN_IP_MINUTES . ' Minuten wieder versuchen.'
            : 'Zu viele Fehlversuche insgesamt. Die Anmeldung ist vorübergehend gesperrt; bitte später wieder versuchen.';
    } elseif (wb_admin_login((string)($_POST['password'] ?? ''))) {
        wb_login_mark_known_device();
        $next = (string)($_POST['next'] ?? wb_admin_url());
        if (!str_starts_with($next, wb_admin_url())) $next = wb_admin_url();
        header('Location: ' . $next);
        exit;
    } else {
        wb_login_record_failure($ip);
        $error = 'Das Passwort stimmt nicht.';
    }
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
