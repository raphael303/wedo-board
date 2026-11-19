<?php
declare(strict_types=1);
// /manage/system: Systemeinstellungen, Einladungen, Systemrollen, Kontosuche, Protokoll (Matrix, Tabelle «System»).
// Nur für Konten mit Systemrolle, nur mit App-Stufe und nach erneuter Passwortabfrage. Jede Handlung prüft wb_can().
if (!defined('WB_ROOT')) exit;
if ($sub === '') ui_redirect('/manage/system');
if ($sub !== 'system') page_not_found();
$u = ui_require_login();
$uid = (int)$u['id'];
$roles = user_system_roles($uid);
if (!$roles) page_not_found(); // Wer keine Systemrolle hat, erfährt nicht, dass es die Seite gibt.
$session = wb_session();
if ($session['mfa_level'] !== 'totp' && $session['kind'] !== 'test') {
    ui_redirect(user_has_totp($u) ? '/account' : '/account/totp', tx('Die Verwaltung verlangt die Anmeldung mit der Authenticator-App.'));
}
ui_require_reauth();
ui_check_csrf();
$error = null;
$found = null;
$roleLabels = ['superadmin' => 'Super-Admin', 'admin' => 'Admin', 'license_manager' => 'Lizenzverwaltung', 'presenter' => 'Vorführung'];
/** Rollen, die dieses Konto vergeben darf (Matrix: Admins ernennt nur der Super-Admin). */
$grantable = array_merge(wb_can('staff.appoint') ? ['superadmin', 'admin'] : [], wb_can('role.grant') ? ['license_manager', 'presenter'] : []);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        switch ($action) {
            case 'switches':
                if (!wb_can('system.settings')) throw new InvalidArgumentException('Nicht berechtigt.');
                $new = [
                    'registration_mode' => in_array($_POST['registration_mode'] ?? '', WB_REGISTRATION_MODES, true) ? (string)$_POST['registration_mode'] : 'off',
                    'teacher_approval' => ($_POST['teacher_approval'] ?? '') === 'auto' ? 'auto' : 'manual',
                    'orgs_enabled' => !empty($_POST['orgs_enabled']) ? '1' : '0',
                ];
                $changed = [];
                foreach ($new as $k => $v) {
                    $old = setting_get($k);
                    if ($old !== $v) { setting_set($k, $v); $changed[$k] = [$old, $v]; }
                }
                if ($changed) audit_log('system.settings', 'settings', null, $changed);
                // Der Pfad der Bibliothek hängt an registration_mode; das Menü zeigt ihn nach dem Neuladen richtig.
                ui_redirect('/manage/system', $changed ? tx('Einstellungen gespeichert.') : tx('Nichts geändert.'));
            case 'invite':
                if (!wb_can('invite.create')) throw new InvalidArgumentException('Nicht berechtigt.');
                if (wb_registration_mode() === 'off') throw new InvalidArgumentException(tx('Solange die Registrierung auf «aus» steht, gibt es keine Einladungen.'));
                invitation_create((string)($_POST['email'] ?? ''), !empty($_POST['teacher_grant']), (int)($_POST['days'] ?? 14), $uid);
                ui_redirect('/manage/system#einladungen', tx('Einladung verschickt.'));
            case 'invite_revoke':
                if (!wb_can('invite.create')) throw new InvalidArgumentException('Nicht berechtigt.');
                invitation_revoke((int)($_POST['id'] ?? 0));
                ui_redirect('/manage/system#einladungen', tx('Einladung zurückgezogen.'));
            case 'role_grant':
            case 'role_revoke':
                $role = (string)($_POST['role'] ?? '');
                if (!in_array($role, $grantable, true)) throw new InvalidArgumentException('Nicht berechtigt.');
                $target = $action === 'role_grant' ? user_by_email((string)($_POST['email'] ?? '')) : user_by_id((int)($_POST['user_id'] ?? 0));
                // Nur bestätigte Konten; die Antwort verrät nicht mehr als die Kontosuche.
                if (!$target || !user_is_active($target)) throw new InvalidArgumentException(tx('Zu dieser Adresse gibt es kein bestätigtes Konto.'));
                if ($action === 'role_grant') {
                    membership_add((int)$target['id'], 'system', 0, $role, $uid);
                    audit_log('role.grant', 'user', (int)$target['id'], ['role' => $role]);
                    account_notify($target, tx('Neue Rolle bei {app}', ['app' => wb_app_name()]), tx('Dein Konto hat die Rolle «{role}» erhalten. Für diese Rolle ist die Anmeldung mit einer Authenticator-App Pflicht.', ['role' => $roleLabels[$role]]));
                } else {
                    if ($role === 'superadmin' && (int)db_row("SELECT COUNT(*) AS n FROM memberships WHERE scope_type = 'system' AND role = 'superadmin'")['n'] <= 1) {
                        throw new InvalidArgumentException(tx('Der letzte Super-Admin kann nicht abgesetzt werden.'));
                    }
                    if (membership_remove((int)$target['id'], 'system', 0, $role)) {
                        audit_log('role.revoke', 'user', (int)$target['id'], ['role' => $role]);
                        wb_sessions_revoke_user((int)$target['id'], (int)$target['id'] === $uid ? $session['id'] : null);
                    }
                }
                ui_redirect('/manage/system#rollen', tx('Rollen gespeichert.'));
            case 'find':
                if (!wb_can('account.find')) throw new InvalidArgumentException('Nicht berechtigt.');
                if (brake_exceeded('find|' . $uid, 30, 60)) throw new InvalidArgumentException(tx('Zu viele Suchen. Bitte später wieder versuchen.'));
                $q = wb_email_normalize((string)($_POST['email'] ?? ''));
                $found = $q !== null ? user_by_email($q) : null;
                audit_log('account.find', 'user', $found ? (int)$found['id'] : null, ['query_hash' => substr(hash('sha256', (string)$q), 0, 16), 'hit' => $found !== null]);
                if (!$found) $error = tx('Zu dieser genauen Adresse gibt es kein Konto.');
                break;
            case 'totp_removal':
                if (!wb_can('account.totp_removal')) throw new InvalidArgumentException('Nicht berechtigt.');
                $target = user_by_id((int)($_POST['user_id'] ?? 0));
                if (!$target || !account_totp_removal_start($target)) throw new InvalidArgumentException(tx('Für dieses Konto läuft schon ein Antrag, oder es hat keine App-Stufe.'));
                ui_redirect('/manage/system#konto', tx('Ausgelöst. Die Inhaberin hat eine Mail erhalten; die Entfernung wirkt in 72 Stunden, wenn sie nicht stoppt.'));
            case 'gate':
                if (!wb_can('test.helpers')) throw new InvalidArgumentException('Nicht berechtigt.');
                $level = (int)($_POST['level'] ?? 1) === 2 ? 2 : 1;
                if ($level === 2 && !wb_can('system.settings')) throw new InvalidArgumentException('Nicht berechtigt.');
                $code = trim((string)($_POST['code'] ?? ''));
                if (mb_strlen($code) < 8) throw new InvalidArgumentException(tx('Der Zugangscode braucht mindestens acht Zeichen.'));
                gate_set_code($level, $code);
                audit_log('test.gate', 'settings', null, ['level' => $level]);
                ui_redirect('/manage/system#test', tx('Zugangscode gesetzt. Alle gemerkten Zugänge dieser Stufe sind ungültig.'));
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    }
}

