<?php
declare(strict_types=1);

/* ---------- Laden und Normalisieren ---------- */

function board_by_key(string $key): ?array {
    if (!preg_match('/^[a-z0-9]{16}$/', $key)) return null;
    $b = db_row('SELECT * FROM boards WHERE bkey = ?', [$key]);
    return $b ? board_normalize($b) : null;
}

function board_by_slug(string $slug): ?array {
    $slug = wb_valid_slug($slug);
    if ($slug === null) return null;
    $b = db_row("SELECT * FROM boards WHERE slug = ? AND status <> 'archived' ORDER BY id DESC LIMIT 1", [$slug]);
    return $b ? board_normalize($b) : null;
}

/** Findet ein Board über Schlüssel oder Kurzwort (für Bibliothek, API und Connector). */
function board_find(string $ref): ?array {
    $ref = trim($ref);
    return board_by_key($ref) ?? board_by_slug($ref);
}

function board_slug_taken(string $slug, ?int $exceptId = null): bool {
    $row = db_row("SELECT id FROM boards WHERE slug = ? AND status <> 'archived'" . ($exceptId ? ' AND id <> ?' : ''), $exceptId ? [$slug, $exceptId] : [$slug]);
    return $row !== null;
}

function board_free_slug(): string {
    for ($i = 0; $i < 50; $i++) {
        $w = wb_short_word();
        if (!in_array($w, wb_reserved_slugs(), true) && !board_slug_taken($w)) return $w;
    }
    return wb_short_word() . random_int(2, 9);
}

function board_by_id(int $id): ?array {
    $b = db_row('SELECT * FROM boards WHERE id = ?', [$id]);
    return $b ? board_normalize($b) : null;
}

function board_normalize(array $b): array {
    $b['id'] = (int)$b['id'];
    $b['columns'] = json_decode($b['columns_json'] ?? '[]', true) ?: [];
    $b['settings'] = array_merge(wb_default_settings(), json_decode($b['settings_json'] ?? '{}', true) ?: []);
    $b['tags'] = wb_parse_tags($b['tags'] ?? '');
    unset($b['columns_json'], $b['settings_json']);
    $b['slug'] = $b['slug'] ?? null;
    $b['url'] = wb_board_url($b['bkey'], $b['status'] === 'archived' ? null : $b['slug']);
    $b['long_url'] = wb_board_url($b['bkey']);
    return $b;
}

function board_public(array $b, string $lang): array {
    return [
        'key' => $b['bkey'],
        'name' => $b['name'],
        'type' => $b['type'],
        'columns' => $b['columns'],
        'background' => $b['background'],
        'lang' => $lang,
        'settings' => $b['settings'],
        'status' => $b['status'],
        'url' => $b['url'],
    ];
}

function board_owner_view(array $b): array {
    $counts = db_row('SELECT COUNT(*) AS posts FROM posts WHERE board_id = ? AND deleted_at IS NULL', [$b['id']]);
    $c = db_row('SELECT COUNT(*) AS comments FROM comments WHERE board_id = ? AND deleted_at IS NULL', [$b['id']]);
    return [
        'id' => $b['id'],
        'key' => $b['bkey'],
        'slug' => $b['slug'],
        'name' => $b['name'],
        'type' => $b['type'],
        'columns' => $b['columns'],
        'background' => $b['background'],
        'lang' => $b['lang'],
        'settings' => $b['settings'],
        'status' => $b['status'],
        'note' => (string)($b['note'] ?? ''),
        'tags' => $b['tags'],
        'url' => $b['url'],
        'long_url' => $b['long_url'],
        'qr_svg' => wb_base_url() . '/api/boards/' . $b['bkey'] . '/qr.svg',
        'qr_png' => wb_base_url() . '/api/boards/' . $b['bkey'] . '/qr.png',
        'post_count' => (int)$counts['posts'],
        'comment_count' => (int)$c['comments'],
        'created_at' => $b['created_at'],
        'updated_at' => $b['updated_at'],
        'last_activity_at' => $b['last_activity_at'],
    ];
}

/* ---------- Eingaben prüfen ---------- */

