<?php
declare(strict_types=1);

/**
 * Gemeinschaftsprüfung (Aktivität vom Typ exam), Etappe G1: Prüfung anlegen, Fragen sammeln,
 * sichten, bearbeiten, annehmen, ablehnen und ordnen. Konzept: CONCEPT.md, Abschnitt «Gemeinschaftsprüfung».
 *
 * Fragen stehen in exam_questions, Versuche (ab G2) in exam_attempts. Fantasienamen auf Aktivitäten
 * werden wie auf Boards eindeutig zugeteilt (activity_guest_names).
 */

const EXAM_QUESTION_TYPES = ['single', 'multi', 'truefalse', 'open'];
const EXAM_PHASES = ['collect', 'running', 'results'];
const EXAM_MAX_OPTIONS = 6;
const EXAM_MIN_OPTIONS = 2;
const EXAM_PROMPT_LENGTH = 600;
const EXAM_OPTION_LENGTH = 200;
const EXAM_SOLUTION_LENGTH = 400;
const EXAM_MAX_QUOTA = 20;
const EXAM_MAX_POINTS = 20;
const EXAM_CONFIG_KEYS = [
    'phase', 'quota_max', 'quota_min', 'require_contribution', 'points', 'mc_rule', 'grading', 'review_done',
    'own_questions', 'display', 'grade', 'ranking', 'solutions', 'solutions_released', 'shuffle_questions',
    'shuffle_options', 'time_limit', 'allow_retry', 'student_pdf', 'ai',
];

/* ---------- Konfiguration ---------- */

function exam_default_config(): array {
    return [
        'phase' => 'collect',
        'quota_max' => 3,
        'quota_min' => 0,
        'require_contribution' => false,
        'points' => ['single' => 1, 'multi' => 2, 'truefalse' => 2, 'open' => 2],
        'mc_rule' => 'kprim',
        'grading' => 'auto',
        'review_done' => false,
        'own_questions' => 'count',
        'display' => 'points',
        'grade' => ['on' => false, 'max' => null, 'round' => 'half'],
        'ranking' => false,
        'solutions' => 'results',
        'solutions_released' => false,
        'shuffle_questions' => true,
        'shuffle_options' => true,
        'time_limit' => ['minutes' => 0, 'per_person' => true],
        'allow_retry' => false,
        'student_pdf' => 'off',
        'ai' => true,
    ];
}

function exam_points_value($v): ?float {
    if (!is_numeric($v)) return null;
    $p = round((float)$v * 2) / 2; // halbe Punkte
    return ($p >= 0 && $p <= EXAM_MAX_POINTS) ? $p : null;
}

/** Bringt eine gespeicherte Konfiguration in feste Typen (nach dem Zusammenführen mit den Standardwerten). */
function exam_cast_config(array $c): array {
    $d = exam_default_config();
    $c['phase'] = in_array($c['phase'] ?? '', EXAM_PHASES, true) ? $c['phase'] : 'collect';
    $c['quota_max'] = max(0, min(EXAM_MAX_QUOTA, (int)$c['quota_max']));
    $c['quota_min'] = max(0, min($c['quota_max'], (int)$c['quota_min']));
    foreach (['require_contribution', 'review_done', 'ranking', 'solutions_released', 'shuffle_questions', 'shuffle_options', 'allow_retry', 'ai'] as $k) {
        $c[$k] = (bool)$c[$k];
    }
    $points = is_array($c['points'] ?? null) ? $c['points'] : [];
    foreach (EXAM_QUESTION_TYPES as $t) $c['points'][$t] = exam_points_value($points[$t] ?? null) ?? $d['points'][$t];
    $c['mc_rule'] = in_array($c['mc_rule'], ['kprim', 'proportional'], true) ? $c['mc_rule'] : 'kprim';
    $c['grading'] = in_array($c['grading'], ['auto', 'review', 'manual'], true) ? $c['grading'] : 'auto';
    $c['own_questions'] = in_array($c['own_questions'], ['count', 'exclude'], true) ? $c['own_questions'] : 'count';
    $c['display'] = in_array($c['display'], ['points', 'percent'], true) ? $c['display'] : 'points';
    $g = is_array($c['grade'] ?? null) ? $c['grade'] : [];
    $c['grade'] = [
        'on' => (bool)($g['on'] ?? false),
        'max' => isset($g['max']) && is_numeric($g['max']) && (float)$g['max'] > 0 ? round((float)$g['max'], 2) : null,
        'round' => in_array($g['round'] ?? '', ['half', 'quarter', 'tenth'], true) ? $g['round'] : 'half',
    ];
    if ($c['grading'] !== 'auto') $c['ranking'] = false; // Rangliste nur bei vollautomatischer Korrektur
    $c['solutions'] = in_array($c['solutions'], ['immediate', 'results', 'release'], true) ? $c['solutions'] : 'results';
    $tl = is_array($c['time_limit'] ?? null) ? $c['time_limit'] : [];
    $c['time_limit'] = ['minutes' => max(0, min(600, (int)($tl['minutes'] ?? 0))), 'per_person' => (bool)($tl['per_person'] ?? true)];
    $c['student_pdf'] = in_array($c['student_pdf'], ['off', 'without', 'with'], true) ? $c['student_pdf'] : 'off';
    return array_intersect_key($c, $d);
}

