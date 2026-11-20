<?php
declare(strict_types=1);

/**
 * Gäste sperren (Etappe 5c). Eine Sperre bezieht sich auf den Browser-Schlüssel wb_uid eines Gastes,
 * auf ein Board oder auf alle Boards. Gesperrte Gäste lesen weiter, schreiben aber nicht mehr.
 * Das optionale Ausblenden der Inhalte löscht weich und läuft über das Änderungsprotokoll,
 * sodass Rückgängig oder Entsperren die Inhalte zurückbringt.
 */

/** Aktive Sperre eines Gastes für ein Board (board-eigene oder globale), sonst null. */
function guest_block_active(string $uid, array $board): ?array {
    if ($uid === '' || $uid === WB_OWNER_KEY) return null;
    // «Auf allen Boards» gilt für die Boards der Person, die gesperrt hat, nicht systemweit.
    return db_row('SELECT * FROM guest_blocks WHERE guest_key = ? AND lifted_at IS NULL AND (board_id = ? OR (board_id IS NULL AND user_id = ?)) ORDER BY board_id IS NULL DESC, id DESC LIMIT 1',
        [$uid, (int)$board['id'], (int)$board['owner_user_id']]) ?: null;
}

/**
 * Sperrt einen Gast. $allBoards gilt für alle Boards; $hide blendet seine Beiträge und Kommentare
 * auf dem aktuellen Board aus (weich). Gibt die Sperre mit undo_id zurück.
 */
