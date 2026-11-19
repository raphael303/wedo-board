<?php
declare(strict_types=1);

/*
 * Tabellen der Nutzerverwaltung, Etappe U1a (Schema 15). db_schema() hängt sie für Neuinstallationen an,
 * Migration 15 legt sie auf bestehenden Installationen an. Zeitstempel sind DATETIME(6) in UTC.
 */
function db_schema_u1a(): array {
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        "CREATE TABLE IF NOT EXISTS orgs (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            okey CHAR(16) NOT NULL,
            type ENUM('district','school') NOT NULL,
            parent_id INT UNSIGNED NULL,
            name VARCHAR(200) NOT NULL,
            place VARCHAR(120) NOT NULL DEFAULT '',
            short_code VARCHAR(32) NULL,
            verified_at DATETIME(6) NULL,
            hidden TINYINT(1) NOT NULL DEFAULT 0,
            allow_move_out TINYINT(1) NOT NULL DEFAULT 1,
            is_demo TINYINT(1) NOT NULL DEFAULT 0,
            settings_json TEXT NULL,
            ai_policy_json TEXT NULL,
            disabled_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            deleted_at DATETIME(6) NULL,
            UNIQUE KEY uk_okey (okey),
            KEY ix_parent (parent_id)
        ) $t",
        "CREATE TABLE IF NOT EXISTS memberships (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            scope_type ENUM('system','org','class') NOT NULL,
            scope_id INT UNSIGNED NOT NULL DEFAULT 0,
            role VARCHAR(24) NOT NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL,
            UNIQUE KEY uk_member (user_id, scope_type, scope_id, role),
            KEY ix_scope (scope_type, scope_id, role)
        ) $t",
        "CREATE TABLE IF NOT EXISTS invitations (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            kind ENUM('system','org','class') NOT NULL DEFAULT 'system',
            scope_id INT UNSIGNED NOT NULL DEFAULT 0,
            email VARCHAR(200) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            teacher_grant TINYINT(1) NOT NULL DEFAULT 0,
            plan_id INT UNSIGNED NULL,
            created_by INT UNSIGNED NOT NULL,
            created_at DATETIME(6) NOT NULL,
            expires_at DATETIME(6) NOT NULL,
            accepted_at DATETIME(6) NULL,
            accepted_user_id INT UNSIGNED NULL,
            revoked_at DATETIME(6) NULL,
            UNIQUE KEY uk_token (token_hash),
            KEY ix_email (email)
        ) $t",
        "CREATE TABLE IF NOT EXISTS sessions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            token_hash CHAR(64) NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            csrf CHAR(32) NOT NULL,
            kind ENUM('normal','test') NOT NULL DEFAULT 'normal',
            mfa_level ENUM('none','mail','totp') NOT NULL DEFAULT 'none',
            reauth_at DATETIME(6) NULL,
            workspace_org_id INT UNSIGNED NULL,
            flash_json TEXT NULL,
            ip VARCHAR(45) NOT NULL DEFAULT '',
            user_agent VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME(6) NOT NULL,
            last_seen_at DATETIME(6) NOT NULL,
            expires_at DATETIME(6) NOT NULL,
            revoked_at DATETIME(6) NULL,
            UNIQUE KEY uk_token (token_hash),
            KEY ix_user (user_id, revoked_at)
        ) $t",
        "CREATE TABLE IF NOT EXISTS known_devices (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            user_agent VARCHAR(255) NOT NULL DEFAULT '',
            ip VARCHAR(45) NOT NULL DEFAULT '',
            created_at DATETIME(6) NOT NULL,
            last_used_at DATETIME(6) NOT NULL,
            expires_at DATETIME(6) NOT NULL,
            UNIQUE KEY uk_token (token_hash),
            KEY ix_user (user_id)
        ) $t",
        "CREATE TABLE IF NOT EXISTS login_tokens (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            purpose VARCHAR(24) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            payload_json TEXT NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME(6) NOT NULL,
            expires_at DATETIME(6) NOT NULL,
            used_at DATETIME(6) NULL,
            KEY ix_token (token_hash),
            KEY ix_user (user_id, purpose)
        ) $t",
        "CREATE TABLE IF NOT EXISTS terms_acceptances (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            kind VARCHAR(16) NOT NULL,
            version VARCHAR(32) NOT NULL,
            wording TEXT NOT NULL,
            ip VARCHAR(45) NOT NULL DEFAULT '',
            accepted_at DATETIME(6) NOT NULL,
            KEY ix_user (user_id)
        ) $t",
        "CREATE TABLE IF NOT EXISTS audit_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            created_at DATETIME(6) NOT NULL,
            actor_user_id INT UNSIGNED NULL,
            actor_role VARCHAR(24) NOT NULL DEFAULT '',
            action VARCHAR(48) NOT NULL,
            target_type VARCHAR(24) NOT NULL DEFAULT '',
            target_id INT UNSIGNED NULL,
            org_id INT UNSIGNED NULL,
            ip VARCHAR(45) NOT NULL DEFAULT '',
            details_json TEXT NULL,
            KEY ix_created (created_at),
            KEY ix_target (target_type, target_id),
            KEY ix_org (org_id, created_at)
        ) $t",
        "CREATE TABLE IF NOT EXISTS mail_queue (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            to_addr VARCHAR(200) NOT NULL,
            to_user_id INT UNSIGNED NULL,
            kind VARCHAR(32) NOT NULL,
            subject VARCHAR(250) NOT NULL,
            body MEDIUMTEXT NOT NULL,
            status ENUM('queued','sent','failed','sink','suppressed','bounced') NOT NULL DEFAULT 'queued',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            next_attempt_at DATETIME(6) NULL,
            last_error VARCHAR(500) NULL,
            message_id VARCHAR(120) NULL,
            created_at DATETIME(6) NOT NULL,
            sent_at DATETIME(6) NULL,
            KEY ix_status (status, next_attempt_at),
            KEY ix_message (message_id),
            KEY ix_created (created_at)
        ) $t",
        "CREATE TABLE IF NOT EXISTS mail_suppressions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(200) NOT NULL,
            reason VARCHAR(32) NOT NULL,
            detail VARCHAR(500) NULL,
            created_at DATETIME(6) NOT NULL,
            UNIQUE KEY uk_email (email)
        ) $t",
    ];
}

