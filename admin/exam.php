<?php
declare(strict_types=1);
// Eine Gemeinschaftsprüfung anlegen, einstellen und ihre Fragen sichten (Etappe G1).
require __DIR__ . '/_layout.php';
admin_require_login();
admin_check_csrf();

$isNew = isset($_GET['new']);
$act = $isNew ? null : activity_by_key((string)($_GET['key'] ?? ''), true);
if ($act && $act['type'] !== 'exam') { header('Location: ' . wb_admin_url() . ($act['type'] === 'wordcloud' ? 'wordcloud' : 'target') . '.php?key=' . $act['akey']); exit; }
if (!$isNew && !$act) {
    http_response_code(404);
    admin_header('Nicht gefunden');
    echo '<section class="card"><h1>Prüfung nicht gefunden</h1><p><a href="' . e(wb_admin_url()) . 'exams.php">Zu den Prüfungen</a></p></section>';
    admin_footer();
    exit;
}

/** Liest eine Frage aus dem Bibliotheksformular (bis zu sechs Zeilen mit Text und Häkchen). */
function admin_exam_question_input(): array {
    $type = (string)($_POST['q_type'] ?? 'single');
    $in = ['type' => $type, 'prompt' => (string)($_POST['q_prompt'] ?? '')];
    if ($type === 'open') {
        $in['solution'] = (string)($_POST['q_solution'] ?? '');
    } else {
        $ids = (array)($_POST['opt_id'] ?? []);
        $texts = (array)($_POST['opt_text'] ?? []);
        $correct = array_map('strval', (array)($_POST['opt_correct'] ?? []));
        $opts = [];
        foreach ($texts as $i => $text) {
            $o = ['text' => (string)$text, 'correct' => in_array((string)$i, $correct, true)];
            if (!empty($ids[$i])) $o['id'] = (string)$ids[$i];
            $opts[] = $o;
        }
        $in['options'] = $opts;
    }
    $in['points'] = trim((string)($_POST['q_points'] ?? '')) === '' ? null : str_replace(',', '.', (string)$_POST['q_points']);
    return $in;
}

