<?php
declare(strict_types=1);
// Eine Zielscheibe anlegen, bearbeiten, steuern und auswerten.
require __DIR__ . '/_layout.php';
admin_require_login();
admin_check_csrf();

$isNew = isset($_GET['new']);
$act = $isNew ? null : activity_by_key((string)($_GET['key'] ?? ''), true);
if ($act && $act['type'] === 'wordcloud') { header('Location: ' . wb_admin_url() . 'wordcloud.php?key=' . $act['akey']); exit; }
if (!$isNew && !$act) {
    http_response_code(404);
    admin_header('Nicht gefunden');
    echo '<section class="card"><h1>Zielscheibe nicht gefunden</h1><p><a href="' . e(wb_admin_url()) . 'targets.php">Zu den Zielscheiben</a></p></section>';
    admin_footer();
    exit;
}

/**
 * Zeilen des Formulars auf Aspekte abbilden: gleicher Wortlaut behält seinen Aspekt, sonst übernimmt eine Zeile
 * den Aspekt an derselben Stelle (Umbenennen), damit bestehende Antworten erhalten bleiben.
 */
function admin_target_aspects(string $text, array $current): array {
    $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $text) ?: []), fn($l) => $l !== ''));
    $byLabel = [];
    foreach ($current as $c) $byLabel[mb_strtolower($c['label'])] = $c['id'];
    $taken = [];
    foreach ($lines as $l) if (isset($byLabel[mb_strtolower($l)])) $taken[$byLabel[mb_strtolower($l)]] = true;
    $out = [];
    foreach ($lines as $i => $l) {
        $id = $byLabel[mb_strtolower($l)] ?? null;
        if ($id === null && isset($current[$i]) && !isset($taken[$current[$i]['id']])) { $id = $current[$i]['id']; $taken[$id] = true; }
        $out[] = $id ? ['id' => $id, 'label' => $l] : ['label' => $l];
    }
    return $out;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');
    try {
        if ($action === 'save') {
            $in = [
                'name' => $_POST['name'] ?? '',
                'slug' => $_POST['slug'] ?? '',
                'aspects' => admin_target_aspects((string)($_POST['aspects'] ?? ''), $act['config']['aspects'] ?? []),
                'color_mode' => $_POST['color_mode'] ?? 'sector',
                'require_all' => !empty($_POST['require_all']),
                'background' => $_POST['background'] ?? 'meer',
                'lang' => $_POST['lang'] ?? 'de',
                'note' => $_POST['note'] ?? '',
                'tags' => $_POST['tags'] ?? '',
            ];
            if ($act) { $act = activity_update($act, $in); admin_flash('Gespeichert.'); }
            else { $act = activity_create($in); admin_flash('Zielscheibe angelegt.'); }
            header('Location: ' . wb_admin_url() . 'target.php?key=' . $act['akey']);
            exit;
        }
        if ($act) {
            switch ($action) {
                case 'reveal': activity_set_revealed($act, true); admin_flash('Punkte werden angezeigt.'); break;
                case 'hide': activity_set_revealed($act, false); admin_flash('Punkte sind verborgen.'); break;
                case 'round': $a2 = activity_new_round($act); admin_flash('Runde ' . $a2['round'] . ' begonnen. Die bisherigen Antworten bleiben unten lesbar.'); break;
                case 'close': activity_update($act, ['status' => 'closed']); admin_flash('Zielscheibe geschlossen.'); break;
                case 'open':
                    $wasArchived = $act['status'] === 'archived';
                    $a2 = activity_update($act, ['status' => 'open']);
                    admin_flash($wasArchived ? 'Aus dem Archiv geholt. Link: ' . $a2['url'] : 'Zielscheibe geöffnet.');
                    break;
                case 'archive': activity_update($act, ['status' => 'archived']); admin_flash('Zielscheibe archiviert.'); break;
                case 'duplicate':
                    $copy = activity_duplicate($act);
                    admin_flash('Kopie angelegt.');
                    header('Location: ' . wb_admin_url() . 'target.php?key=' . $copy['akey']);
                    exit;
                case 'delete':
                    activity_trash($act);
                    admin_flash('Zielscheibe «' . $act['name'] . '» in den Papierkorb gelegt (30 Tage wiederherstellbar).');
                    header('Location: ' . wb_admin_url() . 'targets.php');
                    exit;
                case 'restore':
                    $a2 = activity_restore($act);
                    admin_flash('Wiederhergestellt. Link: ' . $a2['url']);
                    break;
                case 'delete_forever':
                    if ($act['deleted_at'] === null) { $error = 'Nur Zielscheiben im Papierkorb lassen sich endgültig löschen.'; break; }
                    activity_delete_forever($act);
                    admin_flash('Zielscheibe endgültig gelöscht.');
                    header('Location: ' . wb_admin_url() . 'targets.php?status=trash');
                    exit;
            }
            if (!$error) { header('Location: ' . wb_admin_url() . 'target.php?key=' . $act['akey']); exit; }
        }
    } catch (InvalidArgumentException $ex) {
        $error = $ex->getMessage();
    }
}