$staff = wb_can('role.grant') || wb_can('staff.appoint')
    ? db_all("SELECT m.role, m.created_at, u.id, u.email, u.first_name, u.last_name, u.totp_secret_enc IS NOT NULL AS has_totp FROM memberships m JOIN users u ON u.id = m.user_id WHERE m.scope_type = 'system' ORDER BY FIELD(m.role, 'superadmin', 'admin', 'license_manager', 'presenter'), u.email")
    : [];
$invites = wb_can('invite.create') ? invitations_list(50) : [];
$log = wb_can('audit.read') ? audit_recent(60, (string)($_GET['log'] ?? '')) : [];
$bnow = wb_business_time();
ui_header(tx('Verwaltung'), 'manage');
?>
<div class="page-head"><div><h1><?= e(tx('Verwaltung')) ?></h1><p class="muted"><?= e(tx('System')) ?> · <?= e(implode(', ', array_map(fn($r) => $roleLabels[$r] ?? $r, $roles))) ?><?= $session['kind'] === 'test' ? ' · Testrolle' : '' ?></p></div></div>
<?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>

<?php if (wb_can('system.settings')): ?>
<section class="card" id="schalter"><h2><?= e(tx('Schalter')) ?></h2>
  <form method="post" class="form"><?= ui_csrf_field() ?><input type="hidden" name="action" value="switches">
    <div class="row"><label for="rm"><?= e(tx('Registrierung')) ?></label>
      <select id="rm" name="registration_mode"><?php foreach (['off' => tx('aus (nur bestehende Konten)'), 'invite' => tx('mit Einladung'), 'open' => tx('offen')] as $k => $l): ?><option value="<?= $k ?>" <?= wb_registration_mode() === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
      <p class="hint"><?= e(tx('Bei «aus» liegt die Bibliothek unter dem geheimen Pfad, sonst unter /library/.')) ?></p></div>
    <div class="row"><label for="ta"><?= e(tx('Anträge auf die Lehrperson-Freigabe')) ?></label>
      <select id="ta" name="teacher_approval"><option value="manual" <?= setting_get('teacher_approval', 'manual') !== 'auto' ? 'selected' : '' ?>><?= e(tx('von Hand freigeben')) ?></option><option value="auto" <?= setting_get('teacher_approval') === 'auto' ? 'selected' : '' ?>><?= e(tx('automatisch freigeben')) ?></option></select>
      <p class="hint"><?= e(tx('Antrag und Entscheid kommen mit der Etappe U2; der Schalter steht schon hier.')) ?></p></div>
    <div class="row checks"><label><input type="checkbox" name="orgs_enabled" value="1" <?= wb_orgs_enabled() ? 'checked' : '' ?>> <?= e(tx('Schulen, Bezirke und Lizenzen geöffnet (wirkt ab U4)')) ?></label></div>
    <div class="actions"><button class="btn" type="submit"><?= e(tx('Speichern')) ?></button></div>
  </form>
