<?php
declare(strict_types=1);
// Eine Gemeinschaftsprüfung anlegen, einstellen, Fragen sichten (G1), durchführen und auswerten (G2),
// mit KI korrigieren, Fragen streichen und prüfen (G3).
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
    $sh = (string)($_POST['q_shuffle'] ?? '');
    $in['shuffle_options'] = $sh === '' ? null : $sh === '1';
    return $in;
}

/** Kurze Beschreibung der geltenden Regeln für die Steuerung. */
function admin_exam_rules_text(array $c): string {
    $parts = [];
    $parts[] = ['auto' => 'Korrektur automatisch', 'review' => 'Korrektur mit Durchsicht', 'manual' => 'Korrektur von Hand'][$c['grading']];
    $parts[] = $c['mc_rule'] === 'kprim' ? 'Mehrfachauswahl und Richtig/Falsch nach Kprim' : 'Mehrfachauswahl und Richtig/Falsch anteilig';
    $parts[] = 'Lösungen ' . ['immediate' => 'sofort nach der Abgabe', 'results' => 'mit den Ergebnissen', 'release' => 'nach eigener Freigabe' . ($c['solutions_released'] ? ' (freigegeben)' : '')][$c['solutions']];
    $parts[] = $c['time_limit']['minutes'] > 0 ? $c['time_limit']['minutes'] . ' Minuten ' . ($c['time_limit']['per_person'] ? 'pro Person' : 'für alle gemeinsam') : 'ohne Zeitbegrenzung';
    if ($c['ranking']) $parts[] = 'mit Rangliste';
    if ($c['allow_retry']) $parts[] = 'weitere Versuche nach den Ergebnissen erlaubt';
    $txt = implode(' · ', $parts) . '.';
    $txt .= ' Lernende sehen ihre Punkte nur, wenn ihr Versuch vollständig automatisch bewertet ist; sonst erst nach «Korrektur abschliessen»' . ($c['review_done'] ? ' (abgeschlossen)' : '') . '.';
    $txt .= match ($c['grading']) {
        'manual' => ' Offene Fragen bewertest du selbst, pro Versuch oder pro Frage über alle Versuche.',
        'review' => ' Offene Fragen bewertet die KI nach jeder Abgabe als Vorschlag' . ($c['ai'] ? '' : ' (für diese Prüfung ausgeschaltet)') . '; alle Punkte kannst du überschreiben.',
        default => ' Offene Fragen bewertet die KI nach jeder Abgabe' . ($c['ai'] ? '' : ' (für diese Prüfung ausgeschaltet)') . '; ihre Punkte gelten sofort, deine Punkte gehen vor.',
    };
    return $txt;
}

