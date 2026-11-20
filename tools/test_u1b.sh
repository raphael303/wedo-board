# Teil von tools/test.sh (wird dort nach tools/test_u1a.sh eingebunden): Nutzerverwaltung U1b auf der
# Hauptdatenbank des Tests (Produktion, Registrierung «aus»). $TOKEN ist ein persönliches Token des Benutzers 1,
# $ATOK ein Zugriffstoken einer OAuth-Verbindung des Benutzers 1 am Connector /mcp/private.

set +o pipefail
P() { php -r 'require "lib/bootstrap.php"; '"$1" "${@:2}"; }
JSONH=(-H 'Content-Type: application/json')
MCPA=(-H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream')
P 'db_exec("DELETE FROM rate_limits");'

echo '11j. Nutzerverwaltung U1b: Metadaten und Connector ohne Anmeldung'
curl -s "$BASE/.well-known/oauth-authorization-server" > "$TMP/as.json"
jq -e --arg b "$BASE" '.issuer == $b and .authorization_endpoint == ($b + "/oauth/authorize") and .token_endpoint == ($b + "/oauth/token") and .registration_endpoint == ($b + "/oauth/register")
  and .code_challenge_methods_supported == ["S256"] and .token_endpoint_auth_methods_supported == ["none"] and (.grant_types_supported | index("refresh_token")) != null
  and .authorization_response_iss_parameter_supported == true and (has("client_id_metadata_document_supported") | not)' "$TMP/as.json" >/dev/null \
  && ok 'Metadaten des Autorisierungsservers (RFC 8414): Aussteller, Endpunkte, S256, öffentliche Clients' || bad "AS-Metadaten: $(cat "$TMP/as.json")"
curl -s "$BASE/.well-known/oauth-protected-resource/mcp/private" | jq -e --arg b "$BASE" '.resource == ($b + "/mcp/private") and .authorization_servers == [$b] and .bearer_methods_supported == ["header"] and .resource_name == "Tafula – privat"' >/dev/null \
  && [ "$(code "$BASE/.well-known/oauth-protected-resource/mcp/o/aaaaaaaaaaaaaaaa")" = 404 ] && [ "$(code "$BASE/mcp/o/aaaaaaaaaaaaaaaa")" = 404 ] \
  && ok 'Metadaten des Connectors (RFC 9728): resource ist genau seine Adresse; unbekannte Schule 404' || bad 'Metadaten des Connectors'
curl -s -D "$TMP/h401" -o "$TMP/b401" "${MCPA[@]}" -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' "$BASE/mcp/private"
grep -q '^HTTP/[0-9.]* 401' "$TMP/h401" && grep -qi "^WWW-Authenticate: Bearer resource_metadata=\"$BASE/.well-known/oauth-protected-resource/mcp/private\"" "$TMP/h401" \
  && ok 'Connector ohne Token: 401 mit Verweis auf die Metadaten' || bad "401 am Connector: $(head -8 "$TMP/h401")"
[ "$(code -b "$TMP/admin" "${MCPA[@]}" -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' "$BASE/mcp/private")" = 401 ] \
  && [ "$(code "${AUTH[@]}" "${MCPA[@]}" -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' "$BASE/mcp/private")" = 401 ] \
  && [ "$(code -H 'Authorization: Bearer tfa_gibtesnicht' "${MCPA[@]}" -d '{}' "$BASE/mcp/private")" = 401 ] \
  && ok 'Connector: weder Sitzung noch persönliches Token noch ein erfundenes Token gelten dort' || bad 'Connector nimmt fremde Anmeldung an'
[ "$(code "$BASE/api/me?token=$TOKEN")" = 401 ] && [ "$(code -H "Authorization: Bearer $ATOK" "$BASE/api/me")" = 401 ] && [ "$(curl -s "${AUTH[@]}" "$BASE/api/me" | jq -r '.via')" = personal ] \
  && ok 'Schnittstelle: ?token= entfällt, ein Zugriffstoken des Connectors gilt dort nicht, das persönliche Token im Header gilt' || bad 'Tokens an /api/'
[ "$(code "${AUTH[@]}" "$BASE/api/feedback")" != 200 ] && P 'exit(setting_get("api_token") === null && (int)db_row("SELECT COUNT(*) AS n FROM api_tokens WHERE LENGTH(token_hash) <> 64")["n"] === 0 ? 0 : 1);' \
  && ok 'persönliches Token des Super-Admins erreicht keine Rückmeldungen; kein api_token in settings, nur Hashes' || bad 'Token und Rückmeldungen'

echo '11k. Nutzerverwaltung U1b: Registrierung von Clients'
REG() { curl -s -w '\n%{http_code}' "${JSONH[@]}" -d "$1" "$BASE/oauth/register"; }
R=$(REG '{"client_name":"Rauchtest <script>alert(1)</script>","redirect_uris":["https://client.example/cb"],"token_endpoint_auth_method":"none","grant_types":["authorization_code","refresh_token"],"response_types":["code"]}')
CID=$(echo "$R" | head -1 | jq -r '.client_id // empty')
[ "$(echo "$R" | tail -1)" = 201 ] && [ -n "$CID" ] && echo "$R" | head -1 | jq -e '.token_endpoint_auth_method == "none" and (has("client_secret") | not) and .redirect_uris == ["https://client.example/cb"]' >/dev/null \
  && ok 'Client registriert sich selbst (RFC 7591), öffentlich und ohne Geheimnis' || bad "Registrierung: $R"
A=$(REG '{"redirect_uris":["http://client.example/cb"]}' | tail -1); B=$(REG '{"redirect_uris":["https://client.example/cb#frag"]}' | tail -1); C=$(REG '{"redirect_uris":[]}' | tail -1); D=$(REG '{"redirect_uris":["javascript:alert(1)"]}' | tail -1); E=$(REG 'kein json' | tail -1)
F=$(REG '{"redirect_uris":["https://client.example/cb"],"grant_types":["client_credentials"]}' | tail -1)
[ "$A$B$C$D$E$F" = 400400400400400400 ] && ok 'abgelehnt: http ausser localhost, Fragment, leere Liste, anderes Schema, kein JSON, andere grant_types' || bad "Registrierung fehlerhafter Clients: $A $B $C $D $E $F"
R=$(REG '{"client_name":"Lokal","redirect_uris":["http://localhost/callback","http://127.0.0.1/callback"]}'); LCID=$(echo "$R" | head -1 | jq -r '.client_id // empty')
[ -n "$LCID" ] && ok 'lokales Programm registriert Adressen der eigenen Maschine (RFC 8252)' || bad "lokaler Client: $R"
P 'db_exec("DELETE FROM rate_limits"); setting_set("oauth_register_per_ip_hour", "3");'
X=''; for i in 1 2 3 4; do X="$X$(REG '{"redirect_uris":["https://client.example/cb"]}' | tail -1) "; done
P 'db_exec("DELETE FROM rate_limits"); db_exec("DELETE FROM settings WHERE k = \"oauth_register_per_ip_hour\"");'
[ "$X" = '201 201 201 429 ' ] && ok 'Registrierung ist pro IP-Adresse gebremst (429)' || bad "Bremse der Registrierung: $X"

echo '11l. Nutzerverwaltung U1b: Zustimmung, Code, Tokens'
OSC=oscar@tafula.test; OSCPW=oscars-eigenes-passwort
P 'user_create(["email" => $argv[1], "password" => $argv[2], "first_name" => "Oscar", "last_name" => "Auth", "verified" => true, "age_confirmed" => true]);' "$OSC" "$OSCPW"
OID=$(P 'echo (int)user_by_email($argv[1])["id"];' "$OSC")
VER=$(head -c 48 /dev/urandom | base64 | tr -d '=+/\n' | cut -c1-50)
CH=$(printf %s "$VER" | openssl dgst -sha256 -binary | base64 | tr '+/' '-_' | tr -d '=\n')
QS="response_type=code&client_id=$CID&redirect_uri=https%3A%2F%2Fclient.example%2Fcb&state=st-123&code_challenge=$CH&code_challenge_method=S256"
AUTHZ="$BASE/oauth/authorize?$QS&resource=$(printf %s "$BASE/mcp/private" | jq -sRr @uri)"
O=(-b "$TMP/osc" -c "$TMP/osc")
R=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$AUTHZ")
case "$R" in "302 $BASE/login?next=%2Foauth%2Fauthorize%3F"*) ok 'Zustimmung ohne Sitzung: zuerst zur Anmeldung, mit Rückweg';; *) bad "authorize ohne Sitzung: $R";; esac
[ "$(login_as "$TMP/osc" "$OSC" "$OSCPW")" = 302 ] || bad 'Anmeldung Oscar'
P 'db_exec("UPDATE sessions SET reauth_at = NULL WHERE user_id = ?", [(int)$argv[1]]);' "$OID"
R=$(curl -s -o /dev/null -w '%{redirect_url}' "${O[@]}" "$AUTHZ")
case "$R" in "$BASE/login/confirm?next=%2Foauth%2Fauthorize%3F"*) ok 'vor der Zustimmung fragt Tafula das Passwort erneut ab';; *) bad "erneute Passwortabfrage: $R";; esac
CSO=$(csrf_of "$TMP/osc" /login/confirm)
curl -s -o /dev/null "${O[@]}" --data-urlencode "csrf=$CSO" --data-urlencode "password=$OSCPW" "$BASE/login/confirm"
curl -s "${O[@]}" "$AUTHZ" > "$TMP/consent.html"
grep -q 'Verbindung erlauben' "$TMP/consent.html" && grep -q '<strong>client.example</strong>' "$TMP/consent.html" && grep -q 'https://client.example/cb' "$TMP/consent.html" && grep -q '<strong>Privat</strong>' "$TMP/consent.html" \
  && grep -q 'Rauchtest script' "$TMP/consent.html" && ! grep -q '<script>alert' "$TMP/consent.html" && grep -q "$OSC" "$TMP/consent.html" \
  && ok 'Zustimmungsseite: Name des Programms (entschärft), Rücksprungadresse mit Hostnamen, Konto, Arbeitsbereich' || bad "Zustimmungsseite: $(grep -o '<td>.\{0,160\}' "$TMP/consent.html" | head -4)"
