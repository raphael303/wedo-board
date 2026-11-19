<?php
declare(strict_types=1);
/*
 * Prüfung der Rechte-Matrix (docs/rechte-matrix.md) gegen den Testbestand «test». Aufgerufen von tools/test.sh
 * auf einer Datenbank, in die tools/seed.php den Satz geladen hat. Geprüft wird jede in U1a gebaute Zeile für
 * jede Stellung einzeln; alles, was eine spätere Etappe baut, muss «nein» sein. Ausgabe: eine Zeile pro
 * Abweichung, am Schluss die Anzahl der Prüfungen. Rückgabewert 1 bei Abweichungen.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/lib/bootstrap.php';
if (!wb_test_helpers_enabled()) { fwrite(STDERR, "Nur auf einer Datenbank mit Testbestand.\n"); exit(2); }

$fails = 0; $n = 0;
$check = function (string $what, bool $got, bool $want) use (&$fails, &$n): void {
    $n++;
    if ($got !== $want) { $fails++; echo 'ABWEICHUNG: ', $what, ' ist ', $got ? 'ja' : 'nein', ', erwartet ', $want ? 'ja' : 'nein', "\n"; }
};
$id = fn(string $local): int => (int)user_by_email($local . '@' . WB_TEST_DOMAIN)['id'];
$people = ['SA' => $id('sara.super'), 'AD' => $id('adam.admin'), 'LV' => $id('lisa.lizenz'), 'VF' => $id('viktor.vorfuehrung'), 'P' => $id('lea.privat'), 'LP' => $id('tom.lehrer'), 'LP2' => $id('nora.zwei')];
$school = (int)db_row("SELECT id FROM orgs WHERE type = 'school' LIMIT 1")['id'];

/** Handelt wie eine Sitzung der Bibliothek mit der genannten Stufe. */
$as = function (?int $userId, ?int $org = null, string $kind = 'session', string $mfa = 'totp', string $sessionKind = 'normal'): void {
    if ($userId === null) { $GLOBALS['wb_auth_cache'] = null; wb_auth_cli(null); return; }
    $GLOBALS['wb_auth_cache'] = ['kind' => $kind, 'user' => user_by_id($userId), 'org_id' => $org,
        'session' => $kind === 'session' ? ['mfa_level' => $mfa, 'kind' => $sessionKind, 'id' => 0] : null];
};

/* ---------- Tabelle «System» ---------- */
$system = [
    'system.settings' => ['SA'], 'invite.create' => ['SA'], 'staff.appoint' => ['SA'], 'role.grant' => ['SA', 'AD'],
    'feedback.manage' => ['SA', 'AD'], 'audit.read' => ['SA', 'AD'], 'account.find' => ['SA', 'AD', 'LV'],
    'account.totp_removal' => ['SA', 'AD'], 'teacher.decide' => ['SA', 'AD', 'LV'], 'maintenance.purge' => ['SA'], 'test.helpers' => ['SA', 'AD'],
];
foreach ($system as $ability => $allowed) {
    if (!isset(WB_SYSTEM_ABILITIES[$ability])) { $fails++; echo "ABWEICHUNG: Fähigkeit $ability fehlt im Code\n"; continue; }
    foreach ($people as $role => $uid) {
        $as($uid);
        $check("$ability für $role (Sitzung mit App-Stufe)", wb_can($ability), in_array($role, $allowed, true));
        // Ohne App-Stufe und über einen Token nie; die Testrolle der Testinstanz ist ausgenommen.
        $as($uid, null, 'session', 'mail');
        $check("$ability für $role (Sitzung ohne App-Stufe)", wb_can($ability), false);
        $as($uid, null, 'token');
        $check("$ability für $role (Token)", wb_can($ability), false);
        $as($uid, null, 'session', 'none', 'test');
        $check("$ability für $role (Testrolle)", wb_can($ability), in_array($role, $allowed, true));
    }
    $as(null);
    $check("$ability für Gast", wb_can($ability), false);
}
foreach (array_keys(WB_SYSTEM_ABILITIES) as $ability) {
    if (!isset($system[$ability])) { $fails++; echo "ABWEICHUNG: Fähigkeit $ability steht im Code, aber nicht in dieser Prüfung\n"; }
}

