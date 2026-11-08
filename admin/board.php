<?php
declare(strict_types=1);
require __DIR__ . '/_layout.php';
admin_require_login();
admin_check_csrf();

$isNew = isset($_GET['new']);
$board = $isNew ? null : board_by_key((string)($_GET['key'] ?? ''), true);
if (!$isNew && !$board) {
    http_response_code(404);
    admin_header('Nicht gefunden');
    echo '<section class="card"><h1>Board nicht gefunden</h1><p><a href="/admin/">Zur Bibliothek</a></p></section>';
    admin_footer();
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');
    try {
        if ($action === 'save') {
            $in = [
                'name' => $_POST['name'] ?? '',
                'slug' => $_POST['slug'] ?? '',
                'type' => $_POST['type'] ?? 'wall',
                'columns' => $_POST['columns'] ?? '',
                'background' => $_POST['background'] ?? 'morgen',
                'lang' => $_POST['lang'] ?? 'de',
                'note' => $_POST['note'] ?? '',
                'tags' => $_POST['tags'] ?? '',
                'settings' => [
                    'comments' => !empty($_POST['s_comments']),
                    'reactions' => !empty($_POST['s_reactions']),
                    'images' => !empty($_POST['s_images']),
                    'links' => !empty($_POST['s_links']),
                    'new_on_top' => !empty($_POST['s_new_on_top']),
                    'ai' => !empty($_POST['s_ai']),
                    'emojis' => $_POST['emojis'] ?? [],
                ],
            ];
            if ($board) {
                // Bestehende Spalten-IDs erhalten, damit Beiträge zugeordnet bleiben.
                $lines = array_values(array_filter(array_map('trim', explode("\n", (string)$in['columns'])), fn($l) => $l !== ''));
                $cols = [];
                foreach ($lines as $i => $title) {
                    $cols[] = ['id' => $board['columns'][$i]['id'] ?? null, 'title' => $title];
                }
                $in['columns'] = $cols;
                $board = board_update($board, $in);
                admin_flash('Gespeichert.');
            } else {
                $board = board_create($in);
                admin_flash('Board angelegt.');
            }
            header('Location: /admin/board.php?key=' . $board['bkey']);
            exit;
        }
        if ($board) {
            switch ($action) {
                case 'clear': $u = board_clear($board); admin_flash('Alle Beiträge entfernt.', $u, $board['bkey']); break;
                case 'close': board_update($board, ['status' => 'closed']); admin_flash('Board geschlossen.'); break;
                case 'open':
                    $wasArchived = $board['status'] === 'archived';
                    $b2 = board_update($board, ['status' => 'open']);
                    admin_flash($wasArchived ? 'Board aus dem Archiv geholt. Link: ' . $b2['url'] : 'Board geöffnet.');
                    break;
                case 'archive': board_update($board, ['status' => 'archived']); admin_flash('Board archiviert.'); break;
                case 'duplicate':
                    $copy = board_duplicate($board);
                    admin_flash('Kopie angelegt.');
                    header('Location: /admin/board.php?key=' . $copy['bkey']);
                    exit;
                case 'delete':
                    $u = board_trash($board);
                    admin_flash('Board «' . $board['name'] . '» in den Papierkorb gelegt (30 Tage wiederherstellbar).', $u, $board['bkey']);
                    header('Location: /admin/');
                    exit;
                case 'restore':
                    $b2 = board_restore($board);
                    admin_flash('Board wiederhergestellt. Link: ' . $b2['url']);
                    break;
                case 'delete_forever':
                    if ($board['deleted_at'] === null) { $error = 'Nur Boards im Papierkorb lassen sich endgültig löschen.'; break; }
                    board_delete_forever($board);
                    admin_flash('Board endgültig gelöscht.');
                    header('Location: /admin/?status=trash');
                    exit;
                case 'undo':
                    try {
                        $entry = undo_apply($board, (int)($_POST['id'] ?? 0));
                        admin_flash('Rückgängig gemacht: ' . $entry['label'] . '.');
                    } catch (RuntimeException $ex) {
                        admin_flash($ex->getMessage());
                    }
                    break;
                case 'delete_post':
                    $u = post_soft_delete($board, (int)($_POST['id'] ?? 0), true);
                    admin_flash($u >= 0 ? 'Beitrag gelöscht.' : 'Beitrag nicht gefunden.', max(0, $u), $board['bkey']);
                    break;
                case 'delete_bundle':
                    $g = bundle_by_id($board, (int)($_POST['id'] ?? 0));
                    if ($g) { $u = bundle_delete($board, $g); admin_flash('Bündel aufgelöst, Beiträge bleiben erhalten.', $u, $board['bkey']); }
                    break;
                case 'delete_comment':
                    $u = comment_soft_delete($board, (int)($_POST['id'] ?? 0), true);
                    admin_flash($u >= 0 ? 'Kommentar gelöscht.' : 'Kommentar nicht gefunden.', max(0, $u), $board['bkey']);
                    break;
            }
            header('Location: /admin/board.php?key=' . $board['bkey'] . ($action === 'delete_post' || $action === 'delete_comment' ? '#inhalt' : ''));
            exit;
        }
    } catch (InvalidArgumentException $ex) {
        $error = $ex->getMessage();
    }
}

