<?php
declare(strict_types=1);

/**
 * Gemeinschaftsprüfung, Etappe G2: Phasen, Versuche, Bewertung, Note, Rangliste, Lösungsfreigabe.
 * Konzept: CONCEPT.md, Abschnitt «Gemeinschaftsprüfung»; Schnittstelle: API.md, «Prüfung durchführen».
 *
 * Ein Versuch (exam_attempts) hält die gemischte Reihenfolge der Person (order_json: [{q, o: [Options-ids]}]),
 * ihre Antworten (answers_json: {Frage-id: Antwort}) und die Bewertung (grading_json: {Frage-id: {auto,
 * teacher, final, points, excluded}}). Bewertet wird immer auf dem Server mit den aktuellen Fragen, damit eine
 * nachträglich korrigierte Frage alle Versuche neu rechnet. Lösungen verlassen den Server nur über
 * exam_attempt_guest_view, und auch dort nur, wenn exam_solutions_visible es erlaubt.
 */

const EXAM_ANSWER_LENGTH = 1000;
const EXAM_DEADLINE_GRACE = 5; // Sekunden Spielraum nach Ablauf der Zeit

/* ---------- Zeit ---------- */

function exam_utc(string $mod = 'now'): DateTime {
    return new DateTime($mod, new DateTimeZone('UTC'));
}

function exam_ts(?string $dt): ?int {
    if ($dt === null || $dt === '') return null;
    return (new DateTime($dt, new DateTimeZone('UTC')))->getTimestamp();
}

/** DATETIME (UTC) als ISO-Zeitpunkt für Browser und Schnittstelle. */
function exam_iso(?string $dt): ?string {
    $ts = exam_ts($dt);
    return $ts === null ? null : gmdate('Y-m-d\TH:i:s\Z', $ts);
}

function exam_seconds_left(?string $deadline): ?int {
    $ts = exam_ts($deadline);
    return $ts === null ? null : max(0, $ts - time());
}

/* ---------- Phasen ---------- */

/**
 * Wechselt die Phase (collect → running → results, results → running, running → collect ohne Versuche).
 * Gibt [Aktivität, Warnungen] zurück. Beim Wechsel auf results werden laufende Versuche abgegeben.
 */
function exam_set_phase(array $a, string $phase): array {
    $from = $a['config']['phase'];
    if (!in_array($phase, EXAM_PHASES, true)) throw new InvalidArgumentException('exam_phase_invalid');
    if ($phase === $from) return [$a, []];
    $allowed = [['collect', 'running'], ['running', 'results'], ['results', 'running'], ['running', 'collect']];
    if (!in_array([$from, $phase], $allowed, true)) throw new InvalidArgumentException('exam_phase_invalid');
    $c = $a['config'];
    $warnings = [];
    $revealed = 0;
    if ($phase === 'running') {
        $counts = exam_counts($a);
        if ($counts['accepted'] < 1) throw new InvalidArgumentException('exam_phase_no_questions');
        if ($from === 'collect' && $counts['submitted'] > 0) {
            $warnings[] = $counts['submitted'] === 1
                ? 'Eine eingereichte Frage ist noch nicht beurteilt und kommt nicht in die Prüfung.'
                : $counts['submitted'] . ' eingereichte Fragen sind noch nicht beurteilt und kommen nicht in die Prüfung.';
        }
        $tl = $c['time_limit'];
        $c['ends_at'] = ($tl['minutes'] > 0 && !$tl['per_person']) ? exam_utc('+' . $tl['minutes'] . ' minutes')->format('Y-m-d H:i:s.u') : null;
        if ($c['ends_at'] !== null) {
            // Wiedergeöffnet: laufende Versuche erhalten das neue gemeinsame Ende.
            db_exec("UPDATE exam_attempts SET deadline_at = ? WHERE activity_id = ? AND status = 'in_progress' AND attempt_no = 1", [$c['ends_at'], $a['id']]);
        }
    }
    if ($phase === 'collect') {
        $n = (int)(db_row('SELECT COUNT(*) AS n FROM exam_attempts WHERE activity_id = ?', [$a['id']])['n'] ?? 0);
        if ($n > 0) throw new InvalidArgumentException('exam_phase_has_attempts');
        $c['ends_at'] = null;
    }
    if ($phase === 'results') {
        foreach (db_all("SELECT * FROM exam_attempts WHERE activity_id = ? AND status = 'in_progress'", [$a['id']]) as $row) {
            exam_attempt_finish($a, $row);
        }
        $c['ends_at'] = null;
        $revealed = $c['ranking'] ? 1 : 0;
    }
    $c['phase'] = $phase;
    db_exec('UPDATE activities SET config_json = ?, revealed = ?, last_activity_at = ? WHERE id = ?',
        [json_encode($c, JSON_UNESCAPED_UNICODE), $revealed, wb_now(), $a['id']]);
    return [activity_by_id($a['id']), $warnings];
}