/**
 * Übernimmt geänderte Einstellungen. Unbekannte Werte werfen einen Fehler, damit Tippfehler über die
 * Schnittstelle auffallen. Die Phase wechselt in G1 nur zwischen collect und den späteren Etappen über
 * exam_set_phase, nicht über diese Funktion.
 */
function exam_normalize_config(array $in, array $current): array {
    $c = $current;
    $bool = fn($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN);
    $enum = function (string $k, array $allowed) use ($in, &$c) {
        if (!array_key_exists($k, $in)) return;
        if (!in_array($in[$k], $allowed, true)) throw new InvalidArgumentException($k . ' ist ' . implode(', ', $allowed) . '.');
        $c[$k] = $in[$k];
    };
    if (array_key_exists('quota_max', $in)) {
        if (!is_numeric($in['quota_max']) || (int)$in['quota_max'] < 0 || (int)$in['quota_max'] > EXAM_MAX_QUOTA) throw new InvalidArgumentException('quota_max ist 0 bis ' . EXAM_MAX_QUOTA . '.');
        $c['quota_max'] = (int)$in['quota_max'];
    }
    if (array_key_exists('quota_min', $in)) {
        if (!is_numeric($in['quota_min']) || (int)$in['quota_min'] < 0) throw new InvalidArgumentException('quota_min ist 0 bis quota_max.');
        $c['quota_min'] = (int)$in['quota_min'];
    }
    if ((int)$c['quota_min'] > (int)$c['quota_max']) throw new InvalidArgumentException('Die Mindestzahl Fragen darf nicht grösser sein als die Höchstzahl.');
    foreach (['require_contribution', 'shuffle_questions', 'shuffle_options', 'allow_retry', 'ai', 'ranking'] as $k) {
        if (array_key_exists($k, $in)) $c[$k] = $bool($in[$k]);
    }
    if (array_key_exists('points', $in)) {
        if (!is_array($in['points'])) throw new InvalidArgumentException('points ist ein Objekt {single, multi, truefalse, open}.');
        foreach ($in['points'] as $t => $v) {
            if (!in_array($t, EXAM_QUESTION_TYPES, true)) throw new InvalidArgumentException('Unbekannter Fragetyp in points: ' . $t . '.');
            $p = exam_points_value($v);
            if ($p === null) throw new InvalidArgumentException('Punkte sind 0 bis ' . EXAM_MAX_POINTS . ' in halben Schritten.');
            $c['points'][$t] = $p;
        }
    }
    $enum('mc_rule', ['kprim', 'proportional']);
    $enum('grading', ['auto', 'review', 'manual']);
    $enum('own_questions', ['count', 'exclude']);
    $enum('display', ['points', 'percent']);
    $enum('solutions', ['immediate', 'results', 'release']);
    $enum('student_pdf', ['off', 'without', 'with']);
    if (array_key_exists('grade', $in)) {
        $g = $in['grade'];
        if (!is_array($g)) $g = ['on' => $bool($g)];
        $cur = $c['grade'];
        if (array_key_exists('on', $g)) $cur['on'] = $bool($g['on']);
        if (array_key_exists('max', $g)) {
            if ($g['max'] === null || $g['max'] === '' || (float)$g['max'] === 0.0) $cur['max'] = null;
            elseif (!is_numeric($g['max']) || (float)$g['max'] < 0) throw new InvalidArgumentException('grade.max ist eine positive Zahl oder leer (volle Punktzahl).');
            else $cur['max'] = round((float)$g['max'], 2);
        }
        if (array_key_exists('round', $g)) {
            if (!in_array($g['round'], ['half', 'quarter', 'tenth'], true)) throw new InvalidArgumentException('grade.round ist half, quarter oder tenth.');
            $cur['round'] = $g['round'];
        }
        $c['grade'] = $cur;
    }
    if (array_key_exists('time_limit', $in)) {
        $tl = $in['time_limit'];
        if (!is_array($tl)) $tl = ['minutes' => $tl];
        $c['time_limit'] = ['minutes' => max(0, min(600, (int)($tl['minutes'] ?? $c['time_limit']['minutes']))), 'per_person' => array_key_exists('per_person', $tl) ? $bool($tl['per_person']) : $c['time_limit']['per_person']];
    }
    return exam_cast_config($c);
}

