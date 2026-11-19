# Connector für Claude und persönliche Tokens

Mit dem Connector arbeitet Claude in deinem Namen mit Tafula: Es legt Boards und Aktivitäten an, liest Beiträge und wertet sie aus. Du brauchst dafür kein Token; du trägst in Claude nur eine Adresse ein und meldest dich mit deinem Tafula-Konto an.

## Verbinden

Die Adresse deines Connectors steht unter [Konto](/account) im Abschnitt «Connector für Claude». Trage sie in Claude unter «Connectoren» als eigenen Connector ein. Claude öffnet danach Tafula: Du meldest dich an, bestätigst dein Passwort und siehst die Zustimmungsseite. Sie nennt das Programm, die Rücksprungadresse und den Arbeitsbereich. Erlaube die Verbindung nur, wenn du sie eben selbst begonnen hast und die Rücksprungadresse zu deinem Programm gehört.

## Ein Connector pro Arbeitsbereich

Jeder Arbeitsbereich («Privat» und jede Schule) hat eine eigene Adresse und meldet sich in Claude mit eigenem Namen, zum Beispiel «Tafula – privat». Du kannst mehrere nebeneinander verbinden. Eine Verbindung erreicht nur deine eigenen Boards und Aktivitäten in ihrem Arbeitsbereich. Sie erreicht keine Inhalte anderer Personen, keine Rückmeldungen, keine Kontoeinstellungen und nichts aus der Verwaltung.

## Widerrufen

Unter [Konto](/account) siehst du alle Verbindungen und widerrufst jede einzeln; das wirkt sofort. Wenn du dein Passwort über «Passwort vergessen» zurücksetzt, enden alle Verbindungen und Tokens von selbst. Verlässt du eine Schule, wirkt ihre Verbindung nicht mehr.

## Persönliche Tokens

Für eigene Skripte an der Schnittstelle erzeugst du unter [Konto](/account) ein persönliches Token mit Bezeichnung, Arbeitsbereich und Ablauf. Tafula zeigt es ein einziges Mal an und speichert nur einen Hash. Das Skript schickt es im Header `Authorization: Bearer`. Behandle ein Token wie ein Passwort.