R=$(curl -s -D "$TMP/hbad" -o "$TMP/bad.html" -w '%{http_code}' "${O[@]}" "$BASE/oauth/authorize?${QS/client.example%2Fcb/evil.example%2Fcb}")
[ "$R" = 400 ] && ! grep -qi '^Location:' "$TMP/hbad" && ! grep -q 'http-equiv="refresh"' "$TMP/bad.html" && ! grep -q 'evil.example' "$TMP/bad.html" \
  && [ "$(code "${O[@]}" "$BASE/oauth/authorize?${QS/client.example%2Fcb/client.example%2Fcb%2Fmore}")" = 400 ] && [ "$(code "${O[@]}" "$BASE/oauth/authorize?${QS/$CID/tc_unbekannt}")" = 400 ] \
  && ok 'fremde oder verlängerte Rücksprungadresse und unbekannter Client: Fehlerseite ohne Weiterleitung' || bad "falsche Rücksprungadresse: $R"
# Seit U1c (S1, M2): Fehler vor dem Entscheid führen nie selbsttätig zurück, auch nicht ohne Anmeldung.
R=$(curl -s -D "$TMP/hm2" -o "$TMP/m2.html" -w '%{http_code}' "$BASE/oauth/authorize?response_type=token&client_id=$CID&redirect_uri=https%3A%2F%2Fclient.example%2Fcb&state=s2")
[ "$R" = 400 ] && ! grep -qi '^Location:' "$TMP/hm2" && ! grep -q 'http-equiv="refresh"' "$TMP/m2.html" && grep -q '<strong>client.example</strong>' "$TMP/m2.html" && grep -q 'href="https://client.example/cb?error=unsupported_response_type' "$TMP/m2.html" \
  && curl -s "${O[@]}" "$BASE/oauth/authorize?response_type=code&client_id=$CID&redirect_uri=https%3A%2F%2Fclient.example%2Fcb&state=s2" | grep -q 'href="https://client.example/cb?error=invalid_request' \
  && ! curl -s "${O[@]}" "$BASE/oauth/authorize?response_type=code&client_id=$CID&redirect_uri=https%3A%2F%2Fclient.example%2Fcb&state=s2" | grep -q 'http-equiv="refresh"' \
  && curl -s "${O[@]}" "$BASE/oauth/authorize?$QS&resource=https%3A%2F%2Fanderswo.example%2Fmcp" | grep -q 'error=invalid_target' \
  && ok 'falscher response_type, ohne PKCE oder fremde resource: Fehlerseite (400) mit Hostnamen, Rückkehr erst auf Klick (S1 M2)' || bad "Fehler an den Client: $R $(grep -o 'href="https://client[^"]*' "$TMP/m2.html" | head -1)"
