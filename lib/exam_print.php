<?php
declare(strict_types=1);

/**
 * Gemeinschaftsprüfung, Etappe G4: Ausgaben als Druckseite (PDF über den Browser) und als CSV.
 * Die Druckseite selbst ist exam_print.php; hier stehen Daten, Texte und die CSV-Ausgaben.
 * Schnittstelle: API.md, «Ausgaben als PDF und CSV».
 */

const EXAM_PRINT_DOCS = ['sheet', 'key', 'attempt', 'all', 'overview', 'stats'];

/** Zahl ohne überflüssige Nullen (2, 1.5, 0.25). */
function exam_fmt($n, int $dec = 2): string {
    if ($n === null) return '–';
    return rtrim(rtrim(number_format((float)$n, $dec, '.', ''), '0'), '.');
}

/** Angenommene (und mit $withStruck gestrichene) Fragen in der Reihenfolge der Lehrperson. */
function exam_print_questions(array $a, bool $withStruck = true): array {
    $ok = $withStruck ? ['accepted', 'struck'] : ['accepted'];
    return array_values(array_filter(exam_questions($a, 'all'), fn($q) => in_array($q['status'], $ok, true)));
}

/** Sortiert nach Fantasiename (Umlaute wie Grundbuchstaben, ohne intl). */
function exam_print_sort_by_name(array $rows): array {
    $key = function (string $s): string {
        $s = mb_strtolower($s);
        return strtr($s, ['ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'é' => 'e', 'è' => 'e', 'à' => 'a', 'ß' => 'ss']);
    };
    usort($rows, fn($x, $y) => strnatcmp($key((string)$x['name']), $key((string)$y['name'])) ?: ($x['id'] <=> $y['id']));
    return $rows;
}

/** Erste Versuche für die Ergebnisübersicht, nach Fantasiename geordnet. */
function exam_print_overview_rows(array $a): array {
    return exam_print_sort_by_name(exam_attempts($a));
}

/** Kennzahlen der Ergebnisübersicht über die vollständig bewerteten ersten Versuche. */
function exam_print_overview_means(array $a, array $rows): array {
    $graded = array_values(array_filter($rows, fn($r) => $r['status'] === 'graded'));
    $n = count($graded);
    $grades = array_values(array_filter(array_column($graded, 'grade'), fn($g) => $g !== null));
    return [
        'n' => $n,
        'submitted' => count(array_filter($rows, fn($r) => $r['status'] !== 'in_progress')),
        'percent' => $n ? round(array_sum(array_column($graded, 'percent')) / $n, 1) : null,
        'score' => $n ? round(array_sum(array_column($graded, 'score')) / $n, 2) : null,
        'grade' => $grades ? round(array_sum($grades) / count($grades), 2) : null,
    ];
}

function exam_print_status_label(string $status, string $lang): string {
    return t('exam_print_status_' . $status, $lang);
}

/** Häufigste Fehler einer Frage als lesbarer Text. */
function exam_print_errors_text(array $s, string $lang): string {
    if (empty($s['errors'])) return $s['n_answered'] ? t('exam_print_no_errors', $lang) : '';
    $parts = [];
    foreach ($s['errors'] as $er) {
        $parts[] = t('exam_print_err_' . $er['kind'], $lang, ['t' => (string)$er['text']]) . ': ' . $er['n'];
    }
    return implode(' · ', $parts);
}

/** Punkteverteilung einer offenen Frage als lesbarer Text. */
function exam_print_distribution_text(array $s, string $lang): string {
    $parts = [];
    foreach ($s['distribution'] ?? [] as $d) $parts[] = t('exam_print_dist_item', $lang, ['p' => exam_fmt($d['points']), 'n' => $d['n']]);
    return implode(' · ', $parts);
}

/** Dateiname aus dem Titel (nur sichere Zeichen). */
function exam_print_filename(array $a, string $suffix): string {
    $base = strtr(mb_strtolower((string)$a['name']), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'à' => 'a']);
    $base = trim((string)preg_replace('/[^a-z0-9]+/', '-', $base), '-');
    return ($base !== '' ? $base : 'pruefung') . '-' . $suffix . '.csv';
}

/** CSV mit BOM, Semikolon und Dezimalpunkt (Excel öffnet so Umlaute und Spalten richtig). */
function exam_csv(array $header, array $rows): string {
    $cell = function ($v): string {
        $v = (string)$v;
        // Formeln in Zellen verhindern (CSV-Injection über Texte von Lernenden).
        if ($v !== '' && strpbrk($v[0], '=+-@') !== false && !is_numeric($v)) $v = "'" . $v;
        // Nur bei Bedarf in Anführungszeichen (Trennzeichen, Anführungszeichen, Zeilenumbruch).
        return strpbrk($v, ";\"\r\n") !== false ? '"' . str_replace('"', '""', $v) . '"' : $v;
    };
    $out = "\xEF\xBB\xBF";
    foreach (array_merge([$header], $rows) as $r) $out .= implode(';', array_map($cell, $r)) . "\r\n";
    return $out;
}

function exam_results_csv(array $a, string $lang = 'de'): string {
    $header = array_map(fn($k) => t('exam_print_col_' . $k, $lang), ['name', 'status', 'points', 'max', 'percent', 'grade', 'submitted']);
    $rows = [];
    foreach (exam_print_overview_rows($a) as $r) {
        $rows[] = [
            $r['name'], exam_print_status_label($r['status'], $lang),
            $r['score'] === null ? '' : exam_fmt($r['score']), $r['max_score'] === null ? '' : exam_fmt($r['max_score']),
            $r['percent'] === null ? '' : exam_fmt($r['percent'], 1), $r['grade'] === null ? '' : exam_fmt($r['grade']),
            (string)($r['submitted_at'] ?? ''),
        ];
    }
    return exam_csv($header, $rows);
}

function exam_stats_csv(array $a, string $lang = 'de'): string {
    $header = array_map(fn($k) => t('exam_print_col_' . $k, $lang), ['nr', 'question', 'type', 'points', 'qstatus', 'rated', 'mean', 'solved', 'errors', 'distribution']);
    $rows = [];
    foreach (exam_question_stats($a) as $i => $s) {
        $rows[] = [
            $i + 1, $s['prompt'], exam_type_label($s['type'], $lang), exam_fmt($s['points']), t('exam_print_qstatus_' . $s['status'], $lang),
            $s['n'], $s['mean_points'] === null ? '' : exam_fmt($s['mean_points']),
            $s['solved_rate'] === null ? '' : exam_fmt(100 * $s['solved_rate'], 1),
            $s['type'] === 'open' ? '' : exam_print_errors_text($s, $lang),
            $s['type'] === 'open' ? exam_print_distribution_text($s, $lang) : '',
        ];
    }
    return exam_csv($header, $rows);
}

/** Sendet eine CSV-Datei und beendet die Anfrage. */
function exam_send_csv(array $a, string $suffix, string $csv): never {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . exam_print_filename($a, $suffix) . '"');
    header('Cache-Control: no-store');
    echo $csv;
    exit;
}