/** Änderungen an bestehenden Tabellen (Migration 15). Neuinstallationen haben sie schon in db_schema(). */
function db_migration_15_alter(): array {
    return [
        "ALTER TABLE users
            ADD COLUMN first_name VARCHAR(80) NOT NULL DEFAULT '' AFTER id,
            ADD COLUMN last_name VARCHAR(80) NOT NULL DEFAULT '' AFTER first_name,
            ADD COLUMN locale CHAR(2) NOT NULL DEFAULT 'de' AFTER password_hash,
            ADD COLUMN email_verified_at DATETIME(6) NULL,
            ADD COLUMN age_confirmed_at DATETIME(6) NULL,
            ADD COLUMN invited_by INT UNSIGNED NULL,
            ADD COLUMN teacher_requested_at DATETIME(6) NULL,
            ADD COLUMN teacher_approved_at DATETIME(6) NULL,
            ADD COLUMN teacher_approved_by INT UNSIGNED NULL,
            ADD COLUMN teacher_revoked_at DATETIME(6) NULL,
            ADD COLUMN totp_secret_enc VARCHAR(255) NULL,
            ADD COLUMN totp_last_step BIGINT UNSIGNED NOT NULL DEFAULT 0,
            ADD COLUMN recovery_codes_json TEXT NULL,
            ADD COLUMN totp_removal_at DATETIME(6) NULL,
            ADD COLUMN password_changed_at DATETIME(6) NULL,
            ADD COLUMN last_login_at DATETIME(6) NULL,
            ADD COLUMN last_active_at DATETIME(6) NULL,
            ADD COLUMN deleted_at DATETIME(6) NULL",
        "ALTER TABLE users DROP KEY ix_role",
        "ALTER TABLE users DROP COLUMN role, DROP COLUMN display_name",
        "ALTER TABLE users ADD UNIQUE KEY uk_email (email)",
        "ALTER TABLE boards ADD COLUMN org_id INT UNSIGNED NULL AFTER owner_user_id, ADD COLUMN identity_seed CHAR(32) NOT NULL DEFAULT '' AFTER org_id, ADD KEY ix_owner_org (owner_user_id, org_id)",
        "ALTER TABLE activities ADD COLUMN org_id INT UNSIGNED NULL AFTER owner_user_id, ADD COLUMN identity_seed CHAR(32) NOT NULL DEFAULT '' AFTER org_id, ADD KEY ix_owner_org (owner_user_id, org_id)",
        "UPDATE boards SET identity_seed = LOWER(HEX(RANDOM_BYTES(16))) WHERE identity_seed = ''",
        "UPDATE activities SET identity_seed = LOWER(HEX(RANDOM_BYTES(16))) WHERE identity_seed = ''",
        "ALTER TABLE boards ALTER COLUMN owner_user_id DROP DEFAULT",
        "ALTER TABLE activities ALTER COLUMN owner_user_id DROP DEFAULT",
        "ALTER TABLE undo_log ALTER COLUMN user_id DROP DEFAULT",
        "ALTER TABLE guest_blocks ALTER COLUMN user_id DROP DEFAULT",
        "ALTER TABLE ai_credentials ALTER COLUMN user_id DROP DEFAULT",
        "ALTER TABLE ai_usage ALTER COLUMN user_id DROP DEFAULT",
        "INSERT IGNORE INTO settings (k, v) VALUES ('teacher_approval', 'manual')",
        // KI-Schalter, Standard-Anweisung und Bündelungs-Vorlieben gehörten der einen Besitzerin; neu pro Konto (u<id>:<name>).
        "UPDATE settings SET k = CONCAT('u1:', k) WHERE k IN ('ai_enabled', 'ai_auto_prompt')",
        "UPDATE settings SET k = 'u1:ai_organize_prefs' WHERE k = 'ai_organize_prefs_u1'",
    ];
}