/** Rangliste zeigen oder verbergen (nur mit ranking und in der Phase results). */
function exam_set_ranking_shown(array $a, bool $on): array {
    if ($on && (!$a['config']['ranking'] || $a['config']['phase'] !== 'results')) throw new InvalidArgumentException('exam_ranking_unavailable');
    db_exec('UPDATE activities SET revealed = ?, last_activity_at = ? WHERE id = ?', [$on ? 1 : 0, wb_now(), $a['id']]);
    return activity_by_id($a['id']);
}

/** Setzt solutions_released oder review_done in der Konfiguration (ohne die Seite neu zu laden). */
function exam_set_flag(array $a, string $flag, bool $on): array {
    if (!in_array($flag, ['solutions_released', 'review_done'], true)) throw new InvalidArgumentException('Unbekannter Schalter.');
    $c = $a['config'];
    $c[$flag] = $on;
    db_exec('UPDATE activities SET config_json = ?, last_activity_at = ? WHERE id = ?', [json_encode($c, JSON_UNESCAPED_UNICODE), wb_now(), $a['id']]);
    return activity_by_id($a['id']);
}

/* ---------- Fragen für die Bewertung ---------- */

/** Alle Fragen der Prüfung (auch gelöschte) nach id, als Datenbankzeilen. */
function exam_question_map(array $a): array {
    $map = [];
    foreach (db_all('SELECT * FROM exam_questions WHERE activity_id = ?', [$a['id']]) as $q) $map[(int)$q['id']] = $q;
    return $map;
}

function exam_q_points(array $a, array $q): float {
    return $q['points'] !== null ? (float)$q['points'] : (float)$a['config']['points'][$q['type']];
}

function exam_q_options(array $q): array {
    return $q['type'] === 'open' ? [] : (json_decode((string)$q['options_json'], true) ?: []);
}

/** Gehört die Frage (noch) zur Prüfung? Gelöschte oder nicht mehr angenommene Fragen fallen weg. */
function exam_q_active(?array $q): bool {
    return $q !== null && $q['deleted_at'] === null && $q['status'] === 'accepted';
}

/**
 * Berechnete Punkte einer Antwort. Einfachauswahl: alles oder nichts. Mehrfachauswahl und Richtig/Falsch:
 * eine Entscheidung pro Option, nach mc_rule (kprim oder proportional). Offene Fragen: null (von Hand, ab G3 KI).
 */
function exam_grade_question(array $a, array $q, $answer): ?float {
    $pts = exam_q_points($a, $q);
    $opts = exam_q_options($q);
    if ($q['type'] === 'open') return null;
    if ($q['type'] === 'single') {
        foreach ($opts as $o) if ($o['correct']) return is_string($answer) && $answer === $o['id'] ? $pts : 0.0;
        return 0.0;
    }
    $right = $wrong = $open = 0;
    foreach ($opts as $o) {
        if ($q['type'] === 'multi') {
            $chosen = is_array($answer) && in_array($o['id'], $answer, true);
            $chosen === (bool)$o['correct'] ? $right++ : $wrong++;
        } else {
            $v = is_array($answer) && array_key_exists($o['id'], $answer) ? $answer[$o['id']] : null;
            if ($v === null) $open++;
            elseif ((bool)$v === (bool)$o['correct']) $right++;
            else $wrong++;
        }
    }
    if ($a['config']['mc_rule'] === 'proportional') {
        $n = max(1, count($opts));
        return max(0.0, round($pts * ($right - $wrong) / $n, 2));
    }
    $errors = $wrong + $open;
    return $errors === 0 ? $pts : ($errors === 1 ? round($pts / 2, 2) : 0.0);
}

/**
 * Bewertet einen Versuch mit den aktuellen Fragen. Punkte der Lehrperson (teacher) bleiben erhalten.
 * Rückgabe: [grading, score, max, pending, excluded].
 */
function exam_grade_attempt(array $a, array $row, ?array $qmap = null): array {
    $qmap = $qmap ?? exam_question_map($a);
    $order = json_decode((string)$row['order_json'], true) ?: [];
    $answers = json_decode((string)$row['answers_json'], true) ?: [];
    $old = json_decode((string)$row['grading_json'], true) ?: [];
    $grading = [];
    $score = 0.0; $max = 0.0; $pending = 0; $excluded = 0;
    foreach ($order as $item) {
        $id = (int)$item['q'];
        $q = $qmap[$id] ?? null;
        if (!exam_q_active($q)) continue;
        $pts = exam_q_points($a, $q);
        $ex = $a['config']['own_questions'] === 'exclude' && hash_equals((string)$q['guest_key'], (string)$row['guest_key']);
        $auto = exam_grade_question($a, $q, $answers[(string)$id] ?? null);
        $teacher = isset($old[(string)$id]['teacher']) && $old[(string)$id]['teacher'] !== null ? min($pts, (float)$old[(string)$id]['teacher']) : null;
        $final = $teacher ?? $auto;
        $grading[(string)$id] = ['auto' => $auto, 'teacher' => $teacher, 'final' => $final, 'points' => $pts, 'excluded' => $ex];
        if ($ex) { $excluded++; continue; }
        $max += $pts;
        if ($final === null) $pending++;
        else $score += $final;
    }
    return [$grading, round($score, 2), round($max, 2), $pending, $excluded];
}

