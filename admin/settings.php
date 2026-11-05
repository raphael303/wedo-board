<?php
declare(strict_types=1);
require __DIR__ . '/_layout.php';
admin_require_login();
admin_check_csrf();

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'password') {
        $pw = (string)($_POST['password'] ?? '');
        if (strlen($pw) < 8) $error = 'Das Passwort braucht mindestens 8 Zeichen.';
        elseif ($pw !== (string)($_POST['password2'] ?? '')) $error = 'Die Passwörter stimmen nicht überein.';
        else {
            $hash = password_hash($pw, PASSWORD_DEFAULT);
            setting_set('admin_password_hash', $hash);
            $_SESSION['admin_hash'] = $hash;
            admin_flash('Passwort geändert.');
            header('Location: /admin/settings.php'); exit;
        }
    } elseif ($action === 'token') {
        setting_set('api_token', bin2hex(random_bytes(24)));
        admin_flash('Neuer API‑Token erzeugt. Der alte gilt nicht mehr; trage den neuen im Connector ein.');
        header('Location: /admin/settings.php'); exit;
    } elseif ($action === 'purge') {
        $n = board_purge_deleted(30);
        admin_flash("$n gelöschte Elemente endgültig entfernt.");
        header('Location: /admin/settings.php'); exit;
    } elseif ($action === 'backup') {
        $all = [];
        foreach (db_all('SELECT * FROM boards ORDER BY id') as $b) $all[] = board_export_data(board_normalize($b));
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="wedo-board-backup-' . date('Y-m-d') . '.json"');
        echo json_encode(['exported_at' => date('c'), 'boards' => $all], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}
$csrf = wb_csrf_token();
$token = (string)setting_get('api_token');
$counts = db_row("SELECT (SELECT COUNT(*) FROM boards) AS boards, (SELECT COUNT(*) FROM posts WHERE deleted_at IS NULL) AS posts, (SELECT COUNT(*) FROM posts WHERE deleted_at IS NOT NULL) AS trashed");
admin_header('Einstellungen', 'settings');
?>
<div class="page-head"><h1>Einstellungen</h1></div>

<section class="card">
  <h2>Schnittstelle für Claude</h2>
  <p class="muted">Mit diesem Token darf Claude (oder jedes andere Programm) Boards anlegen, lesen, leeren und löschen. Behandle ihn wie ein Passwort.</p>
  <p><code class="token" id="token"><?= e($token) ?></code> <button class="btn-ghost" type="button" data-copy="<?= e($token) ?>">Kopieren</button></p>
  <p class="muted">Basis‑Adresse der Schnittstelle: <code><?= e(wb_base_url()) ?>/api/</code>. Beschreibung in <code>API.md</code> im Repository.</p>
  <form method="post" data-confirm="Neuen Token erzeugen? Der bisherige funktioniert danach nicht mehr."><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="token"><button class="btn-ghost" type="submit">Neuen Token erzeugen</button></form>
</section>

<section class="card">
  <h2>Passwort ändern</h2>
  <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
  <form method="post" class="form">
    <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="password">
    <div class="row two">
      <div><label for="pw">Neues Passwort</label><input id="pw" name="password" type="password" minlength="8" autocomplete="new-password" required></div>
      <div><label for="pw2">Wiederholen</label><input id="pw2" name="password2" type="password" minlength="8" autocomplete="new-password" required></div>
    </div>
    <div class="actions"><button class="btn" type="submit">Passwort speichern</button></div>
  </form>
</section>

<section class="card">
  <h2>Daten</h2>
  <p class="muted"><?= (int)$counts['boards'] ?> Boards, <?= (int)$counts['posts'] ?> Beiträge, <?= (int)$counts['trashed'] ?> gelöschte Beiträge im Papierkorb.</p>
  <div class="action-grid">
    <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="backup"><button class="btn-ghost" type="submit">Alle Boards als JSON sichern</button></form>
    <form method="post" data-confirm="Gelöschte Beiträge und Kommentare, die älter als 30 Tage sind, endgültig entfernen?"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="purge"><button class="btn-ghost" type="submit">Papierkorb leeren (älter als 30 Tage)</button></form>
  </div>
  <p class="muted">Version <?= WB_VERSION ?> · PHP <?= PHP_VERSION ?></p>
</section>
<?php admin_footer();
