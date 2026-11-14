<?php
declare(strict_types=1);

/**
 * Adapter Fake: liefert ohne Netz eine deterministische Einteilung für lokale Tests.
 *
 * Er liest die Beitragsliste aus dem Benutzertext (Zeilen der Form «#12 [Spalte: abc] Titel — Text» zwischen
 * <beitraege> und </beitraege>) und bestehende Bündel («B#3 [Spalte: abc] Titel») zwischen <buendel> und
 * </buendel>, bildet pro Spalte Bündel zu je drei Beiträgen und lässt den letzten Beitrag jeder Spalte lose,
 * wenn mehr als drei vorhanden sind. Beiträge, deren Text «lose» enthält, bleiben immer lose.
 *
 * Fehlerarten lassen sich über den API-Schlüssel auslösen: fake-invalid, fake-limit, fake-overloaded,
 * fake-timeout, fake-garbage (ungültige Antwort). Der Schlüssel fake-slow wartet zwei Sekunden.
 */

function ai_fake_structured(array $credential, string $system, string $user, array $schema, array $options): array {
    $key = (string)($credential['api_key'] ?? '');
    switch ($key) {
        case 'fake-invalid': throw new AiException('invalid_key', 'Fake: Schlüssel ungültig.');
        case 'fake-limit': throw new AiException('rate_limit', 'Fake: Limit erreicht.');
        case 'fake-overloaded': throw new AiException('overloaded', 'Fake: überlastet.');
        case 'fake-timeout': throw new AiException('timeout', 'Fake: Zeitüberschreitung.');
        case 'fake-garbage': return ['data' => ['unsinn' => true], 'input_tokens' => 10, 'output_tokens' => 2, 'model' => 'fake-1'];
        case 'fake-slow': sleep(2); break;
    }
    if (($options['tool_name'] ?? '') === 'zusammenfassung') return ai_fake_summary($credential, $system, $user, $options);
    if (($options['tool_name'] ?? '') === 'wortwolke') return ai_fake_wordcloud($user);
    if (($options['tool_name'] ?? '') === 'korrektur') return ai_fake_exam_grade($user);
    if (($options['tool_name'] ?? '') === 'fragenpruefung') return ai_fake_exam_check($user);
    $posts = [];
    if (preg_match('~<beitraege>(.*?)</beitraege>~su', $user, $m)) {
        foreach (preg_split('/\n+/', trim($m[1])) as $line) {
            if (preg_match('/^#(\d+)\s*(?:\[Spalte:\s*([^\]]*)\])?\s*(.*)$/u', trim($line), $pm)) {
                $posts[] = ['id' => (int)$pm[1], 'column' => $pm[2] !== '' ? trim($pm[2]) : null, 'text' => $pm[3]];
            }
        }
    }
    $existing = [];
    if (preg_match('~<buendel>(.*?)</buendel>~su', $user, $m)) {
        foreach (preg_split('/\n+/', trim($m[1])) as $line) {
            if (preg_match('/^B#(\d+)\s*(?:\[Spalte:\s*([^\]]*)\])?\s*(.*)$/u', trim($line), $bm)) {
                $existing[] = ['id' => (int)$bm[1], 'column' => $bm[2] !== '' ? trim($bm[2]) : null, 'title' => $bm[3]];
            }
        }
    }
    $byCol = [];
    foreach ($posts as $p) {
        if (mb_stripos($p['text'], 'lose') !== false) continue; // bleibt absichtlich lose
        $byCol[$p['column'] ?? ''][] = $p['id'];
    }
    $props = $schema['properties'] ?? [];
    $mode = isset($props['assign']) ? 'loose' : 'all';
    $data = $mode === 'all' ? ['bundles' => []] : ['assign' => [], 'new_bundles' => []];
    $n = 0;
    foreach ($byCol as $col => $ids) {
        if (count($ids) > 3) array_pop($ids); // einer bleibt lose
        $chunks = array_chunk($ids, 3);
        if ($mode === 'loose') {
            // Erste Gruppe ins erste bestehende Bündel derselben Spalte, Rest als neue Bündel.
            $target = null;
            foreach ($existing as $g) { if (($g['column'] ?? '') === (string)$col) { $target = $g['id']; break; } }
            if ($target !== null && $chunks) {
                $data['assign'][] = ['bundle_id' => $target, 'post_ids' => array_shift($chunks)];
            }
        }
        foreach ($chunks as $chunk) {
            $n++;
            $b = ['title' => 'Thema ' . $n, 'summary' => 'Fake-Bündel mit ' . count($chunk) . ' Beiträgen.', 'post_ids' => $chunk];
            if ($col !== '') $b['column_id'] = (string)$col;
            if ($mode === 'all') $data['bundles'][] = $b; else $data['new_bundles'][] = $b;
        }
    }
    return [
        'data' => $data,
        'input_tokens' => (int)(mb_strlen($system . $user) / 4),
        'output_tokens' => (int)(strlen(json_encode($data)) / 4),
        'model' => (string)($options['model'] ?? $credential['model'] ?? 'fake-1'),
    ];
}