$v = $act ? [
    'name' => $act['name'], 'slug' => $act['slug'], 'aspects' => implode("\n", array_column($act['config']['aspects'], 'label')),
    'color_mode' => $act['config']['color_mode'], 'require_all' => $act['config']['require_all'], 'background' => $act['background'],
    'lang' => $act['lang'], 'note' => (string)$act['note'], 'tags' => $act['tags'],
] : [
    'name' => '', 'slug' => '', 'aspects' => '', 'color_mode' => 'sector', 'require_all' => true, 'background' => 'meer',
    'lang' => (string)wb_config('default_lang', 'de'), 'note' => '', 'tags' => [],
];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error && ($_POST['action'] ?? '') === 'save') {
    foreach (['name', 'slug', 'aspects', 'note'] as $k) $v[$k] = (string)($_POST[$k] ?? '');
    $v['tags'] = wb_parse_tags($_POST['tags'] ?? '');
    $v['color_mode'] = ($_POST['color_mode'] ?? '') === 'scale' ? 'scale' : 'sector';
    $v['require_all'] = !empty($_POST['require_all']);
}
$csrf = wb_csrf_token();
$viewRound = $act && isset($_GET['round']) ? max(1, (int)$_GET['round']) : null;
$results = $act ? target_results($act, $viewRound) : null;

admin_header($act ? $act['name'] : 'Neue Zielscheibe', 'targets');
?>
<?php if ($act && $act['deleted_at'] !== null): ?>
<div class="page-head">
  <div>
    <h1><?= e($act['name']) ?> <span class="pill status-trash">Papierkorb</span></h1>
    <p class="muted">Am <?= e(admin_time($act['deleted_at'])) ?> gelöscht. Nach dreissig Tagen wird sie mit dem Papierkorb endgültig entfernt.</p>
  </div>
  <div class="page-actions">
    <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="restore"><button class="btn" type="submit">Wiederherstellen</button></form>
    <form method="post" data-confirm="Zielscheibe jetzt endgültig löschen? Alle Antworten sind danach weg."><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="delete_forever"><button class="btn-danger" type="submit">Endgültig löschen</button></form>
  </div>
</div>
<?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
<?php admin_footer(); exit; endif; ?>

<?php if ($act): ?>
<div class="page-head">
  <div>
    <h1><?= e($act['name']) ?> <span class="pill status-<?= e($act['status']) ?>"><?= e(admin_status_label($act['status'])) ?></span></h1>
    <p class="muted"><a href="<?= e($act['url']) ?>" target="_blank" rel="noopener"><?= e($act['url']) ?></a> · Zielscheibe · Runde <?= $act['round'] ?> · <?= activity_response_count($act) ?> Antworten · Punkte <?= $act['revealed'] ? 'angezeigt' : 'verborgen' ?></p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= e($act['url']) ?>" target="_blank" rel="noopener">Zielscheibe öffnen</a>
    <button class="btn-ghost" type="button" data-copy="<?= e($act['url']) ?>">Link kopieren</button>
  </div>