$v = $board ?: [
    'name' => '', 'slug' => '', 'type' => 'wall', 'columns' => [], 'background' => 'morgen', 'lang' => (string)wb_config('default_lang', 'de'),
    'settings' => wb_default_settings(), 'note' => '', 'tags' => [], 'status' => 'open',
];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error) {
    $v['name'] = $_POST['name'] ?? ''; $v['note'] = $_POST['note'] ?? '';
}
$csrf = wb_csrf_token();
$export = $board ? board_export_data($board) : null;

admin_header($board ? $board['name'] : 'Neues Board', $board ? 'library' : 'new');
?>
<?php if ($board && $board['deleted_at'] !== null): ?>
<div class="page-head">
  <div>
    <h1><?= e($board['name']) ?> <span class="pill status-trash">Papierkorb</span></h1>
    <p class="muted">Am <?= e(admin_time($board['deleted_at'])) ?> gelöscht. Es wird <?= e(admin_time((new DateTime($board['deleted_at'], new DateTimeZone('UTC')))->modify('+30 days')->format('Y-m-d H:i:s'))) ?> endgültig entfernt, falls du es nicht wiederherstellst.</p>
  </div>
  <div class="page-actions">
    <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="restore"><button class="btn" type="submit">Wiederherstellen</button></form>
    <form method="post" data-confirm="Board jetzt endgültig löschen? Beiträge, Kommentare und Bilder sind danach weg."><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="delete_forever"><button class="btn-danger" type="submit">Endgültig löschen</button></form>
  </div>
</div>
<?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
<section class="card" id="inhalt">
  <h2>Inhalt <span class="muted"><?= count($export['posts']) ?> Beiträge</span></h2>
  <?php foreach ($export['posts'] as $p): ?><p class="postitem-body"><strong><?= e($p['title'] ?: '—') ?></strong> · <?= e($p['author']) ?> · <?= nl2br(e(mb_strimwidth($p['body'], 0, 200, '…'))) ?></p><?php endforeach; ?>
</section>
<?php admin_footer(); exit; endif; ?>
<?php if ($board): ?>
<div class="page-head">
  <div>
    <h1><?= e($board['name']) ?> <span class="pill status-<?= e($board['status']) ?>"><?= e(admin_status_label($board['status'])) ?></span></h1>
    <p class="muted"><a href="<?= e($board['url']) ?>" target="_blank" rel="noopener"><?= e($board['url']) ?></a></p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= e($board['url']) ?>" target="_blank" rel="noopener">Board öffnen</a>
    <button class="btn-ghost" type="button" data-copy="<?= e($board['url']) ?>">Link kopieren</button>
  </div>
</div>

