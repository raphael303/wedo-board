# wedo board – Konzept

Stand: 2026-11-05 (Etappen 1 und 2 umgesetzt, Version 0.2.0). Diese Datei ist die verbindliche Wahrheit für das Projekt. Jede Änderung am Datenmodell, an der Schnittstelle oder an den Grundentscheiden wird hier nachgeführt, bevor sie im Code umgesetzt wird.

## 1. Zweck

Ein selbst gehosteter Padlet-Ersatz für den Unterricht unter `https://board.wedo.science`. Schülerinnen und Schüler erhalten einen Link oder QR-Code, schreiben Beiträge, kommentieren und reagieren mit Emojis. Boards werden ausschliesslich vom Besitzer (Raphael) angelegt, verwaltet und gelöscht, entweder in der Bibliothek (Admin-Oberfläche) oder später durch Claude über eine Schnittstelle (Connector). Das System soll in zehn Jahren noch ohne Werkzeugkette laufen und von 40 auf 200 gleichzeitige Nutzende wachsen können.

## 2. Grundentscheide

Die Anwendung ist in PHP 8 mit MySQL geschrieben, ohne Framework und ohne Build-Schritt, weil cyon Webhosting Double genau das bereitstellt und weil so jede Datei direkt per Git oder FTP ausgerollt werden kann. Das Frontend besteht aus eigenem, abhängigkeitsfreiem JavaScript und CSS. Die einzige serverseitige Bibliothek ist ein QR-Code-Generator in reinem PHP, der im Repository liegt. Die Live-Aktualisierung geschieht per Polling (alle drei Sekunden, nur Änderungen seit dem letzten bekannten Stand); ein späterer Wechsel auf Server-Sent Events ist vorgesehen und ändert das Datenmodell nicht. Es gibt keine Benutzerkonten für Schülerinnen. Jeder Browser erhält beim ersten Besuch einen zufälligen Schlüssel in einem Cookie und einen automatisch erzeugten Fantasienamen (zum Beispiel «Mutiger Otter»), der pro Board konstant bleibt. Dieser Schlüssel erlaubt, eigene Beiträge und Kommentare zu bearbeiten und zu löschen. Die Oberfläche ist zweisprachig (Deutsch und Englisch); die Sprache wird pro Board gesetzt und kann im Browser umgeschaltet werden. Alle Texte liegen in einer Sprachdatei, damit weitere Sprachen ergänzt werden können.

## 3. Rollen

Der Besitzer meldet sich mit einem Passwort in der Bibliothek an und hat alle Rechte. Die Schnittstelle (API) wird mit einem geheimen Token authentifiziert, den nur der Besitzer und Claude kennen. Gäste sind alle, die einen Board-Link besitzen; sie können Beiträge, Kommentare und Reaktionen anlegen und ihre eigenen Beiträge und Kommentare bearbeiten oder löschen, solange das Board offen ist.

## 4. Datenmodell

Ein Board hat eine interne Nummer, einen öffentlichen Schlüssel (16 zufällige Zeichen, Teil der langen URL `/b/<schlüssel>`), ein Kurzwort (`slug`, Standard ein aussprechbares Fünf-Buchstaben-Wort wie «tamok» aus Konsonant-Vokal-Wechsel ohne verwechselbare Buchstaben, vom Besitzer frei änderbar auf 3 bis 32 Zeichen aus Kleinbuchstaben, Ziffern und Bindestrich, nicht aus der Liste reservierter Pfade), einen Namen, einen Typ (`wall` für frei verteilt oder `columns` für Spalten), eine Liste von Spalten mit Überschrift und Reihenfolge, einen Hintergrund (Name eines von wenigen vordefinierten Farbverläufen), eine Sprache, Einstellungen (Kommentare erlaubt, Reaktionen erlaubt, Bilder erlaubt, Beiträge werden neu zuoberst oder zuunterst eingefügt), einen Status (`open`, `closed`, `archived`), eine Notiz (freier Text, von Besitzer und Claude lesbar und schreibbar, gedacht für Klasse, Fach, Thema, Datum, Lektionsbezug), Tags (freie Schlagwörter), sowie Zeitstempel für Erstellung, letzte Änderung und letzte Aktivität. Ein geschlossenes Board ist sichtbar, aber nicht mehr beschreibbar; ein archiviertes Board ist für Gäste nicht mehr erreichbar, bleibt aber in der Bibliothek. Der offizielle Link eines Boards ist der Kurzlink `https://board.wedo.science/<kurzwort>`; der lange Link funktioniert weiterhin, und der QR-Code enthält den Kurzlink. Kurzwörter sind nur unter nicht archivierten Boards eindeutig; mit dem Archivieren wird ein Kurzwort wieder frei, und ein aus dem Archiv geholtes Board erhält bei Konflikt ein neues Wort. Fünf Buchstaben sind erratbar; das ist für Klassen-Boards bewusst in Kauf genommen.