/** Konfiguration, wie Seite, Schnittstelle und Connector sie zeigen. */
function exam_config_view(array $a): array {
    $c = $a['config'];
    unset($c['review_done'], $c['solutions_released']);
    return $c + ['review_done' => $a['config']['review_done'], 'solutions_released' => $a['config']['solutions_released']];
}

/** Was die Seite der Klasse über die Konfiguration wissen darf. */
function exam_public_config(array $a): array {
    $c = $a['config'];
    return [
        'phase' => $c['phase'],
        'quota_max' => $c['quota_max'],
        'quota_min' => $c['quota_min'],
        'require_contribution' => $c['require_contribution'],
        'points' => $c['points'],
    ];
}

/* ---------- Fantasienamen auf Aktivitäten ---------- */

/**
 * Fantasiename eines Gastes auf einer Aktivität, eindeutig wie auf den Boards (siehe wb_guest_name).
 * Frei wird zuerst die älteste Zuteilung eines Gastes ohne Frage und ohne Versuch, die älter als zehn Minuten ist.
 */
function activity_guest_name(array $a, string $uid): string {
    $id = (int)$a['id'];
    $fallback = wb_fantasy_name($uid, (string)$a['akey']);
    try {
        for ($try = 0; $try < 6; $try++) {
            $row = db_row('SELECT name FROM activity_guest_names WHERE activity_id = ? AND guest_key = ?', [$id, $uid]);
            if ($row) return (string)$row['name'];
            $name = activity_guest_name_pick($a, $uid);
            try {
                db_exec('INSERT INTO activity_guest_names (activity_id, guest_key, name, created_at) VALUES (?, ?, ?, ?)', [$id, $uid, $name, wb_now()]);
                return $name;
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') throw $e;
            }
        }
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S02') throw $e; // Tabelle fehlt noch (vor Migration 13)
    }
    return $fallback;
}

function activity_guest_name_pick(array $a, string $uid): string {
    $id = (int)$a['id'];
    $pool = wb_name_pool();
    $h = crc32($uid . '|' . $a['akey']);
    $taken = array_flip(array_map(fn($r) => mb_strtolower((string)$r['name']), db_all('SELECT name FROM activity_guest_names WHERE activity_id = ?', [$id])));
    $free = array_values(array_filter($pool, fn($n) => !isset($taken[mb_strtolower($n)])));
    if ($free) return $free[$h % count($free)];
    $idle = db_row('SELECT g.guest_key, g.name FROM activity_guest_names g
        WHERE g.activity_id = ? AND g.created_at < ?
          AND NOT EXISTS (SELECT 1 FROM exam_questions q WHERE q.activity_id = g.activity_id AND q.guest_key = g.guest_key)
          AND NOT EXISTS (SELECT 1 FROM exam_attempts t WHERE t.activity_id = g.activity_id AND t.guest_key = g.guest_key)
        ORDER BY g.created_at, g.guest_key LIMIT 1', [$id, (new DateTime('-10 minutes', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u')]);
    if ($idle) {
        db_exec('DELETE FROM activity_guest_names WHERE activity_id = ? AND guest_key = ?', [$id, $idle['guest_key']]);
        return (string)$idle['name'];
    }
    for ($round = 2; $round < 50; $round++) {
        $free = array_values(array_filter($pool, fn($n) => !isset($taken[mb_strtolower($n . ' ' . $round)])));
        if ($free) return $free[$h % count($free)] . ' ' . $round;
    }
    return $pool[$h % count($pool)] . ' ' . random_int(50, 9999);
}

