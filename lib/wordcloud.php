<?php
declare(strict_types=1);

/*
 * Wortwolke (Aktivität vom Typ «wordcloud»): Teilnehmende geben einzeln Begriffe ein, höchstens max_terms pro
 * Person und Runde. Die Rohbegriffe stehen unverändert in activity_responses (data_json = {"terms": [...]}).
 *
 * Zusammenlegen ist eine Schicht darüber: Begriffe mit gleicher Grundform (Gross- und Kleinschreibung, Leerzeichen,
 * Satzzeichen am Rand, Unicode-Form) legt der Server immer selbst zusammen (wordcloud_norm). Synonyme, Mehrzahl,
 * Tippfehler und Ähnliches legen die KI, Claude über den Connector oder die Lehrperson zusammen. Eine Einteilung
 * ist eine Liste von Gruppen {l: Anzeigebegriff|null, m: [Grundformen], h: ausgeblendet, f: von Hand festgelegt}.
 * Jede Änderung schreibt die ganze Einteilung als neue Zeile in activity_groupings; gültig ist die neueste nicht
 * rückgängig gemachte Zeile der Runde, Rückgängig markiert die neueste als rückgängig gemacht.
 *
 * Gezählt wird pro Begriff die Zahl der Personen, nicht der Einträge: Wer «Auto» und «Autos» schreibt, zählt nach
 * dem Zusammenlegen einmal.
 */

const WORDCLOUD_CONFIG_KEYS = ['max_terms', 'live', 'ai'];
const WORDCLOUD_MAX_TERMS = 10;
const WORDCLOUD_TERM_LENGTH = 40;

function wordcloud_default_config(): array {
    return ['max_terms' => 3, 'live' => true, 'ai' => true];
}

function wordcloud_cast_config(array $c): array {
    return [
        'max_terms' => max(1, min(WORDCLOUD_MAX_TERMS, (int)($c['max_terms'] ?? 3))),
        'live' => (bool)($c['live'] ?? true),
        'ai' => (bool)($c['ai'] ?? true),
    ];
}

function wordcloud_normalize_config(array $in, array $current): array {
    $c = $current;
    if (array_key_exists('max_terms', $in)) {
        $n = filter_var($in['max_terms'], FILTER_VALIDATE_INT);
        if ($n === false || $n < 1 || $n > WORDCLOUD_MAX_TERMS) throw new InvalidArgumentException('max_terms ist eine Zahl von 1 bis ' . WORDCLOUD_MAX_TERMS . ' (Begriffe pro Person).');
        $c['max_terms'] = $n;
    }
    if (array_key_exists('live', $in)) $c['live'] = filter_var($in['live'], FILTER_VALIDATE_BOOLEAN);
    if (array_key_exists('ai', $in)) $c['ai'] = filter_var($in['ai'], FILTER_VALIDATE_BOOLEAN);
    return wordcloud_cast_config($c);
}

/* ---------- Begriffe bereinigen ---------- */

/** Eingabe einer Person: eine Zeile, Leerraum zusammengezogen, höchstens WORDCLOUD_TERM_LENGTH Zeichen. */
function wordcloud_clean_term($s): string {
    $s = wb_clean_text(str_replace(["\n", "\t"], ' ', (string)$s), 400);
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    if (mb_strlen($s) > WORDCLOUD_TERM_LENGTH) $s = rtrim(mb_substr($s, 0, WORDCLOUD_TERM_LENGTH));
    return $s;
}

/** Grundform für das automatische Zusammenlegen: Unicode-NFC, Kleinbuchstaben, ohne Satzzeichen am Rand. */
function wordcloud_norm(string $s): string {
    if (class_exists('Normalizer')) $s = Normalizer::normalize($s, Normalizer::FORM_C) ?: $s;
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    $stripped = trim(preg_replace('/^[\p{P}\s]+|[\p{P}\s]+$/u', '', $s) ?? $s);
    return mb_strtolower($stripped !== '' ? $stripped : $s);
}

/* ---------- Antworten ---------- */

