<?php
declare(strict_types=1);

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $c = $GLOBALS['wb_config']['db'];
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $c['host'], $c['name'], $c['charset'] ?? 'utf8mb4');
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

function db_row(string $sql, array $params = []): ?array {
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

function db_all(string $sql, array $params = []): array {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function db_exec(string $sql, array $params = []): int {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

function db_insert(string $sql, array $params = []): int {
    db_exec($sql, $params);
    return (int)db()->lastInsertId();
}

function db_schema(): array {
    return [
        "CREATE TABLE IF NOT EXISTS settings (
            k VARCHAR(64) NOT NULL PRIMARY KEY,
            v TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

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

        "CREATE TABLE IF NOT EXISTS boards (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            bkey CHAR(16) NOT NULL,
            slug VARCHAR(32) NULL,
            owner_user_id INT UNSIGNED NOT NULL DEFAULT 1,
            name VARCHAR(200) NOT NULL,
            type ENUM('wall','columns') NOT NULL DEFAULT 'wall',
            columns_json TEXT NULL,
            background VARCHAR(40) NOT NULL DEFAULT 'morgen',
            lang CHAR(2) NOT NULL DEFAULT 'de',
            settings_json TEXT NULL,
            status ENUM('open','closed','archived') NOT NULL DEFAULT 'open',
            note TEXT NULL,
            tags VARCHAR(600) NOT NULL DEFAULT '',
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            last_activity_at DATETIME(6) NOT NULL,
            deleted_at DATETIME(6) NULL,
            UNIQUE KEY uk_bkey (bkey),
            KEY ix_slug (slug),
            KEY ix_owner (owner_user_id),
            KEY ix_status (status),
            KEY ix_activity (last_activity_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS posts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            board_id INT UNSIGNED NOT NULL,
            column_id VARCHAR(32) NULL,
            bundle_id INT UNSIGNED NULL,
            title VARCHAR(200) NULL,
            body TEXT NOT NULL,
            image VARCHAR(120) NULL,
            link VARCHAR(1000) NULL,
            author_name VARCHAR(80) NOT NULL,
            author_key CHAR(32) NOT NULL,
            position INT NOT NULL DEFAULT 0,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            edited_at DATETIME(6) NULL,
            deleted_at DATETIME(6) NULL,
            KEY ix_board_updated (board_id, updated_at),
            KEY ix_board_deleted (board_id, deleted_at),
            KEY ix_bundle (bundle_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

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

        "CREATE TABLE IF NOT EXISTS comments (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            board_id INT UNSIGNED NOT NULL,
            post_id INT UNSIGNED NOT NULL,
            body TEXT NOT NULL,
            author_name VARCHAR(80) NOT NULL,
            author_key CHAR(32) NOT NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            edited_at DATETIME(6) NULL,
            deleted_at DATETIME(6) NULL,
            KEY ix_post (post_id),
            KEY ix_board_updated (board_id, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS reactions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            board_id INT UNSIGNED NOT NULL,
            target_type ENUM('post','comment') NOT NULL,
            target_id INT UNSIGNED NOT NULL,
            emoji VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            author_key CHAR(32) NOT NULL,
            created_at DATETIME(6) NOT NULL,
            UNIQUE KEY uk_reaction (target_type, target_id, emoji, author_key),
            KEY ix_board (board_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

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

        "CREATE TABLE IF NOT EXISTS rate_limits (
            author_key CHAR(32) NOT NULL,
            minute INT UNSIGNED NOT NULL,
            cnt INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (author_key, minute)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",


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
        "CREATE TABLE IF NOT EXISTS guest_names (
            board_id INT UNSIGNED NOT NULL,
            guest_key CHAR(32) NOT NULL,
            name VARCHAR(80) NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (board_id, guest_key),
            UNIQUE KEY ux_board_name (board_id, name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
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
    ];
}

function setting_get(string $k, ?string $default = null): ?string {
    $row = db_row('SELECT v FROM settings WHERE k = ?', [$k]);
    return $row ? $row['v'] : $default;
}

function setting_set(string $k, ?string $v): void {
    db_exec('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
}

/** Legt den Super-Admin (Benutzer 1) an, falls er fehlt; Installation und Migration rufen das auf. */
function db_ensure_superadmin(): void {
    $row = db_row('SELECT id FROM users WHERE id = 1');
    if ($row) return;
    db_exec("INSERT INTO users (id, role, display_name, created_at) VALUES (1, 'superadmin', 'Lehrperson', ?)", [wb_now()]);
}

function db_is_installed(): bool {
    try {
        return setting_get('admin_password_hash') !== null;
    } catch (Throwable $e) {
        return false;
    }
}
