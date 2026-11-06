# Nablet – Arbeitsnotizen für die Weiterentwicklung

Diese Datei ist für Claude (und Raphael) gedacht, wenn in einem neuen Chat an Nablet weitergearbeitet wird. Sie beschreibt, was `CONCEPT.md` (Was und Warum) und `API.md` (Schnittstelle) nicht enthalten: den Arbeitsablauf, die Umgebung und die Entscheidungen, die sich bewährt haben. Stand: 2026-11-06, Version 0.3.0.

## Lage der Dinge

Die App heisst für die Klasse «Nablet» und läuft unter `https://nablet.top` auf dem cyon-Hosting «subconsc» (Webhosting Double, PHP 8.3, MariaDB 10.6, LiteSpeed mit `.htaccess`). Der Ordner auf dem Server ist `/home/subconsc/public_html/nablet.top`; die frühere Adresse `board.wedo.science` zeigt auf denselben Ordner und leitet per `.htaccess` auf `nablet.top` um. Das Repository ist `raphael303/wedo-board` auf GitHub (öffentlich, enthält keine Geheimnisse; der interne Name «wedo board» ist historisch). Die Datenbank heisst `subconsc_wedoboard`, Benutzer `subconsc_wedob`; das Passwort steht nur in `config.php` auf dem Server. Admin-Passwort-Hash und API-Token liegen in der Tabelle `settings`. Die Bibliothek ist `https://nablet.top/admin/`, der Connector `https://nablet.top/mcp/<token>`; die Connector-Adresse steht in der Bibliothek unter Einstellungen. In der Claude-App heisst der Connector «Nablet Private Padlet».

## Wie Änderungen zum Server kommen

Claude schreibt Code direkt ins GitHub-Repository (GitHub-Connector in den Claude-Einstellungen, Repository im Chat anhängen). Claude kann den Server nicht selbst erreichen: Weder die Cloud-Umgebung noch die Verbindung über Raphaels Laptop können SSH-Verbindungen zu cyon aufbauen, und auch `curl` auf `nablet.top` ist aus der Cloud-Umgebung nicht möglich; nur lesende Abfragen über WebFetch funktionieren (etwa `https://nablet.top/api/meta` oder `/api/me?token=…`). Deshalb gilt: Nach einem Push sagt Claude Raphael, dass er im Terminal `bash cyon-update.sh` ausführen soll. Dieses Skript liegt in Raphaels Ordner `~/Documents/#UNI/PadletKlon` (im Chat als Ordner freigegeben), verbindet sich per SSH mit seinem hinterlegten Schlüssel und setzt den Server auf `origin/main` (`git fetch` und `git reset --hard`). Dort liegen auch `cyon-erstinstallation.sh` (einmalig benutzt) und `cyon-umzug-nablet.sh` (einmalig benutzt, Umzug vom alten Ordner). Schema-Änderungen brauchen keinen Handgriff: `lib/migrate.php` enthält nummerierte Migrationen, die beim nächsten Öffnen der Bibliothek, beim nächsten Besitzer-Aufruf der Schnittstelle und im Connector automatisch laufen; neue Migrationen bekommen die nächste Nummer, und `WB_SCHEMA_VERSION` in `lib/bootstrap.php` wird nachgezogen. `WB_VERSION` wird bei jeder Lieferung erhöht, weil sie als Cache-Brecher an CSS und JavaScript hängt.

Der cyon-Connector in der Claude-App kann Hosting, Sites, Datenbanken und Zertifikate lesen, Zertifikate beantragen, Document-Roots umstellen und PHP-Versionen setzen, aber keine Dateien hochladen und keine Befehle ausführen. Er ist für Infrastrukturfragen nützlich, nicht fürs Deployment.

## Lokal testen

Claude testet in seiner Cloud-Umgebung mit einer lokalen MariaDB und dem eingebauten PHP-Server; beides muss in jeder Sitzung neu aufgesetzt werden, weil nichts dauerhaft läuft:

```
apt-get install -y mariadb-server
mkdir -p /tmp/mdb && mariadb-install-db --user=mysql --datadir=/tmp/mdb
mariadbd --user=mysql --datadir=/tmp/mdb --socket=/tmp/mdb/sock --pid-file=/tmp/mdb/pid --port=3307 &
mariadb --socket=/tmp/mdb/sock -e "CREATE DATABASE wedoboard CHARACTER SET utf8mb4; CREATE USER 'wedo'@'127.0.0.1' IDENTIFIED BY 'wedo'; GRANT ALL ON wedoboard.* TO 'wedo'@'127.0.0.1';"
```