/**
 * Vor Migration 15: Ohne owner_email in config.php gäbe es danach kein Konto, mit dem sich jemand anmelden kann.
 * Bricht deshalb ab, bevor etwas geändert ist.
 */
function db_migration_15_precheck(): void {
    $email = wb_email_normalize((string)wb_config('owner_email', ''));
    if ($email === null) throw new RuntimeException("Migration 15 abgebrochen: In config.php fehlt 'owner_email' (Adresse des ersten Kontos). Es wurde nichts geändert.");
    if (ai_secret_key() === null) throw new RuntimeException("Migration 15 abgebrochen: In config.php fehlt 'app_secret'. Es wurde nichts geändert.");
}

/** Nach Migration 15: Benutzer 1 wird das Konto des Super-Admins mit dem bisherigen Passwort. */
function db_migration_15_finish(): void {
    $email = wb_email_normalize((string)wb_config('owner_email', ''));
    $hash = setting_get('admin_password_hash');
    $now = wb_now();
    if (!db_row('SELECT id FROM users WHERE id = 1')) {
        db_exec('INSERT INTO users (id, created_at) VALUES (1, ?)', [$now]);
    }
    db_exec('UPDATE users SET email = ?, password_hash = COALESCE(?, password_hash), email_verified_at = COALESCE(email_verified_at, ?), age_confirmed_at = COALESCE(age_confirmed_at, ?),
        first_name = IF(first_name = \'\', ?, first_name), password_changed_at = COALESCE(password_changed_at, ?) WHERE id = 1',
        [$email, $hash, $now, $now, (string)wb_config('owner_name', 'Raphael'), $now]);
    db_exec("INSERT IGNORE INTO memberships (user_id, scope_type, scope_id, role, created_by, created_at) VALUES (1, 'system', 0, 'superadmin', NULL, ?)", [$now]);
    db_exec("DELETE FROM settings WHERE k = 'admin_password_hash'");
}
