# Nutzerverwaltung: Rechte-Matrix (Entwurf, Stand Fassung 7 des Konzepts)

Status: Entwurf, nichts davon ist gebaut. Raphael hat die Matrix am 16. November 2026 im Ganzen gutgeheissen, aber nicht Zeile für Zeile geprüft; sie wird mit dem Datenmodell von U1a noch einmal vorgelegt. Sie gehört zu `konzept-nutzerverwaltung.md` (Fassung 7) und ist die Vorgabe für `wb_can()` und für die Prüfung in `tools/test.sh`. Gegenüber dem Stand zu Fassung 6 geändert (Entscheid vom 17. November): Den KI-Verbrauch sieht, wer den benutzten Schlüssel verwaltet, nicht wer die Ebene verwaltet; die Zeile «KI-Kosten der Ebene sehen» ist dadurch ersetzt, und jede Person sieht ihren eigenen Verbrauch. Gegenüber dem Stand zu Fassung 5 geändert (Entscheide vom 17. November): Lehrpersonen lesen keine Boards von Lernenden, und Lernende erstellen vorerst keine Boards in Klassen; Boards ohne Besitzerin gibt es nicht; das Personal sieht keine Listen von Personen; Verwalterinnen finden Lehrpersonen nur über die genaue Adresse und ordnen sie nur über eine angenommene Einladung zu; die Basisadresse der KI setzen nur bestätigte Organisationen; eine Schule gründet nur, wer die Lehrperson-Freigabe hat; Rückmeldungen erreicht keine Verbindung und kein Token.

Begriffe: «Arbeitsbereich» ist eine Schule oder «Privat». Ein «Karten-Konto» ist ein verwaltetes Konto ohne echte Adresse. Die «Lehrperson-Freigabe» ist ein Zustand am Konto (Abschnitt 4 des Konzepts).

## 1. Grundregeln

Erstens folgen Inhaltsrechte nie aus einer System- oder Verwalterrolle und nie aus einer Klasse. Den Inhalt eines Boards oder einer Aktivität und die Antworten der Teilnehmenden sieht im Verwaltungsteil nur die Besitzerin und sonst nur, wer eine befristete Freigabe der Besitzerin hat.

Zweitens gelten Verwalterrechte nur innerhalb der eigenen Organisation und darunter: Die Bezirksverwaltung handelt in ihrem Bezirk samt dessen Schulen und Klassen, die Schulverwaltung in ihrer Schule samt deren Klassen.

Drittens gelten im Verwaltungsteil nur die Rechte des gewählten Arbeitsbereichs; Erlaubnisse werden nicht über Arbeitsbereiche hinweg vereinigt, und Einschränkungen (KI-Richtlinie, Zugangseinstellung, Lizenzgrenzen) gelten so, wie der Arbeitsbereich des Boards sie vorgibt. Die Teilnahme als Lernende ist vom Arbeitsbereich unabhängig.

Viertens wird jede Handlung des Personals an einer fremden Organisation oder einem fremden Konto protokolliert.

Fünftens ist jedes Mitglied des Personals und jede Verwalterin daneben eine gewöhnliche Person mit eigenen Arbeitsbereichen; für die eigenen Boards gelten dieselben Regeln wie für alle. Die Rollen selbst besitzen keine Boards, und jedes Board hat immer eine Besitzerin.

Sechstens kommt kein bestehendes Konto ohne Zustimmung der Person in eine Klasse oder eine Schule, und ein Karten-Konto gehört nur Klassen der anlegenden Person oder derselben Schule an.

Siebtens braucht alles, was mit Klassen zu tun hat, im Arbeitsbereich «Privat» eine Lizenz mit dem Merkmal «Klassen» (geprüft ab U5) und die Lehrperson-Freigabe; in einer Schule genügt die Zuordnung als Lehrperson durch die Schulverwaltung.

Achtens erreichen Rückmeldungen und Missbrauchsmeldungen samt den daraus abgeleiteten Feldern keine Verbindung des Connectors und kein persönliches Token; Menschen bearbeiten sie in der Oberfläche, die Instanz auf dem Pi liest sie nur über den Digest-Token.