/* ---------- Boards und Aktivitäten: nur die Besitzerin, nur im Arbeitsbereich des Objekts ---------- */
$objects = [
    'Leas Board' => [board_by_slug('test-lea'), 'P', null],
    'Leas Zielscheibe' => [activity_by_slug('test-lea-ziel'), 'P', null],
    'Leas Prüfung' => [activity_by_slug('test-lea-pruefung'), 'P', null],
    'Toms Schulboard' => [board_by_slug('test-tom-schule'), 'LP', $school],
    'Noras Schulboard' => [board_by_slug('test-nora-schule'), 'LP2', $school],
    'Noras privates Board' => [board_by_slug('test-nora-privat'), 'LP2', null],
    'Board der Besitzerin' => [board_by_slug('test-spalten'), null, null],
];
foreach ($objects as $name => [$obj, $ownerRole, $org]) {
    if (!$obj) { $fails++; echo "ABWEICHUNG: $name fehlt im Testbestand\n"; continue; }
    foreach (WB_CONTENT_ABILITIES as $ability) {
        foreach ($people as $role => $uid) {
            foreach ([null, $school] as $ws) {
                if ($ws !== null && wb_valid_workspace(user_by_id($uid), $ws) === null) continue;
                $as($uid, $ws);
                $check("$ability an «{$name}» für $role im Arbeitsbereich " . ($ws === null ? 'Privat' : 'Schule'), wb_can($ability, $obj), $role === $ownerRole && $ws === $org);
            }
        }
        $as(null);
        $check("$ability an «{$name}» für Gast", wb_can($ability, $obj), false);
        $check("$ability ohne Objekt", wb_can($ability), false);
    }
}
// Die Besitzerin der Instanz (Benutzer 1, Super-Admin) hat ihre Inhalte als Person, auch über den Token.
$own = board_by_slug('test-spalten');
$as(1, null, 'token');
$check('board.manage am eigenen Board über den Token', wb_can('board.manage', $own), true);
$check('board.manage an Leas Board über den Token', wb_can('board.manage', $objects['Leas Board'][0]), false);
$as(1, null, 'session', 'mail');
$check('board.manage am eigenen Board ohne App-Stufe', wb_can('board.manage', $own), true);

/* ---------- Anlegen, Sperren, eigener KI-Zugang ---------- */
foreach ($people as $role => $uid) {
    $as($uid);
    $check("content.create für $role", wb_can('content.create'), true);
}
$as(null);
$check('content.create für Gast', wb_can('content.create'), false);
$block = db_row('SELECT * FROM guest_blocks WHERE user_id = 1 LIMIT 1');
if ($block) {
    $as(1); $check('block.lift an eigener Sperre', wb_can('block.lift', $block), true);
    $as($people['P']); $check('block.lift an fremder Sperre', wb_can('block.lift', $block), false);
    $as($people['SA']); $check('block.lift an fremder Sperre für SA', wb_can('block.lift', $block), false);
}
// Arbeitsbereiche: nur mit Zugehörigkeit als Lehrperson.
$check('Lea darf nicht in die Schule wechseln', wb_valid_workspace(user_by_id($people['P']), $school) === null, true);
$check('Tom darf in die Schule wechseln', wb_valid_workspace(user_by_id($people['LP']), $school) === $school, true);
$check('Super-Admin gehört keiner Schule an', wb_valid_workspace(user_by_id($people['SA']), $school) === null, true);

/* ---------- Was spätere Etappen bauen, ist «nein» ---------- */
foreach (['class.create', 'class.manage', 'org.manage', 'license.manage', 'plan.edit', 'abuse.manage', 'account.delete', 'support.read', 'ai.models', 'demo.login', 'gibtsnicht'] as $ability) {
    foreach ($people as $role => $uid) { $as($uid); $check("$ability für $role (noch nicht gebaut)", wb_can($ability), false); }
}

/* ---------- Teilnahme ---------- */
$as(null);
$b = $objects['Leas Board'][0];
$check('Teilnahme: offenes Board sehen', wb_can_participate($b), true);
$check('Teilnahme: offenes Board beschreiben', wb_can_participate($b, true, str_repeat('a', 32)), true);
$check('Teilnahme: geschlossenes Board sehen', wb_can_participate(['status' => 'closed'] + $b), true);
$check('Teilnahme: geschlossenes Board beschreiben', wb_can_participate(['status' => 'closed'] + $b, true), false);
$check('Teilnahme: archiviertes Board sehen', wb_can_participate(['status' => 'archived'] + $b), false);
$check('Teilnahme: Board im Papierkorb sehen', wb_can_participate(['deleted_at' => wb_now()] + $b), false);
$blockedAll = db_row('SELECT guest_key FROM guest_blocks WHERE board_id IS NULL AND user_id = 1 AND lifted_at IS NULL LIMIT 1');
if ($blockedAll) {
    $k = (string)$blockedAll['guest_key'];
    $check('Sperre «alle Boards» der Besitzerin gilt auf ihrem Board', wb_can_participate($own, true, $k), false);
    $check('Sperre «alle Boards» der Besitzerin gilt auf ihrer Aktivität', wb_can_participate(activity_by_slug('test-ziel'), true, $k), false);
    $check('Sperre «alle Boards» der Besitzerin gilt nicht auf Leas Board', wb_can_participate($b, true, $k), true);
    $check('Sperre «alle Boards» der Besitzerin gilt nicht auf Leas Zielscheibe', wb_can_participate($objects['Leas Zielscheibe'][0], true, $k), true);
} else { $fails++; echo "ABWEICHUNG: Im Testbestand fehlt die Sperre für alle Boards\n"; }

echo "Matrix: $n Prüfungen, $fails Abweichungen\n";
exit($fails === 0 ? 0 : 1);
