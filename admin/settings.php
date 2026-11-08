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
    } elseif (str_starts_with($action, 'ai_')) {
        try {
            switch ($action) {
                case 'ai_save':
                    $provider = (string)($_POST['provider'] ?? 'anthropic');
                    if ($provider === 'fake' && !wb_config('ai_allow_fake', false)) throw new InvalidArgumentException('Der Fake-Anbieter ist nur für lokale Tests freigeschaltet.');
                    $model = trim((string)($_POST['model'] ?? ''));
                    if ($model === '__other' || $model === '') $model = trim((string)($_POST['model_other'] ?? ''));
                    $hadCredential = ai_credential_default() !== null;
                    $c = ai_credential_save($provider, (string)($_POST['api_key'] ?? ''), $model);
                    if (!$hadCredential && setting_get('ai_enabled') === null) setting_set('ai_enabled', '1');
                    $test = ai_credential_test($c + ['api_key' => ai_decrypt((string)$c['api_key_enc'])]);
                    admin_flash('KI-Zugang gespeichert. Verbindungstest: ' . $test['message']);
                    break;
                case 'ai_test':
                    $c = ai_credential_default();
                    if (!$c) throw new InvalidArgumentException('Kein KI-Zugang gespeichert.');
                    $test = ai_credential_test($c);
                    admin_flash(($test['ok'] ? 'Verbindung in Ordnung. ' : 'Verbindung fehlgeschlagen: ') . $test['message']);
                    break;
                case 'ai_delete':
                    $c = ai_credential_default();
                    if ($c) ai_credential_delete((int)$c['id']);
                    admin_flash('KI-Zugang entfernt.');
                    break;
                case 'ai_toggle':
                    setting_set('ai_enabled', !empty($_POST['ai_enabled']) ? '1' : '0');
                    admin_flash(!empty($_POST['ai_enabled']) ? 'KI-Funktionen eingeschaltet.' : 'KI-Funktionen ausgeschaltet.');
                    break;
                case 'ai_prompt':
                    $pr = wb_clean_text($_POST['ai_auto_prompt'] ?? '', 4000);
                    setting_set('ai_auto_prompt', $pr === '' ? null : $pr);
                    admin_flash('Standard-Anweisung gespeichert.');
                    break;
                case 'ai_prompt_reset':
                    setting_set('ai_auto_prompt', null);
                    admin_flash('Standard-Anweisung zurückgesetzt.');
                    break;
            }
        } catch (InvalidArgumentException | RuntimeException $ex) {
            admin_flash('Fehler: ' . $ex->getMessage());
        }
        header('Location: /admin/settings.php#ki'); exit;
    } elseif ($action === 'backup') {
        $all = [];
        foreach (db_all('SELECT * FROM boards WHERE deleted_at IS NULL ORDER BY id') as $b) $all[] = board_export_data(board_normalize($b));
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . wb_app_slug() . '-backup-' . date('Y-m-d') . '.json"');
        echo json_encode(['exported_at' => date('c'), 'boards' => $all], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}
$csrf = wb_csrf_token();
$token = (string)setting_get('api_token');
$aiSecretLine = ai_secret_missing_line();
$aiCred = ai_credential_default();
$aiCredPublic = $aiCred ? ai_credential_row_public($aiCred) : null;
$aiProviders = AI_PROVIDERS;
if (!wb_config('ai_allow_fake', false) && (!$aiCred || $aiCred['provider'] !== 'fake')) unset($aiProviders['fake']);
$aiModels = $aiCred ? ai_models_cached($aiCred['provider']) : [];
$aiModelKnown = $aiCred && in_array($aiCred['model'], array_column($aiModels, 'id'), true);
$aiUsage = ai_usage_summary();
$aiRecent = ai_usage_recent(8);
$counts = db_row("SELECT (SELECT COUNT(*) FROM boards WHERE deleted_at IS NULL) AS boards, (SELECT COUNT(*) FROM boards WHERE deleted_at IS NOT NULL) AS trashed_boards, (SELECT COUNT(*) FROM posts WHERE deleted_at IS NULL) AS posts, (SELECT COUNT(*) FROM posts WHERE deleted_at IS NOT NULL) AS trashed");
admin_header('Einstellungen', 'settings');
?>
<div class="page-head"><h1>Einstellungen</h1></div>

