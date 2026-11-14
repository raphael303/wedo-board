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

echo '9b. Gemeinschaftsprüfung durchführen (G2)'
EA="$BASE/api/activities/$EKEY"
GA="$BASE/api/a/$EKEY"
R=$(curl -s "${AUTH[@]}" "${JH[@]}" -d '{"type":"truefalse","prompt":"Aussagen zur Schweiz","options":[{"text":"Bern ist Bundesstadt.","correct":true},{"text":"Die Schweiz hat 30 Kantone.","correct":false},{"text":"Der Rhein fliesst durch Basel.","correct":true}]}' "$EA/questions")
[ "$(echo "$R" | jq -r '.question.status')" = accepted ] && ok 'Besitzer legt Richtig/Falsch an' || bad "Richtig/Falsch: $R"
R=$(curl -s "${AUTH[@]}" "${JH[@]}" -d '{"type":"open","prompt":"Was ist Photosynthese?","solution":"GEHEIMLOESUNGSSATZ Pflanzen bilden mit Licht Zucker."}' "$EA/questions")
QOPEN=$(echo "$R" | jq -r '.question.id // empty')
[ -n "$QOPEN" ] && ok 'Besitzer legt offene Frage an' || bad "offene Frage: $R"
R=$(curl -s "${AUTH[@]}" "${JH[@]}" -X PATCH -d '{"phase":"running","ranking":true,"grade":{"on":true,"round":"half"},"allow_retry":false}' "$EA")
[ "$(echo "$R" | jq -r '.activity.phase')" = collect ] && ok 'phase lässt sich über PATCH nicht ändern' || bad "PATCH phase: $R"
R=$(curl -s "${AUTH[@]}" "${JH[@]}" -d '{"phase":"results"}' "$EA/phase")
[ "$(echo "$R" | jq -r '.code')" = exam_phase_invalid ] && ok 'collect → results abgelehnt' || bad "Phasenwechsel ungültig: $R"
R=$(curl -s "${AUTH[@]}" "${JH[@]}" -d '{"phase":"running"}' "$EA/phase")
[ "$(echo "$R" | jq -r '.activity.phase')" = running ] && [ "$(echo "$R" | jq '.warnings | length')" = 1 ] && ok 'Prüfung gestartet, Warnung für eingereichte Frage' || bad "Start: $R"
R=$(curl -s "${G[@]}" "${JH[@]}" -d '{}' "$GA/attempt/start")
echo "$R" > "$TMP/start.json"
[ "$(echo "$R" | jq '.state.attempt.questions | length')" = 4 ] && ok 'Gast beginnt: vier angenommene Fragen' || bad "attempt/start: $R"
grep -q '"correct"' "$TMP/start.json" && bad 'Lösungen (correct) im Zustand während der Prüfung' || ok 'kein correct im Zustand'
grep -q 'GEHEIMLOESUNGSSATZ' "$TMP/start.json" && bad 'Lösungssatz im Zustand während der Prüfung' || ok 'kein Lösungssatz im Zustand'
curl -s "${G[@]}" "$BASE/a/$EKEY" | grep -q 'GEHEIMLOESUNGSSATZ\|"correct"' && bad 'Lösungen in der Seite' || ok 'keine Lösungen in der ausgelieferten Seite'
opt() { jq -r --arg p "$1" --arg t "$2" '.state.attempt.questions[] | select(.prompt == $p) | .options[] | select(.text == $t) | .id' "$TMP/start.json"; }
qid() { jq -r --arg p "$1" '.state.attempt.questions[] | select(.prompt == $p) | .id' "$TMP/start.json"; }
QS=$(qid 'Hauptstadt der Schweiz?'); QM=$(qid 'Welche sind Primzahlen?'); QT=$(qid 'Aussagen zur Schweiz')
ANS=$(jq -n --arg qs "$QS" --arg qm "$QM" --arg qt "$QT" --arg qo "$QOPEN" --arg bern "$(opt 'Hauptstadt der Schweiz?' Bern)" \
  --arg p2 "$(opt 'Welche sind Primzahlen?' 2)" --arg p5 "$(opt 'Welche sind Primzahlen?' 5)" \
  --arg t1 "$(opt 'Aussagen zur Schweiz' 'Bern ist Bundesstadt.')" --arg t2 "$(opt 'Aussagen zur Schweiz' 'Die Schweiz hat 30 Kantone.')" \
  '{answers: {($qs): $bern, ($qm): [$p2, $p5], ($qt): {($t1): true, ($t2): false}, ($qo): "Pflanzen machen aus Licht Zucker."}}')
