<?php
declare(strict_types=1);
require __DIR__ . '/_layout.php';
admin_require_login();
admin_check_csrf();

$isNew = isset($_GET['new']);
$board = $isNew ? null : board_by_key((string)($_GET['key'] ?? ''));
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
                case 'clear': board_clear($board); admin_flash('Alle Beiträge entfernt.'); break;
                case 'close': board_update($board, ['status' => 'closed']); admin_flash('Board geschlossen.'); break;
                case 'open': board_update($board, ['status' => 'open']); admin_flash('Board geöffnet.'); break;
                case 'archive': board_update($board, ['status' => 'archived']); admin_flash('Board archiviert.'); break;
                case 'duplicate':
                    $copy = board_duplicate($board);
                    admin_flash('Kopie angelegt.');
                    header('Location: /admin/board.php?key=' . $copy['bkey']);
                    exit;
                case 'delete':
                    board_delete_forever($board);
                    admin_flash('Board endgültig gelöscht.');
                    header('Location: /admin/');
                    exit;
                case 'delete_post':
                    $pid = (int)($_POST['id'] ?? 0);
                    $now = wb_now();
                    db_exec('UPDATE posts SET deleted_at = ?, updated_at = ? WHERE id = ? AND board_id = ?', [$now, $now, $pid, $board['id']]);
                    db_exec('UPDATE comments SET deleted_at = ?, updated_at = ? WHERE post_id = ? AND deleted_at IS NULL', [$now, $now, $pid]);
                    board_touch($board['id']);
                    admin_flash('Beitrag gelöscht.');
                    break;
                case 'delete_comment':
                    $cid = (int)($_POST['id'] ?? 0);
                    $now = wb_now();
                    db_exec('UPDATE comments SET deleted_at = ?, updated_at = ? WHERE id = ? AND board_id = ?', [$now, $now, $cid, $board['id']]);
                    board_touch($board['id']);
                    admin_flash('Kommentar gelöscht.');
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
      <?php else: ?>
        <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="open"><button class="btn-ghost" type="submit">Öffnen</button></form>
      <?php endif; ?>
      <form method="post" data-confirm="Alle Beiträge und Kommentare dieses Boards entfernen?"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="clear"><button class="btn-ghost" type="submit">Leeren</button></form>
      <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="duplicate"><button class="btn-ghost" type="submit" title="Struktur ohne Inhalte kopieren">Duplizieren</button></form>
      <?php if ($board['status'] !== 'archived'): ?>
        <form method="post" data-confirm="Board archivieren? Es ist danach für Gäste nicht mehr erreichbar."><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="archive"><button class="btn-ghost" type="submit">Archivieren</button></form>
      <?php endif; ?>
      <a class="btn-ghost" href="/api/boards/<?= e($board['bkey']) ?>/export.md" target="_blank">Export Markdown</a>
      <a class="btn-ghost" href="/admin/export.php?key=<?= e($board['bkey']) ?>" target="_blank">Druckansicht / PDF</a>
      <form method="post" data-confirm="Board endgültig löschen? Das kann nicht rückgängig gemacht werden."><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="delete"><button class="btn-danger" type="submit">Endgültig löschen</button></form>
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
      </div>
    </div>
    <div class="row">
      <span class="label">Erlaubte Emojis</span>
      <div class="checks emojis">
        <?php foreach (wb_emojis() as $em): ?>
          <label><input type="checkbox" name="emojis[]" value="<?= e($em) ?>" <?= in_array($em, $v['settings']['emojis'], true) ? 'checked' : '' ?>> <?= e($em) ?></label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="actions">
      <button class="btn" type="submit"><?= $board ? 'Speichern' : 'Board anlegen' ?></button>
      <?php if (!$board): ?><a class="btn-ghost" href="/admin/">Abbrechen</a><?php endif; ?>
    </div>
  </form>
</section>

<?php if ($board): ?>
<section class="card" id="inhalt">
  <h2>Inhalt <span class="muted"><?= count($export['posts']) ?> Beiträge</span></h2>
  <?php if (!$export['posts']): ?>
    <p class="muted">Noch keine Beiträge.</p>
  <?php else: ?>
  <ul class="postlist">
    <?php foreach ($export['posts'] as $p): ?>
    <li class="postitem">
      <div class="postitem-head">
        <strong><?= e($p['title'] ?: '—') ?></strong>
        <span class="muted"><?= e($p['author']) ?> · <?= e($p['created_at']) ?><?= $p['column'] ? ' · ' . e($p['column']) : '' ?></span>
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