Ein Beitrag gehört zu einem Board und optional zu einer Spalte. Er hat einen optionalen Titel, einen Text, optional ein Bild (Dateiname im Upload-Ordner) und optional einen Link, den Fantasienamen und den Browser-Schlüssel des Verfassers, eine Sortierposition, Zeitstempel sowie ein Feld für weiches Löschen. Ein Kommentar gehört zu einem Beitrag und hat Text, Fantasienamen, Browser-Schlüssel, Zeitstempel und ein Feld für weiches Löschen. Eine Reaktion gehört entweder zu einem Beitrag oder zu einem Kommentar und speichert Emoji und Browser-Schlüssel; pro Element, Emoji und Browser gibt es höchstens eine Reaktion, ein zweiter Klick nimmt sie zurück. Die erlaubten Emojis sind eine feste kleine Auswahl (zum Beispiel 👍 ❤️ 😂 🤔 👏 ⭐), die pro Board eingeschränkt werden kann.

Weiches Löschen bedeutet, dass ein Zeitstempel gesetzt wird und das Element nicht mehr angezeigt wird. «Leeren» eines Boards löscht alle Beiträge weich; «endgültig löschen» entfernt Board, Beiträge, Kommentare, Reaktionen und Bilddateien physisch. Die Bibliothek bietet einen Befehl, der weich gelöschte Inhalte älter als dreissig Tage physisch entfernt.

## 5. Bilder

Gäste können Bilder hochladen (JPEG, PNG, WebP, GIF). Der Server prüft den Dateityp anhand des Inhalts, nicht der Endung, rechnet das Bild auf höchstens 1600 Pixel Kantenlänge herunter, speichert es als WebP oder JPEG und erzeugt zusätzlich ein Vorschaubild mit 400 Pixel Breite. Die Obergrenze für den Upload beträgt 10 MB vor der Verkleinerung. Bilder liegen unter `uploads/<boardkey>/` und werden mit dem Board gelöscht.

## 6. Oberfläche für Gäste

Die Board-Seite füllt die Browserfläche. Der Name des Boards steht in einer schmalen Kopfzeile mit Sprachumschalter, einem Knopf für «Neuer Beitrag» und einem Beamer-Modus. Im Typ `wall` werden Beiträge als dichtes, mehrspaltiges Raster angeordnet (Masonry-Prinzip über CSS-Spalten oder eine kleine Layout-Routine), das sich bei jeder Fenstergrösse neu umbricht: breite Bildschirme zeigen viele Spalten, ein Handy eine einzige. Absolute Positionen werden nicht gespeichert; dadurch gibt es keine Überlappungen und nichts wird abgeschnitten. Im Typ `columns` stehen die definierten Spalten nebeneinander mit Überschrift und eigenem «Neuer Beitrag»-Knopf; bei vielen Spalten wird horizontal gescrollt, auf dem Handy werden die Spalten untereinander gestapelt oder per Reiter gewählt. Ein Beitrag zeigt Fantasienamen, Zeit, Titel, Text, Bild oder Link, Reaktionen und die Zahl der Kommentare; Kommentare klappen unter dem Beitrag auf. Lange Texte werden nach etwa zwölf Zeilen eingeklappt mit «mehr anzeigen», nie abgeschnitten. Eigene Beiträge und Kommentare tragen ein Stiftsymbol und ein Papierkorbsymbol. Der Beamer-Modus blendet Kopfzeile und Bedienelemente aus, vergrössert die Schrift und lässt neue Beiträge sanft erscheinen. Neue Beiträge erscheinen ohne Neuladen innerhalb weniger Sekunden bei allen.

Das Design ist minimalistisch und modern: eine Sans-Serif-Schrift mit Systemschriften als Rückfall, viel Weissraum, Karten mit leichter Rundung und weichem Schatten auf einem von fünf bis sechs vordefinierten Farbverläufen (zum Beispiel Morgen, Meer, Wald, Dämmerung, Graphit, Papier). Schriftgrössen und Abstände sind einmalig in CSS-Variablen festgelegt. Die Oberfläche hält Bildschirmbreiten von 360 Pixel bis 4K aus.

## 7. Bibliothek (Admin)

Die Bibliothek liegt unter `/admin/` hinter einem Passwort-Login mit Sitzungscookie. Sie zeigt alle Boards als Liste mit Name, Typ, Status, Zahl der Beiträge, letzter Aktivität, Tags und gekürzter Notiz; eine Suche filtert über Name, Notiz und Tags, Sortierung nach Name, Erstellung oder Aktivität, Filter nach Status. Pro Board stehen bereit: öffnen, Link kopieren, QR-Code anzeigen und als PNG oder SVG herunterladen, Name, Notiz, Tags, Spalten, Hintergrund und Einstellungen bearbeiten, schliessen oder öffnen, leeren, archivieren, duplizieren (Struktur ohne Inhalte), Inhalte als Markdown exportieren oder als Druckansicht öffnen (daraus PDF über den Browser), einzelne Beiträge und Kommentare löschen (auch direkt auf der Board-Seite, wenn als Besitzer angemeldet), endgültig löschen. Ein Formular legt neue Boards an. Eine Einstellungsseite verwaltet das Admin-Passwort und den API-Token.

