<?php
declare(strict_types=1);
// Eine Wortwolke anlegen, bearbeiten, steuern und auswerten.
require __DIR__ . '/_layout.php';
admin_require_login();
admin_check_csrf();

$isNew = isset($_GET['new']);
$act = $isNew ? null : activity_by_key((string)($_GET['key'] ?? ''), true);
if ($act && $act['type'] !== 'wordcloud') { header('Location: ' . wb_admin_url() . 'target.php?key=' . $act['akey']); exit; }
if (!$isNew && !$act) {
    http_response_code(404);
    admin_header('Nicht gefunden');
    echo '<section class="card"><h1>Wortwolke nicht gefunden</h1><p><a href="' . e(wb_admin_url()) . 'wordclouds.php">Zu den Wortwolken</a></p></section>';
    admin_footer();
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');
    try {
        if ($action === 'save') {
            $in = [
                'type' => 'wordcloud',
                'name' => $_POST['name'] ?? '',
                'slug' => $_POST['slug'] ?? '',
                'max_terms' => (int)($_POST['max_terms'] ?? 3),
                'live' => !empty($_POST['live']),
                'ai' => !empty($_POST['ai']),
                'background' => $_POST['background'] ?? 'meer',
                'lang' => $_POST['lang'] ?? 'de',
                'note' => $_POST['note'] ?? '',
                'tags' => $_POST['tags'] ?? '',
            ];
            if ($act) { unset($in['type']); $act = activity_update($act, $in); admin_flash('Gespeichert.'); }
            else { $act = activity_create($in); admin_flash('Wortwolke angelegt.'); }
            header('Location: ' . wb_admin_url() . 'wordcloud.php?key=' . $act['akey']);
            exit;
        }
        if ($act) {
            switch ($action) {
                case 'reveal': activity_set_revealed($act, true); admin_flash('Die Wolke wird angezeigt.'); break;
                case 'hide': activity_set_revealed($act, false); admin_flash('Die Wolke ist verborgen.'); break;
                case 'merge':
                    @set_time_limit(AI_WORDCLOUD_TIMEOUT + 30);
                    try {
                        $r = ai_wordcloud_merge($act);
                        admin_flash($r['label'] . ' (' . $r['model'] . '). Rückgängig hier oder auf der Wolke.');
                    } catch (AiException $ex) {
                        $error = ai_error_message($ex, $act['lang']);
                    }
                    break;
                case 'undo':
                    $label = wordcloud_undo($act);
                    admin_flash($label === null ? 'Es gibt nichts rückgängig zu machen.' : 'Rückgängig gemacht: ' . $label);
                    break;
                case 'show_term':
                    wordcloud_group_action($act, ['action' => 'show', 'term' => (string)($_POST['term'] ?? '')]);
                    admin_flash('Begriff wieder eingeblendet.');
                    break;
                case 'round': $a2 = activity_new_round($act); admin_flash('Runde ' . $a2['round'] . ' begonnen. Die bisherigen Begriffe bleiben unten lesbar.'); break;
                case 'close': activity_update($act, ['status' => 'closed']); admin_flash('Wortwolke geschlossen.'); break;
                case 'open':
                    $wasArchived = $act['status'] === 'archived';
                    $a2 = activity_update($act, ['status' => 'open']);
                    admin_flash($wasArchived ? 'Aus dem Archiv geholt. Link: ' . $a2['url'] : 'Wortwolke geöffnet.');
                    break;
                case 'archive': activity_update($act, ['status' => 'archived']); admin_flash('Wortwolke archiviert.'); break;
                case 'duplicate':
                    $copy = activity_duplicate($act);
                    admin_flash('Kopie angelegt.');
                    header('Location: ' . wb_admin_url() . 'wordcloud.php?key=' . $copy['akey']);
                    exit;
                case 'delete':
                    activity_trash($act);
                    admin_flash('Wortwolke «' . $act['name'] . '» in den Papierkorb gelegt (30 Tage wiederherstellbar).');
                    header('Location: ' . wb_admin_url() . 'wordclouds.php');
                    exit;
                case 'restore':
                    $a2 = activity_restore($act);
                    admin_flash('Wiederhergestellt. Link: ' . $a2['url']);
                    break;
                case 'delete_forever':
                    if ($act['deleted_at'] === null) { $error = 'Nur Wortwolken im Papierkorb lassen sich endgültig löschen.'; break; }
                    activity_delete_forever($act);
                    admin_flash('Wortwolke endgültig gelöscht.');
                    header('Location: ' . wb_admin_url() . 'wordclouds.php?status=trash');
                    exit;
            }
            if (!$error) { header('Location: ' . wb_admin_url() . 'wordcloud.php?key=' . $act['akey']); exit; }
        }
    } catch (InvalidArgumentException $ex) {
        $error = $ex->getMessage();
    }
}