Neuntens folgt die Sicht auf den KI-Verbrauch aus der Verwaltung des benutzten Schlüssels und nicht aus der Ebene: Wer einen Schlüssel verwaltet, sieht dessen Verbrauch pro Person, Modell und Funktion, aber nie Boards oder Aktivitäten; den Verbrauch eines persönlichen Schlüssels sieht nur seine Inhaberin, auch in einer Schule.

## 2. Matrix

Abkürzungen: SA Super-Admin, AD Admin, LV Lizenzverwaltung, BZ Bezirksverwaltung, SV Schulverwaltung, LP Lehrperson, P Person ohne Freigabe, LE angemeldete Lernende, G Gast ohne Anmeldung. Weil es keine Kontoarten gibt, bezeichnen LP, P und LE Stellungen. «LP» ist, wer in einer Schule die Rolle Lehrperson hat, und im Arbeitsbereich «Privat», wer Lizenz und Lehrperson-Freigabe hat. «P» ist jedes andere selbst verwaltete Konto im Arbeitsbereich «Privat», auch eines ohne Altersbestätigung. «LE» ist, wer als Mitglied einer Klasse oder als angemeldete Person an fremden Boards teilnimmt, und jedes Karten-Konto. Lernende mit selbst verwaltetem Konto handeln bei eigenen Boards als P. Dieselbe Person kann in einem Zusammenhang LP und in einem anderen LE sein. Personal und Verwalterinnen handeln bei eigenen Boards und Klassen als LP oder P; die Spalten SA bis SV zeigen nur, was aus der Rolle selbst folgt. «ja» bedeutet bei BZ und SV immer «in der eigenen Organisation», bei LP und P «im eigenen Arbeitsbereich». «–» bedeutet nein.

### System

| Fähigkeit | SA | AD | LV | BZ | SV | LP | P | LE | G |
|---|---|---|---|---|---|---|---|---|---|
| Systemeinstellungen ändern (Registrierung, Art der Freigabe, Organisationen, Mail, Instanz) | ja | – | – | – | – | – | – | – | – |
| Einladungen von Raphael erstellen (Plan ab U3, Lehrperson-Freigabe) | ja | – | – | – | – | – | – | – | – |
| Admins und Super-Admins ernennen und absetzen | ja | – | – | – | – | – | – | – | – |
| Lizenzverwaltung und Rolle «Vorführung» vergeben und entziehen | ja | ja | – | – | – | – | – | – | – |
| Lizenzpläne, ihre Werte und Merkmale bearbeiten | ja | ja | – | – | – | – | – | – | – |
| Anträge auf die Lehrperson-Freigabe entscheiden, Freigabe entziehen | ja | ja | ja | – | – | – | – | – | – |
| Modellverzeichnis der KI samt Preisen pflegen | ja | ja | – | – | – | – | – | – | – |
| Rückmeldungen in der Bibliothek lesen und bearbeiten, Digest einstellen (nie über Connector oder Token) | ja | ja | – | – | – | – | – | – | – |
| Meldungen lesen und bearbeiten; Board, Aktivität, Konto oder Organisation sperren und entsperren | ja | ja | – | – | – | – | – | – | – |
| Einzelnes Konto über Adresse oder Kennung suchen | ja | ja | ja | – | – | – | – | – | – |
| Einzelnes Konto auf Antrag oder aus zwingendem Grund löschen | ja | ja | – | – | – | – | – | – | – |
| Entfernen der zweiten Stufe eines Kontos auslösen (wirkt nach 72 Stunden) | ja | ja | – | – | – | – | – | – | – |
| Systemweites Protokoll lesen | ja | ja | – | – | – | – | – | – | – |
| Auf der Testinstanz: Zugangscode, Zeitverschiebung, Testbestand neu laden | ja | ja | – | – | – | – | – | – | – |
| Als Vorführung anmelden, Vorführung zurücksetzen | ja | ja | – | – | – | mit Rolle «Vorführung» | mit Rolle «Vorführung» | – | – |
| Board, Aktivität oder Person melden | ja | ja | ja | ja | ja | ja | ja | ja | ja |

### Organisationen und Personen

