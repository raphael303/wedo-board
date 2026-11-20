<?php
declare(strict_types=1);
// /test: Testhilfen von Sandbox und Testinstanz (Abschnitte 16 und 17): Zugangscode, Testrollen mit Anmeldung auf
// einen Klick, Anzeige des angemeldeten Kontos. /test/mail: Sammelpostfach. page.php lässt die Seite nur zu, wenn
// config.php die Instanz ausdrücklich ausweist und die Datenbank den Marker des Testbestands trägt.
if (!defined('WB_ROOT')) exit;
ui_check_csrf();
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$next = ui_safe_next($_POST['next'] ?? $_GET['next'] ?? null, '/test');
$error = null;

if (!gate_ok(1)) {
    if ($post && ($_POST['action'] ?? '') === 'gate') {
        if (gate_enter(1, (string)($_POST['code'] ?? ''))) ui_redirect($next);
        $error = tx('Dieser Zugangscode stimmt nicht.');
    }
    ui_header(tx('Testumgebung'));
    ?>
<section class="card narrow">
  <h1><?= e(tx('Testumgebung')) ?></h1>
  <p class="muted"><?= e(tx('Diese Installation ist zum Ausprobieren da und nicht für den Unterricht. Anmeldung und Registrierung verlangen einen Zugangscode.')) ?></p>
  <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <form method="post" class="form"><?= ui_csrf_field() ?><input type="hidden" name="action" value="gate"><input type="hidden" name="next" value="<?= e($next) ?>">
    <div class="row"><label for="code"><?= e(tx('Zugangscode')) ?></label><input id="code" name="code" type="password" autocomplete="off" autofocus required></div>
    <div class="actions"><button class="btn" type="submit"><?= e(tx('Weiter')) ?></button></div>
  </form>
</section>
<?php
    ui_footer();
    exit;
}

if ($sub === 'mail') {
    $id = (int)($_GET['id'] ?? 0);
    // Nur Mails, die nie hinausgingen (S1, M4): echte Mails an Adressen der mail_allowlist bleiben unsichtbar.
    $one = $id > 0 ? db_row("SELECT * FROM mail_queue WHERE id = ? AND status = 'sink'", [$id]) : null;
    ui_header(tx('Postfach der Testumgebung'));
    ?>
<div class="page-head"><div><h1><?= e(tx('Postfach der Testumgebung')) ?></h1><p class="muted"><?= e(tx('Hier landet jede Mail, die diese Installation verschicken würde. Echte Mails gehen nur an Adressen der mail_allowlist und erscheinen hier nicht.')) ?> <a href="/test"><?= e(tx('Zu den Testrollen')) ?></a></p></div></div>
<?php if ($one): ?>
<section class="card"><p><a href="/test/mail">← <?= e(tx('Alle Mails')) ?></a></p>
  <dl class="fb-ctx"><dt><?= e(tx('An')) ?></dt><dd><?= e((string)$one['to_addr']) ?></dd><dt><?= e(tx('Betreff')) ?></dt><dd><?= e((string)$one['subject']) ?></dd>
    <dt><?= e(tx('Art')) ?></dt><dd><?= e((string)$one['kind']) ?> · <?= e((string)$one['status']) ?> · <?= e((string)wb_local_time((string)$one['created_at'])) ?></dd></dl>
  <pre class="mailbody"><?= preg_replace('~(https?://[^\s<]+)~', '<a href="$1">$1</a>', e((string)$one['body'])) ?></pre>
</section>
<?php else: ?>
<section class="card">
  <table class="results plain"><tr><th><?= e(tx('Zeit')) ?></th><th><?= e(tx('An')) ?></th><th><?= e(tx('Betreff')) ?></th><th><?= e(tx('Stand')) ?></th></tr>
  <?php foreach (mail_sink_list(100) as $m): ?>
    <tr><td><?= e((string)wb_local_time((string)$m['created_at'])) ?></td><td><?= e((string)$m['to_addr']) ?></td><td><a href="/test/mail?id=<?= (int)$m['id'] ?>"><?= e((string)$m['subject']) ?></a></td><td><?= e((string)$m['status']) ?></td></tr>
  <?php endforeach; ?></table>
</section>
<?php endif;
    ui_footer();
    exit;
}
if ($sub !== '') page_not_found();