<div class="grid-2">
  <section class="card" id="qr">
    <h2>QR‑Code</h2>
    <div class="qr-box">
      <img src="/api/boards/<?= e($board['bkey']) ?>/qr.svg" alt="QR-Code zum Board" width="180" height="180">
      <div>
        <p class="muted">Für Folien und Arbeitsblätter. Beide Formate enthalten den Link <code><?= e($board['url']) ?></code>. Der lange Link <code><?= e($board['long_url']) ?></code> funktioniert ebenfalls.</p>
        <p><a class="btn-ghost" href="/api/boards/<?= e($board['bkey']) ?>/qr.png?px=20" download="<?= e(preg_replace('/[^a-z0-9]+/i', '-', $board['name'])) ?>-qr.png">PNG laden</a>
           <a class="btn-ghost" href="/api/boards/<?= e($board['bkey']) ?>/qr.svg" download="<?= e(preg_replace('/[^a-z0-9]+/i', '-', $board['name'])) ?>-qr.svg">SVG laden</a></p>
      </div>
    </div>
  </section>

  <section class="card">
    <h2>Aktionen</h2>
    <div class="action-grid">
      <?php if ($board['status'] === 'open'): ?>
        <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="close"><button class="btn-ghost" type="submit" title="Lesen bleibt möglich, Schreiben nicht mehr">Schliessen</button></form>
      <?php elseif ($board['status'] === 'closed'): ?>
        <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="open"><button class="btn-ghost" type="submit" title="Schreiben wieder erlauben">Öffnen</button></form>
      <?php else: ?>
        <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="open"><button class="btn" type="submit" title="Board wieder aktivieren; ein inzwischen vergebenes Kurzwort wird ersetzt">Aus dem Archiv holen</button></form>
      <?php endif; ?>
      <form method="post" data-confirm="Alle Beiträge und Kommentare dieses Boards entfernen?"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="clear"><button class="btn-ghost" type="submit">Leeren</button></form>
      <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="duplicate"><button class="btn-ghost" type="submit" title="Struktur ohne Inhalte kopieren">Duplizieren</button></form>
      <?php if ($board['status'] !== 'archived'): ?>
        <form method="post" data-confirm="Board archivieren? Es ist danach für Gäste nicht mehr erreichbar."><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="archive"><button class="btn-ghost" type="submit">Archivieren</button></form>
      <?php endif; ?>
      <a class="btn-ghost" href="/api/boards/<?= e($board['bkey']) ?>/export.md" target="_blank">Export Markdown</a>
      <a class="btn-ghost" href="/b/<?= e($board['bkey']) ?>/export?ai=<?= summary_unavailable_reason($board, 'board') === null ? 'board' : 'none' ?>" target="_blank">Export / PDF (ohne Namen)</a>
      <a class="btn-ghost" href="/admin/export.php?key=<?= e($board['bkey']) ?>" target="_blank">Druckansicht mit Namen</a>
      <form method="post" data-confirm="Board in den Papierkorb legen? Es ist danach für die Klasse nicht mehr erreichbar und wird nach 30 Tagen endgültig gelöscht."><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="delete"><button class="btn-danger" type="submit">Löschen</button></form>
    </div>
  </section>
</div>
<?php else: ?>
<div class="page-head"><h1>Neues Board</h1></div>
<?php endif; ?>

<section class="card">
  <h2><?= $board ? 'Einstellungen' : 'Board' ?></h2>
  <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
  <form method="post" class="form">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="save">
    <div class="row">
      <label for="name">Name</label>
      <input id="name" name="name" value="<?= e($v['name']) ?>" maxlength="200" required autofocus>
    </div>
    <div class="row">
      <label for="slug">Kurzwort für den Link</label>
      <div class="slug-row"><span class="muted"><?= e(wb_base_url()) ?>/</span><input id="slug" name="slug" value="<?= e((string)$v['slug']) ?>" maxlength="32" pattern="[a-z0-9][a-z0-9-]{1,30}[a-z0-9]" placeholder="leer lassen für ein automatisches Wort"></div>
      <p class="hint">Kleinbuchstaben, Ziffern und Bindestrich. Leer lassen, dann entsteht ein aussprechbares Wort wie «tamok». Nach dem Archivieren wird das Wort wieder frei.</p>
    </div>
    <div class="row two">
      <div>
        <label for="type">Typ</label>
        <select id="type" name="type">
          <option value="wall" <?= $v['type'] === 'wall' ? 'selected' : '' ?>>Frei (platzfüllendes Raster)</option>
          <option value="columns" <?= $v['type'] === 'columns' ? 'selected' : '' ?>>Spalten</option>
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
    <div class="row" id="columns-row" <?= $v['type'] === 'columns' ? '' : 'hidden' ?>>
      <label for="columns">Spalten (eine pro Zeile)</label>
      <textarea id="columns" name="columns" rows="4"><?= e(implode("\n", array_column($v['columns'], 'title'))) ?></textarea>
      <?php if ($board && $board['columns']): ?><p class="hint">Reihenfolge beibehalten, damit bestehende Beiträge in ihrer Spalte bleiben. Eine entfernte Spalte lässt ihre Beiträge in die erste Spalte rutschen.</p><?php endif; ?>
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
      <textarea id="note" name="note" rows="3" placeholder="Klasse, Fach, Thema, Datum, Lektionsbezug …"><?= e((string)$v['note']) ?></textarea>
    </div>
    <div class="row">
      <label for="tags">Tags (durch Komma getrennt)</label>
      <input id="tags" name="tags" value="<?= e(implode(', ', $v['tags'])) ?>" placeholder="bio, 3b, einstieg">
    </div>
    <div class="row">
      <span class="label">Funktionen</span>
      <div class="checks">
        <label><input type="checkbox" name="s_comments" <?= $v['settings']['comments'] ? 'checked' : '' ?>> Kommentare</label>
        <label><input type="checkbox" name="s_reactions" <?= $v['settings']['reactions'] ? 'checked' : '' ?>> Reaktionen</label>
        <label><input type="checkbox" name="s_images" <?= $v['settings']['images'] ? 'checked' : '' ?>> Bilder</label>
        <label><input type="checkbox" name="s_links" <?= $v['settings']['links'] ? 'checked' : '' ?>> Links</label>
        <label><input type="checkbox" name="s_new_on_top" <?= $v['settings']['new_on_top'] ? 'checked' : '' ?>> Neue Beiträge zuoberst</label>
        <label title="Ausschalten für heikle Boards wie persönliches Feedback: dann werden keine Beiträge dieses Boards an einen KI-Anbieter geschickt."><input type="checkbox" name="s_ai" <?= !empty($v['settings']['ai']) ? 'checked' : '' ?>> KI-Funktionen erlaubt</label>
      </div>
    </div>
    <div class="row">
      <span class="label">Erlaubte Emojis</span>
      <div class="checks emojis">
        <?php foreach (wb_emojis() as $em): ?>
          <label><input type="checkbox" name="emojis[]" value="<?= e($em) ?>" <?= in_array($em, $v['settings']['emojis'], true) ? 'checked' : '' ?>> <?= e($em) ?></label>
        <?php endforeach; ?>
      </div>
      <p class="muted small">Ampel 🔴 🟡 🟢: Jede Person kann pro Beitrag nur eine Farbe wählen; eine neue Farbe ersetzt die bisherige.</p>
    </div>
    <div class="actions">
      <button class="btn" type="submit"><?= $board ? 'Speichern' : 'Board anlegen' ?></button>
      <?php if (!$board): ?><a class="btn-ghost" href="/admin/">Abbrechen</a><?php endif; ?>
    </div>
  </form>
