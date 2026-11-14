<?php
declare(strict_types=1);

/*
 * Aktivitäten: interaktive Formate neben den Boards (die Feedbackzielscheibe, Typ «target», und die Wortwolke,
 * Typ «wordcloud», siehe lib/wordcloud.php; vorgesehen sind weitere Menti-artige Typen wie Umfrage). Eine Aktivität teilt mit den
 * Boards den Namensraum der Kurzwörter, Notiz, Tags, Status und Papierkorb, hat aber eigene Tabellen,
 * eine eigene Seite (activity.php), eigene Routen (/api/a/…, /api/activities/…) und eigene Connector-Werkzeuge.
 *
 * Zielscheibe: Ein Kreis ist in Sektoren geteilt, jeder Sektor trägt einen Aspekt. Jede Person setzt pro
 * Sektor einen Punkt; je näher an der Mitte, desto besser. Ein Punkt wird als {r, t} gespeichert:
 * r = Abstand von der Mitte (0 = Mitte, 1 = Rand), t = Lage innerhalb des Sektors (0 = Anfang, 1 = Ende,
 * im Uhrzeigersinn ab zwölf Uhr). Die Wertung ist 10 · (1 − r), also 10 in der Mitte und 0 am Rand.
 * Antworten gehören zu einer Runde; «Neue Runde» beginnt leer und verbirgt die Punkte wieder, ältere Runden
 * bleiben für den Besitzer lesbar (zum Beispiel für einen Vorher-nachher-Vergleich).
 */

const ACTIVITY_TYPES = ['target', 'wordcloud', 'exam'];
const TARGET_MIN_ASPECTS = 2;
const TARGET_MAX_ASPECTS = 12;
const TARGET_COLOR_MODES = ['sector', 'scale'];

/* ---------- Kurzwörter (gemeinsamer Namensraum mit den Boards) ---------- */

/** Ist das Kurzwort bei einer aktiven Aktivität vergeben? Vor Migration 9 gibt es die Tabelle noch nicht. */
function activity_slug_taken(string $slug, ?int $exceptId = null): bool {
    try {
        $row = db_row("SELECT id FROM activities WHERE slug = ? AND status <> 'archived' AND deleted_at IS NULL" . ($exceptId ? ' AND id <> ?' : ''), $exceptId ? [$slug, $exceptId] : [$slug]);
    } catch (PDOException $e) {
        return false;
    }
    return $row !== null;
}

/** Kurzwort frei für eine Aktivität: weder bei einem Board noch bei einer anderen Aktivität vergeben. */
function activity_slug_in_use(string $slug, ?int $exceptId = null): bool {
    if (activity_slug_taken($slug, $exceptId)) return true;
    return db_row("SELECT id FROM boards WHERE slug = ? AND status <> 'archived' AND deleted_at IS NULL", [$slug]) !== null;
}

function activity_free_slug(): string {
    for ($i = 0; $i < 50; $i++) {
        $w = wb_short_word();
        if (!in_array($w, wb_reserved_slugs(), true) && !activity_slug_in_use($w)) return $w;
    }
    return wb_short_word() . random_int(2, 9);
}

function wb_activity_url(string $key, ?string $slug = null): string {
    return wb_base_url() . '/' . ($slug !== null && $slug !== '' ? $slug : 'a/' . $key);
}

/* ---------- Laden und Normalisieren ---------- */

function activity_by_key(string $key, bool $withTrash = false): ?array {
    if (!preg_match('/^[a-z0-9]{16}$/', $key)) return null;
    try {
        $a = db_row('SELECT * FROM activities WHERE akey = ?' . ($withTrash ? '' : ' AND deleted_at IS NULL'), [$key]);
    } catch (PDOException $e) {
        return null;
    }
    return $a ? activity_normalize($a) : null;
}

