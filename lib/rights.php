<?php
declare(strict_types=1);

/*
 * Rechte (Nutzerverwaltung U1a). Es gibt genau zwei zentrale Funktionen:
 *
 *   wb_can($ability, $object)             entscheidet alles im Verwaltungsteil (Bibliothek, Verwaltung,
 *                                         Schnittstelle, Connector) nach docs/rechte-matrix.md.
 *   wb_can_participate($object, $write)   entscheidet für Gäste und angemeldete Teilnehmende, ob sie ein Board
 *                                         oder eine Aktivität sehen und beschreiben dürfen.
 *
 * Eine Route, die Daten liest oder schreibt, ohne eine der beiden zu durchlaufen, ist ein Fehler.
 *
 * Grundregeln (Matrix, Abschnitt 1): Inhaltsrechte folgen nie aus einer System- oder Verwalterrolle; im
 * Verwaltungsteil gelten nur die Rechte des gewählten Arbeitsbereichs; ein Token trägt nie Systemrechte. Das gilt
 * für alle drei Arten von Tokens (OAuth-Verbindung, persönliches Token, früherer api_token): kind ist «token».
 */

/** Fähigkeiten an einem Board oder einer Aktivität: nur die Besitzerin, nur im Arbeitsbereich des Objekts. */
const WB_CONTENT_ABILITIES = ['board.manage', 'activity.manage', 'bundle.manage', 'undo', 'ai.use', 'guest.block'];

/** Systemfähigkeiten und die Systemrollen, aus denen sie folgen (Matrix, Tabelle «System»). */
const WB_SYSTEM_ABILITIES = [
    'system.settings' => ['superadmin'],
    'invite.create' => ['superadmin'],
    'staff.appoint' => ['superadmin'],
    'role.grant' => ['superadmin', 'admin'],
    'feedback.manage' => ['superadmin', 'admin'],
    'audit.read' => ['superadmin', 'admin'],
    'account.find' => ['superadmin', 'admin', 'license_manager'],
    'account.totp_removal' => ['superadmin', 'admin'],
    'teacher.decide' => ['superadmin', 'admin', 'license_manager'],
    'maintenance.purge' => ['superadmin'],
    'test.helpers' => ['superadmin', 'admin'],
];

function wb_can(string $ability, ?array $object = null): bool {
    $auth = wb_auth();
    if ($auth === null) return false;
    $user = $auth['user'];
    if (!user_is_active($user)) return false;
    $uid = (int)$user['id'];

    if (in_array($ability, WB_CONTENT_ABILITIES, true)) {
        if ($object === null || !isset($object['owner_user_id'])) return false;
        if ((int)$object['owner_user_id'] !== $uid) return false;
        $org = isset($object['org_id']) && $object['org_id'] !== null ? (int)$object['org_id'] : null;
        return $org === $auth['org_id'];
    }
    switch ($ability) {
        case 'content.create':
            // Jedes selbst verwaltete Konto führt Inhalte in «Privat»; in einer Schule nur als Lehrperson
            // (wb_auth() hat den Arbeitsbereich schon geprüft). Karten-Konten (U2) nehmen nur teil.
            return true;
        case 'connector.connect':
        case 'api_token.manage':
            // Matrix «Eigenes Konto»: Verbindungen und persönliche Tokens verwaltet jedes selbst verwaltete Konto,
            // aber nur in einer Sitzung, nie über ein Token. Der Plan wird ab U3 geprüft.
            return $auth['kind'] === 'session';
        case 'block.lift':
            // Nur eigene Sperren im Arbeitsbereich des Aufrufs (S1, N3).
            return $object !== null && isset($object['user_id']) && (int)$object['user_id'] === $uid && guest_block_in_workspace($object);
        case 'ai.credential.manage':
            return $object === null || (int)($object['user_id'] ?? 0) === $uid;
    }
    if (isset(WB_SYSTEM_ABILITIES[$ability])) {
        // Nie über einen Token, und nur mit App-Stufe (Testrollen der Testinstanz sind davon ausgenommen).
        if ($auth['kind'] === 'token') return false;
        if ($auth['kind'] === 'session' && $auth['session']['mfa_level'] !== 'totp' && $auth['session']['kind'] !== 'test') return false;
        if ($ability === 'test.helpers' && !wb_test_helpers_enabled()) return false;
        return array_intersect(WB_SYSTEM_ABILITIES[$ability], user_system_roles($uid)) !== [];
    }
    return false; // Unbekannte und noch nicht gebaute Fähigkeiten sind «nein».
}

