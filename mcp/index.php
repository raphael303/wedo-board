<?php
declare(strict_types=1);
/**
 * Connector für Claude (MCP, Streamable HTTP, zustandslos).
 * Adresse: <base_url>/mcp/<api-token>
 */
require dirname(__DIR__) . '/lib/bootstrap.php';
require WB_ROOT . '/lib/qr.php';

header('Cache-Control: no-store');

function mcp_out(mixed $payload, int $status = 200): never {
    http_response_code($status);
    if ($payload !== null) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

function mcp_rpc_error(mixed $id, int $code, string $message): array {
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
}

// Token prüfen: falscher oder fehlender Token sieht aus wie eine nicht existierende Seite.
$token = (string)($_GET['token'] ?? '');
$stored = db_is_installed() ? (string)setting_get('api_token') : '';
if ($token === '' || $stored === '' || !hash_equals($stored, $token)) {
    http_response_code(404);
    exit;
}
db_migrate();

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET') {
    // Kein Server-Stream: zustandsloser Betrieb.
    header('Allow: POST, DELETE, OPTIONS');
    mcp_out(['error' => 'Streamable HTTP ohne Server-Stream; bitte POST verwenden.'], 405);
}
if ($method === 'DELETE' || $method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($method !== 'POST') {
    mcp_out(['error' => 'Methode nicht erlaubt.'], 405);
}

$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '', true);
if (!is_array($body)) {
    mcp_out(mcp_rpc_error(null, -32700, 'Ungültiges JSON.'), 400);
}
$batch = array_is_list($body);
$messages = $batch ? $body : [$body];
$responses = [];
foreach ($messages as $msg) {
    $r = mcp_handle(is_array($msg) ? $msg : []);
    if ($r !== null) $responses[] = $r;
}
if (!$responses) {
    http_response_code(202);
    exit;
}
mcp_out($batch ? $responses : $responses[0]);

/* ---------------------------------------------------------------- */

function mcp_handle(array $msg): ?array {
    $id = $msg['id'] ?? null;
    $m = (string)($msg['method'] ?? '');
    $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];
    $isNotification = !array_key_exists('id', $msg);

    try {
        switch ($m) {
            case 'initialize':
                $requested = (string)($params['protocolVersion'] ?? '');
                $known = ['2024-11-05', '2025-03-26', '2025-06-18'];
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                    'protocolVersion' => in_array($requested, $known, true) ? $requested : '2025-06-18',
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => ['name' => wb_app_name(), 'version' => WB_VERSION],
                    'instructions' => wb_app_name() . " ist der private Padlet-Ersatz des Besitzers (Lehrperson) unter " . wb_base_url() . ". Arbeitsweise: Wenn bei der Unterrichtsvorbereitung ein Board gebraucht wird (Sammlung, Brainstorming, Pro/Contra, KWL-Tabelle, Feedback), lege es mit create_board selbst an, statt den Besitzer darauf zu verweisen. Wähle type=columns, wenn die Beiträge in vorgegebene Kategorien gehören, sonst wall. Setze immer eine Notiz (Klasse, Fach, Thema, Datum, Lektionsbezug) und Tags (Fach, Klasse, Thema), damit die Bibliothek später durchsuchbar bleibt. Der Link für die Klasse ist der Kurzlink aus url (z. B. " . wb_base_url() . "/tamok); er ist diktierbar und gehört in Folien, Arbeitsblätter und Materialien zusammen mit dem QR-Code aus get_board_qr (PNG, oder die Adresse qr_png?px=20 zum Einbetten). Vor dem Anlegen mit list_boards prüfen, ob ein passendes Board schon existiert; bestehende Boards für dieselbe Klasse eher leeren (clear_board, nur nach Rückfrage) oder duplizieren als neu anlegen. Nach der Lektion liest read_board die Schülerbeiträge zum Auswerten. Bündelungen, Zuordnungen, Löschungen und Leeren werden protokolliert; der Besitzer kann sie auf der Board-Seite im Verlauf rückgängig machen, du brauchst dafür kein Werkzeug. Geschlossen (status=closed) heisst lesbar, aber nicht mehr beschreibbar; archiviert heisst für die Klasse unsichtbar und Kurzwort wieder frei. delete_board nur nach ausdrücklicher Bestätigung des Besitzers. Bündeln: Ein Board hat drei Ebenen, Bündel enthalten Beiträge, Beiträge enthalten Kommentare. Auf Wunsch («bündle», «fasse zusammen», «ordne») liest du das Board mit read_board (die Beitragsnummern stehen als #id), bildest thematische Gruppen und schreibst sie mit organize_board in einem Zug zurück (Titel, kurze Zusammenfassung, Spalte und post_ids pro Bündel); neu dazugekommene lose Beiträge sortierst du später mit assign_posts nach. Die Klasse sieht danach nur die Bündel mit Titel, Zusammenfassung und Anzahl und klappt sie bei Bedarf auf. Du kannst als didaktisches Mittel auch selbst Beiträge (create_post, z. B. Aufgabenstellung, Leitfragen, Fazit) und Kommentare (create_comment, z. B. Rückmeldungen) verfassen; der Autorname ist frei wählbar, Standard ist Claude.",
            ]];
            case 'notifications/initialized':
            case 'notifications/cancelled':
            case 'notifications/progress':
                return null;
            case 'ping':
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => new stdClass()];
            case 'tools/list':
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => mcp_tools()]];
            case 'tools/call':
                $name = (string)($params['name'] ?? '');
                $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => mcp_call($name, $args)];
            case 'resources/list':
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['resources' => []]];
            case 'prompts/list':
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['prompts' => []]];
            default:
                if ($isNotification) return null;
                return mcp_rpc_error($id, -32601, 'Unbekannte Methode: ' . $m);
        }
    } catch (InvalidArgumentException $e) {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => mcp_text($e->getMessage(), true)];
    } catch (Throwable $e) {
        error_log(wb_app_name() . ' mcp: ' . $e->getMessage());
        return mcp_rpc_error($id, -32603, 'Serverfehler: ' . $e->getMessage());
    }
}