| Fähigkeit | SA | AD | LV | BZ | SV | LP | P | LE | G |
|---|---|---|---|---|---|---|---|---|---|
| Bezirk anlegen, umbenennen, löschen | ja | ja | ja | umbenennen | – | – | – | – | – |
| Erste Bezirksverwalterin ernennen | ja | ja | ja | – | – | – | – | – | – |
| Weitere Bezirksverwalterinnen ernennen und absetzen | ja | ja | ja | ja | – | – | – | – | – |
| Schule im Bezirk anlegen, umbenennen, löschen | ja | ja | – | ja | umbenennen | – | – | – | – |
| Schule selbst gründen («nicht bestätigt») | – | – | – | – | – | nur mit Lehrperson-Freigabe | – | – | – |
| Schule bestätigen, einem Bezirk zuordnen | ja | ja | ja | – | – | – | – | – | – |
| Kürzel vergeben oder bestätigen | ja | ja | ja | beantragen | – | beantragen mit bezahlter Lizenz | beantragen mit bezahlter Lizenz | – | – |
| Schulverwalterinnen ernennen und absetzen (nur Personen, die der Schule angehören oder eine Einladung angenommen haben) | ja | ja | – | ja | ja | – | – | – | – |
| Lehrpersonen über die genaue Adresse finden und per Einladung zuordnen; Lehrpersonen entfernen | ja | ja | – | ja | ja | – | – | – | – |
| Anfragen zur Zugehörigkeit entscheiden | ja | ja | letzte Stufe | ja | ja | – | – | – | – |
| Lehrpersonen und Verwalterinnen der Ebene sehen (Name, Adresse, Zugehörigkeiten in der Ebene; erst nach angenommener Einladung) | – | – | – | ja | ja | – | – | – | – |
| Anzahl Boards und Aktivitäten pro Person sehen | ja | ja | – | ja | ja | – | – | – | – |
| Protokoll der eigenen Organisation lesen | ja | ja | – | ja | ja | – | – | – | – |
| KI-Richtlinie setzen, Schlüssel der Organisation hinterlegen | ja | ja | – | ja | ja | – | – | – | – |
| Basisadresse der KI setzen | ja | ja | – | bestätigte Organisation | bestätigte Schule | – | – | – | – |
| KI-Verbrauch eines Schlüssels der Organisation sehen (pro Person, Modell und Funktion, nach Monat, mit Tokens und Kosten, ohne Boards und Aktivitäten) | Summe pro Schlüssel und Modell einer gefundenen Organisation, ohne Personen | Summe pro Schlüssel und Modell einer gefundenen Organisation, ohne Personen | – | Schlüssel des Bezirks und seiner Schulen | Schlüssel der Schule | – | – | – | – |
| Einstellen, ob Boards aus der Schule hinaus verschoben werden dürfen | – | – | – | ja | ja | – | – | – | – |
| Lehrperson-Freigabe beantragen | – | – | – | – | – | – | mit bestätigtem Alter und Lizenz mit Merkmal «Klassen» | – | – |
| Zugehörigkeit zu einer Schule beantragen, Einladung annehmen oder ablehnen, verlassen | – | – | – | – | – | ja | ja | – | – |

Die Spalten SA und AD bei «Anzahl Boards», «Protokoll» und «KI-Verbrauch» gelten für eine einzelne, über die Suche gefundene Organisation oder Person, nicht als Liste über alle.

### Lizenzen

| Fähigkeit | SA | AD | LV | BZ | SV | LP | P | LE | G |
|---|---|---|---|---|---|---|---|---|---|
| Lizenz für Bezirk, Schule oder Person erstellen, ändern, widerrufen | ja | ja | ja | – | – | – | – | – | – |
| Teilkontingent an Schulen zuteilen | ja | ja | ja | ja | – | – | – | – | – |
| Plätze an Lehrpersonen vergeben und entziehen | ja | ja | ja | ja | ja | – | – | – | – |
| Lizenzen sehen | alle | alle | alle | eigene Ebene | eigene Ebene | eigene | eigene | – | – |

### Klassen und Lernende

