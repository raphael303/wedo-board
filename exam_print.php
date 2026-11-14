<?php
declare(strict_types=1);
/**
 * Druckseiten einer Gemeinschaftsprüfung (Etappe G4), aus denen der Browser das PDF macht.
 * Adresse: /a/<schlüssel>/print?doc=sheet|key|attempt|all|overview|stats[&attempt=<id>][&solutions=0|1]
 * Die Lehrperson (Admin-Sitzung) erhält die sechs Ausgaben mit Fantasienamen. Lernende erhalten unter derselben
 * Adresse nur ihren eigenen abgegebenen Versuch (?no=<n>), ohne Fantasienamen, mit Punkten nur nach
 * exam_result_visible und mit Lösungen nur bei student_pdf = with und nach exam_solutions_visible.
 * Schnittstelle: API.md, «Ausgaben als PDF und CSV».
 */
require __DIR__ . '/lib/bootstrap.php';

$a = db_is_installed() ? activity_by_key((string)($_GET['key'] ?? '')) : null;
$lang = wb_resolve_lang($a['lang'] ?? (string)wb_config('default_lang', 'de'));

/** Einfache Fehlerseite (HTML, damit der Browser sie im neuen Tab lesbar zeigt). */
$fail = function (int $code, string $msg) use ($lang): never {
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="' . e($lang) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>' . e(wb_app_name()) . '</title>'
        . '<style>body{font:16px/1.5 -apple-system,"Helvetica Neue",Arial,sans-serif;color:#1d2128;background:#f3f4f6;margin:0;padding:12vh 6vw;text-align:center}</style></head><body><p>' . e($msg) . '</p></body></html>';
    exit;
};
if (!$a || $a['type'] !== 'exam' || $a['status'] === 'archived') $fail(404, t('exam_not_found', $lang));

$owner = wb_can('board.manage');
$cfg = $a['config'];
$wantSolutions = ($_GET['solutions'] ?? '1') !== '0';
$row = null;

if ($owner) {
    $doc = (string)($_GET['doc'] ?? 'sheet');
    if (!in_array($doc, EXAM_PRINT_DOCS, true)) $fail(400, t('exam_print_unknown_doc', $lang));
    if ($doc === 'attempt') {
        $row = exam_attempt_by_id($a, (int)($_GET['attempt'] ?? 0));
        if (!$row) $fail(404, 'Versuch nicht gefunden.');
        $row = exam_attempt_check_deadline($a, $row);
    }
} else {
    // Lernende: immer nur der eigene Versuch.
    $doc = 'mine';
    if ($cfg['student_pdf'] === 'off') $fail(403, t('exam_print_pdf_off', $lang));
    if ($a['status'] === 'closed') $fail(403, t('exam_closed', $lang));
    $uid = wb_client_uid();
    $latest = exam_attempt_latest($a, $uid);
    if ($latest) exam_attempt_check_deadline($a, $latest);
    $no = (int)($_GET['no'] ?? 0);
    $row = $no > 0
        ? db_row("SELECT * FROM exam_attempts WHERE activity_id = ? AND guest_key = ? AND attempt_no = ? AND status <> 'in_progress'", [$a['id'], $uid, $no])
        : db_row("SELECT * FROM exam_attempts WHERE activity_id = ? AND guest_key = ? AND status <> 'in_progress' ORDER BY attempt_no DESC LIMIT 1", [$a['id'], $uid]);
    if (!$row) $fail(404, t('exam_print_no_attempt', $lang));
}