function wordcloud_terms_of(array $a, string $uid, ?int $round = null): array {
    $row = db_row('SELECT data_json FROM activity_responses WHERE activity_id = ? AND round = ? AND guest_key = ?', [$a['id'], $round ?? $a['round'], $uid]);
    if (!$row) return [];
    $d = json_decode($row['data_json'], true);
    return array_values(array_filter((array)($d['terms'] ?? []), 'is_string'));
}

function wordcloud_terms_write(array $a, string $uid, array $terms): void {
    $now = wb_now();
    if (!$terms) {
        db_exec('DELETE FROM activity_responses WHERE activity_id = ? AND round = ? AND guest_key = ?', [$a['id'], $a['round'], $uid]);
    } else {
        db_exec(
            'INSERT INTO activity_responses (activity_id, round, guest_key, data_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE data_json = VALUES(data_json), updated_at = VALUES(updated_at)',
            [$a['id'], $a['round'], $uid, json_encode(['terms' => array_values($terms)], JSON_UNESCAPED_UNICODE), $now, $now]
        );
    }
    activity_touch($a['id']);
}

/**
 * Fügt einen Begriff hinzu. Fehler als InvalidArgumentException mit Sprachschlüssel:
 * wc_empty, wc_limit (Höchstzahl erreicht), wc_duplicate (gleiche Grundform schon eingegeben).
 */
function wordcloud_term_add(array $a, string $uid, $text): array {
    $term = wordcloud_clean_term($text);
    if ($term === '') throw new InvalidArgumentException('wc_empty');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $row = db_row('SELECT data_json FROM activity_responses WHERE activity_id = ? AND round = ? AND guest_key = ? FOR UPDATE', [$a['id'], $a['round'], $uid]);
        $terms = $row ? array_values(array_filter((array)(json_decode($row['data_json'], true)['terms'] ?? []), 'is_string')) : [];
        $norm = wordcloud_norm($term);
        foreach ($terms as $t) if (wordcloud_norm($t) === $norm) throw new InvalidArgumentException('wc_duplicate');
        if (count($terms) >= $a['config']['max_terms']) throw new InvalidArgumentException('wc_limit');
        $terms[] = $term;
        wordcloud_terms_write($a, $uid, $terms);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $terms;
}

/** Entfernt einen eigenen Begriff (über die Stelle in der eigenen Liste oder den Wortlaut). */
function wordcloud_term_remove(array $a, string $uid, $index, $text = null): array {
    $terms = wordcloud_terms_of($a, $uid);
    $i = is_numeric($index) ? (int)$index : -1;
    if (!isset($terms[$i]) || ($text !== null && $text !== '' && $terms[$i] !== $text)) {
        $i = $text !== null ? array_search(wordcloud_clean_term($text), $terms, true) : false;
        if ($i === false) return $terms;
    }
    array_splice($terms, (int)$i, 1);
    wordcloud_terms_write($a, $uid, $terms);
    return $terms;
}

/* ---------- Einteilung (Zusammenlegungen) ---------- */

/** Gültige Einteilung einer Runde: ['id' => ?int, 'groups' => [...], 'actor', 'label', 'created_at']. */
function wordcloud_grouping(array $a, ?int $round = null): array {
    $row = db_row('SELECT * FROM activity_groupings WHERE activity_id = ? AND round = ? AND undone_at IS NULL ORDER BY id DESC LIMIT 1', [$a['id'], $round ?? $a['round']]);
    if (!$row) return ['id' => null, 'groups' => [], 'actor' => null, 'label' => null, 'created_at' => null];
    return ['id' => (int)$row['id'], 'groups' => wordcloud_groups_clean(json_decode($row['groups_json'], true) ?: []), 'actor' => $row['actor'], 'label' => $row['label'], 'created_at' => $row['created_at']];
}

/** Bereinigt Gruppen: jede Grundform höchstens einmal, leere und wirkungslose Gruppen fallen weg. */
function wordcloud_groups_clean(array $groups): array {
    $seen = [];
    $out = [];
    foreach ($groups as $g) {
        if (!is_array($g)) continue;
        $members = [];
        foreach ((array)($g['m'] ?? []) as $k) {
            if (!is_string($k) || $k === '' || isset($seen[$k])) continue;
            $seen[$k] = true;
            $members[] = $k;
        }
        if (!$members) continue;
        $label = isset($g['l']) && is_string($g['l']) ? wordcloud_clean_term($g['l']) : '';
        $hidden = !empty($g['h']);
        $fixed = !empty($g['f']);
        // Eine einzelne Grundform ohne Anzeigebegriff, nicht ausgeblendet und nicht von Hand festgelegt, ändert nichts.
        if (count($members) === 1 && $label === '' && !$hidden && !$fixed) continue;
        $out[] = ['l' => $label !== '' ? $label : null, 'm' => $members, 'h' => $hidden, 'f' => $fixed];
    }
    return $out;
}