/** Name des Gastes, falls schon zugeteilt (ohne neu zuzuteilen). */
function activity_guest_name_get(array $a, string $uid): ?string {
    try {
        $row = db_row('SELECT name FROM activity_guest_names WHERE activity_id = ? AND guest_key = ?', [$a['id'], $uid]);
    } catch (PDOException $e) {
        return null;
    }
    return $row ? (string)$row['name'] : null;
}

/* ---------- Fragen: prüfen ---------- */

/**
 * Prüft eine Frage und gibt die gespeicherte Form zurück. Fehler sind Sprachschlüssel (exam_err_*),
 * damit Gäste sie in ihrer Sprache erhalten. Optionen ohne passende id erhalten eine neue.
 */
function exam_question_clean(array $in, ?array $current = null): array {
    $type = (string)($in['type'] ?? ($current['type'] ?? ''));
    if (!in_array($type, EXAM_QUESTION_TYPES, true)) throw new InvalidArgumentException('exam_err_type');
    $prompt = array_key_exists('prompt', $in) ? wb_clean_text((string)$in['prompt'], EXAM_PROMPT_LENGTH) : (string)($current['prompt'] ?? '');
    if (mb_strlen(trim($prompt)) < 3) throw new InvalidArgumentException('exam_err_prompt');
    $options = [];
    $solution = null;
    if ($type === 'open') {
        $solution = array_key_exists('solution', $in) ? wb_clean_text((string)$in['solution'], EXAM_SOLUTION_LENGTH) : (string)($current['solution_text'] ?? '');
        if (mb_strlen(trim($solution)) < 2) throw new InvalidArgumentException('exam_err_solution');
    } else {
        $raw = array_key_exists('options', $in) ? $in['options'] : ($current ? json_decode((string)$current['options_json'], true) : []);
        if (!is_array($raw)) $raw = [];
        $used = [];
        foreach ($raw as $o) {
            if (is_string($o)) $o = ['text' => $o];
            if (!is_array($o)) continue;
            $text = wb_clean_text(str_replace("\n", ' ', (string)($o['text'] ?? '')), EXAM_OPTION_LENGTH);
            if (trim($text) === '') {
                if (!empty($o['correct'])) throw new InvalidArgumentException('exam_err_option_text');
                continue;
            }
            $id = isset($o['id']) && preg_match('/^[a-z0-9]{1,16}$/', (string)$o['id']) && !isset($used[(string)$o['id']]) ? (string)$o['id'] : wb_random_key(6);
            $used[$id] = true;
            $options[] = ['id' => $id, 'text' => $text, 'correct' => filter_var($o['correct'] ?? false, FILTER_VALIDATE_BOOLEAN)];
        }
        if (count($options) < EXAM_MIN_OPTIONS || count($options) > EXAM_MAX_OPTIONS) throw new InvalidArgumentException('exam_err_options_count');
        $nCorrect = count(array_filter($options, fn($o) => $o['correct']));
        if ($type === 'single' && $nCorrect !== 1) throw new InvalidArgumentException('exam_err_single');
        if ($type === 'multi' && $nCorrect < 1) throw new InvalidArgumentException('exam_err_multi');
    }
    return ['type' => $type, 'prompt' => $prompt, 'options' => $options, 'solution' => $solution];
}

/* ---------- Fragen: lesen ---------- */

function exam_question_row(array $a, int $id, bool $withDeleted = false): ?array {
    $row = db_row('SELECT * FROM exam_questions WHERE id = ? AND activity_id = ?' . ($withDeleted ? '' : ' AND deleted_at IS NULL'), [$id, $a['id']]);
    return $row ?: null;
}