/** Schreibt die Bewertung eines abgegebenen Versuchs (Status graded, wenn keine offene Frage ohne Punkte bleibt). */
function exam_attempt_store_grading(array $a, array $row, ?array $qmap = null): array {
    [$grading, $score, $max, $pending] = exam_grade_attempt($a, $row, $qmap);
    $status = $row['status'] === 'in_progress' ? 'in_progress' : ($pending > 0 ? 'submitted' : 'graded');
    db_exec('UPDATE exam_attempts SET grading_json = ?, score = ?, max_score = ?, status = ?, updated_at = ? WHERE id = ?',
        [json_encode($grading), $score, $max, $status, wb_now(), $row['id']]);
    return db_row('SELECT * FROM exam_attempts WHERE id = ?', [$row['id']]);
}

/** Rechnet alle abgegebenen Versuche neu (nach Änderungen an Fragen oder Bewertungsregeln). */
function exam_regrade_all(array $a): int {
    if ($a['config']['phase'] === 'collect') return 0;
    $qmap = exam_question_map($a);
    $n = 0;
    foreach (db_all("SELECT * FROM exam_attempts WHERE activity_id = ? AND status <> 'in_progress'", [$a['id']]) as $row) {
        exam_attempt_store_grading($a, $row, $qmap);
        $n++;
    }
    if ($n) activity_touch($a['id']);
    return $n;
}

/* ---------- Note ---------- */

/** Note nach DBK-Formel 1 + 5 × Wert / Maximum, höchstens 6, gerundet nach grade.round; null ohne Note. */
function exam_grade_value(array $a, ?float $score, ?float $max): ?float {
    $g = $a['config']['grade'];
    if (!$g['on'] || $score === null || $max === null || $max <= 0) return null;
    if ($a['config']['display'] === 'percent') { $val = 100 * $score / $max; $m = $g['max'] ?? 100.0; }
    else { $val = $score; $m = $g['max'] ?? $max; }
    if ($m <= 0) return null;
    $n = max(1.0, min(6.0, 1 + 5 * $val / $m));
    return match ($g['round']) { 'quarter' => round($n * 4) / 4, 'tenth' => round($n, 1), default => round($n * 2) / 2 };
}

function exam_percent(?float $score, ?float $max): ?float {
    return ($score === null || $max === null || $max <= 0) ? null : round(100 * $score / $max, 1);
}

/* ---------- Versuche ---------- */

function exam_attempt_latest(array $a, string $uid): ?array {
    return db_row('SELECT * FROM exam_attempts WHERE activity_id = ? AND guest_key = ? ORDER BY attempt_no DESC LIMIT 1', [$a['id'], $uid]) ?: null;
}

function exam_attempt_by_id(array $a, int $id): ?array {
    return db_row('SELECT * FROM exam_attempts WHERE id = ? AND activity_id = ?', [$id, $a['id']]) ?: null;
}

/** Darf der Gast den ersten Versuch beginnen? true oder ein Fehlercode. */
function exam_may_start(array $a, string $uid) {
    if ($a['config']['phase'] !== 'running') return 'exam_not_running';
    if ($a['config']['require_contribution']) {
        $need = max(1, (int)$a['config']['quota_min']);
        if (exam_quota_used($a, $uid) < $need) return 'exam_need_contribution';
    }
    return true;
}

/** Gemischte Reihenfolge von Fragen und Optionen für eine Person. */
function exam_build_order(array $a): array {
    $rows = db_all("SELECT * FROM exam_questions WHERE activity_id = ? AND deleted_at IS NULL AND status = 'accepted' ORDER BY position, id", [$a['id']]);
    if ($a['config']['shuffle_questions']) shuffle($rows);
    $order = [];
    foreach ($rows as $q) {
        $ids = array_column(exam_q_options($q), 'id');
        $shuffle = $q['shuffle_options'] === null ? $a['config']['shuffle_options'] : (bool)(int)$q['shuffle_options'];
        if ($shuffle && $ids) shuffle($ids);
        $order[] = ['q' => (int)$q['id'], 'o' => $ids];
    }
    return $order;
}