function ai_fake_models(string $apiKey): array {
    if ($apiKey === 'fake-invalid') throw new AiException('invalid_key', 'Fake: Schlüssel ungültig.');
    return [['id' => 'fake-1', 'name' => 'Fake 1 (deterministisch)'], ['id' => 'fake-2', 'name' => 'Fake 2']];
}

/**
 * Zusammenfassung (lib/summary.php): ein Abschnitt pro Einheit aus <einheiten> bzw. zwei Themen-Abschnitte,
 * Belege aus den Beitragsnummern, ein wörtliches Zitat aus dem ersten Beitrag (die ersten sechs Wörter) und ein
 * erfundenes Zitat, das die Prüfung verwerfen muss. Beiträge mit «lose» erhalten keinen Beleg.
 */
function ai_fake_summary(array $credential, string $system, string $user, array $options): array {
    $posts = [];
    if (preg_match('~<beitraege>(.*?)</beitraege>~su', $user, $m)) {
        foreach (preg_split('/\n+/', trim($m[1])) as $line) {
            if (preg_match('/^#(\d+)\s*(?:\[(?:Einheit|Spalte):\s*([^\]]*)\])?\s*(?:\[Impuls\])?\s*(.*)$/u', trim($line), $pm)) {
                $posts[] = ['id' => (int)$pm[1], 'unit' => trim($pm[2]), 'text' => $pm[3]];
            }
        }
    }
    $units = [];
    if (preg_match('~<einheiten>(.*?)</einheiten>~su', $user, $m)) {
        foreach (preg_split('/\n+/', trim($m[1])) as $line) if (preg_match('/^(\S+):\s*(.*)$/u', trim($line), $um)) $units[] = $um[1];
    }
    $sections = [];
    if ($units) {
        foreach ($units as $u) {
            $ids = array_values(array_map(fn($p) => $p['id'], array_filter($posts, fn($p) => $p['unit'] === $u && mb_stripos($p['text'], 'lose') === false)));
            $sections[] = ['unit' => $u, 'title' => 'Einheit ' . $u, 'paragraphs' => [['text' => 'Zusammenfassung der Einheit ' . $u . ' mit ' . count($ids) . ' Beiträgen.', 'refs' => $ids]]];
        }
        $sections[] = ['unit' => 'gibt-es-nicht', 'title' => 'Falsch', 'paragraphs' => [['text' => 'Muss verworfen werden.', 'refs' => [1]]]];
    } else {
        $half = (int)ceil(count($posts) / 2);
        foreach ([array_slice($posts, 0, $half), array_slice($posts, $half)] as $i => $chunk) {
            if (!$chunk) continue;
            $sections[] = ['title' => 'Thema ' . ($i + 1), 'paragraphs' => [['text' => 'Fake-Abschnitt mit ' . count($chunk) . ' Beiträgen.', 'refs' => array_merge(array_map(fn($p) => $p['id'], $chunk), [999999])]]];
        }
    }
    $quotes = [];
    if ($posts) {
        $words = preg_split('/\s+/u', trim(preg_replace('/^.*? — /u', '', $posts[0]['text']) ?? ''));
        $quotes[] = ['text' => '«' . implode(' ', array_slice($words, 0, 6)) . '»', 'ref' => $posts[0]['id']];
        $quotes[] = ['text' => 'Dieses Zitat steht in keinem Beitrag.', 'ref' => $posts[0]['id']];
    }
    $data = [
        'intro' => 'Fake-Einleitung über ' . count($posts) . ' Beiträge.',
        'sections' => $sections,
        'disagreements' => $posts ? [['text' => 'Fake-Uneinigkeit.', 'refs' => [$posts[0]['id']]]] : [],
        'open_questions' => [['text' => 'Fake-Frage ohne Beleg?', 'refs' => []]],
        'quotes' => $quotes,
    ];
    return [
        'data' => $data,
        'input_tokens' => (int)(mb_strlen($system . $user) / 4),
        'output_tokens' => (int)(strlen(json_encode($data)) / 4),
        'model' => (string)($options['model'] ?? $credential['model'] ?? 'fake-1'),
    ];
}

