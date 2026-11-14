# wedo board

Ein selbst gehosteter Padlet-Ersatz für den Unterricht: Schülerinnen und Schüler schreiben über einen Link oder QR-Code Beiträge, kommentieren und reagieren mit Emojis; Boards werden nur vom Besitzer angelegt, in der Bibliothek oder über eine Schnittstelle (zum Beispiel durch Claude). Daneben gibt es als erste Aktivität die Feedbackzielscheibe: Jede Person setzt pro Aspekt einen Punkt auf eine Scheibe, die Punkte erscheinen erst, wenn die Lehrperson sie anzeigt. Das Konzept steht in `CONCEPT.md`, die Schnittstelle in `API.md`, der Arbeitsablauf für die Weiterentwicklung in `DEVELOPMENT.md`.

## Voraussetzungen

PHP 8.1 oder neuer mit den Erweiterungen PDO MySQL, GD und mbstring, dazu eine MySQL- oder MariaDB-Datenbank und Apache mit `mod_rewrite` (bei cyon vorhanden).

## Installation auf cyon

Lege im Kundencenter die Domain (zurzeit `tafula.top`) mit eigenem Ordner und SSL an sowie eine MySQL-Datenbank mit Benutzer. Hole den Code in diesen Ordner, am einfachsten per SSH:

```
cd ~/public_html/tafula.top
git clone https://github.com/raphael303/wedo-board.git .
cp config.example.php config.php
```

Trage in `config.php` Datenbankname, Benutzer und Passwort ein, setze `app_name` (Anzeigename) und `base_url` (öffentliche Adresse), trage frühere Domains in `legacy_hosts` ein und wähle mit `admin_path` einen eigenen Pfad für die Bibliothek (sonst `/admin/`). Rufe danach `https://tafula.top/install.php` auf, wähle ein Admin-Passwort und notiere den angezeigten API-Token (er ist später auch in der Bibliothek unter Einstellungen sichtbar). Die Installationsseite sperrt sich danach selbst. Die Bibliothek liegt unter `https://tafula.top/admin/`.

Ohne SSH geht es auch mit dem Dateimanager oder FTP: Repository als ZIP von GitHub laden, in den Subdomain-Ordner entpacken, `config.php` anlegen, `install.php` aufrufen.

Für ein privates Repository braucht `git clone` auf dem Server ein Zugangstoken; alternativ kann das Repository öffentlich bleiben, da es keine Geheimnisse enthält (die stehen nur in `config.php` und in der Datenbank).

## Aktualisieren

Ausgerollt wird vom Raspberry Pi aus (Workspace `/srv/workspaces/wedo-board`, SSH-Alias `cyon`):

```
tools/cyon-deploy.sh --check   # nur lesend: Stand auf cyon und GitHub vergleichen
tools/cyon-deploy.sh           # sichern, git pull --ff-only, Migrationen, Startseite prüfen
```

Das Skript ändert weder `config.php` noch `uploads/`. Änderungen am Datenbankschema führt es gleich aus; sonst laufen sie beim nächsten Öffnen der Bibliothek oder beim nächsten Besitzer-Aufruf automatisch (`lib/migrate.php`).

## Connector für Claude

In der Bibliothek unter Einstellungen steht die Connector-Adresse `https://tafula.top/mcp/<token>`. Sie wird in den Claude-Einstellungen unter Connectoren als eigener Connector ohne Anmeldung hinzugefügt; danach kann Claude Boards anlegen, suchen, lesen, leeren, archivieren und QR-Codes liefern.

## Lokale Entwicklung und Tests

```
php -S localhost:8080 dev-router.php
```

`dev-router.php` bildet die Regeln aus `.htaccess` für den eingebauten PHP-Server nach. `config.php` zeigt dabei auf eine lokale Datenbank.

Der Rauchtest `tools/test.sh` (auf dem Pi mit `flock /srv/workspaces/.build.lock tools/test.sh`) startet eine Wegwerf-MariaDB in Docker und den PHP-Server, installiert frisch und prüft Seiten, Schnittstelle, Gäste-Aufrufe, Connector und Migrationen. Einzelheiten stehen in `DEVELOPMENT.md`.

## Aufbau

`board.php` ist die Gästeseite, `admin/` die Bibliothek, `api/index.php` die Schnittstelle, `mcp/index.php` der Connector, `lib/` die gemeinsame Logik, `lang/` die Sprachdateien, `assets/` CSS und JavaScript ohne Abhängigkeiten, `vendor/qrcode.php` der QR-Generator (MIT-Lizenz), `uploads/` die hochgeladenen Bilder (nicht im Repository).

## Domain

Domain und Anzeigename stehen nur in `config.php`. Frühere Domains können in `legacy_hosts` eingetragen werden; `lib/bootstrap.php` leitet sie dann dauerhaft auf `base_url` um, sodass alte Links und QR-Codes weiter funktionieren; `.htaccess` enthält keine Domains. Die Checkliste für einen Domainwechsel steht in `DEVELOPMENT.md`.