CSO=$(csrf_of "$TMP/osc" /account)
FORM=(--data-urlencode "response_type=code" --data-urlencode "client_id=$CID" --data-urlencode "redirect_uri=https://client.example/cb" --data-urlencode "state=st-123" --data-urlencode "code_challenge=$CH" --data-urlencode "code_challenge_method=S256" --data-urlencode "resource=$BASE/mcp/private")
[ "$(code "${O[@]}" "${FORM[@]}" -d 'decision=allow' "$BASE/oauth/authorize")" = 400 ] && ok 'Zustimmung ohne CSRF-Feld: 400' || bad 'Zustimmung ohne CSRF'
curl -s "${O[@]}" "${FORM[@]}" --data-urlencode "csrf=$CSO" -d 'decision=deny' "$BASE/oauth/authorize" | grep -q 'url=https://client.example/cb?error=access_denied&amp;state=st-123' && ok 'Ablehnen: access_denied an das Programm' || bad 'Ablehnen'
leave_url() { grep -oE 'http-equiv="refresh" content="0;url=[^"]+' | sed -E 's/.*url=//; s/&amp;/\&/g'; }
newcode() { curl -s "${O[@]}" "${FORM[@]}" --data-urlencode "csrf=$CSO" -d 'decision=allow' "$BASE/oauth/authorize" | leave_url; }
URL=$(newcode); CODE=$(echo "$URL" | sed -E 's/.*[?&]code=([^&]+).*/\1/')
case "$URL" in "https://client.example/cb?code=tfc_"*"&state=st-123&iss="*) ok 'Erlauben: Code, state und iss gehen an die Rücksprungadresse';; *) bad "Erlauben: $URL";; esac
TOK() { curl -s -w '\n%{http_code}' "$@" "$BASE/oauth/token"; }
R=$(TOK -d "grant_type=authorization_code&client_id=$CID&code=$CODE&code_verifier=falscher-verifier-falscher-verifier-falscher-12345&redirect_uri=https%3A%2F%2Fclient.example%2Fcb")
[ "$(echo "$R" | tail -1)" = 400 ] && [ "$(echo "$R" | head -1 | jq -r '.error')" = invalid_grant ] && ok 'falscher code_verifier: invalid_grant' || bad "PKCE: $R"
R=$(TOK -d "grant_type=authorization_code&client_id=$LCID&code=$CODE&code_verifier=$VER"); [ "$(echo "$R" | head -1 | jq -r '.error')" = invalid_grant ] && ok 'Code eines anderen Clients: invalid_grant' || bad "fremder Client: $R"
R=$(TOK -H 'Content-Type: application/json' -d '{"grant_type":"authorization_code"}'); [ "$(echo "$R" | head -1 | jq -r '.error')" = invalid_request ] && ok 'Token-Endpunkt nimmt nur Formulardaten' || bad "JSON am Token-Endpunkt: $R"
R=$(TOK -d "grant_type=authorization_code&client_id=$CID&code=$CODE&code_verifier=$VER&redirect_uri=https%3A%2F%2Fclient.example%2Fcb&resource=$BASE/mcp/private")
AT=$(echo "$R" | head -1 | jq -r '.access_token // empty'); RT=$(echo "$R" | head -1 | jq -r '.refresh_token // empty')
[ "$(echo "$R" | tail -1)" = 200 ] && [ "${AT:0:4}" = tfa_ ] && [ "${RT:0:4}" = tfr_ ] && echo "$R" | head -1 | jq -e '.token_type == "Bearer" and .expires_in == 3600' >/dev/null \
  && P 'exit((int)db_row("SELECT COUNT(*) AS n FROM oauth_tokens WHERE token_hash IN (?, ?)", [$argv[1], $argv[2]])["n"] === 0 && (int)db_row("SELECT COUNT(*) AS n FROM oauth_tokens WHERE token_hash = ?", [hash("sha256", $argv[1])])["n"] === 1 ? 0 : 1);' "$AT" "$RT" \
  && ok 'Code eingelöst: Zugriffstoken (eine Stunde) und Erneuerungstoken, gespeichert nur als Hash' || bad "Token: $R"
