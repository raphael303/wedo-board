# Teil von tools/test.sh (wird dort eingebunden): Nutzerverwaltung U1a auf der Hauptdatenbank des Tests.
# Stand beim Einstieg: Produktion (keine Instanzart), Registrierung «aus», Benutzer 1 = $OWNER (Super-Admin, noch
# ohne App-Stufe), Cookie-Datei $TMP/admin ist als $OWNER angemeldet (Mailcode), $KEY ist ein Board, $EKEY und $W
# sind Prüfungen des Benutzers 1.

# «curl | grep -q» bricht curl ab, sobald grep fündig ist; mit pipefail gälte das als Fehler.
set +o pipefail
echo '11b. Nutzerverwaltung U1a: Anmeldung und Bremse'
P() { php -r 'require "lib/bootstrap.php"; '"$1" "${@:2}"; }
LG="$BASE/login"
P 'db_exec("DELETE FROM rate_limits");'
CS=$(csrf_of "$TMP/lj" /login)
[ "$(code -b "$TMP/lj" -d "email=$OWNER&password=x" "$LG")" = 400 ] && ok 'Anmeldung ohne CSRF-Feld: 400' || bad 'Anmeldung ohne CSRF'
curl -s -b "$TMP/lj" -d "csrf=$CS&email=$OWNER&password=falsch-falsch" "$LG" > "$TMP/l1.html"
curl -s -b "$TMP/lj" -d "csrf=$CS&email=niemand@example.org&password=falsch-falsch" "$LG" > "$TMP/l2.html"
E1=$(grep -o 'class="error"[^<]*<' "$TMP/l1.html" | head -1); E2=$(grep -o 'class="error"[^<]*<' "$TMP/l2.html" | head -1)
[ -n "$E1" ] && [ "$E1" = "$E2" ] && ok 'falsches Passwort und unbekannte Adresse: dieselbe Meldung' || bad "Meldungen unterscheiden sich: [$E1] [$E2]"
for i in 1 2 3 4; do curl -s -o /dev/null -b "$TMP/lj" -d "csrf=$CS&email=$OWNER&password=falsch-$i" "$LG"; done
R=$(curl -s -o "$TMP/lg.html" -w '%{http_code}' -b "$TMP/lj" --data-urlencode "csrf=$CS" --data-urlencode "email=$OWNER" --data-urlencode "password=$OWNERPW" "$LG")
[ "$R" = 429 ] && grep -q 'Zu viele Fehlversuche' "$TMP/lg.html" && ok 'nach fünf Fehlversuchen am Konto: verlangsamt (429), auch mit richtigem Passwort' || bad "Bremse pro Konto: $R"
CSA=$(csrf_of "$TMP/admin" /account)
cp "$TMP/admin" "$TMP/known"; sed -i '/tafula_s/d' "$TMP/known"
CSK=$(csrf_of "$TMP/known" /login)
R=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b "$TMP/known" -c "$TMP/known" --data-urlencode "csrf=$CSK" --data-urlencode "email=$OWNER" --data-urlencode "password=$OWNERPW" "$LG")
[ "$R" = "302 $BASE/login/code" ] && ok 'bekanntes Gerät ist von der Bremse ausgenommen (Systemrolle: trotzdem zweite Stufe)' || bad "bekanntes Gerät: $R"
N=$(P 'echo (int)db_row("SELECT COUNT(*) AS n FROM rate_limits WHERE author_key REGEXP \"^[0-9a-f]{32}$\"")["n"];')
[ "$N" -ge 2 ] && ! P 'foreach (db_all("SELECT author_key FROM rate_limits") as $r) if (str_contains($r["author_key"], "127.0.0.1") || str_contains($r["author_key"], "owner")) exit(0); exit(1);' && ok 'Zähler in rate_limits mit gehashten Schlüsseln' || bad "rate_limits-Schlüssel ($N)"
P 'for ($i = 0; $i < ACC_IP_FAILS; $i++) brake_hit("login-ip|127.0.0.1");'
R=$(curl -s -o /dev/null -w '%{http_code}' -b "$TMP/lj" -d "csrf=$CS&email=x@example.org&password=irgendwas-12" "$LG")
[ "$R" = 429 ] && ok 'Grenze pro IP-Adresse: 429' || bad "Grenze pro IP: $R"
P 'db_exec("DELETE FROM rate_limits");'
grep -q 'tafula_s' "$TMP/admin" && ! grep -q 'wb_admin\|wb_known' "$TMP/admin" && P 'exit(db_row("SELECT COUNT(*) AS n FROM sessions WHERE LENGTH(token_hash) = 64 AND revoked_at IS NULL")["n"] >= 1 && setting_get("admin_password_hash") === null ? 0 : 1);' \
  && ok 'Sitzung in der Datenbank (nur Hash), kein wb_admin, kein wb_known, kein admin_password_hash' || bad 'Sitzung in der Datenbank'
[ "$(P 'echo wb_cookie_name("x"); $_SERVER["HTTPS"] = "on"; echo " ", wb_cookie_name("tafula_s");')" = 'x __Host-tafula_s' ] && ok 'Cookie über HTTPS mit Präfix __Host-' || bad 'Cookie-Präfix'

echo '11c. Nutzerverwaltung U1a: zweites Konto sieht nichts Fremdes'
LEA=lea@tafula.test; LEAPW=leas-eigenes-passwort
P 'user_create(["email" => $argv[1], "password" => $argv[2], "first_name" => "Lea", "last_name" => "Zweit", "verified" => true, "age_confirmed" => true]);' "$LEA" "$LEAPW"
R=$(login_as "$TMP/lea" "$LEA" "$LEAPW")
[ "$R" = 302 ] && ok 'zweites Konto meldet sich an (Mailcode auf unbekanntem Gerät)' || bad "Anmeldung Lea: $R"
L=(-b "$TMP/lea" -c "$TMP/lea")
CSL=$(csrf_of "$TMP/lea" /account)
LX=("${L[@]}" -H "X-CSRF: $CSL")
[ "$(login_as "$TMP/lea2" "$LEA" "$LEAPW")" = 302 ] && cp "$TMP/lea" "$TMP/lea3" && sed -i '/tafula_s/d' "$TMP/lea3" && CS3=$(csrf_of "$TMP/lea3" /login) \
  && [ "$(curl -s -o /dev/null -w '%{redirect_url}' -b "$TMP/lea3" -c "$TMP/lea3" --data-urlencode "csrf=$CS3" --data-urlencode "email=$LEA" --data-urlencode "password=$LEAPW" "$LG")" = "$BASE/lehrerzimmer/" ] \
  && ok 'bekanntes Gerät ohne Systemrolle: Anmeldung ohne Mailcode' || bad 'bekanntes Gerät ohne Mailcode'