function board_normalize_columns($input): array {
    $out = [];
    if (is_string($input)) {
        $input = array_map('trim', preg_split('/\n+/', $input) ?: []);
    }
    if (!is_array($input)) return [];
    foreach ($input as $i => $col) {
        if (is_string($col)) {
            $col = ['title' => $col];
        }
        if (!is_array($col)) continue;
        $title = wb_clean_text($col['title'] ?? '', 80);
        if ($title === '') continue;
        $id = isset($col['id']) && preg_match('/^[a-z0-9]{1,32}$/', (string)$col['id']) ? (string)$col['id'] : wb_random_key(8);
        $out[] = ['id' => $id, 'title' => $title];
        if (count($out) >= 20) break;
    }
    return $out;
}

function board_normalize_settings($input, array $current): array {
    $s = $current;
    if (!is_array($input)) return $s;
    foreach (['comments', 'reactions', 'images', 'links', 'new_on_top'] as $k) {
        if (array_key_exists($k, $input)) {
            $s[$k] = filter_var($input[$k], FILTER_VALIDATE_BOOLEAN);
        }
    }
    if (isset($input['emojis'])) {
        $allowed = wb_emojis();
        $list = is_array($input['emojis']) ? $input['emojis'] : preg_split('/[\s,]+/u', (string)$input['emojis']);
        $list = array_values(array_intersect($allowed, array_map('trim', $list ?: [])));
        $s['emojis'] = $list ?: $allowed;
    }
    return $s;
}

/* ---------- Besitzerfunktionen ---------- */

function board_create(array $in): array {
    $name = wb_clean_text($in['name'] ?? '', 200);
    if ($name === '') throw new InvalidArgumentException('Name fehlt.');
    $type = ($in['type'] ?? 'wall') === 'columns' ? 'columns' : 'wall';
    $columns = board_normalize_columns($in['columns'] ?? []);
    if ($type === 'columns' && !$columns) {
        $columns = board_normalize_columns(['Spalte 1', 'Spalte 2', 'Spalte 3']);
    }
    $bg = in_array($in['background'] ?? '', wb_backgrounds(), true) ? $in['background'] : wb_backgrounds()[0];
    $lang = in_array($in['lang'] ?? '', wb_langs(), true) ? $in['lang'] : (string)wb_config('default_lang', 'de');
    $settings = board_normalize_settings($in['settings'] ?? [], wb_default_settings());
    $note = wb_clean_text($in['note'] ?? '', 5000);
    $tags = wb_parse_tags($in['tags'] ?? '');
    $slug = null;
    if (isset($in['slug']) && trim((string)$in['slug']) !== '') {
        $slug = wb_valid_slug((string)$in['slug']);
        if ($slug === null) throw new InvalidArgumentException('Kurzwort ungültig: 3 bis 32 Zeichen, nur Kleinbuchstaben, Ziffern und Bindestrich, keine reservierten Wörter.');
        if (board_slug_taken($slug)) throw new InvalidArgumentException('Kurzwort bereits vergeben.');
    } else {
        $slug = board_free_slug();
    }
    $now = wb_now();
    $key = wb_random_key(16);
    $id = db_insert(
        'INSERT INTO boards (bkey, slug, name, type, columns_json, background, lang, settings_json, status, note, tags, created_at, updated_at, last_activity_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$key, $slug, $name, $type, json_encode($columns, JSON_UNESCAPED_UNICODE), $bg, $lang,
         json_encode($settings, JSON_UNESCAPED_UNICODE), 'open', $note, implode(', ', $tags), $now, $now, $now]
    );
    return board_by_id($id);
}

