<?php
declare(strict_types=1);

/**
 * Inhaltlicher Export ohne Personenangaben (Version 0.10). Sammelt die Inhalte eines Boards so, dass weder
 * Fantasienamen noch Browser-Schlüssel noch Zeitstempel einzelner Beiträge enthalten sind. Beiträge und
 * Kommentare des Besitzers tragen nur die Markierung owner (auf der Exportseite «Impuls»).
 * Grundlage für die Exportseite export.php und für die KI-Zusammenfassung in lib/summary.php.
 */

/**
 * @return array{columns: array, bundles: array, posts: array, stats: array}
 *   posts: [{id, n, column, bundle_id, title, body, link, image, thumb, owner, reactions: [[emoji, count]], comments: [{id, body, owner}]}]
 *   n ist die laufende Nummer in Leserichtung nach Spalten und Bündeln; die Exportseite nummeriert nach der
 *   gewählten Ordnung neu (export_number), die KI-Belege speichern Beitrags-IDs und werden beim Anzeigen umgerechnet.
 */
function export_collect(array $b): array {
    $isColumns = $b['type'] === 'columns';
    $columnIds = array_column($b['columns'], 'id');
    $colOf = fn(?string $c): ?string => !$isColumns ? null : (in_array($c, $columnIds, true) ? $c : ($columnIds[0] ?? null));

    $bundles = db_all('SELECT id, column_id, title, summary FROM bundles WHERE board_id = ? AND deleted_at IS NULL ORDER BY position ASC, id ASC', [$b['id']]);
    $bundleById = [];
    foreach ($bundles as $g) {
        $bundleById[(int)$g['id']] = ['id' => (int)$g['id'], 'column' => $colOf($g['column_id']), 'title' => (string)$g['title'], 'summary' => (string)($g['summary'] ?? ''), 'post_ids' => []];
    }

    $posts = db_all('SELECT id, column_id, bundle_id, title, body, link, image, author_key, created_at FROM posts WHERE board_id = ? AND deleted_at IS NULL ORDER BY position ASC, id ASC', [$b['id']]);
    $comments = db_all('SELECT id, post_id, body, author_key FROM comments WHERE board_id = ? AND deleted_at IS NULL ORDER BY id ASC', [$b['id']]);
    $reactions = db_all('SELECT target_type, target_id, emoji, COUNT(*) AS n FROM reactions WHERE board_id = ? GROUP BY target_type, target_id, emoji', [$b['id']]);
    $emojiOrder = array_flip(wb_emojis());
    $rx = [];
    foreach ($reactions as $r) {
        if ($r['target_type'] !== 'post') continue;
        $rx[(int)$r['target_id']][] = [$r['emoji'], (int)$r['n']];
    }
    foreach ($rx as &$list) usort($list, fn($a, $z) => ($emojiOrder[$a[0]] ?? 99) <=> ($emojiOrder[$z[0]] ?? 99));
    unset($list);
    $byPost = [];
    foreach ($comments as $c) {
        $byPost[(int)$c['post_id']][] = ['id' => (int)$c['id'], 'body' => (string)$c['body'], 'owner' => $c['author_key'] === WB_OWNER_KEY];
    }

    $out = [];
    $first = null; $last = null; $nComments = 0;
    foreach ($posts as $p) {
        $id = (int)$p['id'];
        $bid = $p['bundle_id'] !== null && isset($bundleById[(int)$p['bundle_id']]) ? (int)$p['bundle_id'] : null;
        if ($bid !== null) $bundleById[$bid]['post_ids'][] = $id;
        $cs = $byPost[$id] ?? [];
        $nComments += count($cs);
        $first = $first === null || $p['created_at'] < $first ? $p['created_at'] : $first;
        $last = $last === null || $p['created_at'] > $last ? $p['created_at'] : $last;
        $out[] = [
            'id' => $id,
            'n' => 0,
            'column' => $colOf($p['column_id']),
            'bundle_id' => $bid,
            'title' => (string)($p['title'] ?? ''),
            'body' => (string)$p['body'],
            'link' => $p['link'] ?: null,
            'image' => board_image_url($b['bkey'], $p['image']),
            'thumb' => board_image_url($b['bkey'], $p['image'], true),
            'owner' => $p['author_key'] === WB_OWNER_KEY,
            'created_at' => (string)$p['created_at'],
            'reactions' => $rx[$id] ?? [],
            'comments' => $cs,
        ];
    }

    // Laufende Nummern in Leserichtung: Spalten in ihrer Reihenfolge, darin Bündel, dann lose Beiträge.
    $ordered = export_order($b, $out, $bundleById, 'columns');
    $n = 0;
    $numById = [];
    foreach ($ordered as $p) $numById[$p['id']] = ++$n;
    foreach ($out as &$p) $p['n'] = $numById[$p['id']];
    unset($p);

    return [
        'columns' => $b['columns'],
        'bundles' => array_values($bundleById),
        'posts' => $out,
        'stats' => [
            'posts' => count($out),
            'student_posts' => count(array_filter($out, fn($p) => !$p['owner'])),
            'comments' => $nComments,
            'bundles' => count($bundleById),
            'columns' => $isColumns ? count($b['columns']) : 0,
            'first' => $first,
            'last' => $last,
        ],
    ];
}

