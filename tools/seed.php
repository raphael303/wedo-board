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
 * Konten (seit 0.22.0): Der Benutzer 1 ist das Konto der Besitzerin der Instanz (Super-Admin). In der Sandbox
 * heisst es owner@tafula.test mit dem festen Passwort unten; auf der Testinstanz trägt es owner_email aus
 * config.php, bleibt bei --reset samt Passwort und zweiter Stufe erhalten und erhält beim ersten Laden ein
 * zufälliges Passwort, das niemand kennt (die Person setzt es über «Passwort vergessen»).
 * Der Satz «test» legt dazu Testkonten unter der reservierten Domain tafula.test an (alle Systemrollen,
 * Personen mit und ohne Lehrperson-Freigabe, eine Schule als Attrappe, ein unbestätigtes Konto, eine offene
 * Einladung). Ihr Passwort steht unten und im öffentlichen Repository; es gilt nur in Sandbox und Testinstanz,
 * wo unter /test ohnehin ein Klick genügt.
 * config.php kann 'test_logins' => ['owner_password' => …, 'api_token' => …] setzen (api_token: persönliches Token des Benutzers 1).
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/lib/bootstrap.php';

const SEED_SANDBOX_PASSWORD = 'sandbox-passwort-123';
const SEED_TEST_PASSWORD = 'tafula-test-passwort';
/** Geheimnis der App-Stufe des Test-Admins (öffentlich, nur für Tests; Base32). */
const SEED_TEST_TOTP = 'KRQWM5LMMFKGK43UIFSG22LO';
const SEED_SANDBOX_TOKEN = '5a4e4b0c5a4e4b0c5a4e4b0c5a4e4b0c5a4e4b0c5a4e4b0c';
const SEED_CONTENT_TABLES = ['reactions', 'comments', 'posts', 'bundles', 'board_summaries', 'guest_names', 'guest_blocks', 'undo_log',
    'activity_responses', 'activity_groupings', 'exam_attempts', 'exam_questions', 'activity_guest_names', 'activities', 'boards',
    'feedback', 'ai_usage', 'ai_credentials', 'rate_limits',
    'sessions', 'login_tokens', 'known_devices', 'invitations', 'terms_acceptances', 'audit_log', 'mail_queue', 'mail_suppressions', 'orgs',
    // Verbindungen hängen an Arbeitsbereichen, die beim Neuladen neu entstehen; persönliche Tokens des Benutzers 1 in «Privat» bleiben (siehe unten).
    'oauth_tokens', 'oauth_codes', 'oauth_grants', 'oauth_clients'];

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
    // Alle Konten ausser dem Benutzer 1 (Besitzerin der Instanz) samt ihren Rollen.
    if (seed_table_exists('api_tokens')) db()->exec('DELETE FROM api_tokens WHERE user_id <> 1 OR org_id IS NOT NULL');
    if (seed_table_exists('memberships')) { db()->exec('DELETE FROM memberships WHERE user_id <> 1 OR scope_type <> \'system\''); db()->exec('DELETE FROM users WHERE id <> 1'); }
    foreach (['reserved_renamed'] as $k) db_exec('DELETE FROM settings WHERE k = ?', [$k]);
    db_exec("DELETE FROM settings WHERE k LIKE 'exam\\_ai\\_pause\\_%' OR k LIKE 'u%:ai\\_%'");
    echo "Bestehenden Testbestand geleert.\n";
}

/* ---------- Schema und Besitzerin ---------- */
if (ai_secret_key() === null) seed_fail("config.php braucht 'app_secret' (oder muss beschreibbar sein).");
if (!$hasSettings) {
    foreach (db_schema() as $sql) db()->exec($sql);
    setting_set('schema_version', (string)WB_SCHEMA_VERSION);
    setting_set('installed_at', date('c'));
    echo "Schema angelegt (Version " . WB_SCHEMA_VERSION . ").\n";
} else {
    $n = db_migrate();
    if ($n) echo "Migrationen ausgeführt: $n\n";
}
setting_set('instance_marker', $instance);
setting_set('seed_set', $set);
setting_set('seeded_at', gmdate('c'));

