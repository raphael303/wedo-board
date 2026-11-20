# Teil von tools/test.sh (wird dort eingebunden): Nutzerverwaltung U1a auf dem Testbestand.
# Stand beim Einstieg: config.php mit instance = test, Datenbank seedtest mit dem Satz «test» und Marker.

# «curl | grep -q» bricht curl ab, sobald grep fündig ist; mit pipefail gälte das als Fehler.
set +o pipefail
echo '12b. Nutzerverwaltung U1a: Testbestand, Rechte-Matrix, Testhilfen'
php tools/test_matrix.php > "$TMP/matrix.out" 2>&1 && ok "Rechte-Matrix gegen den Testbestand: $(tail -1 "$TMP/matrix.out" | sed 's/^Matrix: //')" || bad "Rechte-Matrix: $(head -8 "$TMP/matrix.out" | tr '\n' ' ') … $(tail -1 "$TMP/matrix.out")"
T() { php -r 'require "lib/bootstrap.php"; '"$1" "${@:2}"; }
[ "$(curl -s -o /dev/null -w '%{redirect_url}' "$BASE/login")" = "$BASE/test?next=%2Flogin" ] && [ "$(curl -s -o /dev/null -w '%{redirect_url}' "$BASE/signup")" = "$BASE/test?next=%2Fsignup" ] && curl -s "$BASE/test" | grep -q 'name="code"' && ! curl -s "$BASE/test" | grep -q 'sara.super' \
  && [ "$(code "$BASE/test-spalten")" = 200 ] && ok 'Testinstanz: Anmeldung und Registrierung verlangen den Zugangscode, Gästeseiten nicht' || bad 'Zugangscode der Testinstanz'
T 'gate_set_code(1, "zugang-eins-zwei"); gate_set_code(2, "zweiter-code-drei"); db_exec("DELETE FROM rate_limits");'
G=(-b "$TMP/gate" -c "$TMP/gate")
CSG=$(csrf_of "$TMP/gate" /test)
[ "$(code "${G[@]}" --data-urlencode "csrf=$CSG" -d 'action=gate&code=falsch' "$BASE/test")" = 200 ] && [ "$(code "${G[@]}" --data-urlencode "csrf=$CSG" -d 'action=gate&code=zugang-eins-zwei&next=/login' "$BASE/test")" = 302 ] && [ "$(code "${G[@]}" "$BASE/login")" = 200 ] \
  && ok 'Zugangscode: falscher abgelehnt, richtiger 30 Tage gemerkt' || bad 'Zugangscode eingeben'
curl -s "${G[@]}" "$BASE/test" > "$TMP/roles.html"
for r in sara.super adam.admin lisa.lizenz viktor.vorfuehrung lea.privat tom.lehrer nora.zwei anna.antrag uwe.unbestaetigt; do grep -q "$r@tafula.test" "$TMP/roles.html" || bad "Testrolle $r fehlt"; done
uid_of() { T 'echo (int)user_by_email($argv[1] . "@tafula.test")["id"];' "$1"; }
[ "$(code "${G[@]}" --data-urlencode "csrf=$CSG" -d "action=login&user_id=1" "$BASE/test")" = 200 ] && [ "$(code "${G[@]}" --data-urlencode "csrf=$CSG" -d "action=login&user_id=$(uid_of sara.super)" "$BASE/test")" = 200 ] \
  && ok 'Anmeldung auf einen Klick: nie für das echte Konto der Besitzerin, Systemrollen nur mit dem zweiten Code' || bad 'Testrollen ohne zweiten Code'