function activity_by_slug(string $slug): ?array {
    $slug = wb_valid_slug($slug);
    if ($slug === null) return null;
    try {
        $a = db_row("SELECT * FROM activities WHERE slug = ? AND status <> 'archived' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1", [$slug]);
    } catch (PDOException $e) {
        return null;
    }
    return $a ? activity_normalize($a) : null;
}

/** Findet eine Aktivität über Schlüssel oder Kurzwort. */
function activity_find(string $ref, bool $withTrash = false): ?array {
    $ref = trim($ref);
    return activity_by_key($ref, $withTrash) ?? activity_by_slug($ref);
}

function activity_by_id(int $id): ?array {
    $a = db_row('SELECT * FROM activities WHERE id = ?', [$id]);
    return $a ? activity_normalize($a) : null;
}

function target_default_config(): array {
    return ['aspects' => [], 'color_mode' => 'sector', 'require_all' => true];
}

function activity_default_config(string $type): array {
    if ($type === 'exam') return exam_default_config();
    return $type === 'wordcloud' ? wordcloud_default_config() : target_default_config();
}

function activity_normalize(array $a): array {
    $a['id'] = (int)$a['id'];
    $a['owner_user_id'] = (int)($a['owner_user_id'] ?? 1);
    $a['round'] = (int)($a['round'] ?? 1);
    $a['revealed'] = (bool)(int)($a['revealed'] ?? 0);
    $a['deleted_at'] = $a['deleted_at'] ?? null;
    $a['config'] = array_merge(activity_default_config((string)$a['type']), json_decode($a['config_json'] ?? '{}', true) ?: []);
    if ($a['type'] === 'wordcloud') {
        $a['config'] = wordcloud_cast_config($a['config']);
    } elseif ($a['type'] === 'exam') {
        $a['config'] = exam_cast_config($a['config']);
    } else {
        $a['config']['require_all'] = (bool)$a['config']['require_all'];
    }
    $a['tags'] = wb_parse_tags($a['tags'] ?? '');
    unset($a['config_json']);
    $a['slug'] = $a['slug'] ?? null;
    $a['url'] = wb_activity_url($a['akey'], $a['status'] === 'archived' ? null : $a['slug']);
    $a['long_url'] = wb_activity_url($a['akey']);
    return $a;
}

/** Was die Seite der Klasse über die Aktivität wissen muss (ohne Notiz und Tags). */
/** Die Felder der Konfiguration, die Seite, Schnittstelle und Connector zeigen (je nach Typ). */
function activity_config_view(array $a, bool $owner = true): array {
    if ($a['type'] === 'exam') return $owner ? exam_config_view($a) : exam_public_config($a);
    if ($a['type'] === 'wordcloud') {
        return ['max_terms' => $a['config']['max_terms'], 'live' => $a['config']['live'], 'ai' => $a['config']['ai']];
    }
    return ['aspects' => $a['config']['aspects'], 'color_mode' => $a['config']['color_mode'], 'require_all' => $a['config']['require_all']];
}

function activity_public(array $a, string $lang): array {
    return [
        'key' => $a['akey'],
        'type' => $a['type'],
        'name' => $a['name'],
    ] + activity_config_view($a, false) + [
        'background' => $a['background'],
        'lang' => $lang,
        'status' => $a['status'],
        'round' => $a['round'],
        'url' => $a['url'],
    ];
}

function activity_response_count(array $a, ?int $round = null): int {
    $row = db_row('SELECT COUNT(*) AS n FROM activity_responses WHERE activity_id = ? AND round = ?', [$a['id'], $round ?? $a['round']]);
    return (int)$row['n'];
}