| Fähigkeit | SA | AD | LV | BZ | SV | LP | P | LE | G |
|---|---|---|---|---|---|---|---|---|---|
| Klasse anlegen (in der Schule oder privat) | – | – | – | ja | ja | ja | – | – | – |
| Klasse bearbeiten, archivieren, ins neue Schuljahr übernehmen | – | – | – | ja | ja | Lehrperson der Klasse | – | – | – |
| Klasse ohne Lehrperson neu zuteilen, Lehrpersonen einer Klasse ändern | – | – | – | ja | ja | Lehrperson der Klasse | – | – | – |
| Namen der Klassen der Schule sehen | – | – | – | ja | ja | ja | – | – | – |
| Klassenliste mit Namen der Lernenden sehen | – | – | – | ja | ja | Lehrperson der Klasse | – | – | – |
| Lernende mit Adresse in die Klasse einladen, Einladungen zurückziehen | – | – | – | ja | ja | Lehrperson der Klasse | – | – | – |
| Karten-Konten anlegen und importieren | – | – | – | ja | ja | Lehrperson der Klasse | – | – | – |
| Karten-Konto einer weiteren Klasse zuordnen | – | – | – | ja | ja | nur Klassen derselben Schule oder eigene private Klassen | – | – | – |
| Passwort eines Karten-Kontos neu setzen, Karten drucken | – | – | – | Konten der eigenen Schulen | Konten der eigenen Schule | anlegende Person oder Lehrperson einer Klasse des Kontos | – | – | – |
| Klassencode erzeugen, erneuern, schliessen, Beitritte bestätigen | – | – | – | ja | ja | Lehrperson der Klasse | – | – | – |
| Lernende aus der Klasse entfernen | – | – | – | ja | ja | Lehrperson der Klasse | – | – | – |
| Einladung in eine Klasse annehmen oder ablehnen, per Code beitreten, eine Klasse verlassen | – | – | – | – | – | ja | ja | ja (Karten-Konten: –) | – |

### Boards und Aktivitäten

| Fähigkeit | SA | AD | LV | BZ | SV | LP | P | LE | G |
|---|---|---|---|---|---|---|---|---|---|
| Anlegen (im Rahmen von Lizenz und Arbeitsbereich) | – | – | – | – | – | ja | ja | – | – |
| Eigene verwalten: ändern, schliessen, leeren, löschen, bündeln, auswerten, exportieren, Rückgängig | – | – | – | – | – | eigene | eigene | – | – |
| Bilder hochladen (im Rahmen des Plans) | – | – | – | – | – | ja | ja, ausser ohne Altersbestätigung | nach Einstellung des Boards | nach Einstellung des Boards |
| Zugang, Namensanzeige und Kopierrichtlinie der eigenen einstellen | – | – | – | – | – | eigene (Zugang «nur Klassen»: nur eigene Klassen) | eigene, ohne Zugang «nur Klassen» | – | – |
| Eigene zwischen den eigenen Arbeitsbereichen verschieben | – | – | – | – | – | eigene, wenn die Schule es erlaubt | – | – | – |
| Eigene an eine Kollegin derselben Schule übergeben (die Kollegin nimmt an) | – | – | – | – | – | eigene | – | – | – |
| Fremde mit dem Link kopieren (ohne Beiträge von Teilnehmenden und ohne Lösungen, nur mit Zugang und nach Kopierrichtlinie) | – | – | – | – | – | ja | ja | – | – |
| KI auf eigenen benutzen (im Rahmen von Plan und Richtlinie) | – | – | – | – | – | eigene | eigene | – | – |
| Fremde im Verwaltungsteil lesen | mit Freigabe der Besitzerin | mit Freigabe der Besitzerin | – | – | – | – | – | – | – |
| Gäste und Personen sperren | – | – | – | – | – | eigene Boards | eigene Boards | – | – |
| Echten Namen angemeldeter Teilnehmender sehen | – | – | – | – | – | Besitzerin nach den Regeln aus Abschnitt 9 des Konzepts; andere nach Einstellung der Aktivität | wie LP, aber nie über eine Klasse | nach Einstellung der Aktivität | nach Einstellung der Aktivität |
| Teilnehmen bei Zugang «alle mit dem Link» | – | – | – | – | – | ja | ja | ja | ja |
| Teilnehmen bei Zugang «Link mit Passwort» | – | – | – | – | – | mit Passwort | mit Passwort | mit Passwort | mit Passwort |
| Teilnehmen bei Zugang «nur angemeldete Personen» | – | – | – | – | – | ja | ja | ja | – |
| Teilnehmen bei Zugang «nur bestimmte Klassen» | – | – | – | – | – | Besitzerin und Lehrperson der Klasse | – | Mitglied der Klasse | – |

### Eigenes Konto