## 8. Schnittstelle (API)

Alle Funktionen der Bibliothek stehen auch als JSON-Schnittstelle unter `/api/` bereit, authentifiziert mit dem Token im Header `Authorization: Bearer …`. Die Gäste-Funktionen (Beiträge, Kommentare, Reaktionen lesen und schreiben) benutzen dieselbe Schnittstelle ohne Token, mit dem Board-Schlüssel in der URL und dem Browser-Schlüssel im Cookie. Die wichtigsten Besitzer-Aufrufe sind: Boards auflisten und suchen, Board anlegen, Board lesen (Struktur und Notiz), Board ändern, Board leeren, schliessen, archivieren, löschen, Inhalte als strukturierten Text oder JSON auslesen, QR-Code als PNG oder SVG holen. Die Schnittstelle ist in `API.md` im Repository dokumentiert, damit Claude sie in jedem Chat nachlesen kann.

## 9. Connector

`mcp/index.php` ist ein zustandsloser MCP-Server (Streamable HTTP, nur POST mit JSON-Antworten, kein Server-Stream) unter `https://board.wedo.science/mcp/<api-token>`. Weil Claude-Connectoren für eigene Dienste keinen Bearer-Token kennen, steckt der Token in der Adresse; ein falscher Token ergibt 404. Die Adresse wird einmal in den Claude-Einstellungen als eigener Connector ohne Anmeldung eingetragen und steht in der Bibliothek unter Einstellungen. Werkzeuge: `list_boards`, `get_board`, `create_board` (verlangt Notiz und Tags), `update_board` (auch Status und Kurzwort), `clear_board`, `duplicate_board`, `delete_board` (nur mit `confirm=true`), `read_board` (Markdown oder JSON), `delete_post` (Beitrag oder Kommentar), `get_board_qr` (PNG als Bildinhalt plus Adressen). Alle Werkzeuge rufen dieselben Funktionen in `lib/boards.php` wie Bibliothek und Schnittstelle; Boards werden über Kurzwort oder Schlüssel angesprochen.

## 10. Betrieb und Sicherheit

Der Code liegt in einem privaten GitHub-Repository; auf dem Server wird er per SSH mit `git pull` aktualisiert. Zugangsdaten (Datenbank, Admin-Passwort-Hash, API-Token) stehen in `config.php`, die nicht im Repository liegt; eine `config.example.php` zeigt die Struktur. Eine Installationsseite legt die Tabellen an und wird danach gesperrt. Alle Eingaben werden serverseitig geprüft und beim Ausgeben entschärft; Links werden auf `http(s)` beschränkt. Schreibende Aufrufe sind pro Browser-Schlüssel und Minute begrenzt, um Missbrauch zu bremsen. Die Datenbank wird mit den üblichen cyon-Backups gesichert; die Bibliothek bietet zusätzlich einen Export aller Boards als JSON.

## 11. Etappen

Etappe 1 (umgesetzt) umfasst Datenbank, Installationsseite, Gäste-Board (beide Typen, Beiträge, Kommentare, Reaktionen, Bilder, Fantasienamen, Bearbeiten und Löschen eigener Inhalte, Polling, Beamer-Modus, zwei Sprachen), Bibliothek mit allen Besitzerfunktionen, QR-Codes und Export sowie die dokumentierte Schnittstelle. Etappe 2 (umgesetzt) sind Kurzwörter und der Connector. Etappe 3 sind Erweiterungen nach Bedarf, zum Beispiel Server-Sent Events, weitere Hintergründe, Moderation vor Veröffentlichung oder Abstimmungen. Ein allgemeiner Link-Kürzer für andere Inhalte ist ein eigenes, separates Projekt.

Schema-Änderungen laufen über `lib/migrate.php` (nummerierte Migrationen, Stand in `settings.schema_version`); sie werden beim Öffnen der Bibliothek, bei Besitzer-Aufrufen der Schnittstelle und im Connector automatisch ausgeführt, sodass nach `git pull` nichts von Hand zu tun ist.

## 12. Umsetzungsnotizen

Die lokale Entwicklung läuft mit `php -S localhost:8080 dev-router.php`; `dev-router.php` bildet die Regeln aus `.htaccess` nach. Das freie Layout ist mit CSS-Spalten (`column-width`) umgesetzt; neue Beiträge erscheinen oben links, die Zuordnung zu Spalten ergibt sich aus der Fensterbreite. Bilder werden als WebP gespeichert, wenn GD das unterstützt, sonst als JPEG oder PNG. Admin-Passwort-Hash und API-Token liegen in der Tabelle `settings`, nicht in `config.php`; `config.php` enthält nur Datenbankzugang, `base_url`, Standardsprache und Zeitzone.

## 13. Offene Punkte

Die PHP-Version und die verfügbaren Erweiterungen (GD oder Imagick für Bilder, PDO für MySQL) auf dem cyon-Paket sind beim ersten Deployment zu prüfen. Die Domain `board.wedo.science` muss im cyon-Kundencenter als Subdomain mit eigenem Ordner und SSL-Zertifikat angelegt werden.