/** Runden mit Anzahl Antworten, neueste zuerst. */
function activity_rounds(array $a): array {
    $rows = db_all('SELECT round, COUNT(*) AS n, MIN(created_at) AS first_at, MAX(updated_at) AS last_at FROM activity_responses WHERE activity_id = ? GROUP BY round ORDER BY round DESC', [$a['id']]);
    $out = [];
    $seenCurrent = false;
    foreach ($rows as $r) {
        if ((int)$r['round'] === $a['round']) $seenCurrent = true;
        $out[] = ['round' => (int)$r['round'], 'responses' => (int)$r['n'], 'current' => (int)$r['round'] === $a['round'], 'first_at' => wb_local_time($r['first_at']), 'last_at' => wb_local_time($r['last_at'])];
    }
    if (!$seenCurrent) array_unshift($out, ['round' => $a['round'], 'responses' => 0, 'current' => true, 'first_at' => null, 'last_at' => null]);
    return $out;
}

function activity_owner_view(array $a): array {
    return [
        'id' => $a['id'],
        'key' => $a['akey'],
        'slug' => $a['slug'],
        'type' => $a['type'],
        'name' => $a['name'],
    ] + activity_config_view($a) + [
        'background' => $a['background'],
        'lang' => $a['lang'],
        'status' => $a['status'],
        'revealed' => $a['revealed'],
        'round' => $a['round'],
        'response_count' => $a['type'] === 'exam' ? exam_counts($a)['contributors'] : activity_response_count($a),
    ] + ($a['type'] === 'exam' ? ['questions' => exam_counts($a), 'attempts' => exam_attempt_counts($a)] : []) + [
        'note' => (string)($a['note'] ?? ''),
        'tags' => $a['tags'],
        'url' => $a['url'],
        'long_url' => $a['long_url'],
        'qr_svg' => wb_base_url() . '/api/activities/' . $a['akey'] . '/qr.svg',
        'qr_png' => wb_base_url() . '/api/activities/' . $a['akey'] . '/qr.png',
        'created_at' => $a['created_at'],
        'updated_at' => $a['updated_at'],
        'last_activity_at' => $a['last_activity_at'],
        'deleted_at' => $a['deleted_at'],
    ];
}

/* ---------- Eingaben prüfen ---------- */

/**
 * Aspekte als Liste von Texten oder Objekten {id, label}. Texte ohne id behalten die id eines bestehenden
 * Aspekts mit gleichem Wortlaut, damit Antworten erhalten bleiben; sonst entsteht ein neuer Aspekt.
 */
function target_normalize_aspects($input, array $current = []): array {
    if (is_string($input)) $input = preg_split('/\r?\n|\s*;\s*/', $input) ?: [];
    if (!is_array($input)) return [];
    $byLabel = [];
    foreach ($current as $c) $byLabel[mb_strtolower($c['label'])] = $c['id'];
    $out = [];
    $used = [];
    foreach ($input as $item) {
        if (is_string($item)) $item = ['label' => $item];
        if (!is_array($item)) continue;
        $label = wb_clean_text(str_replace("\n", ' ', (string)($item['label'] ?? $item['title'] ?? '')), 60);
        if ($label === '') continue;
        $id = isset($item['id']) && preg_match('/^[a-z0-9]{1,16}$/', (string)$item['id']) ? (string)$item['id'] : null;
        if ($id === null && isset($byLabel[mb_strtolower($label)])) $id = $byLabel[mb_strtolower($label)];
        if ($id === null || isset($used[$id])) $id = wb_random_key(6);
        $used[$id] = true;
        $out[] = ['id' => $id, 'label' => $label];
        if (count($out) >= TARGET_MAX_ASPECTS) break;
    }
    return $out;
}

function target_check_aspects(array $aspects): void {
    if (count($aspects) < TARGET_MIN_ASPECTS) {
        throw new InvalidArgumentException('Eine Zielscheibe braucht mindestens ' . TARGET_MIN_ASPECTS . ' und höchstens ' . TARGET_MAX_ASPECTS . ' Aspekte.');
    }
}