/* ---------- Kleine Helfer ---------- */
$typeLabel = fn(string $type): string => exam_type_label($type, $lang);
$pts = fn($n): string => t('exam_points', $lang, ['n' => exam_fmt($n)]);
$markFor = function (string $type, $chosen): string {
    if ($type === 'truefalse') return $chosen === null ? '–' : ($chosen ? 'R' : 'F');
    if ($type === 'single') return $chosen ? '●' : '○';
    return $chosen ? '☑' : '☐';
};
$tfWord = fn(bool $v): string => t($v ? 'exam_true' : 'exam_false', $lang);
$scoreLine = function (?float $score, ?float $max, ?float $grade) use ($lang, $cfg): string {
    if ($score === null || $max === null) return '';
    $s = t('exam_score_points', $lang, ['s' => exam_fmt($score), 'm' => exam_fmt($max)]);
    $p = t('exam_score_percent', $lang, ['p' => exam_fmt(exam_percent($score, $max), 1)]);
    $line = $cfg['display'] === 'percent' ? $p . ' (' . $s . ')' : $s . ' (' . $p . ')';
    if ($grade !== null) $line .= ' · ' . t('exam_grade', $lang, ['g' => exam_fmt($grade)]);
    return $line;
};
$docLabel = t('exam_print_doc_' . $doc, $lang);
$withSolutionsDoc = in_array($doc, ['attempt', 'all', 'mine'], true);

/* ---------- Daten pro Ausgabe ---------- */
$counts = exam_counts($a);
$studentShowsResult = false;
$studentShowsSolutions = false;
$people = [];
if ($doc === 'mine') {
    $studentShowsResult = exam_result_visible($a, $row);
    $studentShowsSolutions = $cfg['student_pdf'] === 'with' && exam_solutions_visible($a, $row);
    $showSol = $studentShowsSolutions && $wantSolutions;
    $people[] = ['d' => exam_attempt_detail($a, $row), 'name' => null];
} elseif ($doc === 'attempt') {
    $showSol = $wantSolutions;
    $d = exam_attempt_detail($a, $row);
    $people[] = ['d' => $d, 'name' => $d['name']];
} elseif ($doc === 'all') {
    $showSol = $wantSolutions;
    exam_finalize_expired($a);
    $list = [];
    foreach (db_all("SELECT * FROM exam_attempts WHERE activity_id = ? AND attempt_no = 1 AND status <> 'in_progress'", [$a['id']]) as $r) {
        $d = exam_attempt_detail($a, $r);
        $list[] = ['id' => $d['id'], 'name' => $d['name'], 'd' => $d];
    }
    foreach (exam_print_sort_by_name($list) as $x) $people[] = ['d' => $x['d'], 'name' => $x['name']];
} else {
    $showSol = $doc === 'key';
}
$overviewRows = in_array($doc, ['overview', 'stats', 'all'], true) ? exam_print_overview_rows($a) : [];
$means = $overviewRows ? exam_print_overview_means($a, $overviewRows) : ['n' => 0, 'submitted' => 0, 'percent' => null, 'score' => null, 'grade' => null];
$stats = $doc === 'stats' ? exam_question_stats($a) : [];

$kicker = $docLabel . ($withSolutionsDoc && ($doc !== 'mine' || $studentShowsSolutions) ? ' · ' . t($showSol ? 'exam_print_with_solutions' : 'exam_print_without_solutions', $lang) : '');
$accessLabel = preg_replace('~^https?://(www\.)?~i', '', (string)$a['url']);
$cssString = fn(string $s): string => '"' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', ' '], $s) . '"';
$self = function (array $over) use ($doc): string {
    $q = array_merge(array_intersect_key($_GET, array_flip(['doc', 'attempt', 'solutions', 'no', 'lang'])), $over);
    unset($q['key']);
    return '?' . http_build_query(array_filter($q, fn($v) => $v !== null));
};

/* ---------- Darstellung ---------- */