R=$(curl -s "${G[@]}" "${JH[@]}" -d "$ANS" "$GA/attempt/answer")
[ "$(echo "$R" | jq -r '.ok')" = true ] && ok 'Antworten zwischengespeichert' || bad "answer: $R"
R=$(curl -s "${G[@]}" "${JH[@]}" -d '{"answers":{"'"$QS"'":"gibtsnicht"}}' "$GA/attempt/answer")
[ "$(echo "$R" | jq -r '.code')" = exam_answer_invalid ] && ok 'ungültige Antwort abgelehnt' || bad "ungültige Antwort: $R"
R=$(curl -s "${G[@]}" "$GA/state")
[ "$(echo "$R" | jq -r --arg q "$QS" '.attempt.questions[] | select(.id == ($q|tonumber)) | .answer')" = "$(opt 'Hauptstadt der Schweiz?' Bern)" ] && ok 'Antwort nach Neuladen vorhanden' || bad 'Antwort nicht gespeichert'
R=$(curl -s "${G[@]}" "${JH[@]}" -d '{}' "$GA/attempt/submit")
[ "$(echo "$R" | jq -r '.state.attempt.status')" = submitted ] && [ "$(echo "$R" | jq -r '.state.attempt.result')" = null ] && [ "$(echo "$R" | jq -r '.state.attempt.solutions')" = null ] \
  && ok 'abgegeben: keine vorläufigen Punkte, keine Lösungen' || bad "submit: $R"
grep -q '"correct"' <<<"$R" && bad 'correct nach Abgabe vor Freigabe' || ok 'Lösungen nach Abgabe weiter geschützt'
R=$(curl -s "${G[@]}" "${JH[@]}" -d "$ANS" "$GA/attempt/answer")
[ "$(echo "$R" | jq -r '.code')" = exam_submitted ] && ok 'nach Abgabe keine Änderung mehr' || bad "Änderung nach Abgabe: $R"
G3=(-b "$TMP/jar3" -c "$TMP/jar3")
R=$(curl -s "${G3[@]}" "${JH[@]}" -d '{}' "$GA/attempt/start")
[ "$(echo "$R" | jq -r '.state.attempt.status')" = in_progress ] && ok 'zweiter Gast beginnt' || bad "Gast 2: $R"
curl -s "${G3[@]}" "${JH[@]}" -d '{}' "$GA/attempt/submit" >/dev/null
curl -s "${AUTH[@]}" "${JH[@]}" -X PATCH -d '{"time_limit":{"minutes":5,"per_person":true}}' "$EA" >/dev/null
G4=(-b "$TMP/jar4" -c "$TMP/jar4")
R=$(curl -s "${G4[@]}" "${JH[@]}" -d '{}' "$GA/attempt/start")
SL=$(echo "$R" | jq -r '.state.attempt.seconds_left')
[ "$SL" -gt 280 ] 2>/dev/null && [ "$SL" -le 300 ] && ok "Zeitbegrenzung pro Person ($SL s)" || bad "Zeitbegrenzung: $R"
php -r 'require "lib/bootstrap.php"; db_exec("UPDATE exam_attempts SET deadline_at = ? WHERE status = ?", [(new DateTime("-1 minute", new DateTimeZone("UTC")))->format("Y-m-d H:i:s.u"), "in_progress"]);'
R=$(curl -s "${G4[@]}" "${JH[@]}" -d "$ANS" "$GA/attempt/answer")
[ "$(echo "$R" | jq -r '.code')" = exam_time_up ] && ok 'nach Ablauf: exam_time_up' || bad "Zeitablauf: $R"
[ "$(curl -s "${G4[@]}" "$GA/state" | jq -r '.attempt.status')" = submitted ] && ok 'nach Ablauf automatisch abgegeben' || bad 'nicht automatisch abgegeben'
R=$(curl -s "${AUTH[@]}" "$EA/attempts")
[ "$(echo "$R" | jq '.attempts | length')" = 3 ] && ok 'Besitzer sieht drei Versuche' || bad "attempts: $R"
AID=$(echo "$R" | jq -r --arg n "$ENAME" '.attempts[] | select(.name == $n) | .id')
[ "$(echo "$R" | jq -r --arg n "$ENAME" '.attempts[] | select(.name == $n) | .status')" = submitted ] && [ "$(echo "$R" | jq -r --arg n "$ENAME" '.attempts[] | select(.name == $n) | .pending')" = 1 ] \
  && ok 'offene Frage wartet auf Bewertung' || bad "pending: $R"