$v = $act ? [
    'name' => $act['name'], 'slug' => $act['slug'], 'max_terms' => $act['config']['max_terms'], 'live' => $act['config']['live'],
    'ai' => $act['config']['ai'], 'background' => $act['background'], 'lang' => $act['lang'], 'note' => (string)$act['note'], 'tags' => $act['tags'],
] : [
    'name' => '', 'slug' => '', 'max_terms' => 3, 'live' => true, 'ai' => true, 'background' => 'meer',
    'lang' => (string)wb_config('default_lang', 'de'), 'note' => '', 'tags' => [],
];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error && ($_POST['action'] ?? '') === 'save') {
    foreach (['name', 'slug', 'note'] as $k) $v[$k] = (string)($_POST[$k] ?? '');
    $v['tags'] = wb_parse_tags($_POST['tags'] ?? '');
    $v['max_terms'] = (int)($_POST['max_terms'] ?? 3);
    $v['live'] = !empty($_POST['live']);
    $v['ai'] = !empty($_POST['ai']);
}
$csrf = wb_csrf_token();
$viewRound = $act && isset($_GET['round']) ? max(1, (int)$_GET['round']) : null;
$results = $act ? wordcloud_results($act, $viewRound) : null;
$aiStatus = $act ? ai_wordcloud_status($act) : null;
$history = $act ? wordcloud_history($act, 10) : [];
$canUndo = $act && wordcloud_grouping($act)['id'] !== null;
$post = fn(string $action, string $label, string $class = 'btn-ghost', string $confirm = '', string $title = '') =>
    '<form method="post"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '><input type="hidden" name="csrf" value="' . e($csrf) . '"><input type="hidden" name="action" value="' . e($action) . '"><button class="' . e($class) . '" type="submit"' . ($title !== '' ? ' title="' . e($title) . '"' : '') . '>' . e($label) . '</button></form>';

admin_header($act ? $act['name'] : 'Neue Wortwolke', 'wordclouds');
?>
<?php if ($act && $act['deleted_at'] !== null): ?>
<div class="page-head">
  <div>
    <h1><?= e($act['name']) ?> <span class="pill status-trash">Papierkorb</span></h1>
    <p class="muted">Am <?= e(admin_time($act['deleted_at'])) ?> gelöscht. Nach dreissig Tagen wird sie mit dem Papierkorb endgültig entfernt.</p>
  </div>
  <div class="page-actions">
    <?= $post('restore', 'Wiederherstellen', 'btn') ?>
    <?= $post('delete_forever', 'Endgültig löschen', 'btn-danger', 'Wortwolke jetzt endgültig löschen? Alle Begriffe sind danach weg.') ?>
  </div>
</div>
<?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
<?php admin_footer(); exit; endif; ?>

<?php if ($act): ?>
<div class="page-head">
  <div>
    <h1><?= e($act['name']) ?> <span class="pill status-<?= e($act['status']) ?>"><?= e(admin_status_label($act['status'])) ?></span></h1>
    <p class="muted"><a href="<?= e($act['url']) ?>" target="_blank" rel="noopener"><?= e($act['url']) ?></a> · Wortwolke · Runde <?= $act['round'] ?> · <?= activity_response_count($act) ?> Personen · <?= $act['config']['live'] ? 'live sichtbar' : ($act['revealed'] ? 'angezeigt' : 'verborgen bis «Anzeigen»') ?></p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= e($act['url']) ?>" target="_blank" rel="noopener">Wortwolke öffnen</a>
    <button class="btn-ghost" type="button" data-copy="<?= e($act['url']) ?>">Link kopieren</button>
  </div>
</div>
<?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>