<section class="card">
  <h2>Schnittstelle für Claude</h2>
  <p class="muted">Mit diesem Token darf Claude (oder jedes andere Programm) Boards anlegen, lesen, leeren und löschen. Behandle ihn wie ein Passwort.</p>
  <p><code class="token" id="token"><?= e($token) ?></code> <button class="btn-ghost" type="button" data-copy="<?= e($token) ?>">Kopieren</button></p>
  <p class="muted">Basis‑Adresse der Schnittstelle: <code><?= e(wb_base_url()) ?>/api/</code>. Beschreibung in <code>API.md</code> im Repository.</p>
  <h3>Connector für die Claude‑App</h3>
  <p class="muted">Diese Adresse in den Claude‑Einstellungen unter «Connectoren» als eigenen Connector eintragen (ohne Anmeldung). Wer die Adresse kennt, kann Boards verwalten; bei einem neuen Token ändert sie sich.</p>
  <p><code class="token"><?= e(wb_base_url()) ?>/mcp/<?= e($token) ?></code> <button class="btn-ghost" type="button" data-copy="<?= e(wb_base_url()) ?>/mcp/<?= e($token) ?>">Kopieren</button></p>
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

<section class="card" id="ki">
  <h2>KI</h2>
  <?php if ($aiSecretLine): ?>
    <p class="error"><code>config.php</code> ist nicht beschreibbar, und es fehlt der Geheimwert für die verschlüsselte Ablage des Schlüssels. Füge diese Zeile in <code>config.php</code> ein (innerhalb des Arrays) und lade die Seite neu:</p>
    <p><code class="token"><?= e(trim($aiSecretLine)) ?></code> <button class="btn-ghost" type="button" data-copy="<?= e(trim($aiSecretLine)) ?>">Kopieren</button></p>
  <?php else: ?>
  <form method="post" class="form">
    <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="ai_toggle">
    <div class="checks"><label><input type="checkbox" name="ai_enabled" <?= ai_enabled() ? 'checked' : '' ?>> <strong>KI-Funktionen aktiv</strong> (Hauptschalter für alle Boards)</label> <button class="btn-ghost small" type="submit">Übernehmen</button></div>
  </form>

  <h3>Zugang</h3>
  <?php if ($aiCredPublic): ?>
    <p class="muted">Gespeichert: <?= e($aiCredPublic['provider_label']) ?>, Schlüssel <code><?= e($aiCredPublic['masked_key']) ?></code>, Modell <code><?= e($aiCredPublic['model']) ?></code><?= $aiCredPublic['last_test_at'] ? ', zuletzt getestet ' . e(admin_time($aiCredPublic['last_test_at'])) . ($aiCredPublic['last_test_ok'] ? ' (in Ordnung)' : ' (fehlgeschlagen)') : ', noch nicht getestet' ?>.</p>
    <div class="action-grid">
      <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="ai_test"><button class="btn-ghost" type="submit">Verbindung testen</button></form>
      <form method="post" data-confirm="KI-Zugang entfernen? Die KI-Funktionen sind danach nicht mehr nutzbar."><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="ai_delete"><button class="btn-ghost danger" type="submit">Zugang entfernen</button></form>
    </div>
  <?php else: ?>
    <p class="muted">Noch kein KI-Zugang gespeichert. Trage einen API-Schlüssel ein; er wird verschlüsselt abgelegt und nie wieder im Klartext angezeigt.</p>
  <?php endif; ?>
  <form method="post" class="form">
    <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="ai_save">
    <div class="row two">
      <div>
        <label for="ai_provider">Anbieter</label>
        <select id="ai_provider" name="provider">
          <?php foreach ($aiProviders as $k => $lbl): ?><option value="<?= e($k) ?>" <?= ($aiCred['provider'] ?? 'anthropic') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="ai_key"><?= $aiCredPublic ? 'Neuer API-Schlüssel (leer lassen, um den gespeicherten zu behalten)' : 'API-Schlüssel' ?></label>
        <input id="ai_key" name="api_key" type="password" autocomplete="off" placeholder="sk-ant-…" <?= $aiCredPublic ? '' : 'required' ?>>
      </div>
    </div>
    <div class="row two">
      <div>
        <label for="ai_model">Modell</label>
        <select id="ai_model" name="model" data-other="#ai_model_other">
          <?php foreach ($aiModels as $m): ?><option value="<?= e($m['id']) ?>" <?= ($aiCred['model'] ?? '') === $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?> (<?= e($m['id']) ?>)</option><?php endforeach; ?>
          <option value="__other" <?= !$aiModelKnown ? 'selected' : '' ?>>Anderes Modell (unten eintragen)</option>
        </select>
        <p class="hint"><?= $aiModels ? 'Liste vom Anbieter, geladen beim letzten Verbindungstest.' : 'Die Liste der Modelle wird beim Speichern oder Testen vom Anbieter geladen.' ?></p>
      </div>
      <div>
        <label for="ai_model_other">Modellname (frei)</label>
        <input id="ai_model_other" name="model_other" value="<?= e(!$aiModelKnown ? ($aiCred['model'] ?? AI_DEFAULT_MODEL['anthropic']) : '') ?>" placeholder="<?= e(AI_DEFAULT_MODEL['anthropic']) ?>">
      </div>
    </div>
    <div class="actions"><button class="btn" type="submit">Zugang speichern und testen</button></div>
  </form>

  <h3>Standard-Anweisung für «Automatisch nach Themen bündeln»</h3>
  <form method="post" class="form">
    <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="ai_prompt">
    <div class="row"><textarea name="ai_auto_prompt" rows="5"><?= e(ai_auto_prompt()) ?></textarea>
    <p class="hint">Dieser Text ist der Auftrag im Auto-Modus. Die technischen Regeln (nur Beitragsnummern aus der Liste, keine erzwungene Zuordnung, Bündel bleiben in ihrer Spalte) gelten immer und stehen fest im Systemprompt.</p></div>
    <div class="actions"><button class="btn" type="submit">Anweisung speichern</button> <button class="btn-ghost" type="submit" name="action" value="ai_prompt_reset">Zurücksetzen</button></div>
  </form>

  <h3>Verbrauch</h3>
  <p class="muted">Laufender Monat (<?= e($aiUsage['this_month']['label']) ?>): <?= $aiUsage['this_month']['calls'] ?> Aufrufe, <?= number_format($aiUsage['this_month']['input_tokens'], 0, '.', '’') ?> Eingabe- und <?= number_format($aiUsage['this_month']['output_tokens'], 0, '.', '’') ?> Ausgabe-Token<?= $aiUsage['this_month']['errors'] ? ', ' . $aiUsage['this_month']['errors'] . ' Fehler' : '' ?>. Letzter Monat (<?= e($aiUsage['last_month']['label']) ?>): <?= $aiUsage['last_month']['calls'] ?> Aufrufe, <?= number_format($aiUsage['last_month']['input_tokens'], 0, '.', '’') ?> Eingabe- und <?= number_format($aiUsage['last_month']['output_tokens'], 0, '.', '’') ?> Ausgabe-Token.</p>
  <?php if ($aiRecent): ?>
  <ul class="usage">
    <?php foreach ($aiRecent as $u): ?><li><span class="muted"><?= e(admin_time($u['created_at'])) ?></span> · <?= e($u['feature']) ?><?= $u['board_name'] ? ' · ' . e($u['board_name']) : '' ?> · <?= e($u['model']) ?> · <?= (int)$u['input_tokens'] ?>/<?= (int)$u['output_tokens'] ?> Token · <?= (int)$u['duration_ms'] ?> ms<?= $u['ok'] ? '' : ' · <span class="bad">Fehler: ' . e((string)$u['error']) . '</span>' ?></li><?php endforeach; ?>
  </ul>
  <?php endif; ?>

  <h3>Datenschutz</h3>
  <p class="muted">Bei einem KI-Lauf gehen nur die Texte der Beiträge (Titel, Text, bei Links die Domain, bei Bildern der Vermerk «[Bild]») und die Spaltennamen an den gewählten Anbieter (zurzeit Anthropic, Server in den USA). Fantasienamen, Kommentare, Reaktionen und Bilder werden nicht übertragen. Boards, bei denen «KI-Funktionen erlaubt» ausgeschaltet ist, werden nie übertragen. Der API-Schlüssel liegt verschlüsselt in der Datenbank; der Geheimwert dazu steht in <code>config.php</code>.</p>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Daten</h2>
  <p class="muted"><?= (int)$counts['boards'] ?> Boards, <?= (int)$counts['posts'] ?> Beiträge; im Papierkorb <?= (int)$counts['trashed_boards'] ?> Boards (<a href="/admin/?status=trash">anzeigen</a>) und <?= (int)$counts['trashed'] ?> Beiträge.</p>
  <div class="action-grid">
    <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="backup"><button class="btn-ghost" type="submit">Alle Boards als JSON sichern</button></form>
    <form method="post" data-confirm="Gelöschte Boards, Beiträge und Kommentare, die älter als 30 Tage sind, endgültig entfernen?"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="purge"><button class="btn-ghost" type="submit">Papierkorb leeren (älter als 30 Tage)</button></form>
  </div>
  <p class="muted">Anzeigename «<?= e(wb_app_name()) ?>» und Adresse <code><?= e(wb_base_url()) ?></code> stammen aus <code>config.php</code><?php $lh = (array)wb_config('legacy_hosts', []); ?><?= $lh ? '; frühere Domains, die dorthin umleiten: ' . e(implode(', ', $lh)) : '' ?>. Die Checkliste für einen Domainwechsel steht in <code>DEVELOPMENT.md</code>.</p>
  <p class="muted">Version <?= WB_VERSION ?> · PHP <?= PHP_VERSION ?></p>
</section>
<?php admin_footer();