| Fähigkeit | SA | AD | LV | BZ | SV | LP | P | LE | G |
|---|---|---|---|---|---|---|---|---|---|
| Profil, Sprache, Passwort, zweite Stufe, Sitzungen und bekannte Geräte verwalten | – | – | – | – | – | ja | ja | ja (Karten-Konten: nur Sprache und Passwort) | – |
| Connector verbinden und Verbindungen widerrufen | – | – | – | – | – | wenn der Plan es erlaubt (geprüft ab U3) | wenn der Plan es erlaubt (geprüft ab U3) | – | – |
| Persönliche Tokens für die Schnittstelle erzeugen und widerrufen | – | – | – | – | – | wenn der Plan es erlaubt | wenn der Plan es erlaubt | – | – |
| Eigenen KI-Schlüssel hinterlegen | – | – | – | – | – | wenn Plan und Richtlinie es erlauben | wenn der Plan es erlaubt | – | – |
| Eigenen KI-Verbrauch sehen, aufgeteilt nach Schlüssel, Modell, Funktion und Board | – | – | – | – | – | ja | ja | – | – |
| Systemnachrichten abonnieren | nach Art | nach Art | nach Art | nach Art | nach Art | nach Art | nach Art | – | – |
| Support-Einsicht in eigene Inhalte befristet freigeben und widerrufen | – | – | – | – | – | ja | ja | – | – |
| Eigene Daten exportieren, Konto löschen | – | – | – | – | – | ja | ja | ja (Karten-Konten: über die Lehrperson) | – |

## 3. Was die Matrix bewusst festlegt

Der Admin unterscheidet sich vom Super-Admin in drei Zeilen: Er ändert keine Systemeinstellungen, erstellt keine Einladungen von Raphael und ernennt keine Admins. Die Lizenzverwaltung legt Bezirke an, bestätigt Schulen, vergibt Kürzel, verwaltet Lizenzen und entscheidet Anträge auf die Lehrperson-Freigabe, greift aber nicht in Schulen, Klassen und KI-Richtlinien ein. Das Personal legt keine Klassen und keine Karten-Konten an, sieht keine Listen von Personen, keine Klassenlisten und keine Klassennamen und setzt keine Passwörter von Lernenden neu; das ist Sache der Schule. Wird das Personal gebeten, ein bestimmtes Konto zu löschen, oder wird es aus einem zwingenden Grund nötig, sucht es dieses eine Konto über Adresse oder Kennung, und Grund und Handlung stehen im Protokoll. Verwalterinnen legen Klassen an und teilen sie zu, finden Lehrpersonen nur über die genaue Adresse (die Suche antwortet für bekannte und unbekannte Adressen gleich) und sehen von einer Person erst etwas, wenn sie die Einladung angenommen hat. Klassen geben niemandem Rechte an Inhalten: Lehrpersonen lesen keine Boards von Lernenden, und Lernende erstellen vorerst keine Boards in Klassen. Boards ohne Besitzerin gibt es nicht; was nach einem Weggang nicht verschoben ist, wird nach dreissig Tagen gelöscht. Verbindungen des Connectors und Tokens tragen nie System- oder Verwalterrechte und erreichen keine Rückmeldungen; sie erreichen nur die Inhalte der Person in dem Arbeitsbereich, für den die Verbindung erteilt wurde. «Als Vorführung anmelden» ist eine eigene Systemrolle.

Den KI-Verbrauch sieht, wer den benutzten Schlüssel verwaltet. Die Schulverwaltung sieht deshalb nicht, was eine Lehrperson in ihrer Schule mit dem eigenen, selbst bezahlten Schlüssel verbraucht, und die Bezirksverwaltung sieht den Verbrauch eines Schulschlüssels, weil ihre Rechte für die Schulen ihres Bezirks gelten. Die Übersicht der Verwaltung nennt Personen, Modelle und Funktionen, aber keine Boards und Aktivitäten, weil schon deren Namen Inhalte verraten. Lernende und Gäste haben keinen eigenen Verbrauch; Aufrufe, die eine Aktivität für sie ausführt, zählen bei der Besitzerin.

Eine Person ohne Freigabe (P) führt eigene Boards und Aktivitäten im Rahmen ihres Plans, aber nichts, was Klassen betrifft. Ein Konto ohne Altersbestätigung ist ein P mit dem Gratisplan, das keine Bilder hochlädt und die Freigabe nicht beantragen kann. Karten-Konten nehmen nur teil.