curl -s "${L[@]}" "$BASE/api/me" | jq -e '.user.email == "lea@tafula.test" and .roles == [] and .auth == "session"' >/dev/null && ok '/api/me mit Sitzung' || bad '/api/me Lea'
[ "$(curl -s "${L[@]}" "$BASE/api/boards?status=all" | jq '.boards | length')" = 0 ] && [ "$(curl -s "${L[@]}" "$BASE/api/activities?status=all" | jq '.activities | length')" = 0 ] && ok 'Listen enthalten nichts Fremdes' || bad 'Listen für Lea'
ALL404=1
for u in "boards/$KEY" "boards/$KEY/posts" "boards/$KEY/export.json" "boards/$KEY/history" "activities/$EKEY" "activities/$EKEY/results" "activities/$EKEY/questions" "activities/$W/attempts" "activities/$W/results.csv"; do
  [ "$(code "${L[@]}" "$BASE/api/$u")" = 404 ] || { ALL404=0; bad "fremdes Objekt lesbar: /api/$u"; }
done
for u in "boards/$KEY/clear" "boards/$KEY/delete" "boards/$KEY/close" "boards/$KEY/duplicate" "activities/$EKEY/delete" "activities/$EKEY/phase" "activities/$EKEY/questions"; do
  [ "$(code "${LX[@]}" "${JH[@]}" -d '{"phase":"running","name":"x"}' "$BASE/api/$u")" = 404 ] || { ALL404=0; bad "fremdes Objekt änderbar: /api/$u"; }
done
[ "$(code "${LX[@]}" "${JH[@]}" -X PATCH -d '{"name":"gekapert"}' "$BASE/api/boards/$KEY")" = 404 ] || { ALL404=0; bad 'fremdes Board umbenannt'; }
[ $ALL404 = 1 ] && [ "$(curl -s "${AUTH[@]}" "$BASE/api/boards/$KEY" | jq -r '.board.name')" = Rauchtest ] && ok 'fremde Boards und Aktivitäten antworten in der Schnittstelle mit 404 (lesen und schreiben)' || bad 'fremde Objekte in der Schnittstelle'
for pg in "board.php?key=$KEY" "exam.php?key=$EKEY" "exam.php?key=$W" "export.php?key=$KEY" "feedback.php"; do
  [ "$(code "${L[@]}" "$BASE/lehrerzimmer/$pg")" = 404 ] || { ALL404=0; bad "Bibliothek zeigt Fremdes: $pg"; }
done
[ $ALL404 = 1 ] && [ "$(code "${L[@]}" "$BASE/lehrerzimmer/")" = 200 ] && ! curl -s "${L[@]}" "$BASE/lehrerzimmer/" | grep -q 'Rauchtest\|feedback\.php' && ok 'Bibliothek: fremde Seiten 404, Liste leer, kein Menü «Rückmeldungen»' || bad 'Bibliothek für Lea'
R=$(curl -s "${L[@]}" "$BASE/api/a/$W/state")
[ "$(curl -s "${L[@]}" "$BASE/api/a/$W" | jq -r '.me.owner')" = false ] && [ "$(echo "$R" | jq -r '.discussion // "keine"')" = keine ] && ! echo "$R" | grep -q GEHEIMSATZ && [ "$(code "${L[@]}" "$BASE/a/$W/print?doc=key")" = 404 ] \
  && ok 'fremde Prüfung: nur Gästesicht, keine Besprechung, keine Lösungen, keine Druckseiten der Lehrperson' || bad "fremde Prüfung für Lea: $(echo "$R" | head -c 200)"
! curl -s "${L[@]}" "$BASE/b/$KEY" | grep -q 'lehrerzimmer' && curl -s -b "$TMP/admin" "$BASE/b/$KEY" | grep -q 'lehrerzimmer/board.php' && ok 'Board-Seite: Link zur Bibliothek nur für die Besitzerin' || bad "Besitzeransicht der Board-Seite: lea=[$(curl -s "${L[@]}" "$BASE/b/$KEY" | grep -o '.\{60\}lehrerzimmer.\{40\}' | head -2)] admin=$(curl -s -b "$TMP/admin" "$BASE/b/$KEY" | grep -c lehrerzimmer) $(code -b "$TMP/admin" "$BASE/b/$KEY")"
[ "$(code "${L[@]}" "${JH[@]}" -d '{"name":"Ohne CSRF"}' "$BASE/api/boards")" = 403 ] && ok 'Schreiben mit Sitzung ohne X-CSRF: 403' || bad 'CSRF an /api/boards'
LB=$(curl -s "${LX[@]}" "${JH[@]}" -d '{"name":"Leas Board","note":"Test","tags":"test"}' "$BASE/api/boards" | jq -r '.board.key // empty')
[ -n "$LB" ] && [ "$(code "${AUTH[@]}" "$BASE/api/boards/$LB")" = 404 ] && ! curl -s "${AUTH[@]}" "$BASE/api/boards?status=all" | jq -r '.boards[].name' | grep -q 'Leas Board' \
  && [ "$(P 'echo implode(" ", array_map("strval", db_row("SELECT owner_user_id <> 1 AS o, org_id IS NULL AS p, LENGTH(identity_seed) AS s FROM boards WHERE bkey = ?", [$argv[1]])));' "$LB")" = '1 1 32' ] \
  && ok 'Lea legt ein Board an: gehört ihr, liegt in «Privat», hat einen Zufallswert; der Token des Benutzers 1 sieht es nicht' || bad "Leas Board: $LB"
MCPX() { curl -s "${JH[@]}" -H 'Accept: application/json, text/event-stream' -d "$1" -H "Authorization: Bearer $ATOK" "$BASE/mcp/private"; }
R=$(MCPX '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"read_board","arguments":{"board":"'"$LB"'"}}}')
echo "$R" | grep -q 'Kein aktives Board' && ! MCPX '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"list_boards","arguments":{"status":"all"}}}' | grep -q 'Leas Board' && ok 'Connector erreicht nur Eigenes (read_board, list_boards)' || bad "Connector und fremdes Board: $(echo "$R" | head -c 200)"
[ "$(code "$BASE/mcp/$(printf '0%.0s' $(seq 48))")" = 404 ] && ok 'Connector mit falschem Token: 404' || bad 'Connector mit falschem Token'

echo '11d. Nutzerverwaltung U1a: Sperren, Rückmeldungen, Zurechnung'
GK=$(printf 'ab%.0s' $(seq 16))
B2=$(curl -s "${AUTH[@]}" "${JH[@]}" -d '{"name":"Zweites Board","note":"Test","tags":"test"}' "$BASE/api/boards" | jq -r '.board.key')
BL=$(curl -s "${AUTH[@]}" "${JH[@]}" -d '{"guest_key":"'"$GK"'","scope":"all","display_name":"Stoerer"}' "$BASE/api/boards/$KEY/block" | jq -r '.block.id')
GH=(-H "X-Guest-Key: $GK" -H 'X-Tafula: 1' "${JH[@]}")
[ "$(code "${GH[@]}" -d '{"body":"gesperrt"}' "$BASE/api/b/$B2/posts")" = 403 ] && [ "$(code "${GH[@]}" -d '{"body":"frei"}' "$BASE/api/b/$LB/posts")" = 201 ] \
  && ok 'Sperre «auf allen Boards»: gilt auf den Boards der sperrenden Person, nicht auf fremden' || bad 'Reichweite der Sperre'
