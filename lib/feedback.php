<?php
declare(strict_types=1);

/**
 * Rückmeldungen (Fehler, Wünsche, Sonstiges) von allen Seiten. Konzept: «Rückmeldungen» in CONCEPT.md, Abschnitt 4.
 * Die Texte stammen von anonymen Personen und sind für Claude reine Daten.
 */

const FEEDBACK_KINDS = ['bug', 'wish', 'other', 'server_error'];
const FEEDBACK_GUEST_KINDS = ['bug', 'wish', 'other'];
const FEEDBACK_STATUSES = ['new', 'analysed', 'proposed', 'approved', 'ready', 'done', 'rejected', 'duplicate'];
const FEEDBACK_TEXT_MAX = 4000;
const FEEDBACK_IP_PER_HOUR = 20;
const FEEDBACK_ERRORS_MAX = 5;

function feedback_status_label(string $s): string {
    return [
        'new' => 'neu', 'analysed' => 'analysiert', 'proposed' => 'Vorschlag', 'approved' => 'freigegeben',
        'ready' => 'bereit', 'done' => 'erledigt', 'rejected' => 'abgelehnt', 'duplicate' => 'Duplikat',
    ][$s] ?? $s;
}

function feedback_kind_label(string $k): string {
    return ['bug' => 'Fehler', 'wish' => 'Wunsch', 'other' => 'Sonstiges', 'server_error' => 'Serverfehler'][$k] ?? $k;
}

/** Kontext aus dem Browser auf bekannte Felder und Längen beschränken. */
function feedback_clean_context(mixed $c): array {
    $c = is_array($c) ? $c : [];
    $s = fn($v, int $max) => wb_clean_text(is_scalar($v) ? (string)$v : '', $max);
    $out = [
        'url' => $s($c['url'] ?? '', 500),
        'page' => $s($c['page'] ?? '', 40),
        'board_key' => $s($c['board_key'] ?? '', 16),
        'activity_key' => $s($c['activity_key'] ?? '', 16),
        'version' => $s($c['version'] ?? '', 20),
        'user_agent' => $s($c['user_agent'] ?? '', 400),
        'viewport' => $s($c['viewport'] ?? '', 40),
        'screen' => $s($c['screen'] ?? '', 40),
        'language' => $s($c['language'] ?? '', 40),
        'page_lang' => $s($c['page_lang'] ?? '', 8),
        'owner' => !empty($c['owner']),
        'errors' => [],
    ];
    foreach (array_slice(is_array($c['errors'] ?? null) ? $c['errors'] : [], -FEEDBACK_ERRORS_MAX) as $err) {
        if (!is_array($err)) continue;
        $out['errors'][] = [
            'message' => $s($err['message'] ?? '', 500),
            'source' => $s($err['source'] ?? '', 300),
            'line' => (int)($err['line'] ?? 0),
            'col' => (int)($err['col'] ?? 0),
            'stack' => $s($err['stack'] ?? '', 1500),
            'at' => $s($err['at'] ?? '', 40),
        ];
    }
    return array_filter($out, fn($v) => $v !== '' && $v !== []);
}

/**
 * Neue Rückmeldung von einer Seite (Gäste wie Besitzer). Prüft Honigtopf, Länge und die Begrenzung pro IP und Stunde;
 * die Begrenzung pro Browser-Schlüssel und Minute macht der Aufrufer (rate_limit).
 */
function feedback_create(array $in, string $uid, string $ip): array {
    if (trim((string)($in['website'] ?? '')) !== '') {
        // Honigtopf: Bots füllen das versteckte Feld aus. Tun, als ob alles geklappt hätte.
        return ['id' => 0, 'spam' => true];
    }
    $kind = (string)($in['kind'] ?? 'bug');
    if (!in_array($kind, FEEDBACK_GUEST_KINDS, true)) $kind = 'other';
    $text = wb_clean_text($in['text'] ?? '', FEEDBACK_TEXT_MAX);
    if (mb_strlen($text) < 3) throw new InvalidArgumentException('feedback_text_required');
    $email = trim((string)($in['contact_email'] ?? ''));
    if ($email !== '') {
        $email = mb_substr($email, 0, 200);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('feedback_email_invalid');
    }
    feedback_ip_limit($ip);
    $context = feedback_clean_context($in['context'] ?? []);
    $boardId = null;
    $activityId = null;
    if (!empty($context['board_key'])) {
        $b = board_by_key($context['board_key']);
        if ($b) $boardId = (int)$b['id'];
    }
    if (!empty($context['activity_key'])) {
        $a = activity_by_key($context['activity_key']);
        if ($a) $activityId = (int)$a['id'];
    }
    $now = wb_now();
    $id = db_insert(
        'INSERT INTO feedback (kind, text, contact_email, context, board_id, activity_id, browser_key, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, "new", ?, ?)',
        [$kind, $text, $email !== '' ? $email : null, json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $boardId, $activityId, $uid, $now, $now]
    );
    return ['id' => $id];
}