</div>

<div class="grid-2">
  <section class="card" id="qr">
    <h2>QR‑Code</h2>
    <div class="qr-box">
      <img src="/api/activities/<?= e($act['akey']) ?>/qr.svg" alt="QR-Code zur Zielscheibe" width="180" height="180">
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
      <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="<?= $act['revealed'] ? 'hide' : 'reveal' ?>"><button class="btn" type="submit"><?= $act['revealed'] ? 'Punkte verbergen' : 'Punkte anzeigen' ?></button></form>
      <form method="post" data-confirm="Neue Runde beginnen? Die Scheibe wird leer und die Punkte werden verborgen. Die bisherigen Antworten bleiben hier lesbar."><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="round"><button class="btn-ghost" type="submit">Neue Runde</button></form>
      <?php if ($act['status'] === 'open'): ?>
        <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="close"><button class="btn-ghost" type="submit" title="Anschauen bleibt möglich, neue Punkte nicht mehr">Schliessen</button></form>
      <?php elseif ($act['status'] === 'closed'): ?>
        <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="open"><button class="btn-ghost" type="submit">Öffnen</button></form>
      <?php else: ?>
        <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="open"><button class="btn" type="submit">Aus dem Archiv holen</button></form>
      <?php endif; ?>
      <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="duplicate"><button class="btn-ghost" type="submit" title="Aspekte und Einstellungen ohne Antworten kopieren">Duplizieren</button></form>
      <?php if ($act['status'] !== 'archived'): ?>
        <form method="post" data-confirm="Zielscheibe archivieren? Sie ist danach für die Klasse nicht mehr erreichbar."><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="archive"><button class="btn-ghost" type="submit">Archivieren</button></form>
      <?php endif; ?>
      <a class="btn-ghost" href="/api/activities/<?= e($act['akey']) ?>/results.md<?= $viewRound ? '?round=' . $viewRound : '' ?>" target="_blank">Auswertung (Markdown)</a>
      <form method="post" data-confirm="Zielscheibe in den Papierkorb legen? Sie wird nach 30 Tagen endgültig gelöscht."><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="delete"><button class="btn-danger" type="submit">Löschen</button></form>
    </div>
    <p class="hint">«Anzeigen» und «Verbergen» gibt es auch direkt auf der Zielscheibe (unten rechts), wenn du hier angemeldet bist.</p>
  </section>
</div>

<section class="card" id="auswertung">
  <h2>Auswertung <span class="muted">Runde <?= $results['round'] ?><?= $results['round'] === $act['round'] ? ' (laufend)' : '' ?>, <?= $results['responses'] ?> Antworten</span></h2>
  <?php if (count($results['rounds']) > 1): ?>
  <p class="tags"><?php foreach ($results['rounds'] as $r): ?><a class="tag <?= $r['round'] === $results['round'] ? 'active' : '' ?>" href="?key=<?= e($act['akey']) ?>&amp;round=<?= $r['round'] ?>#auswertung">Runde <?= $r['round'] ?> <small><?= $r['responses'] ?></small></a><?php endforeach; ?></p>
  <?php endif; ?>
  <?php if ($results['responses'] === 0): ?>
    <p class="muted">Noch keine Antworten in dieser Runde.</p>
  <?php else: ?>
  <table class="results">
    <thead><tr><th>Aspekt</th><th>n</th><th>Mittel</th><th>Median</th><th>Spanne</th><th>Verteilung (innen → aussen)</th></tr></thead>
    <tbody>
    <?php foreach ($results['aspects'] as $x): $max = max(1, max($x['rings'])); ?>
      <tr>
        <td><?= e($x['label']) ?></td>
        <td><?= $x['n'] ?></td>
        <td><strong><?= $x['mean'] !== null ? number_format($x['mean'], 1) : '–' ?></strong></td>
        <td><?= $x['median'] !== null ? number_format($x['median'], 1) : '–' ?></td>
        <td><?= $x['n'] ? $x['min'] . '–' . $x['max'] : '–' ?></td>
        <td><span class="rings"><?php foreach ($x['rings'] as $k => $c): ?><span class="ring-bar ring-<?= $k + 1 ?>" style="height:<?= 4 + round(24 * $c / $max) ?>px" title="Ring <?= $k + 1 ?>: <?= $c ?>"></span><?php endforeach; ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="hint">Wertung 0 bis 10: 10 ist die Mitte (beste Einschätzung), 0 der Rand. <?php if ($results['round'] !== $act['round']): ?><a href="<?= e($act['long_url']) ?>?round=<?= $results['round'] ?>" target="_blank">Diese Runde auf der Scheibe ansehen</a><?php endif; ?></p>
  <?php endif; ?>