/** Frage für Besitzer, Schnittstelle und Connector (mit Lösung und Verfasser). */
function exam_question_view(array $a, array $q, bool $forOwner = true): array {
    $points = $q['points'] !== null ? (float)$q['points'] : (float)$a['config']['points'][$q['type']];
    $v = [
        'id' => (int)$q['id'],
        'type' => $q['type'],
        'prompt' => $q['prompt'],
        'options' => $q['type'] === 'open' ? [] : (json_decode((string)$q['options_json'], true) ?: []),
        'solution' => $q['type'] === 'open' ? (string)$q['solution_text'] : null,
        'points' => $points,
        'status' => $q['status'],
        'edited' => $q['edited_at'] !== null,
        'created_at' => wb_local_time($q['created_at']),
        'updated_at' => wb_local_time($q['updated_at']),
    ];
    if ($forOwner) {
        $v += [
            'position' => (int)$q['position'],
            'points_custom' => $q['points'] !== null,
            'shuffle_options' => $q['shuffle_options'] === null ? null : (bool)(int)$q['shuffle_options'],
            'author' => (string)$q['author_name'],
            'by_owner' => $q['guest_key'] === WB_OWNER_KEY,
            'check' => $q['check_json'] ? json_decode((string)$q['check_json'], true) : null,
            'deleted_at' => $q['deleted_at'],
        ];
    }
    return $v;
}

/**
 * Fragen einer Prüfung in Prüfungsreihenfolge: angenommene nach Position, dann eingereichte, dann abgelehnte.
 * $status: all (ohne gelöschte), submitted, accepted, rejected, trash.
 */
function exam_questions(array $a, string $status = 'all'): array {
    $where = ['activity_id = ?'];
    $params = [$a['id']];
    if ($status === 'trash') $where[] = 'deleted_at IS NOT NULL';
    else $where[] = 'deleted_at IS NULL';
    if (in_array($status, ['submitted', 'accepted', 'rejected'], true)) { $where[] = 'status = ?'; $params[] = $status; }
    $rows = db_all('SELECT * FROM exam_questions WHERE ' . implode(' AND ', $where) . " ORDER BY FIELD(status, 'accepted', 'submitted', 'rejected'), position, id", $params);
    return array_map(fn($q) => exam_question_view($a, $q), $rows);
}

function exam_counts(array $a): array {
    $rows = db_all('SELECT status, COUNT(*) AS n FROM exam_questions WHERE activity_id = ? AND deleted_at IS NULL GROUP BY status', [$a['id']]);
    $c = ['submitted' => 0, 'accepted' => 0, 'rejected' => 0];
    foreach ($rows as $r) $c[$r['status']] = (int)$r['n'];
    $c['total'] = $c['submitted'] + $c['accepted'] + $c['rejected'];
    $c['contributors'] = (int)(db_row("SELECT COUNT(DISTINCT guest_key) AS n FROM exam_questions WHERE activity_id = ? AND deleted_at IS NULL AND guest_key <> ?", [$a['id'], WB_OWNER_KEY])['n'] ?? 0);
    $c['points'] = 0.0;
    foreach (exam_questions($a, 'accepted') as $q) $c['points'] += $q['points'];
    return $c;
}

/** Fragen eines Gastes (eingereicht, angenommen, abgelehnt). */
function exam_my_questions(array $a, string $uid): array {
    $rows = db_all('SELECT * FROM exam_questions WHERE activity_id = ? AND guest_key = ? AND deleted_at IS NULL ORDER BY id', [$a['id'], $uid]);
    return array_map(fn($q) => exam_question_view($a, $q, false), $rows);
}

/** Zählt für das Kontingent: eingereichte und angenommene Fragen (abgelehnte dürfen ersetzt werden). */
function exam_quota_used(array $a, string $uid): int {
    return (int)(db_row("SELECT COUNT(*) AS n FROM exam_questions WHERE activity_id = ? AND guest_key = ? AND deleted_at IS NULL AND status <> 'rejected'", [$a['id'], $uid])['n'] ?? 0);
}

/* ---------- Fragen: schreiben ---------- */

function exam_next_position(array $a): int {
    return (int)(db_row('SELECT COALESCE(MAX(position), 0) + 1 AS p FROM exam_questions WHERE activity_id = ?', [$a['id']])['p'] ?? 1);
}

/** Gast reicht eine Frage ein (nur in der Sammelphase einer offenen Prüfung, im Rahmen des Kontingents). */
function exam_guest_question_create(array $a, string $uid, array $in): array {
    if ($a['config']['phase'] !== 'collect') throw new InvalidArgumentException('exam_not_collecting');
    if (exam_quota_used($a, $uid) >= $a['config']['quota_max']) throw new InvalidArgumentException('exam_quota');
    $q = exam_question_clean($in);
    $now = wb_now();
    $id = db_insert(
        'INSERT INTO exam_questions (activity_id, guest_key, author_name, type, prompt, options_json, solution_text, status, position, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$a['id'], $uid, activity_guest_name($a, $uid), $q['type'], $q['prompt'], json_encode($q['options'], JSON_UNESCAPED_UNICODE), $q['solution'], 'submitted', exam_next_position($a), $now, $now]
    );
    activity_touch($a['id']);
    return exam_question_view($a, exam_question_row($a, $id), false);
}