function target_normalize_config(array $in, array $current): array {
    $c = $current;
    if (array_key_exists('aspects', $in)) {
        $c['aspects'] = target_normalize_aspects($in['aspects'], $current['aspects'] ?? []);
        target_check_aspects($c['aspects']);
    }
    if (array_key_exists('color_mode', $in)) {
        $m = (string)$in['color_mode'];
        if (in_array($m, ['scale', 'ampel', 'traffic', 'gradient'], true)) $m = 'scale';
        if (!in_array($m, TARGET_COLOR_MODES, true)) throw new InvalidArgumentException('color_mode ist «sector» (eine Farbe pro Sektor) oder «scale» (grün in der Mitte, rot am Rand).');
        $c['color_mode'] = $m;
    }
    if (array_key_exists('require_all', $in)) $c['require_all'] = filter_var($in['require_all'], FILTER_VALIDATE_BOOLEAN);
    return $c;
}

/* ---------- Besitzerfunktionen ---------- */

function activity_touch(int $id): string {
    $now = wb_now();
    db_exec('UPDATE activities SET last_activity_at = ? WHERE id = ?', [$now, $id]);
    return $now;
}

function activity_create(array $in): array {
    $type = (string)($in['type'] ?? 'target');
    if (!in_array($type, ACTIVITY_TYPES, true)) throw new InvalidArgumentException('Unbekannter Typ. Erlaubt: ' . implode(', ', ACTIVITY_TYPES) . '.');
    $name = wb_clean_text($in['name'] ?? '', 200);
    if ($name === '') throw new InvalidArgumentException('Name fehlt.');
    $config = match ($type) {
        'wordcloud' => wordcloud_normalize_config($in, wordcloud_default_config()),
        'exam' => exam_normalize_config($in, exam_default_config()),
        default => target_normalize_config($in + ['aspects' => []], target_default_config()),
    };
    $bg = in_array($in['background'] ?? '', wb_backgrounds(), true) ? $in['background'] : 'meer';
    $lang = in_array($in['lang'] ?? '', wb_langs(), true) ? $in['lang'] : (string)wb_config('default_lang', 'de');
    $slug = null;
    if (isset($in['slug']) && trim((string)$in['slug']) !== '') {
        $slug = wb_valid_slug((string)$in['slug']);
        if ($slug === null) throw new InvalidArgumentException('Kurzwort ungültig: 3 bis 32 Zeichen, nur Kleinbuchstaben, Ziffern und Bindestrich, keine reservierten Wörter.');
        if (activity_slug_in_use($slug)) throw new InvalidArgumentException('Kurzwort bereits vergeben.');
    } else {
        $slug = activity_free_slug();
    }
    $key = wb_random_key(16);
    $now = wb_now();
    $ownerId = (int)(wb_current_user()['id'] ?? 1);
    $id = db_insert(
        'INSERT INTO activities (akey, slug, owner_user_id, type, name, config_json, background, lang, status, revealed, round, note, tags, created_at, updated_at, last_activity_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1, ?, ?, ?, ?, ?)',
        [$key, $slug, $ownerId, $type, $name, json_encode($config, JSON_UNESCAPED_UNICODE), $bg, $lang,
         in_array($in['status'] ?? '', ['open', 'closed'], true) ? $in['status'] : 'open',
         wb_clean_text($in['note'] ?? '', 5000), implode(', ', wb_parse_tags($in['tags'] ?? '')), $now, $now, $now]
    );
    return activity_by_id($id);
}