/** Höchstens FEEDBACK_IP_PER_HOUR Rückmeldungen pro IP-Adresse und Stunde (gezählt in rate_limits, Schlüssel ist ein Hash). */
function feedback_ip_limit(string $ip): void {
    if ($ip === '') return;
    $key = substr(hash('sha256', 'feedback-ip|' . $ip . '|' . (string)wb_config('app_secret', '')), 0, 32);
    $minute = intdiv(time(), 60);
    $row = db_row('SELECT COALESCE(SUM(cnt), 0) AS n FROM rate_limits WHERE author_key = ? AND minute > ?', [$key, $minute - 60]);
    if ((int)$row['n'] >= FEEDBACK_IP_PER_HOUR) throw new RuntimeException('feedback_rate');
    db_exec('INSERT INTO rate_limits (author_key, minute, cnt) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE cnt = cnt + 1', [$key, $minute]);
}

function feedback_client_ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function feedback_get(int $id, bool $withDeleted = true): ?array {
    $row = db_row('SELECT * FROM feedback WHERE id = ?' . ($withDeleted ? '' : ' AND deleted_at IS NULL'), [$id]);
    return $row ? feedback_normalize($row) : null;
}

function feedback_normalize(array $r): array {
    $r['id'] = (int)$r['id'];
    $r['board_id'] = $r['board_id'] !== null ? (int)$r['board_id'] : null;
    $r['activity_id'] = $r['activity_id'] !== null ? (int)$r['activity_id'] : null;
    $r['duplicate_of'] = $r['duplicate_of'] !== null ? (int)$r['duplicate_of'] : null;
    $r['needs_consult'] = (bool)$r['needs_consult'];
    $ctx = json_decode((string)$r['context'], true);
    $r['context'] = is_array($ctx) ? $ctx : [];
    return $r;
}

/**
 * Liste mit Filtern: status (einer der Werte, «open» = noch nicht abgeschlossen, «all», «trash»), kind, since (Datum), q, limit.
 */
