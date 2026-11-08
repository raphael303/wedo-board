<?php
declare(strict_types=1);

/**
 * Bündel: eine Organisationsebene über den Beiträgen. Nur Besitzer (Bibliothek, Schnittstelle, Connector)
 * legen Bündel an; Beiträge werden ihnen zugeordnet und bleiben dabei unverändert.
 */

const WB_OWNER_KEY = 'owner-00000000000000000000000000';

function bundle_by_id(array $board, int $id): ?array {
    $g = db_row('SELECT * FROM bundles WHERE id = ? AND board_id = ? AND deleted_at IS NULL', [$id, $board['id']]);
    return $g ?: null;
}

function bundle_column(array $board, $column): ?string {
    if ($board['type'] !== 'columns') return null;
    $ids = array_column($board['columns'], 'id');
    if ($column === null || $column === '') return $ids[0] ?? null;
    if (in_array($column, $ids, true)) return (string)$column;
    // Spaltentitel akzeptieren (für den Connector).
    foreach ($board['columns'] as $c) {
        if (mb_strtolower($c['title']) === mb_strtolower((string)$column)) return $c['id'];
    }
    return $ids[0] ?? null;
}

function bundle_create(array $board, array $in): array {
    $title = wb_clean_text($in['title'] ?? '', 200);
    if ($title === '') throw new InvalidArgumentException('Titel des Bündels fehlt.');
    $summary = wb_clean_text($in['summary'] ?? '', 5000);
    $author = wb_clean_text($in['author'] ?? '', 80);
    $column = bundle_column($board, $in['column'] ?? null);
    $now = wb_now();
    $row = db_row('SELECT MAX(position) AS p FROM bundles WHERE board_id = ?', [$board['id']]);
    $pos = (int)($row['p'] ?? 0) + 1;
    $id = db_insert('INSERT INTO bundles (board_id, column_id, title, summary, author_name, position, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$board['id'], $column, $title, $summary !== '' ? $summary : null, $author, $pos, $now, $now]);
    if (!empty($in['post_ids']) && is_array($in['post_ids'])) {
        bundle_assign($board, $id, array_map('intval', $in['post_ids']));
    }
    board_touch($board['id']);
    return bundle_by_id($board, $id);
}

function bundle_update(array $board, array $g, array $in, ?int &$undoId = null): array {
    // Vorher festhalten: das Bündel und alle Beiträge, die betroffen sein können.
    $affected = array_map('intval', array_column(db_all('SELECT id FROM posts WHERE bundle_id = ? AND deleted_at IS NULL', [$g['id']]), 'id'));
    foreach (['post_ids', 'add_post_ids', 'remove_post_ids'] as $k) {
        if (!empty($in[$k]) && is_array($in[$k])) $affected = array_merge($affected, array_map('intval', $in[$k]));
    }
    $snap = undo_snapshot($board, [(int)$g['id']], array_values(array_unique($affected)));
    $result = undo_suspend(fn() => bundle_update_inner($board, $g, $in));
    $changed = array_intersect_key($in, array_flip(['title', 'summary', 'column', 'position', 'post_ids', 'add_post_ids', 'remove_post_ids'])) !== [];
    if ($changed) {
        $undoId = undo_log($board, 'bundle_update', t('log_bundle_update', $board['lang'], ['title' => $g['title']]), $snap);
    }
    return $result;
}

function bundle_update_inner(array $board, array $g, array $in): array {
    $fields = [];
    $params = [];
    if (array_key_exists('title', $in)) {
        $t = wb_clean_text($in['title'], 200);
        if ($t === '') throw new InvalidArgumentException('Titel des Bündels fehlt.');
        $fields[] = 'title = ?'; $params[] = $t;
    }
    if (array_key_exists('summary', $in)) {
        $sm = wb_clean_text($in['summary'], 5000);
        $fields[] = 'summary = ?'; $params[] = $sm !== '' ? $sm : null;
    }
    if (array_key_exists('column', $in) && $board['type'] === 'columns') {
        $col = bundle_column($board, $in['column']);
        $fields[] = 'column_id = ?'; $params[] = $col;
        // Beiträge im Bündel wandern mit.
        db_exec('UPDATE posts SET column_id = ?, updated_at = ? WHERE bundle_id = ? AND deleted_at IS NULL', [$col, wb_now(), $g['id']]);
    }
    if (array_key_exists('position', $in)) {
        $fields[] = 'position = ?'; $params[] = (int)$in['position'];
    }
    if ($fields) {
        $fields[] = 'updated_at = ?'; $params[] = wb_now();
        $params[] = $g['id'];
        db_exec('UPDATE bundles SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
    }
    if (isset($in['post_ids']) && is_array($in['post_ids'])) {
        // Vollständige neue Zuordnung: alles andere fällt aus dem Bündel.
        $now = wb_now();
        db_exec('UPDATE posts SET bundle_id = NULL, updated_at = ? WHERE bundle_id = ? AND deleted_at IS NULL', [$now, $g['id']]);
        bundle_assign($board, (int)$g['id'], array_map('intval', $in['post_ids']));
    }
    if (!empty($in['add_post_ids']) && is_array($in['add_post_ids'])) {
        bundle_assign($board, (int)$g['id'], array_map('intval', $in['add_post_ids']));
    }
    if (!empty($in['remove_post_ids']) && is_array($in['remove_post_ids'])) {
        bundle_assign($board, null, array_map('intval', $in['remove_post_ids']));
    }
    board_touch($board['id']);
    return bundle_by_id($board, (int)$g['id']);
}

function bundle_delete(array $board, array $g): int {
    $postIds = array_map('intval', array_column(db_all('SELECT id FROM posts WHERE bundle_id = ? AND deleted_at IS NULL', [$g['id']]), 'id'));
    $snap = undo_snapshot($board, [(int)$g['id']], $postIds);
    $now = wb_now();
    db_exec('UPDATE posts SET bundle_id = NULL, updated_at = ? WHERE bundle_id = ? AND deleted_at IS NULL', [$now, $g['id']]);
    db_exec('UPDATE bundles SET deleted_at = ?, updated_at = ? WHERE id = ?', [$now, $now, $g['id']]);
    board_touch($board['id']);
    return undo_log($board, 'bundle_delete', t('log_bundle_delete', $board['lang'], ['title' => $g['title']]), $snap);
}

/**
 * Ordnet Beiträge einem Bündel zu (oder löst sie mit null). Beiträge übernehmen die Spalte des Bündels.
 */
function bundle_assign(array $board, ?int $bundleId, array $postIds, ?int &$undoId = null): int {
    $postIds = array_values(array_unique(array_filter($postIds, fn($i) => $i > 0)));
    if (!$postIds) return 0;
    $now = wb_now();
    $n = 0;
    $snap = undo_snapshot($board, [], $postIds);
    if ($bundleId !== null) {
        $g = bundle_by_id($board, $bundleId);
        if (!$g) throw new InvalidArgumentException('Bündel nicht gefunden.');
        foreach ($postIds as $pid) {
            $set = 'bundle_id = ?, updated_at = ?';
            $params = [$bundleId, $now];
            if ($board['type'] === 'columns' && $g['column_id'] !== null) { $set .= ', column_id = ?'; $params[] = $g['column_id']; }
            $params[] = $pid; $params[] = $board['id'];
            $n += db_exec("UPDATE posts SET $set WHERE id = ? AND board_id = ? AND deleted_at IS NULL", $params);
        }
    } else {
        foreach ($postIds as $pid) {
            $n += db_exec('UPDATE posts SET bundle_id = NULL, updated_at = ? WHERE id = ? AND board_id = ? AND deleted_at IS NULL', [$now, $pid, $board['id']]);
        }
    }
    board_touch($board['id']);
    if ($n > 0) {
        $undoId = undo_log($board, 'assign', t($bundleId !== null ? 'log_assign' : 'log_release', $board['lang'], ['n' => $n]), $snap);
    }
    return $n;
}

/**
 * Ganze Einteilung in einem Zug: bestehende Bündel werden ersetzt, wenn $replace gesetzt ist.
 */
function bundle_organize(array $board, array $bundles, bool $replace, string $author, ?int &$undoId = null): array {
    $snap = undo_snapshot($board);
    $snap['full'] = $replace;
    $created = undo_suspend(function () use ($board, $bundles, $replace, $author) {
        if ($replace) {
            foreach (db_all('SELECT * FROM bundles WHERE board_id = ? AND deleted_at IS NULL', [$board['id']]) as $g) {
                bundle_delete($board, $g);
            }
        }
        $created = [];
        foreach ($bundles as $spec) {
            if (!is_array($spec)) continue;
            $spec['author'] = $spec['author'] ?? $author;
            $created[] = bundle_public(bundle_create($board, $spec));
        }
        return $created;
    });
    $undoId = undo_log($board, 'organize', t('log_organize', $board['lang'], ['n' => count($created)]), $snap);
    return $created;
}

/* ---------- Beiträge und Kommentare durch den Besitzer (Claude, Lehrperson) ---------- */

function owner_post_create(array $board, array $in): array {
    $title = wb_clean_text($in['title'] ?? '', 200);
    $body = wb_clean_text($in['body'] ?? '', 5000);
    $link = wb_clean_url($in['link'] ?? null);
    if ($body === '' && $link === null && $title === '') throw new InvalidArgumentException('Text fehlt.');
    $author = wb_clean_text($in['author'] ?? '', 80);
    if ($author === '') $author = 'Claude';
    $column = bundle_column($board, $in['column'] ?? null);
    $bundleId = null;
    if (!empty($in['bundle_id'])) {
        $g = bundle_by_id($board, (int)$in['bundle_id']);
        if (!$g) throw new InvalidArgumentException('Bündel nicht gefunden.');
        $bundleId = (int)$g['id'];
        if ($board['type'] === 'columns') $column = $g['column_id'];
    }
    $now = wb_now();
    $onTop = array_key_exists('on_top', $in) ? filter_var($in['on_top'], FILTER_VALIDATE_BOOLEAN) : $board['settings']['new_on_top'];
    $row = db_row($onTop ? 'SELECT MIN(position) AS p FROM posts WHERE board_id = ?' : 'SELECT MAX(position) AS p FROM posts WHERE board_id = ?', [$board['id']]);
    $pos = (int)($row['p'] ?? 0) + ($onTop ? -1 : 1);
    $id = db_insert('INSERT INTO posts (board_id, column_id, bundle_id, title, body, link, author_name, author_key, position, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$board['id'], $column, $bundleId, $title !== '' ? $title : null, $body, $link, $author, WB_OWNER_KEY, $pos, $now, $now]);
    board_touch($board['id']);
    return db_row('SELECT * FROM posts WHERE id = ?', [$id]);
}

function owner_comment_create(array $board, int $postId, array $in): array {
    $post = db_row('SELECT id FROM posts WHERE id = ? AND board_id = ? AND deleted_at IS NULL', [$postId, $board['id']]);
    if (!$post) throw new InvalidArgumentException('Beitrag nicht gefunden.');
    $body = wb_clean_text($in['body'] ?? '', 2000);
    if ($body === '') throw new InvalidArgumentException('Text fehlt.');
    $author = wb_clean_text($in['author'] ?? '', 80);
    if ($author === '') $author = 'Claude';
    $now = wb_now();
    $id = db_insert('INSERT INTO comments (board_id, post_id, body, author_name, author_key, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$board['id'], $postId, $body, $author, WB_OWNER_KEY, $now, $now]);
    board_touch($board['id']);
    return db_row('SELECT * FROM comments WHERE id = ?', [$id]);
}