</section>

<?php if ($board): ?>
<section class="card" id="inhalt">
  <h2>Inhalt <span class="muted"><?= count($export['posts']) ?> Beiträge<?= $export['bundles'] ? ', ' . count($export['bundles']) . ' Bündel' : '' ?></span></h2>
  <?php if ($export['bundles']): ?>
  <ul class="postlist">
    <?php foreach ($export['bundles'] as $g): ?>
    <li class="postitem">
      <div class="postitem-head">
        <strong>Bündel: <?= e($g['title']) ?></strong>
        <span class="muted"><?= count($g['post_ids']) ?> Beiträge<?= $g['column'] ? ' · ' . e($g['column']) : '' ?><?= $g['author'] ? ' · ' . e($g['author']) : '' ?></span>
        <form method="post" class="inline" data-confirm="Bündel auflösen? Die Beiträge bleiben erhalten."><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="delete_bundle"><input type="hidden" name="id" value="<?= $g['id'] ?>"><button class="link-danger" type="submit">Auflösen</button></form>
      </div>
      <?php if ($g['summary']): ?><p class="postitem-body"><?= nl2br(e($g['summary'])) ?></p><?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <?php if (!$export['posts']): ?>
    <p class="muted">Noch keine Beiträge.</p>
  <?php else: ?>
  <ul class="postlist">
    <?php foreach ($export['posts'] as $p): ?>
    <li class="postitem">
      <div class="postitem-head">
        <strong><?= e($p['title'] ?: '—') ?></strong>
        <span class="muted"><?= e($p['author']) ?> · <?= e($p['created_at']) ?><?= $p['column'] ? ' · ' . e($p['column']) : '' ?><?php if ($p['bundle_id']): foreach ($export['bundles'] as $g) if ($g['id'] === $p['bundle_id']) echo ' · Bündel «' . e($g['title']) . '»'; endif; ?></span>
        <form method="post" class="inline" data-confirm="Beitrag löschen?"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="delete_post"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="link-danger" type="submit">Löschen</button></form>
      </div>
      <?php if ($p['body']): ?><p class="postitem-body"><?= nl2br(e($p['body'])) ?></p><?php endif; ?>
      <?php if ($p['image']): ?><a href="<?= e($p['image']) ?>" target="_blank"><img class="postitem-img" src="<?= e(str_replace('/uploads/' . $board['bkey'] . '/', '/uploads/' . $board['bkey'] . '/thumb_', $p['image'])) ?>" alt=""></a><?php endif; ?>
      <?php if ($p['link']): ?><p><a href="<?= e($p['link']) ?>" target="_blank" rel="noopener"><?= e($p['link']) ?></a></p><?php endif; ?>
      <?php if ($p['reactions']): ?><p class="muted"><?= e(implode('  ', $p['reactions'])) ?></p><?php endif; ?>
      <?php if ($p['comments']): ?>
      <ul class="commentlist">
        <?php foreach ($p['comments'] as $c): ?>
        <li><strong><?= e($c['author']) ?>:</strong> <?= e($c['body']) ?>
          <form method="post" class="inline" data-confirm="Kommentar löschen?"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="delete_comment"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="link-danger" type="submit">Löschen</button></form>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php admin_footer();