R=$(curl -s "${AUTH[@]}" "$EA/attempts/$AID")
[ "$(echo "$R" | jq -r --arg q "$QT" '.attempt.answers[] | select(.question_id == ($q|tonumber)) | .auto')" = 1 ] && ok 'Kprim: ein Fehler (offene Aussage) ergibt die Hälfte' || bad "Kprim: $R"
[ "$(echo "$R" | jq -r --arg q "$QM" '.attempt.answers[] | select(.question_id == ($q|tonumber)) | .auto')" = 3 ] && ok 'Mehrfachauswahl richtig: volle Punkte' || bad "Mehrfachauswahl: $R"
R=$(curl -s "${AUTH[@]}" "${JH[@]}" -d '{"question_id":'"$QOPEN"',"points":5}' "$EA/attempts/$AID/grade")
[ "$(echo "$R" | jq -r '.code')" = exam_err_grade_points ] && ok 'zu viele Punkte abgelehnt' || bad "grade zu viel: $R"
R=$(curl -s "${AUTH[@]}" "${JH[@]}" -d '{"question_id":'"$QOPEN"',"points":2}' "$EA/attempts/$AID/grade")
[ "$(echo "$R" | jq -r '.attempt.status')" = graded ] && [ "$(echo "$R" | jq -r '.attempt.score')" = 7 ] && [ "$(echo "$R" | jq -r '.attempt.max_score')" = 8 ] \
  && ok 'offene Frage von Hand bewertet: 7 von 8' || bad "grade: $R"
