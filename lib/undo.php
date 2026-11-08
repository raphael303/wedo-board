<?php
declare(strict_types=1);

/**
 * Änderungsprotokoll mit Rückgängig (Etappe 5b).
 *
 * Vor jeder rückgängig machbaren Änderung halten die Fachfunktionen in lib/ den vorherigen Zustand fest
 * (undo_log). Protokolliert wird nur dort, nie in Bibliothek, Schnittstelle oder Connector, damit alle
 * Wege gleich behandelt werden. Rückgängig stellt den protokollierten Zustand wieder her; ist das nicht
 * mehr vollständig möglich, bricht es mit einer verständlichen Meldung ab und ändert nichts.
 *
 * Nutzlast (payload) je nach Aktion:
 *   organize, assign, bundle_update, bundle_delete:
 *     {actor, bundles: {id: {column_id, title, summary, author_name, position}}, posts: {id: {bundle_id, column_id}}, full: bool}
 *     full = true (organize mit replace): Bündel und Zuordnungen, die nicht im Protokoll stehen, werden entfernt beziehungsweise gelöst.
 *   post_delete:    {actor, post_id, comment_ids}
 *   comment_delete: {actor, comment_id}
 *   board_clear:    {actor, post_ids, comment_ids, bundle_ids}
 *   board_delete:   {actor}
 *   guest_block (5c): {actor, block_id, post_ids, comment_ids}
 */

const WB_UNDO_DAYS = 30;

/* ---------- Urheber ---------- */

/** Urheber als Code: teacher (Admin-Sitzung), claude (Token: Schnittstelle oder Connector), ai:<Modell>. */
function undo_actor(): string {
    if (!empty($GLOBALS['wb_actor'])) return (string)$GLOBALS['wb_actor'];
    if (wb_is_admin_session()) return 'teacher';
    return 'claude';
}

function undo_set_actor(?string $actor): void {
    $GLOBALS['wb_actor'] = $actor;
}

/** Protokollierung vorübergehend aussetzen, wenn eine Fachfunktion andere protokollierende Funktionen aufruft. */
function undo_suspend(callable $fn) {
    $GLOBALS['wb_undo_suspended'] = ($GLOBALS['wb_undo_suspended'] ?? 0) + 1;
    try {
        return $fn();
    } finally {
        $GLOBALS['wb_undo_suspended']--;
    }
}

function undo_suspended(): bool {
    return ($GLOBALS['wb_undo_suspended'] ?? 0) > 0;
}

/* ---------- Schreiben ---------- */

/** Legt einen Eintrag an und gibt seine Nummer zurück (0, wenn ausgesetzt). */
function undo_log(array $board, string $action, string $label, array $payload): int {
    if (undo_suspended()) return 0;
    $payload['actor'] = $payload['actor'] ?? undo_actor();
    $userId = (int)(wb_current_user()['id'] ?? 1);
    return db_insert('INSERT INTO undo_log (user_id, board_id, action, label, payload, created_at) VALUES (?, ?, ?, ?, ?, ?)',
        [$userId, $board['id'], $action, mb_substr($label, 0, 200), json_encode($payload, JSON_UNESCAPED_UNICODE), wb_now()]);
}

/** Momentaufnahme der Einteilung: alle aktiven Bündel und die Zuordnung aller aktiven Beiträge. */
function undo_snapshot(array $board, ?array $bundleIds = null, ?array $postIds = null): array {
    $snap = ['bundles' => [], 'posts' => []];
    if ($bundleIds === null) {
        $rows = db_all('SELECT * FROM bundles WHERE board_id = ? AND deleted_at IS NULL', [$board['id']]);
    } elseif ($bundleIds) {
        $in = implode(',', array_fill(0, count($bundleIds), '?'));
        $rows = db_all("SELECT * FROM bundles WHERE board_id = ? AND id IN ($in)", array_merge([$board['id']], $bundleIds));
    } else {
        $rows = [];
    }
    foreach ($rows as $g) {
        $snap['bundles'][(string)$g['id']] = ['column_id' => $g['column_id'], 'title' => $g['title'], 'summary' => $g['summary'], 'author_name' => $g['author_name'], 'position' => (int)$g['position']];
    }
    if ($postIds === null) {
        $rows = db_all('SELECT id, bundle_id, column_id FROM posts WHERE board_id = ? AND deleted_at IS NULL', [$board['id']]);
    } elseif ($postIds) {
        $in = implode(',', array_fill(0, count($postIds), '?'));
        $rows = db_all("SELECT id, bundle_id, column_id FROM posts WHERE board_id = ? AND deleted_at IS NULL AND id IN ($in)", array_merge([$board['id']], $postIds));
    } else {
        $rows = [];
    }
    foreach ($rows as $p) {
        $snap['posts'][(string)$p['id']] = ['bundle_id' => $p['bundle_id'] !== null ? (int)$p['bundle_id'] : null, 'column_id' => $p['column_id']];
    }
    return $snap;
}

