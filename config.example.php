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
    'app_name' => 'Tafula',
    // Öffentliche Adresse ohne Schrägstrich am Ende. Alle Kurzlinks, QR-Codes und die
    // Connector-Adresse entstehen daraus; bei einem Domainwechsel nur diese Zeile ändern.
    'base_url' => 'https://tafula.top',
    // Frühere Domains: Anfragen an diese Hosts werden mit 301 auf base_url umgeleitet,
    // damit gedruckte QR-Codes und alte Links weiter funktionieren. Die www-Variante von
    // base_url wird automatisch umgeleitet.
    'legacy_hosts' => [],
    // Pfad der Bibliothek ohne Schrägstriche (3 bis 32 Zeichen: a-z, 0-9, Bindestrich). Ein eigener Pfad
    // versteckt die Bibliothek vor Neugierigen (Bibliothek dann unter base_url/<pfad>/, /admin/ ergibt 404);
    // er ersetzt das Passwort nicht. Fehlt die Zeile, gilt «admin».
    // 'admin_path' => 'mein-pfad',
    // Geheimwert (Base64, 32 Byte) für die verschlüsselte Ablage von KI-Schlüsseln. Fehlt die Zeile, erzeugt
    // die App sie beim ersten Öffnen der KI-Einstellungen selbst und trägt sie hier ein, wenn die Datei
    // beschreibbar ist; sonst zeigt die Bibliothek die einzufügende Zeile an. Niemals ändern, sonst sind
    // gespeicherte Schlüssel unlesbar.
    // 'app_secret' => '…',
    // Standardsprache für neue Boards: 'de' oder 'en'.
    'default_lang' => 'de',
    // Zeitzone für Zeitangaben.
    'timezone' => 'Europe/Zurich',
];