$error = null;
$tab = in_array($_GET['tab'] ?? '', ['submitted', 'accepted', 'rejected', 'trash'], true) ? $_GET['tab'] : 'all';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');
    $qid = (int)($_POST['qid'] ?? 0);
    $back = fn(string $anchor = '') => wb_admin_url() . 'exam.php?key=' . $act['akey'] . ($tab !== 'all' ? '&tab=' . $tab : '') . $anchor;
    try {
        if ($action === 'save') {
            $in = [
                'type' => 'exam',
                'name' => $_POST['name'] ?? '',
                'slug' => $_POST['slug'] ?? '',
                'quota_max' => (int)($_POST['quota_max'] ?? 3),
                'quota_min' => (int)($_POST['quota_min'] ?? 0),
                'require_contribution' => !empty($_POST['require_contribution']),
                'points' => array_map(fn($v) => str_replace(',', '.', (string)$v), (array)($_POST['points'] ?? [])),
                'ai' => !empty($_POST['ai']),
                'background' => $_POST['background'] ?? 'meer',
                'lang' => $_POST['lang'] ?? 'de',
                'note' => $_POST['note'] ?? '',
                'tags' => $_POST['tags'] ?? '',
            ];
            if ($act) { unset($in['type']); $act = activity_update($act, $in); admin_flash('Gespeichert.'); }
            else { $act = activity_create($in); admin_flash('Prüfung angelegt. Teile den Link oder QR-Code, damit die Klasse Fragen beisteuert.'); }
            header('Location: ' . wb_admin_url() . 'exam.php?key=' . $act['akey']);
            exit;
        }
        if ($act) {
            switch ($action) {
                case 'q_accept': exam_owner_question_update($act, $qid, ['status' => 'accepted']); admin_flash('Frage angenommen.'); break;
                case 'q_reject': exam_owner_question_update($act, $qid, ['status' => 'rejected']); admin_flash('Frage abgelehnt.'); break;
                case 'q_reopen': exam_owner_question_update($act, $qid, ['status' => 'submitted']); admin_flash('Frage wieder offen.'); break;
                case 'q_delete': exam_owner_question_delete($act, $qid); admin_flash('Frage gelöscht. Sie liegt im Papierkorb der Prüfung.'); break;
                case 'q_restore': exam_owner_question_restore($act, $qid); admin_flash('Frage wiederhergestellt.'); break;
                case 'q_up': exam_owner_move($act, $qid, -1); break;
                case 'q_down': exam_owner_move($act, $qid, 1); break;
                case 'q_save':
                    $in = admin_exam_question_input();
                    exam_owner_question_update($act, $qid, $in);
                    admin_flash('Frage gespeichert.');
                    header('Location: ' . $back('#q' . $qid));
                    exit;
                case 'q_add':
                    $in = admin_exam_question_input();
                    $q = exam_owner_question_create($act, $in);
                    admin_flash('Frage hinzugefügt und angenommen.');
                    header('Location: ' . $back('#q' . $q['id']));
                    exit;
                case 'close': activity_update($act, ['status' => 'closed']); admin_flash('Prüfung offline geschaltet.'); break;
                case 'open':
                    $wasArchived = $act['status'] === 'archived';
                    $a2 = activity_update($act, ['status' => 'open']);
                    admin_flash($wasArchived ? 'Aus dem Archiv geholt. Link: ' . $a2['url'] : 'Prüfung online geschaltet.');
                    break;
                case 'archive': activity_update($act, ['status' => 'archived']); admin_flash('Prüfung archiviert.'); break;
                case 'duplicate':
                    $copy = activity_duplicate($act);
                    admin_flash('Kopie mit den angenommenen Fragen angelegt.');
                    header('Location: ' . wb_admin_url() . 'exam.php?key=' . $copy['akey']);
                    exit;
                case 'delete':
                    activity_trash($act);
                    admin_flash('Prüfung «' . $act['name'] . '» in den Papierkorb gelegt (30 Tage wiederherstellbar).');
                    header('Location: ' . wb_admin_url() . 'exams.php');
                    exit;
                case 'restore':
                    $a2 = activity_restore($act);
                    admin_flash('Wiederhergestellt. Link: ' . $a2['url']);
                    break;
                case 'delete_forever':
                    if ($act['deleted_at'] === null) { $error = 'Nur Prüfungen im Papierkorb lassen sich endgültig löschen.'; break; }
                    activity_delete_forever($act);
                    admin_flash('Prüfung endgültig gelöscht.');
                    header('Location: ' . wb_admin_url() . 'exams.php?status=trash');
                    exit;
            }
            if (!$error) { header('Location: ' . $back($qid ? '#q' . $qid : '')); exit; }
        }
    } catch (InvalidArgumentException $ex) {
        $msg = $ex->getMessage();
        $error = str_starts_with($msg, 'exam_') ? t($msg, 'de', ['n' => $act['config']['quota_max'] ?? 0]) : $msg;
    }
}

$cfg = $act ? $act['config'] : exam_default_config();
$v = $act ? [
    'name' => $act['name'], 'slug' => $act['slug'], 'background' => $act['background'], 'lang' => $act['lang'],
    'note' => (string)$act['note'], 'tags' => $act['tags'],
] : ['name' => '', 'slug' => '', 'background' => 'meer', 'lang' => (string)wb_config('default_lang', 'de'), 'note' => '', 'tags' => []];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error && ($_POST['action'] ?? '') === 'save') {
    foreach (['name', 'slug', 'note'] as $k) $v[$k] = (string)($_POST[$k] ?? '');
    $v['tags'] = wb_parse_tags($_POST['tags'] ?? '');
}
$csrf = wb_csrf_token();
$post = fn(string $action, string $label, string $class = 'btn-ghost', string $confirm = '', string $title = '', int $qid = 0) =>
    '<form method="post"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '><input type="hidden" name="csrf" value="' . e($csrf) . '"><input type="hidden" name="action" value="' . e($action) . '">'
    . ($qid ? '<input type="hidden" name="qid" value="' . $qid . '">' : '') . '<button class="' . e($class) . '" type="submit"' . ($title !== '' ? ' title="' . e($title) . '"' : '') . '>' . e($label) . '</button></form>';