$roleLabels = ['superadmin' => 'Super-Admin', 'admin' => 'Admin', 'license_manager' => 'Lizenzverwaltung', 'presenter' => 'Vorführung'];
if ($post) {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'gate2') {
        if (!gate_enter(2, (string)($_POST['code'] ?? ''))) $error = tx('Dieser Code stimmt nicht.');
        else ui_redirect('/test');
    } elseif ($action === 'login') {
        $target = user_by_id((int)($_POST['user_id'] ?? 0));
        // Nur Konten des Testbestands (reservierte Domain), nie ein echtes Konto und nie der Benutzer 1 (Besitzerin der Instanz).
        if (!$target || (int)$target['id'] === 1 || !user_is_active($target) || wb_email_domain((string)$target['email']) !== WB_TEST_DOMAIN) $error = tx('Dieses Konto ist keine Testrolle.');
        elseif (user_system_roles((int)$target['id']) !== [] && !gate_ok(2)) $error = tx('Testrollen des Personals verlangen den zweiten Code.');
        else {
            wb_logout();
            wb_session_create((int)$target['id'], 'none', 'test');
            audit_log('test.login', 'user', (int)$target['id'], ['roles' => user_system_roles((int)$target['id'])], null, (int)$target['id'], 'test');
            ui_redirect(user_system_roles((int)$target['id']) ? '/manage/system' : wb_admin_url());
        }
    }
}
$accounts = db_all('SELECT id, email, first_name, last_name, email_verified_at, teacher_approved_at, totp_secret_enc IS NOT NULL AS has_totp FROM users WHERE email LIKE ? AND id <> 1 AND deleted_at IS NULL ORDER BY id', ['%@' . WB_TEST_DOMAIN]);
ui_header(tx('Testrollen'));
?>
<div class="page-head"><div><h1><?= e(tx('Testrollen')) ?></h1>
  <p class="muted"><?= e($u ? tx('Du bist angemeldet als {name} ({email}).', ['name' => user_name($u), 'email' => (string)$u['email']]) : tx('Du bist nicht angemeldet.')) ?> <a href="/test/mail"><?= e(tx('Postfach der Testumgebung')) ?></a> · <a href="/login"><?= e(tx('Gewöhnliche Anmeldung')) ?></a></p></div></div>
<?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
<section class="card">
  <p class="muted"><?= e(tx('Ein Klick meldet dich als diese Rolle an (ohne Passwort und zweite Stufe, nur hier). Die Passwörter der Testkonten stehen im öffentlichen Repository und gelten nur in Sandbox und Testinstanz.')) ?></p>
  <table class="results plain"><tr><th><?= e(tx('Konto')) ?></th><th><?= e(tx('Stellung')) ?></th><th></th></tr>
  <?php foreach ($accounts as $a): $sr = user_system_roles((int)$a['id']); $ws = array_slice(wb_workspaces($a), 1); ?>
    <tr><td><?= e(trim($a['first_name'] . ' ' . $a['last_name'])) ?><br><span class="muted"><?= e((string)$a['email']) ?></span></td>
      <td><?= e(implode(', ', array_merge(array_map(fn($r) => $roleLabels[$r] ?? $r, $sr), array_map(fn($w) => tx('Lehrperson in {org}', ['org' => $w['name']]), $ws),
          $a['teacher_approved_at'] ? [tx('Lehrperson-Freigabe')] : [], $a['email_verified_at'] === null ? [tx('unbestätigt')] : [])) ?: tx('Person ohne Freigabe')) ?></td>
      <td><?php if ($a['email_verified_at'] !== null): ?><form method="post" class="inline"><?= ui_csrf_field() ?><input type="hidden" name="action" value="login"><input type="hidden" name="user_id" value="<?= (int)$a['id'] ?>"><button class="btn-ghost small" type="submit"><?= e(tx('Anmelden')) ?></button></form><?php endif; ?></td></tr>
  <?php endforeach; ?></table>
</section>
<?php if (gate_required() && !gate_ok(2)): ?>
<section class="card narrow"><h2><?= e(tx('Zweiter Code')) ?></h2>
  <p class="muted"><?= e(tx('Testrollen mit Systemrolle verlangen einen zweiten Code.')) ?></p>
  <form method="post" class="form"><?= ui_csrf_field() ?><input type="hidden" name="action" value="gate2">
    <div class="row"><label for="c2"><?= e(tx('Zweiter Code')) ?></label><input id="c2" name="code" type="password" autocomplete="off" required></div>
    <div class="actions"><button class="btn-ghost" type="submit"><?= e(tx('Freischalten')) ?></button></div></form>
</section>
<?php endif;
ui_footer();
