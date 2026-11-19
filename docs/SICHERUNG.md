# Sicherung und Wiederherstellung (Konzept, Stand 0.22.0)

Status: Konzept aus der Etappe U1a der Nutzerverwaltung (Abschnitt 18 des Konzepts). Gebaut ist davon nur, was unter «Heute» steht; der Rest wartet auf Raphaels Ja und wird spätestens vor der Umstellung der Produktion (U1c) eingerichtet.

## Was zu sichern ist

Tafula hat drei Arten von Zustand. Erstens die Datenbank (Konten, Boards, Aktivitäten, Protokoll, Warteschlange der Mails); am 16. November 2026 war sie kleiner als 1 MB. Zweitens die Bilder unter `uploads/`. Drittens `config.php` mit den Geheimnissen: den Zugangsdaten der Datenbank, dem SMTP-Passwort, dem Bibliothekspfad und vor allem `app_secret`. Ohne `app_secret` sind die gespeicherten KI-Schlüssel und die Geheimnisse der App-Stufe unlesbar, und ab U2 hängen die Teilnehmerschlüssel daran; eine Sicherung der Datenbank ohne `config.php` ist deshalb unvollständig. Der Code selbst liegt auf GitHub und braucht keine Sicherung.

## Heute

`tools/cyon-deploy.sh` legt vor jedem Ausrollen und vor `--seed` einen Abzug der Datenbank (`mariadb-dump --single-transaction`) und ein Archiv des Code-Ordners ohne `uploads/` nach `~/backups_pi` auf cyon und löscht eigene Sicherungen, die älter als 30 Tage sind. Das schützt vor einem missglückten Ausrollen, nicht vor einem Verlust des Hostings, weil die Sicherung auf demselben Konto liegt. cyon sichert das Hosting täglich, garantiert aber keinen Wiederherstellungspunkt. Bilder werden heute nicht eigens gesichert.

## Vorschlag

Tägliche Sicherung auf cyon: Ein zweiter Eintrag in der crontab (nachts, zum Beispiel 03:17) ruft `tools/backup.php` auf. Das Skript schreibt einen Abzug der Datenbank, ein Archiv von `uploads/` und eine Kopie von `config.php` in ein Archiv, verschlüsselt es und legt es nach `~/backups_pi/nightly/`. Aufbewahrt werden die letzten 14 Tage und die letzten 8 Sonntage. Die Produktion und die Testinstanz werden getrennt gesichert; die Testinstanz nur die Datenbank, weil ihr Bestand jederzeit neu geladen werden kann.

Verschlüsselung: Das Archiv wird auf cyon mit einem öffentlichen Schlüssel verschlüsselt (`sodium_crypto_box_seal` in PHP, ohne zusätzliche Programme). Der private Schlüssel liegt nicht auf cyon, sondern nur auf dem Pi (`/srv/workspaces/wedo-board/.env`, Rechte 600) und als Ausdruck bei Raphael. Wer das Hosting übernimmt, kann die Sicherungen damit nicht lesen, und eine Sicherung, die cyon verlässt, ist immer verschlüsselt.

Kopie ausser Haus: Ein geplanter Job auf dem Pi (Projekt nach `/srv/projects/README.md`, weil er ohne Web-Präsenz läuft) holt jede Nacht nach der Sicherung die neuesten Archive mit `rsync` über den SSH-Alias `cyon` nach `/srv/projects/tafula-backup/data/` und behält 30 Tage und 12 Monatsstände. Damit liegen Sicherungen an zwei Orten bei zwei Betreibern. Der Pi meldet Raphael per Mail, wenn eine Nacht kein neues Archiv kommt oder ein Archiv auffällig kleiner ist als das vorige.

Aufbewahrungsfrist: Abzüge enthalten IP-Adressen (Sitzungen, bekannte Geräte, Protokoll) und Namen. Die Fristen oben bleiben unter dem Richtwert von neunzig Tagen aus Abschnitt 13 des Konzepts, ausser den Monatsständen auf dem Pi; ob es diese braucht, entscheidet Raphael zusammen mit der Datenschutzerklärung.

## Wiederherstellung

Die Wiederherstellung läuft in dieser Reihenfolge und wird als `tools/restore.sh` gebaut, das jeden Schritt ansagt und vor dem Überschreiben fragt. Zuerst das Archiv auf dem Pi entschlüsseln und prüfen. Dann auf dem Ziel den Code auf den Stand bringen, zu dem die Sicherung gehört (der Commit steht im Archiv). Dann `config.php` zurückspielen, danach die Datenbank einspielen und `uploads/` entpacken. Zum Schluss `tools/cyon-deploy.sh --check` und eine Anmeldung. Weil das Schema im Abzug steht, passt der Code danach zur Datenbank; ein neuerer Code migriert beim nächsten Ausrollen.

Probe: Eine Sicherung, die nie zurückgespielt wurde, ist keine. Einmal im Quartal und vor jeder Umstellung der Produktion spielt Claude die neueste Sicherung der Produktion in die Sandbox auf dem Pi ein (ohne Ausgang ins Netz), prüft Anmeldung, ein Board und ein Bild und löscht die Kopie wieder. Derselbe Ablauf dient dem Probelauf der Migration in U1c.

## Entscheide von Raphael (19.11.2026)

Die Kopie ausser Haus liegt vorerst nur auf dem Pi; einen dritten Ort gibt es nicht. Aufbewahrt wird höchstens neunzig Tage, auch auf dem Pi; Monatsstände darüber hinaus gibt es nicht (statt «30 Tage und 12 Monatsstände» also die Stände der letzten neunzig Tage). Offen ist noch, wo der Ausdruck des privaten Schlüssels liegt; das entscheidet Raphael, bevor die Sicherung vor U1c eingerichtet wird.