/**
 * Wortwolke ohne Netz: legt Begriffe mit gleichen ersten vier Buchstaben zusammen (Anzeigebegriff = häufigster),
 * korrigiert «Fahrad» zu «Fahrrad», ordnet Begriffe mit «fest» der ersten festen Gruppe zu und liefert zum Prüfen
 * eine unbekannte und eine doppelte Nummer.
 */
function ai_fake_wordcloud(string $user): array {
    $terms = [];
    if (preg_match('~<begriffe>(.*?)</begriffe>~su', $user, $m)) {
        foreach (preg_split('/\n+/', trim($m[1])) as $line) {
            if (preg_match('/^#(\d+)\s+(.*)\s+\((\d+)\)$/u', trim($line), $tm)) $terms[] = ['n' => (int)$tm[1], 'text' => $tm[2], 'c' => (int)$tm[3]];
        }
    }
    $hasFixed = str_contains($user, '<feste_gruppen>');
    $buckets = [];
    $add = [];
    $groups = [];
    foreach ($terms as $t) {
        if ($hasFixed && mb_stripos($t['text'], 'fest') !== false) { $add[] = $t['n']; continue; }
        if (mb_strtolower($t['text']) === 'fahrad') { $groups[] = ['label' => 'Fahrrad', 'members' => [$t['n']]]; continue; }
        $buckets[mb_substr(mb_strtolower($t['text']), 0, 4)][] = $t;
    }
    foreach ($buckets as $b) {
        if (count($b) < 2) continue;
        usort($b, fn($x, $y) => $y['c'] <=> $x['c']);
        $groups[] = ['label' => $b[0]['text'], 'members' => array_column($b, 'n')];
    }
    if ($groups) $groups[0]['members'] = array_merge($groups[0]['members'], [9999, $groups[0]['members'][0]]);
    return ['data' => ['groups' => $groups, 'add_to_fixed' => $add ? [['group' => 1, 'members' => $add]] : []], 'input_tokens' => 50 + 5 * count($terms), 'output_tokens' => 20 + 4 * count($terms), 'model' => 'fake-1'];
}

/** Legt den letzten Benutzertext eines Werkzeugs ab, damit der Rauchtest prüfen kann, was an die KI ging. */
function ai_fake_dump(string $tool, string $user): void {
    @file_put_contents(sys_get_temp_dir() . '/tafula-fake-' . $tool . '.txt', $user);
}