$typeLabel = fn(string $tp) => t('exam_type_' . $tp, 'de');
$statusLabel = ['submitted' => 'eingereicht', 'accepted' => 'angenommen', 'rejected' => 'abgelehnt'];
$fmt = fn($n) => rtrim(rtrim(number_format((float)$n, 1, '.', ''), '0'), '.');

/** Formular zum Anlegen oder Bearbeiten einer Frage. */
$questionForm = function (?array $q, string $action) use ($csrf, $typeLabel, $cfg): string {
    ob_start();
    $type = $q['type'] ?? 'single';
    $opts = $q['options'] ?? [];
    while (count($opts) < EXAM_MAX_OPTIONS) $opts[] = ['id' => '', 'text' => '', 'correct' => false];
    ?>
    <form method="post" class="form exam-qform">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="<?= e($action) ?>">
      <?php if ($q): ?><input type="hidden" name="qid" value="<?= (int)$q['id'] ?>"><?php endif; ?>
      <div class="row two">
        <div>
          <label>Fragetyp</label>
          <select name="q_type">
            <?php foreach (EXAM_QUESTION_TYPES as $tp): ?><option value="<?= $tp ?>" <?= $type === $tp ? 'selected' : '' ?>><?= e($typeLabel($tp)) ?> (Standard <?= e((string)$cfg['points'][$tp]) ?> P.)</option><?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Punkte für diese Frage</label>
          <input name="q_points" inputmode="decimal" value="<?= $q && $q['points_custom'] ? e((string)$q['points']) : '' ?>" placeholder="leer = Standard des Typs">
        </div>
      </div>
      <div class="row">
        <label>Frage</label>
        <textarea name="q_prompt" rows="2" maxlength="<?= EXAM_PROMPT_LENGTH ?>" required><?= e($q['prompt'] ?? '') ?></textarea>
      </div>
      <div class="row">
        <label>Antworten bzw. Aussagen (nicht für offene Fragen)</label>
        <p class="hint">Häkchen = richtig. Einfachauswahl: genau ein Häkchen. Mehrfachauswahl: mindestens eines. Richtig/Falsch: Häkchen bei den richtigen Aussagen. Leere Zeilen werden ignoriert.</p>
        <?php foreach ($opts as $i => $o): ?>
        <div class="exam-optrow">
          <input type="hidden" name="opt_id[<?= $i ?>]" value="<?= e((string)($o['id'] ?? '')) ?>">
          <label class="exam-optcheck"><input type="checkbox" name="opt_correct[]" value="<?= $i ?>" <?= !empty($o['correct']) ? 'checked' : '' ?>> richtig</label>
          <input name="opt_text[<?= $i ?>]" value="<?= e((string)$o['text']) ?>" maxlength="<?= EXAM_OPTION_LENGTH ?>" placeholder="Antwort oder Aussage <?= $i + 1 ?>">
        </div>
        <?php endforeach; ?>
      </div>
      <div class="row">
        <label>Lösungssatz (nur offene Fragen)</label>
        <textarea name="q_solution" rows="2" maxlength="<?= EXAM_SOLUTION_LENGTH ?>"><?= e((string)($q['solution'] ?? '')) ?></textarea>
      </div>
      <div class="actions"><button class="btn" type="submit"><?= $q ? 'Frage speichern' : 'Frage hinzufügen' ?></button></div>
    </form>
    <?php
    return (string)ob_get_clean();
};

admin_header($act ? $act['name'] : 'Neue Prüfung', 'exams');
?>
<?php if ($act && $act['deleted_at'] !== null): ?>
<div class="page-head">
  <div>
    <h1><?= e($act['name']) ?> <span class="pill status-trash">Papierkorb</span></h1>
    <p class="muted">Am <?= e(admin_time($act['deleted_at'])) ?> gelöscht. Nach dreissig Tagen wird sie mit dem Papierkorb endgültig entfernt.</p>
  </div>
  <div class="page-actions">
    <?= $post('restore', 'Wiederherstellen', 'btn') ?>
    <?= $post('delete_forever', 'Endgültig löschen', 'btn-danger', 'Prüfung jetzt endgültig löschen? Alle Fragen sind danach weg.') ?>
  </div>