cp "$TMP/gate" "$TMP/lea-t"
[ "$(curl -s -o /dev/null -w '%{redirect_url}' -b "$TMP/lea-t" -c "$TMP/lea-t" --data-urlencode "csrf=$CSG" -d "action=login&user_id=$(uid_of lea.privat)" "$BASE/test")" = "$BASE/library/" ] || bad 'Testrolle Lea anmelden'
curl -s -b "$TMP/lea-t" "$BASE/library/" > "$TMP/lib.html"
grep -q 'Leas privates Board' "$TMP/lib.html" && ! grep -q 'Spalten mit Bündeln\|Toms Board\|Noras' "$TMP/lib.html" && [ "$(code -b "$TMP/lea-t" "$BASE/manage/system")" = 404 ] && [ "$(code -b "$TMP/lea-t" "$BASE/library/board.php?key=$(T 'echo board_by_slug("test-spalten")["bkey"];')")" = 404 ] \
  && [ "$(T 'echo (int)db_row("SELECT COUNT(*) AS n FROM audit_log WHERE action = \"test.login\"")["n"], db_row("SELECT kind FROM sessions ORDER BY id DESC LIMIT 1")["kind"];')" = 1test ] \
  && ok 'Testrolle Lea: sieht nur ihre Bibliothek; die Anmeldung steht im Protokoll' || bad 'Testrolle Lea'
curl -s -b "$TMP/lea-t" "$BASE/api/a/$(T 'echo activity_by_slug("test-lea-pruefung")["akey"];')" | jq -e '.me.owner == true' >/dev/null \
  && ! curl -s "${G[@]}" "$BASE/api/a/$(T 'echo activity_by_slug("test-lea-pruefung")["akey"];')/state" | grep -q 'LEAS-GEHEIME-LOESUNG' && ok 'Leas Prüfung: Besitzeransicht nur für Lea, Lösung nicht für andere' || bad 'Leas Prüfung'
# Arbeitsbereiche mit der Schule als Attrappe
cp "$TMP/gate" "$TMP/nora"
curl -s -o /dev/null -b "$TMP/nora" -c "$TMP/nora" --data-urlencode "csrf=$CSG" -d "action=login&user_id=$(uid_of nora.zwei)" "$BASE/test"
CSN2=$(csrf_of "$TMP/nora" /account)
SCHOOL=$(T 'echo (int)db_row("SELECT id FROM orgs WHERE type = \"school\"")["id"];')
curl -s -b "$TMP/nora" "$BASE/library/" > "$TMP/n-priv.html"
curl -s -o /dev/null -b "$TMP/nora" --data-urlencode "csrf=$CSN2" -d "org=$SCHOOL" "$BASE/account/workspace"
curl -s -b "$TMP/nora" "$BASE/library/" > "$TMP/n-school.html"
grep -q 'Noras privates Board' "$TMP/n-priv.html" && ! grep -q 'Noras Board in der Schule' "$TMP/n-priv.html" && grep -q 'Noras Board in der Schule' "$TMP/n-school.html" && ! grep -q 'Noras privates Board\|Toms Board' "$TMP/n-school.html" && grep -q 'id="ws-select"' "$TMP/n-school.html" \
  && [ "$(curl -s -b "$TMP/nora" "$BASE/api/me" | jq -r '.workspace.name')" = 'Testschule (Attrappe)' ] \
  && ok 'Arbeitsbereich: Bibliothek zeigt nur das Eigene im gewählten Bereich, auch nicht die Boards einer Kollegin' || bad 'Arbeitsbereiche'
NB=$(curl -s -b "$TMP/nora" -H "X-CSRF: $CSN2" "${JH[@]}" -d '{"name":"Neu in der Schule","note":"Test","tags":"test"}' "$BASE/api/boards" | jq -r '.board.key')
[ "$(T 'echo (int)db_row("SELECT org_id FROM boards WHERE bkey = ?", [$argv[1]])["org_id"];' "$NB")" = "$SCHOOL" ] && [ "$(code -b "$TMP/lea-t" --data-urlencode "csrf=$(csrf_of "$TMP/lea-t" /account)" -d "org=$SCHOOL" "$BASE/account/workspace")" = 302 ] && [ "$(curl -s -b "$TMP/lea-t" "$BASE/api/me" | jq -r '.workspace.org_id')" = null ] \
  && ok 'ein neues Board liegt im gewählten Arbeitsbereich; wer der Schule nicht angehört, kann nicht dorthin wechseln' || bad 'Board im Arbeitsbereich'