function mcp_text(string $text, bool $isError = false): array {
    $r = ['content' => [['type' => 'text', 'text' => $text]]];
    if ($isError) $r['isError'] = true;
    return $r;
}

function mcp_json(array $data): array {
    return mcp_text(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

function mcp_board_ref(array $args): array {
    $ref = trim((string)($args['board'] ?? ''));
    if ($ref === '') throw new InvalidArgumentException('Parameter «board» (Kurzwort oder Schlüssel) fehlt.');
    $b = board_find($ref);
    if (!$b) {
        // Archivierte Boards sind über das Kurzwort nicht erreichbar; der Schlüssel findet sie trotzdem.
        throw new InvalidArgumentException('Kein aktives Board mit Kurzwort oder Schlüssel «' . $ref . '». Archivierte Boards lassen sich über list_boards mit status=archived und ihren Schlüssel ansprechen.');
    }
    return $b;
}

function mcp_board_summary(array $v): array {
    return [
        'name' => $v['name'], 'slug' => $v['slug'], 'url' => $v['url'], 'key' => $v['key'],
        'type' => $v['type'], 'columns' => array_column($v['columns'], 'title'), 'status' => $v['status'],
        'background' => $v['background'], 'lang' => $v['lang'], 'note' => $v['note'], 'tags' => $v['tags'],
        'posts' => $v['post_count'], 'comments' => $v['comment_count'],
        'qr_png' => $v['qr_png'], 'qr_svg' => $v['qr_svg'],
        'created_at' => wb_local_time($v['created_at']), 'last_activity_at' => wb_local_time($v['last_activity_at']),
    ];
}

function mcp_tools(): array {
    $boardParam = ['type' => 'string', 'description' => 'Kurzwort (slug) oder Schlüssel des Boards.'];
    $boardFields = [
        'name' => ['type' => 'string', 'description' => 'Titel des Boards, wie ihn die Klasse sieht.'],
        'type' => ['type' => 'string', 'enum' => ['wall', 'columns'], 'description' => 'wall = Beiträge frei verteilt in einem platzfüllenden Raster; columns = Spalten mit Überschriften.'],
        'columns' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Spaltentitel in Reihenfolge (nur bei type=columns).'],
        'background' => ['type' => 'string', 'enum' => wb_backgrounds(), 'description' => 'Farbverlauf: morgen (Pfirsich), meer (Blau), wald (Grün), daemmerung (Violett-Orange), graphit (Dunkel), papier (Hell).'],
        'lang' => ['type' => 'string', 'enum' => wb_langs(), 'description' => 'Sprache der Oberfläche für die Klasse.'],
        'note' => ['type' => 'string', 'description' => 'Notiz für Besitzer und Claude: Klasse, Fach, Thema, Datum, Lektionsbezug. Immer setzen.'],
        'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Schlagwörter, zum Beispiel ["bio", "3b", "einstieg"]. Immer setzen.'],
        'slug' => ['type' => 'string', 'description' => 'Eigenes Kurzwort für den Link (3–32 Zeichen, a-z, 0-9, Bindestrich). Leer lassen für ein automatisches aussprechbares Wort.'],
        'settings' => ['type' => 'object', 'description' => 'Optionale Schalter: comments, reactions, images, links, new_on_top, ai (KI-Funktionen für dieses Board erlaubt; für heikle Boards wie persönliches Feedback ausschalten; jeweils boolean), emojis (Liste erlaubter Emojis aus ' . implode(' ', wb_emojis()) . ').',
            'properties' => [
                'comments' => ['type' => 'boolean'], 'reactions' => ['type' => 'boolean'], 'images' => ['type' => 'boolean'],
                'links' => ['type' => 'boolean'], 'new_on_top' => ['type' => 'boolean'], 'ai' => ['type' => 'boolean'], 'emojis' => ['type' => 'array', 'items' => ['type' => 'string']],
            ]],
    ];
    return [
        [
            'name' => 'list_boards',
            'description' => 'Listet Boards der Bibliothek mit Kurzwort, Link, Status, Notiz und Tags. Standardmässig nur aktive (offene und geschlossene) Boards.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'query' => ['type' => 'string', 'description' => 'Suchtext für Name, Notiz und Tags.'],
                'status' => ['type' => 'string', 'enum' => ['active', 'open', 'closed', 'archived', 'all'], 'description' => 'Filter nach Status; Standard active.'],
                'tag' => ['type' => 'string', 'description' => 'Nur Boards mit diesem Tag.'],
                'sort' => ['type' => 'string', 'enum' => ['activity', 'created', 'name']],
            ]],
        ],
        [
            'name' => 'get_board',
            'description' => 'Liest Einstellungen, Link, QR-Adressen, Notiz und Tags eines Boards.',
            'inputSchema' => ['type' => 'object', 'properties' => ['board' => $boardParam], 'required' => ['board']],
        ],
        [
            'name' => 'create_board',
            'description' => 'Legt ein neues Board an und liefert Link (kurz, tippbar), Schlüssel und QR-Code-Adressen. Notiz und Tags immer mitgeben.',
            'inputSchema' => ['type' => 'object', 'properties' => $boardFields, 'required' => ['name', 'note', 'tags']],
        ],
        [
            'name' => 'update_board',
            'description' => 'Ändert Name, Kurzwort, Notiz, Tags, Spalten, Hintergrund, Sprache, Einstellungen oder Status eines Boards. Nur angegebene Felder werden geändert. Bei Spalten bleiben Beiträge in ihrer Spalte, wenn die Reihenfolge erhalten bleibt.',
            'inputSchema' => ['type' => 'object', 'properties' => ['board' => $boardParam] + $boardFields + [
                'status' => ['type' => 'string', 'enum' => ['open', 'closed', 'archived'], 'description' => 'open = beschreibbar (auch zum Zurückholen aus dem Archiv); closed = nur lesbar; archived = für die Klasse unsichtbar, Kurzwort wird frei.'],
            ], 'required' => ['board']],
        ],
        [
            'name' => 'clear_board',
            'description' => 'Entfernt alle Beiträge, Kommentare und Reaktionen eines Boards; die Struktur bleibt. Vorher beim Besitzer rückfragen, wenn nicht ausdrücklich gewünscht.',
            'inputSchema' => ['type' => 'object', 'properties' => ['board' => $boardParam], 'required' => ['board']],
        ],
        [
            'name' => 'duplicate_board',
            'description' => 'Kopiert die Struktur eines Boards (Typ, Spalten, Hintergrund, Einstellungen, Notiz, Tags) ohne Inhalte in ein neues Board mit neuem Kurzwort.',
            'inputSchema' => ['type' => 'object', 'properties' => ['board' => $boardParam, 'name' => ['type' => 'string', 'description' => 'Name des neuen Boards.']], 'required' => ['board']],
        ],
        [
            'name' => 'delete_board',
            'description' => 'Legt ein Board in den Papierkorb der Bibliothek (dreissig Tage wiederherstellbar, danach endgültig weg). Nur mit confirm=true und nur nach ausdrücklicher Bestätigung des Besitzers; sonst archivieren (update_board mit status=archived).',
            'inputSchema' => ['type' => 'object', 'properties' => ['board' => $boardParam, 'confirm' => ['type' => 'boolean']], 'required' => ['board', 'confirm']],
        ],
        [
            'name' => 'read_board',
            'description' => 'Liest alle Beiträge, Kommentare und Reaktionen eines Boards, zum Beispiel um Schülerbeiträge nach der Lektion auszuwerten.',
            'inputSchema' => ['type' => 'object', 'properties' => ['board' => $boardParam, 'format' => ['type' => 'string', 'enum' => ['markdown', 'json'], 'description' => 'Standard markdown.']], 'required' => ['board']],
        ],
        [
            'name' => 'delete_post',
            'description' => 'Löscht einen einzelnen Beitrag (mit seinen Kommentaren) oder einen einzelnen Kommentar eines Boards.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'board' => $boardParam,
                'post_id' => ['type' => 'integer', 'description' => 'ID des Beitrags (aus read_board).'],
                'comment_id' => ['type' => 'integer', 'description' => 'ID des Kommentars (aus read_board), alternativ zu post_id.'],
            ], 'required' => ['board']],
        ],
        [
            'name' => 'create_post',
            'description' => 'Schreibt als Besitzer oder Claude einen Beitrag auf ein Board, zum Beispiel eine Aufgabenstellung, Leitfragen oder ein Fazit. Optional in eine Spalte oder in ein Bündel.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'board' => $boardParam,
                'title' => ['type' => 'string'],
                'body' => ['type' => 'string', 'description' => 'Text des Beitrags (Zeilenumbrüche erlaubt).'],
                'link' => ['type' => 'string', 'description' => 'Optionaler Link.'],
                'column' => ['type' => 'string', 'description' => 'Spalten-ID oder Spaltentitel (nur bei Spalten-Boards).'],
                'bundle_id' => ['type' => 'integer', 'description' => 'Bündel, in das der Beitrag gehört.'],
                'author' => ['type' => 'string', 'description' => 'Angezeigter Name, Standard Claude.'],
                'on_top' => ['type' => 'boolean', 'description' => 'Zuoberst einfügen (Standard: Einstellung des Boards).'],
            ], 'required' => ['board', 'body']],
        ],
        [
            'name' => 'create_comment',
            'description' => 'Schreibt als Besitzer oder Claude einen Kommentar zu einem Beitrag, zum Beispiel eine Rückmeldung oder Nachfrage.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'board' => $boardParam,
                'post_id' => ['type' => 'integer', 'description' => 'ID des Beitrags (aus read_board).'],
                'body' => ['type' => 'string'],
                'author' => ['type' => 'string', 'description' => 'Angezeigter Name, Standard Claude.'],
            ], 'required' => ['board', 'post_id', 'body']],
        ],
        [
            'name' => 'organize_board',
            'description' => 'Bündelt die Beiträge eines Boards in einem Zug: Liste von Bündeln mit Titel, kurzer Zusammenfassung, optional Spalte (Titel oder ID) und den post_ids. Mit replace=true (Standard) werden bestehende Bündel vorher aufgelöst; die Beiträge selbst bleiben unverändert. Beiträge ohne Bündel bleiben lose sichtbar.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'board' => $boardParam,
                'bundles' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'title' => ['type' => 'string'],
                    'summary' => ['type' => 'string', 'description' => 'Ein bis drei Sätze, die die Beiträge des Bündels zusammenfassen.'],
                    'column' => ['type' => 'string'],
                    'post_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                ], 'required' => ['title', 'post_ids']]],
                'replace' => ['type' => 'boolean'],
                'author' => ['type' => 'string', 'description' => 'Name, der an den Bündeln steht, Standard Claude.'],
            ], 'required' => ['board', 'bundles']],
        ],
        [
            'name' => 'create_bundle',
            'description' => 'Legt ein einzelnes Bündel an, optional gleich mit Beiträgen.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'board' => $boardParam,
                'title' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
                'column' => ['type' => 'string'],
                'post_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'author' => ['type' => 'string'],
            ], 'required' => ['board', 'title']],
        ],
        [
            'name' => 'update_bundle',
            'description' => 'Ändert Titel, Zusammenfassung oder Spalte eines Bündels oder ordnet Beiträge zu (post_ids ersetzt die Zuordnung ganz, add_post_ids und remove_post_ids ändern sie teilweise).',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'board' => $boardParam,
                'bundle_id' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
                'column' => ['type' => 'string'],
                'post_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'add_post_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'remove_post_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            ], 'required' => ['board', 'bundle_id']],
        ],
        [
            'name' => 'delete_bundle',
            'description' => 'Löst ein Bündel auf; seine Beiträge bleiben als lose Beiträge erhalten.',
            'inputSchema' => ['type' => 'object', 'properties' => ['board' => $boardParam, 'bundle_id' => ['type' => 'integer']], 'required' => ['board', 'bundle_id']],
        ],
        [
            'name' => 'assign_posts',
            'description' => 'Ordnet Beiträge einem Bündel zu (bundle_id) oder löst sie aus jedem Bündel (bundle_id weglassen oder 0).',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'board' => $boardParam,
                'bundle_id' => ['type' => 'integer'],
                'post_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            ], 'required' => ['board', 'post_ids']],
        ],
        [
            'name' => 'get_board_qr',
            'description' => 'Liefert den QR-Code eines Boards als PNG-Bild (für Folien und Arbeitsblätter) sowie die Bildadressen zum Einbetten.',
            'inputSchema' => ['type' => 'object', 'properties' => ['board' => $boardParam], 'required' => ['board']],
        ],
    ];
}