[ "$(curl -s "${L[@]}" "$BASE/api/blocks?status=all" | jq '.blocks | length')" = 0 ] && [ "$(code "${LX[@]}" -X POST "$BASE/api/blocks/$BL/lift")" = 422 ] && [ "$(curl -s "${AUTH[@]}" "$BASE/api/blocks" | jq '[.blocks[] | select(.id == '"$BL"')] | length')" = 1 ] \
  && ok 'Sperren: Liste und Aufheben nur für eigene' || bad 'fremde Sperre'
curl -s "${JH[@]}" -d '{"kind":"bug","text":"Rückmeldung für den Rechtetest"}' "$BASE/api/feedback" >/dev/null
[ "$(code "${AUTH[@]}" "$BASE/api/feedback")" = 403 ] && [ "$(code "${AUTH[@]}" "$BASE/api/feedback/1")" = 403 ] && [ "$(code "${L[@]}" "$BASE/api/feedback")" = 403 ] && [ "$(code "$BASE/api/feedback")" = 401 ] \
  && [ "$(code -b "$TMP/admin" "$BASE/api/feedback")" = 403 ] && [ "$(code -b "$TMP/admin" "$BASE/lehrerzimmer/feedback.php")" = 404 ] \
  && ok 'Rückmeldungen: nicht mit Token, nicht für andere Konten, nicht für den Super-Admin ohne App-Stufe' || bad 'Zugang zu Rückmeldungen'
[ "$(code "${AUTH[@]}" -X POST "$BASE/api/maintenance/purge")" = 403 ] && [ "$(code "${LX[@]}" -X POST "$BASE/api/maintenance/purge")" = 403 ] && ok 'Papierkorb aller leeren: nicht mit Token, nicht für andere' || bad 'maintenance/purge'
[ "$(P 'echo (int)db_row("SELECT COUNT(*) AS n FROM ai_usage")["n"] > 0 ? 1 : 0, (int)db_row("SELECT COUNT(*) AS n FROM ai_usage u LEFT JOIN users x ON x.id = u.user_id WHERE x.id IS NULL")["n"];')" = 10 ] && ok 'jeder KI-Aufruf ist einem bestehenden Konto zugerechnet' || bad 'Zurechnung der KI-Aufrufe'
P 'wb_auth_cli(null); try { board_create(["name" => "ohne Konto"]); echo "angelegt"; } catch (Throwable $e) { echo "abgelehnt"; }' | grep -q abgelehnt && ok 'ohne Anmeldung entsteht kein Board (kein Rückfall auf den Benutzer 1)' || bad 'Board ohne Konto'

