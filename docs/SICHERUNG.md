# Sicherung und Wiederherstellung (Stand 0.24.0, eingerichtet in U1c-2 am 20.11.2026)

Das Konzept stammt aus der Etappe U1a der Nutzerverwaltung (Abschnitt 18 des Konzepts); Raphaels Entscheide vom 19. November stehen am Ende. Seit dem 20. November ist es gebaut und eingerichtet, wie unten beschrieben.

## Was zu sichern ist

Tafula hat drei Arten von Zustand. Erstens die Datenbank (Konten, Boards, Aktivitäten, Protokoll, Warteschlange der Mails); am 20. November 2026 war ihr Abzug 330 KB gross. Zweitens die Bilder unter `uploads/`. Drittens `config.php` mit den Geheimnissen: den Zugangsdaten der Datenbank, dem SMTP-Passwort, dem Bibliothekspfad und vor allem `app_secret`. Ohne `app_secret` sind die gespeicherten KI-Schlüssel und die Geheimnisse der App-Stufe unlesbar, und ab U2 hängen die Teilnehmerschlüssel daran; eine Sicherung der Datenbank ohne `config.php` ist deshalb unvollständig. Der Code selbst liegt auf GitHub und braucht keine Sicherung.

## Sicherung vor dem Ausrollen

`tools/cyon-deploy.sh` legt vor jedem Ausrollen und vor `--seed` einen Abzug der Datenbank (`mariadb-dump --single-transaction`) und ein Archiv des Code-Ordners ohne `uploads/` nach `~/backups_pi` auf cyon und löscht eigene Sicherungen, die älter als 30 Tage sind. Das schützt vor einem missglückten Ausrollen, nicht vor einem Verlust des Hostings, weil die Sicherung auf demselben Konto liegt. Diese Abzüge sind nicht verschlüsselt und verlassen cyon nie.

## Nächtliche Sicherung auf cyon

`tools/backup.php` schreibt einen Abzug der Datenbank, eine Datei `meta.json` (Art, Zeitpunkt, Commit, Schema) und bei der Produktion zusätzlich `config.php` und `uploads/` in ein tar.gz-Archiv, verschlüsselt es und legt es nach `~/backups_pi/nightly/prod/` beziehungsweise `~/backups_pi/nightly/test/`. Die Testinstanz wird nur mit der Datenbank gesichert, weil ihr Bestand jederzeit neu geladen werden kann (sie erkennt sich an `'instance' => 'test'`). Das Skript benutzt weder `bootstrap.php` noch die Datenbankverbindung von Tafula und läuft deshalb auch bei veraltetem Schema. Es löscht eigene Archive, die älter als 14 Tage sind, und schreibt jeden Lauf und jeden Fehler nach `~/backups_pi/nightly/backup.log`.

Die crontab auf cyon ruft es um 03:17 für die Produktion und um 03:19 für die Testinstanz auf. Bis zur Umstellung der Produktion in U1c benutzt der Eintrag für die Produktion das Skript der Testinstanz mit `--site=…/tafula.top`, weil der Code der Produktion es noch nicht enthält; `tools/u1c-switch.sh deploy` stellt ihn auf das Skript der Produktion um.

Verschlüsselung: Ein zufälliger Schlüssel für `crypto_secretstream_xchacha20poly1305` verschlüsselt das Archiv in Blöcken von 64 KB; dieser Schlüssel selbst wird mit `sodium_crypto_box_seal` für den öffentlichen Schlüssel `backup_public_key` aus `config.php` versiegelt (Format in der Kopfzeile von `tools/backup.php`). Der private Schlüssel liegt nicht auf cyon, sondern nur auf dem Pi (`/srv/workspaces/wedo-board/.env`, Zeile `TAFULA_BACKUP_SECRET`, Rechte 600, nicht in Git) und als Ausdruck bei Raphael. Wer das Hosting übernimmt, kann die Sicherungen damit nicht lesen, und eine Sicherung, die cyon verlässt, ist immer verschlüsselt. Ein verändertes, abgeschnittenes oder mit einem anderen Schlüssel erstelltes Archiv weist `tools/backup-decrypt.php` ab.

