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
        setting_set('schema_version', (string)$version);
        $applied++;
    }
    return $applied;
}