function mcp_call(string $name, array $a): array {
    switch ($name) {
        case 'list_boards':
            $list = boards_list(['q' => $a['query'] ?? '', 'status' => $a['status'] ?? 'active', 'tag' => $a['tag'] ?? '', 'sort' => $a['sort'] ?? 'activity']);
            if (!$list) return mcp_text('Keine Boards gefunden.');
            return mcp_json(['count' => count($list), 'boards' => array_map('mcp_board_summary', $list)]);

        case 'get_board':
            return mcp_json(mcp_board_summary(board_owner_view(mcp_board_ref($a))));

        case 'create_board':
            $b = board_create($a);
            $v = board_owner_view($b);
            return mcp_json(['created' => true] + mcp_board_summary($v));

        case 'update_board':
            $b = mcp_board_ref($a);
            unset($a['board']);
            $v = board_owner_view(board_update($b, $a));
            return mcp_json(['updated' => true] + mcp_board_summary($v));

        case 'clear_board':
            $b = mcp_board_ref($a);
            board_clear($b);
            return mcp_text('Board «' . $b['name'] . '» geleert.');

        case 'duplicate_board':
            $b = mcp_board_ref($a);
            $copy = board_duplicate($b, isset($a['name']) ? (string)$a['name'] : null);
            return mcp_json(['created' => true] + mcp_board_summary(board_owner_view($copy)));

        case 'delete_board':
            if (empty($a['confirm'])) throw new InvalidArgumentException('Löschen braucht confirm=true nach Bestätigung durch den Besitzer.');
            $b = mcp_board_ref($a);
            board_trash($b);
            return mcp_text('Board «' . $b['name'] . '» in den Papierkorb gelegt; der Besitzer kann es in der Bibliothek dreissig Tage lang wiederherstellen.');

        case 'read_board':
            $b = mcp_board_ref($a);
            if (($a['format'] ?? 'markdown') === 'json') return mcp_json(board_export_data($b));
            return mcp_text(board_export_markdown($b));

        case 'delete_post':
            $b = mcp_board_ref($a);
            if (!empty($a['comment_id'])) {
                $r = comment_soft_delete($b, (int)$a['comment_id'], true);
                return mcp_text($r >= 0 ? 'Kommentar gelöscht (der Besitzer kann das im Verlauf des Boards rückgängig machen).' : 'Kein solcher Kommentar.', $r < 0);
            }
            if (!empty($a['post_id'])) {
                $r = post_soft_delete($b, (int)$a['post_id'], true);
                return mcp_text($r >= 0 ? 'Beitrag gelöscht (der Besitzer kann das im Verlauf des Boards rückgängig machen).' : 'Kein solcher Beitrag.', $r < 0);
            }
            throw new InvalidArgumentException('post_id oder comment_id angeben.');

        case 'create_post':
            $b = mcp_board_ref($a);
            $post = owner_post_create($b, $a);
            return mcp_json(['created' => true, 'post_id' => (int)$post['id'], 'author' => $post['author_name'], 'bundle_id' => $post['bundle_id'] !== null ? (int)$post['bundle_id'] : null, 'url' => $b['url']]);

        case 'create_comment':
            $b = mcp_board_ref($a);
            $c = owner_comment_create($b, (int)($a['post_id'] ?? 0), $a);
            return mcp_json(['created' => true, 'comment_id' => (int)$c['id'], 'post_id' => (int)$c['post_id'], 'author' => $c['author_name']]);

        case 'organize_board':
            $b = mcp_board_ref($a);
            $list = is_array($a['bundles'] ?? null) ? $a['bundles'] : [];
            $replace = array_key_exists('replace', $a) ? (bool)$a['replace'] : true;
            $created = bundle_organize($b, $list, $replace, (string)($a['author'] ?? 'Claude'));
            $loose = db_row('SELECT COUNT(*) AS n FROM posts WHERE board_id = ? AND deleted_at IS NULL AND bundle_id IS NULL', [$b['id']]);
            return mcp_json(['organized' => true, 'bundles' => $created, 'loose_posts' => (int)$loose['n'], 'url' => $b['url']]);

        case 'create_bundle':
            $b = mcp_board_ref($a);
            if (empty($a['author'])) $a['author'] = 'Claude';
            return mcp_json(['created' => true] + bundle_public(bundle_create($b, $a)));

        case 'update_bundle':
            $b = mcp_board_ref($a);
            $g = bundle_by_id($b, (int)($a['bundle_id'] ?? 0));
            if (!$g) throw new InvalidArgumentException('Bündel nicht gefunden.');
            return mcp_json(['updated' => true] + bundle_public(bundle_update($b, $g, $a)));

        case 'delete_bundle':
            $b = mcp_board_ref($a);
            $g = bundle_by_id($b, (int)($a['bundle_id'] ?? 0));
            if (!$g) throw new InvalidArgumentException('Bündel nicht gefunden.');
            bundle_delete($b, $g);
            return mcp_text('Bündel «' . $g['title'] . '» aufgelöst; die Beiträge bleiben erhalten.');

        case 'assign_posts':
            $b = mcp_board_ref($a);
            $gid = !empty($a['bundle_id']) ? (int)$a['bundle_id'] : null;
            $n = bundle_assign($b, $gid, array_map('intval', (array)($a['post_ids'] ?? [])));
            return mcp_text($n . ' Beiträge ' . ($gid ? 'zugeordnet.' : 'aus Bündeln gelöst.'));

        case 'get_board_qr':
            $b = mcp_board_ref($a);
            $v = board_owner_view($b);
            return ['content' => [
                ['type' => 'text', 'text' => 'QR-Code für ' . $v['url'] . "\nPNG: " . $v['qr_png'] . "?px=20\nSVG: " . $v['qr_svg']],
                ['type' => 'image', 'data' => base64_encode(wb_qr_png($v['url'], 10)), 'mimeType' => 'image/png'],
            ]];
    }
    throw new InvalidArgumentException('Unbekanntes Werkzeug: ' . $name);
}