P 'exit((int)db_row("SELECT COUNT(*) AS n FROM mail_queue WHERE to_addr = ? AND subject LIKE \"Neue Verbindung%\"", [$argv[1]])["n"] === 1 && (int)db_row("SELECT COUNT(*) AS n FROM audit_log WHERE action = \"oauth.grant\"")["n"] >= 1 ? 0 : 1);' "$OSC" \
  && ok 'neue Verbindung: Mail an die Inhaberin und Eintrag im Protokoll' || bad 'Mail oder Protokoll zur Verbindung'
OM() { curl -s -H "Authorization: Bearer $1" "${MCPA[@]}" -d "$3" "$BASE/mcp/$2"; }
R=$(OM "$AT" private '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}')
echo "$R" | jq -e '.result.serverInfo.name == "Tafula – privat" and .result.protocolVersion == "2025-11-25" and (.result.instructions | test("Arbeitsbereich «Privat» von Oscar Auth"))' >/dev/null && ok 'Connector meldet sich als «Tafula – privat» und nennt den Arbeitsbereich' || bad "initialize: $(echo "$R" | head -c 300)"
R=$(OM "$AT" private '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"create_board","arguments":{"name":"Oscars Connector-Board","note":"Test","tags":["test"]}}}')
OB=$(echo "$R" | jq -r '.result.content[0].text' | jq -r '.key // .board.key // empty' 2>/dev/null)
[ "$(P 'echo implode(" ", array_map("strval", db_row("SELECT owner_user_id, org_id IS NULL AS p FROM boards WHERE name = \"Oscars Connector-Board\"") ?: []));')" = "$OID 1" ] \
  && ! OM "$AT" private '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"list_boards","arguments":{"status":"all"}}}' | grep -q 'Rauchtest\|Leas Board' \
  && OM "$AT" private '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"read_board","arguments":{"board":"'"$KEY"'"}}}' | grep -q 'Kein aktives Board' \
  && ok 'Verbindung arbeitet als Oscar in «Privat» und erreicht nur Eigenes' || bad "Connector als Oscar: $(echo "$R" | head -c 300)"
R=$(TOK -d "grant_type=authorization_code&client_id=$CID&code=$CODE&code_verifier=$VER")
[ "$(echo "$R" | head -1 | jq -r '.error')" = invalid_grant ] && [ "$(code -H "Authorization: Bearer $AT" "${MCPA[@]}" -d '{"jsonrpc":"2.0","id":1,"method":"ping"}' "$BASE/mcp/private")" = 401 ] \
  && ok 'Code ein zweites Mal eingelöst: abgelehnt, und die daraus entstandene Verbindung ist widerrufen' || bad "Code doppelt: $R"