</div>
<?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
<?php admin_footer(); exit; endif; ?>

<?php if ($act):
    $counts = exam_counts($act);
    $questions = exam_questions($act, $tab);
    $phaseLabel = ['collect' => 'Fragen sammeln', 'running' => 'Prüfung läuft', 'results' => 'Ergebnisse freigegeben'][$cfg['phase']];
?>
<div class="page-head">
  <div>
    <h1><?= e($act['name']) ?> <span class="pill status-<?= e($act['status']) ?>"><?= $act['status'] === 'closed' ? 'offline' : e(admin_status_label($act['status'])) ?></span></h1>
    <p class="muted"><a href="<?= e($act['url']) ?>" target="_blank" rel="noopener"><?= e($act['url']) ?></a> · Gemeinschaftsprüfung · Phase: <?= e($phaseLabel) ?> · <?= $counts['total'] ?> Fragen von <?= $counts['contributors'] ?> Personen</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= e($act['url']) ?>" target="_blank" rel="noopener">Prüfung öffnen</a>
    <button class="btn-ghost" type="button" data-copy="<?= e($act['url']) ?>">Link kopieren</button>
  </div>
</div>
<?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>

<div class="grid-2">
  <section class="card" id="qr">
    <h2>QR‑Code</h2>
    <div class="qr-box">
      <img src="/api/activities/<?= e($act['akey']) ?>/qr.svg" alt="QR-Code zur Prüfung" width="180" height="180">
      <div>
        <p class="muted">Enthält den Link <code><?= e($act['url']) ?></code>. Unter demselben Link reicht die Klasse jetzt Fragen ein und löst später die Prüfung.</p>
        <p><a class="btn-ghost" href="/api/activities/<?= e($act['akey']) ?>/qr.png?px=20" download="<?= e(preg_replace('/[^a-z0-9]+/i', '-', $act['name'])) ?>-qr.png">PNG laden</a>
           <a class="btn-ghost" href="/api/activities/<?= e($act['akey']) ?>/qr.svg" download="<?= e(preg_replace('/[^a-z0-9]+/i', '-', $act['name'])) ?>-qr.svg">SVG laden</a></p>
      </div>
    </div>
  </section>

  <section class="card">
    <h2>Steuerung</h2>
    <p class="muted">Angenommen: <strong><?= $counts['accepted'] ?></strong> <?= $counts['accepted'] === 1 ? 'Frage' : 'Fragen' ?> mit <?= $fmt($counts['points']) ?> <?= (float)$counts['points'] === 1.0 ? 'Punkt' : 'Punkten' ?>. Offen: <strong><?= $counts['submitted'] ?></strong>. Abgelehnt: <?= $counts['rejected'] ?>.</p>
    <div class="action-grid">
      <?php if ($act['status'] === 'open'): ?>
        <?= $post('close', 'Offline schalten', 'btn-ghost', '', 'Die Klasse sieht nur noch einen Hinweis') ?>
      <?php elseif ($act['status'] === 'closed'): ?>
        <?= $post('open', 'Online schalten', 'btn') ?>
      <?php else: ?>
        <?= $post('open', 'Aus dem Archiv holen', 'btn') ?>
      <?php endif; ?>
      <?= $post('duplicate', 'Duplizieren', 'btn-ghost', '', 'Einstellungen und angenommene Fragen kopieren') ?>
      <?php if ($act['status'] !== 'archived'): ?><?= $post('archive', 'Archivieren', 'btn-ghost', 'Prüfung archivieren? Sie ist danach für die Klasse nicht mehr erreichbar.') ?><?php endif; ?>
      <a class="btn-ghost" href="/api/activities/<?= e($act['akey']) ?>/results.md" target="_blank">Fragen (Markdown)</a>
      <?= $post('delete', 'Löschen', 'btn-danger', 'Prüfung in den Papierkorb legen? Sie wird nach 30 Tagen endgültig gelöscht.') ?>
    </div>
    <p class="hint">Das Starten der Prüfung, die automatische Bewertung, Rangliste und PDFs folgen in den nächsten Etappen. Bis dahin sammelt die Prüfung Fragen.</p>
  </section>
