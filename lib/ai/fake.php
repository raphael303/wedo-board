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