echo '11m. Nutzerverwaltung U1b: Erneuern, Widerruf, Lebensdauer'
grant() { local c; c=$(newcode | sed -E 's/.*[?&]code=([^&]+).*/\1/'); TOK -d "grant_type=authorization_code&client_id=$CID&code=$c&code_verifier=$VER" | head -1; }
R=$(grant); AT=$(echo "$R" | jq -r .access_token); RT=$(echo "$R" | jq -r .refresh_token)
R2=$(TOK -d "grant_type=refresh_token&client_id=$CID&refresh_token=$RT" | head -1); AT2=$(echo "$R2" | jq -r '.access_token // empty'); RT2=$(echo "$R2" | jq -r '.refresh_token // empty')
[ -n "$AT2" ] && [ "$RT2" != "$RT" ] && [ "$(code -H "Authorization: Bearer $AT2" "${MCPA[@]}" -d '{"jsonrpc":"2.0","id":1,"method":"ping"}' "$BASE/mcp/private")" = 200 ] && ok 'Erneuern tauscht den Erneuerungstoken aus' || bad "Erneuern: $R2"
[ "$(TOK -d "grant_type=refresh_token&client_id=$CID&refresh_token=$RT" | head -1 | jq -r '.access_token // empty' | cut -c1-4)" = tfa_ ] && ok 'derselbe Erneuerungstoken sofort noch einmal (Wiederholung): angenommen' || bad 'Wiederholung des Erneuerns'
P 'db_exec("UPDATE oauth_tokens SET used_at = ? WHERE token_hash = ?", [wb_utc(time() - 120), hash("sha256", $argv[1])]);' "$RT"
R=$(TOK -d "grant_type=refresh_token&client_id=$CID&refresh_token=$RT")
[ "$(echo "$R" | tail -1)" = 400 ] && [ "$(echo "$R" | head -1 | jq -r '.error')" = invalid_grant ] && [ "$(code -H "Authorization: Bearer $AT2" "${MCPA[@]}" -d '{}' "$BASE/mcp/private")" = 401 ] \
  && [ "$(TOK -d "grant_type=refresh_token&client_id=$CID&refresh_token=$RT2" | head -1 | jq -r '.error')" = invalid_grant ] \
  && ok 'ausgetauschter Erneuerungstoken taucht später wieder auf: invalid_grant, die ganze Verbindung endet' || bad "Wiederverwendung: $R"
R=$(grant); AT=$(echo "$R" | jq -r .access_token); RT=$(echo "$R" | jq -r .refresh_token)
P 'db_exec("UPDATE oauth_tokens SET expires_at = ? WHERE token_hash = ?", [wb_utc(time() - 5), hash("sha256", $argv[1])]);' "$AT"
[ "$(code -H "Authorization: Bearer $AT" "${MCPA[@]}" -d '{}' "$BASE/mcp/private")" = 401 ] && AT=$(TOK -d "grant_type=refresh_token&refresh_token=$RT" | head -1 | jq -r .access_token) && [ "${AT:0:4}" = tfa_ ] && ok 'abgelaufenes Zugriffstoken: 401, Erneuern liefert ein neues' || bad 'Ablauf des Zugriffstokens'
curl -s "${O[@]}" "$BASE/account" > "$TMP/acc.html"
GID=$(P 'echo (int)db_row("SELECT id FROM oauth_grants WHERE user_id = ? AND revoked_at IS NULL ORDER BY id DESC LIMIT 1", [(int)$argv[1]])["id"];' "$OID")
grep -q 'id="verbindungen"' "$TMP/acc.html" && grep -q "$BASE/mcp/private" "$TMP/acc.html" && grep -q 'Rauchtest' "$TMP/acc.html" && grep -q 'client.example' "$TMP/acc.html" && ! grep -q "tfa_\|tfr_" "$TMP/acc.html" \
  && ok 'Konto: Adresse des Connectors und die Verbindung mit Programm und Rücksprungadresse, kein Token' || bad 'Konto: Verbindungen'
P 'user_create(["email" => "paula@tafula.test", "password" => $argv[1], "first_name" => "Paula", "last_name" => "Fremd", "verified" => true, "age_confirmed" => true]);' "$OSCPW"
[ "$(login_as "$TMP/paula" paula@tafula.test "$OSCPW")" = 302 ] || bad 'Anmeldung Paula'
[ "$(code -b "$TMP/paula" --data-urlencode "csrf=$(csrf_of "$TMP/paula" /account)" -d "action=grant_revoke&id=$GID" "$BASE/account")" = 302 ] && [ "$(code -H "Authorization: Bearer $AT" "${MCPA[@]}" -d '{"jsonrpc":"2.0","id":1,"method":"ping"}' "$BASE/mcp/private")" = 200 ] \
  && ok 'eine fremde Person kann die Verbindung nicht widerrufen' || bad 'fremder Widerruf'
[ "$(code "${O[@]}" --data-urlencode "csrf=$CSO" -d "action=grant_revoke&id=$GID" "$BASE/account")" = 302 ] && [ "$(code -H "Authorization: Bearer $AT" "${MCPA[@]}" -d '{}' "$BASE/mcp/private")" = 401 ] \
  && [ "$(TOK -d "grant_type=refresh_token&refresh_token=$RT" | head -1 | jq -r '.error')" = invalid_grant ] && ok 'Widerruf im Konto: Zugriffs- und Erneuerungstoken gelten sofort nicht mehr' || bad 'Widerruf im Konto'
R=$(grant); AT=$(echo "$R" | jq -r .access_token)
[ "$(code -d "token=$AT" "$BASE/oauth/revoke")" = 200 ] && [ "$(code -d "token=unbekannt" "$BASE/oauth/revoke")" = 200 ] && [ "$(code -H "Authorization: Bearer $AT" "${MCPA[@]}" -d '{}' "$BASE/mcp/private")" = 401 ] && ok 'Widerruf durch das Programm (RFC 7009)' || bad 'revoke'