</section>
<?php endif; ?>

<?php if (wb_can('invite.create')): ?>
<section class="card" id="einladungen"><h2><?= e(tx('Einladungen')) ?></h2>
  <?php if (wb_registration_mode() === 'off'): ?><p class="muted"><?= e(tx('Solange die Registrierung auf «aus» steht, gibt es keine Einladungen.')) ?></p><?php else: ?>
  <form method="post" class="form"><?= ui_csrf_field() ?><input type="hidden" name="action" value="invite">
    <div class="row two"><div><label for="ie"><?= e(tx('E-Mail-Adresse')) ?></label><input id="ie" name="email" type="email" autocomplete="off" required></div>
      <div><label for="id"><?= e(tx('Gültig (Tage)')) ?></label><input id="id" name="days" type="number" min="1" max="90" value="14" required></div></div>
    <div class="row checks"><label><input type="checkbox" name="teacher_grant" value="1"> <?= e(tx('mit Lehrperson-Freigabe')) ?></label></div>
    <p class="hint"><?= e(tx('Den Plan trägt eine Einladung ab der Etappe U3.')) ?></p>
    <div class="actions"><button class="btn" type="submit"><?= e(tx('Einladen')) ?></button></div>
  </form>
  <?php endif; ?>
  <?php if ($invites): ?>
  <table class="results plain"><tr><th><?= e(tx('Adresse')) ?></th><th><?= e(tx('Freigabe')) ?></th><th><?= e(tx('Erstellt')) ?></th><th><?= e(tx('Stand')) ?></th><th></th></tr>
  <?php foreach ($invites as $i): $open = $i['accepted_at'] === null && $i['revoked_at'] === null && wb_ts((string)$i['expires_at']) > $bnow; ?>
    <tr><td><?= e((string)$i['email']) ?></td><td><?= (int)$i['teacher_grant'] ? e(tx('Lehrperson')) : '–' ?></td><td><?= e((string)wb_local_time((string)$i['created_at'])) ?></td>
      <td><?= e($i['accepted_at'] !== null ? tx('angenommen') : ($i['revoked_at'] !== null ? tx('zurückgezogen') : ($open ? tx('offen bis {t}', ['t' => (string)wb_local_time((string)$i['expires_at'])]) : tx('verfallen')))) ?></td>
      <td><?php if ($open): ?><form method="post" class="inline"><?= ui_csrf_field() ?><input type="hidden" name="action" value="invite_revoke"><input type="hidden" name="id" value="<?= (int)$i['id'] ?>"><button class="link-danger" type="submit"><?= e(tx('Zurückziehen')) ?></button></form><?php endif; ?></td></tr>
  <?php endforeach; ?></table>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($grantable): ?>