<div class="grid-2">
  <section class="card" id="qr">
    <h2>QR‑Code</h2>
    <div class="qr-box">
      <img src="/api/activities/<?= e($act['akey']) ?>/qr.svg" alt="QR-Code zur Wortwolke" width="180" height="180">
      <div>
        <p class="muted">Enthält den Link <code><?= e($act['url']) ?></code>. Der lange Link <code><?= e($act['long_url']) ?></code> funktioniert ebenfalls.</p>
        <p><a class="btn-ghost" href="/api/activities/<?= e($act['akey']) ?>/qr.png?px=20" download="<?= e(preg_replace('/[^a-z0-9]+/i', '-', $act['name'])) ?>-qr.png">PNG laden</a>
           <a class="btn-ghost" href="/api/activities/<?= e($act['akey']) ?>/qr.svg" download="<?= e(preg_replace('/[^a-z0-9]+/i', '-', $act['name'])) ?>-qr.svg">SVG laden</a></p>
      </div>
    </div>
  </section>

  <section class="card">
    <h2>Steuerung</h2>
    <div class="action-grid">
      <?= $aiStatus['available'] ? $post('merge', '✨ Mit KI zusammenlegen', 'btn', '', 'Synonyme, Mehrzahl und Tippfehler zusammenlegen; von Hand Korrigiertes bleibt') : '' ?>
      <?= $canUndo ? $post('undo', 'Letzte Einteilung rückgängig') : '' ?>
      <?php if (!$act['config']['live']): ?><?= $post($act['revealed'] ? 'hide' : 'reveal', $act['revealed'] ? 'Wolke verbergen' : 'Wolke anzeigen', 'btn') ?><?php endif; ?>
      <?= $post('round', 'Neue Runde', 'btn-ghost', 'Neue Runde beginnen? Die Wolke wird leer. Die bisherigen Begriffe bleiben hier lesbar.') ?>
      <?php if ($act['status'] === 'open'): ?>
        <?= $post('close', 'Schliessen', 'btn-ghost', '', 'Anschauen bleibt möglich, neue Begriffe nicht mehr') ?>
      <?php elseif ($act['status'] === 'closed'): ?>
        <?= $post('open', 'Öffnen') ?>
      <?php else: ?>
        <?= $post('open', 'Aus dem Archiv holen', 'btn') ?>
      <?php endif; ?>
      <?= $post('duplicate', 'Duplizieren', 'btn-ghost', '', 'Frage und Einstellungen ohne Begriffe kopieren') ?>
      <?php if ($act['status'] !== 'archived'): ?><?= $post('archive', 'Archivieren', 'btn-ghost', 'Wortwolke archivieren? Sie ist danach für die Klasse nicht mehr erreichbar.') ?><?php endif; ?>
      <a class="btn-ghost" href="/api/activities/<?= e($act['akey']) ?>/results.md<?= $viewRound ? '?round=' . $viewRound : '' ?>" target="_blank">Begriffe (Markdown)</a>
      <?= $post('delete', 'Löschen', 'btn-danger', 'Wortwolke in den Papierkorb legen? Sie wird nach 30 Tagen endgültig gelöscht.') ?>
    </div>
    <?php if (!$aiStatus['available']): ?><p class="hint">KI-Zusammenlegen ist nicht verfügbar: <?= e(t('ai_unavailable_' . $aiStatus['reason'], 'de')) ?> <?php if ($aiStatus['reason'] !== 'activity_off'): ?><a href="<?= e(wb_admin_url()) ?>settings.php#ki">Einstellungen</a><?php endif; ?></p><?php endif; ?>
    <p class="hint">Auf der Wolke selbst (angemeldet) stehen «✨ Zusammenlegen» und «Rückgängig» in der Kopfzeile. Ein Klick auf einen Begriff zeigt, woraus er zusammengelegt ist, und erlaubt Umbenennen, Lösen und Ausblenden.</p>
  </section>
</div>

<section class="card" id="begriffe">
  <h2>Begriffe <span class="muted">Runde <?= $results['round'] ?><?= $results['round'] === $act['round'] ? ' (laufend)' : '' ?>, <?= $results['responses'] ?> Personen, <?= $results['entries'] ?> Einträge, <?= count($results['terms']) ?> Begriffe</span></h2>
  <?php if (count($results['rounds']) > 1): ?>
  <p class="tags"><?php foreach ($results['rounds'] as $r): ?><a class="tag <?= $r['round'] === $results['round'] ? 'active' : '' ?>" href="?key=<?= e($act['akey']) ?>&amp;round=<?= $r['round'] ?>#begriffe">Runde <?= $r['round'] ?> <small><?= $r['responses'] ?></small></a><?php endforeach; ?></p>
  <?php endif; ?>
  <?php if (!$results['terms']): ?>
    <p class="muted">Noch keine Begriffe in dieser Runde.</p>
  <?php else: ?>
  <table class="results wc-results">
    <thead><tr><th>Begriff</th><th>Personen</th><th>Zusammengelegt aus</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($results['terms'] as $x): ?>
      <tr<?= $x['hidden'] ? ' class="muted"' : '' ?>>
        <td><?= $x['hidden'] ? '<s>' . e($x['label']) . '</s>' : '<strong>' . e($x['label']) . '</strong>' ?></td>
        <td><?= $x['n'] ?></td>
        <td><?= count($x['variants']) > 1 || $x['label'] !== $x['variants'][0]['text'] ? e(implode(', ', array_map(fn($vv) => $vv['text'] . ' (' . $vv['n'] . ')', $x['variants']))) : '' ?></td>
        <td><?php if ($x['hidden'] && $results['round'] === $act['round']): ?><form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="show_term"><input type="hidden" name="term" value="<?= e($x['id']) ?>"><button class="btn-ghost small" type="submit">Einblenden</button></form><?php elseif ($x['fixed']): ?><span class="muted" title="Von Hand korrigiert; KI-Zusammenlegen ändert das nicht">von Hand</span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="hint">Gezählt werden Personen, nicht Einträge: Wer «Auto» und «Autos» schreibt, zählt nach dem Zusammenlegen einmal. Die Rohbegriffe bleiben immer gespeichert. <?php if ($results['round'] !== $act['round']): ?><a href="<?= e($act['long_url']) ?>?round=<?= $results['round'] ?>" target="_blank">Diese Runde als Wolke ansehen</a><?php endif; ?></p>
  <?php endif; ?>
  <?php if ($history && $results['round'] === $act['round']): ?>
  <h3>Verlauf der Einteilung</h3>
  <ul class="history">
    <?php foreach ($history as $h): ?><li<?= $h['undone'] ? ' class="muted"' : '' ?>><?= e($h['created_at']) ?> · <?= $h['undone'] ? '<s>' . e($h['label']) . '</s> (rückgängig gemacht)' : e($h['label']) ?> <span class="muted">· <?= e(admin_actor_label($h['actor'])) ?></span></li><?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php else: ?>