/**
 * Werkzeug «korrektur»: Stufe nach Anteil der Wörter des Lösungssatzes (ab vier Buchstaben), die in der Antwort
 * vorkommen (ab 60 % full, ab 30 % half, sonst 0). Antworten mit «FAKE-UNGUELTIG» erhalten eine ungültige Stufe.
 * Am Ende stehen absichtlich eine doppelte und eine unbekannte Nummer, die die Prüfung verwerfen muss.
 */
function ai_fake_exam_grade(string $user): array {
    ai_fake_dump('korrektur', $user);
    $words = function (string $s): array {
        preg_match_all('/[\p{L}\p{N}]{4,}/u', mb_strtolower($s), $m);
        return array_values(array_unique($m[0]));
    };
    $sol = preg_match('~<loesung>(.*?)</loesung>~su', $user, $m) ? $words($m[1]) : [];
    $grades = [];
    if (preg_match('~<antworten>(.*?)</antworten>~su', $user, $m)) {
        foreach (preg_split('/\n+/', trim($m[1])) as $line) {
            if (!preg_match('/^#(\d+)\s*(.*)$/u', trim($line), $lm)) continue;
            $ans = $words($lm[2]);
            $hit = $sol ? count(array_intersect($sol, $ans)) / count($sol) : 0;
            $level = $hit >= 0.6 ? 'full' : ($hit >= 0.3 ? 'half' : '0');
            if (str_contains($lm[2], 'FAKE-UNGUELTIG')) $level = 'maximal';
            $grades[] = ['n' => (int)$lm[1], 'level' => $level, 'reason' => 'Fake: ' . round(100 * $hit) . ' % der Schlüsselwörter.'];
        }
    }
    $grades[] = ['n' => 1, 'level' => 'full', 'reason' => 'Doppelt, muss verworfen werden.'];
    $grades[] = ['n' => 999, 'level' => 'full', 'reason' => 'Unbekannt, muss verworfen werden.'];
    return ['data' => ['grades' => $grades], 'input_tokens' => (int)(mb_strlen($user) / 4), 'output_tokens' => count($grades) * 20, 'model' => 'fake-1'];
}

/**
 * Werkzeug «fragenpruefung»: duplicate bei gleichem Fragetext wie eine frühere Frage, unclear bei Fragen unter
 * 20 Zeichen, solution bei «FAKE-LOESUNG» in der Frage; dazu eine unbekannte Nummer und eine unbekannte Art.
 */
function ai_fake_exam_check(string $user): array {
    ai_fake_dump('fragenpruefung', $user);
    $issues = [];
    $seen = [];
    if (preg_match('~<fragen>(.*?)</fragen>~su', $user, $m)) {
        foreach (preg_split('/\n+/', $m[1]) as $line) {
            if (!preg_match('/^#(\d+)\s*\[[^\]]*\]\s*(.*)$/u', trim($line), $lm)) continue;
            $n = (int)$lm[1];
            $norm = mb_strtolower(trim($lm[2]));
            if (isset($seen[$norm])) $issues[] = ['n' => $n, 'kind' => 'duplicate', 'text' => 'Gleich wie eine frühere Frage.', 'duplicate_of' => $seen[$norm]];
            else $seen[$norm] = $n;
            if (mb_strlen($norm) < 20) $issues[] = ['n' => $n, 'kind' => 'unclear', 'text' => 'Sehr kurz, vielleicht unklar.'];
            if (str_contains($lm[2], 'FAKE-LOESUNG')) $issues[] = ['n' => $n, 'kind' => 'solution', 'text' => 'Die Lösung scheint falsch.'];
        }
    }
    $issues[] = ['n' => 999, 'kind' => 'unclear', 'text' => 'Unbekannte Nummer.'];
    $issues[] = ['n' => 1, 'kind' => 'boese', 'text' => 'Unbekannte Art.'];
    return ['data' => ['issues' => $issues], 'input_tokens' => (int)(mb_strlen($user) / 4), 'output_tokens' => 50, 'model' => 'fake-1'];
}
