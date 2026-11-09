<?php
declare(strict_types=1);

/**
 * Schema-Änderungen nach der Erstinstallation. Jede Migration läuft genau einmal;
 * der Stand steht in settings.schema_version.
 */
function db_migrations(): array {
    return [
        1 => [
            "ALTER TABLE boards ADD COLUMN slug VARCHAR(32) NULL AFTER bkey",
            "ALTER TABLE boards ADD KEY ix_slug (slug)",
        ],
        2 => [
            "ALTER TABLE posts ADD COLUMN bundle_id INT UNSIGNED NULL AFTER column_id",
            "ALTER TABLE posts ADD KEY ix_bundle (bundle_id)",
            "ALTER TABLE posts ADD COLUMN edited_at DATETIME(6) NULL AFTER updated_at",
            "ALTER TABLE comments ADD COLUMN edited_at DATETIME(6) NULL AFTER updated_at",
            "CREATE TABLE IF NOT EXISTS bundles (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                board_id INT UNSIGNED NOT NULL,
                column_id VARCHAR(32) NULL,
                title VARCHAR(200) NOT NULL,
                summary TEXT NULL,
                author_name VARCHAR(80) NOT NULL DEFAULT '',
                position INT NOT NULL DEFAULT 0,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                deleted_at DATETIME(6) NULL,
                KEY ix_board_updated (board_id, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],
        // Etappe 5a: Benutzer und Rollen (vorbereitend), Boards gehören einem Benutzer.
        3 => [
            "CREATE TABLE IF NOT EXISTS users (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                role ENUM('superadmin','admin','teacher','student','guest') NOT NULL DEFAULT 'guest',
                display_name VARCHAR(80) NOT NULL DEFAULT '',
                email VARCHAR(200) NULL,
                password_hash VARCHAR(255) NULL,
                created_at DATETIME(6) NOT NULL,
                disabled_at DATETIME(6) NULL,
                KEY ix_role (role)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "ALTER TABLE boards ADD COLUMN owner_user_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER slug",
            "ALTER TABLE boards ADD KEY ix_owner (owner_user_id)",
        ],
        // Etappe 5b: Änderungsprotokoll mit Rückgängig, Papierkorb für Boards.
        4 => [
            "ALTER TABLE boards ADD COLUMN deleted_at DATETIME(6) NULL AFTER last_activity_at",
            "CREATE TABLE IF NOT EXISTS undo_log (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL DEFAULT 1,
                board_id INT UNSIGNED NOT NULL,
                action VARCHAR(40) NOT NULL,
                label VARCHAR(200) NOT NULL DEFAULT '',
                payload MEDIUMTEXT NULL,
                created_at DATETIME(6) NOT NULL,
                undone_at DATETIME(6) NULL,
                undone_by INT UNSIGNED NULL,
                KEY ix_board_created (board_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],
        // Etappe 5c: Gäste sperren.
        5 => [
            "CREATE TABLE IF NOT EXISTS guest_blocks (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL DEFAULT 1,
                board_id INT UNSIGNED NULL,
                guest_key CHAR(32) NOT NULL,
                display_name VARCHAR(80) NOT NULL DEFAULT '',
                hide_content TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME(6) NOT NULL,
                lifted_at DATETIME(6) NULL,
                KEY ix_guest (guest_key, lifted_at),
                KEY ix_board (board_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],
        // Etappe 5d: KI-Zugänge und Protokoll der KI-Aufrufe.
        6 => [
            "CREATE TABLE IF NOT EXISTS ai_credentials (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL DEFAULT 1,
                provider VARCHAR(32) NOT NULL,
                label VARCHAR(80) NOT NULL DEFAULT '',
                api_key_enc TEXT NOT NULL,
                model VARCHAR(120) NOT NULL DEFAULT '',
                is_default TINYINT(1) NOT NULL DEFAULT 1,
                last_test_at DATETIME(6) NULL,
                last_test_ok TINYINT(1) NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                KEY ix_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS ai_usage (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL DEFAULT 1,
                board_id INT UNSIGNED NULL,
                feature VARCHAR(40) NOT NULL,
                provider VARCHAR(32) NOT NULL,
                model VARCHAR(120) NOT NULL DEFAULT '',
                input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
                output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
                duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
                ok TINYINT(1) NOT NULL DEFAULT 1,
                error VARCHAR(500) NULL,
                created_at DATETIME(6) NOT NULL,
                KEY ix_user_created (user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],
        // Version 0.10: gespeicherte KI-Zusammenfassungen für den inhaltlichen Export.
        7 => [
            "CREATE TABLE IF NOT EXISTS board_summaries (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                board_id INT UNSIGNED NOT NULL,
                mode VARCHAR(16) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'idle',
                content MEDIUMTEXT NULL,
                fingerprint CHAR(40) NULL,
                model VARCHAR(120) NOT NULL DEFAULT '',
                post_count INT UNSIGNED NOT NULL DEFAULT 0,
                comment_count INT UNSIGNED NOT NULL DEFAULT 0,
                generated_by VARCHAR(16) NOT NULL DEFAULT '',
                lock_token CHAR(32) NULL,
                started_at DATETIME(6) NULL,
                finished_at DATETIME(6) NULL,
                failed_at DATETIME(6) NULL,
                error VARCHAR(500) NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                UNIQUE KEY ux_board_mode (board_id, mode)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],
        // Version 0.10.1: Emojis byte-genau vergleichen. Unter utf8mb4_unicode_ci gelten viele Emojis als gleich
        // (zum Beispiel 👍 und 😂 oder 🔴 und 🟢), wodurch Reaktionen sich gegenseitig aufhoben und zusammengezählt wurden.
        8 => [
            "ALTER TABLE reactions MODIFY emoji VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL",
        ],
        // Version 0.11: Aktivitäten neben den Boards (zuerst die Feedbackzielscheibe) mit Antworten pro Runde.
        9 => [
            "CREATE TABLE IF NOT EXISTS activities (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                akey CHAR(16) NOT NULL,
                slug VARCHAR(32) NULL,
                owner_user_id INT UNSIGNED NOT NULL DEFAULT 1,
                type VARCHAR(24) NOT NULL DEFAULT 'target',
                name VARCHAR(200) NOT NULL,
                config_json TEXT NULL,
                background VARCHAR(40) NOT NULL DEFAULT 'meer',
                lang CHAR(2) NOT NULL DEFAULT 'de',
                status ENUM('open','closed','archived') NOT NULL DEFAULT 'open',
                revealed TINYINT(1) NOT NULL DEFAULT 0,
                round INT UNSIGNED NOT NULL DEFAULT 1,
                note TEXT NULL,
                tags VARCHAR(600) NOT NULL DEFAULT '',
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                last_activity_at DATETIME(6) NOT NULL,
                deleted_at DATETIME(6) NULL,
                UNIQUE KEY uk_akey (akey),
                KEY ix_slug (slug),
                KEY ix_owner (owner_user_id),
                KEY ix_activity (last_activity_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS activity_responses (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                activity_id INT UNSIGNED NOT NULL,
                round INT UNSIGNED NOT NULL DEFAULT 1,
                guest_key CHAR(32) NOT NULL,
                data_json TEXT NOT NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                UNIQUE KEY ux_activity_round_guest (activity_id, round, guest_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],
        // Wortwolke: Zusammenlegungen pro Runde als Verlauf. Jede Änderung (KI, Claude, Lehrperson) schreibt die ganze
        // Einteilung als neue Zeile; gültig ist die neueste nicht rückgängig gemachte. Die Rohbegriffe bleiben unverändert.
        10 => [
            "CREATE TABLE IF NOT EXISTS activity_groupings (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                activity_id INT UNSIGNED NOT NULL,
                round INT UNSIGNED NOT NULL DEFAULT 1,
                groups_json MEDIUMTEXT NOT NULL,
                actor VARCHAR(80) NOT NULL DEFAULT '',
                label VARCHAR(200) NOT NULL DEFAULT '',
                created_at DATETIME(6) NOT NULL,
                undone_at DATETIME(6) NULL,
                KEY ix_activity_round (activity_id, round, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],
        // Rückmeldungen (Fehler, Wünsche) von allen Seiten; ein geplanter Claude-Lauf analysiert sie und schlägt Lösungen vor.
        11 => [
            "CREATE TABLE IF NOT EXISTS feedback (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                kind VARCHAR(16) NOT NULL DEFAULT 'bug',
                text TEXT NOT NULL,
                contact_email VARCHAR(200) NULL,
                context TEXT NULL,
                board_id INT UNSIGNED NULL,
                activity_id INT UNSIGNED NULL,
                browser_key CHAR(32) NOT NULL DEFAULT '',
                status VARCHAR(16) NOT NULL DEFAULT 'new',
                analysis MEDIUMTEXT NULL,
                proposal MEDIUMTEXT NULL,
                needs_consult TINYINT(1) NOT NULL DEFAULT 0,
                duplicate_of INT UNSIGNED NULL,
                digest_message_id VARCHAR(300) NULL,
                approved_at DATETIME(6) NULL,
                approved_via VARCHAR(16) NULL,
                branch VARCHAR(120) NULL,
                `commit` VARCHAR(64) NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL,
                deleted_at DATETIME(6) NULL,
                KEY ix_status (status, id),
                KEY ix_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],
    ];
}

function db_migrate(): int {
    $current = (int)(setting_get('schema_version') ?? 0);
    $applied = 0;
    foreach (db_migrations() as $version => $statements) {
        if ($version <= $current) continue;
        foreach ($statements as $sql) {
            try {
                db()->exec($sql);
            } catch (PDOException $e) {
                // Spalte oder Index existiert bereits (zum Beispiel nach Neuinstallation mit aktuellem Schema).
                if (!in_array($e->getCode(), ['42S21', '42000'], true) || !preg_match('/Duplicate|already exists/i', $e->getMessage())) {
                    throw $e;
                }
            }
        }
        if ($version === 1) {
            // Bestehende Boards erhalten ein Kurzwort.
            foreach (db_all('SELECT id FROM boards WHERE slug IS NULL') as $row) {
                db_exec('UPDATE boards SET slug = ? WHERE id = ?', [board_free_slug(), (int)$row['id']]);
            }
        }
        if ($version === 3) {
            db_ensure_superadmin();
        }
        setting_set('schema_version', (string)$version);
        $applied++;
    }
    return $applied;
}