echo '11n. Nutzerverwaltung U1b: Connector pro Arbeitsbereich'
OKEY=schuleschule0001
SID=$(P '$d = db_insert("INSERT INTO orgs (okey, type, name, created_at, updated_at) VALUES (\"bezirkbezirk0001\", \"district\", \"Bezirk\", ?, ?)", [wb_now(), wb_now()]);
  $s = db_insert("INSERT INTO orgs (okey, type, parent_id, name, created_at, updated_at) VALUES (?, \"school\", ?, \"OSZ Test\", ?, ?)", [$argv[1], $d, wb_now(), wb_now()]); membership_add((int)$argv[2], "org", $s, "teacher", 1); echo $s;' "$OKEY" "$OID")
curl -s "$BASE/.well-known/oauth-protected-resource/mcp/o/$OKEY" | jq -e --arg r "$BASE/mcp/o/$OKEY" '.resource == $r and .resource_name == "Tafula – OSZ Test"' >/dev/null && ok 'der Connector der Schule hat eigene Metadaten und einen eigenen Namen' || bad 'Metadaten Schule'
FORM_S=("${FORM[@]:0:12}" --data-urlencode "resource=$BASE/mcp/o/$OKEY")
curl -s "${O[@]}" "$BASE/oauth/authorize?$QS&resource=$BASE/mcp/o/$OKEY" | grep -q '<strong>OSZ Test</strong>' || bad 'Zustimmungsseite nennt die Schule nicht'
SC=$(curl -s "${O[@]}" "${FORM_S[@]}" --data-urlencode "csrf=$CSO" -d 'decision=allow' "$BASE/oauth/authorize" | leave_url | sed -E 's/.*[?&]code=([^&]+).*/\1/')
R=$(TOK -d "grant_type=authorization_code&client_id=$CID&code=$SC&code_verifier=$VER" | head -1); SAT=$(echo "$R" | jq -r '.access_token // empty'); SRT=$(echo "$R" | jq -r '.refresh_token // empty')
R=$(grant); PAT=$(echo "$R" | jq -r .access_token)
[ "$(OM "$SAT" "o/$OKEY" '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' | jq -r '.result.serverInfo.name')" = 'Tafula – OSZ Test' ] \
  && [ "$(code -H "Authorization: Bearer $SAT" "${MCPA[@]}" -d '{}' "$BASE/mcp/private")" = 401 ] && [ "$(code -H "Authorization: Bearer $PAT" "${MCPA[@]}" -d '{}' "$BASE/mcp/o/$OKEY")" = 401 ] \
  && ok 'zwei Connectoren nebeneinander: eigener Name, und ein Token gilt nur an seinem Connector' || bad 'Token am falschen Connector'
OM "$SAT" "o/$OKEY" '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"create_board","arguments":{"name":"Oscars Schulboard","note":"Test","tags":["test"]}}}' >/dev/null
[ "$(P 'echo (int)db_row("SELECT org_id FROM boards WHERE name = \"Oscars Schulboard\"")["org_id"];')" = "$SID" ] \
  && OM "$SAT" "o/$OKEY" '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"list_boards","arguments":{"status":"all"}}}' | grep -q 'Oscars Schulboard' \
  && ! OM "$SAT" "o/$OKEY" '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"list_boards","arguments":{"status":"all"}}}' | grep -q 'Oscars Connector-Board' \
  && ! OM "$PAT" private '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"list_boards","arguments":{"status":"all"}}}' | grep -q 'Oscars Schulboard' \
  && ok 'jede Verbindung sieht und schreibt nur ihren Arbeitsbereich' || bad 'Trennung der Arbeitsbereiche am Connector'
P 'db_exec("UPDATE sessions SET reauth_at = ? WHERE revoked_at IS NULL", [wb_now()]);'
[ "$(code -b "$TMP/paula" "$BASE/oauth/authorize?$QS&resource=$BASE/mcp/o/$OKEY")" = 403 ] && ok 'wer der Schule nicht angehört, kann ihren Connector nicht verbinden (403)' || bad "fremde Schule: $(code -b "$TMP/paula" "$BASE/oauth/authorize?$QS&resource=$BASE/mcp/o/$OKEY")"
curl -s "${O[@]}" "$BASE/oauth/authorize?$QS" | grep -q '<select id="ws" name="workspace">' && ok 'Programm ohne resource (älterer Stand): die Person wählt den Arbeitsbereich auf der Zustimmungsseite' || bad 'Auswahl des Arbeitsbereichs'
P 'membership_remove((int)$argv[1], "org", (int)$argv[2], "teacher");' "$OID" "$SID"
[ "$(code -H "Authorization: Bearer $SAT" "${MCPA[@]}" -d '{}' "$BASE/mcp/o/$OKEY")" = 401 ] && [ "$(TOK -d "grant_type=refresh_token&refresh_token=$SRT" | head -1 | jq -r '.error')" = invalid_grant ] \
  && [ "$(code -H "Authorization: Bearer $PAT" "${MCPA[@]}" -d '{"jsonrpc":"2.0","id":1,"method":"ping"}' "$BASE/mcp/private")" = 200 ] \
  && ok 'nach dem Austritt aus der Schule wirkt deren Verbindung nicht mehr (bei jedem Aufruf geprüft); die private bleibt' || bad 'Verbindung nach Austritt'
