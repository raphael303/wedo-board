# Tafula – Kapazität, Grenzen und Optimierungen

Stand 2026-11-08, Version 0.9.5, Schema 6. Dieses Dokument hält fest, wie viel Last Tafula auf dem heutigen cyon-Paket trägt, wo die Grenzen liegen und welche Optimierungen sie verschieben. Die Zahlen stammen aus einer lokalen Messung und sind auf cyon übertragen; sie sollten nach grösseren Änderungen am Polling oder an den Bildern neu gemessen werden.

## Rahmen

Tafula läuft auf cyon «Webhosting Double» (Server s068, CloudLinux mit LiteSpeed, PHP 8.3 pro Anfrage, MariaDB 10.6 auf demselben Server). Das Paket bietet 125 % Rechenleistung, also etwa einen und einen Viertel virtuellen Prozessorkern, und 2 GB Arbeitsspeicher; beides gilt für das ganze Hosting, also auch für die anderen Websites darauf. OPcache und APCu sind bei cyon standardmässig eingeschaltet. Es gibt keinen dauerhaft laufenden Prozess, keinen Redis und keine WebSockets. Wie viele PHP-Prozesse gleichzeitig laufen dürfen, nennt cyon öffentlich nicht; der Ressourcenverbrauch lässt sich im my.cyon beobachten.

Als Lastfall gilt eine Lehrperson mit einem Board und 20 Teilnehmenden, dazu ein Beamer. Diese 21 Browser fragen laut `assets/board.js` alle drei Sekunden `GET /api/b/{key}/state?since=` ab (im Hintergrund-Tab alle 15 Sekunden, bei Fehlern mit wachsendem Abstand). Das ergibt etwa 7 Abfragen pro Sekunde und Klasse, auch wenn niemand schreibt. Schreibende Aufrufe der Lernenden sind dagegen selten und fallen kaum ins Gewicht.

## Messung

Gemessen wurde lokal auf einem Intel Xeon mit 2,3 GHz, PHP 8.3 im eingebauten Server und MariaDB, mit einem Spalten-Board, auf dem 20 Gäste 41 Beiträge, 40 Kommentare und 100 Reaktionen geschrieben hatten. Die Rechenzeit wurde getrennt für den PHP-Prozess und für MariaDB aus `/proc/<pid>/stat` über 500 bis 1000 Abfragen mit `ab` bestimmt; die Bilder wurden mit einem Skript gemessen, das `wb_store_image()` direkt aufruft.

| Vorgang | PHP-Rechenzeit | MariaDB-Rechenzeit | Antwort |
|---|---|---|---|
| Polling ohne Änderung, OPcache an | 0,6 ms | 0,55 ms | 66 Byte |
| Polling ohne Änderung, OPcache aus | 4,3 ms | 0,5 ms | 66 Byte |
| Polling nach einem neuen Beitrag | 0,9 ms | 1,2 ms | 3,6 KB |
| Polling mit vollem Zustand | 0,9 ms | 1,0 ms | 37 KB |
| Board-Seite laden (davon QR-Code 6 ms) | 12 ms | 1,4 ms | 61 KB |
| Bild 12 Megapixel, 6,5 MB, auf 1600 px als WebP | 680 bis 750 ms | – | 100 bis 125 MB Speicher |
| Bild 2 Megapixel, 0,35 MB | 260 ms | – | 62 MB Speicher |

Die Werte zeigen dreierlei. Erstens ist eine Nachfrage ohne Änderung billig, solange OPcache läuft; ohne OPcache kostet sie etwa siebenmal mehr. Zweitens kostet eine Nachfrage nach einer Änderung kaum weniger als der volle Zustand, weil `board_state()` die Reaktionen des ganzen Boards bei jeder Änderung neu gruppiert und mitschickt und weil drei weitere Abfragen laufen. Drittens ist die Bildverarbeitung um Grössenordnungen teurer als alles andere: Ein Handyfoto kostet so viel Rechenzeit wie über tausend Nachfragen, und GD belegt dabei Speicher ausserhalb von `memory_limit`, der aber im Speicherlimit des Pakets zählt.