/** Frage auf dem Aufgabenblatt oder im Lösungsschlüssel. */
$renderSheetQuestion = function (array $q, int $n, bool $key) use ($lang, $typeLabel, $pts, $markFor, $tfWord): void {
    $struck = $q['status'] === 'struck';
    echo '<div class="q' . ($struck ? ' is-struck' : '') . '">';
    echo '<div class="q-head"><span class="q-num">' . e(t('exam_print_q', $lang, ['n' => $n])) . '</span><span class="pill">' . e($typeLabel($q['type'])) . '</span><span class="pill">' . e($pts($q['points'])) . '</span>';
    if ($struck) echo '<span class="pill struck">' . e(t('exam_print_struck', $lang)) . '</span>';
    echo '</div><p class="prompt">' . nl2br(e($q['prompt'])) . '</p>';
    if ($q['type'] === 'open') {
        if ($key) echo '<p class="solution"><b>' . e(t('exam_solution', $lang)) . ':</b> ' . nl2br(e((string)$q['solution'])) . '</p>';
        else echo '<div class="lines"><span></span><span></span><span></span><span></span></div>';
    } elseif ($q['type'] === 'truefalse') {
        echo '<table class="tf"><thead><tr><th></th><th>' . e($tfWord(true)) . '</th><th>' . e($tfWord(false)) . '</th></tr></thead><tbody>';
        foreach ($q['options'] as $o) {
            $c = (bool)$o['correct'];
            echo '<tr><td>' . e($o['text']) . '</td><td class="box">' . ($key && $c ? '✕' : '') . '</td><td class="box">' . ($key && !$c ? '✕' : '') . '</td></tr>';
        }
        echo '</tbody></table>';
    } else {
        if ($q['type'] === 'multi' && !$key) echo '<p class="hint">' . e(t('exam_multi_hint', $lang)) . '</p>';
        echo '<ul class="opts">';
        foreach ($q['options'] as $o) {
            $c = (bool)$o['correct'];
            echo '<li class="' . ($key ? ($c ? 'ok' : 'plain') : 'plain') . '"><span class="mark">' . e($markFor($q['type'], $key && $c)) . '</span><span class="txt">' . e($o['text']) . '</span>' . ($key && $c ? '<span class="tag ok">' . e(t('exam_correct_mark', $lang)) . '</span>' : '') . '</li>';
        }
        echo '</ul>';
    }
    echo '</div>';
};