<section class="card" id="rollen"><h2><?= e(tx('Systemrollen')) ?></h2>
  <table class="results plain"><tr><th><?= e(tx('Rolle')) ?></th><th><?= e(tx('Person')) ?></th><th><?= e(tx('App-Stufe')) ?></th><th></th></tr>
  <?php foreach ($staff as $s): ?>
    <tr><td><?= e($roleLabels[$s['role']] ?? $s['role']) ?></td><td><?= e(trim($s['first_name'] . ' ' . $s['last_name'])) ?> <span class="muted"><?= e((string)$s['email']) ?></span></td><td><?= (int)$s['has_totp'] ? e(tx('ja')) : '<span class="bad">' . e(tx('fehlt')) . '</span>' ?></td>
      <td><?php if (in_array($s['role'], $grantable, true)): ?><form method="post" class="inline" data-confirm="<?= e(tx('Rolle entziehen?')) ?>"><?= ui_csrf_field() ?><input type="hidden" name="action" value="role_revoke"><input type="hidden" name="role" value="<?= e($s['role']) ?>"><input type="hidden" name="user_id" value="<?= (int)$s['id'] ?>"><button class="link-danger" type="submit"><?= e(tx('Entziehen')) ?></button></form><?php endif; ?></td></tr>
  <?php endforeach; ?></table>
  <form method="post" class="form"><?= ui_csrf_field() ?><input type="hidden" name="action" value="role_grant">
    <div class="row two"><div><label for="re"><?= e(tx('Genaue Adresse des Kontos')) ?></label><input id="re" name="email" type="email" autocomplete="off" required></div>
      <div><label for="rr"><?= e(tx('Rolle')) ?></label><select id="rr" name="role"><?php foreach ($grantable as $r): ?><option value="<?= e($r) ?>"><?= e($roleLabels[$r]) ?></option><?php endforeach; ?></select></div></div>
    <div class="actions"><button class="btn-ghost" type="submit"><?= e(tx('Rolle vergeben')) ?></button></div>
  </form>
</section>
<?php endif; ?>

<?php if (wb_can('account.find')): ?>
<section class="card" id="konto"><h2><?= e(tx('Einzelnes Konto suchen')) ?></h2>
  <p class="muted"><?= e(tx('Nur über die genaue Adresse. Listen von Personen gibt es nicht, und Inhalte von Boards sind hier nie sichtbar. Jede Suche steht im Protokoll.')) ?></p>
  <form method="post" class="form"><?= ui_csrf_field() ?><input type="hidden" name="action" value="find">
    <div class="row"><label for="fe"><?= e(tx('E-Mail-Adresse')) ?></label><input id="fe" name="email" type="email" autocomplete="off" required></div>
    <div class="actions"><button class="btn-ghost" type="submit"><?= e(tx('Suchen')) ?></button></div>
  </form>
  <?php if ($found): ?>
  <dl class="fb-ctx">
    <dt><?= e(tx('Name')) ?></dt><dd><?= e(user_name($found)) ?></dd>
    <dt><?= e(tx('Adresse')) ?></dt><dd><?= e((string)$found['email']) ?> (<?= e($found['email_verified_at'] !== null ? tx('bestätigt') : tx('unbestätigt')) ?>)</dd>
    <dt><?= e(tx('Angelegt')) ?></dt><dd><?= e((string)wb_local_time((string)$found['created_at'])) ?></dd>
    <dt><?= e(tx('Letzte Anmeldung')) ?></dt><dd><?= e($found['last_login_at'] ? (string)wb_local_time((string)$found['last_login_at']) : '–') ?></dd>
    <dt><?= e(tx('App-Stufe')) ?></dt><dd><?= e(user_has_totp($found) ? tx('eingerichtet') : tx('nicht eingerichtet')) ?><?= !empty($found['totp_removal_at']) ? ' · ' . e(tx('Entfernen läuft bis {t}', ['t' => (string)wb_local_time((string)$found['totp_removal_at'])])) : '' ?></dd>
    <dt><?= e(tx('Systemrollen')) ?></dt><dd><?= e(implode(', ', array_map(fn($r) => $roleLabels[$r] ?? $r, user_system_roles((int)$found['id']))) ?: '–') ?></dd>
    <dt><?= e(tx('Lehrperson-Freigabe')) ?></dt><dd><?= e(['no' => tx('nein'), 'requested' => tx('beantragt'), 'approved' => tx('freigegeben')][user_teacher_state($found)]) ?></dd>
  </dl>
  <?php if (wb_can('account.totp_removal') && user_has_totp($found) && empty($found['totp_removal_at'])): ?>
  <form method="post" class="actions" data-confirm="<?= e(tx('Entfernen der zweiten Stufe auslösen? Die Inhaberin erhält eine Mail und kann es 72 Stunden lang stoppen.')) ?>"><?= ui_csrf_field() ?><input type="hidden" name="action" value="totp_removal"><input type="hidden" name="user_id" value="<?= (int)$found['id'] ?>"><button class="btn-ghost danger" type="submit"><?= e(tx('Zweite Stufe entfernen (wirkt nach 72 Stunden)')) ?></button></form>
  <?php endif; ?>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if (wb_can('test.helpers')): ?>