## Übertragung auf cyon

Für cyon wird mit dem Faktor drei gerechnet, weil LiteSpeed mit LSAPI, geteilte Prozessoren, Nachbarn auf demselben Server und die Abrechnung über CloudLinux mehr kosten als die lokale Messung. Ein ruhiges Board braucht dann etwa 7 × 1,8 ms ≈ 12 ms PHP-Rechenzeit pro Sekunde; 1250 ms pro Sekunde reichen damit rechnerisch für rund 100 ruhige Boards. Ein lebhaftes Board, bei dem fast jede Nachfrage eine Änderung findet, braucht etwa 7 × 2,7 ms ≈ 19 ms pro Sekunde und Klasse; das ergibt rund 65 Boards. Bei dieser Last arbeitet zusätzlich MariaDB etwa 25 ms pro Sekunde und Klasse. Diese Rechenzeit fällt nicht im Paket an, sondern auf dem gemeinsamen Datenbankserver, wo cyon sie bei Dauerlast drosseln kann; sie ist darum eine zweite, weniger genau bekannte Grenze. Das Laden der Board-Seite kostet pro Klasse einmalig etwa eine halbe Sekunde Rechenzeit und spielt nur eine Rolle, wenn viele Klassen in derselben Minute beginnen.

Die eigentliche Grenze sind Bild-Uploads. Lädt eine Klasse innerhalb von 30 Sekunden 20 Handyfotos hoch, braucht das mit dem Faktor zwei für die Bildbibliothek etwa 20 bis 30 Sekunden Rechenzeit, also fast das ganze Paket für diese halbe Minute. Gleichzeitig belegt jedes Foto während der Verarbeitung 100 bis 125 MB; ab etwa 15 gleichzeitigen Uploads ist der Arbeitsspeicher von 2 GB erschöpft, und weitere Anfragen scheitern, bis Speicher frei wird. Eine Klasse mit Fotoauftrag ist also gut tragbar, zwei gleichzeitig führen zu spürbaren Wartezeiten, mehr zu Fehlern. KI-Bündelungen kosten wenig Rechenzeit, halten aber einen PHP-Prozess bis zu 60 Sekunden offen.

Zusammengefasst trägt Version 0.9.5 auf diesem Paket etwa 40 bis 60 gleichzeitig laufende Klassen zu je 20 Teilnehmenden, also 800 bis 1200 gleichzeitige Nutzende, solange nicht mehrere Klassen gleichzeitig Fotos hochladen. Mit Fotos liegt die Grenze bei ein bis zwei gleichzeitigen Foto-Phasen. Das Ziel aus dem Konzept, 200 gleichzeitige Nutzende, ist damit beim Polling deutlich erfüllt, bei Bildern knapp. Weil nie alle Lehrpersonen gleichzeitig unterrichten (bei drei Tafula-Lektionen von rund dreissig Wochenlektionen ist im Mittel weniger als ein Zehntel aktiv, in der Spitzenstunde etwas mehr), entspricht das einigen hundert registrierten Lehrpersonen.

## Optimierungen

Die folgenden Schritte sind nach Nutzen im Verhältnis zum Aufwand geordnet. Keiner ändert das Datenmodell; wo die Schnittstelle betroffen ist, steht es dabei, und dafür braucht es vorher Rücksprache.

Am meisten bringt eine Verkleinerung der Bilder im Browser. Vor dem Hochladen wird das Bild mit einem Canvas auf höchstens 1600 Pixel Kantenlänge gebracht und als WebP oder JPEG geschickt; das braucht keine Abhängigkeit und entfernt nebenbei die EXIF-Daten mit dem Aufnahmeort. Der Server prüft und verarbeitet weiterhin jedes Bild, braucht dann aber nur noch etwa ein Drittel der Rechenzeit und die Hälfte des Speichers, und die Uploads werden auf dem Handy viel schneller. Die Grenze von 10 MB und die serverseitige Prüfung bleiben als Rückfall für alte Browser. Zusätzlich sollte der Server höchstens zwei oder drei Bilder gleichzeitig verarbeiten (zum Beispiel mit einer Sperrdatei und `flock`) und andere kurz warten lassen, damit eine Foto-Phase nie den Speicher sprengt.