</div>

<section class="card" id="fragen">
  <h2>Fragen</h2>
  <p class="tags">
    <?php foreach (['all' => 'Alle (' . $counts['total'] . ')', 'submitted' => 'Eingereicht (' . $counts['submitted'] . ')', 'accepted' => 'Angenommen (' . $counts['accepted'] . ')', 'rejected' => 'Abgelehnt (' . $counts['rejected'] . ')', 'trash' => 'Papierkorb'] as $k => $label): ?>
      <a class="tag <?= $tab === $k ? 'active' : '' ?>" href="?key=<?= e($act['akey']) ?><?= $k !== 'all' ? '&amp;tab=' . $k : '' ?>#fragen"><?= e($label) ?></a>
    <?php endforeach; ?>
  </p>
  <?php if (!$questions): ?>
    <p class="muted"><?= $tab === 'all' ? 'Noch keine Fragen. Teile den Link mit der Klasse oder füge unten selbst Fragen hinzu.' : 'Keine Fragen in dieser Ansicht.' ?></p>
  <?php else: ?>
  <ol class="exam-admin-list">
    <?php $accIndex = 0; foreach ($questions as $q): if ($q['status'] === 'accepted' && !$q['deleted_at']) $accIndex++; ?>
    <li class="card exam-admin-q st-<?= e($q['status']) ?>" id="q<?= $q['id'] ?>">
      <div class="exam-admin-head">
        <span class="pill"><?= $q['status'] === 'accepted' && !$q['deleted_at'] ? 'Nr. ' . $accIndex . ' · ' : '' ?><?= e($typeLabel($q['type'])) ?> · <?= $fmt($q['points']) ?> P.<?= $q['points_custom'] ? ' (eigen)' : '' ?></span>
        <span class="pill exam-st-<?= e($q['status']) ?>"><?= e($statusLabel[$q['status']]) ?></span>
        <span class="muted">von <?= e($q['author']) ?><?= $q['edited'] ? ' · von dir bearbeitet' : '' ?> · <?= e((string)$q['created_at']) ?></span>
      </div>
      <p class="exam-admin-prompt"><?= nl2br(e($q['prompt'])) ?></p>
      <?php if ($q['type'] === 'open'): ?>
        <p><strong>Lösung:</strong> <?= e((string)$q['solution']) ?></p>
      <?php else: ?>
        <ul class="exam-admin-opts">
          <?php foreach ($q['options'] as $o): ?>
          <li class="<?= $o['correct'] ? 'ok' : 'no' ?>"><span class="exam-admin-mark"><?= $q['type'] === 'truefalse' ? ($o['correct'] ? 'richtig' : 'falsch') : ($o['correct'] ? '✓' : '–') ?></span> <?= e($o['text']) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <div class="action-grid">
        <?php if ($q['deleted_at']): ?>
          <?= $post('q_restore', 'Wiederherstellen', 'btn-ghost', '', '', $q['id']) ?>
        <?php else: ?>
          <?php if ($q['status'] !== 'accepted'): ?><?= $post('q_accept', 'Annehmen', 'btn', '', '', $q['id']) ?><?php endif; ?>
          <?php if ($q['status'] !== 'rejected'): ?><?= $post('q_reject', 'Ablehnen', 'btn-ghost', '', '', $q['id']) ?><?php endif; ?>
          <?php if ($q['status'] !== 'submitted' && !$q['by_owner']): ?><?= $post('q_reopen', 'Wieder offen', 'btn-ghost', '', 'Die Verfasserin kann die Frage dann wieder bearbeiten', $q['id']) ?><?php endif; ?>
          <?php if ($q['status'] === 'accepted'): ?><?= $post('q_up', '↑', 'btn-ghost', '', 'Nach oben', $q['id']) ?><?= $post('q_down', '↓', 'btn-ghost', '', 'Nach unten', $q['id']) ?><?php endif; ?>
          <?= $post('q_delete', 'Löschen', 'btn-ghost danger', 'Frage löschen?', '', $q['id']) ?>
        <?php endif; ?>
      </div>
      <?php if (!$q['deleted_at']): ?>
      <details class="exam-admin-edit"><summary>Bearbeiten</summary><?= $questionForm($q, 'q_save') ?></details>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ol>
  <?php endif; ?>
  <details class="exam-admin-edit" id="neu"<?= ($_POST['action'] ?? '') === 'q_add' && $error ? ' open' : '' ?>><summary>Eigene Frage hinzufügen</summary><?= $questionForm(null, 'q_add') ?></details>