/**
 * Gleiche Anzeigebegriffe ergeben einen Begriff: Gruppen mit gleichem Anzeigebegriff (nach Grundform) werden vereint,
 * und eine noch nicht eingeteilte Grundform, die einem Anzeigebegriff entspricht, kommt in dessen Gruppe. Umbenennen
 * auf einen bestehenden Begriff legt also zusammen, und eine korrigierte Schreibweise («Fahrad» → «Fahrrad») trifft
 * die richtig geschriebenen Einträge.
 */
function wordcloud_consolidate(array $a, array $groups): array {
    $groups = wordcloud_groups_clean($groups);
    $entries = wordcloud_entries($a, $a['round'])['terms'];
    $byLabel = [];
    $out = [];
    foreach ($groups as $g) {
        $ln = $g['l'] !== null ? wordcloud_norm($g['l']) : (count($g['m']) === 1 ? $g['m'][0] : null);
        if ($ln !== null && isset($byLabel[$ln])) {
            $i = $byLabel[$ln];
            $out[$i]['m'] = array_values(array_unique(array_merge($out[$i]['m'], $g['m'])));
            $out[$i]['h'] = $out[$i]['h'] || $g['h'];
            $out[$i]['f'] = $out[$i]['f'] || $g['f'];
            if ($out[$i]['l'] === null) $out[$i]['l'] = $g['l'];
            continue;
        }
        $out[] = $g;
        if ($ln !== null) $byLabel[$ln] = count($out) - 1;
    }
    $grouped = [];
    foreach ($out as $g) foreach ($g['m'] as $k) $grouped[$k] = true;
    foreach ($entries as $k => $v) {
        if (isset($grouped[$k]) || !isset($byLabel[$k])) continue;
        $out[$byLabel[$k]]['m'][] = $k;
    }
    return $out;
}

function wordcloud_grouping_save(array $a, array $groups, string $actor, string $label): int {
    $id = db_insert('INSERT INTO activity_groupings (activity_id, round, groups_json, actor, label, created_at) VALUES (?, ?, ?, ?, ?, ?)', [
        $a['id'], $a['round'], json_encode(wordcloud_consolidate($a, $groups), JSON_UNESCAPED_UNICODE), mb_substr($actor, 0, 80), mb_substr($label, 0, 200), wb_now(),
    ]);
    activity_touch($a['id']);
    return $id;
}

/** Macht die neueste Änderung der laufenden Runde rückgängig. Liefert ihre Beschriftung oder null. */
function wordcloud_undo(array $a): ?string {
    $g = wordcloud_grouping($a);
    if ($g['id'] === null) return null;
    db_exec('UPDATE activity_groupings SET undone_at = ? WHERE id = ? AND undone_at IS NULL', [wb_now(), $g['id']]);
    activity_touch($a['id']);
    return (string)$g['label'];
}

/** Letzte Änderungen der laufenden Runde (neueste zuerst). */
function wordcloud_history(array $a, int $limit = 10): array {
    $rows = db_all('SELECT id, actor, label, created_at, undone_at FROM activity_groupings WHERE activity_id = ? AND round = ? ORDER BY id DESC LIMIT ' . max(1, min(50, $limit)), [$a['id'], $a['round']]);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'actor' => $r['actor'], 'label' => $r['label'], 'created_at' => wb_local_time($r['created_at']), 'undone' => $r['undone_at'] !== null], $rows);
}

function wordcloud_actor(): string {
    return function_exists('undo_actor') ? undo_actor() : 'teacher';
}

/* ---------- Auswerten ---------- */

/**
 * Alle Einträge einer Runde nach Grundform: [norm => ['text' => häufigste Schreibweise, 'guests' => [uid => true],
 * 'entries' => n, 'order' => erste Stelle]], dazu die Zahl der Personen und Einträge.
 */