/**
 * Ordnet Beiträge für die Anzeige. $group: columns (Spalte → Bündel → lose), bundles (Bündel → lose, Spalten
 * werden ignoriert) oder time (Reihenfolge der Erstellung).
 */
function export_order(array $b, array $posts, array $bundleById, string $group): array {
    if ($group === 'time') {
        usort($posts, fn($a, $z) => [$a['created_at'], $a['id']] <=> [$z['created_at'], $z['id']]);
        return $posts;
    }
    $bundleIndex = array_flip(array_keys($bundleById));
    $colIndex = array_flip(array_column($b['columns'], 'id'));
    $useCols = $group === 'columns' && $b['type'] === 'columns';
    $key = function (array $p) use ($bundleIndex, $colIndex, $useCols) {
        return [
            $useCols ? ($colIndex[$p['column']] ?? 999) : 0,
            // Impulse (lose Beiträge des Besitzers) zuerst, dann Bündel, dann lose Beiträge.
            $p['bundle_id'] !== null ? 1 : ($p['owner'] ? 0 : 2),
            $p['bundle_id'] === null ? 0 : ($bundleIndex[$p['bundle_id']] ?? 999),
        ];
    };
    $pos = array_flip(array_column($posts, 'id'));
    usort($posts, fn($a, $z) => [$key($a), $pos[$a['id']]] <=> [$key($z), $pos[$z['id']]]);
    return $posts;
}

/** Zeitraum «3.–5.11.2026» bzw. «5.11.2026» aus UTC-Zeitstempeln in lokaler Zeit. */
function export_period(?string $first, ?string $last): string {
    if (!$first) return '';
    $tz = new DateTimeZone((string)wb_config('timezone', 'Europe/Zurich'));
    $a = (new DateTime($first, new DateTimeZone('UTC')))->setTimezone($tz);
    $z = (new DateTime((string)$last, new DateTimeZone('UTC')))->setTimezone($tz);
    if ($a->format('Y-m-d') === $z->format('Y-m-d')) return $a->format('j.n.Y');
    if ($a->format('Y') === $z->format('Y')) {
        return $a->format('Y-m') === $z->format('Y-m') ? $a->format('j.') . '–' . $z->format('j.n.Y') : $a->format('j.n.') . '–' . $z->format('j.n.Y');
    }
    return $a->format('j.n.Y') . '–' . $z->format('j.n.Y');
}

/** Nummeriert die Beiträge in der angezeigten Reihenfolge: [post_id => laufende Nummer]. */
function export_number(array $orderedPosts): array {
    $out = [];
    foreach (array_values($orderedPosts) as $i => $p) $out[$p['id']] = $i + 1;
    return $out;
}