function exam_attempt_insert(array $a, string $uid, int $no, ?string $deadline): array {
    $now = wb_now();
    try {
        db_insert('INSERT INTO exam_attempts (activity_id, guest_key, attempt_no, order_json, answers_json, grading_json, status, started_at, deadline_at, created_at, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$a['id'], $uid, $no, json_encode(exam_build_order($a)), '{}', '{}', 'in_progress', $now, $deadline, $now, $now]);
    } catch (PDOException $e) {
        if ($e->getCode() !== '23000') throw $e; // gleichzeitiger Start desselben Browsers
    }
    activity_guest_name($a, $uid);
    activity_touch($a['id']);
    return db_row('SELECT * FROM exam_attempts WHERE activity_id = ? AND guest_key = ? AND attempt_no = ?', [$a['id'], $uid, $no]);
}

/** Beginnt den ersten Versuch oder liefert den bestehenden. */
function exam_attempt_start(array $a, string $uid): array {
    $row = exam_attempt_latest($a, $uid);
    if ($row) return exam_attempt_check_deadline($a, $row);
    $may = exam_may_start($a, $uid);
    if ($may !== true) throw new InvalidArgumentException($may);
    $tl = $a['config']['time_limit'];
    $deadline = null;
    if ($tl['minutes'] > 0) $deadline = $tl['per_person'] ? exam_utc('+' . $tl['minutes'] . ' minutes')->format('Y-m-d H:i:s.u') : $a['config']['ends_at'];
    return exam_attempt_insert($a, $uid, 1, $deadline);
}

/** Weiterer Versuch nach freigegebenen Ergebnissen (zählt nirgends). */
function exam_attempt_retry(array $a, string $uid): array {
    $row = exam_attempt_latest($a, $uid);
    if (!exam_can_retry($a, $row)) throw new InvalidArgumentException('exam_retry_unavailable');
    $tl = $a['config']['time_limit'];
    $deadline = $tl['minutes'] > 0 ? exam_utc('+' . $tl['minutes'] . ' minutes')->format('Y-m-d H:i:s.u') : null;
    return exam_attempt_insert($a, $uid, (int)$row['attempt_no'] + 1, $deadline);
}

function exam_can_retry(array $a, ?array $row): bool {
    return $row !== null && $a['config']['phase'] === 'results' && $a['config']['allow_retry'] && $row['status'] !== 'in_progress';
}

/** Gibt einen abgelaufenen Versuch ab und liefert die aktuelle Zeile. */
function exam_attempt_check_deadline(array $a, array $row): array {
    if ($row['status'] === 'in_progress' && $row['deadline_at'] !== null && time() > exam_ts($row['deadline_at']) + EXAM_DEADLINE_GRACE) {
        return exam_attempt_finish($a, $row);
    }
    return $row;
}

/** Gibt alle abgelaufenen Versuche einer Prüfung ab (für Besitzeransichten und die Rangliste). */
function exam_finalize_expired(array $a): void {
    $limit = exam_utc('-' . EXAM_DEADLINE_GRACE . ' seconds')->format('Y-m-d H:i:s.u');
    foreach (db_all("SELECT * FROM exam_attempts WHERE activity_id = ? AND status = 'in_progress' AND deadline_at IS NOT NULL AND deadline_at < ?", [$a['id'], $limit]) as $row) {
        exam_attempt_finish($a, $row);
    }
}

function exam_attempt_finish(array $a, array $row): array {
    $now = wb_now();
    $n = db_exec("UPDATE exam_attempts SET status = 'submitted', submitted_at = ?, updated_at = ? WHERE id = ? AND status = 'in_progress'", [$now, $now, $row['id']]);
    $row = db_row('SELECT * FROM exam_attempts WHERE id = ?', [$row['id']]);
    if ($n) {
        $row = exam_attempt_store_grading($a, $row);
        activity_touch($a['id']);
    }
    return $row;
}

/** Gibt den laufenden Versuch des Gastes ab. */
function exam_attempt_submit(array $a, string $uid): array {
    $row = exam_attempt_latest($a, $uid);
    if (!$row) throw new InvalidArgumentException('exam_no_attempt');
    $row = exam_attempt_check_deadline($a, $row);
    if ($row['status'] !== 'in_progress') return $row;
    if ((int)$row['attempt_no'] === 1 && $a['config']['phase'] !== 'running') throw new InvalidArgumentException('exam_not_running');
    return exam_attempt_finish($a, $row);
}

/** Prüft eine Antwort gegen die Frage; wirft exam_answer_invalid. */
function exam_clean_answer(array $q, $v) {
    $ids = array_column(exam_q_options($q), 'id');
    switch ($q['type']) {
        case 'single':
            if ($v === null || $v === '') return null;
            if (!is_string($v) || !in_array($v, $ids, true)) throw new InvalidArgumentException('exam_answer_invalid');
            return $v;
        case 'multi':
            if ($v === null) return [];
            if (!is_array($v)) throw new InvalidArgumentException('exam_answer_invalid');
            $out = [];
            foreach ($v as $x) {
                if (!is_string($x) || !in_array($x, $ids, true)) throw new InvalidArgumentException('exam_answer_invalid');
                $out[$x] = true;
            }
            return array_keys($out);
        case 'truefalse':
            if ($v === null) return (object)[];
            if (!is_array($v)) throw new InvalidArgumentException('exam_answer_invalid');
            $out = [];
            foreach ($v as $k => $x) {
                if (!in_array((string)$k, $ids, true)) throw new InvalidArgumentException('exam_answer_invalid');
                if ($x === null) continue;
                $out[(string)$k] = filter_var($x, FILTER_VALIDATE_BOOLEAN);
            }
            return (object)$out;
        default:
            if ($v === null) return '';
            if (!is_string($v)) throw new InvalidArgumentException('exam_answer_invalid');
            return wb_clean_text($v, EXAM_ANSWER_LENGTH);
    }
}

/** Speichert Antworten des laufenden Versuchs zwischen (ersetzt die genannten Fragen). */
function exam_attempt_save_answers(array $a, string $uid, $answers): array {
    $row = exam_attempt_latest($a, $uid);
    if (!$row) throw new InvalidArgumentException('exam_no_attempt');
    $row = exam_attempt_check_deadline($a, $row);
    if ($row['status'] !== 'in_progress') {
        throw new InvalidArgumentException($row['deadline_at'] !== null && time() > exam_ts($row['deadline_at']) ? 'exam_time_up' : 'exam_submitted');
    }
    if ((int)$row['attempt_no'] === 1 && $a['config']['phase'] !== 'running') throw new InvalidArgumentException('exam_not_running');
    if (!is_array($answers)) throw new InvalidArgumentException('exam_answer_invalid');
    $inOrder = array_flip(array_map(fn($i) => (int)$i['q'], json_decode((string)$row['order_json'], true) ?: []));
    $qmap = exam_question_map($a);
    $stored = json_decode((string)$row['answers_json'], true) ?: [];
    foreach ($answers as $qid => $v) {
        $qid = (int)$qid;
        if (!isset($inOrder[$qid]) || !exam_q_active($qmap[$qid] ?? null)) throw new InvalidArgumentException('exam_answer_invalid');
        $stored[(string)$qid] = exam_clean_answer($qmap[$qid], $v);
    }
    $now = wb_now();
    db_exec('UPDATE exam_attempts SET answers_json = ?, updated_at = ? WHERE id = ?', [json_encode((object)$stored, JSON_UNESCAPED_UNICODE), $now, $row['id']]);
    return ['saved_at' => exam_iso($now), 'seconds_left' => exam_seconds_left($row['deadline_at'])];
}

/** Punkte der Lehrperson für eine Frage eines abgegebenen Versuchs (null entfernt sie). */
function exam_attempt_grade(array $a, int $attemptId, int $qid, $points): array {
    $row = exam_attempt_by_id($a, $attemptId);
    if (!$row) throw new InvalidArgumentException('exam_no_attempt');
    if ($row['status'] === 'in_progress') throw new InvalidArgumentException('exam_attempt_running');
    $qmap = exam_question_map($a);
    $q = $qmap[$qid] ?? null;
    $inOrder = in_array($qid, array_map(fn($i) => (int)$i['q'], json_decode((string)$row['order_json'], true) ?: []), true);
    if (!$inOrder || !exam_q_active($q)) throw new InvalidArgumentException('exam_err_not_found');
    $grading = json_decode((string)$row['grading_json'], true) ?: [];
    if ($points === null || $points === '') {
        $value = null;
    } else {
        $value = exam_points_value(str_replace(',', '.', (string)$points));
        if ($value === null || $value > exam_q_points($a, $q)) throw new InvalidArgumentException('exam_err_grade_points');
    }
    $grading[(string)$qid] = ($grading[(string)$qid] ?? []) + [];
    $grading[(string)$qid]['teacher'] = $value;
    $row['grading_json'] = json_encode($grading);
    $row = exam_attempt_store_grading($a, $row, $qmap);
    activity_touch($a['id']);
    return $row;
}

function exam_attempt_delete(array $a, int $attemptId): void {
    if (!exam_attempt_by_id($a, $attemptId)) throw new InvalidArgumentException('exam_no_attempt');
    db_exec('DELETE FROM exam_attempts WHERE id = ?', [$attemptId]);
    activity_touch($a['id']);
}

/* ---------- Sichtbarkeit ---------- */

/** Hat die Lehrperson an diesem Versuch Punkte gesetzt (offene Fragen oder Überschreibungen)? */
function exam_attempt_has_teacher_points(array $row): bool {
    foreach (json_decode((string)$row['grading_json'], true) ?: [] as $g) if (isset($g['teacher']) && $g['teacher'] !== null) return true;
    return false;
}

/**
 * Darf die Person ihre Punkte sehen? Nie vorläufig: Der Versuch ist abgegeben und vollständig bewertet, und
 * entweder ist er rein automatisch bewertet (grading = auto, keine Punkte der Lehrperson) oder die Lehrperson
 * hat die Korrektur abgeschlossen (review_done).
 */
function exam_result_visible(array $a, array $row): bool {
    if ($row['status'] !== 'graded') return false;
    $needsRelease = $a['config']['grading'] !== 'auto' || exam_attempt_has_teacher_points($row);
    return !$needsRelease || $a['config']['review_done'];
}

/** Darf die Person die Lösungen sehen? Nie vor der eigenen Abgabe. */
function exam_solutions_visible(array $a, array $row): bool {
    if ($row['status'] === 'in_progress') return false;
    return match ($a['config']['solutions']) {
        'immediate' => true,
        'results' => $a['config']['phase'] === 'results',
        default => (bool)$a['config']['solutions_released'],
    };
}

/* ---------- Ansichten ---------- */

/** Versuch für die Seite der Person: Fragen ohne Lösungen; Ergebnis und Lösungen nur, wenn erlaubt. */
function exam_attempt_guest_view(array $a, array $row): array {
    $qmap = exam_question_map($a);
    $order = json_decode((string)$row['order_json'], true) ?: [];
    $answers = json_decode((string)$row['answers_json'], true) ?: [];
    $grading = json_decode((string)$row['grading_json'], true) ?: [];
    $showResult = exam_result_visible($a, $row);
    $showSolutions = exam_solutions_visible($a, $row);
    $questions = [];
    $solutions = [];
    $perQuestion = [];
    $n = 0;
    foreach ($order as $item) {
        $id = (int)$item['q'];
        $q = $qmap[$id] ?? null;
        if (!exam_q_active($q)) continue;
        $byId = [];
        foreach (exam_q_options($q) as $o) $byId[$o['id']] = $o;
        $opts = [];
        foreach ($item['o'] as $oid) if (isset($byId[$oid])) $opts[] = ['id' => $oid, 'text' => $byId[$oid]['text']];
        foreach ($byId as $oid => $o) if (!in_array($oid, $item['o'], true)) $opts[] = ['id' => $oid, 'text' => $o['text']]; // nachträglich ergänzte Option
        $own = hash_equals((string)$q['guest_key'], (string)$row['guest_key']);
        $questions[] = [
            'id' => $id, 'n' => ++$n, 'type' => $q['type'], 'prompt' => $q['prompt'], 'points' => exam_q_points($a, $q),
            'options' => $opts, 'own' => $own, 'excluded' => $own && $a['config']['own_questions'] === 'exclude',
            'answer' => $answers[(string)$id] ?? null,
        ];
        if ($showSolutions) {
            $solutions[(string)$id] = [
                'correct' => array_values(array_map(fn($o) => $o['id'], array_filter($byId, fn($o) => !empty($o['correct'])))),
                'solution' => $q['type'] === 'open' ? (string)$q['solution_text'] : null,
            ];
        }
        if ($showResult) $perQuestion[(string)$id] = $grading[(string)$id]['final'] ?? null;
    }
    $score = $row['score'] !== null ? (float)$row['score'] : null;
    $max = $row['max_score'] !== null ? (float)$row['max_score'] : null;
    return [
        'no' => (int)$row['attempt_no'],
        'status' => $row['status'] === 'in_progress' ? 'in_progress' : 'submitted',
        'started_at' => exam_iso($row['started_at']),
        'deadline_at' => exam_iso($row['deadline_at']),
        'seconds_left' => $row['status'] === 'in_progress' ? exam_seconds_left($row['deadline_at']) : null,
        'submitted_at' => exam_iso($row['submitted_at']),
        'questions' => $questions,
        'result' => $showResult ? [
            'score' => $score, 'max_score' => $max, 'percent' => exam_percent($score, $max), 'grade' => exam_grade_value($a, $score, $max),
            'pending' => 0, 'display' => $a['config']['display'], 'per_question' => (object)$perQuestion,
        ] : null,
        'solutions' => $showSolutions ? (object)$solutions : null,
    ];
}

/** Zusammenfassung eines Versuchs für Besitzer, Schnittstelle und Connector. */
function exam_attempt_summary(array $a, array $row): array {
    $g = json_decode((string)$row['grading_json'], true) ?: [];
    $pending = 0; $excluded = 0;
    foreach ($g as $x) {
        if (!empty($x['excluded'])) { $excluded++; continue; }
        if (($x['final'] ?? null) === null) $pending++;
    }
    $done = $row['status'] !== 'in_progress';
    $score = $done && $row['score'] !== null ? (float)$row['score'] : null;
    $max = $done && $row['max_score'] !== null ? (float)$row['max_score'] : null;
    return [
        'id' => (int)$row['id'],
        'attempt_no' => (int)$row['attempt_no'],
        'name' => activity_guest_name_get($a, (string)$row['guest_key']) ?? '?',
        'status' => $row['status'],
        'started_at' => wb_local_time($row['started_at']),
        'deadline_at' => wb_local_time($row['deadline_at']),
        'submitted_at' => wb_local_time($row['submitted_at']),
        'score' => $score,
        'max_score' => $max,
        'percent' => exam_percent($score, $max),
        'grade' => $row['status'] === 'graded' ? exam_grade_value($a, $score, $max) : null,
        'pending' => $done ? $pending : null,
        'excluded' => $excluded,
        'visible_to_person' => exam_result_visible($a, $row),
    ];
}

/** Versuch mit allen Antworten für Besitzer (Bibliothek, Schnittstelle, Connector). */
function exam_attempt_detail(array $a, array $row): array {
    $qmap = exam_question_map($a);
    if ($row['status'] !== 'in_progress') $row = exam_attempt_store_grading($a, $row, $qmap);
    $order = json_decode((string)$row['order_json'], true) ?: [];
    $answers = json_decode((string)$row['answers_json'], true) ?: [];
    $grading = json_decode((string)$row['grading_json'], true) ?: [];
    $list = [];
    foreach ($order as $item) {
        $id = (int)$item['q'];
        $q = $qmap[$id] ?? null;
        if (!exam_q_active($q)) continue;
        $ans = $answers[(string)$id] ?? null;
        $byId = [];
        foreach (exam_q_options($q) as $o) $byId[$o['id']] = $o;
        $opts = [];
        foreach (array_unique(array_merge($item['o'], array_keys($byId))) as $oid) {
            if (!isset($byId[$oid])) continue;
            $chosen = match ($q['type']) {
                'single' => $ans === $oid,
                'multi' => is_array($ans) && in_array($oid, $ans, true),
                default => is_array($ans) && array_key_exists($oid, $ans) ? (bool)$ans[$oid] : null,
            };
            $opts[] = ['id' => $oid, 'text' => $byId[$oid]['text'], 'correct' => (bool)$byId[$oid]['correct'], 'chosen' => $chosen];
        }
        $gq = $grading[(string)$id] ?? [];
        $own = hash_equals((string)$q['guest_key'], (string)$row['guest_key']);
        $list[] = [
            'question_id' => $id, 'type' => $q['type'], 'prompt' => $q['prompt'], 'points' => exam_q_points($a, $q),
            'options' => $opts, 'solution' => $q['type'] === 'open' ? (string)$q['solution_text'] : null,
            'answer' => $q['type'] === 'open' ? (string)($ans ?? '') : $ans,
            'auto' => $gq['auto'] ?? null, 'teacher' => $gq['teacher'] ?? null, 'final' => $gq['final'] ?? null,
            'own' => $own, 'excluded' => $own && $a['config']['own_questions'] === 'exclude',
        ];
    }
    return exam_attempt_summary($a, $row) + ['answers' => $list];
}

/** Versuche einer Prüfung, neueste Abgabe zuletzt; ohne $all nur erste Versuche. */
function exam_attempts(array $a, bool $all = false): array {
    exam_finalize_expired($a);
    $rows = db_all('SELECT * FROM exam_attempts WHERE activity_id = ?' . ($all ? '' : ' AND attempt_no = 1') . ' ORDER BY attempt_no, COALESCE(submitted_at, started_at), id', [$a['id']]);
    return array_map(fn($r) => exam_attempt_summary($a, $r), $rows);
}

/** Zählung der ersten Versuche. */
function exam_attempt_counts(array $a): array {
    $c = ['started' => 0, 'submitted' => 0, 'in_progress' => 0, 'pending' => 0];
    try {
        $rows = db_all('SELECT status, COUNT(*) AS n FROM exam_attempts WHERE activity_id = ? AND attempt_no = 1 GROUP BY status', [$a['id']]);
    } catch (PDOException $e) {
        return $c;
    }
    foreach ($rows as $r) {
        $n = (int)$r['n'];
        $c['started'] += $n;
        if ($r['status'] === 'in_progress') $c['in_progress'] += $n;
        else $c['submitted'] += $n;
        if ($r['status'] === 'submitted') $c['pending'] += $n;
    }
    return $c;
}

/** Lösungsquote pro angenommener Frage aus den ersten, abgegebenen Versuchen. */
function exam_question_stats(array $a): array {
    $sum = [];
    foreach (db_all("SELECT grading_json FROM exam_attempts WHERE activity_id = ? AND attempt_no = 1 AND status <> 'in_progress'", [$a['id']]) as $r) {
        foreach (json_decode((string)$r['grading_json'], true) ?: [] as $qid => $g) {
            if (!empty($g['excluded']) || ($g['final'] ?? null) === null) continue;
            $s = &$sum[$qid];
            $s['n'] = ($s['n'] ?? 0) + 1;
            $s['points'] = ($s['points'] ?? 0) + (float)$g['final'];
            $s['full'] = ($s['full'] ?? 0) + ((float)$g['final'] >= (float)$g['points'] ? 1 : 0);
            unset($s);
        }
    }
    $out = [];
    foreach (exam_questions($a, 'accepted') as $q) {
        $s = $sum[(string)$q['id']] ?? ['n' => 0, 'points' => 0, 'full' => 0];
        $out[] = [
            'question_id' => $q['id'], 'prompt' => $q['prompt'], 'type' => $q['type'], 'n' => $s['n'], 'points' => $q['points'],
            'mean_points' => $s['n'] ? round($s['points'] / $s['n'], 2) : null,
            'solved_rate' => $s['n'] ? round($s['full'] / $s['n'], 3) : null,
        ];
    }
    return $out;
}

/** Rangliste aus den ersten, vollständig bewerteten Versuchen (nach Prozent, gleiche Werte teilen den Rang). */
function exam_ranking(array $a, ?string $uid = null): array {
    exam_finalize_expired($a);
    $rows = db_all("SELECT * FROM exam_attempts WHERE activity_id = ? AND attempt_no = 1 AND status = 'graded'", [$a['id']]);
    $list = [];
    foreach ($rows as $r) {
        $score = (float)$r['score']; $max = (float)$r['max_score'];
        $list[] = ['guest_key' => $r['guest_key'], 'score' => $score, 'max_score' => $max, 'percent' => exam_percent($score, $max) ?? 0.0, 'grade' => exam_grade_value($a, $score, $max), 'at' => (string)$r['submitted_at']];
    }
    usort($list, fn($x, $y) => [$y['percent'], $y['score'], $x['at']] <=> [$x['percent'], $x['score'], $y['at']]);
    $out = [];
    $rank = 0; $prev = null;
    foreach ($list as $i => $e) {
        if ($prev === null || $e['percent'] !== $prev) { $rank = $i + 1; $prev = $e['percent']; }
        $row = ['rank' => $rank, 'name' => activity_guest_name_get($a, $e['guest_key']) ?? '?', 'score' => $e['score'], 'max_score' => $e['max_score'], 'percent' => $e['percent'], 'grade' => $e['grade']];
        if ($uid !== null) $row['m'] = hash_equals($e['guest_key'], $uid) ? 1 : 0;
        $out[] = $row;
    }
    return $out;
}

/** Ergebnisse als Markdown (für results.md und den Connector). */
function exam_results_markdown(array $a): string {
    $c = $a['config'];
    $out = "\n## Ergebnisse (erste Versuche)\n\n";
    $attempts = exam_attempts($a);
    if (!$attempts) return $out . "Noch keine Versuche.\n";
    $fmt = fn($n) => $n === null ? '–' : rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.');
    $out .= "| Versuch | Fantasiename | Status | Punkte | Prozent | Note | ohne Punkte |\n|---|---|---|---|---|---|---|\n";
    foreach ($attempts as $t) {
        $out .= '| #' . $t['id'] . ' | ' . $t['name'] . ' | ' . $t['status'] . ' | ' . $fmt($t['score']) . ' / ' . $fmt($t['max_score']) . ' | ' . $fmt($t['percent']) . ' | ' . $fmt($t['grade']) . ' | ' . ($t['pending'] ?? '–') . " |\n";
    }
    $out .= "\n## Lösungsquote pro Frage\n\n";
    foreach (exam_question_stats($a) as $i => $s) {
        $out .= ($i + 1) . '. [#' . $s['question_id'] . '] ' . mb_substr($s['prompt'], 0, 80) . ': ' . ($s['n'] ? round(100 * $s['solved_rate']) . ' % voll, Mittel ' . $fmt($s['mean_points']) . ' von ' . $fmt($s['points']) . ' P. (' . $s['n'] . ' bewertet)' : 'noch keine Bewertung') . "\n";
    }
    if ($c['ranking']) {
        $out .= "\n## Rangliste" . ($a['revealed'] ? ' (gezeigt)' : ' (nicht gezeigt)') . "\n\n";
        foreach (exam_ranking($a) as $r) $out .= $r['rank'] . '. ' . $r['name'] . ': ' . $fmt($r['percent']) . " %\n";
    }
    return $out;
}

/* ---------- Zustand für die Seite in den Phasen running und results ---------- */

function exam_run_state(array $a, string $uid, bool $owner): array {
    $c = $a['config'];
    $s = ['time_limit' => $c['time_limit'], 'ends_at' => exam_iso($c['ends_at'] ?? null), 'display' => $c['display'], 'grading' => $c['grading']];
    if ($owner) {
        $s['attempts'] = exam_attempt_counts($a);
        $s['ranking_enabled'] = $c['ranking'];
        $s['ranking_shown'] = (bool)$a['revealed'];
        $s['ranking'] = $c['ranking'] ? exam_ranking($a) : null;
        return $s;
    }
    $row = exam_attempt_latest($a, $uid);
    if ($row) $row = exam_attempt_check_deadline($a, $row);
    $may = $row ? true : exam_may_start($a, $uid);
    $s['me'] = ['name' => activity_guest_name($a, $uid)];
    $s['may_start'] = $may;
    $s['attempt'] = $row ? exam_attempt_guest_view($a, $row) : null;
    $s['can_retry'] = exam_can_retry($a, $row);
    $s['ranking'] = ($c['phase'] === 'results' && $c['ranking'] && $a['revealed']) ? exam_ranking($a, $uid) : null;
    return $s;
}