function wordcloud_entries(array $a, int $round): array {
    $rows = db_all('SELECT guest_key, data_json FROM activity_responses WHERE activity_id = ? AND round = ? ORDER BY id ASC', [$a['id'], $round]);
    $byNorm = [];
    $forms = [];
    $order = 0;
    $entries = 0;
    $people = 0;
    foreach ($rows as $row) {
        $terms = array_filter((array)(json_decode($row['data_json'], true)['terms'] ?? []), 'is_string');
        if (!$terms) continue;
        $people++;
        foreach ($terms as $t) {
            $k = wordcloud_norm($t);
            if ($k === '') continue;
            $entries++;
            if (!isset($byNorm[$k])) $byNorm[$k] = ['guests' => [], 'entries' => 0, 'order' => $order++];
            $byNorm[$k]['guests'][$row['guest_key']] = true;
            $byNorm[$k]['entries']++;
            $forms[$k][$t] = ($forms[$k][$t] ?? 0) + 1;
        }
    }
    foreach ($byNorm as $k => &$v) {
        // Häufigste Schreibweise; bei Gleichstand die zuerst eingegebene (arsort ist stabil).
        arsort($forms[$k]);
        $v['text'] = (string)array_key_first($forms[$k]);
    }
    unset($v);
    return ['terms' => $byNorm, 'people' => $people, 'entries' => $entries];
}

/**
 * Begriffe der Wolke nach Anwendung der Einteilung, häufigste zuerst:
 * [{id, label, n, hidden, fixed, grouped, variants: [{key, text, n}], guests}].
 * id ist die Grundform des häufigsten Mitglieds und dient den Aktionen der Lehrperson als Bezug.
 */
function wordcloud_build(array $a, int $round, ?array $entries = null, ?array $grouping = null): array {
    $entries = $entries ?? wordcloud_entries($a, $round);
    $grouping = $grouping ?? wordcloud_grouping($a, $round);
    $byNorm = $entries['terms'];
    $variant = fn(string $k) => ['key' => $k, 'text' => $byNorm[$k]['text'], 'n' => count($byNorm[$k]['guests'])];
    $sortVariants = function (array &$vs) use ($byNorm) {
        usort($vs, fn($x, $y) => [$y['n'], $byNorm[$x['key']]['order']] <=> [$x['n'], $byNorm[$y['key']]['order']]);
    };
    $out = [];
    $grouped = [];
    foreach ($grouping['groups'] as $g) {
        $present = array_values(array_filter($g['m'], fn($k) => isset($byNorm[$k])));
        foreach ($g['m'] as $k) $grouped[$k] = true;
        if (!$present) continue;
        $vs = array_map($variant, $present);
        $sortVariants($vs);
        $guests = [];
        foreach ($present as $k) $guests += $byNorm[$k]['guests'];
        $out[] = ['id' => $vs[0]['key'], 'label' => $g['l'] ?? $vs[0]['text'], 'n' => count($guests), 'hidden' => $g['h'], 'fixed' => $g['f'],
                  'grouped' => count($vs) > 1 || $g['l'] !== null, 'variants' => $vs, 'guests' => $guests, 'order' => min(array_map(fn($k) => $byNorm[$k]['order'], $present))];
    }
    foreach ($byNorm as $k => $v) {
        if (isset($grouped[$k])) continue;
        $out[] = ['id' => $k, 'label' => $v['text'], 'n' => count($v['guests']), 'hidden' => false, 'fixed' => false, 'grouped' => false,
                  'variants' => [$variant($k)], 'guests' => $v['guests'], 'order' => $v['order']];
    }
    usort($out, fn($x, $y) => [$y['n'], $x['order']] <=> [$x['n'], $y['order']]);
    return $out;
}

