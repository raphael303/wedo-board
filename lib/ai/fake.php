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
