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