function board_update(array $b, array $in): array {
    $fields = [];
    $params = [];
    if (array_key_exists('name', $in)) {
        $name = wb_clean_text($in['name'], 200);
        if ($name === '') throw new InvalidArgumentException('Name fehlt.');
        $fields[] = 'name = ?'; $params[] = $name;
    }
    if (array_key_exists('type', $in)) {
        $fields[] = 'type = ?'; $params[] = $in['type'] === 'columns' ? 'columns' : 'wall';
    }
    if (array_key_exists('columns', $in)) {
        $fields[] = 'columns_json = ?'; $params[] = json_encode(board_normalize_columns($in['columns']), JSON_UNESCAPED_UNICODE);
    }
    if (array_key_exists('background', $in) && in_array($in['background'], wb_backgrounds(), true)) {
        $fields[] = 'background = ?'; $params[] = $in['background'];
    }
    if (array_key_exists('lang', $in) && in_array($in['lang'], wb_langs(), true)) {
        $fields[] = 'lang = ?'; $params[] = $in['lang'];
    }
    if (array_key_exists('settings', $in)) {
        $fields[] = 'settings_json = ?'; $params[] = json_encode(board_normalize_settings($in['settings'], $b['settings']), JSON_UNESCAPED_UNICODE);
    }
    $newStatus = $b['status'];
    if (array_key_exists('status', $in) && in_array($in['status'], ['open', 'closed', 'archived'], true)) {
        $newStatus = $in['status'];
        $fields[] = 'status = ?'; $params[] = $newStatus;
    }
    if (array_key_exists('slug', $in)) {
        $slug = trim((string)$in['slug']) === '' ? board_free_slug() : wb_valid_slug((string)$in['slug']);
        if ($slug === null) throw new InvalidArgumentException('Kurzwort ungültig: 3 bis 32 Zeichen, nur Kleinbuchstaben, Ziffern und Bindestrich, keine reservierten Wörter.');
        if ($slug !== $b['slug'] && board_slug_taken($slug, $b['id'])) throw new InvalidArgumentException('Kurzwort bereits vergeben.');
        $fields[] = 'slug = ?'; $params[] = $slug;
        $b['slug'] = $slug;
    }
    if ($newStatus !== 'archived' && $b['status'] === 'archived' && $b['slug'] && board_slug_taken($b['slug'], $b['id'])) {
        // Beim Zurückholen aus dem Archiv ist das alte Kurzwort inzwischen vergeben.
        $fields[] = 'slug = ?'; $params[] = board_free_slug();
    }
    if (array_key_exists('note', $in)) {
        $fields[] = 'note = ?'; $params[] = wb_clean_text($in['note'], 5000);
    }
    if (array_key_exists('tags', $in)) {
        $fields[] = 'tags = ?'; $params[] = implode(', ', wb_parse_tags($in['tags']));
    }
    if (!$fields) return $b;
    $now = wb_now();
    $fields[] = 'updated_at = ?'; $params[] = $now;
    $fields[] = 'last_activity_at = ?'; $params[] = $now;
    $params[] = $b['id'];
    db_exec('UPDATE boards SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
    return board_by_id($b['id']);
}

function board_touch(int $boardId): string {
    $now = wb_now();
    db_exec('UPDATE boards SET last_activity_at = ? WHERE id = ?', [$now, $boardId]);
    return $now;
}

function board_clear(array $b): void {
    $now = wb_now();
    db_exec('UPDATE posts SET deleted_at = ?, updated_at = ? WHERE board_id = ? AND deleted_at IS NULL', [$now, $now, $b['id']]);
    db_exec('UPDATE comments SET deleted_at = ?, updated_at = ? WHERE board_id = ? AND deleted_at IS NULL', [$now, $now, $b['id']]);
    db_exec('DELETE FROM reactions WHERE board_id = ?', [$b['id']]);
    db_exec('UPDATE bundles SET deleted_at = ?, updated_at = ? WHERE board_id = ? AND deleted_at IS NULL', [$now, $now, $b['id']]);
    board_touch($b['id']);
}

function board_delete_forever(array $b): void {
    db_exec('DELETE FROM reactions WHERE board_id = ?', [$b['id']]);
    db_exec('DELETE FROM comments WHERE board_id = ?', [$b['id']]);
    db_exec('DELETE FROM posts WHERE board_id = ?', [$b['id']]);
    db_exec('DELETE FROM bundles WHERE board_id = ?', [$b['id']]);
    db_exec('DELETE FROM boards WHERE id = ?', [$b['id']]);
    $dir = WB_ROOT . '/uploads/' . $b['bkey'];
    if (is_dir($dir)) {
        foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($dir);
    }
}

function board_duplicate(array $b, ?string $name = null): array {
    return board_create([
        'name' => $name ?? ($b['name'] . ' (Kopie)'),
        'type' => $b['type'],
        'columns' => array_map(fn($c) => ['title' => $c['title']], $b['columns']),
        'background' => $b['background'],
        'lang' => $b['lang'],
        'settings' => $b['settings'],
        'note' => $b['note'],
        'tags' => $b['tags'],
    ]);
}

function board_purge_deleted(int $days = 30): int {
    $limit = (new DateTime("-$days days", new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $n = 0;
    $posts = db_all('SELECT p.id, p.image, b.bkey FROM posts p JOIN boards b ON b.id = p.board_id WHERE p.deleted_at IS NOT NULL AND p.deleted_at < ?', [$limit]);
    foreach ($posts as $p) {
        if ($p['image']) post_delete_image($p['bkey'], $p['image']);
        db_exec('DELETE FROM reactions WHERE target_type = "post" AND target_id = ?', [$p['id']]);
        db_exec('DELETE FROM comments WHERE post_id = ?', [$p['id']]);
        db_exec('DELETE FROM posts WHERE id = ?', [$p['id']]);
        $n++;
    }
    $n += db_exec('DELETE FROM comments WHERE deleted_at IS NOT NULL AND deleted_at < ?', [$limit]);
    $n += db_exec('DELETE FROM bundles WHERE deleted_at IS NOT NULL AND deleted_at < ?', [$limit]);
    return $n;
}

function boards_list(array $opt = []): array {
    $where = [];
    $params = [];
    $status = $opt['status'] ?? 'active';
    if ($status === 'active') { $where[] = "status IN ('open','closed')"; }
    elseif (in_array($status, ['open', 'closed', 'archived'], true)) { $where[] = 'status = ?'; $params[] = $status; }
    $q = trim((string)($opt['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(name LIKE ? OR note LIKE ? OR tags LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like);
    }
    if (!empty($opt['tag'])) {
        $where[] = 'tags LIKE ?';
        $params[] = '%' . $opt['tag'] . '%';
    }
    $order = match ($opt['sort'] ?? 'activity') {
        'name' => 'name ASC',
        'created' => 'created_at DESC',
        default => 'last_activity_at DESC',
    };
    $sql = 'SELECT * FROM boards' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY ' . $order . ' LIMIT 500';
    return array_map(fn($b) => board_owner_view(board_normalize($b)), db_all($sql, $params));
}

/* ---------- Inhalte ---------- */

function post_delete_image(string $bkey, string $image): void {
    $dir = WB_ROOT . '/uploads/' . $bkey . '/';
    foreach ([$image, 'thumb_' . $image] as $f) {
        if (preg_match('/^[a-z0-9_]+\.(jpg|webp|png|gif)$/', $f) && file_exists($dir . $f)) @unlink($dir . $f);
    }
}

function board_image_url(string $bkey, ?string $image, bool $thumb = false): ?string {
    if (!$image) return null;
    return wb_base_url() . '/uploads/' . $bkey . '/' . ($thumb ? 'thumb_' : '') . $image;
}

/**
 * Vollständiger oder inkrementeller Zustand für die Board-Seite.
 */
function board_state(array $b, string $uid, ?string $since, bool $owner): array {
    $bid = $b['id'];
    $version = $b['last_activity_at'];
    if ($since !== null && $since !== '' && $since >= $version) {
        return ['version' => $version, 'changed' => false];
    }
    $incremental = $since !== null && $since !== '';
    $posts = $incremental
        ? db_all('SELECT * FROM posts WHERE board_id = ? AND updated_at > ? ORDER BY position ASC, id ASC', [$bid, $since])
        : db_all('SELECT * FROM posts WHERE board_id = ? AND deleted_at IS NULL ORDER BY position ASC, id ASC', [$bid]);
    $comments = $incremental
        ? db_all('SELECT * FROM comments WHERE board_id = ? AND updated_at > ? ORDER BY id ASC', [$bid, $since])
        : db_all('SELECT * FROM comments WHERE board_id = ? AND deleted_at IS NULL ORDER BY id ASC', [$bid]);
    $reactions = db_all('SELECT target_type, target_id, emoji, COUNT(*) AS n, SUM(author_key = ?) AS mine FROM reactions WHERE board_id = ? GROUP BY target_type, target_id, emoji', [$uid, $bid]);
    $bundles = $incremental
        ? db_all('SELECT * FROM bundles WHERE board_id = ? AND updated_at > ? ORDER BY position ASC, id ASC', [$bid, $since])
        : db_all('SELECT * FROM bundles WHERE board_id = ? AND deleted_at IS NULL ORDER BY position ASC, id ASC', [$bid]);

    $out = [
        'version' => $version,
        'changed' => true,
        'full' => !$incremental,
        'status' => $b['status'],
        'bundles' => array_map('bundle_public', $bundles),
        'posts' => array_map(fn($p) => post_public($b, $p, $uid, $owner), $posts),
        'comments' => array_map(fn($c) => comment_public($c, $uid, $owner), $comments),
        'reactions' => array_map(fn($r) => [
            't' => $r['target_type'], 'id' => (int)$r['target_id'], 'e' => $r['emoji'], 'n' => (int)$r['n'], 'mine' => (int)$r['mine'] > 0,
        ], $reactions),
    ];
    return $out;
}

function post_public(array $b, array $p, string $uid, bool $owner): array {
    return [
        'id' => (int)$p['id'],
        'column' => $p['column_id'],
        'bundle' => $p['bundle_id'] !== null ? (int)$p['bundle_id'] : null,
        'title' => $p['title'],
        'body' => $p['body'],
        'image' => board_image_url($b['bkey'], $p['image']),
        'thumb' => board_image_url($b['bkey'], $p['image'], true),
        'link' => $p['link'],
        'author' => $p['author_name'],
        'hue' => wb_author_hue($p['author_name']),
        'mine' => $p['author_key'] === $uid,
        'editable' => $owner || $p['author_key'] === $uid,
        'position' => (int)$p['position'],
        'created_at' => $p['created_at'],
        'updated_at' => $p['updated_at'],
        'edited' => ($p['edited_at'] ?? null) !== null,
        'deleted' => $p['deleted_at'] !== null,
    ];
}

function bundle_public(array $g): array {
    return [
        'id' => (int)$g['id'],
        'column' => $g['column_id'],
        'title' => $g['title'],
        'summary' => $g['summary'],
        'author' => $g['author_name'],
        'position' => (int)$g['position'],
        'created_at' => $g['created_at'],
        'updated_at' => $g['updated_at'],
        'deleted' => $g['deleted_at'] !== null,
    ];
}

function comment_public(array $c, string $uid, bool $owner): array {
    return [
        'id' => (int)$c['id'],
        'post' => (int)$c['post_id'],
        'body' => $c['body'],
        'author' => $c['author_name'],
        'hue' => wb_author_hue($c['author_name']),
        'mine' => $c['author_key'] === $uid,
        'editable' => $owner || $c['author_key'] === $uid,
        'created_at' => $c['created_at'],
        'updated_at' => $c['updated_at'],
        'edited' => ($c['edited_at'] ?? null) !== null,
        'deleted' => $c['deleted_at'] !== null,
    ];
}

/* ---------- Export ---------- */

function wb_local_time(?string $utc): ?string {
    if (!$utc) return null;
    $d = new DateTime($utc, new DateTimeZone('UTC'));
    $d->setTimezone(new DateTimeZone((string)wb_config('timezone', 'Europe/Zurich')));
    return $d->format('Y-m-d H:i');
}

function board_export_data(array $b): array {
    $posts = db_all('SELECT * FROM posts WHERE board_id = ? AND deleted_at IS NULL ORDER BY position ASC, id ASC', [$b['id']]);
    $comments = db_all('SELECT * FROM comments WHERE board_id = ? AND deleted_at IS NULL ORDER BY id ASC', [$b['id']]);
    $reactions = db_all('SELECT target_type, target_id, emoji, COUNT(*) AS n FROM reactions WHERE board_id = ? GROUP BY target_type, target_id, emoji', [$b['id']]);
    $byPost = [];
    foreach ($comments as $c) $byPost[(int)$c['post_id']][] = $c;
    $rx = [];
    foreach ($reactions as $r) $rx[$r['target_type']][(int)$r['target_id']][] = $r['emoji'] . ' ' . $r['n'];
    $columns = [];
    foreach ($b['columns'] as $c) $columns[$c['id']] = $c['title'];
    $bundles = db_all('SELECT * FROM bundles WHERE board_id = ? AND deleted_at IS NULL ORDER BY position ASC, id ASC', [$b['id']]);
    $bundleOut = [];
    foreach ($bundles as $g) {
        $bundleOut[] = [
            'id' => (int)$g['id'],
            'column' => $b['type'] === 'columns' ? ($columns[$g['column_id']] ?? (reset($columns) ?: null)) : null,
            'title' => $g['title'],
            'summary' => $g['summary'],
            'author' => $g['author_name'],
            'post_ids' => [],
        ];
    }
    $bundleIndex = array_column($bundleOut, null, 'id');
    $out = [];
    foreach ($posts as $p) {
        if ($p['bundle_id'] !== null && isset($bundleIndex[(int)$p['bundle_id']])) {
            foreach ($bundleOut as &$bo) { if ($bo['id'] === (int)$p['bundle_id']) $bo['post_ids'][] = (int)$p['id']; }
            unset($bo);
        }
        $out[] = [
            'id' => (int)$p['id'],
            'column' => $b['type'] === 'columns' ? ($columns[$p['column_id']] ?? (reset($columns) ?: null)) : null,
            'bundle_id' => $p['bundle_id'] !== null && isset($bundleIndex[(int)$p['bundle_id']]) ? (int)$p['bundle_id'] : null,
            'title' => $p['title'],
            'body' => $p['body'],
            'image' => board_image_url($b['bkey'], $p['image']),
            'link' => $p['link'],
            'author' => $p['author_name'],
            'created_at' => wb_local_time($p['created_at']),
            'reactions' => $rx['post'][(int)$p['id']] ?? [],
            'comments' => array_map(fn($c) => [
                'id' => (int)$c['id'],
                'body' => $c['body'],
                'author' => $c['author_name'],
                'created_at' => wb_local_time($c['created_at']),
                'reactions' => $rx['comment'][(int)$c['id']] ?? [],
            ], $byPost[(int)$p['id']] ?? []),
        ];
    }
    return ['board' => board_owner_view($b), 'bundles' => $bundleOut, 'posts' => $out];
}

function board_export_markdown(array $b): string {
    $data = board_export_data($b);
    $md = '# ' . $b['name'] . "\n\n";
    if ($b['note']) $md .= '> ' . str_replace("\n", "\n> ", $b['note']) . "\n\n";
    $md .= '_' . $b['url'] . " — exportiert am " . date('d.m.Y H:i') . "_\n\n";
    $renderPost = function (array $p, string $h) use (&$md): void {
        $md .= $h . ' ' . ($p['title'] ?: 'Beitrag') . ' (#' . $p['id'] . ")\n";
        $md .= '_' . $p['author'] . ', ' . $p['created_at'] . '_';
        if ($p['reactions']) $md .= ' · ' . implode(' ', $p['reactions']);
        $md .= "\n\n" . $p['body'] . "\n\n";
        if ($p['image']) $md .= '![Bild](' . $p['image'] . ")\n\n";
        if ($p['link']) $md .= '<' . $p['link'] . ">\n\n";
        foreach ($p['comments'] as $c) {
            $md .= '- **' . $c['author'] . ':** ' . str_replace("\n", ' ', $c['body']) . ' (Kommentar #' . $c['id'] . ')';
            if ($c['reactions']) $md .= ' · ' . implode(' ', $c['reactions']);
            $md .= "\n";
        }
        if ($p['comments']) $md .= "\n";
    };
    $columnsOrder = $b['type'] === 'columns' ? array_map(fn($c) => $c['title'], $b['columns']) : [''];
    foreach ($columnsOrder as $col) {
        if ($col !== '') $md .= '## ' . $col . "\n\n";
        $inCol = fn($x) => ($x['column'] ?? '') === $col || $col === '';
        foreach (array_filter($data['bundles'], $inCol) as $g) {
            $md .= '### Bündel: ' . $g['title'] . ' (Bündel #' . $g['id'] . ', ' . count($g['post_ids']) . " Beiträge)\n\n";
            if ($g['summary']) $md .= $g['summary'] . "\n\n";
            foreach ($data['posts'] as $p) {
                if ($p['bundle_id'] === $g['id']) $renderPost($p, '####');
            }
        }
        $loose = array_filter($data['posts'], fn($p) => $inCol($p) && $p['bundle_id'] === null);
        if ($loose && $data['bundles']) $md .= "### Nicht gebündelt\n\n";
        foreach ($loose as $p) $renderPost($p, $data['bundles'] ? '####' : '###');
    }
    return $md;
}