/** Gast ändert eine eigene, noch nicht beurteilte Frage. */
function exam_guest_question_update(array $a, string $uid, int $id, array $in): array {
    $row = exam_question_row($a, $id);
    if (!$row || !hash_equals($row['guest_key'], $uid)) throw new InvalidArgumentException('exam_err_not_found');
    if ($a['config']['phase'] !== 'collect') throw new InvalidArgumentException('exam_not_collecting');
    if ($row['status'] !== 'submitted') throw new InvalidArgumentException('exam_err_locked');
    $q = exam_question_clean($in, $row);
    db_exec('UPDATE exam_questions SET type = ?, prompt = ?, options_json = ?, solution_text = ?, updated_at = ? WHERE id = ?',
        [$q['type'], $q['prompt'], json_encode($q['options'], JSON_UNESCAPED_UNICODE), $q['solution'], wb_now(), $id]);
    activity_touch($a['id']);
    return exam_question_view($a, exam_question_row($a, $id), false);
}

/** Gast zieht eine eigene, noch nicht beurteilte Frage zurück (weich gelöscht). */
function exam_guest_question_withdraw(array $a, string $uid, int $id): void {
    $row = exam_question_row($a, $id);
    if (!$row || !hash_equals($row['guest_key'], $uid)) throw new InvalidArgumentException('exam_err_not_found');
    if ($a['config']['phase'] !== 'collect') throw new InvalidArgumentException('exam_not_collecting');
    if ($row['status'] !== 'submitted') throw new InvalidArgumentException('exam_err_locked');
    db_exec('UPDATE exam_questions SET deleted_at = ?, updated_at = ? WHERE id = ?', [wb_now(), wb_now(), $id]);
    activity_touch($a['id']);
}