echo '11e. Nutzerverwaltung U1a: App-Stufe, Verwaltung, Schalter'
[ "$(curl -s -o /dev/null -w '%{redirect_url}' -b "$TMP/admin" "$BASE/manage/system")" = "$BASE/account/totp" ] && [ "$(code "${L[@]}" "$BASE/manage/system")" = 404 ] && ok 'Verwaltung: ohne App-Stufe zur Einrichtung, ohne Systemrolle 404' || bad 'Zugang zur Verwaltung'
curl -s -b "$TMP/admin" -c "$TMP/admin" "$BASE/account/totp" > "$TMP/totp.html"
SECRET=$(grep -oE '<code class="token">[A-Z2-7 ]+</code>' "$TMP/totp.html" | sed -E 's/<[^>]+>//g; s/ //g')
SEALED=$(grep -oE 'name="s" value="[^"]+"' "$TMP/totp.html" | sed -E 's/.*value="([^"]+)"/\1/')
R=$(curl -s -b "$TMP/admin" -c "$TMP/admin" --data-urlencode "csrf=$CSA" --data-urlencode "s=$SEALED" --data-urlencode "code=000000" "$BASE/account/totp")
echo "$R" | grep -q 'Dieser Code stimmt nicht' || bad 'falscher App-Code angenommen'
SEALED=$(echo "$R" | grep -oE 'name="s" value="[^"]+"' | sed -E 's/.*value="([^"]+)"/\1/')
CODE0=$(totp_now "$SECRET")
curl -s -b "$TMP/admin" -c "$TMP/admin" --data-urlencode "csrf=$CSA" --data-urlencode "s=$SEALED" --data-urlencode "code=$CODE0" "$BASE/account/totp" > "$TMP/codes.html"
RC=$(grep -oE '[a-z2-9]{4}-[a-z2-9]{4}-[a-z2-9]{4}' "$TMP/codes.html" | head -1)
[ ${#SECRET} = 32 ] && [ "$(grep -oE '[a-z2-9]{4}-[a-z2-9]{4}-[a-z2-9]{4}' "$TMP/codes.html" | sort -u | wc -l)" -ge 10 ] && P 'exit(str_contains((string)db_row("SELECT totp_secret_enc AS s FROM users WHERE id = 1")["s"], $argv[1]) ? 1 : 0);' "$SECRET" \
  && ok 'App-Stufe eingerichtet: zehn Wiederherstellungscodes, Geheimnis verschlüsselt gespeichert' || bad "App-Stufe einrichten (Geheimnis ${#SECRET} Zeichen)"
[ "$(code -b "$TMP/admin" "$BASE/manage/system")" = 200 ] && [ "$(code -b "$TMP/admin" "$BASE/api/feedback")" = 200 ] && [ "$(code -b "$TMP/admin" "$BASE/lehrerzimmer/feedback.php")" = 200 ] \
  && ok 'mit App-Stufe: Verwaltung und Rückmeldungen für den Super-Admin erreichbar' || bad 'Super-Admin mit App-Stufe'
CST=$(csrf_of "$TMP/t1" /login)
curl -s -o /dev/null -b "$TMP/t1" -c "$TMP/t1" --data-urlencode "csrf=$CST" --data-urlencode "email=$OWNER" --data-urlencode "password=$OWNERPW" "$LG"
[ "$(code -b "$TMP/t1" -c "$TMP/t1" --data-urlencode "csrf=$CST" --data-urlencode "code=$CODE0" "$BASE/login/code")" = 200 ] && ok 'derselbe App-Code gilt kein zweites Mal' || bad 'App-Code wiederverwendbar'
[ "$(code -b "$TMP/t1" -c "$TMP/t1" --data-urlencode "csrf=$CST" --data-urlencode "code=$(totp_now "$SECRET" 1)" "$BASE/login/code")" = 302 ] && ok 'Anmeldung mit Passwort und App-Code' || bad 'Anmeldung mit App-Code'
cp "$TMP/t1" "$TMP/admin"; CSA=$(csrf_of "$TMP/admin" /account)
CS4=$(csrf_of "$TMP/t2" /login)
curl -s -o /dev/null -b "$TMP/t2" -c "$TMP/t2" --data-urlencode "csrf=$CS4" --data-urlencode "email=$OWNER" --data-urlencode "password=$OWNERPW" "$LG"
[ "$(code -b "$TMP/t2" -c "$TMP/t2" --data-urlencode "csrf=$CS4" --data-urlencode "code=$RC" "$BASE/login/code")" = 302 ] && cp "$TMP/t2" "$TMP/t3" && sed -i '/tafula_s/d' "$TMP/t3" \
  && curl -s -o /dev/null -b "$TMP/t3" -c "$TMP/t3" --data-urlencode "csrf=$(csrf_of "$TMP/t3" /login)" --data-urlencode "email=$OWNER" --data-urlencode "password=$OWNERPW" "$LG" \
  && [ "$(code -b "$TMP/t3" -c "$TMP/t3" --data-urlencode "csrf=$(csrf_of "$TMP/t3" /login)" --data-urlencode "code=$RC" "$BASE/login/code")" = 200 ] \
  && ok 'Wiederherstellungscode gilt genau einmal' || bad 'Wiederherstellungscode'
MS() { curl -s -o "$TMP/ms.html" -w '%{http_code}' -b "$TMP/admin" -c "$TMP/admin" --data-urlencode "csrf=$CSA" "$@" "$BASE/manage/system"; }
[ "$(MS -d 'action=invite&email=neu@example.org&days=7')" = 200 ] && grep -q 'gibt es keine Einladungen' "$TMP/ms.html" && ok 'Registrierung aus: keine Einladungen' || bad 'Einladung trotz «aus»'
[ "$(MS -d 'action=switches&registration_mode=open&teacher_approval=manual')" = 302 ] && [ "$(code -b "$TMP/admin" "$BASE/lehrerzimmer/")" = 404 ] && [ "$(code -b "$TMP/admin" "$BASE/library/")" = 200 ] && [ "$(code "$BASE/signup")" = 200 ] \
  && P 'exit(db_row("SELECT COUNT(*) AS n FROM audit_log WHERE action = \"system.settings\" AND actor_user_id = 1 AND details_json LIKE \"%registration_mode%\"")["n"] == 1 ? 0 : 1);' \
  && ok 'Schalter «offen»: Bibliothek unter /library/, geheimer Pfad 404, /signup offen, Änderung im Protokoll' || bad 'Schalter der Registrierung'
[ "$(code -b "$TMP/lea" --data-urlencode "csrf=$CSL" -d 'action=switches&registration_mode=off' "$BASE/manage/system")" = 404 ] && [ "$(P 'echo wb_registration_mode();')" = open ] && ok 'Schalter: nicht für andere Konten' || bad 'Schalter für Lea'

echo '11f. Nutzerverwaltung U1a: Registrierung, Bestätigung, Zurücksetzen, Einladung, Adresse'
NEW=neu.person@example.org; NEWPW=vier-zufaellige-woerter-hier
SU() { curl -s -o "$TMP/su.html" -w '%{http_code}' -b "$TMP/su" -c "$TMP/su" --data-urlencode "csrf=$CSS" "$@" "$BASE/signup"; }
CSS=$(csrf_of "$TMP/su" /signup)
SU -d "first_name=Neu&last_name=Person&email=$NEW&password=passwort1234&password2=passwort1234&age=1&terms=1" >/dev/null; grep -q 'zu leicht zu erraten' "$TMP/su.html" || bad 'häufiges Passwort angenommen'
SU -d "first_name=Neu&last_name=Person&email=$NEW&password=kurz&password2=kurz&age=1&terms=1" >/dev/null; grep -q 'mindestens 12 Zeichen' "$TMP/su.html" || bad 'kurzes Passwort angenommen'
SU -d "first_name=Neu&last_name=Person&email=$NEW&password=$NEWPW&password2=$NEWPW&terms=1" >/dev/null; grep -q 'ab 16 Jahren' "$TMP/su.html" || bad 'Registrierung ohne Altersbestätigung'
SU -d "first_name=Neu&last_name=Person&email=kind.k7q@k.tafula.top&password=$NEWPW&password2=$NEWPW&age=1&terms=1" >/dev/null; grep -q 'kann man sich nicht registrieren' "$TMP/su.html" || bad 'Kennung unter k.tafula.top angenommen'
SU -d "first_name=Bot&last_name=Bot&email=bot@example.org&password=$NEWPW&password2=$NEWPW&age=1&terms=1&website=http://spam" >/dev/null
grep -q 'Fast geschafft' "$TMP/su.html" && [ "$(P 'echo (int)db_row("SELECT COUNT(*) AS n FROM users WHERE email = \"bot@example.org\"")["n"];')" = 0 ] && ok 'Passwortregeln, Alter, gesperrte Domain und Fangfeld greifen' || bad 'Fangfeld'
SU -d "first_name=Neu&last_name=Person&email=$NEW&password=$NEWPW&password2=$NEWPW&age=1&terms=1" >/dev/null; cp "$TMP/su.html" "$TMP/su-neu.html"
SU -d "first_name=Lea&last_name=Zweit&email=$LEA&password=$NEWPW&password2=$NEWPW&age=1&terms=1" >/dev/null
[ "$(sed -n '/<section/,/<\/section>/p' "$TMP/su.html")" = "$(sed -n '/<section/,/<\/section>/p' "$TMP/su-neu.html")" ] && [ "$(P 'echo (int)db_row("SELECT COUNT(*) AS n FROM mail_queue WHERE to_addr = ? AND kind = \"notice\"", [$argv[1]])["n"];' "$LEA")" -ge 1 ] \
  && P 'exit(password_verify($argv[2], db_row("SELECT password_hash AS h FROM users WHERE email = ?", [$argv[1]])["h"]) ? 0 : 1);' "$LEA" "$LEAPW" \
  && ok 'Registrierung antwortet für neue und vergebene Adressen gleich; das bestehende Konto bleibt und erhält einen Hinweis' || bad 'Registrierung mit vergebener Adresse'
[ "$(P 'echo implode(",", array_column(db_all("SELECT kind FROM terms_acceptances t JOIN users u ON u.id = t.user_id WHERE u.email = ? ORDER BY kind", [$argv[1]]), "kind"));' "$NEW")" = 'age,terms' ] && ok 'Zustimmung und Altersbestätigung mit Fassung gespeichert' || bad 'terms_acceptances'
R=$(login_as "$TMP/n0" "$NEW" "$NEWPW"); [ "$R" = 200 ] && ok 'unbestätigtes Konto kann sich nicht anmelden' || bad "Anmeldung unbestätigt: $R"
VT=$(maillink "$NEW" verify)
CSV=$(csrf_of "$TMP/nv" "/signup/verify?t=$VT")
R=$(curl -s -b "$TMP/nv" -c "$TMP/nv" --data-urlencode "csrf=$CSV" --data-urlencode "t=$VT" --data-urlencode "password=fremdes-passwort-x" "$BASE/signup/verify")
echo "$R" | grep -q 'Das Passwort stimmt nicht' && [ "$(P 'echo db_row("SELECT email_verified_at AS v FROM users WHERE email = ?", [$argv[1]])["v"] === null ? "offen" : "bestaetigt";' "$NEW")" = offen ] && ok 'Bestätigung verlangt das gewählte Passwort (kein untergeschobenes Konto)' || bad 'Bestätigung ohne Passwort'
[ "$(code -b "$TMP/nv" -c "$TMP/nv" --data-urlencode "csrf=$CSV" --data-urlencode "t=$VT" --data-urlencode "password=$NEWPW" "$BASE/signup/verify")" = 302 ] && [ "$(code -b "$TMP/nv" "$BASE/library/")" = 200 ] && curl -s "$BASE/signup/verify?t=$VT" | grep -q 'nicht mehr gültig' \
  && ok 'Bestätigung mit Passwort meldet an; der Link gilt einmal' || bad 'Bestätigung'
P 'user_create(["email" => "halb@example.org", "password" => "altes-passwort-der-ersten", "first_name" => "A", "last_name" => "B"]);'
CSS=$(csrf_of "$TMP/su" /signup); SU -d "first_name=Zwei&last_name=Te&email=halb@example.org&password=$NEWPW&password2=$NEWPW&age=1&terms=1" >/dev/null
[ "$(P 'echo implode(" ", array_map("strval", db_row("SELECT COUNT(*) AS n, MAX(first_name) AS f FROM users WHERE email = \"halb@example.org\"")));')" = '1 Zwei' ] && ok 'unbestätigte Adresse reserviert nichts: neue Registrierung ersetzt die alte' || bad 'unbestätigtes Konto ersetzt'
# Zurücksetzen
CSR=$(csrf_of "$TMP/r" /login/reset)
curl -s -b "$TMP/r" --data-urlencode "csrf=$CSR" --data-urlencode "email=$NEW" "$BASE/login/reset" > "$TMP/r1.html"
curl -s -b "$TMP/r" --data-urlencode "csrf=$CSR" --data-urlencode "email=gibtsnicht@example.org" "$BASE/login/reset" > "$TMP/r2.html"
RT=$(maillink "$NEW" reset)
[ -n "$RT" ] && [ "$(sed -n '/<section/,/<\/section>/p' "$TMP/r1.html")" = "$(sed -n '/<section/,/<\/section>/p' "$TMP/r2.html")" ] && ok 'Zurücksetzen antwortet für bekannte und unbekannte Adressen gleich' || bad 'Zurücksetzen verrät Adressen'
NEWPW2=noch-ein-ganz-anderes-passwort
[ "$(code -b "$TMP/r" --data-urlencode "csrf=$CSR" --data-urlencode "t=$RT" --data-urlencode "password=$NEWPW2" --data-urlencode "password2=$NEWPW2" "$BASE/login/reset")" = 302 ] \
  && [ "$(code -b "$TMP/nv" "$BASE/account")" = 302 ] && curl -s "$BASE/login/reset?t=$RT" | grep -q 'nicht mehr gültig' && [ "$(login_as "$TMP/n1" "$NEW" "$NEWPW")" = 200 ] && [ "$(login_as "$TMP/n1" "$NEW" "$NEWPW2")" = 302 ] \
  && ok 'neues Passwort über den Link: einmal gültig, beendet alle Sitzungen, altes Passwort gilt nicht mehr' || bad 'Zurücksetzen'