/** Zustand für die Seite. Bei «live = false» bleibt die Wolke verborgen, bis der Besitzer sie anzeigt. */
function wordcloud_state(array $a, string $uid, bool $owner, ?int $round): array {
    $viewRound = $owner && $round !== null && $round > 0 && $round !== $a['round'] ? $round : $a['round'];
    $past = $viewRound !== $a['round'];
    $visible = $past || $a['config']['live'] || $a['revealed'];
    $entries = wordcloud_entries($a, $viewRound);
    $mine = $past ? [] : wordcloud_terms_of($a, $uid);
    $terms = [];
    $hidden = [];
    $all = wordcloud_build($a, $viewRound, $entries);
    foreach ($all as $t) {
        $pub = ['id' => $t['id'], 'label' => $t['label'], 'n' => $t['n'], 'm' => isset($t['guests'][$uid]) && !$past ? 1 : 0];
        if ($t['grouped'] && count($t['variants']) > 1) $pub['v'] = array_map(fn($v) => [$v['text'], $v['n'], $v['key']], $t['variants']);
        if ($t['hidden']) { if ($owner) $hidden[] = $pub; continue; }
        if ($visible) $terms[] = $pub;
    }
    $state = [
        'changed' => true,
        'version' => (string)$a['last_activity_at'],
        'config_version' => (string)$a['updated_at'],
        'status' => $a['status'],
        'round' => $viewRound,
        'current_round' => $a['round'],
        'past' => $past,
        'revealed' => $a['revealed'],
        'visible' => $visible,
        'count' => $entries['people'],
        'entries' => $entries['entries'],
        'mine' => $mine,
        'terms' => $terms,
    ];
    if ($owner) {
        $g = $past ? null : wordcloud_grouping($a);
        $state['owner'] = ['hidden' => $visible ? $hidden : [], 'hidden_count' => count($hidden), 'undo' => $g && $g['id'] !== null ? ['label' => $g['label'], 'actor' => $g['actor']] : null];
    }
    return $state;
}

/* ---------- Aktionen der Lehrperson ---------- */

/**
 * Einzelne Korrektur: rename (term, label), release (variant: Grundform aus ihrer Gruppe lösen), hide und show (term).
 * Betroffene Gruppen gelten danach als von Hand festgelegt (f), damit ein späteres KI-Zusammenlegen sie nicht ändert.
 */
function wordcloud_group_action(array $a, array $in): array {
    $action = (string)($in['action'] ?? '');
    $lang = $a['lang'];
    $groups = wordcloud_grouping($a)['groups'];
    $find = function (string $k) use (&$groups): ?int {
        foreach ($groups as $i => $g) if (in_array($k, $g['m'], true)) return $i;
        return null;
    };
    $entries = wordcloud_entries($a, $a['round']);
    $keyOf = function ($raw) use ($entries, $lang): string {
        $k = wordcloud_key_for($entries, $raw);
        if ($k === null) throw new InvalidArgumentException(t('wc_unknown_term', $lang));
        return $k;
    };
    switch ($action) {
        case 'rename':
            $k = $keyOf($in['term'] ?? '');
            $label = wordcloud_clean_term($in['label'] ?? '');
            $i = $find($k);
            if ($i === null) { $groups[] = ['l' => $label, 'm' => [$k], 'h' => false, 'f' => true]; }
            else { $groups[$i]['l'] = $label !== '' ? $label : null; $groups[$i]['f'] = true; }
            $log = $label !== '' ? t('wc_log_rename', $lang, ['label' => $label]) : t('wc_log_rename_reset', $lang);
            break;
        case 'release':
            $k = $keyOf($in['variant'] ?? $in['term'] ?? '');
            $i = $find($k);
            if ($i === null || count($groups[$i]['m']) < 2) throw new InvalidArgumentException(t('wc_not_grouped', $lang));
            $groups[$i]['m'] = array_values(array_diff($groups[$i]['m'], [$k]));
            $groups[$i]['f'] = true;
            $groups[] = ['l' => null, 'm' => [$k], 'h' => false, 'f' => true];
            $log = t('wc_log_release', $lang, ['term' => $entries['terms'][$k]['text']]);
            break;
        case 'hide':
        case 'show':
            $k = $keyOf($in['term'] ?? '');
            $i = $find($k);
            if ($i === null) { $groups[] = ['l' => null, 'm' => [$k], 'h' => $action === 'hide', 'f' => true]; $name = $entries['terms'][$k]['text']; }
            else { $groups[$i]['h'] = $action === 'hide'; $groups[$i]['f'] = true; $name = $groups[$i]['l'] ?? $entries['terms'][$k]['text']; }
            $log = t($action === 'hide' ? 'wc_log_hide' : 'wc_log_show', $lang, ['term' => $name]);
            break;
        default:
            throw new InvalidArgumentException('Unbekannte Aktion. Erlaubt: rename, release, hide, show.');
    }
    $id = wordcloud_grouping_save($a, $groups, wordcloud_actor(), $log);
    return ['grouping_id' => $id, 'label' => $log];
}

