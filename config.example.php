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
    // Anzeigename der App (Seitentitel, Bibliothek, Exporte, Connector).
    'app_name' => 'Nablet',
    // Öffentliche Adresse ohne Schrägstrich am Ende. Alle Kurzlinks, QR-Codes und die
    // Connector-Adresse entstehen daraus; bei einem Domainwechsel nur diese Zeile ändern.
    'base_url' => 'https://nablet.top',
    // Frühere Domains: Anfragen an diese Hosts werden mit 301 auf base_url umgeleitet,
    // damit gedruckte QR-Codes und alte Links weiter funktionieren. Die www-Variante von
    // base_url wird automatisch umgeleitet.
    'legacy_hosts' => ['board.wedo.science'],
    // Standardsprache für neue Boards: 'de' oder 'en'.
    'default_lang' => 'de',
    // Zeitzone für Zeitangaben.
    'timezone' => 'Europe/Zurich',
];
