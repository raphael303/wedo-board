<?php
// Kopiere diese Datei nach config.php und trage die Werte ein.
// config.php gehört NICHT ins Repository.
return [
    'db' => [
        'host' => 'localhost',
        'name' => 'subconsc_wedoboard',
        'user' => 'subconsc_wedob',
        'pass' => 'HIER-DAS-PASSWORT',
        'charset' => 'utf8mb4',
    ],
    // Öffentliche Adresse ohne Schrägstrich am Ende.
    'base_url' => 'https://nablet.top',
    // Standardsprache für neue Boards: 'de' oder 'en'.
    'default_lang' => 'de',
    // Zeitzone für Zeitangaben.
    'timezone' => 'Europe/Zurich',
];