An zweiter Stelle steht ein schneller Weg für die Nachfrage ohne Änderung. Heute lädt sie alle Bibliotheken, öffnet für jeden Gast eine PHP-Sitzung (`wb_admin`, auch ohne Anmeldung), fragt das Board, die Einstellungen und die Gäste-Sperren ab und vergleicht erst dann den Versionsstempel. Besser ist es, den Versionsstempel bei jeder Änderung zusätzlich in APCu abzulegen (mit der Datenbank als Rückfall, wenn APCu fehlt oder leer ist) und bei `since` gleich Version sofort mit `changed: false` zu antworten, ohne Sitzung und ohne Datenbank. Eine Sitzung sollte allgemein nur gestartet werden, wenn der Browser ein `wb_admin`-Cookie mitschickt. Das senkt die Kosten der häufigsten Abfrage vermutlich auf die Hälfte und entlastet die Datenbank fast vollständig.

Drittens sollten bei einer Änderung nur die Reaktionen der geänderten Beiträge und Kommentare übertragen werden statt aller Reaktionen des Boards. Das verlangt, dass eine Reaktion den Zeitstempel ihres Beitrags oder Kommentars mitzieht, und ändert die dokumentierte Bedeutung von `reactions` in `API.md` («vollständig»); es braucht darum Rücksprache. Es lohnt sich vor allem bei grossen Boards, weil die Kosten heute mit der Zahl der Reaktionen wachsen.

Viertens kann der QR-Code der Board-Seite zwischengespeichert werden, etwa als Datei unter `uploads/<boardkey>/` oder in APCu, weil er sich nur mit dem Kurzwort ändert; das halbiert die Kosten des Seitenaufrufs. Fünftens kann das Polling-Intervall nach einer Minute ohne Änderung von drei auf sechs Sekunden wachsen und bei der nächsten Änderung wieder auf drei fallen; auf geschlossenen Boards reichen 15 Sekunden. Beides verkleinert die Grundlast ruhiger Boards ungefähr auf die Hälfte, ohne dass Lernende einen Unterschied merken.

Server-Sent Events sind auf diesem Paket keine Optimierung. Jede offene Verbindung hielte einen PHP-Prozess dauerhaft fest, und bei mehreren Klassen wäre die Zahl erlaubter Prozesse schnell erreicht. Sie werden erst auf einem eigenen oder verwalteten Server sinnvoll.

Mit Browser-Verkleinerung, begrenzter Bildverarbeitung und schnellem Polling-Weg sollten 80 bis 100 gleichzeitige Klassen und mehrere gleichzeitige Foto-Phasen möglich sein. Darüber hinaus helfen nur mehr Rechenleistung (Webhosting Pro mit bis zu 600 %, ein Managed Server bei cyon oder ein eigener virtueller Server) oder eine zweite Instanz.

## Beobachten und neu messen

Im Betrieb zeigt das my.cyon den Verbrauch von Rechenleistung, Speicher und Prozessen; Fehler 503 oder 508 während einer Lektion deuten auf erreichte Grenzen. Lokal lässt sich die Messung wiederholen, indem man nach `DEVELOPMENT.md` aufsetzt, mit einem kleinen Skript 20 Gäste mit eigenen `wb_uid`-Cookies Beiträge, Kommentare und Reaktionen schreiben lässt und dann mit `ab -n 1000 -c 1 -C "wb_uid=<uid>" "<base>/api/b/<key>/state?since=<version>"` misst, während man die CPU-Zeiten des PHP- und des MariaDB-Prozesses vorher und nachher aus `/proc/<pid>/stat` liest. OPcache wird für den eingebauten Server mit `php -d opcache.enable_cli=1` eingeschaltet.