function wb_require_can(string $ability, ?array $object = null): void {
    if (!wb_can($ability, $object)) {
        wb_error('Nicht berechtigt.', wb_current_user() === null ? 401 : 403);
    }
}

/** Für Routen der Schnittstelle: ohne Anmeldung 401. */
function wb_require_login(): array {
    $u = wb_current_user();
    if ($u === null || !user_is_active($u)) wb_error('Nicht berechtigt.', 401);
    return $u;
}

/** Besitzerfilter für Listen: Konto und Arbeitsbereich des Aufrufs. Ohne Anmeldung ein Fehler. */
function wb_scope(): array {
    return ['owner_user_id' => wb_user_id(), 'org_id' => wb_workspace_org_id()];
}

/** SQL-Bedingung und Parameter zum Besitzerfilter. */
function wb_scope_sql(array $opt, string $alias = ''): array {
    if (!isset($opt['owner_user_id']) || !array_key_exists('org_id', $opt)) throw new LogicException('Liste ohne Besitzerfilter.');
    $p = $alias !== '' ? $alias . '.' : '';
    return $opt['org_id'] === null
        ? ["{$p}owner_user_id = ? AND {$p}org_id IS NULL", [(int)$opt['owner_user_id']]]
        : ["{$p}owner_user_id = ? AND {$p}org_id = ?", [(int)$opt['owner_user_id'], (int)$opt['org_id']]];
}

/** Board über Schlüssel oder Kurzwort, aber nur, wenn es dem Aufruf gehört; sonst null (für 404). */
function board_owned(string $ref, bool $withTrash = false): ?array {
    $b = board_find($ref, $withTrash);
    return $b && wb_can('board.manage', $b) ? $b : null;
}

function activity_owned(string $ref, bool $withTrash = false): ?array {
    $a = activity_find($ref, $withTrash);
    return $a && wb_can('activity.manage', $a) ? $a : null;
}

/**
 * Teilnahme: 'see' und 'write' für diesen Browser an diesem Objekt, dazu der Grund einer Ablehnung
 * ('gone', 'closed', 'blocked'). Die Besitzerin wird hier nicht bevorzugt; ihre Rechte prüft wb_can().
 * In U1a entscheiden Papierkorb, Status und Sperre; Zugangseinstellung, Passwort, Klasse, Sperre des Objekts
 * und Ruhe kommen in U2 und U3 an dieser Stelle dazu.
 */
function wb_participation(array $object, ?string $guestKey = null): array {
    if (($object['deleted_at'] ?? null) !== null || ($object['status'] ?? '') === 'archived') {
        return ['see' => false, 'write' => false, 'reason' => 'gone', 'blocked' => false];
    }
    // Sperre: auf einem Board die eigene oder die «auf allen Boards» seiner Besitzerin; auf einer Aktivität nur die zweite.
    $blocked = $guestKey !== null && guest_block_active($guestKey, isset($object['bkey']) ? $object : ['id' => 0, 'owner_user_id' => $object['owner_user_id']]) !== null;
    if (($object['status'] ?? 'open') !== 'open') return ['see' => true, 'write' => false, 'reason' => 'closed', 'blocked' => $blocked];
    if ($blocked) return ['see' => true, 'write' => false, 'reason' => 'blocked', 'blocked' => true];
    return ['see' => true, 'write' => true, 'reason' => null, 'blocked' => false];
}

function wb_can_participate(array $object, bool $write = false, ?string $guestKey = null): bool {
    $p = wb_participation($object, $guestKey);
    return $write ? $p['write'] : $p['see'];
}