P 'db_exec("UPDATE users SET disabled_at = ? WHERE id = ?", [wb_now(), (int)$argv[1]]);' "$OID"
X=$(code -H "Authorization: Bearer $PAT" "${MCPA[@]}" -d '{}' "$BASE/mcp/private")
P 'db_exec("UPDATE users SET disabled_at = NULL WHERE id = ?", [(int)$argv[1]]);' "$OID"
[ "$X" = 401 ] && ok 'gesperrtes Konto: Verbindung wirkt nicht' || bad "gesperrtes Konto: $X"

echo '11o. Nutzerverwaltung U1b: persönliche Tokens, alter Token, Aufräumen'
P 'db_exec("UPDATE sessions SET reauth_at = NULL WHERE user_id = ?", [(int)$argv[1]]);' "$OID"
case "$(curl -s -o /dev/null -w '%{redirect_url}' "${O[@]}" "$BASE/account/token")" in "$BASE/login/confirm"*) ok 'neues persönliches Token: erst nach erneuter Passwortabfrage';; *) bad 'Token ohne Passwortabfrage';; esac
curl -s -o /dev/null "${O[@]}" --data-urlencode "csrf=$CSO" --data-urlencode "password=$OSCPW" "$BASE/login/confirm"
curl -s "${O[@]}" --data-urlencode "csrf=$CSO" -d 'label=Mein+Skript&workspace=private&days=30' "$BASE/account/token" > "$TMP/tok.html"
PT=$(grep -oE 'tfp_[A-Za-z0-9_-]{43}' "$TMP/tok.html" | head -1)
[ -n "$PT" ] && [ "$(curl -s -H "Authorization: Bearer $PT" "$BASE/api/me" | jq -r '[.user.email, .auth, .via, (.roles | length)] | join(" ")')" = "$OSC token personal 0" ] \
  && ! curl -s "${O[@]}" "$BASE/account" | grep -q "$PT" && curl -s "${O[@]}" "$BASE/account" | grep -q 'Mein Skript' \
  && P 'exit((int)db_row("SELECT COUNT(*) AS n FROM api_tokens WHERE token_hash = ? AND purpose = \"personal\" AND expires_at IS NOT NULL", [hash("sha256", $argv[1])])["n"] === 1 ? 0 : 1);' "$PT" \
  && ok 'persönliches Token: einmal angezeigt, nur als Hash gespeichert, mit Ablauf, gilt an /api/' || bad "persönliches Token: $(grep -o 'class="error"[^<]*' "$TMP/tok.html")"
[ "$(code -H "Authorization: Bearer $PT" "$BASE/api/boards/$KEY")" = 404 ] && [ "$(code -H "Authorization: Bearer $PT" "${MCPA[@]}" -d '{}' "$BASE/mcp/private")" = 401 ] && [ "$(code -H "Authorization: Bearer $PT" "$BASE/account")" = 302 ] \
  && [ "$(code -H "Authorization: Bearer $PT" "$BASE/oauth/authorize?$QS")" = 302 ] \
  && ok 'persönliches Token: nur Eigenes, nicht am Connector, keine Kontoseiten, keine Zustimmung' || bad 'Grenzen des persönlichen Tokens'
[ "$(code "${O[@]}" --data-urlencode "csrf=$CSO" -d 'label=X&workspace=999&days=30' "$BASE/account/token")" = 200 ] && [ "$(code "${O[@]}" --data-urlencode "csrf=$CSO" -d 'label=X&workspace=private&days=9999' "$BASE/account/token")" = 200 ] \
  && [ "$(P 'echo (int)db_row("SELECT COUNT(*) AS n FROM api_tokens WHERE user_id = ?", [(int)$argv[1]])["n"];' "$OID")" = 1 ] && ok 'fremder Arbeitsbereich oder freie Gültigkeit: kein Token' || bad 'Prüfung beim Erzeugen'
TID=$(P 'echo (int)db_row("SELECT id FROM api_tokens WHERE user_id = ?", [(int)$argv[1]])["id"];' "$OID")
[ "$(code "${O[@]}" --data-urlencode "csrf=$CSO" -d "action=token_revoke&id=$TID" "$BASE/account")" = 302 ] && [ "$(code -H "Authorization: Bearer $PT" "$BASE/api/me")" = 401 ] && ok 'Widerruf des persönlichen Tokens wirkt sofort' || bad 'Widerruf Token'
# Auf Wunsch (SHOTS_DIR gesetzt): Bildschirmfotos und Layoutprüfung der neuen Seiten in drei Breiten (tools/shots_u1b.py)
if [ -n "${SHOTS_DIR:-}" ]; then
  mkdir -p "$SHOTS_DIR"
  SESS=$(P '$s = wb_session_create((int)$argv[1], "mail"); db_exec("UPDATE sessions SET reauth_at = ? WHERE id = ?", [wb_utc(time() + 600), (int)$s["id"]]); $t = wb_random_token(32); db_exec("UPDATE sessions SET token_hash = ? WHERE id = ?", [wb_token_hash($t), (int)$s["id"]]); echo $t;' "$OID" 2>/dev/null)
  docker run --rm --ipc=host --network host -v "$SHOTS_DIR":/w -v "$ROOT/tools/shots_u1b.py":/shots.py:ro -e BASE="$BASE" -e SESS="$SESS" -e CID="$CID" -e LCID="$LCID" -e CH="$CH" \
    mcr.microsoft.com/playwright/python:v1.65.2-noble sh -c "pip install -q --break-system-packages playwright==1.65.2 2>/dev/null; python3 /shots.py" > "$TMP/shots.out" 2>&1 || true
  grep -q 'alle Seiten in allen Breiten ohne Befund' "$TMP/shots.out" && ok "Layoutprüfung in 390, 700 und 1280 Pixel: $(tail -1 "$TMP/shots.out")" || bad "Layoutprüfung: $(tail -8 "$TMP/shots.out" | cut -c1-400)"
  P 'db_exec("DELETE FROM api_tokens WHERE label LIKE \"Bildschirmfoto%\"");'
