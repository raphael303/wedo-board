<?php
declare(strict_types=1);
/*
 * Testbestand für Sandbox und Testinstanz (Nutzerverwaltung, Abschnitte 16 und 17; Etappe U0).
 *
 *   php tools/seed.php test|demo [--reset]
 *
 * Sicherungen:
 *   1. Läuft nur, wenn config.php die Instanz ausdrücklich als 'sandbox' oder 'test' ausweist.
 *   2. Eine Datenbank mit Inhalten, aber ohne Marker (settings.instance_marker) wird nie angefasst.
 *   3. Trägt die Datenbank einen Marker einer anderen Instanzart, bricht das Skript ab.
 *   4. Hat die Datenbank schon Inhalte, braucht es --reset; das leert nur Datenbanken mit passendem Marker.
 * Auf einer leeren Datenbank legt das Skript das Schema selbst an (wie install.php).
 *
 * Zugangsdaten des Besitzers: config.php kann 'test_logins' => ['owner_password' => …, 'api_token' => …]
 * setzen. In der Sandbox gelten sonst die festen Werte unten; sie stehen im öffentlichen Repository und
 * gelten nur dort, weil die Sandbox nicht aus dem Netz erreichbar ist. Auf der Testinstanz entstehen ohne
 * 'test_logins' zufällige Werte, die das Skript nicht ausgibt.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/lib/bootstrap.php';
// Fachfunktionen fragen die Sitzung ab; sie muss vor der ersten Ausgabe beginnen.
@wb_session_start();

const SEED_SANDBOX_PASSWORD = 'sandbox-passwort-123';
const SEED_SANDBOX_TOKEN = '5a4e4b0c5a4e4b0c5a4e4b0c5a4e4b0c5a4e4b0c5a4e4b0c';
const SEED_CONTENT_TABLES = ['reactions', 'comments', 'posts', 'bundles', 'board_summaries', 'guest_names', 'guest_blocks', 'undo_log',
    'activity_responses', 'activity_groupings', 'exam_attempts', 'exam_questions', 'activity_guest_names', 'activities', 'boards',
    'feedback', 'ai_usage', 'ai_credentials', 'rate_limits'];

function seed_fail(string $msg, int $code = 2): never {
    fwrite(STDERR, "seed: $msg\n");
    exit($code);
}

function seed_table_exists(string $t): bool {
    return (int)db_row('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t])['n'] > 0;
}

function seed_has_content(): bool {
    foreach (['boards', 'activities', 'feedback'] as $t) {
        if (seed_table_exists($t) && (int)db_row("SELECT COUNT(*) AS n FROM $t")['n'] > 0) return true;
    }
    return false;
}

/** Deterministischer Gastschlüssel (32 Hex-Zeichen) pro Name, damit Läufe vergleichbar bleiben. */
function seed_uid(string $who): string {
    return substr(hash('sha256', 'seed-guest|' . $who), 0, 32);
}

$args = array_slice($argv, 1);
$set = $args[0] ?? '';
$reset = in_array('--reset', $args, true);
if (!in_array($set, ['test', 'demo'], true)) seed_fail("Aufruf: php tools/seed.php test|demo [--reset]", 1);

/* ---------- Sicherungen ---------- */
$instance = wb_instance();
if ($instance === 'production') seed_fail("verweigert: config.php weist die Instanz nicht als 'sandbox' oder 'test' aus.");