function activity_update(array $a, array $in): array {
    $fields = [];
    $params = [];
    if (array_key_exists('name', $in)) {
        $name = wb_clean_text($in['name'], 200);
        if ($name === '') throw new InvalidArgumentException('Name fehlt.');
        $fields[] = 'name = ?'; $params[] = $name;
    }
    $configKeys = match ($a['type']) { 'wordcloud' => WORDCLOUD_CONFIG_KEYS, 'exam' => EXAM_CONFIG_KEYS, default => ['aspects', 'color_mode', 'require_all'] };
    if (array_intersect($configKeys, array_keys($in))) {
        $config = match ($a['type']) {
            'wordcloud' => wordcloud_normalize_config($in, $a['config']),
            'exam' => exam_normalize_config(array_diff_key($in, ['phase' => 1, 'ends_at' => 1, 'review_done' => 1, 'solutions_released' => 1]), $a['config']),
            default => target_normalize_config($in, $a['config']),
        };
        $fields[] = 'config_json = ?'; $params[] = json_encode($config, JSON_UNESCAPED_UNICODE);
    }
    if (array_key_exists('background', $in) && in_array($in['background'], wb_backgrounds(), true)) { $fields[] = 'background = ?'; $params[] = $in['background']; }
    if (array_key_exists('lang', $in) && in_array($in['lang'], wb_langs(), true)) { $fields[] = 'lang = ?'; $params[] = $in['lang']; }
    if (array_key_exists('note', $in)) { $fields[] = 'note = ?'; $params[] = wb_clean_text($in['note'], 5000); }
    if (array_key_exists('tags', $in)) { $fields[] = 'tags = ?'; $params[] = implode(', ', wb_parse_tags($in['tags'])); }
    if (array_key_exists('revealed', $in)) { $fields[] = 'revealed = ?'; $params[] = filter_var($in['revealed'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0; }
    $newStatus = $a['status'];
    if (array_key_exists('status', $in)) {
        if (!in_array($in['status'], ['open', 'closed', 'archived'], true)) throw new InvalidArgumentException('Status ist open, closed oder archived.');
        $newStatus = $in['status'];
        $fields[] = 'status = ?'; $params[] = $newStatus;
    }
    if (array_key_exists('slug', $in)) {
        $slug = trim((string)$in['slug']) === '' ? activity_free_slug() : wb_valid_slug((string)$in['slug']);
        if ($slug === null) throw new InvalidArgumentException('Kurzwort ungültig: 3 bis 32 Zeichen, nur Kleinbuchstaben, Ziffern und Bindestrich, keine reservierten Wörter.');
        if ($slug !== $a['slug'] && activity_slug_in_use($slug, $a['id'])) throw new InvalidArgumentException('Kurzwort bereits vergeben.');
        $fields[] = 'slug = ?'; $params[] = $slug;
        $a['slug'] = $slug;
    }
    // Aus dem Archiv geholt: ein inzwischen vergebenes Kurzwort wird ersetzt.
    if ($newStatus !== 'archived' && $a['status'] === 'archived' && $a['slug'] && activity_slug_in_use($a['slug'], $a['id'])) {
        $fields[] = 'slug = ?'; $params[] = activity_free_slug();
    }
    if (!$fields) return $a;
    $now = wb_now();
    // updated_at kennzeichnet den Aufbau (Name, Aspekte, Farben …); ändert er sich, lädt die Seite neu.
    // Anzeigen, Verbergen und Status gehen über den normalen Abgleich.
    if (array_diff(array_keys($in), ['revealed', 'status'])) { $fields[] = 'updated_at = ?'; $params[] = $now; }
    $fields[] = 'last_activity_at = ?'; $params[] = $now;
    $params[] = $a['id'];
    db_exec('UPDATE activities SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
    $updated = activity_by_id($a['id']);
    // Geänderte Bewertungsregeln einer laufenden Prüfung: alle Versuche neu rechnen.
    if ($a['type'] === 'exam' && array_intersect(['points', 'mc_rule', 'own_questions'], array_keys($in))) exam_regrade_all($updated);
    return $updated;
}

function activity_set_revealed(array $a, bool $on): array {
    return activity_update($a, ['revealed' => $on]);
}

/** Neue Runde: leere Scheibe, Punkte wieder verborgen. Die bisherigen Antworten bleiben als frühere Runde lesbar. */
function activity_new_round(array $a): array {
    $now = wb_now();
    db_exec('UPDATE activities SET round = round + 1, revealed = 0, last_activity_at = ? WHERE id = ?', [$now, $a['id']]);
    return activity_by_id($a['id']);
}

function activity_trash(array $a): void {
    db_exec('UPDATE activities SET deleted_at = ?, last_activity_at = ? WHERE id = ?', [wb_now(), wb_now(), $a['id']]);
}

function activity_restore(array $a): array {
    $fields = ['deleted_at = NULL', 'last_activity_at = ?'];
    $params = [wb_now()];
    if ($a['status'] !== 'archived' && $a['slug'] && activity_slug_in_use($a['slug'], $a['id'])) {
        $fields[] = 'slug = ?'; $params[] = activity_free_slug();
    }
    $params[] = $a['id'];
    db_exec('UPDATE activities SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
    return activity_by_id($a['id']);
}

function activity_delete_forever(array $a): void {
    db_exec('DELETE FROM activity_responses WHERE activity_id = ?', [$a['id']]);
    db_exec('DELETE FROM activity_groupings WHERE activity_id = ?', [$a['id']]);
    exam_delete_data($a['id']);
    db_exec('DELETE FROM activities WHERE id = ?', [$a['id']]);
}

/** Kopie der Struktur (Aspekte bzw. Einstellungen der Wortwolke, Farben, Notiz, Tags) ohne Antworten. */
function activity_duplicate(array $a, ?string $name = null): array {
    if ($a['type'] === 'exam') {
        // Prüfung: Einstellungen und angenommene Fragen, neu in der Sammelphase.
        $copy = activity_create([
            'type' => 'exam',
            'name' => $name !== null && trim($name) !== '' ? $name : $a['name'] . ' (Kopie)',
        ] + array_diff_key($a['config'], ['phase' => 1, 'review_done' => 1, 'solutions_released' => 1, 'ends_at' => 1]) + [
            'background' => $a['background'], 'lang' => $a['lang'], 'note' => $a['note'], 'tags' => $a['tags'],
        ]);
        exam_copy_questions($a, $copy);
        return activity_by_id($copy['id']);
    }
    $config = $a['type'] === 'wordcloud' ? $a['config'] : [
        'aspects' => array_column($a['config']['aspects'], 'label'),
        'color_mode' => $a['config']['color_mode'],
        'require_all' => $a['config']['require_all'],
    ];
    return activity_create([
        'type' => $a['type'],
        'name' => $name !== null && trim($name) !== '' ? $name : $a['name'] . ' (Kopie)',
    ] + $config + [
        'background' => $a['background'],
        'lang' => $a['lang'],
        'note' => $a['note'],
        'tags' => $a['tags'],
    ]);
}

function activities_list(array $opt = []): array {
    $where = [];
    $params = [];
    $status = $opt['status'] ?? 'active';
    $where[] = $status === 'trash' ? 'deleted_at IS NOT NULL' : 'deleted_at IS NULL';
    if ($status === 'active') { $where[] = "status IN ('open','closed')"; }
    elseif (in_array($status, ['open', 'closed', 'archived'], true)) { $where[] = 'status = ?'; $params[] = $status; }
    if (!empty($opt['type']) && in_array($opt['type'], ACTIVITY_TYPES, true)) { $where[] = 'type = ?'; $params[] = $opt['type']; }
    $q = trim((string)($opt['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(name LIKE ? OR note LIKE ? OR tags LIKE ? OR config_json LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if (!empty($opt['tag'])) { $where[] = 'tags LIKE ?'; $params[] = '%' . $opt['tag'] . '%'; }
    $order = match ($opt['sort'] ?? 'activity') {
        'name' => 'name ASC',
        'created' => 'created_at DESC',
        default => 'last_activity_at DESC',
    };
    $sql = 'SELECT * FROM activities WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $order . ' LIMIT 500';
    return array_map(fn($a) => activity_owner_view(activity_normalize($a)), db_all($sql, $params));
}

/** Entfernt Aktivitäten, die länger als $days Tage im Papierkorb liegen, samt Antworten. */
function activity_purge_deleted(int $days = 30): int {
    $limit = (new DateTime("-$days days", new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $n = 0;
    try {
        $rows = db_all('SELECT * FROM activities WHERE deleted_at IS NOT NULL AND deleted_at < ?', [$limit]);
    } catch (PDOException $e) {
        return 0;
    }
    foreach ($rows as $a) {
        activity_delete_forever(activity_normalize($a));
        $n++;
    }
    return $n;
}

/* ---------- Antworten ---------- */

/**
 * Prüft die Punkte einer Antwort: {aspect_id: {r, t}}. Unbekannte Aspekte werden verworfen,
 * Werte auf 0 bis 1 begrenzt. Bei require_all muss jeder Aspekt einen Punkt haben.
 */
function target_clean_points(array $a, $points): array {
    if (!is_array($points)) $points = [];
    $out = [];
    foreach ($a['config']['aspects'] as $asp) {
        $p = $points[$asp['id']] ?? null;
        if (!is_array($p) || !isset($p['r']) || !is_numeric($p['r'])) continue;
        $r = max(0.0, min(1.0, (float)$p['r']));
        $t = isset($p['t']) && is_numeric($p['t']) ? max(0.0, min(1.0, (float)$p['t'])) : 0.5;
        $out[$asp['id']] = ['r' => round($r, 4), 't' => round($t, 4)];
    }
    if (!$out) throw new InvalidArgumentException('target_none');
    if ($a['config']['require_all'] && count($out) < count($a['config']['aspects'])) throw new InvalidArgumentException('target_incomplete');
    return $out;
}

/** Speichert oder ersetzt die Antwort eines Browsers in der laufenden Runde. */
function target_response_save(array $a, string $uid, $points): array {
    $clean = target_clean_points($a, $points);
    $now = wb_now();
    db_exec(
        'INSERT INTO activity_responses (activity_id, round, guest_key, data_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE data_json = VALUES(data_json), updated_at = VALUES(updated_at)',
        [$a['id'], $a['round'], $uid, json_encode($clean), $now, $now]
    );
    activity_touch($a['id']);
    return $clean;
}

function target_response_get(array $a, string $uid): ?array {
    $row = db_row('SELECT data_json FROM activity_responses WHERE activity_id = ? AND round = ? AND guest_key = ?', [$a['id'], $a['round'], $uid]);
    return $row ? (json_decode($row['data_json'], true) ?: []) : null;
}

/**
 * Zustand für die Seite. Solange die Punkte verborgen sind, sieht auch der Besitzer nur die Anzahl,
 * damit auf dem Beamer nichts verraten wird. Der Besitzer kann über $round eine frühere Runde lesen;
 * dort sind die Punkte immer sichtbar.
 */
function activity_state(array $a, string $uid, ?string $since, bool $owner, ?int $round = null): array {
    $version = (string)$a['last_activity_at'];
    if ($round === null && $since !== null && $since === $version) return ['changed' => false, 'version' => $version];
    if ($a['type'] === 'wordcloud') return wordcloud_state($a, $uid, $owner, $round);
    if ($a['type'] === 'exam') return exam_state($a, $uid, $owner);
    $viewRound = $owner && $round !== null && $round > 0 && $round !== $a['round'] ? $round : $a['round'];
    $past = $viewRound !== $a['round'];
    $revealed = $past || $a['revealed'];
    $mine = $past ? null : target_response_get($a, $uid);
    $points = [];
    if ($revealed) {
        $rows = db_all('SELECT id, guest_key, data_json FROM activity_responses WHERE activity_id = ? AND round = ? ORDER BY id ASC', [$a['id'], $viewRound]);
        foreach ($rows as $row) {
            $isMine = !$past && hash_equals($row['guest_key'], $uid);
            foreach (json_decode($row['data_json'], true) ?: [] as $aid => $p) {
                $points[] = ['a' => (string)$aid, 'r' => (float)$p['r'], 't' => (float)$p['t'], 'm' => $isMine ? 1 : 0, 'g' => (int)$row['id']];
            }
        }
    }
    return [
        'changed' => true,
        'version' => $version,
        'config_version' => (string)$a['updated_at'],
        'status' => $a['status'],
        'round' => $viewRound,
        'current_round' => $a['round'],
        'past' => $past,
        'revealed' => $revealed,
        'count' => activity_response_count($a, $viewRound),
        'mine' => $mine,
        'points' => $points,
    ];
}

/* ---------- Auswertung ---------- */

function target_score(float $r): float {
    return round(10 * (1 - $r), 1);
}

/** Auswertung pro Aspekt: Anzahl, Mittelwert, Median, Minimum, Maximum (Wertung 0 bis 10, 10 = Mitte) und Verteilung auf fünf Ringe. */
function target_results(array $a, ?int $round = null): array {
    $round = $round ?? $a['round'];
    $rows = db_all('SELECT data_json FROM activity_responses WHERE activity_id = ? AND round = ?', [$a['id'], $round]);
    $values = [];
    foreach ($rows as $row) {
        foreach (json_decode($row['data_json'], true) ?: [] as $aid => $p) $values[(string)$aid][] = target_score((float)$p['r']);
    }
    $aspects = [];
    foreach ($a['config']['aspects'] as $asp) {
        $v = $values[$asp['id']] ?? [];
        sort($v);
        $n = count($v);
        $rings = [0, 0, 0, 0, 0]; // Ring 1 = innen (Wertung über 8) … Ring 5 = aussen (bis 2)
        foreach ($v as $s) $rings[max(0, min(4, (int)floor((10 - $s) / 2)))]++;
        $aspects[] = [
            'id' => $asp['id'],
            'label' => $asp['label'],
            'n' => $n,
            'mean' => $n ? round(array_sum($v) / $n, 1) : null,
            'median' => $n ? round($n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2, 1) : null,
            'min' => $n ? $v[0] : null,
            'max' => $n ? $v[$n - 1] : null,
            'rings' => $rings,
        ];
    }
    return [
        'name' => $a['name'],
        'round' => $round,
        'current_round' => $a['round'],
        'revealed' => $round !== $a['round'] || $a['revealed'],
        'responses' => count($rows),
        'scale' => 'Wertung 0 bis 10: 10 = Mitte (beste Einschätzung), 0 = Rand. Ringe 1 (innen) bis 5 (aussen).',
        'aspects' => $aspects,
        'rounds' => activity_rounds($a),
    ];
}

function target_results_markdown(array $a, ?int $round = null): string {
    $r = target_results($a, $round);
    $out = '# ' . $a['name'] . "\n\n";
    $out .= 'Zielscheibe, Runde ' . $r['round'] . ($r['round'] === $r['current_round'] ? ' (laufend, Punkte ' . ($a['revealed'] ? 'angezeigt' : 'verborgen') . ')' : ' (abgeschlossen)') . ', ' . $r['responses'] . ' Antworten. ' . $r['scale'] . "\n";
    if (!empty($a['note'])) $out .= "\nNotiz: " . $a['note'] . "\n";
    $out .= "\n| Aspekt | n | Mittel | Median | Min | Max | Ringe 1–5 |\n|---|---|---|---|---|---|---|\n";
    foreach ($r['aspects'] as $x) {
        $out .= '| ' . str_replace('|', '/', $x['label']) . ' | ' . $x['n'] . ' | ' . ($x['mean'] ?? '–') . ' | ' . ($x['median'] ?? '–') . ' | ' . ($x['min'] ?? '–') . ' | ' . ($x['max'] ?? '–') . ' | ' . implode(' · ', $x['rings']) . " |\n";
    }
    if (count($r['rounds']) > 1) {
        $out .= "\nRunden: " . implode(', ', array_map(fn($x) => $x['round'] . ' (' . $x['responses'] . ($x['current'] ? ', laufend' : '') . ')', $r['rounds'])) . "\n";
    }
    return $out;
}
