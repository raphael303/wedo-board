<?php
declare(strict_types=1);
// Schnittstelle der Aktivitäten (Zielscheibe); eingebunden von api/index.php.
if (!defined('WB_ROOT')) exit;

/* ======================= Teilnehmende: /api/a/{key}/… ======================= */

function activity_guest_api(string $method, array $p): never {
    $a = activity_by_key($p[1] ?? '');
    if (!$a || $a['status'] === 'archived') wb_error('Nicht gefunden.', 404);
    $uid = wb_client_uid();
    $owner = wb_can('board.manage');
    $lang = wb_resolve_lang($a['lang']);
    $sub = $p[2] ?? '';

    if ($method === 'GET' && $sub === '') {
        wb_json([
            'ok' => true,
            'activity' => activity_public($a, $lang),
            'me' => ['owner' => $owner, 'uid' => $uid, 'fresh' => !empty($GLOBALS['wb_uid_fresh'])],
            'state' => activity_state($a, $uid, null, $owner),
        ]);
    }
    if ($method === 'GET' && $sub === 'state') {
        $round = $owner && isset($_GET['round']) ? max(1, (int)$_GET['round']) : null;
        wb_json(['ok' => true] + activity_state($a, $uid, isset($_GET['since']) ? (string)$_GET['since'] : null, $owner, $round));
    }
    if ($sub === 'identify' && $method === 'POST') {
        $in = wb_read_json_body();
        if (!wb_set_client_uid((string)($in['uid'] ?? ''))) wb_error('Ungültiger Schlüssel.', 422);
        wb_json(['ok' => true]);
    }
    // Besitzer auf der Seite (Admin-Sitzung mit CSRF-Header): Anzeigen/Verbergen und neue Runde.
    if ($sub === 'reveal' && $method === 'POST') {
        if (!$owner) wb_error('Nicht berechtigt.', 403);
        wb_check_csrf_header();
        $in = wb_read_json_body();
        $a = activity_set_revealed($a, filter_var($in['revealed'] ?? true, FILTER_VALIDATE_BOOLEAN));
        wb_json(['ok' => true, 'state' => activity_state($a, $uid, null, true)]);
    }
    if ($sub === 'round' && $method === 'POST') {
        if (!$owner) wb_error('Nicht berechtigt.', 403);
        wb_check_csrf_header();
        $a = activity_new_round($a);
        wb_json(['ok' => true, 'state' => activity_state($a, $uid, null, true)]);
    }
    if ($sub === 'response' && $method === 'POST') {
        if (!$owner) {
            if ($a['status'] !== 'open') wb_error(t('target_closed', $lang), 403, ['code' => 'closed']);
            // Sperren für alle Boards gelten auch hier; Sperren für ein einzelnes Board nicht.
            if (guest_block_active($uid, 0) !== null) wb_error(t('blocked', $lang), 403, ['code' => 'blocked']);
            rate_limit($uid);
        }
        $in = wb_read_json_body();
        try {
            target_response_save($a, $uid, $in['points'] ?? null);
        } catch (InvalidArgumentException $e) {
            wb_error($e->getMessage(), 422);
        }
        wb_json(['ok' => true, 'state' => activity_state(activity_by_id($a['id']), $uid, null, $owner)]);
    }
    wb_error('Unbekannter Aufruf.', 404);
}

/* ======================= Besitzer: /api/activities/… ======================= */

function activity_owner_api(string $method, array $p): never {
    $key = $p[1] ?? '';
    $action = $p[2] ?? '';

    // QR-Codes sind öffentlich wie bei den Boards.
    if ($key !== '' && in_array($action, ['qr.svg', 'qr.png'], true) && $method === 'GET') {
        $a = activity_find($key);
        if (!$a) wb_error('Nicht gefunden.', 404);
        header('Cache-Control: public, max-age=86400');
        if ($action === 'qr.svg') {
            header('Content-Type: image/svg+xml');
            echo wb_qr_svg($a['url']);
        } else {
            header('Content-Type: image/png');
            echo wb_qr_png($a['url'], max(4, min(40, (int)($_GET['px'] ?? 12))));
        }
        exit;
    }

    wb_require_owner();
    db_migrate();
    $in = wb_read_json_body();

    if ($key === '') {
        if ($method === 'GET') {
            wb_json(['ok' => true, 'activities' => activities_list([
                'q' => $_GET['q'] ?? '', 'status' => $_GET['status'] ?? 'active', 'sort' => $_GET['sort'] ?? 'activity',
                'tag' => $_GET['tag'] ?? '', 'type' => $_GET['type'] ?? '',
            ])]);
        }
        if ($method === 'POST') {
            wb_json(['ok' => true, 'activity' => activity_owner_view(activity_create($in))], 201);
        }
        wb_error('Unbekannter Aufruf.', 404);
    }

    $a = activity_find($key, true);
    if (!$a) wb_error('Nicht gefunden.', 404);

    if ($action === '') {
        if ($method === 'GET') wb_json(['ok' => true, 'activity' => activity_owner_view($a)]);
        if ($method === 'PATCH' || $method === 'POST' || $method === 'PUT') wb_json(['ok' => true, 'activity' => activity_owner_view(activity_update($a, $in))]);
        if ($method === 'DELETE') { activity_trash($a); wb_json(['ok' => true, 'deleted' => $a['akey'], 'trash' => true]); }
    }
    if ($method === 'GET' && $action === 'results') {
        $round = isset($_GET['round']) ? max(1, (int)$_GET['round']) : null;
        wb_json(['ok' => true] + target_results($a, $round));
    }
    if ($method === 'GET' && $action === 'results.md') {
        header('Content-Type: text/markdown; charset=utf-8');
        echo target_results_markdown($a, isset($_GET['round']) ? max(1, (int)$_GET['round']) : null);
        exit;
    }
    if ($method === 'POST' || $method === 'DELETE') {
        switch ($action) {
            case 'reveal': wb_json(['ok' => true, 'activity' => activity_owner_view(activity_set_revealed($a, true))]);
            case 'hide': wb_json(['ok' => true, 'activity' => activity_owner_view(activity_set_revealed($a, false))]);
            case 'round': wb_json(['ok' => true, 'activity' => activity_owner_view(activity_new_round($a))]);
            case 'close': wb_json(['ok' => true, 'activity' => activity_owner_view(activity_update($a, ['status' => 'closed']))]);
            case 'open': wb_json(['ok' => true, 'activity' => activity_owner_view(activity_update($a, ['status' => 'open']))]);
            case 'archive': wb_json(['ok' => true, 'activity' => activity_owner_view(activity_update($a, ['status' => 'archived']))]);
            case 'duplicate': wb_json(['ok' => true, 'activity' => activity_owner_view(activity_duplicate($a, isset($in['name']) ? (string)$in['name'] : null))], 201);
            case 'delete': activity_trash($a); wb_json(['ok' => true, 'deleted' => $a['akey'], 'trash' => true]);
            case 'restore': wb_json(['ok' => true, 'activity' => activity_owner_view(activity_restore($a))]);
        }
    }
    wb_error('Unbekannter Aufruf.', 404);
}