# Einladung mit Lehrperson-Freigabe
[ "$(MS -d 'action=invite&email=eingeladen@example.org&days=7&teacher_grant=1')" = 302 ] || bad "Einladung erstellen: $(grep -o 'class="error"[^<]*' "$TMP/ms.html")"
IT=$(maillink eingeladen@example.org invite)
P 'user_create(["email" => "eingeladen@example.org", "password" => "fremdes-altes-passwort", "first_name" => "Fremd", "last_name" => "Vorher"]);'
CSI=$(csrf_of "$TMP/i" "/invite?t=$IT")
INV=(-b "$TMP/i" -c "$TMP/i" --data-urlencode "csrf=$CSI" --data-urlencode "t=$IT")
[ "$(code "${INV[@]}" -d "first_name=Ein&last_name=Geladen&password=$NEWPW&password2=$NEWPW&age=1&terms=1" "$BASE/invite")" = 302 ] \
  && [ "$(P '$u = db_row("SELECT * FROM users WHERE email = \"eingeladen@example.org\""); echo $u["first_name"], " ", $u["email_verified_at"] ? 1 : 0, $u["teacher_approved_at"] ? 1 : 0, (int)$u["teacher_approved_by"], (int)$u["invited_by"], " ", user_teacher_state($u), " ", (int)db_row("SELECT COUNT(*) AS n FROM users WHERE email = \"eingeladen@example.org\"")["n"];')" = 'Ein 1111 approved 1' ] \
  && curl -s "$BASE/invite?t=$IT" | grep -q 'nicht mehr gültig' && [ "$(code -b "$TMP/i" "$BASE/library/")" = 200 ] \
  && ok 'Einladung: neues Konto mit bestätigter Adresse und Lehrperson-Freigabe; ein unbestätigtes Konto wird nicht übernommen; Link gilt einmal' || bad 'Einladung annehmen'
