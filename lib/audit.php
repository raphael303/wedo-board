<?php
declare(strict_types=1);

/*
 * Protokoll (Nutzerverwaltung, Abschnitte 4 und 13): Handlungen des Personals, Änderungen an Systemeinstellungen,
 * Einladungen, Rollen, Anmeldungen der Testrollen, jeder Schritt beim Entfernen der zweiten Stufe und das
 * Notfall-Skript. Nie Inhalte von Boards oder Aktivitäten, nie Passwörter, Codes oder Tokens.
 */
function audit_log(string $action, string $targetType = '', ?int $targetId = null, array $details = [], ?int $orgId = null, ?int $actorId = null, string $actorRole = ''): void {
    $auth = PHP_SAPI === 'cli' && $actorId === null ? null : wb_auth();
    if ($actorId === null && $auth) {
        $actorId = (int)$auth['user']['id'];
        if ($actorRole === '') $actorRole = user_system_roles($actorId)[0] ?? '';
    }
    if ($actorRole === '' && PHP_SAPI === 'cli') $actorRole = 'cli';
    try {
        db_exec('INSERT INTO audit_log (created_at, actor_user_id, actor_role, action, target_type, target_id, org_id, ip, details_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [wb_now(), $actorId, mb_substr($actorRole, 0, 24), mb_substr($action, 0, 48), mb_substr($targetType, 0, 24), $targetId, $orgId, wb_client_ip(),
             $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null]);
    } catch (PDOException $e) {
        error_log(wb_app_name() . ' audit: ' . $e->getMessage());
    }
}

function audit_recent(int $limit = 100, ?string $action = null): array {
    $limit = max(1, min(500, $limit));
    $where = $action !== null && $action !== '' ? ' WHERE a.action LIKE ?' : '';
    return db_all("SELECT a.*, u.email AS actor_email FROM audit_log a LEFT JOIN users u ON u.id = a.actor_user_id$where ORDER BY a.id DESC LIMIT $limit",
        $where ? [$action . '%'] : []);
}