/** Besitzer legt eine Frage an; sie ist sofort angenommen, ausser $in['status'] sagt etwas anderes. */
function exam_owner_question_create(array $a, array $in): array {
    $q = exam_question_clean($in);
    $status = in_array($in['status'] ?? '', ['submitted', 'accepted', 'rejected'], true) ? $in['status'] : 'accepted';
    $points = array_key_exists('points', $in) && $in['points'] !== null && $in['points'] !== '' ? exam_points_value($in['points']) : null;
    if (array_key_exists('points', $in) && $in['points'] !== null && $in['points'] !== '' && $points === null) throw new InvalidArgumentException('exam_err_points');
    $author = wb_clean_text((string)($in['author'] ?? ''), 80);
    if ($author === '') $author = undo_actor() === 'claude' ? 'Claude' : 'Lehrperson';
    $now = wb_now();
    $id = db_insert(
        'INSERT INTO exam_questions (activity_id, guest_key, author_name, type, prompt, options_json, solution_text, points, shuffle_options, status, position, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$a['id'], WB_OWNER_KEY, $author, $q['type'], $q['prompt'], json_encode($q['options'], JSON_UNESCAPED_UNICODE), $q['solution'], $points,
         array_key_exists('shuffle_options', $in) && $in['shuffle_options'] !== null ? (filter_var($in['shuffle_options'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0) : null,
         $status, exam_next_position($a), $now, $now]
    );
    activity_touch($a['id']);
    return exam_question_view($a, exam_question_row($a, $id));
}

/**
 * Besitzer ändert eine Frage: Inhalt (type, prompt, options, solution), points (null = Standard des Typs),
 * shuffle_options (null = Standard der Prüfung), status, position. Inhaltliche Änderungen an Fragen von
 * Lernenden setzen edited_at.
 */
function exam_owner_question_update(array $a, int $id, array $in): array {
    $row = exam_question_row($a, $id, true);
    if (!$row) throw new InvalidArgumentException('exam_err_not_found');
    $fields = [];
    $params = [];
    if (array_intersect(['type', 'prompt', 'options', 'solution'], array_keys($in))) {
        $q = exam_question_clean($in, $row);
        array_push($fields, 'type = ?', 'prompt = ?', 'options_json = ?', 'solution_text = ?');
        array_push($params, $q['type'], $q['prompt'], json_encode($q['options'], JSON_UNESCAPED_UNICODE), $q['solution']);
        $changed = $q['type'] !== $row['type'] || $q['prompt'] !== $row['prompt'] || (string)$q['solution'] !== (string)$row['solution_text']
            || json_encode($q['options'], JSON_UNESCAPED_UNICODE) !== (string)$row['options_json'];
        if ($changed && $row['guest_key'] !== WB_OWNER_KEY) { $fields[] = 'edited_at = ?'; $params[] = wb_now(); }
    }
    if (array_key_exists('points', $in)) {
        if ($in['points'] === null || $in['points'] === '') { $fields[] = 'points = NULL'; }
        else {
            $p = exam_points_value($in['points']);
            if ($p === null) throw new InvalidArgumentException('exam_err_points');
            $fields[] = 'points = ?'; $params[] = $p;
        }
    }
    if (array_key_exists('shuffle_options', $in)) {
        if ($in['shuffle_options'] === null || $in['shuffle_options'] === '') $fields[] = 'shuffle_options = NULL';
        else { $fields[] = 'shuffle_options = ?'; $params[] = filter_var($in['shuffle_options'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0; }
    }
    if (array_key_exists('status', $in)) {
        if (!in_array($in['status'], ['submitted', 'accepted', 'rejected'], true)) throw new InvalidArgumentException('Status ist submitted, accepted oder rejected.');
        $fields[] = 'status = ?'; $params[] = $in['status'];
        // Neu angenommene Fragen kommen ans Ende der Prüfung.
        if ($in['status'] === 'accepted' && $row['status'] !== 'accepted' && !array_key_exists('position', $in)) { $fields[] = 'position = ?'; $params[] = exam_next_position($a); }
    }
    if (array_key_exists('position', $in) && is_numeric($in['position'])) { $fields[] = 'position = ?'; $params[] = (int)$in['position']; }
    if (!$fields) return exam_question_view($a, $row);
    $fields[] = 'updated_at = ?'; $params[] = wb_now();
    $params[] = $id;
    db_exec('UPDATE exam_questions SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
    activity_touch($a['id']);
    return exam_question_view($a, exam_question_row($a, $id, true));
}

function exam_owner_question_delete(array $a, int $id): void {
    if (!exam_question_row($a, $id)) throw new InvalidArgumentException('exam_err_not_found');
    db_exec('UPDATE exam_questions SET deleted_at = ?, updated_at = ? WHERE id = ?', [wb_now(), wb_now(), $id]);
    activity_touch($a['id']);
}

function exam_owner_question_restore(array $a, int $id): array {
    if (!exam_question_row($a, $id, true)) throw new InvalidArgumentException('exam_err_not_found');
    db_exec('UPDATE exam_questions SET deleted_at = NULL, updated_at = ? WHERE id = ?', [wb_now(), $id]);
    activity_touch($a['id']);
    return exam_question_view($a, exam_question_row($a, $id));
}

/** Setzt die Reihenfolge: $ids in der gewünschten Folge; nicht genannte Fragen behalten ihre Lage dahinter. */
function exam_owner_reorder(array $a, array $ids): void {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $known = array_map('intval', array_column(db_all('SELECT id FROM exam_questions WHERE activity_id = ? AND deleted_at IS NULL ORDER BY position, id', [$a['id']]), 'id'));
    $order = array_merge(array_values(array_intersect($ids, $known)), array_values(array_diff($known, $ids)));
    $pos = 1;
    foreach ($order as $qid) db_exec('UPDATE exam_questions SET position = ? WHERE id = ?', [$pos++, $qid]);
    activity_touch($a['id']);
}

/** Verschiebt eine angenommene Frage um einen Platz nach oben (-1) oder unten (+1). */
function exam_owner_move(array $a, int $id, int $dir): void {
    $ids = array_column(exam_questions($a, 'accepted'), 'id');
    $i = array_search($id, $ids, true);
    if ($i === false) throw new InvalidArgumentException('exam_err_not_found');
    $j = $i + ($dir < 0 ? -1 : 1);
    if ($j < 0 || $j >= count($ids)) return;
    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
    exam_owner_reorder($a, $ids);
}

/* ---------- Zustand für die Seite ---------- */

function exam_state(array $a, string $uid, bool $owner): array {
    $c = $a['config'];
    $counts = exam_counts($a);
    $state = [
        'changed' => true,
        'version' => (string)$a['last_activity_at'],
        'config_version' => (string)$a['updated_at'],
        'status' => $a['status'],
        'phase' => $c['phase'],
        'quota_max' => $c['quota_max'],
        'quota_min' => $c['quota_min'],
        'count' => $counts['total'],
    ];
    if ($owner) {
        $state['counts'] = $counts;
    } else {
        $state['me'] = ['name' => activity_guest_name($a, $uid)];
        $state['mine'] = exam_my_questions($a, $uid);
        $state['quota_used'] = exam_quota_used($a, $uid);
    }
    return $state;
}

/* ---------- Kopieren, Löschen ---------- */

/** Kopiert die angenommenen Fragen in eine andere Prüfung (als Fragen des Besitzers, angenommen). */
function exam_copy_questions(array $from, array $to): int {
    $n = 0;
    $now = wb_now();
    foreach (db_all("SELECT * FROM exam_questions WHERE activity_id = ? AND deleted_at IS NULL AND status = 'accepted' ORDER BY position, id", [$from['id']]) as $q) {
        db_insert('INSERT INTO exam_questions (activity_id, guest_key, author_name, type, prompt, options_json, solution_text, points, shuffle_options, status, position, created_at, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$to['id'], WB_OWNER_KEY, 'Lehrperson', $q['type'], $q['prompt'], $q['options_json'], $q['solution_text'], $q['points'], $q['shuffle_options'], 'accepted', ++$n, $now, $now]);
    }
    return $n;
}

function exam_delete_data(int $activityId): void {
    foreach (['exam_questions', 'exam_attempts', 'activity_guest_names'] as $table) {
        try {
            db_exec('DELETE FROM ' . $table . ' WHERE activity_id = ?', [$activityId]);
        } catch (PDOException $e) {
            if ($e->getCode() !== '42S02') throw $e;
        }
    }
}

/* ---------- Markdown für Connector und Bibliothek ---------- */

function exam_type_label(string $type, string $lang = 'de'): string {
    return t('exam_type_' . $type, $lang);
}

function exam_markdown(array $a, bool $withSubmitted = true): string {
    $c = $a['config'];
    $counts = exam_counts($a);
    $out = '# ' . $a['name'] . "\n\n";
    $out .= 'Gemeinschaftsprüfung, Phase ' . $c['phase'] . ', Status ' . $a['status'] . '. Fragen: ' . $counts['accepted'] . ' angenommen, '
        . $counts['submitted'] . ' eingereicht, ' . $counts['rejected'] . ' abgelehnt, von ' . $counts['contributors'] . ' Personen. '
        . 'Höchstens ' . $c['quota_max'] . ' Fragen pro Person' . ($c['quota_min'] ? ', mindestens ' . $c['quota_min'] : '') . '. Punkte total ' . $counts['points'] . ".\n";
    if (!empty($a['note'])) $out .= "\nNotiz: " . $a['note'] . "\n";
    foreach (['accepted' => 'Angenommen (Prüfungsreihenfolge)', 'submitted' => 'Eingereicht, noch nicht beurteilt', 'rejected' => 'Abgelehnt'] as $st => $title) {
        if ($st !== 'accepted' && !$withSubmitted) continue;
        $qs = exam_questions($a, $st);
        if (!$qs) continue;
        $out .= "\n## " . $title . "\n";
        foreach ($qs as $i => $q) {
            $out .= "\n" . ($i + 1) . '. [#' . $q['id'] . ', ' . exam_type_label($q['type']) . ', ' . $q['points'] . ' P., von ' . $q['author'] . ($q['edited'] ? ', bearbeitet' : '') . '] ' . $q['prompt'] . "\n";
            foreach ($q['options'] as $o) {
                $mark = $q['type'] === 'truefalse' ? ($o['correct'] ? 'richtig' : 'falsch') : ($o['correct'] ? 'x' : ' ');
                $out .= '   - [' . $mark . '] ' . $o['text'] . "\n";
            }
            if ($q['type'] === 'open') $out .= '   Lösung: ' . $q['solution'] . "\n";
        }
    }
    return $out;
}