/**
 * Ersetzt alle nicht von Hand festgelegten Gruppen (Ergebnis der KI oder von Claude). Von Hand festgelegte Gruppen
 * bleiben; $addToFixed ordnet ihnen neue Grundformen zu ([fixed_index => [keys]], Index in der Liste der festen Gruppen).
 * $groupsIn: [['label' => ?string, 'members' => [keys], 'hidden' => bool]]. Unbekannte und doppelte Grundformen fallen weg.
 */
function wordcloud_apply_merge(array $a, array $groupsIn, array $addToFixed, string $actor, string|callable $label): array {
    $entries = wordcloud_entries($a, $a['round']);
    $fixed = array_values(array_filter(wordcloud_grouping($a)['groups'], fn($g) => $g['f']));
    $used = [];
    foreach ($fixed as $g) foreach ($g['m'] as $k) $used[$k] = true;
    $added = 0;
    foreach ($addToFixed as $fi => $keys) {
        if (!isset($fixed[$fi])) continue;
        foreach ($keys as $k) {
            if (!isset($entries['terms'][$k]) || isset($used[$k])) continue;
            $used[$k] = true;
            $fixed[$fi]['m'][] = $k;
            $added++;
        }
    }
    $new = [];
    $merged = 0;
    foreach ($groupsIn as $g) {
        $members = [];
        foreach ((array)($g['members'] ?? []) as $k) {
            if (!is_string($k) || !isset($entries['terms'][$k]) || isset($used[$k])) continue;
            $used[$k] = true;
            $members[] = $k;
        }
        if (!$members) continue;
        $l = wordcloud_clean_term($g['label'] ?? '');
        // Ein Anzeigebegriff, der nur die Schreibweise des einzigen Mitglieds wiederholt, ist überflüssig.
        if (count($members) === 1 && ($l === '' || $l === $entries['terms'][$members[0]]['text']) && empty($g['hidden'])) continue;
        $new[] = ['l' => $l !== '' ? $l : null, 'm' => $members, 'h' => !empty($g['hidden']), 'f' => false];
        $merged += count($members);
    }
    $all = wordcloud_consolidate($a, array_merge($fixed, $new));
    // Vorher: alle verschiedenen Grundformen; nachher: Begriffe der Wolke mit der neuen Einteilung.
    $before = count($entries['terms']);
    $after = count(wordcloud_build($a, $a['round'], $entries, ['groups' => $all]));
    $merged = max(0, $before - $after);
    $label = is_callable($label) ? $label($before, $after) : $label;
    $id = wordcloud_grouping_save($a, $all, $actor, $label);
    return ['grouping_id' => $id, 'groups' => count($new), 'added_to_fixed' => $added, 'terms_before' => $before, 'terms_after' => $after, 'merged' => $merged, 'label' => $label];
}

/** Grundform zu einem Text oder einer Grundform, die in der Runde vorkommt, sonst null. */
function wordcloud_key_for(array $entries, $raw): ?string {
    if (!is_string($raw) && !is_numeric($raw)) return null;
    $raw = (string)$raw;
    if (isset($entries['terms'][$raw])) return $raw;
    $k = wordcloud_norm(wordcloud_clean_term($raw));
    return isset($entries['terms'][$k]) ? $k : null;
}

/* ---------- Auswertung für Bibliothek, Schnittstelle und Connector ---------- */