function guest_block_create(array $board, string $uid, string $displayName, bool $allBoards, bool $hide): array {
    if (!preg_match('/^[a-f0-9]{32}$/', $uid)) throw new InvalidArgumentException('Ungültiger Gast-Schlüssel.');
    $now = wb_now();
    $displayName = wb_clean_text($displayName, 80);
    if ($displayName === '') {
        $row = db_row('SELECT author_name FROM posts WHERE board_id = ? AND author_key = ? ORDER BY id DESC LIMIT 1', [$board['id'], $uid])
            ?? db_row('SELECT author_name FROM comments WHERE board_id = ? AND author_key = ? ORDER BY id DESC LIMIT 1', [$board['id'], $uid]);
        $displayName = $row['author_name'] ?? wb_guest_name($board, $uid);
    }
    // Eine bestehende gleichwertige Sperre wird nicht verdoppelt.
    $existing = db_row('SELECT * FROM guest_blocks WHERE guest_key = ? AND lifted_at IS NULL AND ' . ($allBoards ? 'board_id IS NULL AND user_id = ?' : 'board_id = ?'), $allBoards ? [$uid, (int)$board['owner_user_id']] : [$uid, $board['id']]);
    if ($existing) {
        $id = (int)$existing['id'];
        if ($hide && !(int)$existing['hide_content']) db_exec('UPDATE guest_blocks SET hide_content = 1 WHERE id = ?', [$id]);
    } else {
        $id = db_insert('INSERT INTO guest_blocks (user_id, board_id, guest_key, display_name, hide_content, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [(int)$board['owner_user_id'], $allBoards ? null : $board['id'], $uid, $displayName, $hide ? 1 : 0, $now]);
    }
    $payload = ['block_id' => $id, 'post_ids' => [], 'comment_ids' => []];
    if ($hide) {
        $payload['post_ids'] = array_map('intval', array_column(db_all('SELECT id FROM posts WHERE board_id = ? AND author_key = ? AND deleted_at IS NULL', [$board['id'], $uid]), 'id'));
        $payload['comment_ids'] = array_map('intval', array_column(db_all('SELECT id FROM comments WHERE board_id = ? AND author_key = ? AND deleted_at IS NULL', [$board['id'], $uid]), 'id'));
        db_exec('UPDATE posts SET deleted_at = ?, updated_at = ? WHERE board_id = ? AND author_key = ? AND deleted_at IS NULL', [$now, $now, $board['id'], $uid]);
        db_exec('UPDATE comments SET deleted_at = ?, updated_at = ? WHERE board_id = ? AND author_key = ? AND deleted_at IS NULL', [$now, $now, $board['id'], $uid]);
        if ($payload['post_ids']) {
            $in = implode(',', array_fill(0, count($payload['post_ids']), '?'));
            db_exec("UPDATE comments SET deleted_at = ?, updated_at = ? WHERE post_id IN ($in) AND deleted_at IS NULL", array_merge([$now, $now], $payload['post_ids']));
            // Kommentare anderer unter ausgeblendeten Beiträgen gehören zur Wiederherstellung dazu.
            $payload['comment_ids'] = array_values(array_unique(array_merge($payload['comment_ids'],
                array_map('intval', array_column(db_all("SELECT id FROM comments WHERE post_id IN ($in) AND deleted_at = ?", array_merge($payload['post_ids'], [$now])), 'id')))));
        }
    }
    board_touch($board['id']);
    $label = t($allBoards ? 'log_guest_block_all' : 'log_guest_block', $board['lang'], ['name' => $displayName]);
    $undoId = undo_log($board, 'guest_block', $label, $payload);
    $block = guest_block_public(db_row('SELECT * FROM guest_blocks WHERE id = ?', [$id]));
    $block['undo_id'] = $undoId;
    $block['hidden_posts'] = count($payload['post_ids']);
    return $block;
}

/**
 * Hebt eine Sperre auf. Ausgeblendete Inhalte kommen über den zugehörigen Protokolleintrag zurück,
 * sofern er noch gültig und nicht schon rückgängig gemacht ist. Gibt eine Meldung zurück, wenn das nicht ging.
 */
function guest_block_lift(int $id): array {
    $block = db_row('SELECT * FROM guest_blocks WHERE id = ?', [$id]);
    // Eine fremde Sperre verhält sich wie eine unbekannte.
    if (!$block || !wb_can('block.lift', $block)) throw new InvalidArgumentException('Sperre nicht gefunden.');
    $note = null;
    if ($block['lifted_at'] === null) {
        $entry = db_row("SELECT * FROM undo_log WHERE action = 'guest_block' AND undone_at IS NULL AND JSON_EXTRACT(payload, '$.block_id') = ? ORDER BY id DESC LIMIT 1", [$id]);
        if ($entry) {
            $board = board_by_id((int)$entry['board_id']);
            try {
                if ($board) undo_apply($board, (int)$entry['id']); // hebt die Sperre mit auf
            } catch (RuntimeException $e) {
                $note = $e->getMessage();
            }
        }
        db_exec('UPDATE guest_blocks SET lifted_at = ? WHERE id = ? AND lifted_at IS NULL', [wb_now(), $id]);
    }
    $out = guest_block_public(db_row('SELECT * FROM guest_blocks WHERE id = ?', [$id]));
    if ($note) $out['note'] = $note;
    return $out;
}

function guest_block_public(array $b): array {
    $board = $b['board_id'] !== null ? board_by_id((int)$b['board_id']) : null;
    return [
        'id' => (int)$b['id'],
        'board_id' => $b['board_id'] !== null ? (int)$b['board_id'] : null,
        'board_key' => $board['bkey'] ?? null,
        'board_name' => $board['name'] ?? null,
        'guest_key' => $b['guest_key'],
        'display_name' => $b['display_name'],
        'hide_content' => (bool)$b['hide_content'],
        'created_at' => $b['created_at'],
        'lifted_at' => $b['lifted_at'],
        'active' => $b['lifted_at'] === null,
    ];
}

/**
 * Sperren, die eine Person gesetzt hat, im Arbeitsbereich des Aufrufs (S1, N3): Sperren eines Boards nur, wenn
 * das Board in diesem Arbeitsbereich liegt; Sperren «auf allen Boards» haben kein Board, wirken für alle Boards
 * der Person und erscheinen deshalb in jedem Arbeitsbereich (bis U2 sie an die Benutzernummer bindet).
 */
function guest_blocks_list(int $userId, bool $activeOnly = true, int $limit = 200): array {
    $rows = db_all('SELECT g.* FROM guest_blocks g LEFT JOIN boards b ON b.id = g.board_id WHERE g.user_id = ? AND (g.board_id IS NULL OR b.org_id <=> ?)'
        . ($activeOnly ? ' AND g.lifted_at IS NULL' : '') . ' ORDER BY g.lifted_at IS NULL DESC, g.id DESC LIMIT ' . max(1, min(500, $limit)), [$userId, wb_workspace_org_id()]);
    return array_map('guest_block_public', $rows);
}

/** Liegt die Sperre im Arbeitsbereich des Aufrufs? (Regel wie in guest_blocks_list) */
function guest_block_in_workspace(array $block): bool {
    if ($block['board_id'] === null) return true;
    $b = db_row('SELECT org_id FROM boards WHERE id = ?', [(int)$block['board_id']]);
    if (!$b) return false;
    $org = wb_workspace_org_id();
    return $b['org_id'] === null ? $org === null : $org !== null && (int)$b['org_id'] === $org;
}