<div class="page-head"><h1>Neue Wortwolke</h1></div>
<?php endif; ?>

<section class="card">
  <h2><?= $act ? 'Einstellungen' : 'Wortwolke' ?></h2>
  <?php if ($error && !$act): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
  <form method="post" class="form">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="save">
    <div class="row">
      <label for="name">Frage oder Impuls</label>
      <input id="name" name="name" value="<?= e($v['name']) ?>" maxlength="200" required placeholder="Was fällt dir zu … ein?" <?= $act ? '' : 'autofocus' ?>>
      <p class="hint">Erscheint gross über der Wolke.</p>
    </div>
    <div class="row">
      <label for="slug">Kurzwort für den Link</label>
      <div class="slug-row"><span class="muted"><?= e(wb_base_url()) ?>/</span><input id="slug" name="slug" value="<?= e((string)$v['slug']) ?>" maxlength="32" pattern="[a-z0-9][a-z0-9-]{1,30}[a-z0-9]" placeholder="leer lassen für ein automatisches Wort"></div>
      <p class="hint">Boards, Zielscheiben und Wortwolken teilen sich die Kurzwörter.</p>
    </div>
    <div class="row two">
      <div>
        <label for="max_terms">Begriffe pro Person</label>
        <select id="max_terms" name="max_terms">
          <?php for ($i = 1; $i <= WORDCLOUD_MAX_TERMS; $i++): ?><option value="<?= $i ?>" <?= $v['max_terms'] === $i ? 'selected' : '' ?>><?= $i ?></option><?php endfor; ?>
        </select>
      </div>
      <div>
        <label for="lang">Sprache</label>
        <select id="lang" name="lang">
          <option value="de" <?= $v['lang'] === 'de' ? 'selected' : '' ?>>Deutsch</option>
          <option value="en" <?= $v['lang'] === 'en' ? 'selected' : '' ?>>English</option>
        </select>
      </div>
    </div>
    <div class="row">
      <div class="checks">
        <label><input type="checkbox" name="live" <?= $v['live'] ? 'checked' : '' ?>> Live: Die Wolke wächst bei allen sichtbar mit (sonst verborgen, bis du «Anzeigen» drückst)</label>
        <label><input type="checkbox" name="ai" <?= $v['ai'] ? 'checked' : '' ?>> KI-Funktionen erlaubt (Begriffe gehen beim Zusammenlegen ohne Namen an den KI-Anbieter)</label>
      </div>
    </div>
    <div class="row">
      <span class="label">Hintergrund</span>
      <div class="swatches">
        <?php foreach (wb_backgrounds() as $bg): ?>
          <label class="swatch bg-<?= $bg ?>" title="<?= e(admin_bg_label($bg)) ?>">
            <input type="radio" name="background" value="<?= $bg ?>" <?= $v['background'] === $bg ? 'checked' : '' ?>>
            <span><?= e(admin_bg_label($bg)) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="row">
      <label for="note">Notiz (für dich und Claude; durchsuchbar)</label>
      <textarea id="note" name="note" rows="3" placeholder="Klasse, Fach, Thema, Datum, Lektionsbezug …"><?= e($v['note']) ?></textarea>
    </div>
    <div class="row">
      <label for="tags">Tags (durch Komma getrennt)</label>
      <input id="tags" name="tags" value="<?= e(implode(', ', $v['tags'])) ?>" placeholder="einstieg, 3b, verkehr">
    </div>
    <div class="actions">
      <button class="btn" type="submit"><?= $act ? 'Speichern' : 'Wortwolke anlegen' ?></button>
      <?php if (!$act): ?><a class="btn-ghost" href="<?= e(wb_admin_url()) ?>wordclouds.php">Abbrechen</a><?php endif; ?>
    </div>
  </form>
</section>
<?php admin_footer();