# Systemrollen: zweiter Code, Verwaltung
[ "$(code "${G[@]}" --data-urlencode "csrf=$CSG" -d 'action=gate2&code=zweiter-code-drei' "$BASE/test")" = 302 ] || bad 'zweiter Code'
cp "$TMP/gate" "$TMP/sara"; cp "$TMP/gate" "$TMP/lisa"
[ "$(curl -s -o /dev/null -w '%{redirect_url}' -b "$TMP/sara" -c "$TMP/sara" --data-urlencode "csrf=$CSG" -d "action=login&user_id=$(uid_of sara.super)" "$BASE/test")" = "$BASE/manage/system" ] || bad 'Testrolle Super-Admin'
curl -s -o /dev/null -b "$TMP/lisa" -c "$TMP/lisa" --data-urlencode "csrf=$CSG" -d "action=login&user_id=$(uid_of lisa.lizenz)" "$BASE/test"
curl -s -b "$TMP/sara" "$BASE/manage/system" > "$TMP/m-sa.html"; curl -s -b "$TMP/lisa" "$BASE/manage/system" > "$TMP/m-lv.html"
grep -q 'id="schalter"' "$TMP/m-sa.html" && grep -q 'id="einladungen"' "$TMP/m-sa.html" && grep -q 'id="protokoll"' "$TMP/m-sa.html" && grep -q 'id="test"' "$TMP/m-sa.html" && grep -q 'eingeladen@tafula.test' "$TMP/m-sa.html" \
  && grep -q 'id="konto"' "$TMP/m-lv.html" && ! grep -q 'id="schalter"\|id="einladungen"\|id="protokoll"\|id="rollen"\|id="test"' "$TMP/m-lv.html" && ! grep -qE '<b>(Warning|Fatal error|Notice|Deprecated)</b>|Uncaught' "$TMP/m-sa.html" "$TMP/m-lv.html" \
  && ok 'Verwaltung: der Super-Admin sieht alles, die Lizenzverwaltung nur die Kontosuche' || bad 'Verwaltung nach Rolle'
CSSA=$(csrf_of "$TMP/sara" /account)
[ "$(code -b "$TMP/lisa" --data-urlencode "csrf=$(csrf_of "$TMP/lisa" /account)" -d 'action=switches&registration_mode=off' "$BASE/manage/system")" = 200 ] && [ "$(T 'echo wb_registration_mode();')" = open ] \
  && [ "$(code -b "$TMP/lisa" --data-urlencode "csrf=$(csrf_of "$TMP/lisa" /account)" -d "action=role_grant&role=admin&email=lea.privat@tafula.test" "$BASE/manage/system")" = 200 ] && [ "$(T 'echo count(user_system_roles((int)user_by_email("lea.privat@tafula.test")["id"]));')" = 0 ] \
  && ok 'Lizenzverwaltung kann weder Schalter stellen noch Rollen vergeben' || bad 'Rechte der Lizenzverwaltung'
[ "$(code -b "$TMP/sara" --data-urlencode "csrf=$CSSA" -d "action=role_grant&role=presenter&email=lea.privat@tafula.test" "$BASE/manage/system")" = 302 ] && [ "$(T 'echo implode(",", user_system_roles((int)user_by_email("lea.privat@tafula.test")["id"]));')" = presenter ] \
  && [ "$(code -b "$TMP/sara" --data-urlencode "csrf=$CSSA" -d "action=role_revoke&role=presenter&user_id=$(uid_of lea.privat)" "$BASE/manage/system")" = 302 ] && [ "$(T 'echo implode(",", array_column(db_all("SELECT action FROM audit_log WHERE action LIKE \"role.%\" ORDER BY id"), "action"));')" = 'role.grant,role.revoke' ] \
  && ok 'Super-Admin vergibt und entzieht Systemrollen; beides steht im Protokoll' || bad 'Rollen vergeben'