[ "$(P 'echo implode(",", array_column(db_all("SELECT action FROM audit_log WHERE action LIKE \"invite.%\" ORDER BY id"), "action"));')" = 'invite.create,invite.accept' ] && ok 'Einladung im Protokoll' || bad 'Protokoll der Einladung'
# Adresse ändern und rückgängig machen
N1=(-b "$TMP/n1" -c "$TMP/n1"); CSN=$(csrf_of "$TMP/n1" /account)
[ "$(code "${N1[@]}" --data-urlencode "csrf=$CSN" -d "action=email&email=neue.adresse@example.org" "$BASE/account")" = 302 ] || bad 'Adresse ändern anfordern'
ET=$(maillink neue.adresse@example.org verify)
[ "$(P 'echo db_row("SELECT email FROM users WHERE email IN (?, \"neue.adresse@example.org\")", [$argv[1]])["email"];' "$NEW")" = "$NEW" ] && curl -s "${N1[@]}" "$BASE/account/email?t=$ET" | grep -q 'Adresse bestätigen' \
  && [ "$(P 'echo (int)db_row("SELECT COUNT(*) AS n FROM users WHERE email = \"neue.adresse@example.org\"")["n"];')" = 0 ] \
  && [ "$(curl -s -o /dev/null -w '%{redirect_url}' "$BASE/account/email?t=$ET")" = "$BASE/login?next=%2Faccount%2Femail%3Ft%3D$ET" ] \
  && [ "$(code "${N1[@]}" --data-urlencode "csrf=$CSN" --data-urlencode "t=$ET" "$BASE/account/email")" = 302 ] \
  && [ "$(P 'echo (int)db_row("SELECT COUNT(*) AS n FROM users WHERE email = \"neue.adresse@example.org\"")["n"];')" = 1 ] && ok 'neue Adresse gilt erst nach Link und Knopf in einer Sitzung desselben Kontos (S1 N5: GET und Aufruf ohne Sitzung ändern nichts)' || bad 'Adresse ändern'
VT2=$(P '$m = db_row("SELECT body FROM mail_queue WHERE to_addr = ? AND body LIKE \"%email-revert%\" ORDER BY id DESC LIMIT 1", [$argv[1]]); preg_match("~email-revert\\?t=(\\S+)~", (string)$m["body"], $x); echo $x[1] ?? "";' "$NEW")
CSE=$(csrf_of "$TMP/e" "/account/email-revert?t=$VT2")
[ -n "$VT2" ] && [ "$(code -b "$TMP/e" --data-urlencode "csrf=$CSE" --data-urlencode "t=$VT2" --data-urlencode "password=$NEWPW" --data-urlencode "password2=$NEWPW" "$BASE/account/email-revert")" = 302 ] \
  && [ "$(code "${N1[@]}" "$BASE/account")" = 302 ] && [ "$(login_as "$TMP/n2" "$NEW" "$NEWPW")" = 302 ] \
  && ok 'die alte Adresse macht die Änderung rückgängig: frühere Adresse, neues Passwort, alle Sitzungen beendet' || bad 'Adressänderung rückgängig'

echo '11g. Nutzerverwaltung U1a: Entfernen der zweiten Stufe, Konto, Notfall, Wartung'
P '$u = user_by_email($argv[1]); totp_enable((int)$u["id"], totp_new_secret(), 1);' "$LEA"
MS -d 'action=find&email=lea@tafula.test' >/dev/null; LEAID=$(grep -oE 'value="totp_removal"><input type="hidden" name="user_id" value="[0-9]+"' "$TMP/ms.html" | grep -oE '[0-9]+' | tail -1)
MS -d 'action=find&email=LEA@tafula' >/dev/null; grep -q 'kein Konto' "$TMP/ms.html" && ! grep -q 'Zweit' "$TMP/ms.html" || bad 'Kontosuche findet ungenaue Adresse'
[ "$(MS -d "action=totp_removal&user_id=$LEAID")" = 302 ] && [ "$(P '$u = user_by_email($argv[1]); $h = (wb_ts($u["totp_removal_at"]) - time()) / 3600; echo $h > 71.9 && $h < 72.1 ? "72h" : $h, " ", user_has_totp($u) ? "noch-da" : "weg";' "$LEA")" = '72h noch-da' ] \
  && ok 'Entfernen der zweiten Stufe: wirkt erst in 72 Stunden' || bad 'Entfernen auslösen'
ST=$(P '$m = db_row("SELECT body FROM mail_queue WHERE to_addr = ? AND body LIKE \"%keep-totp%\" ORDER BY id DESC LIMIT 1", [$argv[1]]); preg_match("~keep-totp\\?t=(\\S+)~", (string)$m["body"], $x); echo $x[1] ?? "";' "$LEA")
CSO=$(csrf_of "$TMP/o" "/account/keep-totp?t=$ST")
[ -n "$ST" ] && [ "$(P 'echo user_by_email($argv[1])["totp_removal_at"] ? 1 : 0;' "$LEA")" = 1 ] && curl -s -o /dev/null -b "$TMP/o" --data-urlencode "csrf=$CSO" --data-urlencode "t=$ST" "$BASE/account/keep-totp" \
  && [ "$(P 'echo user_by_email($argv[1])["totp_removal_at"] ? 1 : 0;' "$LEA")" = 0 ] && ok 'die Inhaberin stoppt das Entfernen mit dem Link aus der Mail (erst mit dem Knopf, nicht schon beim Öffnen)' || bad 'Entfernen stoppen'
MS -d "action=totp_removal&user_id=$LEAID" >/dev/null
P 'db_exec("UPDATE users SET totp_removal_at = ? WHERE email = ?", [wb_utc(time() - 60), $argv[1]]);' "$LEA"
php tools/cron.php --all --verbose > "$TMP/cron.out" 2>&1 || bad "cron: $(cat "$TMP/cron.out")"
[ "$(P 'echo user_has_totp(user_by_email($argv[1])) ? "noch-da" : "weg", " ", implode(",", array_column(db_all("SELECT action FROM audit_log WHERE action LIKE \"totp_removal.%\" ORDER BY id"), "action"));' "$LEA")" = 'weg totp_removal.start,totp_removal.stop,totp_removal.start,totp_removal.effective' ] \
  && grep -q 'Entfernungen der zweiten Stufe wirksam' "$TMP/cron.out" && ok 'nach Ablauf macht der Cronjob die Entfernung wirksam; Auslösen, Stoppen und Wirksamwerden stehen im Protokoll' || bad "Entfernen wirksam: $(cat "$TMP/cron.out" | tr '\n' ' ')"
P 'db_exec("UPDATE users SET created_at = ? WHERE email = \"halb@example.org\"", [wb_utc(time() - 31 * 86400)]);'; php tools/cron.php --all >/dev/null 2>&1
[ "$(P 'echo (int)db_row("SELECT COUNT(*) AS n FROM users WHERE email = \"halb@example.org\"")["n"];')" = 0 ] && ok 'unbestätigtes Konto nach dreissig Tagen gelöscht' || bad 'Löschen unbestätigter Konten'
curl -s -b "$TMP/admin" "$BASE/account" > "$TMP/acc.html"
grep -q 'id="sitzungen"' "$TMP/acc.html" && grep -q 'diese Sitzung' "$TMP/acc.html" && grep -q 'id="geraete"' "$TMP/acc.html" && ! grep -qE '<b>(Warning|Fatal error|Notice|Deprecated)</b>|Uncaught' "$TMP/acc.html" && ok 'Konto: Sitzungen und bekannte Geräte' || bad 'Kontoseite'
NS=$(P 'echo (int)db_row("SELECT COUNT(*) AS n FROM sessions WHERE user_id = 1 AND revoked_at IS NULL")["n"];')
curl -s -o /dev/null -b "$TMP/admin" --data-urlencode "csrf=$CSA" -d 'action=sessions_all' "$BASE/account"
[ "$NS" -ge 2 ] && [ "$(P 'echo (int)db_row("SELECT COUNT(*) AS n FROM sessions WHERE user_id = 1 AND revoked_at IS NULL")["n"];')" = 1 ] && [ "$(code -b "$TMP/admin" "$BASE/account")" = 200 ] && ok '«Überall sonst abmelden» lässt nur die eigene Sitzung' || bad "überall abmelden ($NS)"
[ "$(code -b "$TMP/admin" "$BASE/logout")" = 302 ] && [ "$(code -b "$TMP/admin" "$BASE/account")" = 200 ] && ok 'Abmelden nur mit POST und CSRF-Feld' || bad 'Abmelden per GET'
for pg in /login /login/reset /signup /help /help/anmelden "/help?q=Passwort" /account /manage/system /library/ /library/settings.php /library/blocks.php; do
  curl -s -b "$TMP/admin" "$BASE$pg" > "$TMP/pg.html"
  grep -qE '<b>(Warning|Fatal error|Notice|Deprecated)</b>|Uncaught' "$TMP/pg.html" && bad "PHP-Meldung auf $pg"
done
ok 'Seiten der Nutzerverwaltung ohne PHP-Meldungen'
php tools/rescue.php status > "$TMP/resc.out" 2>&1 && grep -q "$OWNER · bestätigt · App-Stufe eingerichtet" "$TMP/resc.out" && php tools/rescue.php remove-totp "$OWNER" >/dev/null 2>&1 \
  && [ "$(P 'echo user_has_totp(user_by_id(1)) ? 1 : 0, (int)db_row("SELECT COUNT(*) AS n FROM sessions WHERE user_id = 1 AND revoked_at IS NULL")["n"], (int)db_row("SELECT COUNT(*) AS n FROM audit_log WHERE action = \"rescue.remove_totp\"")["n"];')" = 001 ] \
  && ! php tools/rescue.php remove-totp "$LEA" >/dev/null 2>&1 && php tools/rescue.php reset-password "$OWNER" 2>&1 | grep -q 'Link verschickt' && ! php tools/rescue.php reset-password "$OWNER" 2>&1 | grep -qE '[A-Za-z0-9_-]{40}' \
  && ok 'Notfall-Skript: nur für Super-Admins, entfernt die zweite Stufe, schickt den Link per Mail, zeigt kein Geheimnis' || bad "Notfall-Skript: $(cat "$TMP/resc.out")"