fi
# Zurücksetzen des Passworts widerruft Verbindungen und Tokens
PT2=$(P 'echo api_token_create((int)$argv[1], null, "personal", "Vor dem Zurücksetzen", 30)[1];' "$OID")
P '$t = login_token_create((int)$argv[1], "reset_password", 3600); $e = account_reset_finish($t, "ein-ganz-neues-passwort-77", "ein-ganz-neues-passwort-77"); if ($e !== null) { fwrite(STDERR, $e); exit(1); }' "$OID" 2>"$TMP/rst.err" || bad "Zurücksetzen: $(cat "$TMP/rst.err")"
[ "$(code -H "Authorization: Bearer $PT2" "$BASE/api/me")" = 401 ] && [ "$(code -H "Authorization: Bearer $PAT" "${MCPA[@]}" -d '{}' "$BASE/mcp/private")" = 401 ] \
  && [ "$(P 'echo (int)db_row("SELECT COUNT(*) AS n FROM oauth_grants WHERE user_id = ? AND revoked_at IS NULL", [(int)$argv[1]])["n"], db_row("SELECT revoked_reason FROM oauth_grants WHERE user_id = ? ORDER BY id DESC LIMIT 1", [(int)$argv[1]])["revoked_reason"];' "$OID")" = 0password_reset ] \
  && ok 'Zurücksetzen des Passworts widerruft alle Verbindungen und Tokens' || bad 'Widerruf beim Zurücksetzen'
# Der frühere api_token: nur noch an der alten Connector-Adresse, mit Ablauf
OLD=$(printf 'cd%.0s' $(seq 24))
P 'setting_set("api_token", $argv[1]); db_migration_16_finish();' "$OLD"
[ "$(curl -s "${MCPA[@]}" -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' "$BASE/mcp/$OLD" | jq -r '.result.serverInfo.name')" = Tafula ] && [ "$(code -H "Authorization: Bearer $OLD" "$BASE/api/me")" = 401 ] && [ "$(code -H "Authorization: Bearer $OLD" "${MCPA[@]}" -d '{}' "$BASE/mcp/private")" = 401 ] \
  && [ "$(P 'echo setting_get("api_token") ?? "weg", " ", implode(" ", array_map("strval", db_row("SELECT purpose, user_id, org_id IS NULL AS p, expires_at > ? AS lebt, expires_at < ? AS endet, token_hash = ? AS hash FROM api_tokens WHERE purpose = \"legacy_connector\"", [wb_now(), wb_utc(time() + 31 * 86400), hash("sha256", $argv[1])])));' "$OLD")" = 'weg legacy_connector 1 1 1 1 1' ] \
  && ok 'alter Token überführt: als Hash mit Ablauf in api_tokens, aus settings gelöscht, gilt nur an /mcp/<token>, nicht an /api/' || bad 'Überführung des alten Tokens'
P 'db_exec("UPDATE api_tokens SET expires_at = ? WHERE purpose = \"legacy_connector\"", [wb_utc(time() - 5)]);'
[ "$(code "${MCPA[@]}" -d '{}' "$BASE/mcp/$OLD")" = 404 ] && ok 'nach dem Ablauf gibt es die alte Connector-Adresse nicht mehr (404)' || bad 'Ablauf des alten Tokens'
P 'db_exec("DELETE FROM api_tokens WHERE purpose = \"legacy_connector\"");'
N1=$(P 'echo (int)db_row("SELECT COUNT(*) AS n FROM oauth_clients")["n"];')
P 'db_exec("UPDATE oauth_clients SET expires_at = ? WHERE client_id IN (?, ?)", [wb_utc(time() - 5), $argv[1], $argv[2]]); db_exec("UPDATE oauth_clients c SET c.expires_at = ? WHERE NOT EXISTS (SELECT 1 FROM oauth_grants g WHERE g.client_id = c.id)", [wb_utc(time() - 5)]); oauth_cleanup();' "$LCID" "$CID"
[ "$(P 'echo (int)db_row("SELECT COUNT(*) AS n FROM oauth_clients WHERE client_id = ?", [$argv[1]])["n"], (int)db_row("SELECT COUNT(*) AS n FROM oauth_clients WHERE client_id = ?", [$argv[2]])["n"];' "$LCID" "$CID")" = 00 ] \
  && [ "$(code "${O[@]}" "$BASE/oauth/authorize?$QS")" != 200 ] && ok "unbenutzte und verwaiste Clients verfallen (vorher $N1)" || bad 'Aufräumen der Clients'
P 'db_exec("DELETE FROM rate_limits");'
set -o pipefail
