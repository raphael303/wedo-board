# Schnittstelle

Basis: `<base_url>/api/`, zurzeit `https://tafula.top/api/`; `base_url` steht in `config.php`. Alle Antworten sind JSON mit `ok: true` oder `ok: false` und `error`. Zeitstempel sind UTC im Format `YYYY-MM-DD HH:MM:SS.ffffff`; in Exporten stehen sie in lokaler Zeit (`Europe/Zurich`).

## Authentifizierung

Besitzer-Aufrufe brauchen den API-Token aus der Bibliothek (Einstellungen) im Header `Authorization: Bearer <token>`. Als Ersatz wird `?token=<token>` in der URL akzeptiert, was nur für Bild-Links gedacht ist. Eine angemeldete Admin-Sitzung (Cookie) gilt ebenfalls als Besitzer. Gäste-Aufrufe brauchen keinen Token; sie identifizieren den Browser über das Cookie `wb_uid`, das der Server beim ersten Aufruf setzt.

Prüfung des Tokens: `GET /api/me` → `{ok, owner: true, version, base_url}`. Besitzer-Aufrufe werden intern über die zentrale Rechteprüfung `wb_can('board.manage')` geprüft; heute entspricht das dem Token oder der Admin-Sitzung.

## Besitzer: Boards

`GET /api/boards?q=&status=&sort=&tag=` listet Boards. `q` sucht in Name, Notiz und Tags; `status` ist `active` (Standard: offen und geschlossen), `open`, `closed`, `archived`, `all` oder `trash` (Papierkorb; alle anderen Werte schliessen gelöschte Boards aus); `sort` ist `activity` (Standard), `created` oder `name`; `tag` filtert auf ein Tag. Antwort: `{boards: [Board]}`.

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

`POST /api/boards/{key}/clear` entfernt alle Beiträge, Kommentare und Bündel weich (Antwort mit `undo_id`). `POST /api/boards/{key}/close` und `.../open` schalten das Schreiben aus und ein. `POST /api/boards/{key}/archive` versteckt das Board vor Gästen. `POST /api/boards/{key}/duplicate` mit optionalem `{name}` kopiert die Struktur ohne Inhalte und gibt das neue Board zurück. `DELETE /api/boards/{key}` (oder `POST .../delete`) legt das Board in den Papierkorb (Antwort `{deleted, trash: true, undo_id}`); es ist für Gäste sofort unerreichbar, sein Kurzwort wird frei, und nach dreissig Tagen wird es mit dem Papierkorb endgültig entfernt. `POST /api/boards/{key}/restore` holt es zurück (bei vergebenem Kurzwort mit neuem Wort). Besitzer-Aufrufe finden Boards im Papierkorb weiterhin über den Schlüssel.

Ein `Board`-Objekt enthält `id, key, slug, name, type, columns, background, lang, settings, status, note, tags, url, qr_svg, qr_png, post_count, comment_count, created_at, updated_at, last_activity_at, deleted_at` (gesetzt, wenn es im Papierkorb liegt). `url` ist der Kurzlink für die Klasse (`<base_url>/<slug>`), `long_url` der lange Link über den Schlüssel.

## Besitzer: Inhalte

`GET /api/boards/{key}/export.json` (gleichbedeutend `.../posts`) liefert `{board, posts}`; jeder Beitrag hat `id, column, title, body, image, link, author, created_at, reactions, comments[]`, und jeder Kommentar `id, body, author, created_at, reactions`. `GET /api/boards/{key}/export.md` liefert dasselbe als Markdown-Text. `POST /api/boards/{key}/posts/{id}/delete` und `POST /api/boards/{key}/comments/{id}/delete` löschen einzelne Elemente weich (Antwort mit `undo_id`).

