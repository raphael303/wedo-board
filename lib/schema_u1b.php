<?php
declare(strict_types=1);

/*
 * Tabellen der Nutzerverwaltung, Etappe U1b (Schema 16): OAuth-Clients, Verbindungen, Codes, Tokens und
 * persönliche Tokens. db_schema() hängt sie für Neuinstallationen an, Migration 16 legt sie auf bestehenden
 * Installationen an. Jedes Geheimnis steht nur als SHA-256-Hash in der Datenbank.
 */
function db_schema_u1b(): array {
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        "CREATE TABLE IF NOT EXISTS oauth_clients (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            client_id VARCHAR(64) NOT NULL,
            name VARCHAR(120) NOT NULL DEFAULT '',
            redirect_uris_json TEXT NOT NULL,
            created_ip VARCHAR(45) NOT NULL DEFAULT '',
            created_at DATETIME(6) NOT NULL,
            last_used_at DATETIME(6) NULL,
            expires_at DATETIME(6) NOT NULL,
            disabled_at DATETIME(6) NULL,
            UNIQUE KEY uk_client (client_id),
            KEY ix_expires (expires_at)
        ) $t",
        "CREATE TABLE IF NOT EXISTS oauth_grants (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            org_id INT UNSIGNED NULL,
            resource VARCHAR(255) NOT NULL,
            scope VARCHAR(64) NOT NULL DEFAULT 'content',
            created_at DATETIME(6) NOT NULL,
            last_used_at DATETIME(6) NULL,
            revoked_at DATETIME(6) NULL,
            revoked_reason VARCHAR(32) NULL,
            KEY ix_user (user_id, revoked_at),
            KEY ix_client (client_id)
        ) $t",
        "CREATE TABLE IF NOT EXISTS oauth_codes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            code_hash CHAR(64) NOT NULL,
            client_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            org_id INT UNSIGNED NULL,
            redirect_uri VARCHAR(500) NOT NULL,
            code_challenge VARCHAR(128) NOT NULL,
            resource VARCHAR(255) NOT NULL,
            scope VARCHAR(64) NOT NULL DEFAULT 'content',
            grant_id INT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL,
            expires_at DATETIME(6) NOT NULL,
            used_at DATETIME(6) NULL,
            UNIQUE KEY uk_code (code_hash),
            KEY ix_expires (expires_at)
        ) $t",
        "CREATE TABLE IF NOT EXISTS oauth_tokens (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            grant_id INT UNSIGNED NOT NULL,
            kind ENUM('access','refresh') NOT NULL,
            token_hash CHAR(64) NOT NULL,
            created_at DATETIME(6) NOT NULL,
            expires_at DATETIME(6) NOT NULL,
            used_at DATETIME(6) NULL,
            revoked_at DATETIME(6) NULL,
            UNIQUE KEY uk_token (token_hash),
            KEY ix_grant (grant_id, kind),
            KEY ix_expires (expires_at)
        ) $t",
        "CREATE TABLE IF NOT EXISTS api_tokens (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            org_id INT UNSIGNED NULL,
            purpose VARCHAR(24) NOT NULL,
            label VARCHAR(120) NOT NULL DEFAULT '',
            token_hash CHAR(64) NOT NULL,
            token_prefix VARCHAR(12) NOT NULL DEFAULT '',
            created_at DATETIME(6) NOT NULL,
            last_used_at DATETIME(6) NULL,
            expires_at DATETIME(6) NULL,
            revoked_at DATETIME(6) NULL,
            UNIQUE KEY uk_token (token_hash),
            KEY ix_user (user_id, revoked_at)
        ) $t",
    ];
}

/**
 * Nach Migration 16: Der bisherige api_token aus settings wird als Hash ein Token mit dem Zweck
 * «legacy_connector» des Benutzers 1 (Arbeitsbereich «Privat», mit Ablaufdatum) und verschwindet aus settings.
 * Er gilt danach nur noch an der alten Connector-Adresse /mcp/<token>, an /api/ nicht mehr.
 */
function db_migration_16_finish(): void {
    $old = setting_get('api_token');
    if ($old !== null && $old !== '' && db_row('SELECT id FROM users WHERE id = 1')) {
        $days = max(1, (int)(setting_get('legacy_token_days') ?? '30'));
        db_exec("INSERT IGNORE INTO api_tokens (user_id, org_id, purpose, label, token_hash, token_prefix, created_at, expires_at) VALUES (1, NULL, 'legacy_connector', 'Alter Connector', ?, ?, ?, ?)",
            [hash('sha256', $old), substr($old, 0, 8), wb_now(), gmdate('Y-m-d H:i:s.000000', time() + $days * 86400)]);
    }
    db_exec("DELETE FROM settings WHERE k = 'api_token'");
}