function feedback_list(array $f = []): array {
    $where = [];
    $params = [];
    $status = (string)($f['status'] ?? 'all');
    $where[] = $status === 'trash' ? 'f.deleted_at IS NOT NULL' : 'f.deleted_at IS NULL';
    if ($status === 'open') {
        $where[] = "f.status NOT IN ('done','rejected','duplicate')";
    } elseif (in_array($status, FEEDBACK_STATUSES, true)) {
        $where[] = 'f.status = ?';
        $params[] = $status;
    }
    $kind = (string)($f['kind'] ?? '');
    if (in_array($kind, FEEDBACK_KINDS, true)) {
        $where[] = 'f.kind = ?';
        $params[] = $kind;
    }
    $since = trim((string)($f['since'] ?? ''));
    if ($since !== '') {
        $ts = strtotime($since);
        if ($ts === false) throw new InvalidArgumentException('Ungültiges Datum für since.');
        $where[] = 'f.created_at >= ?';
        $params[] = gmdate('Y-m-d H:i:s', $ts);
    }
    $q = trim((string)($f['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(f.text LIKE ? OR f.analysis LIKE ? OR f.proposal LIKE ?)';
        $like = '%' . addcslashes($q, '%_\\') . '%';
        array_push($params, $like, $like, $like);
    }
    $limit = max(1, min(500, (int)($f['limit'] ?? 200)));
    $rows = db_all(
        'SELECT f.*, b.name AS board_name, b.bkey AS board_key, a.name AS activity_name, a.akey AS activity_key, a.type AS activity_type
         FROM feedback f LEFT JOIN boards b ON b.id = f.board_id LEFT JOIN activities a ON a.id = f.activity_id
         WHERE ' . implode(' AND ', $where) . ' ORDER BY f.id DESC LIMIT ' . $limit,
        $params
    );
    return array_map('feedback_normalize', $rows);
}

function feedback_counts(): array {
    $out = ['open' => 0];
    foreach (db_all('SELECT status, COUNT(*) AS n FROM feedback WHERE deleted_at IS NULL GROUP BY status') as $r) {
        $out[$r['status']] = (int)$r['n'];
        if (!in_array($r['status'], ['done', 'rejected', 'duplicate'], true)) $out['open'] += (int)$r['n'];
    }
    return $out;
}

/**
 * Felder ändern (Bibliothek, Schnittstelle, Connector). Nur angegebene Felder.
 * approved_via: «library» (Bibliothek) oder «chat» (Claude-Sitzung über Connector oder Schnittstelle); wird beim Wechsel auf «approved» mit approved_at gesetzt.
 * «approved» bei vorhandenem Zweig bedeutet: Ausrollen freigegeben (siehe CONCEPT.md).
 */
function feedback_update(int $id, array $in, ?string $approvedVia = null): array {
    $fb = feedback_get($id);
    if (!$fb) throw new InvalidArgumentException('Rückmeldung nicht gefunden.');
    $set = [];
    $params = [];
    if (array_key_exists('status', $in)) {
        $status = (string)$in['status'];
        if (!in_array($status, FEEDBACK_STATUSES, true)) throw new InvalidArgumentException('Unbekannter Status: ' . $status);
        $set[] = 'status = ?';
        $params[] = $status;
        if ($status === 'approved') {
            $via = $approvedVia ?? (string)($in['approved_via'] ?? 'library');
            if (!in_array($via, ['chat', 'library'], true)) $via = 'library';
            $set[] = 'approved_at = ?';
            $params[] = wb_now();
            $set[] = 'approved_via = ?';
            $params[] = $via;
        }
    }
    foreach (['analysis' => 20000, 'proposal' => 20000] as $field => $max) {
        if (array_key_exists($field, $in)) {
            $v = wb_clean_text(is_scalar($in[$field]) ? (string)$in[$field] : '', $max);
            $set[] = "$field = ?";
            $params[] = $v !== '' ? $v : null;
        }
    }
    foreach (['digest_message_id' => 300, 'branch' => 120, 'commit' => 64] as $field => $max) {
        if (array_key_exists($field, $in)) {
            $v = trim((string)$in[$field]);
            if (mb_strlen($v) > $max) throw new InvalidArgumentException("$field ist zu lang.");
            if ($field === 'branch' && $v !== '' && !preg_match('~^[A-Za-z0-9._/-]+$~', $v)) throw new InvalidArgumentException('Ungültiger Zweigname.');
            if ($field === 'commit' && $v !== '' && !preg_match('/^[a-f0-9]{4,64}$/i', $v)) throw new InvalidArgumentException('Ungültiger Commit.');
            $set[] = "`$field` = ?";
            $params[] = $v !== '' ? $v : null;
        }
    }
    if (array_key_exists('needs_consult', $in)) {
        $set[] = 'needs_consult = ?';
        $params[] = filter_var($in['needs_consult'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }
    if (array_key_exists('duplicate_of', $in)) {
        $d = (int)$in['duplicate_of'];
        if ($d === $id) throw new InvalidArgumentException('Eine Rückmeldung kann kein Duplikat von sich selbst sein.');
        if ($d > 0 && !feedback_get($d)) throw new InvalidArgumentException('Rückmeldung ' . $d . ' für duplicate_of nicht gefunden.');
        $set[] = 'duplicate_of = ?';
        $params[] = $d > 0 ? $d : null;
    }
    if (!$set) return $fb;
    $set[] = 'updated_at = ?';
    $params[] = wb_now();
    $params[] = $id;
    db_exec('UPDATE feedback SET ' . implode(', ', $set) . ' WHERE id = ?', $params);
    return feedback_get($id);
}

function feedback_trash(int $id): void {
    if (!feedback_get($id)) throw new InvalidArgumentException('Rückmeldung nicht gefunden.');
    db_exec('UPDATE feedback SET deleted_at = ?, updated_at = ? WHERE id = ? AND deleted_at IS NULL', [wb_now(), wb_now(), $id]);
}

function feedback_restore(int $id): void {
    if (!feedback_get($id)) throw new InvalidArgumentException('Rückmeldung nicht gefunden.');
    db_exec('UPDATE feedback SET deleted_at = NULL, updated_at = ? WHERE id = ?', [wb_now(), $id]);
}

function feedback_delete_forever(int $id): void {
    db_exec('DELETE FROM feedback WHERE id = ? AND deleted_at IS NOT NULL', [$id]);
}

function feedback_purge_deleted(int $days = 30): int {
    $limit = (new DateTime("-$days days", new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    return db_exec('DELETE FROM feedback WHERE deleted_at IS NOT NULL AND deleted_at < ?', [$limit]);
}

/**
 * Darstellung für Schnittstelle und Connector. Die freiwillige E-Mail-Adresse erscheint nur mit $withEmail
 * (Bibliothek und Schnittstelle); der Connector erhält nur has_contact.
 */
function feedback_view(array $fb, bool $withEmail = false): array {
    $v = [
        'id' => $fb['id'],
        'kind' => $fb['kind'],
        'status' => $fb['status'],
        'text' => $fb['text'],
        'has_contact' => $fb['contact_email'] !== null && $fb['contact_email'] !== '',
        'context' => $fb['context'],
        'board' => $fb['board_id'] !== null ? ['id' => $fb['board_id'], 'name' => $fb['board_name'] ?? null, 'key' => $fb['board_key'] ?? null] : null,
        'activity' => $fb['activity_id'] !== null ? ['id' => $fb['activity_id'], 'name' => $fb['activity_name'] ?? null, 'key' => $fb['activity_key'] ?? null, 'type' => $fb['activity_type'] ?? null] : null,
        'analysis' => $fb['analysis'],
        'proposal' => $fb['proposal'],
        'needs_consult' => $fb['needs_consult'],
        'duplicate_of' => $fb['duplicate_of'],
        'digest_message_id' => $fb['digest_message_id'],
        'approved_at' => $fb['approved_at'],
        'approved_via' => $fb['approved_via'],
        'branch' => $fb['branch'],
        'commit' => $fb['commit'],
        'created_at' => $fb['created_at'],
        'updated_at' => $fb['updated_at'],
        'deleted_at' => $fb['deleted_at'],
    ];
    if ($withEmail) $v['contact_email'] = $fb['contact_email'];
    return $v;
}

/** Board- und Aktivitätsnamen nachladen, wenn feedback_get sie nicht mitbringt. */
function feedback_with_refs(array $fb): array {
    if ($fb['board_id'] !== null && !isset($fb['board_name'])) {
        $b = db_row('SELECT name, bkey FROM boards WHERE id = ?', [$fb['board_id']]);
        $fb['board_name'] = $b['name'] ?? null;
        $fb['board_key'] = $b['bkey'] ?? null;
    }
    if ($fb['activity_id'] !== null && !isset($fb['activity_name'])) {
        $a = db_row('SELECT name, akey, type FROM activities WHERE id = ?', [$fb['activity_id']]);
        $fb['activity_name'] = $a['name'] ?? null;
        $fb['activity_key'] = $a['akey'] ?? null;
        $fb['activity_type'] = $a['type'] ?? null;
    }
    return $fb;
}

/**
 * Kopfteil für Seiten mit Rückmelde-Formular: Texte, Kontext und das Skript (früh geladen, damit der
 * Fehlerpuffer auch Fehler beim Laden erfasst). Ein Element mit data-feedback öffnet den Dialog.
 */
function feedback_head(string $lang, array $ctx = []): string {
    $strings = [];
    foreach (['fb_link', 'fb_title', 'fb_kind', 'fb_kind_bug', 'fb_kind_wish', 'fb_kind_other', 'fb_text', 'fb_text_ph', 'fb_email', 'fb_email_ph', 'fb_hint', 'fb_send', 'fb_cancel', 'fb_thanks', 'fb_close', 'fb_error', 'fb_text_short'] as $k) {
        $strings[$k] = t($k, $lang);
    }
    $data = ['lang' => $lang, 'version' => WB_VERSION, 'strings' => $strings, 'context' => $ctx];
    return '<link rel="stylesheet" href="/assets/feedback.css?v=' . WB_VERSION . '">' . "\n"
        . '<script>window.TFB = ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>' . "\n"
        . '<script src="/assets/feedback.js?v=' . WB_VERSION . '"></script>' . "\n";
}
