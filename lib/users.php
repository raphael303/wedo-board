<?php
declare(strict_types=1);

/*
 * Konten, Rollen und Arbeitsbereiche (Nutzerverwaltung U1a). Rollen hängen nie am Konto, sondern stehen in
 * memberships. Karten-Konten, Klassen und echte Organisationen folgen in U2 und U4; orgs enthält bis dahin nur
 * die Attrappe aus dem Testbestand.
 */

const WB_CARD_DOMAIN = 'k.tafula.top';
const WB_SYSTEM_ROLES = ['superadmin', 'admin', 'license_manager', 'presenter'];
const WB_PASSWORD_MIN = 12;

function user_normalize(array $u): array {
    $u['id'] = (int)$u['id'];
    return $u;
}

function user_by_id(int $id): ?array {
    static $cache = [];
    if (isset($cache[$id]) && empty($GLOBALS['wb_user_cache_off'])) return $cache[$id];
    try {
        $u = db_row('SELECT * FROM users WHERE id = ?', [$id]);
    } catch (PDOException $e) {
        return null;
    }
    if (!$u) return null;
    return $cache[$id] = user_normalize($u);
}

/** Nach einer Änderung am Konto frisch lesen. */
function user_reload(int $id): ?array {
    $GLOBALS['wb_user_cache_off'] = true;
    $u = user_by_id($id);
    $GLOBALS['wb_user_cache_off'] = false;
    wb_session_forget_cache();
    return $u;
}

function user_by_email(string $email): ?array {
    $email = wb_email_normalize($email);
    if ($email === null) return null;
    $u = db_row('SELECT * FROM users WHERE email = ? AND deleted_at IS NULL', [$email]);
    return $u ? user_normalize($u) : null;
}

/** Ein Konto kann sich anmelden und handeln, wenn es bestätigt, nicht gesperrt und nicht gelöscht ist. */
function user_is_active(array $u): bool {
    return $u['disabled_at'] === null && ($u['deleted_at'] ?? null) === null && ($u['email_verified_at'] ?? null) !== null;
}

function user_name(array $u): string {
    $n = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
    return $n !== '' ? $n : (string)($u['email'] ?? '');
}

function user_has_totp(array $u): bool {
    return !empty($u['totp_secret_enc']);
}