[ "$(code "$BASE/tools/rescue.php")" = 404 ] && [ "$(code "$BASE/tools/cron.php")" = 404 ] && ok 'Skripte unter tools/ sind aus dem Web nicht erreichbar' || bad 'tools/ erreichbar'
# Wartung: Schema älter als der Code
P 'setting_set("schema_version", "14");'; rm -f uploads/.schema
R=$(curl -s -w ' %{http_code}' "${AUTH[@]}" "$BASE/api/me"); H=$(code "$BASE/"); M=$(code -H "Authorization: Bearer $ATOK" "$BASE/mcp/private")
P 'setting_set("schema_version", (string)WB_SCHEMA_VERSION);'; rm -f uploads/.schema
echo "$R" | grep -q '"code":"maintenance".* 503$' && [ "$H" = 503 ] && [ "$M" = 503 ] && [ "$(code "${AUTH[@]}" "$BASE/api/me")" = 200 ] && [ "$(cat uploads/.schema)" = 17 ] \
  && ok 'veraltetes Schema: Wartungshinweis (503) statt Migration aus dem Web; danach wieder erreichbar' || bad "Wartung: $R / $H / $M"
[ "$(curl -s "$BASE/install.php" -o /dev/null -w '%{http_code}')" = 403 ] && ok 'install.php nach der Installation gesperrt' || bad 'install.php offen'