function wordcloud_results(array $a, ?int $round = null): array {
    $round = $round ?? $a['round'];
    $entries = wordcloud_entries($a, $round);
    $grouping = wordcloud_grouping($a, $round);
    $terms = array_map(fn($t) => [
        'id' => $t['id'], 'label' => $t['label'], 'n' => $t['n'], 'hidden' => $t['hidden'], 'fixed' => $t['fixed'],
        'variants' => array_map(fn($v) => ['key' => $v['key'], 'text' => $v['text'], 'n' => $v['n']], $t['variants']),
    ], wordcloud_build($a, $round, $entries, $grouping));
    return [
        'name' => $a['name'],
        'round' => $round,
        'current_round' => $a['round'],
        'visible' => $round !== $a['round'] || $a['config']['live'] || $a['revealed'],
        'responses' => $entries['people'],
        'entries' => $entries['entries'],
        'distinct' => count($entries['terms']),
        'terms' => $terms,
        'grouping' => ['actor' => $grouping['actor'], 'label' => $grouping['label'], 'at' => $grouping['created_at'] ? wb_local_time($grouping['created_at']) : null],
        'rounds' => activity_rounds($a),
    ];
}

function wordcloud_results_markdown(array $a, ?int $round = null): string {
    $r = wordcloud_results($a, $round);
    $out = '# ' . $a['name'] . "\n\n";
    $out .= 'Wortwolke, Runde ' . $r['round'] . ($r['round'] === $r['current_round'] ? ' (laufend, ' . ($r['visible'] ? 'sichtbar' : 'verborgen') . ')' : ' (abgeschlossen)')
        . ', ' . $r['responses'] . ' Personen, ' . $r['entries'] . ' Einträge, ' . $r['distinct'] . " verschiedene Grundformen. Zahlen = Personen pro Begriff.\n";
    if (!empty($a['note'])) $out .= "\nNotiz: " . $a['note'] . "\n";
    if ($r['grouping']['label']) $out .= "\nLetzte Einteilung: " . $r['grouping']['label'] . ' (' . $r['grouping']['actor'] . ', ' . $r['grouping']['at'] . ")\n";
    $out .= "\n| Begriff | n | zusammengelegt aus (Eingabe: n) | |\n|---|---|---|---|\n";
    foreach ($r['terms'] as $t) {
        $vs = count($t['variants']) > 1 || $t['label'] !== $t['variants'][0]['text']
            ? implode(', ', array_map(fn($v) => $v['text'] . ': ' . $v['n'], $t['variants']))
            : '';
        $flags = trim(($t['hidden'] ? 'ausgeblendet ' : '') . ($t['fixed'] ? 'von Hand' : ''));
        $out .= '| ' . str_replace('|', '/', $t['label']) . ' | ' . $t['n'] . ' | ' . str_replace('|', '/', $vs) . ' | ' . $flags . " |\n";
    }
    if (count($r['rounds']) > 1) {
        $out .= "\nRunden: " . implode(', ', array_map(fn($x) => $x['round'] . ' (' . $x['responses'] . ($x['current'] ? ', laufend' : '') . ')', $r['rounds'])) . "\n";
    }
    return $out;
}

/**
 * Zusammenlegen durch Claude (Schnittstelle und Connector): {groups: [{label, members: [Texte oder Grundformen], hidden}]}.
 * Ersetzt wie die KI alle nicht von Hand festgelegten Gruppen; Begriffe in festen Gruppen und unbekannte fallen weg.
 */
function wordcloud_merge_from_input(array $a, array $in): array {
    $groups = $in['groups'] ?? null;
    if (!is_array($groups)) throw new InvalidArgumentException('groups fehlt: Liste von {label, members, hidden}; members sind Begriffe, wie sie read_wordcloud zeigt.');
    $entries = wordcloud_entries($a, $a['round']);
    $spec = [];
    $unknown = [];
    foreach ($groups as $g) {
        if (!is_array($g)) continue;
        $keys = [];
        foreach ((array)($g['members'] ?? []) as $m) {
            $k = wordcloud_key_for($entries, $m);
            if ($k === null) { $unknown[] = is_scalar($m) ? (string)$m : '?'; continue; }
            $keys[] = $k;
        }
        $spec[] = ['label' => (string)($g['label'] ?? ''), 'members' => $keys, 'hidden' => !empty($g['hidden'])];
    }
    $lang = $a['lang'];
    return wordcloud_apply_merge($a, $spec, [], wordcloud_actor(), fn(int $from, int $to) => t('wc_log_claude', $lang, ['from' => $from, 'to' => $to])) + ['unknown' => $unknown];
}
