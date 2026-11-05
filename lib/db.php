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

        "CREATE TABLE IF NOT EXISTS boards (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            bkey CHAR(16) NOT NULL,
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
            UNIQUE KEY uk_bkey (bkey),
            KEY ix_status (status),
            KEY ix_activity (last_activity_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS posts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            board_id INT UNSIGNED NOT NULL,
            column_id VARCHAR(32) NULL,
            title VARCHAR(200) NULL,
            body TEXT NOT NULL,
            image VARCHAR(120) NULL,
            link VARCHAR(1000) NULL,
            author_name VARCHAR(80) NOT NULL,
            author_key CHAR(32) NOT NULL,
            position INT NOT NULL DEFAULT 0,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            deleted_at DATETIME(6) NULL,
            KEY ix_board_updated (board_id, updated_at),
            KEY ix_board_deleted (board_id, deleted_at)
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
            deleted_at DATETIME(6) NULL,
            KEY ix_post (post_id),
            KEY ix_board_updated (board_id, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS reactions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            board_id INT UNSIGNED NOT NULL,
            target_type ENUM('post','comment') NOT NULL,
            target_id INT UNSIGNED NOT NULL,
            emoji VARCHAR(16) NOT NULL,
            author_key CHAR(32) NOT NULL,
            created_at DATETIME(6) NOT NULL,
            UNIQUE KEY uk_reaction (target_type, target_id, emoji, author_key),
            KEY ix_board (board_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS rate_limits (
            author_key CHAR(32) NOT NULL,
            minute INT UNSIGNED NOT NULL,
            cnt INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (author_key, minute)
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

function db_is_installed(): bool {
    try {
        return setting_get('admin_password_hash') !== null;
    } catch (Throwable $e) {
        return false;
    }
}
