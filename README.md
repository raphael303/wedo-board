# wedo board

Ein selbst gehosteter Padlet-Ersatz für den Unterricht: Schülerinnen und Schüler schreiben über einen Link oder QR-Code Beiträge, kommentieren und reagieren mit Emojis; Boards werden nur vom Besitzer angelegt, in der Bibliothek oder über eine Schnittstelle (zum Beispiel durch Claude). Das Konzept steht in `CONCEPT.md`, die Schnittstelle in `API.md`.

## Voraussetzungen

PHP 8.1 oder neuer mit den Erweiterungen PDO MySQL, GD und mbstring, dazu eine MySQL- oder MariaDB-Datenbank und Apache mit `mod_rewrite` (bei cyon vorhanden).

## Installation auf cyon

Lege im Kundencenter die Subdomain `board.wedo.science` mit eigenem Ordner und SSL an sowie eine MySQL-Datenbank mit Benutzer. Hole den Code in diesen Ordner, am einfachsten per SSH:

```
cd ~/public_html/board.wedo.science   # Pfad des Subdomain-Ordners
git clone https://github.com/raphael303/wedo-board.git .
cp config.example.php config.php
```

Trage in `config.php` Datenbankname, Benutzer und Passwort ein und prüfe `base_url`. Rufe danach `https://board.wedo.science/install.php` auf, wähle ein Admin-Passwort und notiere den angezeigten API-Token (er ist später auch in der Bibliothek unter Einstellungen sichtbar). Die Installationsseite sperrt sich danach selbst. Die Bibliothek liegt unter `https://board.wedo.science/admin/`.

Ohne SSH geht es auch mit dem Dateimanager oder FTP: Repository als ZIP von GitHub laden, in den Subdomain-Ordner entpacken, `config.php` anlegen, `install.php` aufrufen.

Für ein privates Repository braucht `git clone` auf dem Server ein Zugangstoken; alternativ kann das Repository öffentlich bleiben, da es keine Geheimnisse enthält (die stehen nur in `config.php` und in der Datenbank).

## Aktualisieren

```
cd ~/public_html/board.wedo.science
git pull
```

Änderungen am Datenbankschema werden in künftigen Versionen über `install.php` oder eine Migrationsseite nachgezogen; das steht dann in den Versionshinweisen.

## Lokale Entwicklung

```
php -S localhost:8080 dev-router.php
```

`dev-router.php` bildet die Regeln aus `.htaccess` für den eingebauten PHP-Server nach. `config.php` zeigt dabei auf eine lokale Datenbank.

## Aufbau

`board.php` ist die Gästeseite, `admin/` die Bibliothek, `api/index.php` die Schnittstelle, `lib/` die gemeinsame Logik, `lang/` die Sprachdateien, `assets/` CSS und JavaScript ohne Abhängigkeiten, `vendor/qrcode.php` der QR-Generator (MIT-Lizenz), `uploads/` die hochgeladenen Bilder (nicht im Repository).