/** Kleinschreibung und Prüfung; null bei ungültiger Adresse. */
function wb_email_normalize(string $email): ?string {
    $email = mb_strtolower(trim($email));
    if ($email === '' || strlen($email) > 200 || !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
    return $email;
}

function wb_email_domain(string $email): string {
    return (string)substr(strrchr($email, '@') ?: '@', 1);
}

/** Kennungen der Karten-Konten sind keine echten Adressen: keine Registrierung, nie eine Mail. */
function wb_email_is_card(string $email): bool {
    $d = wb_email_domain(mb_strtolower($email));
    return $d === WB_CARD_DOMAIN || str_ends_with($d, '.' . WB_CARD_DOMAIN);
}

/**
 * Prüft ein selbst gewähltes Passwort: mindestens zwölf Zeichen, nicht auf der Liste häufiger Passwörter, kein
 * einfaches Muster, nicht aus Adresse oder Namen gebildet. Gibt null zurück oder den Grund als Satz.
 */
function wb_password_problem(string $pw, array $context = []): ?string {
    $len = mb_strlen($pw);
    if ($len < WB_PASSWORD_MIN) return tx('Das Passwort braucht mindestens {n} Zeichen.', ['n' => WB_PASSWORD_MIN]);
    if (strlen($pw) > 500) return tx('Das Passwort ist zu lang.');
    $low = mb_strtolower($pw);
    $weak = tx('Dieses Passwort ist zu leicht zu erraten. Wähle ein anderes, zum Beispiel vier zufällige Wörter.');
    if (count(array_unique(mb_str_split($low))) < 5) return $weak;
    // Wiederholung eines kurzen Musters («abcabcabcabc») und auf- oder absteigende Folgen.
    for ($n = 1; $n <= 4; $n++) {
        if (mb_substr(str_repeat(mb_substr($low, 0, $n), (int)ceil($len / $n)), 0, $len) === $low) return $weak;
    }
    foreach (['01234567890123456789', '98765432109876543210', 'abcdefghijklmnopqrstuvwxyz', 'qwertzuiopasdfghjklyxcvbnm', 'qwertyuiopasdfghjklzxcvbnm'] as $seq) {
        if (str_contains($seq, $low)) return $weak;
    }
    static $common = null;
    if ($common === null) $common = array_flip(@file(WB_ROOT . '/lib/data/common_passwords.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
    // Auch ein häufiges Wort mit angehängten oder vorangestellten Ziffern und Zeichen («passwort1234!») zählt.
    $core = trim($low, '0123456789!?.,#*+-_@$%& ');
    if (isset($common[$low]) || (mb_strlen($core) >= 6 && isset($common[$core]))) return $weak;
    foreach ($context as $c) {
        $c = mb_strtolower(trim((string)$c));
        if (mb_strlen($c) >= 4 && str_contains($low, $c) && mb_strlen($low) - mb_strlen($c) < 6) return tx('Das Passwort darf nicht aus deinem Namen oder deiner Adresse bestehen.');
    }
    return null;
}

/**
 * Legt ein Konto an. Pflicht: email, password. Optional: first_name, last_name, locale, verified (bool),
 * age_confirmed (bool), invited_by. Gibt das Konto zurück.
 */
function user_create(array $in): array {
    $email = wb_email_normalize((string)($in['email'] ?? ''));
    if ($email === null) throw new InvalidArgumentException(tx('Diese Adresse ist ungültig.'));
    $now = wb_now();
    $id = db_insert('INSERT INTO users (first_name, last_name, email, password_hash, locale, created_at, email_verified_at, age_confirmed_at, invited_by, password_changed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        wb_clean_text($in['first_name'] ?? '', 80), wb_clean_text($in['last_name'] ?? '', 80), $email,
        password_hash((string)$in['password'], PASSWORD_DEFAULT),
        in_array($in['locale'] ?? '', wb_langs(), true) ? $in['locale'] : 'de', $now,
        !empty($in['verified']) ? $now : null, !empty($in['age_confirmed']) ? $now : null,
        isset($in['invited_by']) ? (int)$in['invited_by'] : null, $now,
    ]);
    return user_reload($id);
}

/** Löscht ein Konto ohne Inhalte endgültig (unbestätigte Konten; das Löschen bestätigter Konten baut U3). */
function user_delete_unverified(int $id): void {
    $u = db_row('SELECT id, email_verified_at FROM users WHERE id = ?', [$id]);
    if (!$u || $u['email_verified_at'] !== null) return;
    foreach (['sessions', 'login_tokens', 'known_devices', 'memberships', 'terms_acceptances'] as $t) db_exec("DELETE FROM $t WHERE user_id = ?", [$id]);
    db_exec('DELETE FROM users WHERE id = ? AND email_verified_at IS NULL', [$id]);
}

function user_set_password(int $id, string $pw): void {
    db_exec('UPDATE users SET password_hash = ?, password_changed_at = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), wb_now(), $id]);
    user_reload($id);
}

/* ---------- Rollen ---------- */

/** Systemrollen eines Kontos, zum Beispiel ['superadmin']. */
function user_system_roles(int $userId): array {
    static $cache = [];
    if (isset($cache[$userId]) && empty($GLOBALS['wb_roles_cache_off'])) return $cache[$userId];
    try {
        $rows = db_all("SELECT role FROM memberships WHERE user_id = ? AND scope_type = 'system'", [$userId]);
    } catch (PDOException $e) {
        return [];
    }
    return $cache[$userId] = array_values(array_intersect(WB_SYSTEM_ROLES, array_column($rows, 'role')));
}

function membership_add(int $userId, string $scopeType, int $scopeId, string $role, ?int $by): void {
    db_exec('INSERT IGNORE INTO memberships (user_id, scope_type, scope_id, role, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)', [$userId, $scopeType, $scopeId, $role, $by, wb_now()]);
    $GLOBALS['wb_roles_cache_off'] = true; user_system_roles($userId); $GLOBALS['wb_roles_cache_off'] = false;
}

function membership_remove(int $userId, string $scopeType, int $scopeId, string $role): int {
    $n = db_exec('DELETE FROM memberships WHERE user_id = ? AND scope_type = ? AND scope_id = ? AND role = ?', [$userId, $scopeType, $scopeId, $role]);
    $GLOBALS['wb_roles_cache_off'] = true; user_system_roles($userId); $GLOBALS['wb_roles_cache_off'] = false;
    return $n;
}

/** Zustand der Lehrperson-Freigabe: no, requested oder approved (Antrag und Entscheid baut U2). */
function user_teacher_state(array $u): string {
    if (!empty($u['teacher_approved_at']) && empty($u['teacher_revoked_at'])) return 'approved';
    return !empty($u['teacher_requested_at']) && empty($u['teacher_revoked_at']) ? 'requested' : 'no';
}

/* ---------- Arbeitsbereiche ---------- */

function org_by_id(int $id): ?array {
    try {
        $o = db_row('SELECT * FROM orgs WHERE id = ? AND deleted_at IS NULL', [$id]);
    } catch (PDOException $e) {
        return null;
    }
    if ($o) $o['id'] = (int)$o['id'];
    return $o ?: null;
}

/**
 * Arbeitsbereiche eines Kontos: immer «Privat» (org_id null), dazu jede Schule, in der es Lehrperson ist.
 * Bis U4 gibt es Schulen nur als Attrappe im Testbestand.
 */
function wb_workspaces(array $user): array {
    $out = [['org_id' => null, 'name' => tx('Privat')]];
    try {
        $rows = db_all("SELECT o.id, o.name FROM memberships m JOIN orgs o ON o.id = m.scope_id AND o.type = 'school' AND o.deleted_at IS NULL AND o.disabled_at IS NULL
            WHERE m.user_id = ? AND m.scope_type = 'org' AND m.role = 'teacher' ORDER BY o.name", [(int)$user['id']]);
    } catch (PDOException $e) {
        $rows = [];
    }
    foreach ($rows as $r) $out[] = ['org_id' => (int)$r['id'], 'name' => (string)$r['name']];
    return $out;
}

/** Gibt $orgId zurück, wenn das Konto dort arbeiten darf, sonst null («Privat»). */
function wb_valid_workspace(array $user, ?int $orgId): ?int {
    if ($orgId === null) return null;
    foreach (wb_workspaces($user) as $w) if ($w['org_id'] === $orgId) return $orgId;
    return null;
}

/** Arbeitsbereich des Aufrufs (null bedeutet «Privat»). */
function wb_workspace_org_id(): ?int {
    return wb_auth()['org_id'] ?? null;
}

function wb_workspace_name(): string {
    $id = wb_workspace_org_id();
    return $id === null ? tx('Privat') : (string)(org_by_id($id)['name'] ?? tx('Privat'));
}

function wb_workspace_switch(?int $orgId): bool {
    $s = wb_session();
    if (!$s || wb_valid_workspace($s['user'], $orgId) !== $orgId) return false;
    db_exec('UPDATE sessions SET workspace_org_id = ? WHERE id = ?', [$orgId, $s['id']]);
    wb_session_forget_cache();
    return true;
}