Bündel: `GET /api/boards/{key}/bundles` listet sie; `POST /api/boards/{key}/bundles` mit `{title, summary, column, post_ids, author}` legt eines an; `PATCH /api/boards/{key}/bundles/{id}` ändert `title, summary, column, position` oder die Zuordnung (`post_ids` ersetzt, `add_post_ids` und `remove_post_ids` ergänzen); `DELETE /api/boards/{key}/bundles/{id}` löst es auf. `POST /api/boards/{key}/organize` mit `{bundles: [{title, summary, column, post_ids}], replace: true, author}` setzt die ganze Einteilung in einem Zug. `POST /api/boards/{key}/assign` mit `{bundle_id, post_ids}` ordnet zu (ohne `bundle_id`: löst). Ändern und Auflösen eines Bündels, `organize` und `assign` antworten zusätzlich mit `undo_id` (Nummer des Protokolleintrags, `null`, wenn nichts protokolliert wurde). Besitzer-Inhalte: `POST /api/boards/{key}/posts` mit `{title, body, link, column, bundle_id, author, on_top}` und `POST /api/boards/{key}/posts/{id}/comments` mit `{body, author}`. `export.json` enthält zusätzlich `bundles` (mit `post_ids`) und pro Beitrag `bundle_id`; `export.md` gliedert nach Spalten und Bündeln und nennt die Nummern als `#id`.

`POST /api/maintenance/purge` entfernt weich gelöschte Inhalte, Boards im Papierkorb und Protokolleinträge, die älter als 30 Tage sind, endgültig.

## Besitzer: Verlauf und Rückgängig

`GET /api/boards/{key}/history?limit=10` liefert `{entries: [{id, action, label, actor, created_at, undone, undone_at, can_undo}]}` mit den letzten Aktionen des Boards (höchstens 50, nur der letzten dreissig Tage). `action` ist `organize`, `assign`, `bundle_update`, `bundle_delete`, `post_delete`, `comment_delete`, `board_clear`, `board_delete` oder `guest_block`; `label` ist die Beschriftung in der Sprache des Boards; `actor` ist `teacher`, `claude` oder `ai:<Modell>`. `POST /api/boards/{key}/undo/{id}` macht einen Eintrag rückgängig und liefert `{entry}`; ist das nicht mehr möglich (bereits rückgängig gemacht, älter als dreissig Tage, Inhalte inzwischen endgültig gelöscht), antwortet die Schnittstelle mit 400 und einer Meldung in der Sprache des Boards, ohne etwas zu ändern. Protokolliert werden alle Bündelungen, Zuordnungen, Löschungen, das Leeren und das Sperren von Gästen, gleich ob sie über Bibliothek, Schnittstelle oder Connector ausgelöst wurden.

## Besitzer: Gäste sperren

`POST /api/boards/{key}/block` mit `{guest_key, scope: "board"|"all", hide: bool, display_name}` sperrt einen Gast (`guest_key` ist sein Browser-Schlüssel `wb_uid`, den die Board-Seite dem Besitzer in `posts[].akey` und `comments[].akey` liefert; `display_name` ist optional und wird sonst aus seinen Beiträgen übernommen). Mit `hide` werden seine Beiträge und Kommentare auf diesem Board weich gelöscht. Antwort: `{block, undo_id}`; `block` enthält `id, board_id, board_key, board_name, guest_key, display_name, hide_content, created_at, lifted_at, active, hidden_posts`. `GET /api/blocks?status=active|all` listet Sperren, `POST /api/blocks/{id}/lift` hebt eine auf und stellt ausgeblendete Inhalte über den Protokolleintrag wieder her (Antwort `{block}`, bei nicht mehr möglicher Wiederherstellung mit `note`).

## QR-Codes (öffentlich)

`GET /api/boards/{key}/qr.svg` und `GET /api/boards/{key}/qr.png?px=12` liefern den QR-Code mit dem Board-Link; `px` ist die Pixelgrösse eines Moduls (4 bis 40). Diese Adressen brauchen keinen Token, weil der Link ohnehin nur den Schlüssel enthält, und eignen sich direkt für `<img>` in Folien oder Arbeitsblättern.

## Gäste