</section>
<?php else: ?>
<div class="page-head"><h1>Neue Zielscheibe</h1></div>
<?php endif; ?>

<section class="card">
  <h2><?= $act ? 'Einstellungen' : 'Zielscheibe' ?></h2>
  <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
  <form method="post" class="form">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="save">
    <div class="row">
      <label for="name">Name</label>
      <input id="name" name="name" value="<?= e($v['name']) ?>" maxlength="200" required <?= $act ? '' : 'autofocus' ?>>
    </div>
    <div class="row">
      <label for="slug">Kurzwort für den Link</label>
      <div class="slug-row"><span class="muted"><?= e(wb_base_url()) ?>/</span><input id="slug" name="slug" value="<?= e((string)$v['slug']) ?>" maxlength="32" pattern="[a-z0-9][a-z0-9-]{1,30}[a-z0-9]" placeholder="leer lassen für ein automatisches Wort"></div>
      <p class="hint">Boards und Zielscheiben teilen sich die Kurzwörter.</p>
    </div>
    <div class="row">
      <label for="aspects">Aspekte (einer pro Zeile, <?= TARGET_MIN_ASPECTS ?> bis <?= TARGET_MAX_ASPECTS ?>)</label>
      <textarea id="aspects" name="aspects" rows="6" placeholder="Teamarbeit&#10;Zeitmanagement&#10;Inhalt verstanden&#10;Präsentation&#10;Spass" required><?= e($v['aspects']) ?></textarea>
      <p class="hint">Jeder Aspekt wird ein Sektor der Scheibe, im Uhrzeigersinn ab zwölf Uhr. Kurze Begriffe lesen sich am besten. <?= $act ? 'Beim Umbenennen bleiben die Punkte erhalten; ein entfernter Aspekt verschwindet mit seinen Punkten aus der Anzeige.' : '' ?></p>
    </div>
    <div class="row two">
      <div>
        <label for="color_mode">Farben der Punkte</label>
        <select id="color_mode" name="color_mode">
          <option value="sector" <?= $v['color_mode'] === 'sector' ? 'selected' : '' ?>>Eine Farbe pro Sektor (automatisch)</option>
          <option value="scale" <?= $v['color_mode'] === 'scale' ? 'selected' : '' ?>>Nach Lage: grün in der Mitte, rot am Rand</option>
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
      <div class="checks"><label><input type="checkbox" name="require_all" <?= $v['require_all'] ? 'checked' : '' ?>> In jedem Sektor muss ein Punkt gesetzt sein, bevor man bestätigen kann</label></div>
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
      <input id="tags" name="tags" value="<?= e(implode(', ', $v['tags'])) ?>" placeholder="feedback, 3b, projektwoche">
    </div>
    <div class="actions">
      <button class="btn" type="submit"><?= $act ? 'Speichern' : 'Zielscheibe anlegen' ?></button>
      <?php if (!$act): ?><a class="btn-ghost" href="<?= e(wb_admin_url()) ?>targets.php">Abbrechen</a><?php endif; ?>
    </div>
  </form>
</section>
<?php admin_footer();
