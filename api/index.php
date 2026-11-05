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
    error_log('wedo board: ' . $e->getMessage());
    wb_error('Serverfehler.', 500);
}

/* ======================= Gäste ======================= */

function guest_api(string $method, array $p): never {
    $board = board_by_key($p[1] ?? '');
    if (!$board || $board['status'] === 'archived') wb_error('Board nicht gefunden.', 404);
    $uid = wb_client_uid();
    $owner = wb_is_owner();
    $lang = wb_resolve_lang($board['lang']);
    $name = wb_fantasy_name($uid, $board['bkey'], $board['lang']);
    $sub = $p[2] ?? '';
    $id = (int)($p[3] ?? 0);
    $action = $p[4] ?? '';

    if ($method === 'GET' && $sub === '') {
        wb_json([
            'ok' => true,
            'board' => board_public($board, $lang),
            'me' => ['name' => $name, 'hue' => wb_author_hue($name), 'owner' => $owner],
            'strings' => wb_lang_strings($lang),
            'state' => board_state($board, $uid, null, $owner),
        ]);
    }
    if ($method === 'GET' && $sub === 'state') {
        wb_json(['ok' => true] + board_state($board, $uid, isset($_GET['since']) ? (string)$_GET['since'] : null, $owner));
    }

    // Ab hier nur schreibende Aufrufe.
    if (!$owner) {
        if ($board['status'] !== 'open') wb_error('board_closed', 403);
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
            $now = wb_now();
            db_exec('UPDATE posts SET deleted_at = ?, updated_at = ? WHERE id = ?', [$now, $now, $id]);
            db_exec('UPDATE comments SET deleted_at = ?, updated_at = ? WHERE post_id = ? AND deleted_at IS NULL', [$now, $now, $id]);
            wb_json(['ok' => true, 'version' => board_touch($board['id'])]);
        }
        if ($method === 'POST' || $method === 'PATCH') {
            $post = post_update($board, $post, $in, $owner);
            wb_json(['ok' => true, 'post' => post_public($board, $post, $uid, $owner), 'version' => board_touch($board['id'])]);
        }
    }
    if ($sub === 'comments' && $id > 0) {
        $c = db_row('SELECT * FROM comments WHERE id = ? AND board_id = ? AND deleted_at IS NULL', [$id, $board['id']]);
        if (!$c) wb_error('Kommentar nicht gefunden.', 404);
        if (!$owner && $c['author_key'] !== $uid) wb_error('Nicht dein Kommentar.', 403);
        $now = wb_now();
        if ($method === 'DELETE' || $action === 'delete') {
            db_exec('UPDATE comments SET deleted_at = ?, updated_at = ? WHERE id = ?', [$now, $now, $id]);
            wb_json(['ok' => true, 'version' => board_touch($board['id'])]);
        }
        $body = wb_clean_text($in['body'] ?? '', 2000);
        if ($body === '') wb_error('body_required', 422);
        db_exec('UPDATE comments SET body = ?, updated_at = ? WHERE id = ?', [$body, $now, $id]);
        $c = db_row('SELECT * FROM comments WHERE id = ?', [$id]);
        wb_json(['ok' => true, 'comment' => comment_public($c, $uid, $owner), 'version' => board_touch($board['id'])]);
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

function post_update(array $board, array $post, array $in, bool $owner): array {
    $fields = [];
    $params = [];
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
        if (in_array($in['column'], $ids, true)) { $fields[] = 'column_id = ?'; $params[] = $in['column']; }
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
    $fields[] = 'updated_at = ?'; $params[] = wb_now();
    $params[] = $post['id'];
    db_exec('UPDATE posts SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
    $p = db_row('SELECT * FROM posts WHERE id = ?', [$post['id']]);
    if ($p['body'] === '' && !$p['image'] && !$p['link']) wb_error('body_required', 422);
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

    $board = board_find($key);
    if (!$board) wb_error('Board nicht gefunden.', 404);

    if ($action === '') {
        if ($method === 'GET') wb_json(['ok' => true, 'board' => board_owner_view($board)]);
        if ($method === 'PATCH' || $method === 'POST' || $method === 'PUT') {
            wb_json(['ok' => true, 'board' => board_owner_view(board_update($board, $in))]);
        }
        if ($method === 'DELETE') {
            board_delete_forever($board);
            wb_json(['ok' => true, 'deleted' => $key]);
        }
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
    }
    if ($method === 'POST' || $method === 'DELETE') {
        switch ($action) {
            case 'clear':
                board_clear($board);
                wb_json(['ok' => true, 'board' => board_owner_view(board_by_id($board['id']))]);
            case 'close':
                wb_json(['ok' => true, 'board' => board_owner_view(board_update($board, ['status' => 'closed']))]);
            case 'open':
                wb_json(['ok' => true, 'board' => board_owner_view(board_update($board, ['status' => 'open']))]);
            case 'archive':
                wb_json(['ok' => true, 'board' => board_owner_view(board_update($board, ['status' => 'archived']))]);
            case 'duplicate':
                wb_json(['ok' => true, 'board' => board_owner_view(board_duplicate($board, isset($in['name']) ? (string)$in['name'] : null))], 201);
            case 'delete':
                board_delete_forever($board);
                wb_json(['ok' => true, 'deleted' => $key]);
            case 'posts':
                $pid = (int)($p[3] ?? 0);
                $sub = $p[4] ?? '';
                if ($pid > 0 && ($sub === 'delete' || $method === 'DELETE')) {
                    $now = wb_now();
                    db_exec('UPDATE posts SET deleted_at = ?, updated_at = ? WHERE id = ? AND board_id = ?', [$now, $now, $pid, $board['id']]);
                    db_exec('UPDATE comments SET deleted_at = ?, updated_at = ? WHERE post_id = ? AND deleted_at IS NULL', [$now, $now, $pid]);
                    board_touch($board['id']);
                    wb_json(['ok' => true]);
                }
                break;
            case 'comments':
                $cid = (int)($p[3] ?? 0);
                if ($cid > 0) {
                    $now = wb_now();
                    db_exec('UPDATE comments SET deleted_at = ?, updated_at = ? WHERE id = ? AND board_id = ?', [$now, $now, $cid, $board['id']]);
                    board_touch($board['id']);
                    wb_json(['ok' => true]);
                }
                break;
        }
    }
    wb_error('Unbekannter Aufruf.', 404);
}