<section class="card" id="test"><h2><?= e(tx('Testumgebung')) ?></h2>
  <p class="muted"><?= e(tx('Der Zugangscode schützt Anmeldung, Registrierung, Testrollen und Sammelpostfach. Der zweite Code schützt die Testrollen mit Systemrolle.')) ?> <a href="/test"><?= e(tx('Zu den Testrollen')) ?></a> · <a href="/test/mail"><?= e(tx('Zum Sammelpostfach')) ?></a></p>
  <?php foreach ([1 => tx('Zugangscode'), 2 => tx('Zweiter Code (Testrollen des Personals)')] as $lvl => $label): if ($lvl === 2 && !wb_can('system.settings')) continue; ?>
  <form method="post" class="form"><?= ui_csrf_field() ?><input type="hidden" name="action" value="gate"><input type="hidden" name="level" value="<?= $lvl ?>">
    <div class="row"><label for="gc<?= $lvl ?>"><?= e($label) ?> <span class="muted">(<?= e(setting_get('test_gate' . $lvl . '_hash') ? tx('gesetzt') : tx('noch nicht gesetzt')) ?>)</span></label><input id="gc<?= $lvl ?>" name="code" type="text" autocomplete="off" minlength="8" required></div>
    <div class="actions"><button class="btn-ghost" type="submit"><?= e(tx('Neu setzen')) ?></button></div>
  </form>
  <?php endforeach; ?>
  <p class="hint"><?= e(tx('Zeitverschiebung für fachliche Fristen: {n} Sekunden (clock_offset in config.php).', ['n' => (int)wb_config('clock_offset', 0)])) ?> <?= e(tx('Den Testbestand lädt tools/cyon-deploy.sh --seed test neu.')) ?></p>
</section>
<?php endif; ?>

<?php if (wb_can('audit.read')): ?>
<section class="card" id="protokoll"><h2><?= e(tx('Protokoll')) ?></h2>
  <form method="get" class="filters"><input type="search" name="log" value="<?= e((string)($_GET['log'] ?? '')) ?>" placeholder="<?= e(tx('Handlung beginnt mit …')) ?>"><button class="btn-ghost" type="submit"><?= e(tx('Filtern')) ?></button></form>
  <table class="results plain"><tr><th><?= e(tx('Zeit')) ?></th><th><?= e(tx('Wer')) ?></th><th><?= e(tx('Handlung')) ?></th><th><?= e(tx('Ziel')) ?></th><th><?= e(tx('Einzelheiten')) ?></th></tr>
  <?php foreach ($log as $l): ?>
    <tr><td><?= e((string)wb_local_time((string)$l['created_at'])) ?></td><td><?= e((string)($l['actor_email'] ?? '–')) ?> <span class="muted"><?= e((string)$l['actor_role']) ?></span></td><td><code><?= e((string)$l['action']) ?></code></td>
      <td><?= e(trim($l['target_type'] . ' ' . ($l['target_id'] ?? ''))) ?></td><td class="muted"><?= e(mb_substr((string)($l['details_json'] ?? ''), 0, 160)) ?></td></tr>
  <?php endforeach; ?></table>
</section>
<?php endif; ?>
<?php
ui_footer();