`GET /api/b/{key}` liefert `{board, me, strings, state}`; `me.name` ist der Fantasiename dieses Browsers. `GET /api/b/{key}/state?since=<version>` liefert `{changed: false}` oder die Änderungen seit `version` (`posts`, `comments` mit `deleted`-Flag, `reactions` vollständig). `POST /api/b/{key}/posts` (JSON oder multipart mit `image`) legt einen Beitrag mit `title, body, link, column` an. `POST /api/b/{key}/posts/{id}` ändert einen eigenen Beitrag (zusätzlich `remove_image: 1`), `DELETE /api/b/{key}/posts/{id}` löscht ihn. `POST /api/b/{key}/posts/{id}/comments` mit `{body}` kommentiert; `POST` und `DELETE /api/b/{key}/comments/{id}` ändern und löschen eigene Kommentare. Der angemeldete Besitzer darf zusätzlich über die Gäste-Adresse Bündel verwalten (`POST /api/b/{key}/bundles`, `POST`/`DELETE /api/b/{key}/bundles/{id}`) und bei `POST /api/b/{key}/posts/{id}` das Feld `bundle_id` setzen (0 löst); das benutzt die Board-Seite für Drag-and-drop. Seine protokollierten Aktionen antworten mit `undo_id`; `GET /api/b/{key}/history` und `POST /api/b/{key}/undo/{id}` entsprechen den Besitzer-Routen. Schreibende Besitzer-Aufrufe über die Admin-Sitzung, die nicht aus einem Formular stammen (heute `undo`, später die KI-Bündelung), müssen den CSRF-Token der Sitzung im Header `X-CSRF` mitschicken; die Board-Seite erhält ihn in `WB.csrf`. `POST /api/b/{key}/react` mit `{type: "post"|"comment", id, emoji}` setzt eine Reaktion oder nimmt sie zurück. Gäste sind auf 30 schreibende Aufrufe pro Minute begrenzt; ein Besitzer darf auf geschlossenen Boards und an fremden Beiträgen alles. Ein gesperrter Gast erhält bei jedem schreibenden Aufruf 403 mit `{error: <Text in Boardsprache>, code: "blocked"}`; `me.blocked` im Antwortobjekt von `GET /api/b/{key}` sagt der Seite, die Eingabe auszublenden. `me.uid` ist der eigene Browser-Schlüssel, `me.fresh` zeigt an, dass das Cookie gerade neu erzeugt wurde. `POST /api/b/{key}/identify` mit `{uid}` setzt das Cookie auf einen aus `localStorage` bekannten früheren Schlüssel; der Header `X-Guest-Key` wird bei fehlendem Cookie ebenfalls akzeptiert. Der angemeldete Besitzer sperrt über `POST /api/b/{key}/block` (gleicher Körper wie die Besitzer-Route, mit CSRF-Header).

## Connector

`POST <base_url>/mcp/<token>` ist ein MCP-Server (Streamable HTTP, zustandslos, JSON-Antworten). `delete_board` legt das Board in den Papierkorb; `delete_post`, `organize_board`, `assign_posts`, `update_bundle`, `delete_bundle` und `clear_board` werden protokolliert und sind vom Besitzer im Verlauf des Boards rückgängig zu machen. Der Connector selbst hat keine Rückgängig-Werkzeuge. Er bietet die Werkzeuge `list_boards`, `get_board`, `create_board`, `update_board`, `clear_board`, `duplicate_board`, `delete_board`, `read_board`, `delete_post`, `get_board_qr`, `create_post`, `create_comment`, `organize_board`, `create_bundle`, `update_bundle`, `delete_bundle` und `assign_posts`; die Beschreibungen und Parameter liefert `tools/list`. Die Adresse steht in der Bibliothek unter Einstellungen und wird in den Claude-Einstellungen als eigener Connector ohne Anmeldung eingetragen.

## Beispiel für Claude

Board anlegen und Link und QR-Code für Folien holen:

```
POST /api/boards
Authorization: Bearer <token>
{"name": "Pro und Contra Kernkraft", "type": "columns", "columns": ["Pro", "Contra"], "background": "daemmerung", "note": "Klasse 4c, Physik, Debatte Energie, 20.3.2027", "tags": "physik, 4c, debatte"}
```

Antwort enthält `board.url` (für die Folie) und `board.qr_png` (als Bild einbettbar). Nach der Lektion liefert `GET /api/boards/{key}/export.md` die Schülerbeiträge zum Weiterverarbeiten.