$logins = (array)wb_config('test_logins', []);
$ownerEmail = wb_email_normalize((string)wb_config('owner_email', '')) ?? ($instance === 'sandbox' ? 'owner@' . WB_TEST_DOMAIN : null);
if ($ownerEmail === null) seed_fail("config.php der Testinstanz braucht 'owner_email' (Adresse des Benutzers 1).");
if ($instance === 'sandbox') {
    $pw = (string)($logins['owner_password'] ?? SEED_SANDBOX_PASSWORD);
    $token = (string)($logins['api_token'] ?? SEED_SANDBOX_TOKEN);
} else {
    $pw = isset($logins['owner_password']) ? (string)$logins['owner_password'] : null;
    $token = isset($logins['api_token']) ? (string)$logins['api_token'] : null;
}
$now = wb_now();
$owner = db_row('SELECT * FROM users WHERE id = 1');
if (!$owner) {
    db_exec('INSERT INTO users (id, first_name, last_name, email, password_hash, created_at, email_verified_at, age_confirmed_at, password_changed_at) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?)',
        [(string)wb_config('owner_name', 'Besitzerin'), '', $ownerEmail, password_hash($pw ?? bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $now, $now, $now, $now]);
} else {
    // Bestehendes Konto der Besitzerin bleibt, wie es ist (Passwort, zweite Stufe); nur die Adresse folgt config.php.
    db_exec('UPDATE users SET email = ?, email_verified_at = COALESCE(email_verified_at, ?), disabled_at = NULL, deleted_at = NULL WHERE id = 1', [$ownerEmail, $now]);
    if ($pw !== null) db_exec('UPDATE users SET password_hash = ? WHERE id = 1', [password_hash($pw, PASSWORD_DEFAULT)]);
}
db_exec("INSERT IGNORE INTO memberships (user_id, scope_type, scope_id, role, created_at) VALUES (1, 'system', 0, 'superadmin', ?)", [$now]);
db_exec("DELETE FROM settings WHERE k = 'admin_password_hash'");
// Seit 0.23.0 gibt es keinen api_token in settings mehr. Die Sandbox (und eine Testinstanz mit test_logins) erhält
// stattdessen ein persönliches Token des Benutzers 1 mit bekanntem Wert für die Schnittstelle /api/.
db_exec("DELETE FROM settings WHERE k = 'api_token'");
if ($token !== null && !db_row('SELECT id FROM api_tokens WHERE token_hash = ?', [wb_token_hash($token)])) {
    api_token_create(1, null, 'personal', 'Testbestand', null, $token);
}
// Schalter: offene Registrierung und Organisationen auf Test und Sandbox.
setting_set('registration_mode', 'open');
setting_set('orgs_enabled', '1');
setting_set('teacher_approval', 'manual');
// Testinstanz: Ohne Zugangscode bleibt die Tür zu, bis tools/rescue.php gate-code einen setzt und verschickt.
if ($instance === 'test') {
    foreach ([1, 2] as $lvl) if (!setting_get('test_gate' . $lvl . '_hash')) gate_set_code($lvl, bin2hex(random_bytes(16)));
}
if (!is_dir(WB_ROOT . '/uploads')) @mkdir(WB_ROOT . '/uploads', 0755);
// Inhalte des Satzes gehören zuerst der Besitzerin im Arbeitsbereich «Privat».
wb_auth_cli(1, null);

/* ---------- Inhalte ---------- */
mt_srand(20261119);
$data = require __DIR__ . "/seed/$set.php";
$count = ['boards' => 0, 'posts' => 0, 'activities' => 0, 'feedback' => 0];

// KI über den Fake-Anbieter, damit KI-Funktionen bis zum Aufruf nachgestellt werden können.
if (!empty($data['ai'])) {
    try {
        $cred = ai_credential_save('fake', 'fake-ok', 'fake-1', 'Fake (Testbestand)', 1);
        user_setting_set(1, 'ai_enabled', '1');
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

/* ---------- Testkonten, Rollen und die Schule als Attrappe (nur Satz «test») ---------- */
if ($set === 'test') {
    $mk = function (string $local, string $first, string $last, array $opt = []) use ($now): int {
        $u = user_create(['email' => $local . '@' . WB_TEST_DOMAIN, 'password' => SEED_TEST_PASSWORD, 'first_name' => $first, 'last_name' => $last,
            'verified' => empty($opt['unverified']), 'age_confirmed' => empty($opt['no_age']), 'invited_by' => $opt['invited_by'] ?? null]);
        $id = (int)$u['id'];
        foreach ($opt['roles'] ?? [] as $r) membership_add($id, 'system', 0, $r, 1);
        if (!empty($opt['teacher'])) db_exec('UPDATE users SET teacher_requested_at = ?, teacher_approved_at = ?, teacher_approved_by = 1 WHERE id = ?', [$now, $now, $id]);
        if (!empty($opt['teacher_requested'])) db_exec('UPDATE users SET teacher_requested_at = ? WHERE id = ?', [$now, $id]);
        return $id;
    };
    $sa = $mk('sara.super', 'Sara', 'Super', ['roles' => ['superadmin']]);
    $ad = $mk('adam.admin', 'Adam', 'Admin', ['roles' => ['admin']]);
    db_exec('UPDATE users SET totp_secret_enc = ?, recovery_codes_json = ? WHERE id = ?', [wb_seal('totp', SEED_TEST_TOTP), '[]', $ad]);
    $mk('lisa.lizenz', 'Lisa', 'Lizenz', ['roles' => ['license_manager']]);
    $mk('viktor.vorfuehrung', 'Viktor', 'Vorführung', ['roles' => ['presenter']]);
    $lea = $mk('lea.privat', 'Lea', 'Privat');
    $tom = $mk('tom.lehrer', 'Tom', 'Lehrer', ['teacher' => true, 'invited_by' => 1]);
    $nora = $mk('nora.zwei', 'Nora', 'Zwei', ['teacher' => true]);
    $mk('anna.antrag', 'Anna', 'Antrag', ['teacher_requested' => true]);
    $mk('uwe.unbestaetigt', 'Uwe', 'Unbestätigt', ['unverified' => true]);
    $count['accounts'] = 9;

    // Schule als Attrappe, damit Arbeitsbereiche geprüft werden können, bevor U4 Schulen baut.
    $district = db_insert("INSERT INTO orgs (okey, type, parent_id, name, place, verified_at, created_at, updated_at) VALUES (?, 'district', NULL, 'Testbezirk (Attrappe)', 'Testwil', ?, ?, ?)", [wb_random_key(16), $now, $now, $now]);
    // Der Schlüssel der Attrappe ist fest pro Instanz, damit die Adresse ihres Connectors (/mcp/o/<okey>) ein
    // Neuladen des Testbestands übersteht. Er ist kein Geheimnis.
    $school = db_insert("INSERT INTO orgs (okey, type, parent_id, name, place, verified_at, created_at, updated_at) VALUES (?, 'school', ?, 'Testschule (Attrappe)', 'Testwil', ?, ?, ?)", [substr(hash('sha256', 'attrappe-schule|' . wb_base_url()), 0, 16), $district, $now, $now, $now]);
    // Die Besitzerin der Instanz ist dort Lehrperson, damit sie zwei Connectoren (privat und Schule) nebeneinander prüfen kann (U1b).
    membership_add(1, 'org', $school, 'teacher', 1);
    membership_add($tom, 'org', $school, 'teacher', 1);
    membership_add($nora, 'org', $school, 'teacher', 1);

    // Inhalte anderer Personen: Daran zeigt sich, dass niemand Fremdes sieht.
    wb_auth_cli($lea, null);
    $b = board_create(['name' => 'Test: Leas privates Board', 'slug' => 'test-lea', 'note' => 'Testbestand: gehört Lea Privat (Arbeitsbereich «Privat»).', 'tags' => 'test, fremd']);
    owner_post_create($b, ['body' => 'Nur Lea verwaltet dieses Board.', 'author' => 'Lea']);
    activity_create(['type' => 'target', 'name' => 'Test: Leas Zielscheibe', 'slug' => 'test-lea-ziel', 'aspects' => ['Tempo', 'Klarheit', 'Stimmung'], 'note' => 'Testbestand: gehört Lea Privat.', 'tags' => 'test, fremd']);
    $x = activity_create(['type' => 'exam', 'name' => 'Test: Leas Prüfung', 'slug' => 'test-lea-pruefung', 'note' => 'Testbestand: gehört Lea Privat.', 'tags' => 'test, fremd']);
    exam_owner_question_create($x, ['type' => 'open', 'prompt' => 'Was ist geheim?', 'solution' => 'LEAS-GEHEIME-LOESUNG', 'points' => 1]);
    wb_auth_cli($tom, $school);
    board_create(['name' => 'Test: Toms Board in der Schule', 'slug' => 'test-tom-schule', 'note' => 'Testbestand: gehört Tom Lehrer, Arbeitsbereich Testschule.', 'tags' => 'test, schule']);
    wb_auth_cli($nora, $school);
    board_create(['name' => 'Test: Noras Board in der Schule', 'slug' => 'test-nora-schule', 'note' => 'Testbestand: gehört Nora Zwei, Arbeitsbereich Testschule.', 'tags' => 'test, schule']);
    wb_auth_cli($nora, null);
    board_create(['name' => 'Test: Noras privates Board', 'slug' => 'test-nora-privat', 'note' => 'Testbestand: gehört Nora Zwei, Arbeitsbereich «Privat».', 'tags' => 'test, privat']);
    $count['boards'] += 4; $count['activities'] += 2;

    // Eine offene Einladung mit Lehrperson-Freigabe (der Link steht im Sammelpostfach).
    wb_auth_cli(1, null);
    invitation_create('eingeladen@' . WB_TEST_DOMAIN, true, 30, 1);
}
db_exec('DELETE FROM rate_limits');

$count['accounts'] = $count['accounts'] ?? 0;
echo "Satz '$set' geladen ($instance): {$count['accounts']} Testkonten, {$count['boards']} Boards, {$count['posts']} Beiträge, {$count['activities']} Aktivitäten, {$count['feedback']} Rückmeldungen.\n";
