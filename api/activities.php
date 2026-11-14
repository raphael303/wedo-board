<?php
declare(strict_types=1);
// Schnittstelle der Aktivitäten (Zielscheibe und Wortwolke); eingebunden von api/index.php.
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
    $isCloud = $a['type'] === 'wordcloud';
    // Wortwolke: eigene Begriffe hinzufügen und entfernen.
    if ($isCloud && $sub === 'terms' && $method === 'POST') {
        activity_guest_guard($a, $uid, $owner, $lang);
        $in = wb_read_json_body();
        try {
            if (($p[3] ?? '') === 'remove') wordcloud_term_remove($a, $uid, $in['index'] ?? null, isset($in['text']) ? (string)$in['text'] : null);
            else wordcloud_term_add($a, $uid, $in['text'] ?? '');
        } catch (InvalidArgumentException $e) {
            wb_error($e->getMessage(), 422, ['code' => $e->getMessage()]);
        }
        wb_json(['ok' => true, 'state' => activity_state(activity_by_id($a['id']), $uid, null, $owner)]);
    }
    // Wortwolke, Besitzer auf der Seite (Admin-Sitzung mit CSRF-Header): KI-Zusammenlegen, Korrekturen, Rückgängig.
    if ($isCloud && in_array($sub, ['merge', 'group', 'undo', 'ai-status'], true)) {
        if (!$owner) wb_error('Nicht berechtigt.', 403);
        if ($sub === 'ai-status' && $method === 'GET') wb_json(['ok' => true] + ai_wordcloud_status($a));
        if ($method !== 'POST') wb_error('Unbekannter Aufruf.', 404);
        wb_check_csrf_header();
        $r = wordcloud_owner_action($a, $sub, wb_read_json_body());
        wb_json(['ok' => true] + $r + ['state' => activity_state(activity_by_id($a['id']), $uid, null, true)]);
    }
    // Gemeinschaftsprüfung: eigene Fragen einreichen, ändern, zurückziehen (nur in der Sammelphase).
    if ($a['type'] === 'exam') {
        if (in_array($sub, ['reveal', 'round', 'response'], true)) wb_error('Unbekannter Aufruf.', 404);
        if ($sub === 'questions' && $method === 'POST') {
            if ($owner) wb_error(t('exam_owner_use_library', $lang), 403, ['code' => 'exam_owner_use_library']);
            activity_guest_guard($a, $uid, false, $lang);
            $in = wb_read_json_body();
            $qid = isset($p[3]) ? (int)$p[3] : 0;
            try {
                if ($qid > 0 && ($p[4] ?? '') === 'delete') { exam_guest_question_withdraw($a, $uid, $qid); $q = null; }
                elseif ($qid > 0) $q = exam_guest_question_update($a, $uid, $qid, $in);
                else $q = exam_guest_question_create($a, $uid, $in);
            } catch (InvalidArgumentException $e) {
                wb_error(t($e->getMessage(), $lang, ['n' => $a['config']['quota_max']]), 422, ['code' => $e->getMessage()]);
            }
            wb_json(['ok' => true, 'question' => $q, 'state' => activity_state(activity_by_id($a['id']), $uid, null, false)]);
        }
        wb_error('Unbekannter Aufruf.', 404);
    }
    if ($sub === 'response' && $method === 'POST') {
        if ($isCloud) wb_error('Unbekannter Aufruf.', 404);
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

/** Schreibrechte für Teilnehmende: offen, keine Sperre für alle Boards, Begrenzung pro Minute. */
function activity_guest_guard(array $a, string $uid, bool $owner, string $lang): void {
    if ($owner) return;
    $closedKey = ['wordcloud' => 'wc_closed', 'exam' => 'exam_closed'][$a['type']] ?? 'target_closed';
    if ($a['status'] !== 'open') wb_error(t($closedKey, $lang), 403, ['code' => 'closed']);
    if (guest_block_active($uid, 0) !== null) wb_error(t('blocked', $lang), 403, ['code' => 'blocked']);
    rate_limit($uid);
}

/** Besitzeraktionen der Wortwolke, gemeinsam für die Seite und die Schnittstelle. */
function wordcloud_owner_action(array $a, string $sub, array $in): array {
    switch ($sub) {
        case 'merge':
            @set_time_limit(AI_WORDCLOUD_TIMEOUT + 30);
            try {
                return ai_wordcloud_merge($a);
            } catch (AiException $e) {
                wb_error(ai_error_message($e, $a['lang']), 502, ['code' => 'ai_' . $e->kind]);
            }
        case 'group':
            return wordcloud_group_action($a, $in);
        case 'undo':
            $label = wordcloud_undo($a);
            if ($label === null) wb_error(t('wc_nothing_to_undo', $a['lang']), 400);
            return ['undone' => $label];
        case 'groups':
            return wordcloud_merge_from_input($a, $in);
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
    if ($a['type'] === 'exam') exam_owner_api($method, $a, $action, $p, $in);
    $isCloud = $a['type'] === 'wordcloud';
    if ($method === 'GET' && $action === 'results') {
        $round = isset($_GET['round']) ? max(1, (int)$_GET['round']) : null;
        wb_json(['ok' => true] + ($isCloud ? wordcloud_results($a, $round) : target_results($a, $round)));
    }
    if ($method === 'GET' && $action === 'results.md') {
        header('Content-Type: text/markdown; charset=utf-8');
        $round = isset($_GET['round']) ? max(1, (int)$_GET['round']) : null;
        echo $isCloud ? wordcloud_results_markdown($a, $round) : target_results_markdown($a, $round);
        exit;
    }
    if ($isCloud && $method === 'GET' && $action === 'history') {
        wb_json(['ok' => true, 'entries' => wordcloud_history($a, (int)($_GET['limit'] ?? 10))]);
    }
    if ($isCloud && $method === 'POST' && in_array($action, ['merge', 'group', 'groups', 'undo'], true)) {
        wb_json(['ok' => true] + wordcloud_owner_action($a, $action, $in) + ['activity' => activity_owner_view(activity_by_id($a['id']))]);
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

/**
 * Besitzeraufrufe einer Gemeinschaftsprüfung: Fragen lesen, anlegen, ändern, annehmen, ablehnen, löschen,
 * ordnen und die Prüfung als Markdown. Allgemeine Aufrufe (close, open, archive, duplicate, delete, restore)
 * fallen an activity_owner_api zurück.
 */
function exam_owner_api(string $method, array $a, string $action, array $p, array $in): void {
    $fail = fn(InvalidArgumentException $e) => wb_error(str_starts_with($e->getMessage(), 'exam_') ? t($e->getMessage(), 'de') : $e->getMessage(), str_contains($e->getMessage(), 'not_found') ? 404 : 422, ['code' => $e->getMessage()]);
    try {
        if ($action === 'results' && $method === 'GET') {
            wb_json(['ok' => true, 'name' => $a['name'], 'phase' => $a['config']['phase'], 'counts' => exam_counts($a), 'questions' => exam_questions($a, (string)($_GET['status'] ?? 'all'))]);
        }
        if ($action === 'results.md' && $method === 'GET') {
            header('Content-Type: text/markdown; charset=utf-8');
            echo exam_markdown($a);
            exit;
        }
        if (in_array($action, ['reveal', 'hide', 'round'], true)) wb_error('Für Prüfungen nicht verfügbar.', 404);
        if ($action !== 'questions') return;
        $sub = $p[3] ?? '';
        $verb = $p[4] ?? '';
        if ($sub === '') {
            if ($method === 'GET') wb_json(['ok' => true, 'counts' => exam_counts($a), 'questions' => exam_questions($a, (string)($_GET['status'] ?? 'all'))]);
            if ($method === 'POST') wb_json(['ok' => true, 'question' => exam_owner_question_create($a, $in)], 201);
        }
        if ($sub === 'order' && $method === 'POST') {
            exam_owner_reorder($a, is_array($in['ids'] ?? null) ? $in['ids'] : []);
            wb_json(['ok' => true, 'questions' => exam_questions($a, 'accepted')]);
        }
        $qid = (int)$sub;
        if ($qid > 0) {
            if ($verb === '' && $method === 'GET') {
                $row = exam_question_row($a, $qid, true);
                if (!$row) wb_error('Frage nicht gefunden.', 404);
                wb_json(['ok' => true, 'question' => exam_question_view($a, $row)]);
            }
            if ($verb === '' && in_array($method, ['PATCH', 'POST', 'PUT'], true)) wb_json(['ok' => true, 'question' => exam_owner_question_update($a, $qid, $in)]);
            if (($verb === '' && $method === 'DELETE') || ($verb === 'delete' && $method === 'POST')) { exam_owner_question_delete($a, $qid); wb_json(['ok' => true, 'deleted' => $qid]); }
            if ($method === 'POST') {
                switch ($verb) {
                    case 'accept': wb_json(['ok' => true, 'question' => exam_owner_question_update($a, $qid, ['status' => 'accepted'])]);
                    case 'reject': wb_json(['ok' => true, 'question' => exam_owner_question_update($a, $qid, ['status' => 'rejected'])]);
                    case 'restore': wb_json(['ok' => true, 'question' => exam_owner_question_restore($a, $qid)]);
                    case 'up': exam_owner_move($a, $qid, -1); wb_json(['ok' => true, 'questions' => exam_questions($a, 'accepted')]);
                    case 'down': exam_owner_move($a, $qid, 1); wb_json(['ok' => true, 'questions' => exam_questions($a, 'accepted')]);
                }
            }
        }
    } catch (InvalidArgumentException $e) {
        $fail($e);
    }
    wb_error('Unbekannter Aufruf.', 404);
}