[ "$(curl -s "${G[@]}" "$GA/state" | jq -r '.attempt.result')" = null ] && ok 'von Hand bewertet: Punkte erst nach Freigabe' || bad 'Punkte vor review_done sichtbar'
curl -s "${AUTH[@]}" "${JH[@]}" -d '{"done":true}' "$EA/review" >/dev/null
R=$(curl -s "${G[@]}" "$GA/state")
[ "$(echo "$R" | jq -r '.attempt.result.score')" = 7 ] && [ "$(echo "$R" | jq -r '.attempt.result.grade')" = 5.5 ] && ok 'nach Korrektur: 7 Punkte, Note 5.5' || bad "Ergebnis: $(echo "$R" | jq -c '.attempt.result')"
[ "$(curl -s "${AUTH[@]}" "$EA/ranking" | jq '.ranking | length')" = 1 ] && ok 'Rangliste nur mit vollständig bewerteten Versuchen' || bad 'Rangliste'
[ "$(curl -s "${G[@]}" "$GA/state" | jq -r '.ranking')" = null ] && ok 'Rangliste vor dem Zeigen verborgen' || bad 'Rangliste zu früh sichtbar'
R=$(curl -s "${AUTH[@]}" "${JH[@]}" -d '{"phase":"collect"}' "$EA/phase")
[ "$(echo "$R" | jq -r '.code')" = exam_phase_has_attempts ] && ok 'zurück zum Sammeln mit Versuchen abgelehnt' || bad "collect: $R"
R=$(curl -s "${AUTH[@]}" "${JH[@]}" -d '{"phase":"results"}' "$EA/phase")
[ "$(echo "$R" | jq -r '.activity.revealed')" = true ] && ok 'Ergebnisse freigegeben, Rangliste gezeigt' || bad "results: $R"
R=$(curl -s "${G[@]}" "$GA/state")
[ "$(echo "$R" | jq -r '.ranking[0].m')" = 1 ] && ok 'eigene Zeile der Rangliste markiert' || bad "Rangliste Gast: $(echo "$R" | jq -c '.ranking')"
[ "$(echo "$R" | jq -r --arg q "$QS" '.attempt.solutions[$q].correct[0]')" = "$(opt 'Hauptstadt der Schweiz?' Bern)" ] && echo "$R" | grep -q GEHEIMLOESUNGSSATZ && ok 'Lösungen nach den Ergebnissen sichtbar' || bad "Lösungen: $(echo "$R" | jq -c '.attempt.solutions')"
[ "$(echo "$R" | jq -r '.can_retry')" = false ] && ok 'kein weiterer Versuch ohne allow_retry' || bad 'can_retry ohne allow_retry'
curl -s "${AUTH[@]}" "${JH[@]}" -X PATCH -d '{"allow_retry":true}' "$EA" >/dev/null
R=$(curl -s "${G[@]}" "${JH[@]}" -d '{}' "$GA/attempt/retry")
[ "$(echo "$R" | jq -r '.state.attempt.no')" = 2 ] && [ "$(echo "$R" | jq -r '.state.attempt.solutions')" = null ] && ok 'Übungsversuch 2 ohne Lösungen vor der Abgabe' || bad "retry: $R"
[ "$(curl -s "${AUTH[@]}" "$EA/ranking" | jq '.ranking | length')" = 1 ] && ok 'Übungsversuch zählt nicht' || bad 'Rangliste nach Übungsversuch'
OUT=$(php -r 'require "lib/bootstrap.php";
$a = ["config" => exam_default_config()]; $a["config"]["mc_rule"] = "proportional";
$q = ["type" => "truefalse", "points" => null, "options_json" => json_encode([["id"=>"a","correct"=>true],["id"=>"b","correct"=>false],["id"=>"c","correct"=>true],["id"=>"d","correct"=>true]])];
$p1 = exam_grade_question($a, $q, ["a"=>true,"b"=>true,"c"=>true]);
$a["config"]["mc_rule"] = "kprim"; $p2 = exam_grade_question($a, $q, ["a"=>true,"b"=>false,"c"=>true,"d"=>true]); $p3 = exam_grade_question($a, $q, ["a"=>false,"b"=>true]);
$a["config"]["grade"] = ["on"=>true,"max"=>null,"round"=>"quarter"]; $a["config"]["display"] = "percent"; $g1 = exam_grade_value($a, 13.0, 20.0);
$a["config"]["grade"]["round"] = "tenth"; $a["config"]["grade"]["max"] = 90.0; $g2 = exam_grade_value($a, 19.0, 20.0);
echo json_encode([$p1, $p2, $p3, $g1, $g2]);' | jq -c .)
[ "$OUT" = '[0.5,2,0,4.25,6]' ] && ok 'Bewertungsregeln (anteilig, Kprim, Note mit Rundung und Maximum)' || bad "Regeln: $OUT"
X=$(curl -s "${AUTH[@]}" "${JH[@]}" -d '{"type":"exam","name":"Automatisch","quota_max":0,"solutions":"immediate","note":"Test","tags":"test"}' "$BASE/api/activities" | jq -r '.activity.key')
curl -s "${AUTH[@]}" "${JH[@]}" -d '{"type":"single","prompt":"Zwei plus zwei?","options":[{"text":"4","correct":true},{"text":"5","correct":false}]}' "$BASE/api/activities/$X/questions" >/dev/null
curl -s "${AUTH[@]}" "${JH[@]}" -d '{"phase":"running"}' "$BASE/api/activities/$X/phase" >/dev/null
G5=(-b "$TMP/jar5" -c "$TMP/jar5")
R=$(curl -s "${G5[@]}" "${JH[@]}" -d '{}' "$BASE/api/a/$X/attempt/start")
XQ=$(echo "$R" | jq -r '.state.attempt.questions[0].id'); XO=$(echo "$R" | jq -r '.state.attempt.questions[0].options[] | select(.text == "4") | .id')
curl -s "${G5[@]}" "${JH[@]}" -d '{"answers":{"'"$XQ"'":"'"$XO"'"}}' "$BASE/api/a/$X/attempt/answer" >/dev/null
R=$(curl -s "${G5[@]}" "${JH[@]}" -d '{}' "$BASE/api/a/$X/attempt/submit")
[ "$(echo "$R" | jq -r '.state.attempt.result.score')" = 1 ] && [ "$(echo "$R" | jq -r --arg q "$XQ" '.state.attempt.solutions[$q].correct[0]')" = "$XO" ] \
  && ok 'rein automatisch: Punkte und Lösungen (immediate) sofort nach der Abgabe' || bad "automatisch: $R"
R=$(MCP '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"read_exam_results","arguments":{"exam":"'"$EKEY"'"}}}')
echo "$R" | jq -r '.result.content[0].text' | grep -q 'Rangliste' && ok 'Connector: read_exam_results' || bad "read_exam_results: $R"
R=$(MCP '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"control_exam","arguments":{"exam":"'"$EKEY"'","ranking_shown":false}}}')
echo "$R" | jq -r '.result.content[0].text' | jq -e '.ranking_shown == false' >/dev/null && ok 'Connector: control_exam verbirgt Rangliste' || bad "control_exam: $R"

echo '10. Migration 13 von Schema 12'
OUT=$(php -r 'require "lib/bootstrap.php"; foreach (["exam_questions","exam_attempts","activity_guest_names"] as $t) db()->exec("DROP TABLE $t"); setting_set("schema_version","12"); echo db_migrate(), " ", setting_get("schema_version"), " ", (int)db_row("SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN (\"exam_questions\",\"exam_attempts\",\"activity_guest_names\")")["n"];')
[ "$OUT" = "1 13 3" ] && ok 'Migration 13 legt die drei Tabellen an' || bad "Migration 13: $OUT"

if grep -qiE 'fatal|parse error' "$TMP/server.log"; then bad 'PHP-Fehler im Serverprotokoll'; grep -iE 'fatal|parse error' "$TMP/server.log" | head -5; fi
echo
[ $FAIL = 0 ] && echo 'Alle Prüfungen bestanden.' || { echo 'Es gab Fehler.'; exit 1; }