`config.php` zeigt dann mit `'host' => '127.0.0.1;port=3307'`, Datenbank `wedoboard`, Benutzer und Passwort `wedo` und `base_url` `http://localhost:8080` auf diese Datenbank; `config.php` ist in `.gitignore`. Der Server startet mit `php -S 127.0.0.1:8080 dev-router.php`; `dev-router.php` bildet die `.htaccess`-Regeln nach und muss bei neuen Regeln mitgepflegt werden. Die Installation läuft per `curl -d "password=testpass123&password2=testpass123" http://127.0.0.1:8080/install.php`; der API-Token steht danach in der Antwort. Oberflächen prüft Claude mit Playwright-Screenshots (Chromium ist vorinstalliert) in mindestens 390, 700 und 1280 Pixel Breite; QR-Codes lassen sich mit `cv2.QRCodeDetector` dekodieren; der Connector lässt sich mit `@modelcontextprotocol/sdk` (Client mit `StreamableHTTPClientTransport`) gegen `http://127.0.0.1:8080/mcp/<token>` prüfen. Hintergrundprozesse werden in der Cloud-Umgebung nach einigen Minuten beendet; bei «connection refused» einfach neu starten.

## Aufbau des Codes

`lib/boards.php` enthält die gesamte Fachlogik (Laden, Anlegen, Ändern, Leeren, Löschen, Duplizieren, Zustand für Gäste, Export). Bibliothek (`admin/`), Schnittstelle (`api/index.php`) und Connector (`mcp/index.php`) sind dünne Schichten darüber; eine neue Funktion wird zuerst in `lib/boards.php` gebaut und dann an allen drei Stellen angeboten, soweit sinnvoll. `lib/helpers.php` hat Hilfen (Schlüssel, Kurzwörter, Bereinigung, Hintergründe, Emojis), `lib/auth.php` Admin-Sitzung und Token, `lib/i18n.php` mit `lang/de.php` und `lang/en.php` die Texte der Gästeseite, `lib/names.php` die Fantasienamen, `lib/images.php` die Bildverkleinerung (GD), `lib/qr.php` die QR-Codes über `vendor/qrcode.php` (MIT). Die Gästeseite besteht aus `board.php`, `assets/board.css` und `assets/board.js` ohne Abhängigkeiten; der Zustand wird vollständig in JavaScript gehalten und bei jeder Änderung neu gezeichnet, Entwürfe in Kommentarfeldern und aufgeklappte Bereiche bleiben dabei erhalten. Das freie Layout ist CSS-Spalten (`column-width`), das Spaltenlayout Flexbox mit Reitern unter 640 Pixel. Die Bibliothek ist serverseitig gerendertes PHP mit wenig JavaScript (`assets/admin.js`: Kopieren, Bestätigen, Spaltenfeld ein- und ausblenden).

## Entscheidungen, die bewusst so sind

Keine Benutzerkonten für die Klasse; Identität ist das Cookie `wb_uid`, der Fantasiename ist daraus und aus dem Board-Schlüssel abgeleitet und wird beim Beitrag gespeichert. Löschen ist zuerst weich (`deleted_at`), endgültig nach dreissig Tagen über die Bibliothek. Kurzwörter sind nur unter nicht archivierten Boards eindeutig und bewusst erratbar; der lange Schlüssel bleibt daneben gültig. Live-Aktualisierung ist Polling alle drei Sekunden mit Versionsstempel (`last_activity_at`), Server-Sent Events sind der vorgesehene nächste Schritt, falls nötig. Der Connector ist zustandslos (nur POST, JSON-Antworten, kein Server-Stream) und trägt den Token in der Adresse, weil Claude-Connectoren ohne OAuth keinen Bearer-Token senden. Der QR-Code ist öffentlich abrufbar, weil die Adresse ohnehin nur den Schlüssel enthält. PDF-Export ist die Druckansicht `admin/export.php` über den Browser, kein serverseitiges PDF.

## Offene Ideen

Ein separater Link-Kürzer (eigenes Projekt, eigene Subdomain, gleiche Bauweise, eigener Connector). Umbenennung des Repositories und der internen Bezeichnung «wedo board» in «nablet». Server-Sent Events bei grossen Gruppen. Abstimmungen, Moderation vor Veröffentlichung, weitere Hintergründe, freies Verschieben von Karten (bewusst noch nicht, weil es die Robustheit über Bildschirmgrössen kostet).
