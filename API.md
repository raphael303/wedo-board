# wedo board – Schnittstelle

Basis: `https://board.wedo.science/api/`. Alle Antworten sind JSON mit `ok: true` oder `ok: false` und `error`. Zeitstempel sind UTC im Format `YYYY-MM-DD HH:MM:SS.ffffff`; in Exporten stehen sie in lokaler Zeit (`Europe/Zurich`).

## Authentifizierung

Besitzer-Aufrufe brauchen den API-Token aus der Bibliothek (Einstellungen) im Header `Authorization: Bearer <token>`. Als Ersatz wird `?token=<token>` in der URL akzeptiert, was nur für Bild-Links gedacht ist. Eine angemeldete Admin-Sitzung (Cookie) gilt ebenfalls als Besitzer. Gäste-Aufrufe brauchen keinen Token; sie identifizieren den Browser über das Cookie `wb_uid`, das der Server beim ersten Aufruf setzt.

Prüfung des Tokens: `GET /api/me` → `{ok, owner: true, version, base_url}`.

## Besitzer: Boards

`GET /api/boards?q=&status=&sort=&tag=` listet Boards. `q` sucht in Name, Notiz und Tags; `status` ist `active` (Standard: offen und geschlossen), `open`, `closed`, `archived` oder `all`; `sort` ist `activity` (Standard), `created` oder `name`; `tag` filtert auf ein Tag. Antwort: `{boards: [Board]}`.

`POST /api/boards` legt ein Board an. Felder (alle ausser `name` optional):

```json
{
  "name": "Photosynthese Einstieg",
  "type": "wall",
  "columns": ["Was weiss ich?", "Was will ich wissen?"],
  "background": "meer",
  "lang": "de",
  "note": "Klasse 3b, Biologie, Einstieg Photosynthese, 12.3.2027",
  "tags": "bio, 3b, einstieg",
  "settings": {"comments": true, "reactions": true, "images": true, "links": true, "new_on_top": true, "emojis": ["👍", "❤️"]}
}
```

`slug` ist optional ein eigenes Kurzwort für den Link; ohne Angabe entsteht ein aussprechbares Fünf-Buchstaben-Wort. `type` ist `wall` (frei, platzfüllendes Raster) oder `columns` (Spalten). `columns` ist eine Liste von Titeln oder von Objekten `{id, title}`; bei `columns` ohne Angabe entstehen drei Standardspalten. `background` ist einer von `morgen`, `meer`, `wald`, `daemmerung`, `graphit`, `papier`. `lang` ist `de` oder `en`. `tags` darf eine Zeichenkette mit Kommas oder eine Liste sein. Antwort: `{board: Board}` mit Status 201.

Überall, wo `{key}` steht, ist auch das Kurzwort erlaubt. `GET /api/boards/{key}` liest ein Board. `PATCH /api/boards/{key}` (auch `POST`) ändert beliebige der obigen Felder, `slug` (leer = neues automatisches Wort) sowie `status` (`open`, `closed`, `archived`). Beim Ändern von `columns` bleiben Beiträge in ihrer Spalte, wenn die bestehende `id` mitgegeben wird; Titel ohne `id` ergeben neue Spalten, deren Beiträge in die erste Spalte rutschen.

`POST /api/boards/{key}/clear` entfernt alle Beiträge, Kommentare und Reaktionen (weich, 30 Tage wiederherstellbar in der Datenbank). `POST /api/boards/{key}/close` und `.../open` schalten das Schreiben aus und ein. `POST /api/boards/{key}/archive` versteckt das Board vor Gästen. `POST /api/boards/{key}/duplicate` mit optionalem `{name}` kopiert die Struktur ohne Inhalte und gibt das neue Board zurück. `DELETE /api/boards/{key}` (oder `POST .../delete`) löscht endgültig inklusive Bilder.

Ein `Board`-Objekt enthält `id, key, slug, name, type, columns, background, lang, settings, status, note, tags, url, qr_svg, qr_png, post_count, comment_count, created_at, updated_at, last_activity_at`. `url` ist der Kurzlink für die Klasse (`https://board.wedo.science/<slug>`), `long_url` der lange Link über den Schlüssel.

## Besitzer: Inhalte

`GET /api/boards/{key}/export.json` (gleichbedeutend `.../posts`) liefert `{board, posts}`; jeder Beitrag hat `id, column, title, body, image, link, author, created_at, reactions, comments[]`, und jeder Kommentar `id, body, author, created_at, reactions`. `GET /api/boards/{key}/export.md` liefert dasselbe als Markdown-Text. `POST /api/boards/{key}/posts/{id}/delete` und `POST /api/boards/{key}/comments/{id}/delete` löschen einzelne Elemente.

`POST /api/maintenance/purge` entfernt weich gelöschte Inhalte, die älter als 30 Tage sind, endgültig.

## QR-Codes (öffentlich)

`GET /api/boards/{key}/qr.svg` und `GET /api/boards/{key}/qr.png?px=12` liefern den QR-Code mit dem Board-Link; `px` ist die Pixelgrösse eines Moduls (4 bis 40). Diese Adressen brauchen keinen Token, weil der Link ohnehin nur den Schlüssel enthält, und eignen sich direkt für `<img>` in Folien oder Arbeitsblättern.

## Gäste

`GET /api/b/{key}` liefert `{board, me, strings, state}`; `me.name` ist der Fantasiename dieses Browsers. `GET /api/b/{key}/state?since=<version>` liefert `{changed: false}` oder die Änderungen seit `version` (`posts`, `comments` mit `deleted`-Flag, `reactions` vollständig). `POST /api/b/{key}/posts` (JSON oder multipart mit `image`) legt einen Beitrag mit `title, body, link, column` an. `POST /api/b/{key}/posts/{id}` ändert einen eigenen Beitrag (zusätzlich `remove_image: 1`), `DELETE /api/b/{key}/posts/{id}` löscht ihn. `POST /api/b/{key}/posts/{id}/comments` mit `{body}` kommentiert; `POST` und `DELETE /api/b/{key}/comments/{id}` ändern und löschen eigene Kommentare. `POST /api/b/{key}/react` mit `{type: "post"|"comment", id, emoji}` setzt eine Reaktion oder nimmt sie zurück. Gäste sind auf 30 schreibende Aufrufe pro Minute begrenzt; ein Besitzer darf auf geschlossenen Boards und an fremden Beiträgen alles.

## Connector

`POST https://board.wedo.science/mcp/<token>` ist ein MCP-Server (Streamable HTTP, zustandslos, JSON-Antworten). Er bietet die Werkzeuge `list_boards`, `get_board`, `create_board`, `update_board`, `clear_board`, `duplicate_board`, `delete_board`, `read_board`, `delete_post` und `get_board_qr`; die Beschreibungen und Parameter liefert `tools/list`. Die Adresse steht in der Bibliothek unter Einstellungen und wird in den Claude-Einstellungen als eigener Connector ohne Anmeldung eingetragen.

## Beispiel für Claude

Board anlegen und Link und QR-Code für Folien holen:

```
POST /api/boards
Authorization: Bearer <token>
{"name": "Pro und Contra Kernkraft", "type": "columns", "columns": ["Pro", "Contra"], "background": "daemmerung", "note": "Klasse 4c, Physik, Debatte Energie, 20.3.2027", "tags": "physik, 4c, debatte"}
```

Antwort enthält `board.url` (für die Folie) und `board.qr_png` (als Bild einbettbar). Nach der Lektion liefert `GET /api/boards/{key}/export.md` die Schülerbeiträge zum Weiterverarbeiten.