[ "$(code -b "$TMP/sara" "$BASE/library/board.php?key=$(T 'echo board_by_slug("test-lea")["bkey"];')")" = 404 ] && ! curl -s -b "$TMP/sara" "$BASE/library/" | grep -q 'Leas\|Spalten mit Bündeln' && [ "$(code -b "$TMP/sara" "$BASE/library/feedback.php")" = 200 ] \
  && ok 'Super-Admin: keine fremden Inhalte in der Bibliothek, aber Rückmeldungen' || bad 'Inhaltsrechte des Super-Admins'
curl -s "${G[@]}" "$BASE/test/mail" > "$TMP/sink.html"
grep -q 'eingeladen@tafula.test' "$TMP/sink.html" && grep -q 'Einladung zu Tafula' "$TMP/sink.html" && ! curl -s "$BASE/test/mail" | grep -q 'eingeladen@' && [ "$(T 'echo implode(",", array_unique(array_column(db_all("SELECT status FROM mail_queue"), "status")));')" = sink ] \
  && ok 'Sammelpostfach: jede Mail der Testinstanz landet dort, nichts geht hinaus; ohne Zugangscode nicht lesbar' || bad 'Sammelpostfach'
# Gewöhnliche Anmeldung des Test-Admins mit seiner App-Stufe
cp "$TMP/gate" "$TMP/adam"
# Seit U1c (S1, M4) melden sich Testkonten mit Systemrolle nur über /test mit dem zweiten Code an.
curl -s -o /dev/null -b "$TMP/adam" -c "$TMP/adam" --data-urlencode "csrf=$CSG" -d 'action=gate2&code=zweiter-code-drei' "$BASE/test"
[ "$(curl -s -o /dev/null -w '%{redirect_url}' -b "$TMP/adam" -c "$TMP/adam" --data-urlencode "csrf=$CSG" -d "action=login&user_id=$(uid_of adam.admin)" "$BASE/test")" = "$BASE/manage/system" ] && [ "$(code -b "$TMP/adam" "$BASE/manage/system")" = 200 ] && ! curl -s -b "$TMP/adam" "$BASE/manage/system" | grep -q 'id="schalter"\|id="einladungen"' && curl -s -b "$TMP/adam" "$BASE/manage/system" | grep -q 'id="protokoll"' \
  && ok 'Test-Admin über /test mit dem zweiten Code: Verwaltung ohne Schalter und Einladungen' || bad "Anmeldung des Test-Admins: $(login_as "$TMP/adam9" adam.admin@tafula.test tafula-test-passwort KRQWM5LMMFKGK43UIFSG22LO) $(code -b "$TMP/adam" "$BASE/manage/system") $(curl -s -o /dev/null -w '%{redirect_url}' -b "$TMP/adam" "$BASE/manage/system") $(grep -c tafula "$TMP/adam")"
# Zeitverschiebung: nur fachliche Fristen
sed -i "s/'instance' => 'test',/'instance' => 'test', 'clock_offset' => 2764800,/" config.php
php tools/cron.php --all >/dev/null 2>&1
[ "$(T 'echo (int)db_row("SELECT COUNT(*) AS n FROM users WHERE email = \"uwe.unbestaetigt@tafula.test\"")["n"], invitation_find("x") === null ? "" : "?", (int)db_row("SELECT COUNT(*) AS n FROM invitations WHERE expires_at > ?", [wb_utc(wb_business_time())])["n"];')" = 00 ] && [ "$(code -b "$TMP/sara" "$BASE/account")" = 200 ] \
  && ok 'Zeitverschiebung um 32 Tage: unbestätigtes Konto gelöscht, Einladung verfallen, Sitzungen unberührt' || bad 'Zeitverschiebung'
sed -i "s/ 'clock_offset' => 2764800,//" config.php
set -o pipefail