$hasSettings = seed_table_exists('settings');
$marker = $hasSettings ? setting_get('instance_marker') : null;
if ($marker !== null && $marker !== $instance) seed_fail("verweigert: Die Datenbank trägt den Marker '$marker', config.php sagt '$instance'.");
if ($hasSettings && $marker === null && seed_has_content()) seed_fail('verweigert: Die Datenbank enthält Inhalte, aber keinen Marker. Sie gehört nicht zu einer Test- oder Sandbox-Instanz.');
if (seed_has_content()) {
    if (!$reset) seed_fail('Die Datenbank enthält schon einen Testbestand. Neu laden mit --reset.', 3);
    foreach (SEED_CONTENT_TABLES as $t) if (seed_table_exists($t)) db()->exec("DELETE FROM $t");
    foreach (['ai_enabled', 'reserved_renamed'] as $k) db_exec('DELETE FROM settings WHERE k = ?', [$k]);
    db_exec("DELETE FROM settings WHERE k LIKE 'exam\\_ai\\_pause\\_%' OR k LIKE 'ai\\_organize\\_prefs\\_%'");
    echo "Bestehenden Testbestand geleert.\n";
}

/* ---------- Schema und Besitzer ---------- */
if (!$hasSettings) {
    foreach (db_schema() as $sql) db()->exec($sql);
    setting_set('schema_version', (string)WB_SCHEMA_VERSION);
    setting_set('installed_at', date('c'));
    db_ensure_superadmin();
    echo "Schema angelegt (Version " . WB_SCHEMA_VERSION . ").\n";
} else {
    $n = db_migrate();
    if ($n) echo "Migrationen ausgeführt: $n\n";
}
setting_set('instance_marker', $instance);
setting_set('seed_set', $set);
setting_set('seeded_at', gmdate('c'));

$logins = (array)wb_config('test_logins', []);
if ($instance === 'sandbox') {
    $pw = (string)($logins['owner_password'] ?? SEED_SANDBOX_PASSWORD);
    $token = (string)($logins['api_token'] ?? SEED_SANDBOX_TOKEN);
} else {
    $pw = (string)($logins['owner_password'] ?? bin2hex(random_bytes(16)));
    $token = (string)($logins['api_token'] ?? bin2hex(random_bytes(24)));
}
setting_set('admin_password_hash', password_hash($pw, PASSWORD_DEFAULT));
setting_set('api_token', $token);
// Schalter: offene Registrierung und Organisationen auf Test und Sandbox (ab U1a wirksam).
setting_set('registration_mode', 'open');
setting_set('orgs_enabled', '1');
if (!is_dir(WB_ROOT . '/uploads')) @mkdir(WB_ROOT . '/uploads', 0755);

/* ---------- Inhalte ---------- */
mt_srand(20261119);
$data = require __DIR__ . "/seed/$set.php";
$count = ['boards' => 0, 'posts' => 0, 'activities' => 0, 'feedback' => 0];

// KI über den Fake-Anbieter, damit KI-Funktionen bis zum Aufruf nachgestellt werden können.
if (!empty($data['ai'])) {
    try {
        $cred = ai_credential_save('fake', 'fake-ok', 'fake-1', 'Fake (Testbestand)', 1);
        setting_set('ai_enabled', '1');
    } catch (Throwable $e) {
        echo "Hinweis: KI-Zugang nicht angelegt ({$e->getMessage()}).\n";
    }
}

