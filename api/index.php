<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';
require WB_ROOT . '/lib/images.php';
require WB_ROOT . '/lib/qr.php';

$route = trim((string)($_GET['route'] ?? ''), '/');
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST' && isset($_POST['_method'])) {
    $method = strtoupper((string)$_POST['_method']);
}
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    if (!db_is_installed()) wb_error('Nicht installiert.', 503);
    $parts = $route === '' ? [] : explode('/', $route);
    $head = $parts[0] ?? '';

    if ($head === 'b') {
        guest_api($method, $parts);
    } elseif ($head === 'boards') {
        owner_boards_api($method, $parts);
    } elseif ($head === 'me' && $method === 'GET') {
        wb_require_owner();
        db_migrate();
        wb_json(['ok' => true, 'owner' => true, 'version' => WB_VERSION, 'base_url' => wb_base_url()]);
    } elseif ($head === 'blocks') {
        wb_require_can('guest.block');
        db_migrate();
        $bid = (int)($parts[1] ?? 0);
        if ($method === 'GET' && $bid === 0) {
            wb_json(['ok' => true, 'blocks' => guest_blocks_list(($_GET['status'] ?? 'active') !== 'all')]);
        }
        if ($method === 'POST' && $bid > 0 && ($parts[2] ?? '') === 'lift') {
            wb_json(['ok' => true, 'block' => guest_block_lift($bid)]);
        }
    } elseif ($head === 'maintenance' && ($parts[1] ?? '') === 'purge' && $method === 'POST') {
        wb_require_owner();
        wb_json(['ok' => true, 'purged' => board_purge_deleted(30)]);
    } elseif ($head === 'meta' && $method === 'GET') {
        wb_json(['ok' => true, 'backgrounds' => wb_backgrounds(), 'emojis' => wb_emojis(), 'langs' => wb_langs(), 'types' => ['wall', 'columns']]);
    }
    wb_error('Unbekannter Aufruf.', 404);
} catch (InvalidArgumentException $e) {
    wb_error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    wb_error($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log(wb_app_name() . ' api: ' . $e->getMessage());
    wb_error('Serverfehler.', 500);
}

/* ======================= Gäste ======================= */

function guest_api(string $method, array $p): never {
    $board = board_by_key($p[1] ?? '');
    if (!$board || $board['status'] === 'archived') wb_error('Board nicht gefunden.', 404);
    $uid = wb_client_uid();
    $owner = wb_can('board.manage', $board);
    $lang = wb_resolve_lang($board['lang']);
    $name = wb_fantasy_name($uid, $board['bkey'], $board['lang']);
    $blocked = !$owner && guest_block_active($uid, $board['id']) !== null;
    $sub = $p[2] ?? '';
    $id = (int)($p[3] ?? 0);
    $action = $p[4] ?? '';

    if ($method === 'GET' && $sub === '') {
        wb_json([
            'ok' => true,
            'board' => board_public($board, $lang),
            'me' => ['name' => $name, 'hue' => wb_author_hue($name), 'owner' => $owner, 'uid' => $uid, 'blocked' => $blocked, 'fresh' => !empty($GLOBALS['wb_uid_fresh'])],
            'strings' => wb_lang_strings($lang),
            'state' => board_state($board, $uid, null, $owner),
        ]);
    }
    if ($method === 'GET' && $sub === 'state') {
        wb_json(['ok' => true] + board_state($board, $uid, isset($_GET['since']) ? (string)$_GET['since'] : null, $owner));
    }
    if ($sub === 'identify' && $method === 'POST') {
        // Der Browser kennt aus localStorage seinen früheren Schlüssel, das Cookie ging verloren: Cookie neu setzen.
        $in = wb_read_json_body();
        if (!wb_set_client_uid((string)($in['uid'] ?? ''))) wb_error('Ungültiger Schlüssel.', 422);
        wb_json(['ok' => true]);
    }
    if ($sub === 'history' && $method === 'GET') {
        if (!wb_can('undo', $board)) wb_error('Nicht berechtigt.', 403);
        wb_json(['ok' => true, 'entries' => undo_history($board, 10)]);
    }
    if ($sub === 'undo' && $method === 'POST' && $id > 0) {
        if (!wb_can('undo', $board)) wb_error('Nicht berechtigt.', 403);
        wb_check_csrf_header();
        $entry = undo_apply($board, $id);
        wb_json(['ok' => true, 'entry' => $entry, 'version' => board_by_id($board['id'])['last_activity_at']]);
    }
    if ($sub === 'ai' && ($p[3] ?? '') === 'status' && $method === 'GET') {
        if (!wb_can('ai.use', $board)) wb_error('Nicht berechtigt.', 403);
        wb_json(['ok' => true] + ai_organize_status($board));
    }
    if ($sub === 'ai' && ($p[3] ?? '') === 'organize' && $method === 'POST') {
        if (!wb_can('ai.use', $board)) wb_error('Nicht berechtigt.', 403);
        wb_check_csrf_header();
        ai_organize_api($board, wb_read_json_body());
    }
    if ($sub === 'block' && $method === 'POST') {
        if (!wb_can('guest.block', $board)) wb_error('Nicht berechtigt.', 403);
        wb_check_csrf_header();
        $in = wb_read_json_body();
        $block = guest_block_create($board, (string)($in['guest_key'] ?? ''), (string)($in['display_name'] ?? ''), ($in['scope'] ?? 'board') === 'all', filter_var($in['hide'] ?? false, FILTER_VALIDATE_BOOLEAN));
        wb_json(['ok' => true, 'block' => $block, 'undo_id' => $block['undo_id'], 'version' => board_by_id($board['id'])['last_activity_at']]);
    }

    // Ab hier nur schreibende Aufrufe.
    if (!$owner) {
        if ($board['status'] !== 'open') wb_error('board_closed', 403);
        if ($blocked) wb_error(t('blocked', $lang), 403, ['code' => 'blocked']);
        rate_limit($uid);
    }
    $in = wb_read_json_body();

    if ($sub === 'posts' && $method === 'POST' && $id === 0) {
        $post = post_create($board, $in, $uid, $name);
        wb_json(['ok' => true, 'post' => post_public($board, $post, $uid, $owner), 'version' => board_touch($board['id'])], 201);
    }
    if ($sub === 'posts' && $id > 0) {
        $post = db_row('SELECT * FROM posts WHERE id = ? AND board_id = ? AND deleted_at IS NULL', [$id, $board['id']]);
        if (!$post) wb_error('Beitrag nicht gefunden.', 404);
        // Kommentare darf jede Person schreiben, nicht nur der Verfasser des Beitrags.
        if ($action === 'comments' && $method === 'POST') {
            if (!$board['settings']['comments'] && !$owner) wb_error('Kommentare sind ausgeschaltet.', 403);
            $body = wb_clean_text($in['body'] ?? '', 2000);
            if ($body === '') wb_error('body_required', 422);
            $now = wb_now();
            $cid = db_insert('INSERT INTO comments (board_id, post_id, body, author_name, author_key, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$board['id'], $id, $body, $name, $uid, $now, $now]);
            $c = db_row('SELECT * FROM comments WHERE id = ?', [$cid]);
            wb_json(['ok' => true, 'comment' => comment_public($c, $uid, $owner), 'version' => board_touch($board['id'])], 201);
        }
        if (!$owner && $post['author_key'] !== $uid) wb_error('Nicht dein Beitrag.', 403);
        if ($method === 'DELETE' || $action === 'delete') {
            $undoId = post_soft_delete($board, $id, $owner);
            wb_json(['ok' => true, 'version' => board_by_id($board['id'])['last_activity_at'], 'undo_id' => $undoId > 0 ? $undoId : null]);
        }
        if ($method === 'POST' || $method === 'PATCH') {
            $undoId = null;
            $post = post_update($board, $post, $in, $owner, $undoId);
            wb_json(['ok' => true, 'post' => post_public($board, $post, $uid, $owner), 'version' => board_touch($board['id']), 'undo_id' => $undoId]);
        }
    }
    if ($sub === 'comments' && $id > 0) {
        $c = db_row('SELECT * FROM comments WHERE id = ? AND board_id = ? AND deleted_at IS NULL', [$id, $board['id']]);
        if (!$c) wb_error('Kommentar nicht gefunden.', 404);
        if (!$owner && $c['author_key'] !== $uid) wb_error('Nicht dein Kommentar.', 403);
        $now = wb_now();
        if ($method === 'DELETE' || $action === 'delete') {
            $undoId = comment_soft_delete($board, $id, $owner);
            wb_json(['ok' => true, 'version' => board_by_id($board['id'])['last_activity_at'], 'undo_id' => $undoId > 0 ? $undoId : null]);
        }
        $body = wb_clean_text($in['body'] ?? '', 2000);
        if ($body === '') wb_error('body_required', 422);
        db_exec('UPDATE comments SET body = ?, updated_at = ?, edited_at = ? WHERE id = ?', [$body, $now, $now, $id]);
        $c = db_row('SELECT * FROM comments WHERE id = ?', [$id]);
        wb_json(['ok' => true, 'comment' => comment_public($c, $uid, $owner), 'version' => board_touch($board['id'])]);
    }
    if ($sub === 'bundles') {
        if (!wb_can('bundle.manage', $board)) wb_error('Nur der Besitzer kann Bündel verwalten.', 403);
        if ($id === 0 && $method === 'POST') {
            $g = bundle_create($board, $in);
            wb_json(['ok' => true, 'bundle' => bundle_public($g), 'version' => board_by_id($board['id'])['last_activity_at']], 201);
        }
        $g = bundle_by_id($board, $id);
        if (!$g) wb_error('Bündel nicht gefunden.', 404);
        if ($method === 'DELETE' || $action === 'delete') {
            $undoId = bundle_delete($board, $g);
            wb_json(['ok' => true, 'version' => board_by_id($board['id'])['last_activity_at'], 'undo_id' => $undoId ?: null]);
        }
        $undoId = null;
        $g = bundle_update($board, $g, $in, $undoId);
        wb_json(['ok' => true, 'bundle' => bundle_public($g), 'version' => board_by_id($board['id'])['last_activity_at'], 'undo_id' => $undoId]);
    }
    if ($sub === 'react' && $method === 'POST') {
        if (!$board['settings']['reactions'] && !$owner) wb_error('Reaktionen sind ausgeschaltet.', 403);
        $type = ($in['type'] ?? '') === 'comment' ? 'comment' : 'post';
        $tid = (int)($in['id'] ?? 0);
        $emoji = (string)($in['emoji'] ?? '');
        if (!in_array($emoji, $board['settings']['emojis'], true)) wb_error('Dieses Emoji ist nicht erlaubt.', 422);
        $table = $type === 'post' ? 'posts' : 'comments';
        $exists = db_row("SELECT id FROM $table WHERE id = ? AND board_id = ? AND deleted_at IS NULL", [$tid, $board['id']]);
        if (!$exists) wb_error('Ziel nicht gefunden.', 404);
        $had = db_exec('DELETE FROM reactions WHERE target_type = ? AND target_id = ? AND emoji = ? AND author_key = ?', [$type, $tid, $emoji, $uid]);
        if ($had === 0) {
            db_exec('INSERT INTO reactions (board_id, target_type, target_id, emoji, author_key, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$board['id'], $type, $tid, $emoji, $uid, wb_now()]);
        }
        wb_json(['ok' => true, 'active' => $had === 0, 'version' => board_touch($board['id'])]);
    }
    wb_error('Unbekannter Aufruf.', 404);
}

/** KI-Bündelung für Board-Seite und Schnittstelle; ein Lauf darf bis zu 60 Sekunden dauern. */
function ai_organize_api(array $board, array $in): never {
    @set_time_limit(AI_ORGANIZE_TIMEOUT + 30);
    try {
        $r = ai_organize_board($board, $in);
    } catch (AiException $e) {
        wb_error(ai_error_message($e, $board['lang']), 502, ['code' => 'ai_' . $e->kind]);
    }
    wb_json(['ok' => true] + $r + ['version' => board_by_id($board['id'])['last_activity_at']]);
}

function rate_limit(string $uid, int $max = 30): void {
    $minute = intdiv(time(), 60);
    db_exec('INSERT INTO rate_limits (author_key, minute, cnt) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE cnt = cnt + 1', [$uid, $minute]);
    $row = db_row('SELECT cnt FROM rate_limits WHERE author_key = ? AND minute = ?', [$uid, $minute]);
    if ((int)$row['cnt'] > $max) wb_error('error_rate', 429);
    if (random_int(0, 50) === 0) db_exec('DELETE FROM rate_limits WHERE minute < ?', [$minute - 5]);
}

function post_create(array $board, array $in, string $uid, string $name): array {
    $title = wb_clean_text($in['title'] ?? '', 200);
    $body = wb_clean_text($in['body'] ?? '', 5000);
    $link = $board['settings']['links'] ? wb_clean_url($in['link'] ?? null) : null;
    $column = null;
    if ($board['type'] === 'columns') {
        $ids = array_column($board['columns'], 'id');
        $column = in_array($in['column'] ?? '', $ids, true) ? (string)$in['column'] : ($ids[0] ?? null);
    }
    $image = null;
    if (!empty($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        if (!$board['settings']['images']) wb_error('Bilder sind ausgeschaltet.', 403);
        $image = wb_store_image($_FILES['image'], $board['bkey']);
    }
    if ($body === '' && $image === null && $link === null) wb_error('body_required', 422);
    $now = wb_now();
    if ($board['settings']['new_on_top']) {
        $row = db_row('SELECT MIN(position) AS p FROM posts WHERE board_id = ?', [$board['id']]);
        $pos = (int)($row['p'] ?? 0) - 1;
    } else {
        $row = db_row('SELECT MAX(position) AS p FROM posts WHERE board_id = ?', [$board['id']]);
        $pos = (int)($row['p'] ?? 0) + 1;
    }
    $id = db_insert('INSERT INTO posts (board_id, column_id, title, body, image, link, author_name, author_key, position, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$board['id'], $column, $title !== '' ? $title : null, $body, $image, $link, $name, $uid, $pos, $now, $now]);
    return db_row('SELECT * FROM posts WHERE id = ?', [$id]);
}

function post_update(array $board, array $post, array $in, bool $owner, ?int &$undoId = null): array {
    $fields = [];
    $params = [];
    $snap = $owner && array_key_exists('bundle_id', $in) ? undo_snapshot($board, [], [(int)$post['id']]) : null;
    if (array_key_exists('title', $in)) {
        $t = wb_clean_text($in['title'], 200);
        $fields[] = 'title = ?'; $params[] = $t !== '' ? $t : null;
    }
    if (array_key_exists('body', $in)) {
        $fields[] = 'body = ?'; $params[] = wb_clean_text($in['body'], 5000);
    }
    if (array_key_exists('link', $in)) {
        $fields[] = 'link = ?'; $params[] = $board['settings']['links'] ? wb_clean_url($in['link']) : null;
    }
    if (array_key_exists('column', $in) && $board['type'] === 'columns') {
        $ids = array_column($board['columns'], 'id');
        if (in_array($in['column'], $ids, true) && $in['column'] !== $post['column_id']) {
            $fields[] = 'column_id = ?'; $params[] = $in['column'];
            if (!array_key_exists('bundle_id', $in)) $fields[] = 'bundle_id = NULL';
        }
    }
    if ($owner && array_key_exists('bundle_id', $in)) {
        if ($in['bundle_id'] === null || $in['bundle_id'] === '' || (int)$in['bundle_id'] === 0) {
            $fields[] = 'bundle_id = NULL';
        } else {
            $g = bundle_by_id($board, (int)$in['bundle_id']);
            if (!$g) wb_error('Bündel nicht gefunden.', 404);
            $fields[] = 'bundle_id = ?'; $params[] = (int)$g['id'];
            if ($board['type'] === 'columns' && $g['column_id'] !== null) { $fields[] = 'column_id = ?'; $params[] = $g['column_id']; }
        }
    }
    if (!empty($in['remove_image']) && $post['image']) {
        post_delete_image($board['bkey'], $post['image']);
        $fields[] = 'image = NULL';
        $post['image'] = null;
    }
    if (!empty($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        if (!$board['settings']['images'] && !$owner) wb_error('Bilder sind ausgeschaltet.', 403);
        $img = wb_store_image($_FILES['image'], $board['bkey']);
        if ($post['image']) post_delete_image($board['bkey'], $post['image']);
        $fields[] = 'image = ?'; $params[] = $img;
    }
    if (!$fields) return $post;
    $contentChanged = (bool)array_intersect_key($in, array_flip(['title', 'body', 'link', 'remove_image'])) || (!empty($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE);
    if ($contentChanged) { $fields[] = 'edited_at = ?'; $params[] = wb_now(); }
    $fields[] = 'updated_at = ?'; $params[] = wb_now();
    $params[] = $post['id'];
    db_exec('UPDATE posts SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
    $p = db_row('SELECT * FROM posts WHERE id = ?', [$post['id']]);
    if ($p['body'] === '' && !$p['image'] && !$p['link']) wb_error('body_required', 422);
    if ($snap !== null && ($p['bundle_id'] !== $post['bundle_id'] || $p['column_id'] !== $post['column_id'])) {
        $undoId = undo_log($board, 'assign', t($p['bundle_id'] !== null ? 'log_assign' : 'log_release', $board['lang'], ['n' => 1]), $snap);
    }
    return $p;
}

/* ======================= Besitzer ======================= */

function owner_boards_api(string $method, array $p): never {
    $key = $p[1] ?? '';
    $action = $p[2] ?? '';

    // QR-Codes sind öffentlich: wer den Schlüssel kennt, kennt bereits den Link.
    if ($key !== '' && in_array($action, ['qr.svg', 'qr.png'], true) && $method === 'GET') {
        $board = board_find($key);
        if (!$board) wb_error('Board nicht gefunden.', 404);
        header('Cache-Control: public, max-age=86400');
        if ($action === 'qr.svg') {
            header('Content-Type: image/svg+xml');
            echo wb_qr_svg($board['url']);
        } else {
            $px = max(4, min(40, (int)($_GET['px'] ?? 12)));
            header('Content-Type: image/png');
            echo wb_qr_png($board['url'], $px);
        }
        exit;
    }

    wb_require_owner();
    db_migrate();
    $in = wb_read_json_body();

    if ($key === '') {
        if ($method === 'GET') {
            wb_json(['ok' => true, 'boards' => boards_list([
                'q' => $_GET['q'] ?? '', 'status' => $_GET['status'] ?? 'active', 'sort' => $_GET['sort'] ?? 'activity', 'tag' => $_GET['tag'] ?? '',
            ])]);
        }
        if ($method === 'POST') {
            $b = board_create($in);
            wb_json(['ok' => true, 'board' => board_owner_view($b)], 201);
        }
        wb_error('Unbekannter Aufruf.', 404);
    }

    $board = board_find($key, true);
    if (!$board) wb_error('Board nicht gefunden.', 404);

    if ($action === '') {
        if ($method === 'GET') wb_json(['ok' => true, 'board' => board_owner_view($board)]);
        if ($method === 'PATCH' || $method === 'POST' || $method === 'PUT') {
            wb_json(['ok' => true, 'board' => board_owner_view(board_update($board, $in))]);
        }
        if ($method === 'DELETE') {
            $undoId = board_trash($board);
            wb_json(['ok' => true, 'deleted' => $key, 'trash' => true, 'undo_id' => $undoId]);
        }
    }
    if ($action === 'history' && $method === 'GET') {
        wb_json(['ok' => true, 'entries' => undo_history($board, max(1, min(50, (int)($_GET['limit'] ?? 10))))]);
    }
    if ($action === 'undo' && $method === 'POST' && (int)($p[3] ?? 0) > 0) {
        wb_check_csrf_header();
        wb_json(['ok' => true, 'entry' => undo_apply($board, (int)$p[3])]);
    }
    if ($action === 'ai-organize' && $method === 'POST') {
        ai_organize_api($board, $in);
    }
    if ($action === 'ai-status' && $method === 'GET') {
        wb_json(['ok' => true] + ai_organize_status($board));
    }
    if ($action === 'block' && $method === 'POST') {
        $block = guest_block_create($board, (string)($in['guest_key'] ?? ''), (string)($in['display_name'] ?? ''), ($in['scope'] ?? 'board') === 'all', filter_var($in['hide'] ?? false, FILTER_VALIDATE_BOOLEAN));
        wb_json(['ok' => true, 'block' => $block, 'undo_id' => $block['undo_id']]);
    }
    if ($action === 'restore' && $method === 'POST') {
        wb_json(['ok' => true, 'board' => board_owner_view(board_restore($board))]);
    }
    if ($method === 'GET') {
        if ($action === 'export.json') {
            wb_json(['ok' => true] + board_export_data($board));
        }
        if ($action === 'export.md') {
            header('Content-Type: text/markdown; charset=utf-8');
            header('Content-Disposition: inline; filename="' . preg_replace('/[^a-z0-9]+/i', '-', $board['name']) . '.md"');
            echo board_export_markdown($board);
            exit;
        }
        if ($action === 'posts') {
            wb_json(['ok' => true] + board_export_data($board));
        }
        if ($action === 'bundles') {
            wb_json(['ok' => true, 'bundles' => board_export_data($board)['bundles']]);
        }
    }
    if ($action === 'bundles' && ($method === 'POST' || $method === 'PATCH' || $method === 'DELETE')) {
        $gid = (int)($p[3] ?? 0);
        if ($gid === 0 && $method === 'POST') {
            wb_json(['ok' => true, 'bundle' => bundle_public(bundle_create($board, $in))], 201);
        }
        $g = bundle_by_id($board, $gid);
        if (!$g) wb_error('Bündel nicht gefunden.', 404);
        if ($method === 'DELETE' || ($p[4] ?? '') === 'delete') {
            $undoId = bundle_delete($board, $g);
            wb_json(['ok' => true, 'undo_id' => $undoId ?: null]);
        }
        $undoId = null;
        $g = bundle_update($board, $g, $in, $undoId);
        wb_json(['ok' => true, 'bundle' => bundle_public($g), 'undo_id' => $undoId]);
    }
    if ($action === 'organize' && $method === 'POST') {
        $list = is_array($in['bundles'] ?? null) ? $in['bundles'] : [];
        $replace = array_key_exists('replace', $in) ? filter_var($in['replace'], FILTER_VALIDATE_BOOLEAN) : true;
        $undoId = null;
        $created = bundle_organize($board, $list, $replace, (string)($in['author'] ?? 'Claude'), $undoId);
        wb_json(['ok' => true, 'bundles' => $created, 'undo_id' => $undoId]);
    }
    if ($action === 'assign' && $method === 'POST') {
        $gid = isset($in['bundle_id']) && (int)$in['bundle_id'] > 0 ? (int)$in['bundle_id'] : null;
        $undoId = null;
        $n = bundle_assign($board, $gid, array_map('intval', (array)($in['post_ids'] ?? [])), $undoId);
        wb_json(['ok' => true, 'assigned' => $n, 'undo_id' => $undoId]);
    }
    if ($action === 'posts' && $method === 'POST' && (int)($p[3] ?? 0) === 0) {
        $post = owner_post_create($board, $in);
        wb_json(['ok' => true, 'post' => post_public($board, $post, WB_OWNER_KEY, true)], 201);
    }
    if ($action === 'posts' && $method === 'POST' && (int)($p[3] ?? 0) > 0 && ($p[4] ?? '') === 'comments') {
        $c = owner_comment_create($board, (int)$p[3], $in);
        wb_json(['ok' => true, 'comment' => comment_public($c, WB_OWNER_KEY, true)], 201);
    }
    if ($method === 'POST' || $method === 'DELETE') {
        switch ($action) {
            case 'clear':
                $undoId = board_clear($board);
                wb_json(['ok' => true, 'board' => board_owner_view(board_by_id($board['id'])), 'undo_id' => $undoId]);
            case 'close':
                wb_json(['ok' => true, 'board' => board_owner_view(board_update($board, ['status' => 'closed']))]);
            case 'open':
                wb_json(['ok' => true, 'board' => board_owner_view(board_update($board, ['status' => 'open']))]);
            case 'archive':
                wb_json(['ok' => true, 'board' => board_owner_view(board_update($board, ['status' => 'archived']))]);
            case 'duplicate':
                wb_json(['ok' => true, 'board' => board_owner_view(board_duplicate($board, isset($in['name']) ? (string)$in['name'] : null))], 201);
            case 'delete':
                $undoId = board_trash($board);
                wb_json(['ok' => true, 'deleted' => $key, 'trash' => true, 'undo_id' => $undoId]);
            case 'posts':
                $pid = (int)($p[3] ?? 0);
                $sub = $p[4] ?? '';
                if ($pid > 0 && ($sub === 'delete' || $method === 'DELETE')) {
                    $undoId = post_soft_delete($board, $pid, true);
                    if ($undoId < 0) wb_error('Beitrag nicht gefunden.', 404);
                    wb_json(['ok' => true, 'undo_id' => $undoId]);
                }
                break;
            case 'comments':
                $cid = (int)($p[3] ?? 0);
                if ($cid > 0) {
                    $undoId = comment_soft_delete($board, $cid, true);
                    if ($undoId < 0) wb_error('Kommentar nicht gefunden.', 404);
                    wb_json(['ok' => true, 'undo_id' => $undoId]);
                }
                break;
        }
    }
    wb_error('Unbekannter Aufruf.', 404);
}