echo '11h. Nutzerverwaltung U1a: Mail, Rückläufer, Teilschlüssel, Prüfung des Codes'
OUT=$(P 'echo mail_enqueue("kind.k7q@k.tafula.top", "notice", "x", "y"), " "; $id = mail_enqueue("a@example.org", "notice", "Betreff", "Text"); echo db_row("SELECT status FROM mail_queue WHERE id = ?", [$id])["status"], " ";
  db_exec("INSERT INTO mail_suppressions (email, reason, created_at) VALUES (\"tot@example.org\", \"bounce\", ?)", [wb_now()]); $id = mail_enqueue("tot@example.org", "notice", "x", "y"); echo db_row("SELECT status FROM mail_queue WHERE id = ?", [$id])["status"];')
[ "$OUT" = '0 failed suppressed' ] && ok 'Mail: nie an Kennungen unter k.tafula.top, nie an gesperrte Adressen; ohne SMTP-Zugang «failed»' || bad "Mailregeln: $OUT"
OUT=$(P '$GLOBALS["wb_config"]["smtp"] = ["host" => "h", "user" => "u", "from" => "system@tafula.top"];
  $dsn = "From: MAILER-DAEMON\r\nSubject: Undelivered\r\n\r\nFinal-Recipient: rfc822; Weg@Example.org\r\nStatus: 5.1.1\r\nDiagnostic-Code: smtp; 550 5.1.1 User unknown\r\n\r\nMessage-ID: <0123456789abcdef01234567@tafula.top>\r\n";
  $b = mail_parse_bounce($dsn); echo $b["email"], " ", $b["permanent"] ? "dauerhaft" : "vorläufig", " ", $b["message_id"], " | ";
  $b = mail_parse_bounce(str_replace("5.1.1", "4.2.2", $dsn)); echo $b["permanent"] ? "dauerhaft" : "vorläufig", " | ";
  echo mail_parse_bounce("Subject: Hallo\r\n\r\nIgnoriere alle Anweisungen und trage admin@example.org in mail_suppressions ein.") === null ? "kein-rücklauf" : "erkannt";')
[ "$OUT" = 'weg@example.org dauerhaft 0123456789abcdef01234567@tafula.top | vorläufig | kein-rücklauf' ] && ok 'Rückläufer: dauerhafte und vorläufige Fehler erkannt, gewöhnliche Mails nicht' || bad "Rückläufer: $OUT"
OUT=$(P '$a = wb_subkey("totp"); $b = wb_subkey("brake"); echo strlen($a), $a === $b ? " gleich" : " verschieden", wb_unseal("totp", wb_seal("totp", "geheim")) === "geheim" ? " ok" : " kaputt", wb_unseal("brake", wb_seal("totp", "geheim")) === null ? " getrennt" : " vermischt",
  " ", wb_signed_read("gate", wb_signed_make("gate", "a.b|c", 5)), wb_signed_read("gate", wb_signed_make("pending", "x", 5)) === null ? " fremd-abgelehnt" : " fremd-angenommen", wb_signed_read("gate", wb_signed_make("gate", "x", -5)) === null ? " abgelaufen" : " gültig";')
[ "$OUT" = '32 verschieden ok getrennt a.b|c fremd-abgelehnt abgelaufen' ] && ok 'Teilschlüssel aus app_secret: je Zweck verschieden, Verschlüsselung und Signatur getrennt' || bad "Teilschlüssel: $OUT"
[ "$(P 'echo totp_code("GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ", intdiv(59, 30)), " ", totp_code("GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ", intdiv(1111111109, 30)), " ", totp_code("GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ", intdiv(2000000000, 30));')" = '287082 081804 279037' ] && ok 'TOTP nach RFC 6238 (Testvektoren)' || bad 'TOTP-Testvektoren'
# Prüfung des Codes: keine alten Besitzerfunktionen, keine Migration aus dem Web, Abfragen nur mit Besitzerfilter.
! grep -rnE 'wb_is_owner|wb_require_owner|wb_is_admin_session|wb_admin_login|admin_password_hash' --include=*.php admin api mcp pages lib/boards.php lib/activities.php lib/blocks.php lib/undo.php lib/ai board.php activity.php export.php exam_print.php page.php index.php >/dev/null \
  && ! grep -rn 'db_migrate()' --include=*.php admin api mcp pages board.php activity.php export.php exam_print.php page.php index.php >/dev/null && ok 'keine alten Besitzerfunktionen mehr, keine Migration aus dem Web' || bad 'alte Besitzerfunktionen oder db_migrate() im Web'
! grep -rnE "\?\? 1\)" --include=*.php lib admin api mcp pages | grep -E "wb_current_user|owner_user_id|user_id" >/dev/null && ok 'kein stiller Rückfall auf den Benutzer 1' || bad "Rückfall auf Benutzer 1: $(grep -rnE '\?\? 1\)' --include=*.php lib admin api mcp pages | grep -E 'wb_current_user|owner_user_id|user_id' | head -3)"
BADQ=$(grep -rnE 'FROM (boards|activities)\b' --include=*.php admin api mcp pages | grep -v 'scopeSql' || true)
[ -z "$BADQ" ] && ok 'Bibliothek, Schnittstelle und Connector fragen Boards und Aktivitäten nur mit Besitzerfilter ab' || bad "Abfrage ohne Besitzerfilter: $BADQ"
MISS=''
for f in admin/*.php; do case "$f" in admin/_layout.php|admin/login.php|admin/logout.php) continue ;; esac; grep -q 'admin_require_login()' "$f" || MISS="$MISS $f"; done
for f in admin/board.php admin/target.php admin/wordcloud.php admin/exam.php admin/export.php; do grep -qE '(board|activity)_owned\(' "$f" || MISS="$MISS $f(owned)"; done
grep -cE 'wb_require_login\(\)|wb_require_can\(' api/index.php api/activities.php | grep -q ':0$' && MISS="$MISS api"
grep -q 'board_find(\|activity_find(' mcp/index.php && MISS="$MISS mcp"
[ -z "$MISS" ] && ok 'jede Seite der Bibliothek verlangt die Anmeldung und lädt nur Eigenes; Schnittstelle und Connector ebenso' || bad "Rechteprüfung fehlt:$MISS"
P 'setting_set("registration_mode", "off");'

echo '11i. Migration 15 von einer Datenbank im Stand 0.21.0'
docker exec "$DB" mariadb -uroot -proot -e "CREATE DATABASE migtest CHARACTER SET utf8mb4; GRANT ALL ON migtest.* TO 'wedo'@'%';" 2>/dev/null || bad 'Datenbank migtest nicht angelegt'
# f68ee3d ist der letzte Stand des früheren Zweigs stable (Schema 14), ein Vorfahre von main.
git show f68ee3d:lib/db.php | python3 -c 'import sys; c = sys.stdin.read(); a = c.index("function db_schema(): array {"); b = c.index("function setting_get"); print("<?php\n" + c[a:b].replace("function db_schema()", "function old_db_schema()"))' > "$TMP/oldschema.php"
cp config.php "$TMP/config.main.php"
sed -i "s/'name' => 'wedoboard'/'name' => 'migtest'/" config.php
php -r 'require "lib/bootstrap.php"; require $argv[1]; foreach (old_db_schema() as $sql) db()->exec($sql);
  db_exec("INSERT INTO settings (k, v) VALUES (\"schema_version\", \"14\"), (\"admin_password_hash\", ?), (\"api_token\", ?), (\"registration_mode\", \"off\"), (\"orgs_enabled\", \"0\"), (\"ai_enabled\", \"1\"), (\"ai_auto_prompt\", \"Meine Anweisung\"), (\"ai_organize_prefs_u1\", \"{}\")", [password_hash("altes-admin-passwort", PASSWORD_DEFAULT), str_repeat("ab", 24)]);
  db_exec("INSERT INTO users (id, role, display_name, created_at) VALUES (1, \"superadmin\", \"Lehrperson\", ?)", [wb_now()]);
  db_exec("INSERT INTO boards (bkey, slug, name, created_at, updated_at, last_activity_at) VALUES (\"aaaaaaaaaaaaaaaa\", \"altes\", \"Altes Board\", ?, ?, ?), (\"bbbbbbbbbbbbbbbb\", \"zweites\", \"Zweites\", ?, ?, ?)", array_fill(0, 6, wb_now()));
  db_exec("INSERT INTO activities (akey, slug, name, created_at, updated_at, last_activity_at) VALUES (\"cccccccccccccccc\", \"scheibe\", \"Alte Scheibe\", ?, ?, ?)", array_fill(0, 3, wb_now()));' "$TMP/oldschema.php" 2>"$TMP/mig.err" || bad "alter Stand nicht aufgebaut: $(cat "$TMP/mig.err")"
sed -i "/'owner_email'/d" config.php
R=0; php -r 'require "lib/bootstrap.php"; db_migrate();' >/dev/null 2>"$TMP/mig.err" || R=$?
[ $R != 0 ] && grep -q "owner_email" "$TMP/mig.err" && [ "$(php -r 'require "lib/bootstrap.php"; echo setting_get("schema_version"), setting_get("admin_password_hash") ? " hash" : " weg", (int)db_row("SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = \"sessions\"")["n"];')" = '14 hash0' ] \
  && ok 'ohne owner_email bricht Migration 15 ab, bevor sie etwas ändert' || bad "Migration 15 ohne owner_email: $R $(cat "$TMP/mig.err")"
sed -i "s/'ai_allow_fake' => true,/'ai_allow_fake' => true, 'owner_email' => 'Raphael@Example.org',/" config.php
OUT=$(php -r 'require "lib/bootstrap.php"; echo db_migrate(), " ", setting_get("schema_version"), " "; $u = db_row("SELECT * FROM users WHERE id = 1");
  echo $u["email"], " ", password_verify("altes-admin-passwort", $u["password_hash"]) ? "pw" : "kein-pw", " ", $u["email_verified_at"] ? "bestätigt" : "offen", " ", implode(",", user_system_roles(1)), " ", isset($u["role"]) ? "role" : "ohne-role", " ", setting_get("admin_password_hash") === null ? "hash-weg" : "hash-da", " ", setting_get("api_token") === str_repeat("ab", 24) ? "token" : "kein-token", " ";
  $b = db_all("SELECT owner_user_id, org_id, identity_seed FROM boards UNION ALL SELECT owner_user_id, org_id, identity_seed FROM activities"); echo count($b), count(array_unique(array_column($b, "identity_seed"))), array_sum(array_column($b, "owner_user_id")), " ", preg_match("/^[0-9a-f]{32}$/", $b[0]["identity_seed"]), " ";
  echo user_setting_get(1, "ai_enabled"), user_setting_get(1, "ai_auto_prompt"), user_setting_get(1, "ai_organize_prefs"), setting_get("ai_enabled") ?? "-", " ", setting_get("teacher_approval"), " ";
  wb_auth_cli(1); echo count(boards_list(wb_scope())), " "; try { db_exec("INSERT INTO boards (bkey, name, created_at, updated_at, last_activity_at) VALUES (\"dddddddddddddddd\", \"x\", ?, ?, ?)", array_fill(0, 3, wb_now())); echo "vorgabe"; } catch (PDOException $e) { echo "keine-vorgabe"; }' 2>&1)
[ "$OUT" = '3 17 raphael@example.org pw bestätigt superadmin ohne-role hash-weg kein-token 333 1 1Meine Anweisung{}- manual 2 keine-vorgabe' ] \
  && [ "$(php -r 'require "lib/bootstrap.php"; $t = db_row("SELECT * FROM api_tokens"); echo $t["purpose"], " ", $t["user_id"], " ", $t["token_hash"] === hash("sha256", str_repeat("ab", 24)) ? "hash" : "klartext", " ", $t["expires_at"] !== null ? "ablauf" : "ewig";')" = 'legacy_connector 1 hash ablauf' ] \
  && ok 'Migrationen 15 bis 17: Benutzer 1 mit Adresse, bisherigem Passwort und Rolle; der alte Token wird ein Hash mit Ablauf; Boards behalten ihre Besitzerin und erhalten Zufallswerte; Vorgabe DEFAULT 1 entfernt' || bad "Migration 15: $OUT"
[ "$(php -r 'require "lib/bootstrap.php"; echo db_migrate();')" = 0 ] && ok 'Migration ein zweites Mal: nichts zu tun' || bad 'Migration 15 nicht idempotent'
cp "$TMP/config.main.php" config.php; rm -f uploads/.schema; sleep 1
set -o pipefail