## Kopie auf dem Pi

Das Projekt `/srv/projects/tafula-backup` auf dem Pi (Docker Compose, privates Repository `raphael303/tafula-backup`) holt jede Nacht um 04:40 die neuen Archive mit `rsync` nach `data/archive/` und behält sie neunzig Tage (gemessen am Datum im Dateinamen). Es benutzt einen eigenen SSH-Schlüssel, den cyon mit `restrict` und dem erzwungenen Befehl `~/bin/tafula-backup-serve` auf das Lesen von `~/backups_pi/nightly/` beschränkt; Shell, andere Ordner und Schreiben werden abgewiesen. Der Pi schickt Raphael über `mailrelay` eine Mail, wenn das Abholen scheitert, wenn für eine Art kein Archiv von heute da ist oder wenn ein Archiv weniger als halb so gross ist wie das vorige. Der Pi selbst kann die Archive nur mit dem Schlüssel aus dem Workspace lesen; Bedienung und Wiederaufbau stehen im README des Projekts.

## Wiederherstellung

Entschlüsseln geht nur auf dem Pi: `php tools/backup-decrypt.php <archiv.tfbk> <ziel.tar.gz>` (Schlüssel aus `.env` oder `TAFULA_BACKUP_SECRET`). Das Archiv enthält `db.sql`, `meta.json` und bei der Produktion `config.php` und `uploads/`.

Eine echte Wiederherstellung auf cyon braucht Raphaels Ja und läuft in dieser Reihenfolge, jeder Schritt mit einer Sicherung des bisherigen Zustands nach `~/backups_pi`: Archiv auf dem Pi entschlüsseln und mit `tools/restore-probe.sh` prüfen; auf cyon den Code mit `git checkout` auf den Commit aus `meta.json` bringen; `config.php` zurückspielen (Rechte 600); `db.sql` mit `mariadb` einspielen; `uploads/` entpacken; danach `tools/cyon-deploy.sh --check` und eine Anmeldung. Weil das Schema im Abzug steht, passt der Code danach zur Datenbank; ein neuerer Code migriert beim nächsten Ausrollen. Ein Skript mit Rückfragen gibt es dafür bewusst nicht, weil Claude Befehle auf dem Pi ohne Eingabe ausführt; die Schritte sind kurz genug, um sie einzeln anzusagen.

Probe: Eine Sicherung, die nie zurückgespielt wurde, ist keine. Einmal im Quartal und vor jeder Umstellung der Produktion spielt Claude die neueste Sicherung der Produktion mit `tools/restore-probe.sh up <archiv>` in eine Wegwerf-Kopie auf dem Pi ein (MariaDB 11.4 und das Abbild der Sandbox, internes Netz ohne Ausgang ins Internet, nur über 127.0.0.1:8017 erreichbar, `config.php` aus dem Archiv mit eigener Datenbank und ohne SMTP), prüft Anmeldung, Boards und Bilder und entfernt alles mit `tools/restore-probe.sh down`. Mit einem neueren Stand als zweitem Argument (`origin/main`) laufen dessen Migrationen; das ist zugleich der Probelauf einer Migration. Die erste Probe vom 20. November 2026 mit der Produktion (Schema 14 auf 17) steht im Projektdokument von U1c-2.

## Entscheide von Raphael

19.11.2026: Die Kopie ausser Haus liegt vorerst nur auf dem Pi; einen dritten Ort gibt es nicht. Aufbewahrt wird höchstens neunzig Tage, auch auf dem Pi; Monatsstände darüber hinaus gibt es nicht. Auf cyon bleiben die letzten 14 Tage. Wo der Ausdruck des privaten Schlüssels liegt, steht im Projektdokument von U1c-2, sobald Raphael es entschieden hat (der Schlüssel selbst steht nie in einem Dokument).