$error = null;
$tab = in_array($_GET['tab'] ?? '', ['submitted', 'accepted', 'struck', 'rejected', 'trash'], true) ? $_GET['tab'] : 'all';
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
                'mc_rule' => (string)($_POST['mc_rule'] ?? 'kprim'),
                'grading' => (string)($_POST['grading'] ?? 'auto'),
                'own_questions' => (string)($_POST['own_questions'] ?? 'count'),
                'display' => (string)($_POST['display'] ?? 'points'),
                'grade' => ['on' => !empty($_POST['grade_on']), 'max' => trim(str_replace(',', '.', (string)($_POST['grade_max'] ?? ''))), 'round' => (string)($_POST['grade_round'] ?? 'half')],
                'ranking' => !empty($_POST['ranking']),
                'solutions' => (string)($_POST['solutions'] ?? 'results'),
                'shuffle_questions' => !empty($_POST['shuffle_questions']),
                'shuffle_options' => !empty($_POST['shuffle_options']),
                'time_limit' => ['minutes' => (int)($_POST['tl_minutes'] ?? 0), 'per_person' => ($_POST['tl_mode'] ?? 'person') === 'person'],
                'allow_retry' => !empty($_POST['allow_retry']),
                'student_pdf' => (string)($_POST['student_pdf'] ?? 'off'),
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
                case 'q_strike': exam_owner_question_update($act, $qid, ['status' => 'struck']); admin_flash('Frage gestrichen. Sie zählt in keinem Versuch mehr; alle Versuche sind neu gerechnet.'); break;
                case 'q_unstrike': exam_owner_question_update($act, $qid, ['status' => 'accepted']); admin_flash('Frage wieder aufgenommen. Alle Versuche sind neu gerechnet.'); break;
                case 'q_check':
                    $r = exam_ai_check($act, 'all');
                    admin_flash($r['checked'] . ' Fragen geprüft, ' . ($r['flagged'] === 1 ? 'eine mit Hinweisen' : $r['flagged'] . ' mit Hinweisen') . ' · ' . $r['model'] . '. Geändert wurde nichts.');
                    header('Location: ' . $back('#fragen'));
                    exit;
                case 'ai_grade':
                    $opt = ['redo' => !empty($_POST['redo'])];
                    if (!empty($_POST['attempt'])) $opt['attempt_id'] = (int)$_POST['attempt'];
                    if (!empty($_POST['question'])) $opt['question_id'] = (int)$_POST['question'];
                    $r = exam_ai_grade($act, $opt, true);
                    if ($r['state'] === 'busy') admin_flash('Die KI bewertet gerade schon. Lade die Seite in einem Moment neu.');
                    elseif ($r['state'] === 'none') admin_flash('Keine offenen Antworten ohne Bewertung.');
                    else admin_flash($r['graded'] . ' Antworten bewertet' . ($r['failed'] ? ', ' . $r['failed'] . ' ohne Ergebnis' : '') . ($r['skipped'] ? ', ' . $r['skipped'] . ' für den nächsten Lauf' : '') . ($r['model'] ? ' · ' . $r['model'] : '') . '.' . (!empty($r['error']) ? ' ' . $r['error'] : ''));
                    $to = !empty($_POST['attempt']) ? '&attempt=' . (int)$_POST['attempt'] : (!empty($_POST['question']) ? '&grade_q=' . (int)$_POST['question'] : '#ergebnisse');
                    header('Location: ' . wb_admin_url() . 'exam.php?key=' . $act['akey'] . $to);
                    exit;
                case 'grade_question':
                    // Eine Frage über alle Versuche: zuerst alles prüfen, dann speichern.
                    $gq = (int)($_POST['question'] ?? 0);
                    $mq = exam_question_map($act)[$gq] ?? null;
                    if (!$mq) throw new InvalidArgumentException('exam_err_not_found');
                    $vals = [];
                    foreach ((array)($_POST['ga'] ?? []) as $aid => $gp) {
                        $gp = trim(str_replace(',', '.', (string)$gp));
                        if ($gp !== '') {
                            $v = exam_points_value($gp);
                            if ($v === null || $v > exam_q_points($act, $mq)) throw new InvalidArgumentException('exam_err_grade_points');
                        }
                        $vals[(int)$aid] = $gp === '' ? null : $gp;
                    }
                    foreach ($vals as $aid => $gp) exam_attempt_grade($act, $aid, $gq, $gp);
                    admin_flash('Bewertung gespeichert.');
                    header('Location: ' . wb_admin_url() . 'exam.php?key=' . $act['akey'] . '&grade_q=' . $gq);
                    exit;
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
                case 'phase_running':
                case 'phase_results':
                case 'phase_collect':
                    [$a2, $warnings] = exam_set_phase($act, substr($action, 6));
                    admin_flash(['phase_running' => $act['config']['phase'] === 'results' ? 'Prüfung wieder geöffnet.' : 'Prüfung gestartet. Unter dem Link lösen jetzt alle die Prüfung.',
                        'phase_results' => $a2['revealed'] ? 'Ergebnisse freigegeben und Rangliste gezeigt.' : 'Ergebnisse freigegeben.',
                        'phase_collect' => 'Zurück in der Sammelphase.'][$action] . ($warnings ? ' ' . implode(' ', $warnings) : ''));
                    break;
                case 'ranking_show': exam_set_ranking_shown($act, true); admin_flash('Rangliste für alle sichtbar.'); break;
                case 'ranking_hide': exam_set_ranking_shown($act, false); admin_flash('Rangliste verborgen.'); break;
                case 'solutions_release': exam_set_flag($act, 'solutions_released', true); admin_flash('Lösungen freigegeben.'); break;
                case 'solutions_lock': exam_set_flag($act, 'solutions_released', false); admin_flash('Lösungen wieder gesperrt.'); break;
                case 'review_done': exam_set_flag($act, 'review_done', true); admin_flash('Korrektur abgeschlossen. Die Lernenden sehen ihre Punkte.'); break;
                case 'review_open': exam_set_flag($act, 'review_done', false); admin_flash('Korrektur wieder offen. Punkte, die eine Freigabe brauchen, sind wieder verborgen.'); break;
                case 'shuffle_all':
                    db_exec('UPDATE exam_questions SET shuffle_options = NULL WHERE activity_id = ?', [$act['id']]);
                    activity_touch($act['id']);
                    admin_flash('Alle Fragen mischen die Antworten jetzt nach der Einstellung der Prüfung.');
                    break;
                case 'grade':
                    $aid = (int)($_POST['attempt'] ?? 0);
                    // Zuerst alle Felder prüfen, damit ein ungültiger Wert nichts halb speichert.
                    $maxPts = [];
                    foreach (exam_question_map($act) as $mq) $maxPts[(int)$mq['id']] = exam_q_points($act, $mq);
                    foreach ((array)($_POST['g'] ?? []) as $gq => $gp) {
                        $gp = trim(str_replace(',', '.', (string)$gp));
                        if ($gp === '') continue;
                        $v = exam_points_value($gp);
                        if ($v === null || $v > ($maxPts[(int)$gq] ?? 0)) throw new InvalidArgumentException('exam_err_grade_points');
                    }
                    foreach ((array)($_POST['g'] ?? []) as $gq => $gp) {
                        $gp = trim((string)$gp);
                        exam_attempt_grade($act, $aid, (int)$gq, $gp === '' ? null : $gp);
                    }
                    admin_flash('Bewertung gespeichert.');
                    header('Location: ' . wb_admin_url() . 'exam.php?key=' . $act['akey'] . '&attempt=' . $aid);
                    exit;
                case 'attempt_delete':
                    exam_attempt_delete($act, (int)($_POST['attempt'] ?? 0));
                    admin_flash('Versuch gelöscht. Die Person kann neu beginnen.');
                    header('Location: ' . wb_admin_url() . 'exam.php?key=' . $act['akey'] . '#ergebnisse');
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
        $error = preg_match('/^(exam|ai)_[a-z_]+$/', $msg) ? t($msg, 'de', ['n' => $act['config']['quota_max'] ?? 0]) : $msg;
    } catch (AiException $ex) {
        $error = ai_error_message($ex, 'de');
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
$statusLabel = ['submitted' => 'eingereicht', 'accepted' => 'angenommen', 'rejected' => 'abgelehnt', 'struck' => 'gestrichen'];
$aiStatus = $act ? ai_exam_status($act) : ['available' => false, 'reason' => null];
$aiReasonText = fn() => $aiStatus['reason'] ? t('ai_unavailable_' . $aiStatus['reason'], 'de') : '';
$checkKind = ['solution' => 'Lösung fraglich', 'duplicate' => 'doppelt', 'unclear' => 'unklar'];
$aiForm = function (string $label, array $hidden = [], string $class = 'btn-ghost', string $confirm = '') use ($csrf): string {
    $h = '';
    foreach ($hidden as $k => $v) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e((string)$v) . '">';
    return '<form method="post" data-busy="Die KI arbeitet …"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '><input type="hidden" name="csrf" value="' . e($csrf) . '"><input type="hidden" name="action" value="ai_grade">' . $h . '<button class="' . e($class) . '" type="submit">' . e($label) . '</button></form>';
};
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
        <label>Antworten mischen (nicht für offene Fragen)</label>
        <?php $sh = $q['shuffle_options'] ?? null; ?>
        <select name="q_shuffle">
          <option value="" <?= $sh === null ? 'selected' : '' ?>>wie in den Einstellungen der Prüfung (<?= $cfg['shuffle_options'] ? 'mischen' : 'nicht mischen' ?>)</option>
          <option value="1" <?= $sh === true ? 'selected' : '' ?>>mischen</option>
          <option value="0" <?= $sh === false ? 'selected' : '' ?>>nicht mischen (Reihenfolge bleibt)</option>
        </select>
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

<?php if ($act && isset($_GET['attempt'])):
    $row = exam_attempt_by_id($act, (int)$_GET['attempt']);
    if ($row) $row = exam_attempt_check_deadline($act, $row);
    $d = $row ? exam_attempt_detail($act, $row) : null;
?>
<div class="page-head">
  <div>
    <h1><?= $d ? e($d['name']) . ($d['attempt_no'] > 1 ? ' · Übungsversuch ' . $d['attempt_no'] : '') : 'Versuch nicht gefunden' ?></h1>
    <p class="muted"><a href="?key=<?= e($act['akey']) ?>#ergebnisse">← <?= e($act['name']) ?></a><?php if ($d): ?> · begonnen <?= e((string)$d['started_at']) ?><?= $d['submitted_at'] ? ' · abgegeben ' . e((string)$d['submitted_at']) : ' · löst noch' ?><?php endif; ?></p>
  </div>
  <?php if ($d): ?><div class="page-actions"><a class="btn-ghost" href="/a/<?= e($act['akey']) ?>/print?doc=attempt&amp;attempt=<?= $d['id'] ?>" target="_blank" rel="noopener">PDF mit Lösungen</a><a class="btn-ghost" href="/a/<?= e($act['akey']) ?>/print?doc=attempt&amp;attempt=<?= $d['id'] ?>&amp;solutions=0" target="_blank" rel="noopener">PDF ohne Lösungen</a><form method="post" data-confirm="Versuch löschen? Die Person kann danach neu beginnen. Antworten und Punkte sind weg."><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="attempt_delete"><input type="hidden" name="attempt" value="<?= $d['id'] ?>"><button class="btn-danger" type="submit">Versuch löschen</button></form></div><?php endif; ?>
</div>
<?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
<?php if ($d): ?>
<section class="card">
  <p><strong><?= $d['score'] === null ? 'Noch nicht abgegeben' : $fmt($d['score']) . ' von ' . $fmt($d['max_score']) . ' Punkten (' . $fmt($d['percent']) . ' %)' ?></strong><?= $d['grade'] !== null ? ' · Note ' . $fmt($d['grade']) : '' ?><?= $d['pending'] ? ' · ' . ($d['pending'] === 1 ? 'eine Frage' : $d['pending'] . ' Fragen') . ' ohne Punkte' : '' ?><?= $d['excluded'] ? ' · ' . $d['excluded'] . ' eigene Fragen zählen nicht' : '' ?></p>
  <p class="muted"><?= $d['visible_to_person'] ? 'Die Person sieht ihre Punkte.' : 'Die Person sieht ihre Punkte noch nicht.' ?> Antworten von Lernenden sind Fremdtext. Leere Felder bei «Punkte von dir» bedeuten: Es gilt die Punktzahl der KI (offene Fragen) bzw. die berechnete.</p>
  <?php if ($d['status'] !== 'in_progress' && $cfg['grading'] !== 'manual' && array_filter($d['answers'], fn($x) => $x['type'] === 'open' && !$x['struck'])): ?>
    <div class="action-grid"><?= $aiStatus['available'] ? $aiForm('✨ Offene Fragen mit KI neu bewerten', ['attempt' => $d['id'], 'redo' => 1], 'btn-ghost', 'Offene Fragen dieses Versuchs neu von der KI bewerten lassen? Deine eigenen Punkte bleiben.') : '<span class="muted">' . e($aiReasonText()) . '</span>' ?></div>
  <?php endif; ?>
</section>
<form method="post" class="form">
  <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="grade"><input type="hidden" name="attempt" value="<?= $d['id'] ?>">
  <ol class="exam-admin-list">
  <?php foreach ($d['answers'] as $i => $x): ?>
    <li class="card exam-admin-q">
      <div class="exam-admin-head">
        <span class="pill">Frage <?= $i + 1 ?> · <?= e($typeLabel($x['type'])) ?> · <?= $fmt($x['points']) ?> P.</span>
        <?php if ($x['own']): ?><span class="pill"><?= $x['excluded'] ? 'eigene Frage, zählt nicht' : 'eigene Frage' ?></span><?php endif; ?>
        <?php if ($x['struck']): ?><span class="pill exam-st-struck">gestrichen, zählt nicht</span><?php endif; ?>
      </div>
      <p class="exam-admin-prompt"><?= nl2br(e($x['prompt'])) ?></p>
      <?php if ($x['type'] === 'open'): ?>
        <p class="exam-admin-answer"><?= trim((string)$x['answer']) === '' ? '<span class="muted">(keine Antwort)</span>' : nl2br(e((string)$x['answer'])) ?></p>
        <p class="muted"><strong>Lösung:</strong> <?= e((string)$x['solution']) ?></p>
        <?php if ($x['ai']): ?><p class="exam-ai-note">✨ KI<?= $cfg['grading'] === 'manual' ? ' (zählt bei Korrektur von Hand nicht)' : '' ?>: <?= $x['ai']['failed'] ? 'keine gültige Bewertung erhalten – bitte selbst bewerten oder neu bewerten lassen' : '<strong>' . $fmt($x['ai']['points']) . ' P.</strong> – ' . e((string)$x['ai']['reason']) ?><?= $x['ai']['model'] ? ' <span class="muted">(' . e((string)$x['ai']['model']) . ')</span>' : '' ?></p><?php endif; ?>
      <?php else: ?>
        <ul class="exam-admin-opts">
          <?php foreach ($x['options'] as $o):
            $mark = $x['type'] === 'truefalse' ? ($o['chosen'] === null ? '–' : ($o['chosen'] ? 'richtig' : 'falsch')) : ($o['chosen'] ? '☑' : '☐');
            $ok = $x['type'] === 'truefalse' ? $o['chosen'] === $o['correct'] : ($x['type'] === 'single' ? (!$o['chosen'] || $o['correct']) : $o['chosen'] === $o['correct']); ?>
          <li class="<?= $ok ? 'ok' : 'no' ?>"><span class="exam-admin-mark"><?= e($mark) ?></span> <?= e($o['text']) ?> <span class="muted">(<?= $x['type'] === 'truefalse' ? ($o['correct'] ? 'richtig' : 'falsch') : ($o['correct'] ? 'richtig' : 'falsch') ?>)</span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <p class="exam-grade-row">
        <span><?= $x['type'] === 'open' ? 'KI' : 'Berechnet' ?>: <strong><?= $x['type'] === 'open' ? ($x['ai'] && !$x['ai']['failed'] ? $fmt($x['ai']['points']) : '–') : ($x['auto'] === null ? '–' : $fmt($x['auto'])) ?></strong></span>
        <label>Punkte von dir <input name="g[<?= $x['question_id'] ?>]" inputmode="decimal" size="4" value="<?= $x['teacher'] === null ? '' : e($fmt($x['teacher'])) ?>" placeholder="<?= $x['type'] === 'open' ? '0 bis ' . $fmt($x['points']) : '' ?>" <?= $d['status'] === 'in_progress' || $x['struck'] ? 'disabled' : '' ?>></label>
        <span>Gilt: <strong><?= $x['struck'] ? 'gestrichen' : ($x['final'] === null ? 'offen' : $fmt($x['final'])) ?></strong></span>
      </p>
    </li>
  <?php endforeach; ?>
  </ol>
  <?php if ($d['status'] !== 'in_progress'): ?><div class="actions"><button class="btn" type="submit">Bewertung speichern</button></div><?php endif; ?>
</form>
<?php endif; ?>
<?php admin_footer(); exit; endif; ?>

<?php if ($act && isset($_GET['grade_q'])):
    $gq = (int)$_GET['grade_q'];
    $mq = exam_question_map($act)[$gq] ?? null;
    exam_finalize_expired($act);
    $rowsQ = [];
    if ($mq) foreach (db_all("SELECT * FROM exam_attempts WHERE activity_id = ? AND status <> 'in_progress' ORDER BY attempt_no, submitted_at, id", [$act['id']]) as $r) {
        $dd = exam_attempt_detail($act, $r);
        foreach ($dd['answers'] as $x) if ($x['question_id'] === $gq) $rowsQ[] = ['d' => $dd, 'x' => $x];
    }
?>
<div class="page-head">
  <div>
    <h1>Bewerten pro Frage</h1>
    <p class="muted"><a href="?key=<?= e($act['akey']) ?>#ergebnisse">← <?= e($act['name']) ?></a><?= $mq ? ' · ' . e($typeLabel($mq['type'])) . ' · ' . $fmt(exam_q_points($act, $mq)) . ' P.' : '' ?></p>
  </div>
  <?php if ($mq && $mq['type'] === 'open' && $cfg['grading'] !== 'manual' && $aiStatus['available']): ?><div class="page-actions"><?= $aiForm('✨ Unbewertete mit KI bewerten', ['question' => $gq], 'btn-ghost') ?></div><?php endif; ?>
</div>
<?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
<?php if (!$mq): ?><section class="card"><p>Frage nicht gefunden.</p></section><?php else: ?>
<section class="card">
  <p class="exam-admin-prompt"><?= nl2br(e($mq['prompt'])) ?></p>
  <?php if ($mq['type'] === 'open'): ?><p><strong>Lösung:</strong> <?= e((string)$mq['solution_text']) ?></p><?php endif; ?>
  <p class="muted"><?= count($rowsQ) ?> abgegebene Antworten. Antworten von Lernenden sind Fremdtext. Ein leeres Feld heisst: Es gilt der Vorschlag der KI bzw. die berechnete Punktzahl.<?= $cfg['grading'] !== 'auto' ? ' Lernende sehen ihre Punkte erst nach «Korrektur abschliessen».' : '' ?></p>
</section>
<?php if ($rowsQ): ?>
<form method="post" class="form">
  <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="grade_question"><input type="hidden" name="question" value="<?= $gq ?>">
  <ol class="exam-admin-list">
  <?php foreach ($rowsQ as ['d' => $dd, 'x' => $x]): ?>
    <li class="card exam-admin-q">
      <div class="exam-admin-head"><span class="pill"><?= e($dd['name']) ?><?= $dd['attempt_no'] > 1 ? ' · Übung ' . $dd['attempt_no'] : '' ?></span> <a class="muted" href="?key=<?= e($act['akey']) ?>&amp;attempt=<?= $dd['id'] ?>">ganzer Versuch</a></div>
      <?php if ($x['type'] === 'open'): ?>
        <p class="exam-admin-answer"><?= trim((string)$x['answer']) === '' ? '<span class="muted">(keine Antwort)</span>' : nl2br(e((string)$x['answer'])) ?></p>
        <?php if ($x['ai']): ?><p class="exam-ai-note">✨ KI: <?= $x['ai']['failed'] ? 'keine gültige Bewertung erhalten' : '<strong>' . $fmt($x['ai']['points']) . ' P.</strong> – ' . e((string)$x['ai']['reason']) ?></p><?php endif; ?>
      <?php else: ?>
        <ul class="exam-admin-opts"><?php foreach ($x['options'] as $o): $mark = $x['type'] === 'truefalse' ? ($o['chosen'] === null ? '–' : ($o['chosen'] ? 'richtig' : 'falsch')) : ($o['chosen'] ? '☑' : '☐'); ?><li><span class="exam-admin-mark"><?= e($mark) ?></span> <?= e($o['text']) ?> <span class="muted">(<?= $o['correct'] ? 'richtig' : 'falsch' ?>)</span></li><?php endforeach; ?></ul>
      <?php endif; ?>
      <p class="exam-grade-row">
        <span><?= $x['type'] === 'open' ? 'KI' : 'Berechnet' ?>: <strong><?= $x['type'] === 'open' ? ($x['ai'] && !$x['ai']['failed'] ? $fmt($x['ai']['points']) : '–') : ($x['auto'] === null ? '–' : $fmt($x['auto'])) ?></strong></span>
        <label>Punkte von dir <input name="ga[<?= $dd['id'] ?>]" inputmode="decimal" size="4" value="<?= $x['teacher'] === null ? '' : e($fmt($x['teacher'])) ?>" placeholder="0 bis <?= $fmt($x['points']) ?>" <?= $x['struck'] ? 'disabled' : '' ?>></label>
        <span>Gilt: <strong><?= $x['struck'] ? 'gestrichen' : ($x['final'] === null ? 'offen' : $fmt($x['final'])) ?></strong></span>
      </p>
    </li>
  <?php endforeach; ?>
  </ol>
  <div class="actions"><button class="btn" type="submit">Bewertung speichern</button></div>
</form>
<?php endif; endif; ?>
<?php admin_footer(); exit; endif; ?>

<?php if ($act):
    $counts = exam_counts($act);
    $attemptCounts = exam_attempt_counts($act);
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
    <h3 class="exam-admin-h3">Ablauf: <?= e($phaseLabel) ?></h3>
    <div class="action-grid">
      <?php if ($cfg['phase'] === 'collect'): ?>
        <?= $post('phase_running', 'Prüfung starten', 'btn', 'Prüfung jetzt starten? Unter dem Link lösen dann alle die Prüfung; noch eingereichte Fragen kommen nicht hinein.' . ($cfg['time_limit']['minutes'] > 0 && !$cfg['time_limit']['per_person'] ? ' Das gemeinsame Ende ist in ' . $cfg['time_limit']['minutes'] . ' Minuten.' : '')) ?>
      <?php elseif ($cfg['phase'] === 'running'): ?>
        <?= $post('phase_results', $cfg['ranking'] ? 'Rangliste zeigen' : 'Ergebnisse freigeben', 'btn', 'Prüfung beenden und Ergebnisse freigeben? Laufende Versuche werden abgegeben.' . ($cfg['ranking'] ? ' Die Rangliste wird allen gezeigt.' : '')) ?>
        <?php if (!$attemptCounts['started']): ?><?= $post('phase_collect', 'Zurück zum Sammeln', 'btn-ghost') ?><?php endif; ?>
      <?php else: ?>
        <?= $post('phase_running', 'Prüfung wieder öffnen', 'btn-ghost', 'Prüfung wieder öffnen? Wer noch nicht abgegeben hat, kann wieder lösen.') ?>
        <?php if ($cfg['ranking']): ?><?= $act['revealed'] ? $post('ranking_hide', 'Rangliste verbergen', 'btn-ghost') : $post('ranking_show', 'Rangliste zeigen', 'btn') ?><?php endif; ?>
      <?php endif; ?>
      <?php if ($cfg['phase'] !== 'collect' && $cfg['solutions'] === 'release'): ?>
        <?= $cfg['solutions_released'] ? $post('solutions_lock', 'Lösungen sperren', 'btn-ghost') : $post('solutions_release', 'Lösungen freigeben', 'btn-ghost', 'Lösungen für alle freigeben, die abgegeben haben?') ?>
      <?php endif; ?>
      <?php if ($cfg['phase'] !== 'collect'): ?>
        <?= $cfg['review_done'] ? $post('review_open', 'Korrektur wieder öffnen', 'btn-ghost') : $post('review_done', 'Korrektur abschliessen', 'btn-ghost', 'Korrektur abschliessen? Die Lernenden sehen danach ihre Punkte, auch bei von Hand bewerteten Fragen.') ?>
      <?php endif; ?>
    </div>
    <p class="hint"><?= e(admin_exam_rules_text($cfg)) ?></p>
  </section>
</div>

<section class="card" id="ausgaben">
  <h2>Ausgaben (PDF und CSV)</h2>
  <p class="muted">Jede Ausgabe öffnet eine Druckseite in einem neuen Tab; dort machst du mit «Drucken / als PDF sichern» das PDF. Fantasienamen erscheinen nur in diesen Ausgaben für dich. Gestrichene Fragen stehen als gestrichen darin, das Aufgabenblatt lässt sie weg.</p>
  <?php $pr = '/a/' . e($act['akey']) . '/print?doc='; ?>
  <div class="action-grid">
    <a class="btn-ghost" href="<?= $pr ?>sheet" target="_blank" rel="noopener">Aufgabenblatt</a>
    <a class="btn-ghost" href="<?= $pr ?>key" target="_blank" rel="noopener">Lösungsschlüssel</a>
    <?php if ($cfg['phase'] !== 'collect'): ?>
    <a class="btn-ghost" href="<?= $pr ?>all" target="_blank" rel="noopener">Sammelausgabe mit Lösungen</a>
    <a class="btn-ghost" href="<?= $pr ?>all&amp;solutions=0" target="_blank" rel="noopener">Sammelausgabe ohne Lösungen</a>
    <a class="btn-ghost" href="<?= $pr ?>overview" target="_blank" rel="noopener">Ergebnisübersicht</a>
    <a class="btn-ghost" href="/api/activities/<?= e($act['akey']) ?>/results.csv">Ergebnisübersicht (CSV)</a>
    <a class="btn-ghost" href="<?= $pr ?>stats" target="_blank" rel="noopener">Fragenauswertung</a>
    <a class="btn-ghost" href="/api/activities/<?= e($act['akey']) ?>/stats.csv">Fragenauswertung (CSV)</a>
    <?php endif; ?>
  </div>
  <p class="hint">Einzelversuche druckst du über «Antworten» in den Ergebnissen. PDF für Lernende: <?= e(['off' => 'nicht erlaubt', 'without' => 'ohne Lösungen', 'with' => 'mit Lösungen, sobald sie sichtbar sind'][$cfg['student_pdf']]) ?> (Einstellungen).</p>
</section>

<?php if ($cfg['phase'] !== 'collect'): $attempts = exam_attempts($act, true); $stats = exam_question_stats($act); $firsts = array_values(array_filter($attempts, fn($t) => $t['attempt_no'] === 1)); $retries = count($attempts) - count($firsts); ?>
<section class="card" id="ergebnisse">
  <h2>Ergebnisse</h2>
  <p class="muted"><?= $attemptCounts['started'] ?> begonnen, <?= $attemptCounts['submitted'] ?> abgegeben<?= $attemptCounts['pending'] ? ', davon ' . $attemptCounts['pending'] . ' mit offenen Fragen ohne Punkte' : '' ?><?= $retries ? ' · ' . $retries . ' Übungsversuche' : '' ?>. Es zählt der erste Versuch.</p>
  <?php $aiPendingN = $cfg['grading'] !== 'manual' ? exam_ai_pending_count($act) : 0; if ($aiPendingN || ($cfg['grading'] !== 'manual' && array_filter($stats, fn($s) => $s['type'] === 'open'))): ?>
  <div class="action-grid">
    <?php if ($aiStatus['available']): ?>
      <?= $aiPendingN ? $aiForm('✨ Offene Fragen bewerten (' . $aiPendingN . ')', [], 'btn') : '' ?>
      <?= $aiForm('✨ Alle offenen Fragen neu bewerten', ['redo' => 1], 'btn-ghost', 'Alle offenen Antworten neu von der KI bewerten lassen? Deine eigenen Punkte bleiben.') ?>
    <?php else: ?><span class="muted">KI-Korrektur nicht verfügbar: <?= e($aiReasonText()) ?></span><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php if (!$firsts): ?>
    <p class="muted">Noch keine Versuche.</p>
  <?php else: ?>
  <div class="table-wrap">
  <table class="results exam-results">
    <thead><tr><th>Fantasiename</th><th>Status</th><th>Punkte</th><th>Prozent</th><?php if ($cfg['grade']['on']): ?><th>Note</th><?php endif; ?><th>Sieht Punkte</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($firsts as $t): ?>
      <tr>
        <td><?= e($t['name']) ?></td>
        <td><?= e(['in_progress' => 'löst noch', 'submitted' => 'offene Fragen ohne Punkte (' . (int)$t['pending'] . ')', 'graded' => 'bewertet'][$t['status']]) ?></td>
        <td><?= $t['score'] === null ? '–' : $fmt($t['score']) . ' / ' . $fmt($t['max_score']) ?></td>
        <td><?= $t['percent'] === null ? '–' : $fmt($t['percent']) . ' %' ?></td>
        <?php if ($cfg['grade']['on']): ?><td><?= $t['grade'] === null ? '–' : $fmt($t['grade']) ?></td><?php endif; ?>
        <td><?= $t['visible_to_person'] ? 'ja' : 'nein' ?></td>
        <td><a href="?key=<?= e($act['akey']) ?>&amp;attempt=<?= $t['id'] ?>">Antworten</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
  <?php if ($retries): ?>
  <details class="exam-admin-edit"><summary>Übungsversuche (<?= $retries ?>)</summary>
    <ul><?php foreach ($attempts as $t): if ($t['attempt_no'] === 1) continue; ?><li><?= e($t['name']) ?>, Versuch <?= $t['attempt_no'] ?>: <?= $t['score'] === null ? e($t['status']) : $fmt($t['score']) . ' / ' . $fmt($t['max_score']) ?> · <a href="?key=<?= e($act['akey']) ?>&amp;attempt=<?= $t['id'] ?>">Antworten</a></li><?php endforeach; ?></ul>
  </details>
  <?php endif; ?>
  <h3 class="exam-admin-h3">Lösungsquote pro Frage</h3>
  <ol class="exam-stats">
    <?php foreach ($stats as $s): if ($s['status'] === 'struck') { echo '<li><span class="exam-stats-prompt">' . e(mb_strimwidth($s['prompt'], 0, 90, '…')) . '</span> <span class="pill exam-st-struck">gestrichen, zählt nicht</span></li>'; continue; } ?>
    <li><span class="exam-stats-prompt"><a href="?key=<?= e($act['akey']) ?>&amp;grade_q=<?= $s['question_id'] ?>" title="Diese Frage über alle Versuche bewerten"><?= e(mb_strimwidth($s['prompt'], 0, 90, '…')) ?></a></span>
      <span class="exam-stats-bar" title="Anteil voller Punktzahl"><span style="width: <?= $s['n'] ? round(100 * $s['solved_rate']) : 0 ?>%"></span></span>
      <span class="muted"><?= $s['n'] ? round(100 * $s['solved_rate']) . ' % voll · Mittel ' . $fmt($s['mean_points']) . '/' . $fmt($s['points']) . ' P. · ' . $s['n'] . ' bewertet' : 'noch keine Bewertung' ?></span></li>
    <?php endforeach; ?>
  </ol>
  <?php if ($cfg['ranking']): $ranking = exam_ranking($act); ?>
  <h3 class="exam-admin-h3">Rangliste <?= $act['revealed'] ? '(für alle sichtbar)' : '(nur für dich)' ?></h3>
  <?php if (!$ranking): ?><p class="muted">Noch keine vollständig bewerteten Versuche.</p><?php else: ?>
  <ol class="exam-rank"><?php foreach ($ranking as $r): ?><li><?= $r['rank'] ?>. <?= e($r['name']) ?> · <?= $fmt($r['percent']) ?> %<?= $r['grade'] !== null ? ' · Note ' . $fmt($r['grade']) : '' ?></li><?php endforeach; ?></ol>
  <?php endif; endif; ?>
</section>
<?php endif; ?>

<section class="card" id="fragen">
  <h2>Fragen</h2>
  <p class="tags">
    <?php foreach (['all' => 'Alle (' . $counts['total'] . ')', 'submitted' => 'Eingereicht (' . $counts['submitted'] . ')', 'accepted' => 'Angenommen (' . $counts['accepted'] . ')', 'struck' => 'Gestrichen (' . $counts['struck'] . ')', 'rejected' => 'Abgelehnt (' . $counts['rejected'] . ')', 'trash' => 'Papierkorb'] as $k => $label): if ($k === 'struck' && !$counts['struck']) continue; ?>
      <a class="tag <?= $tab === $k ? 'active' : '' ?>" href="?key=<?= e($act['akey']) ?><?= $k !== 'all' ? '&amp;tab=' . $k : '' ?>#fragen"><?= e($label) ?></a>
    <?php endforeach; ?>
  </p>
  <div class="action-grid">
    <?php if ($aiStatus['available'] && ($counts['submitted'] + $counts['accepted']) > 0): ?>
      <form method="post" data-busy="Die KI prüft die Fragen …"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="q_check"><button class="btn-ghost" type="submit" title="Markiert verdächtige Lösungen, Doppelte und unklare Formulierungen; ändert nichts">✨ Fragen prüfen</button></form>
    <?php elseif (!$aiStatus['available']): ?><span class="muted">✨ Fragen prüfen: <?= e($aiReasonText()) ?></span><?php endif; ?>
  </div>
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
      <?php if (!empty($q['check']['issues'])): ?>
        <ul class="exam-check">
          <?php foreach ($q['check']['issues'] as $is): ?><li class="exam-check-<?= e($is['kind']) ?>">✨ <strong><?= e($checkKind[$is['kind']] ?? $is['kind']) ?>:</strong> <?= e($is['text']) ?><?= $is['duplicate_of'] ? ' <a href="#q' . (int)$is['duplicate_of'] . '">(siehe Frage)</a>' : '' ?></li><?php endforeach; ?>
        </ul>
      <?php elseif ($q['check'] !== null): ?><p class="exam-check-ok muted">✨ unauffällig (<?= e((string)$q['check']['at']) ?>)</p><?php endif; ?>
      <div class="action-grid">
        <?php if ($q['deleted_at']): ?>
          <?= $post('q_restore', 'Wiederherstellen', 'btn-ghost', '', '', $q['id']) ?>
        <?php else: ?>
          <?php if ($q['status'] === 'struck'): ?>
            <?= $post('q_unstrike', 'Wieder aufnehmen', 'btn', '', 'Die Frage zählt wieder in allen Versuchen', $q['id']) ?>
          <?php else: ?>
          <?php if ($q['status'] !== 'accepted'): ?><?= $post('q_accept', 'Annehmen', 'btn', '', '', $q['id']) ?><?php endif; ?>
          <?php if ($q['status'] === 'accepted' && $cfg['phase'] !== 'collect'): ?><?= $post('q_strike', 'Streichen', 'btn-ghost', 'Frage streichen? Sie fällt in allen Versuchen aus Punkten und Maximum; die Lernenden sehen sie als gestrichen. Rückgängig mit «Wieder aufnehmen».', 'Missratene Frage aus der Bewertung nehmen', $q['id']) ?><?php endif; ?>
          <?php if ($q['status'] !== 'rejected'): ?><?= $post('q_reject', 'Ablehnen', 'btn-ghost', '', '', $q['id']) ?><?php endif; ?>
          <?php endif; ?>
          <?php if (!in_array($q['status'], ['submitted', 'struck'], true) && !$q['by_owner']): ?><?= $post('q_reopen', 'Wieder offen', 'btn-ghost', '', 'Die Verfasserin kann die Frage dann wieder bearbeiten', $q['id']) ?><?php endif; ?>
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
    <h3 class="exam-admin-h3">Durchführung und Bewertung</h3>
    <div class="row two">
      <div>
        <label for="grading">Korrektur</label>
        <select id="grading" name="grading">
          <option value="auto" <?= $cfg['grading'] === 'auto' ? 'selected' : '' ?>>automatisch (Punkte sofort, Rangliste möglich)</option>
          <option value="review" <?= $cfg['grading'] === 'review' ? 'selected' : '' ?>>mit Durchsicht (Punkte nach «Korrektur abschliessen»)</option>
          <option value="manual" <?= $cfg['grading'] === 'manual' ? 'selected' : '' ?>>von Hand (offene Fragen bewertest du)</option>
        </select>
        <p class="hint">Automatisch: Die KI bewertet offene Fragen nach jeder Abgabe, die Punkte gelten sofort. Mit Durchsicht: Alle Punkte sind Vorschläge, die du überschreiben kannst. Von Hand: keine KI, offene Fragen bewertest du selbst. Deine Punkte gehen immer vor.</p>
      </div>
      <div>
        <label for="mc_rule">Mehrfachauswahl und Richtig/Falsch</label>
        <select id="mc_rule" name="mc_rule">
          <option value="kprim" <?= $cfg['mc_rule'] === 'kprim' ? 'selected' : '' ?>>Kprim: alles richtig = voll, ein Fehler = halb</option>
          <option value="proportional" <?= $cfg['mc_rule'] === 'proportional' ? 'selected' : '' ?>>anteilig: richtige minus falsche Entscheidungen</option>
        </select>
      </div>
    </div>
    <div class="row two">
      <div>
        <label for="own_questions">Eigene Fragen</label>
        <select id="own_questions" name="own_questions">
          <option value="count" <?= $cfg['own_questions'] === 'count' ? 'selected' : '' ?>>zählen mit</option>
          <option value="exclude" <?= $cfg['own_questions'] === 'exclude' ? 'selected' : '' ?>>zählen für die Verfasserin nicht</option>
        </select>
      </div>
      <div>
        <label for="display">Ausgabe</label>
        <select id="display" name="display">
          <option value="points" <?= $cfg['display'] === 'points' ? 'selected' : '' ?>>Punkte</option>
          <option value="percent" <?= $cfg['display'] === 'percent' ? 'selected' : '' ?>>Prozent</option>
        </select>
      </div>
    </div>
    <div class="row two">
      <div>
        <label><input type="checkbox" name="grade_on" <?= $cfg['grade']['on'] ? 'checked' : '' ?>> Note zeigen (1 + 5 × erreicht / Maximum)</label>
        <label for="grade_max">Maximum für Note 6</label>
        <input id="grade_max" name="grade_max" inputmode="decimal" value="<?= $cfg['grade']['max'] === null ? '' : e((string)$cfg['grade']['max']) ?>" placeholder="leer = volle Punkt- bzw. Prozentzahl">
      </div>
      <div>
        <label for="grade_round">Rundung der Note</label>
        <select id="grade_round" name="grade_round">
          <?php foreach (['half' => 'auf halbe Noten', 'quarter' => 'auf Viertelnoten', 'tenth' => 'auf Zehntel'] as $k => $l): ?><option value="<?= $k ?>" <?= $cfg['grade']['round'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="row two">
      <div>
        <label for="solutions">Lösungen sehen die Lernenden</label>
        <select id="solutions" name="solutions">
          <option value="immediate" <?= $cfg['solutions'] === 'immediate' ? 'selected' : '' ?>>sofort nach der eigenen Abgabe</option>
          <option value="results" <?= $cfg['solutions'] === 'results' ? 'selected' : '' ?>>nach dem Freigeben der Ergebnisse</option>
          <option value="release" <?= $cfg['solutions'] === 'release' ? 'selected' : '' ?>>erst nach eigener Freigabe</option>
        </select>
      </div>
      <div>
        <label for="tl_minutes">Zeitbegrenzung in Minuten (0 = keine)</label>
        <input id="tl_minutes" name="tl_minutes" type="number" min="0" max="600" value="<?= (int)$cfg['time_limit']['minutes'] ?>">
        <select name="tl_mode" aria-label="Art der Zeitbegrenzung">
          <option value="person" <?= $cfg['time_limit']['per_person'] ? 'selected' : '' ?>>pro Person ab ihrem Start</option>
          <option value="fixed" <?= !$cfg['time_limit']['per_person'] ? 'selected' : '' ?>>gemeinsames Ende ab dem Start der Prüfung</option>
        </select>
      </div>
    </div>
    <div class="row">
      <div class="checks">
        <label><input type="checkbox" name="shuffle_questions" <?= $cfg['shuffle_questions'] ? 'checked' : '' ?>> Fragen pro Person mischen</label>
        <label><input type="checkbox" name="shuffle_options" <?= $cfg['shuffle_options'] ? 'checked' : '' ?>> Antworten pro Person mischen (Standard; pro Frage einstellbar)</label>
        <label><input type="checkbox" name="ranking" <?= $cfg['ranking'] ? 'checked' : '' ?>> Rangliste mit Fantasienamen (nur bei automatischer Korrektur)</label>
        <label><input type="checkbox" name="allow_retry" <?= $cfg['allow_retry'] ? 'checked' : '' ?>> Nach den Ergebnissen weitere Übungsversuche erlauben (zählen nicht)</label>
      </div>
    </div>
    <div class="row two">
      <div>
        <label for="student_pdf">Eigener Versuch als PDF für Lernende</label>
        <select id="student_pdf" name="student_pdf">
          <option value="off" <?= $cfg['student_pdf'] === 'off' ? 'selected' : '' ?>>nicht erlaubt</option>
          <option value="without" <?= $cfg['student_pdf'] === 'without' ? 'selected' : '' ?>>ohne Lösungen</option>
          <option value="with" <?= $cfg['student_pdf'] === 'with' ? 'selected' : '' ?>>mit Lösungen, sobald sie sichtbar sind</option>
        </select>
        <p class="hint">Nach der Abgabe erscheint auf der Prüfungsseite «Als PDF sichern». Punkte stehen darin erst, wenn die Person sie sehen darf; Fantasienamen nie.</p>
      </div>
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
  <?php if ($act): ?><div class="action-grid exam-shuffle-all"><?= $post('shuffle_all', 'Antworten mischen: Einstellung der Prüfung für alle Fragen übernehmen', 'btn-ghost', 'Eigene Einstellungen einzelner Fragen zum Mischen der Antworten zurücksetzen?') ?></div><?php endif; ?>
</section>
<?php admin_footer();
