#!/bin/bash
# Rauchtest für Tafula auf dem Raspberry Pi (Workspace /srv/workspaces/wedo-board).
# Startet eine Wegwerf-MariaDB 10.6 in Docker (127.0.0.1:3307) und den eingebauten PHP-Server
# (127.0.0.1:8090), installiert frisch und prüft Startseite, Schnittstelle, Gäste-Aufrufe,
# Connector und Migrationen auf der Kommandozeile. Danach wird alles wieder entfernt.
#
#   flock /srv/workspaces/.build.lock tools/test.sh
#
# Braucht PHP mit pdo_mysql, gd, mbstring und sodium sowie Docker. Eine vorhandene config.php
# wird für die Dauer des Tests beiseitegelegt und danach zurückgestellt.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT=$(pwd)
DB=tafula-test-db
PORT=8090
BASE="http://127.0.0.1:$PORT"
TMP=$(mktemp -d)
FAIL=0
ok()   { echo "  ok   $*"; }
bad()  { echo "  FEHLER $*"; FAIL=1; }

cleanup() {
  [ -n "${SRV:-}" ] && kill "$SRV" 2>/dev/null || true
  docker rm -f "$DB" >/dev/null 2>&1 || true
  rm -f "$ROOT/config.php"
  [ -f "$TMP/config.php.orig" ] && mv "$TMP/config.php.orig" "$ROOT/config.php"
  rm -rf "$TMP"
}
trap cleanup EXIT

echo '1. Syntax'
n=0
while IFS= read -r f; do
  php -l "$f" >/dev/null 2>"$TMP/lint" || { bad "$f: $(cat "$TMP/lint")"; }
  n=$((n+1))
done < <(git ls-files '*.php')
ok "$n PHP-Dateien geprüft"
for s in tools/*.sh; do bash -n "$s" || bad "$s"; done

echo '2. Datenbank und Server'
[ -f config.php ] && mv config.php "$TMP/config.php.orig"
docker rm -f "$DB" >/dev/null 2>&1 || true
docker run -d --rm --name "$DB" -p 127.0.0.1:3307:3306 --tmpfs /var/lib/mysql \
  -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wedoboard -e MARIADB_USER=wedo -e MARIADB_PASSWORD=wedo \
  mariadb:10.6 >/dev/null
for i in $(seq 60); do
  docker exec "$DB" mariadb -uwedo -pwedo -e 'SELECT 1' wedoboard >/dev/null 2>&1 && break
  sleep 1
done
cat > config.php <<'PHP'
<?php
return [
    'db' => ['host' => '127.0.0.1;port=3307', 'name' => 'wedoboard', 'user' => 'wedo', 'pass' => 'wedo', 'charset' => 'utf8mb4'],
    'app_name' => 'Tafula',
    'base_url' => 'http://127.0.0.1:8090',
    'legacy_hosts' => ['alt.example'],
    'admin_path' => 'lehrerzimmer',
    'ai_allow_fake' => true,
    'default_lang' => 'de',
    'timezone' => 'Europe/Zurich',
];
PHP
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:$PORT dev-router.php >"$TMP/server.log" 2>&1 &
SRV=$!
for i in $(seq 20); do curl -s -o /dev/null "$BASE/" && break; sleep 0.5; done
ok 'MariaDB 10.6 und PHP-Server laufen'

echo '3. Installation'
TOKEN=$(curl -s -d 'password=testpass123&password2=testpass123' "$BASE/install.php" | grep -oE '[0-9a-f]{48}' | head -1 || true)
[ -n "$TOKEN" ] && ok 'install.php liefert API-Token' || bad 'kein API-Token aus install.php'
AUTH=(-H "Authorization: Bearer $TOKEN")

echo '4. Seiten'
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
[ "$(code "$BASE/")" = 200 ] && ok 'Startseite 200' || bad 'Startseite'
[ "$(code "$BASE/admin/")" = 404 ] && ok '/admin/ 404 bei eigenem Pfad' || bad '/admin/ sollte 404 sein'
[ "$(code "$BASE/lehrerzimmer/login.php")" = 200 ] && ok 'Bibliothek unter admin_path' || bad 'Bibliothek unter admin_path'
[ "$(code -H 'Host: alt.example' "$BASE/abcde")" = 301 ] && ok 'frühere Domain leitet um' || bad 'Weiterleitung legacy_hosts'
[ "$(code "$BASE/tools/test.sh")" = 404 ] && ok 'tools/ gesperrt' || bad 'tools/ sollte 404 sein'

echo '5. Schnittstelle'
curl -s "${AUTH[@]}" "$BASE/api/me" | jq -e '.owner == true' >/dev/null && ok 'GET /api/me als Besitzer' || bad '/api/me'
BOARD=$(curl -s "${AUTH[@]}" -H 'Content-Type: application/json' \
  -d '{"name":"Rauchtest","type":"columns","columns":["Pro","Contra"],"note":"Test","tags":"test"}' "$BASE/api/boards")
KEY=$(echo "$BOARD" | jq -r '.board.key // .board.bkey // empty')
[ -n "$KEY" ] && ok "Board angelegt ($KEY)" || bad "Board anlegen: $BOARD"

echo '6. Gäste'
J=(-b "$TMP/jar" -c "$TMP/jar")
NAME=$(curl -s "${J[@]}" "$BASE/api/b/$KEY" | jq -r '.me.name // empty')
[ -n "$NAME" ] && ok "Gast erhält Fantasienamen ($NAME)" || bad 'kein Fantasiename'
POST=$(curl -s "${J[@]}" -H 'Content-Type: application/json' -H 'X-Tafula: 1' \
  -d '{"title":"Hallo","body":"Rauchtest","column":"'"$(echo "$BOARD" | jq -r '.board.columns[0].id // empty')"'"}' "$BASE/api/b/$KEY/posts")
echo "$POST" | jq -e '.ok == true' >/dev/null && ok 'Gast schreibt Beitrag' || bad "Beitrag: $POST"
curl -s "${J[@]}" "$BASE/api/b/$KEY/state?since=0" | jq -e '(.posts | length) >= 1' >/dev/null && ok 'Polling liefert Beitrag' || bad 'Polling'

echo '7. Connector'
TOOLS=$(curl -s -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' "$BASE/mcp/$TOKEN" | jq '.result.tools | length' 2>/dev/null || echo 0)
[ "${TOOLS:-0}" -gt 20 ] && ok "tools/list liefert $TOOLS Werkzeuge" || bad "tools/list ($TOOLS)"

echo '8. Migrationen auf der Kommandozeile (wie beim Ausrollen)'
OUT=$(php -r 'require "lib/bootstrap.php"; echo db_migrate(), " ", setting_get("schema_version"), " ", WB_SCHEMA_VERSION;')
set -- $OUT
[ "$1" = 0 ] && [ "$2" = "$3" ] && ok "db_migrate() ohne Arbeit, Schema $2" || bad "Migrationen: $OUT"

if grep -qiE 'fatal|parse error' "$TMP/server.log"; then bad 'PHP-Fehler im Serverprotokoll'; grep -iE 'fatal|parse error' "$TMP/server.log" | head -5; fi
echo
[ $FAIL = 0 ] && echo 'Alle Prüfungen bestanden.' || { echo 'Es gab Fehler.'; exit 1; }