function seed_board(array $spec, array &$count): void {
    $b = board_create($spec + ['note' => '', 'tags' => '']);
    $count['boards']++;
    $colIds = array_column($b['columns'], 'id');
    $postIds = [];
    $now = wb_now();
    foreach ($spec['posts'] ?? [] as $i => $p) {
        $who = $p['who'] ?? ('gast-' . $i);
        if ($who === 'owner') {
            $row = owner_post_create($b, ['title' => $p['title'] ?? '', 'body' => $p['body'], 'author' => $p['author'] ?? 'Lehrperson',
                'column' => isset($p['col']) ? $colIds[$p['col']] ?? null : null]);
        } else {
            $uid = seed_uid($who);
            $name = wb_guest_name($b, $uid);
            $col = $b['type'] === 'columns' ? ($colIds[$p['col'] ?? 0] ?? $colIds[0]) : null;
            $pos = $i + 1;
            $id = db_insert('INSERT INTO posts (board_id, column_id, title, body, link, author_name, author_key, position, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$b['id'], $col, ($p['title'] ?? '') !== '' ? $p['title'] : null, $p['body'], $p['link'] ?? null, $name, $uid, $pos, $now, $now]);
            $row = db_row('SELECT * FROM posts WHERE id = ?', [$id]);
        }
        $postIds[$i] = (int)$row['id'];
        $count['posts']++;
        foreach ($p['comments'] ?? [] as $j => $c) {
            $cuid = seed_uid($c[0]);
            db_insert('INSERT INTO comments (board_id, post_id, body, author_name, author_key, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$b['id'], $row['id'], $c[1], wb_guest_name($b, $cuid), $cuid, $now, $now]);
        }
        foreach ($p['reactions'] ?? [] as $j => $emoji) {
            db_exec('INSERT IGNORE INTO reactions (board_id, target_type, target_id, emoji, author_key, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$b['id'], 'post', $row['id'], $emoji, seed_uid('reakt-' . $j), $now]);
        }
    }
    board_touch($b['id']);
    $b = board_by_id($b['id']);
    if (!empty($spec['bundles'])) {
        $bundles = [];
        foreach ($spec['bundles'] as $g) {
            $bundles[] = ['title' => $g['title'], 'summary' => $g['summary'] ?? '',
                'column_id' => isset($g['col']) ? ($colIds[$g['col']] ?? null) : null,
                'post_ids' => array_map(fn($i) => $postIds[$i], $g['posts'])];
        }
        bundle_organize($b, $bundles, true, $spec['bundle_author'] ?? 'Lehrperson');
    }
    if (!empty($spec['block'])) {
        [$who, $all] = $spec['block'];
        $uid = seed_uid($who);
        guest_block_create($b, $uid, wb_guest_name($b, $uid), $all, false);
    }
    $status = $spec['final_status'] ?? 'open';
    if ($status !== 'open') board_update(board_by_id($b['id']), ['status' => $status]);
    if (!empty($spec['trash'])) board_trash(board_by_id($b['id']));
}

function seed_target(array $spec, array &$count): void {
    $a = activity_create(['type' => 'target'] + $spec);
    $count['activities']++;
    $ids = array_column($a['config']['aspects'], 'id');
    foreach ($spec['rounds'] ?? [] as $ri => $round) {
        if ($ri > 0) $a = activity_new_round(activity_by_id($a['id']));
        for ($n = 0; $n < $round['people']; $n++) {
            $pts = [];
            foreach ($ids as $k => $id) {
                $base = $round['center'][$k] ?? 0.4;
                $pts[$id] = ['r' => max(0, min(1, $base + (mt_rand(-20, 20) / 100))), 't' => mt_rand(10, 90) / 100];
            }
            target_response_save($a, seed_uid("ziel-{$a['id']}-$ri-$n"), $pts);
        }
    }
    if (!empty($spec['revealed'])) activity_set_revealed(activity_by_id($a['id']), true);
    if (($spec['final_status'] ?? 'open') !== 'open') activity_update(activity_by_id($a['id']), ['status' => $spec['final_status']]);
}

function seed_wordcloud(array $spec, array &$count): void {
    $a = activity_create(['type' => 'wordcloud'] + $spec);
    $count['activities']++;
    foreach ($spec['people'] ?? [] as $n => $terms) {
        foreach ($terms as $t) wordcloud_term_add(activity_by_id($a['id']), seed_uid("wolke-{$a['id']}-$n"), $t);
    }
    if (!empty($spec['merge'])) {
        $groups = array_map(fn($g) => ['label' => $g[0], 'members' => array_map('wordcloud_norm', $g[1])], $spec['merge']);
        wordcloud_apply_merge(activity_by_id($a['id']), $groups, [], 'teacher', 'Testbestand');
    }
    if (!empty($spec['revealed'])) activity_set_revealed(activity_by_id($a['id']), true);
}

function seed_exam(array $spec, array &$count): void {
    $a = activity_create(['type' => 'exam'] + ($spec['config'] ?? []) + ['name' => $spec['name'], 'note' => $spec['note'] ?? '', 'tags' => $spec['tags'] ?? '']);
    $count['activities']++;
    foreach ($spec['questions'] as $i => $q) {
        $by = $q['by'] ?? 'owner';
        unset($q['by']);
        $status = $q['status'] ?? null;
        unset($q['status']);
        if ($by === 'owner') {
            exam_owner_question_create(activity_by_id($a['id']), $q);
        } else {
            $row = exam_guest_question_create(activity_by_id($a['id']), seed_uid($by), $q);
            if ($status === 'accepted' || $status === 'rejected') {
                exam_owner_question_update(activity_by_id($a['id']), (int)$row['id'], ['status' => $status]);
            }
        }
    }
    $phase = $spec['phase'] ?? 'collect';
    if ($phase === 'collect') return;
    exam_set_phase(activity_by_id($a['id']), 'running');
    foreach ($spec['attempts'] ?? [] as $n => $how) {
        $uid = seed_uid("pruef-{$a['id']}-$n");
        exam_attempt_start(activity_by_id($a['id']), $uid);
        $answers = [];
        foreach (exam_question_map($a) as $q) {
            if ($q['status'] !== 'accepted' || $q['deleted_at'] !== null) continue;
            $answers[$q['id']] = seed_exam_answer($q, $how);
        }
        exam_attempt_save_answers(activity_by_id($a['id']), $uid, $answers);
        if ($how !== 'running') exam_attempt_submit(activity_by_id($a['id']), $uid);
    }
    if ($phase === 'results') exam_set_phase(activity_by_id($a['id']), 'results');
}

/** Antwort nach Muster: 'good' meist richtig, 'bad' meist falsch, 'running' halb ausgefüllt. */
function seed_exam_answer(array $q, string $how) {
    $opts = exam_q_options($q);
    $right = $how === 'good' ? mt_rand(1, 10) <= 8 : ($how === 'bad' ? mt_rand(1, 10) <= 3 : mt_rand(0, 1) === 1);
    switch ($q['type']) {
        case 'single':
            $c = array_values(array_filter($opts, fn($o) => $o['correct'] === $right));
            return $c ? $c[0]['id'] : $opts[0]['id'];
        case 'multi':
            return array_values(array_map(fn($o) => $o['id'], array_filter($opts, fn($o) => $right ? $o['correct'] : !$o['correct'])));
        case 'truefalse':
            $out = [];
            foreach ($opts as $o) $out[$o['id']] = $right ? $o['correct'] : !$o['correct'];
            return $out;
        default:
            return $right ? ($q['solution_text'] ?? 'Antwort') : 'Weiss ich nicht genau.';
    }
}

foreach ($data['boards'] ?? [] as $spec) seed_board($spec, $count);
foreach ($data['targets'] ?? [] as $spec) seed_target($spec, $count);
foreach ($data['wordclouds'] ?? [] as $spec) seed_wordcloud($spec, $count);
foreach ($data['exams'] ?? [] as $spec) seed_exam($spec, $count);
foreach ($data['feedback'] ?? [] as $i => $fb) {
    feedback_create($fb, seed_uid('rueckmeldung-' . $i), '127.0.0.' . ($i + 1));
    $count['feedback']++;
}
if (!empty($data['ai_usage']) && isset($cred)) {
    foreach ($data['ai_usage'] as $u) {
        ai_usage_log(null, $u[0], $cred, ['model' => 'fake-1', 'input_tokens' => $u[1], 'output_tokens' => $u[2], 'duration_ms' => 800], $u[3] ? null : new AiException('rate_limit', 'Testbestand'), 1);
    }
}
db_exec('DELETE FROM rate_limits');

echo "Satz '$set' geladen ($instance): {$count['boards']} Boards, {$count['posts']} Beiträge, {$count['activities']} Aktivitäten, {$count['feedback']} Rückmeldungen.\n";