/* ---------- Lesen ---------- */

function undo_entry_public(array $e): array {
    $payload = json_decode($e['payload'] ?? '{}', true) ?: [];
    $expired = $e['created_at'] < undo_cutoff();
    return [
        'id' => (int)$e['id'],
        'action' => $e['action'],
        'label' => $e['label'],
        'actor' => (string)($payload['actor'] ?? 'teacher'),
        'created_at' => $e['created_at'],
        'undone' => $e['undone_at'] !== null,
        'undone_at' => $e['undone_at'],
        'can_undo' => $e['undone_at'] === null && !$expired,
    ];
}

function undo_cutoff(): string {
    return (new DateTime('-' . WB_UNDO_DAYS . ' days', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
}

function undo_history(array $board, int $limit = 10): array {
    $rows = db_all('SELECT * FROM undo_log WHERE board_id = ? AND created_at >= ? ORDER BY id DESC LIMIT ' . max(1, min(50, $limit)), [$board['id'], undo_cutoff()]);
    return array_map('undo_entry_public', $rows);
}

function undo_entry(array $board, int $id): ?array {
    return db_row('SELECT * FROM undo_log WHERE id = ? AND board_id = ?', [$id, $board['id']]) ?: null;
}

/* ---------- Rückgängig ---------- */

/**
 * Macht einen Eintrag rückgängig. Wirft RuntimeException mit einer Meldung in der Sprache des Boards,
 * wenn das nicht (mehr) möglich ist; dann ist nichts verändert.
 */
function undo_apply(array $board, int $id): array {
    $lang = $board['lang'];
    $e = undo_entry($board, $id);
    if (!$e) throw new RuntimeException(t('undo_not_found', $lang));
    if ($e['undone_at'] !== null) throw new RuntimeException(t('undo_already', $lang));
    if ($e['created_at'] < undo_cutoff()) throw new RuntimeException(t('undo_expired', $lang));
    $payload = json_decode($e['payload'] ?? '{}', true) ?: [];
    $now = wb_now();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        switch ($e['action']) {
            case 'organize':
            case 'assign':
            case 'bundle_update':
            case 'bundle_delete':
                undo_restore_arrangement($board, $payload, !empty($payload['full']), $now);
                break;
            case 'post_delete':
                undo_require_rows('posts', [(int)$payload['post_id']], t('undo_gone_post', $lang));
                db_exec('UPDATE posts SET deleted_at = NULL, updated_at = ? WHERE id = ? AND board_id = ?', [$now, (int)$payload['post_id'], $board['id']]);
                undo_undelete_ids('comments', array_map('intval', $payload['comment_ids'] ?? []), $board['id'], $now);
                break;
            case 'comment_delete':
                undo_require_rows('comments', [(int)$payload['comment_id']], t('undo_gone_comment', $lang));
                db_exec('UPDATE comments SET deleted_at = NULL, updated_at = ? WHERE id = ? AND board_id = ?', [$now, (int)$payload['comment_id'], $board['id']]);
                break;
            case 'board_clear':
            case 'guest_block':
                $postIds = array_map('intval', $payload['post_ids'] ?? []);
                $commentIds = array_map('intval', $payload['comment_ids'] ?? []);
                $bundleIds = array_map('intval', $payload['bundle_ids'] ?? []);
                undo_require_rows('posts', $postIds, t('undo_gone_content', $lang));
                undo_require_rows('comments', $commentIds, t('undo_gone_content', $lang));
                undo_require_rows('bundles', $bundleIds, t('undo_gone_content', $lang));
                undo_undelete_ids('posts', $postIds, $board['id'], $now);
                undo_undelete_ids('comments', $commentIds, $board['id'], $now);
                undo_undelete_ids('bundles', $bundleIds, $board['id'], $now);
                if ($e['action'] === 'guest_block' && !empty($payload['block_id'])) {
                    db_exec('UPDATE guest_blocks SET lifted_at = ? WHERE id = ? AND lifted_at IS NULL', [$now, (int)$payload['block_id']]);
                }
                break;
            case 'board_delete':
                board_restore($board);
                break;
            default:
                throw new RuntimeException(t('undo_unknown', $lang));
        }
        db_exec('UPDATE undo_log SET undone_at = ?, undone_by = ? WHERE id = ?', [$now, (int)(wb_current_user()['id'] ?? 1), $id]);
        board_touch($board['id']);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    return undo_entry_public(undo_entry($board, $id));
}

/** Bricht ab, wenn eine der Zeilen physisch nicht mehr existiert. */
function undo_require_rows(string $table, array $ids, string $message): void {
    $ids = array_values(array_filter($ids));
    if (!$ids) return;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $row = db_row("SELECT COUNT(*) AS n FROM $table WHERE id IN ($in)", $ids);
    if ((int)$row['n'] !== count($ids)) throw new RuntimeException($message);
}

function undo_undelete_ids(string $table, array $ids, int $boardId, string $now): void {
    $ids = array_values(array_filter($ids));
    if (!$ids) return;
    $in = implode(',', array_fill(0, count($ids), '?'));
    db_exec("UPDATE $table SET deleted_at = NULL, updated_at = ? WHERE board_id = ? AND id IN ($in)", array_merge([$now, $boardId], $ids));
}

/**
 * Stellt eine protokollierte Einteilung wieder her. Bündel aus dem Protokoll werden (auch aus dem
 * Papierkorb) auf ihren Stand gesetzt; Beiträge aus dem Protokoll erhalten ihre alte Zuordnung, sofern sie
 * noch da sind. Mit $full werden Bündel und Zuordnungen, die seither neu entstanden sind, entfernt.
 */
function undo_restore_arrangement(array $board, array $payload, bool $full, string $now): void {
    $bundles = is_array($payload['bundles'] ?? null) ? $payload['bundles'] : [];
    $posts = is_array($payload['posts'] ?? null) ? $payload['posts'] : [];
    $lang = $board['lang'];
    undo_require_rows('bundles', array_map('intval', array_keys($bundles)), t('undo_gone_bundle', $lang));
    // Bündel, die in Beiträgen referenziert werden, aber nicht im Protokoll stehen (zum Beispiel bei assign), müssen noch existieren.
    $referenced = [];
    foreach ($posts as $pid => $p) {
        $gid = $p['bundle_id'] ?? null;
        if ($gid !== null && !isset($bundles[(string)$gid])) $referenced[(int)$gid] = true;
    }
    if ($referenced) {
        $ids = array_keys($referenced);
        undo_require_rows('bundles', $ids, t('undo_gone_bundle', $lang));
        $in = implode(',', array_fill(0, count($ids), '?'));
        db_exec("UPDATE bundles SET deleted_at = NULL, updated_at = ? WHERE board_id = ? AND id IN ($in)", array_merge([$now, $board['id']], $ids));
    }
    foreach ($bundles as $gid => $g) {
        db_exec('UPDATE bundles SET deleted_at = NULL, column_id = ?, title = ?, summary = ?, author_name = ?, position = ?, updated_at = ? WHERE id = ? AND board_id = ?',
            [$g['column_id'] ?? null, (string)($g['title'] ?? ''), $g['summary'] ?? null, (string)($g['author_name'] ?? ''), (int)($g['position'] ?? 0), $now, (int)$gid, $board['id']]);
    }
    if ($full) {
        $keep = array_map('intval', array_keys($bundles));
        $sql = 'UPDATE bundles SET deleted_at = ?, updated_at = ? WHERE board_id = ? AND deleted_at IS NULL';
        $params = [$now, $now, $board['id']];
        if ($keep) { $sql .= ' AND id NOT IN (' . implode(',', array_fill(0, count($keep), '?')) . ')'; $params = array_merge($params, $keep); }
        db_exec($sql, $params);
        $keepPosts = array_map('intval', array_keys($posts));
        $sql = 'UPDATE posts SET bundle_id = NULL, updated_at = ? WHERE board_id = ? AND deleted_at IS NULL AND bundle_id IS NOT NULL';
        $params = [$now, $board['id']];
        if ($keepPosts) { $sql .= ' AND id NOT IN (' . implode(',', array_fill(0, count($keepPosts), '?')) . ')'; $params = array_merge($params, $keepPosts); }
        db_exec($sql, $params);
    }
    foreach ($posts as $pid => $p) {
        db_exec('UPDATE posts SET bundle_id = ?, column_id = ?, updated_at = ? WHERE id = ? AND board_id = ? AND deleted_at IS NULL',
            [isset($p['bundle_id']) && $p['bundle_id'] !== null ? (int)$p['bundle_id'] : null, $p['column_id'] ?? null, $now, (int)$pid, $board['id']]);
    }
}

function undo_purge(int $days = WB_UNDO_DAYS): int {
    $limit = (new DateTime("-$days days", new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    return db_exec('DELETE FROM undo_log WHERE created_at < ?', [$limit]);
}