/** Ein Versuch (Lehrperson: mit Fantasiename und Korrektur; Lernende: ohne Namen, nur Freigegebenes). */
$renderAttempt = function (array $d, ?string $name, array $o) use ($lang, $typeLabel, $pts, $markFor, $tfWord, $scoreLine, $cfg): void {
    $teacher = $o['teacher'];
    $sol = $o['solutions'];
    $points = $o['points'];
    echo '<section class="person' . ($o['break'] ? ' break' : '') . '">';
    echo '<div class="person-head">';
    if ($name !== null) echo '<h2>' . e($name) . ($d['attempt_no'] > 1 ? ' <small>' . e(t('exam_practice', $lang, ['n' => $d['attempt_no']])) . '</small>' : '') . '</h2>';
    elseif ($d['attempt_no'] > 1) echo '<p class="hint">' . e(t('exam_practice', $lang, ['n' => $d['attempt_no']])) . '</p>';
    $meta = [];
    if ($teacher) $meta[] = exam_print_status_label($d['status'], $lang);
    if ($d['submitted_at']) $meta[] = t('exam_print_submitted_at', $lang, ['t' => (new DateTime((string)$d['submitted_at']))->format('j.n.Y H:i')]);
    if ($meta) echo '<p class="meta">' . e(implode(' · ', $meta)) . '</p>';
    if ($points && $d['score'] !== null) {
        echo '<p class="score">' . e($scoreLine($d['score'], $d['max_score'], $d['grade'])) . '</p>';
        if ($teacher && $d['pending']) echo '<p class="meta">' . e(t('exam_print_status_submitted', $lang)) . ': ' . (int)$d['pending'] . '</p>';
    } elseif (!$points) {
        echo '<p class="notice">' . e(t('exam_print_points_hidden', $lang)) . '</p>';
    }
    if (!$teacher && !$sol && $o['solutions_hidden']) echo '<p class="notice">' . e(t('exam_print_solutions_hidden', $lang)) . '</p>';
    echo '</div>';
    foreach ($d['answers'] as $i => $x) {
        echo '<div class="q' . ($x['struck'] ? ' is-struck' : '') . '">';
        echo '<div class="q-head"><span class="q-num">' . e(t('exam_print_q', $lang, ['n' => $i + 1])) . '</span><span class="pill">' . e($typeLabel($x['type'])) . '</span><span class="pill">' . e($pts($x['points'])) . '</span>';
        if ($x['own']) echo '<span class="pill">' . e(t($x['excluded'] ? 'exam_print_own_excluded' : 'exam_print_own', $lang)) . '</span>';
        if ($x['struck']) echo '<span class="pill struck">' . e(t('exam_print_struck', $lang)) . '</span>';
        echo '</div><p class="prompt">' . nl2br(e($x['prompt'])) . '</p>';
        if ($x['type'] === 'open') {
            $ans = trim((string)$x['answer']);
            echo '<div class="answer">' . ($ans === '' ? '<span class="muted">' . e(t('exam_no_answer', $lang)) . '</span>' : nl2br(e($ans))) . '</div>';
            if ($sol) echo '<p class="solution"><b>' . e(t('exam_solution', $lang)) . ':</b> ' . nl2br(e((string)$x['solution'])) . '</p>';
        } else {
            echo '<ul class="opts">';
            foreach ($x['options'] as $op) {
                $cls = 'plain';
                $tag = '';
                if ($sol) {
                    $ok = $x['type'] === 'truefalse' ? $op['chosen'] === $op['correct'] : ($x['type'] === 'single' ? (!$op['chosen'] || $op['correct']) : $op['chosen'] === $op['correct']);
                    $cls = $ok ? ($op['chosen'] || $x['type'] === 'truefalse' ? 'ok' : 'plain') : 'no';
                    if ($x['type'] === 'truefalse') $tag = '<span class="tag ' . ($ok ? 'ok' : 'no') . '">' . e($tfWord((bool)$op['correct'])) . '</span>';
                    elseif ($op['correct']) $tag = '<span class="tag ok">' . e(t('exam_correct_mark', $lang)) . '</span>';
                }
                echo '<li class="' . $cls . '"><span class="mark">' . e($markFor($x['type'], $op['chosen'])) . '</span><span class="txt">' . e($op['text']) . '</span>' . $tag . '</li>';
            }
            echo '</ul>';
        }
        if ($x['struck']) {
            // gestrichen: keine Punkte
        } elseif ($teacher) {
            $parts = [];
            if ($x['type'] !== 'open') $parts[] = t('exam_print_auto', $lang) . ' ' . exam_fmt($x['auto']);
            if ($x['type'] === 'open') $parts[] = t('exam_print_ai', $lang) . ' ' . ($x['ai'] && !$x['ai']['failed'] ? exam_fmt($x['ai']['points']) : '–');
            if ($x['teacher'] !== null) $parts[] = t('exam_print_teacher', $lang) . ' ' . exam_fmt($x['teacher']);
            $final = $x['excluded'] ? '–' : ($x['final'] === null ? t('exam_print_pending', $lang) : exam_fmt($x['final']) . ' / ' . exam_fmt($x['points']));
            echo '<p class="grading">' . e(implode(' · ', $parts)) . ' · <b>' . e(t('exam_print_final', $lang)) . ': ' . e($final) . '</b></p>';
            if ($x['ai']) echo '<p class="reason">✨ ' . e($x['ai']['failed'] ? t('exam_print_ai_failed', $lang) : t('exam_print_reason', $lang) . ': ' . (string)$x['ai']['reason']) . ($x['ai']['model'] ? ' <span class="muted">(' . e((string)$x['ai']['model']) . ')</span>' : '') . '</p>';
        } elseif ($points && !$x['excluded']) {
            echo '<p class="grading"><b>' . e($x['final'] === null ? t('exam_print_pending', $lang) : t('exam_points_got', $lang, ['s' => exam_fmt($x['final']), 'm' => exam_fmt($x['points'])])) . '</b></p>';
            if ($sol && $x['type'] === 'open' && $x['ai'] && !$x['ai']['failed'] && $x['teacher'] === null && $cfg['grading'] !== 'manual') {
                echo '<p class="reason">' . e(t('exam_print_reason', $lang) . ': ' . (string)$x['ai']['reason']) . '</p>';
            }
        }
        echo '</div>';
    }
    echo '</section>';
};
?>
<!doctype html>
<html lang="<?= e($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($a['name']) ?> – <?= e($docLabel) ?></title>
<link rel="stylesheet" href="/assets/exam_print.css?v=<?= WB_VERSION ?>">
<style>
@page {
  size: A4;
  margin: 18mm 17mm 18mm 17mm;
  @bottom-left { content: <?= $cssString($a['name'] . ' · ' . $docLabel) ?>; font: 7.5pt Inter, "Helvetica Neue", Arial, sans-serif; color: #8a8f98; }
  @bottom-right { content: counter(page) " / " counter(pages); font: 7.5pt Inter, "Helvetica Neue", Arial, sans-serif; color: #8a8f98; }
}
</style>
</head>
<body>
<div class="toolbar no-print">
  <?php if ($owner): ?>
    <a class="btn" href="<?= e(wb_admin_url()) ?>exam.php?key=<?= e($a['akey']) ?>"><?= e(t('exam_print_back_library', $lang)) ?></a>
    <select class="btn" id="docnav" aria-label="<?= e($docLabel) ?>">
      <?php foreach (EXAM_PRINT_DOCS as $dd): if ($dd === 'attempt' && $doc !== 'attempt') continue; ?>
        <option value="<?= e($self(['doc' => $dd, 'attempt' => $dd === 'attempt' ? ($_GET['attempt'] ?? null) : null])) ?>" <?= $dd === $doc ? 'selected' : '' ?>><?= e(t('exam_print_doc_' . $dd, $lang)) ?></option>
      <?php endforeach; ?>
    </select>
  <?php else: ?>
    <a class="btn" href="<?= e($a['url']) ?>"><?= e(t('exam_print_back_exam', $lang)) ?></a>
  <?php endif; ?>
  <?php if ($withSolutionsDoc && ($doc !== 'mine' || $studentShowsSolutions)): ?>
    <a class="btn" href="<?= e($self(['solutions' => $showSol ? '0' : '1'])) ?>"><?= e(t($showSol ? 'exam_print_without_solutions' : 'exam_print_with_solutions', $lang)) ?></a>
  <?php endif; ?>
  <?php if ($doc === 'overview' || $doc === 'stats'): ?>
    <a class="btn" href="/api/activities/<?= e($a['akey']) ?>/<?= $doc === 'overview' ? 'results' : 'stats' ?>.csv"><?= e(t('exam_print_csv', $lang)) ?></a>
  <?php endif; ?>
  <button class="btn primary" id="print" type="button"><?= e(t('export_print', $lang)) ?></button>
</div>
<main class="page doc-<?= e($doc) ?>">
  <div class="band bg-<?= e($a['background']) ?>"></div>
  <p class="kicker"><?= e($kicker) ?> · <?= e(wb_app_name()) ?></p>
  <h1><?= e($a['name']) ?></h1>

<?php if ($doc === 'sheet' || $doc === 'key'):
    $qs = exam_print_questions($a, $doc === 'key'); ?>
  <div class="stats">
    <div><b><?= count(array_filter($qs, fn($q) => $q['status'] === 'accepted')) ?></b><?= e(t('exam_print_stat_questions', $lang)) ?></div>
    <div><b><?= e(exam_fmt($counts['points'])) ?></b><?= e(t('exam_print_stat_points', $lang)) ?></div>
    <?php if ($doc === 'sheet'): ?><div class="fill"><b>&nbsp;</b><?= e(t('exam_print_name', $lang)) ?></div><div class="fill short"><b>&nbsp;</b><?= e(t('exam_print_date', $lang)) ?></div><?php endif; ?>
  </div>
  <?php if ($doc === 'key'): ?><p class="rule"><?= e(t('exam_print_rule_' . $cfg['mc_rule'], $lang)) ?><?= $counts['struck'] ? ' ' . e(t('exam_print_struck_note', $lang)) : '' ?></p><?php endif; ?>
  <?php if (!$qs): ?><p class="empty"><?= e(t('exam_print_no_questions', $lang)) ?></p><?php endif; ?>
  <?php $n = 0; foreach ($qs as $q) $renderSheetQuestion($q, ++$n, $doc === 'key'); ?>

<?php elseif ($doc === 'overview'): ?>
  <div class="stats">
    <div><b><?= (int)$means['submitted'] ?></b><?= e(t('exam_print_stat_attempts', $lang)) ?></div>
    <div><b><?= $means['percent'] === null ? '–' : e(exam_fmt($means['percent'], 1)) . ' %' ?></b><?= e(t('exam_print_stat_mean', $lang)) ?></div>
    <?php if ($cfg['grade']['on']): ?><div><b><?= e(exam_fmt($means['grade'])) ?></b><?= e(t('exam_print_stat_mean_grade', $lang)) ?></div><?php endif; ?>
    <div><b><?= e(exam_fmt($counts['points'])) ?></b><?= e(t('exam_print_stat_points', $lang)) ?></div>
  </div>
  <p class="rule"><?= e(t('exam_print_first_only', $lang)) ?></p>
  <?php if (!$overviewRows): ?><p class="empty"><?= e(t('exam_print_no_attempts', $lang)) ?></p><?php else: ?>
  <div class="table-wrap"><table class="results">
    <thead><tr><th><?= e(t('exam_print_col_name', $lang)) ?></th><th><?= e(t('exam_print_col_status', $lang)) ?></th><th class="num"><?= e(t('exam_print_col_points', $lang)) ?></th><th class="num"><?= e(t('exam_print_col_percent', $lang)) ?></th><?php if ($cfg['grade']['on']): ?><th class="num"><?= e(t('exam_print_col_grade', $lang)) ?></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($overviewRows as $r): ?>
      <tr><td><?= e($r['name']) ?></td><td><?= e(exam_print_status_label($r['status'], $lang)) ?></td>
        <td class="num"><?= $r['score'] === null ? '–' : e(exam_fmt($r['score'])) . ' / ' . e(exam_fmt($r['max_score'])) ?></td>
        <td class="num"><?= $r['percent'] === null ? '–' : e(exam_fmt($r['percent'], 1)) . ' %' ?></td>
        <?php if ($cfg['grade']['on']): ?><td class="num"><?= e(exam_fmt($r['grade'])) ?></td><?php endif; ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>

<?php elseif ($doc === 'stats'): ?>
  <div class="stats">
    <div><b><?= (int)$means['submitted'] ?></b><?= e(t('exam_print_stat_attempts', $lang)) ?></div>
    <div><b><?= $means['percent'] === null ? '–' : e(exam_fmt($means['percent'], 1)) . ' %' ?></b><?= e(t('exam_print_stat_mean', $lang)) ?></div>
    <div><b><?= count(array_filter($stats, fn($s) => $s['status'] === 'accepted')) ?></b><?= e(t('exam_print_stat_questions', $lang)) ?></div>
  </div>
  <p class="rule"><?= e(t('exam_print_first_only', $lang)) ?><?= $counts['struck'] ? ' ' . e(t('exam_print_struck_note', $lang)) : '' ?></p>
  <?php if (!$stats): ?><p class="empty"><?= e(t('exam_print_no_questions', $lang)) ?></p><?php endif; ?>
  <?php foreach ($stats as $i => $s): $struck = $s['status'] === 'struck'; $rate = $s['solved_rate'] === null ? null : round(100 * $s['solved_rate']); ?>
  <div class="q stat<?= $struck ? ' is-struck' : '' ?>">
    <div class="q-head"><span class="q-num"><?= e(t('exam_print_q', $lang, ['n' => $i + 1])) ?></span><span class="pill"><?= e($typeLabel($s['type'])) ?></span><span class="pill"><?= e($pts($s['points'])) ?></span><?php if ($struck): ?><span class="pill struck"><?= e(t('exam_print_struck', $lang)) ?></span><?php endif; ?></div>
    <p class="prompt"><?= nl2br(e($s['prompt'])) ?></p>
    <?php if (!$struck): ?>
    <div class="bar"><span style="width: <?= (int)($rate ?? 0) ?>%"></span></div>
    <p class="meta"><?= $s['n'] ? e(t('exam_print_solved', $lang, ['p' => $rate]) . ' · ' . t('exam_print_mean', $lang, ['s' => exam_fmt($s['mean_points']), 'm' => exam_fmt($s['points'])]) . ' · ' . t('exam_print_rated', $lang, ['n' => $s['n']])) : e(t('exam_print_not_rated', $lang)) ?></p>
    <?php if ($s['type'] === 'open'): $dt = exam_print_distribution_text($s, $lang); ?>
      <?php if ($dt !== ''): ?><p class="detail"><b><?= e(t('exam_print_col_distribution', $lang)) ?>:</b> <?= e($dt) ?></p><?php endif; ?>
    <?php else: $et = exam_print_errors_text($s, $lang); ?>
      <?php if ($et !== ''): ?><p class="detail"><b><?= e(t('exam_print_col_errors', $lang)) ?>:</b> <?= e($et) ?></p><?php endif; ?>
    <?php endif; endif; ?>
  </div>
  <?php endforeach; ?>

<?php else: /* attempt, all, mine */ ?>
  <?php if ($doc === 'all'): ?>
  <div class="stats">
    <div><b><?= count($people) ?></b><?= e(t('exam_print_stat_attempts', $lang)) ?></div>
    <div><b><?= $means['percent'] === null ? '–' : e(exam_fmt($means['percent'], 1)) . ' %' ?></b><?= e(t('exam_print_stat_mean', $lang)) ?></div>
    <?php if ($cfg['grade']['on']): ?><div><b><?= e(exam_fmt($means['grade'])) ?></b><?= e(t('exam_print_stat_mean_grade', $lang)) ?></div><?php endif; ?>
  </div>
  <?php if (!$people): ?><p class="empty"><?= e(t('exam_print_no_attempts', $lang)) ?></p><?php endif; ?>
  <?php endif; ?>
  <?php foreach ($people as $i => $p) {
      $renderAttempt($p['d'], $p['name'], [
          'teacher' => $owner, 'solutions' => $showSol, 'break' => $doc === 'all' && $i > 0,
          'points' => $owner || $studentShowsResult,
          'solutions_hidden' => $doc === 'mine' && $cfg['student_pdf'] === 'with' && !$studentShowsSolutions,
      ]);
  } ?>
<?php endif; ?>

  <p class="colophon"><?= e(t('exam_print_colophon', $lang, ['date' => date('j.n.Y'), 'app' => wb_app_name(), 'url' => $accessLabel])) ?></p>
</main>
<script>
(function () {
  document.getElementById('print').addEventListener('click', function () { window.print(); });
  var nav = document.getElementById('docnav');
  if (nav) nav.addEventListener('change', function () { location.href = nav.value; });
  // Safari zeichnet die Seite nach dem Druckdialog manchmal nicht neu: Neuaufbau erzwingen (wie beim Export).
  window.addEventListener('afterprint', function () {
    var m = document.querySelector('main.page');
    m.style.display = 'none'; void m.offsetHeight; m.style.display = '';
    window.scrollBy(0, 1); window.scrollBy(0, -1);
  });
})();
</script>
</body>
</html>