</section>
<?php else: ?>
<div class="page-head"><h1>Neue Prüfung</h1></div>
<?php endif; ?>

<section class="card">
  <h2><?= $act ? 'Einstellungen' : 'Prüfung' ?></h2>
  <?php if ($error && !$act): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
  <form method="post" class="form">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="save">
    <div class="row">
      <label for="name">Titel der Prüfung</label>
      <input id="name" name="name" value="<?= e($v['name']) ?>" maxlength="200" required placeholder="Gemeinsame Prüfung: Die Römer" <?= $act ? '' : 'autofocus' ?>>
    </div>
    <div class="row">
      <label for="slug">Kurzwort für den Link</label>
      <div class="slug-row"><span class="muted"><?= e(wb_base_url()) ?>/</span><input id="slug" name="slug" value="<?= e((string)$v['slug']) ?>" maxlength="32" pattern="[a-z0-9][a-z0-9-]{1,30}[a-z0-9]" placeholder="leer lassen für ein automatisches Wort"></div>
      <p class="hint">Boards und Aktivitäten teilen sich die Kurzwörter.</p>
    </div>
    <div class="row two">
      <div>
        <label for="quota_max">Höchstens Fragen pro Person</label>
        <input id="quota_max" name="quota_max" type="number" min="0" max="<?= EXAM_MAX_QUOTA ?>" value="<?= (int)$cfg['quota_max'] ?>">
        <p class="hint">0 = die Klasse schreibt keine Fragen; du füllst die Prüfung selbst.</p>
      </div>
      <div>
        <label for="quota_min">Mindestens (0 = keine Vorgabe)</label>
        <input id="quota_min" name="quota_min" type="number" min="0" max="<?= EXAM_MAX_QUOTA ?>" value="<?= (int)$cfg['quota_min'] ?>">
      </div>
    </div>
    <div class="row">
      <span class="label">Standardpunkte pro Fragetyp</span>
      <div class="exam-points-row">
        <?php foreach (EXAM_QUESTION_TYPES as $tp): ?>
        <label><?= e($typeLabel($tp)) ?> <input name="points[<?= $tp ?>]" inputmode="decimal" value="<?= e((string)$cfg['points'][$tp]) ?>" size="4"></label>
        <?php endforeach; ?>
      </div>
      <p class="hint">Für einzelne Fragen lassen sich die Punkte überschreiben. Halbe Punkte sind möglich.</p>
    </div>
    <div class="row">
      <div class="checks">
        <label><input type="checkbox" name="require_contribution" <?= $cfg['require_contribution'] ? 'checked' : '' ?>> Nur wer Fragen beigesteuert hat, darf die Prüfung lösen (gilt ab dem Start)</label>
        <label><input type="checkbox" name="ai" <?= $cfg['ai'] ? 'checked' : '' ?>> KI-Funktionen erlaubt (Fragen und Antworten gehen ohne Namen an den KI-Anbieter)</label>
      </div>
    </div>
    <div class="row two">
      <div>
        <label for="lang">Sprache</label>
        <select id="lang" name="lang">
          <option value="de" <?= $v['lang'] === 'de' ? 'selected' : '' ?>>Deutsch</option>
          <option value="en" <?= $v['lang'] === 'en' ? 'selected' : '' ?>>English</option>
        </select>
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
      <input id="tags" name="tags" value="<?= e(implode(', ', $v['tags'])) ?>" placeholder="prüfung, 3b, geschichte">
    </div>
    <div class="actions">
      <button class="btn" type="submit"><?= $act ? 'Speichern' : 'Prüfung anlegen' ?></button>
      <?php if (!$act): ?><a class="btn-ghost" href="<?= e(wb_admin_url()) ?>exams.php">Abbrechen</a><?php endif; ?>
    </div>
  </form>
</section>
<?php admin_footer();
