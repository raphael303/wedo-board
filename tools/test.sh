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

echo '9. Gemeinschaftsprüfung'
JH=(-H 'Content-Type: application/json')
EX=$(curl -s "${AUTH[@]}" "${JH[@]}" -d '{"type":"exam","name":"Rauchtest-Prüfung","quota_max":2,"note":"Test","tags":"test"}' "$BASE/api/activities")
EKEY=$(echo "$EX" | jq -r '.activity.key // empty')
[ -n "$EKEY" ] && ok "Prüfung angelegt ($EKEY)" || bad "Prüfung anlegen: $EX"
[ "$(code "$BASE/a/$EKEY")" = 200 ] && ok 'Prüfungsseite 200' || bad 'Prüfungsseite'
G=(-b "$TMP/jar2" -c "$TMP/jar2")
ENAME=$(curl -s "${G[@]}" "$BASE/api/a/$EKEY" | jq -r '.state.me.name // empty')
[ -n "$ENAME" ] && ok "Gast erhält Fantasienamen auf der Prüfung ($ENAME)" || bad 'kein Fantasiename auf der Prüfung'
R=$(curl -s "${G[@]}" "${JH[@]}" -d '{"type":"single","prompt":"Hauptstadt der Schweiz?","options":[{"text":"Bern","correct":true},{"text":"Zürich","correct":false}]}' "$BASE/api/a/$EKEY/questions")
Q1=$(echo "$R" | jq -r '.question.id // empty')
[ -n "$Q1" ] && [ "$(echo "$R" | jq -r '.state.quota_used')" = 1 ] && ok 'Gast reicht Einfachauswahl ein' || bad "Frage einreichen: $R"
R=$(curl -s "${G[@]}" "${JH[@]}" -d '{"type":"single","prompt":"Zwei richtig?","options":[{"text":"a","correct":true},{"text":"b","correct":true}]}' "$BASE/api/a/$EKEY/questions")
[ "$(echo "$R" | jq -r '.code')" = exam_err_single ] && ok 'Einfachauswahl mit zwei richtigen abgelehnt' || bad "Prüfung Einfachauswahl: $R"
R=$(curl -s "${G[@]}" "${JH[@]}" -d '{"type":"open","prompt":"Warum ist der Himmel blau?","solution":"Weil kurzwelliges Licht stärker gestreut wird."}' "$BASE/api/a/$EKEY/questions")
[ "$(echo "$R" | jq -r '.ok')" = true ] && ok 'Gast reicht offene Frage ein' || bad "offene Frage: $R"
R=$(curl -s "${G[@]}" "${JH[@]}" -d '{"type":"truefalse","prompt":"Stimmt das?","options":[{"text":"x","correct":true},{"text":"y","correct":false}]}' "$BASE/api/a/$EKEY/questions")
[ "$(echo "$R" | jq -r '.code')" = exam_quota ] && ok 'Kontingent greift' || bad "Kontingent: $R"
R=$(curl -s "${AUTH[@]}" "$BASE/api/activities/$EKEY/questions")
[ "$(echo "$R" | jq -r '.counts.submitted')" = 2 ] && ok 'Besitzer sieht zwei eingereichte Fragen' || bad "Fragen lesen: $R"
R=$(curl -s "${AUTH[@]}" -X POST "$BASE/api/activities/$EKEY/questions/$Q1/accept")
[ "$(echo "$R" | jq -r '.question.status')" = accepted ] && ok 'Frage angenommen' || bad "annehmen: $R"
R=$(curl -s "${G[@]}" "${JH[@]}" -d '{"prompt":"Geändert?"}' "$BASE/api/a/$EKEY/questions/$Q1")
[ "$(echo "$R" | jq -r '.code')" = exam_err_locked ] && ok 'angenommene Frage für Gast gesperrt' || bad "Sperre: $R"
R=$(curl -s "${AUTH[@]}" "${JH[@]}" -d '{"type":"multi","prompt":"Welche sind Primzahlen?","options":[{"text":"2","correct":true},{"text":"4","correct":false},{"text":"5","correct":true}],"points":3}' "$BASE/api/activities/$EKEY/questions")
[ "$(echo "$R" | jq -r '.question.status')" = accepted ] && [ "$(echo "$R" | jq -r '.question.points')" = 3 ] && ok 'Besitzer legt Frage mit eigenen Punkten an' || bad "Besitzerfrage: $R"
curl -s "${AUTH[@]}" "$BASE/api/activities/$EKEY/results.md" | grep -q 'Hauptstadt der Schweiz' && ok 'Prüfung als Markdown' || bad 'results.md'
MCP() { curl -s "${JH[@]}" -H 'Accept: application/json, text/event-stream' -d "$1" "$BASE/mcp/$TOKEN"; }
R=$(MCP '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"create_exam","arguments":{"name":"Connector-Prüfung","quota_max":0,"note":"Test","tags":["test"],"questions":[{"type":"truefalse","prompt":"Aussagen zu Wasser","options":[{"text":"Wasser kocht auf Meereshöhe bei 100 °C.","correct":true},{"text":"Eis ist schwerer als Wasser.","correct":false}]}]}}}')
echo "$R" | jq -r '.result.content[0].text' | jq -e '.questions_added == 1' >/dev/null && ok 'Connector: create_exam mit Frage' || bad "create_exam: $R"
R=$(MCP '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"read_exam","arguments":{"exam":"'"$EKEY"'"}}}')
echo "$R" | jq -r '.result.content[0].text' | grep -q 'Welche sind Primzahlen' && ok 'Connector: read_exam' || bad "read_exam: $R"

echo '10. Migration 13 von Schema 12'
OUT=$(php -r 'require "lib/bootstrap.php"; foreach (["exam_questions","exam_attempts","activity_guest_names"] as $t) db()->exec("DROP TABLE $t"); setting_set("schema_version","12"); echo db_migrate(), " ", setting_get("schema_version"), " ", (int)db_row("SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN (\"exam_questions\",\"exam_attempts\",\"activity_guest_names\")")["n"];')
[ "$OUT" = "1 13 3" ] && ok 'Migration 13 legt die drei Tabellen an' || bad "Migration 13: $OUT"

if grep -qiE 'fatal|parse error' "$TMP/server.log"; then bad 'PHP-Fehler im Serverprotokoll'; grep -iE 'fatal|parse error' "$TMP/server.log" | head -5; fi
echo
[ $FAIL = 0 ] && echo 'Alle Prüfungen bestanden.' || { echo 'Es gab Fehler.'; exit 1; }
