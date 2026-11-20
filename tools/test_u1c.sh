# Teil von tools/test.sh (wird dort nach tools/test_u1b.sh eingebunden): Befunde der Sicherheitsprüfung S1,
# behoben in U1c. Hauptdatenbank des Tests (Produktion, Registrierung «aus»). $TOKEN ist ein persönliches Token
# des Benutzers 1, $TMP/admin eine Sitzung des Benutzers 1, $KEY sein Board, $EKEY seine Prüfung.
# Die Prüfungen für die Testinstanz (M4) stehen in tools/test_u1c_seed.sh.

set +o pipefail
P() { php -r 'require "lib/bootstrap.php"; '"$1" "${@:2}"; }
# Wie P, aber mit SMTP-Zugang zur Attrappe tools/fake_smtp.py (Produktion: jede Mail geht hinaus).
SMTPPORT=2526
S() { php -r 'require "lib/bootstrap.php"; $GLOBALS["wb_config"]["smtp"] = ["host" => "127.0.0.1", "port" => '"$SMTPPORT"', "secure" => "plain", "user" => "u", "pass" => "p", "from" => "system@tafula.test"]; '"$1" "${@:2}"; }
P 'db_exec("DELETE FROM rate_limits");'
python3 tools/fake_smtp.py "$SMTPPORT" "$TMP/smtp.out" >/dev/null 2>&1 &
SMTPPID=$!
for i in $(seq 20); do (exec 3<>/dev/tcp/127.0.0.1/$SMTPPORT) 2>/dev/null && break; sleep 0.2; done

echo '13a. U1c, H1: Zustellmeldungen unterdrücken nur mit eigener Message-ID'
mid_of() { S '$id = mail_enqueue($argv[1], "login_code", "Code", "Dein Code lautet 482913."); echo (string)db_row("SELECT message_id FROM mail_queue WHERE id = ?", [$id])["message_id"];' "$1"; }
MID=$(mid_of opfer@example.org)
MID2=$(mid_of angreifer@example.org)
grep -q 482913 "$TMP/smtp.out" && [ -n "$MID" ] && [ -n "$MID2" ] && ok 'Mails gehen über die SMTP-Attrappe hinaus und tragen eine Message-ID' || bad "Versand über die Attrappe: [$MID] [$MID2] $(tail -3 "$TMP/smtp.out" 2>/dev/null)"
dsn() { printf 'From: MAILER-DAEMON@mail.example\nSubject: Undelivered Mail Returned to Sender\nContent-Type: multipart/report\n\nThis is the mail system.\n\nFinal-Recipient: rfc822; %s\nAction: failed\nStatus: 5.1.1\nDiagnostic-Code: smtp; 550 5.1.1 user unknown\n%s\n' "$1" "${2:+Message-ID: <$2>}"; }
apply() { S '$b = mail_parse_bounce($argv[1]); $b = mail_bounce_apply($b); echo $b === null ? "nichts" : "erkannt", " ", mail_suppressed("opfer@example.org") ? 1 : 0;' "$1"; }
[ "$(apply "$(dsn opfer@example.org)")" = 'nichts 0' ] && ok 'gefälschte Meldung ohne Message-ID: keine Unterdrückung (der Angriff aus S1)' || bad "Meldung ohne Message-ID: $(apply "$(dsn opfer@example.org)")"
[ "$(apply "$(dsn opfer@example.org "$MID2")")" = 'nichts 0' ] && ok 'Message-ID einer Mail an eine andere Adresse: keine Unterdrückung' || bad 'fremde Message-ID'
[ "$(apply "$(dsn opfer@example.org "0123456789abcdef01234567@tafula.test")")" = 'nichts 0' ] && ok 'erfundene Message-ID: keine Unterdrückung' || bad 'erfundene Message-ID'
P 'db_exec("UPDATE mail_queue SET sent_at = ? WHERE message_id = ?", [wb_utc(time() - 8 * 86400), $argv[1]]);' "$MID"
[ "$(apply "$(dsn opfer@example.org "$MID")")" = 'nichts 0' ] && ok 'Meldung zu einer Mail, die älter als mail_bounce_days ist: keine Unterdrückung' || bad 'alte Mail'
P 'db_exec("UPDATE mail_queue SET sent_at = ? WHERE message_id = ?", [wb_now(), $argv[1]]);' "$MID"
[ "$(apply "$(dsn opfer@example.org "$MID")")" = 'erkannt 1' ] && [ "$(P 'echo db_row("SELECT status FROM mail_queue WHERE message_id = ?", [$argv[1]])["status"];' "$MID")" = bounced ] \
  && ok 'echte Meldung zur eigenen Mail an genau diese Adresse: Mail «bounced», Adresse unterdrückt' || bad 'echte Meldung'
[ "$(S 'echo db_row("SELECT status FROM mail_queue WHERE id = ?", [mail_enqueue("opfer@example.org", "notice", "x", "y")])["status"];')" = suppressed ] && ok 'unterdrückte Adresse erhält nichts' || bad 'Unterdrückung wirkt nicht'
php tools/rescue.php unsuppress opfer@example.org > "$TMP/resc.out" 2>&1
grep -q 'aufgehoben' "$TMP/resc.out" && [ "$(P 'echo mail_suppressed("opfer@example.org") ? 1 : 0, db_row("SELECT action FROM audit_log ORDER BY id DESC LIMIT 1")["action"];')" = 0rescue.unsuppress ] \
  && ok 'rescue.php unsuppress hebt die Unterdrückung auf und steht im Protokoll' || bad "unsuppress: $(cat "$TMP/resc.out")"
P 'mail_suppress("opfer@example.org", "bounce", "x"); db_exec("UPDATE mail_suppressions SET created_at = ? WHERE email = ?", [wb_utc(time() - 31 * 86400), "opfer@example.org"]);'
[ "$(P 'echo mail_suppressed("opfer@example.org") ? 1 : 0; $r = mail_cleanup(); echo $r["suppressions"], (int)db_row("SELECT COUNT(*) AS n FROM mail_suppressions WHERE email = ?", ["opfer@example.org"])["n"];')" = 010 ] \
  && ok 'Unterdrückungen verfallen nach mail_suppression_days (30) Tagen und werden aufgeräumt' || bad 'Verfall der Unterdrückung'
[ "$(S '$id = mail_enqueue("reject-me@example.org", "login_code", "x", "Code 777111"); $m = db_row("SELECT status, body FROM mail_queue WHERE id = ?", [$id]); echo $m["status"], " ", $m["body"] === MAIL_BODY_GONE ? "leer" : "voll", " ", db_row("SELECT reason FROM mail_suppressions WHERE email = ?", ["reject-me@example.org"])["reason"] ?? "-";')" = 'failed leer rejected' ] \
  && ok 'Ablehnung durch den eigenen SMTP-Server bei RCPT TO unterdrückt weiter (vertrauenswürdige Quelle)' || bad 'Ablehnung bei RCPT TO'
P 'db_exec("DELETE FROM mail_suppressions");'

echo '13b. U1c, M3: Text verschickter Mails bleibt nicht stehen'
[ "$(S '$m = db_row("SELECT status, body FROM mail_queue WHERE message_id = ?", [$argv[1]]); echo $m["body"] === MAIL_BODY_GONE ? "leer" : "voll";' "$MID2")" = leer ] && ok 'sofort verschickte Mail: Text nach dem Versand entfernt' || bad 'Text nach sofortigem Versand'
OUT=$(S '$id = mail_enqueue("warte@example.org", "notice", "Hinweis", "Link https://x.example/?t=GEHEIM"); $a = db_row("SELECT status, body FROM mail_queue WHERE id = ?", [$id]); mail_send_queued(10, 0); $b = db_row("SELECT status, body FROM mail_queue WHERE id = ?", [$id]); echo $a["status"], str_contains($a["body"], "GEHEIM") ? "+" : "-", " ", $b["status"], $b["body"] === MAIL_BODY_GONE ? "+" : "-";')
[ "$OUT" = 'queued+ sent+' ] && ok 'Mail aus der Warteschlange: Text bleibt nur bis zum Versand' || bad "Warteschlange: $OUT"
OUT=$(P 'foreach (["sent", "bounced", "suppressed", "sink", "queued"] as $s) db_exec("INSERT INTO mail_queue (to_addr, kind, subject, body, status, created_at) VALUES (?, ?, ?, ?, ?, ?)", ["alt@example.org", "reset", "Alt", "LINK-$s", $s, wb_now()]);
  db_exec("INSERT INTO mail_queue (to_addr, kind, subject, body, status, created_at) VALUES (?, ?, ?, ?, ?, ?)", ["alt@example.org", "reset", "Alt", "LINK-failed-alt", "failed", wb_utc(time() - 2 * 86400)]);
  mail_cleanup(); echo implode(",", array_column(db_all("SELECT body FROM mail_queue WHERE to_addr = ? AND body LIKE ? ORDER BY id", ["alt@example.org", "LINK-%"]), "body"));')
[ "$OUT" = 'LINK-sink,LINK-queued' ] && ok 'Aufräumen entfernt die Texte bestehender Mails; nur «queued» und «sink» bleiben lesbar' || bad "Aufräumen der Texte: $OUT"

echo '13c. U1c, M6: «Passwort vergessen» geht erst nach der Antwort hinaus'
OUT=$(S 'account_reset_request($argv[1]); $m = db_row("SELECT status FROM mail_queue WHERE kind = \"reset\" AND to_addr = ? ORDER BY id DESC LIMIT 1", [$argv[1]]); echo $m["status"] ?? "-", " ", in_array("reset", MAIL_URGENT, true) || in_array("verify", MAIL_URGENT, true) ? "dringend" : "danach";' "$OWNER")
[ "$OUT" = 'queued danach' ] && ok 'reset und verify werden nicht im selben Aufruf über SMTP verschickt (nach der Antwort oder mit dem Cronjob)' || bad "Versand von reset: $OUT"
grep -q "password_hash(\$pw, PASSWORD_DEFAULT);" lib/accounts.php && grep -q 'litespeed_finish_request' lib/mail.php && ok 'Registrierung einer vergebenen Adresse rechnet den Passwort-Hash mit' || bad 'gleicher Aufwand bei der Registrierung'
P 'db_exec("DELETE FROM rate_limits");'

echo '13d. U1c, M1 und M5: Weiterleitung nach der Anmeldung, Bremse bekannter Geräte'
OUT=$(P 'foreach (["/account", "/oauth/authorize?x=a%3A%2F%2Fb", "/ok#teil", "/\t/beispiel.example/x", "//beispiel.example", "/\\\\beispiel.example", "/a\\\\b", "https://beispiel.example", "/a\nb", "/a\rb", "/a\x01b"] as $n) echo ui_safe_next($n, "D") === $n ? "+" : "-";')
[ "$OUT" = '+++--------' ] && ok 'ui_safe_next: nur Pfade aus festem Zeichenvorrat, keine Steuerzeichen, kein Backslash, kein //' || bad "ui_safe_next: $OUT"
MIA=mia@tafula.test; MIAPW=mias-passwort-fuer-tests; MAX=max@tafula.test
P 'foreach ([[$argv[1], "Mia"], [$argv[3], "Max"]] as [$m, $n]) user_create(["email" => $m, "password" => $argv[2], "first_name" => $n, "last_name" => "Test", "verified" => true, "age_confirmed" => true]);' "$MIA" "$MIAPW" "$MAX"
[ "$(login_as "$TMP/mia" "$MIA" "$MIAPW")" = 302 ] || bad 'Anmeldung Mia'
cp "$TMP/mia" "$TMP/miad"; sed -i '/tafula_s/d' "$TMP/miad"
CSM=$(csrf_of "$TMP/miad" /login)
R=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$TMP/miad" -c "$TMP/miad" --data-urlencode "csrf=$CSM" --data-urlencode "email=$MIA" --data-urlencode "password=$MIAPW" --data-urlencode "next=$(printf '/\t/beispiel.example/x')" "$BASE/login")
[ "$R" = "$BASE/lehrerzimmer/" ] && ok 'Anmeldung mit next=/<Tab>/beispiel.example/x endet in der Bibliothek, nicht auf der fremden Website' || bad "Weiterleitung nach der Anmeldung: $R"
cp "$TMP/mia" "$TMP/miad"; sed -i '/tafula_s/d' "$TMP/miad"; P 'db_exec("DELETE FROM rate_limits");'
CSM=$(csrf_of "$TMP/miad" /login)
for i in $(seq 10); do curl -s -o /dev/null -b "$TMP/miad" --data-urlencode "csrf=$CSM" --data-urlencode "email=$MIA" --data-urlencode "password=falsch-$i" "$BASE/login"; done
R=$(code -b "$TMP/miad" --data-urlencode "csrf=$CSM" --data-urlencode "email=$MIA" --data-urlencode "password=$MIAPW" "$BASE/login")
R2=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b "$TMP/mx" -c "$TMP/mx" --data-urlencode "csrf=$(csrf_of "$TMP/mx" /login)" --data-urlencode "email=$MAX" --data-urlencode "password=$MIAPW" "$BASE/login")
R3=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b "$TMP/my" -c "$TMP/my" --data-urlencode "csrf=$(csrf_of "$TMP/my" /login)" --data-urlencode "email=$MIA" --data-urlencode "password=$MIAPW" "$BASE/login")
[ "$R" = 429 ] && [ "$R2" = "302 $BASE/login/code" ] && [ "$R3" = "302 $BASE/login/code" ] && [ "$(P 'echo brake_count("login-ip|127.0.0.1", 15), brake_count("login-acct|" . $argv[1], 15);' "$MIA")" = 00 ] \
  && ok 'bekanntes Gerät: nach zehn Fehlversuchen gebremst; zählt weder auf die IP-Adresse noch aufs Konto (andere Konten und Geräte melden sich an)' || bad "Bremse bekannter Geräte: $R / $R2 / $R3"
P 'db_exec("DELETE FROM rate_limits");'

echo '13e. U1c, N2 und N4: Tokens nur an /api/, CSRF auch auf den Gäste-Routen'
[ "$(P 'echo json_encode(wb_auth_from_token($argv[1]));' "$TOKEN")" = null ] && [ "$(curl -s "${AUTH[@]}" "$BASE/api/me" | jq -r '.via')" = personal ] \
  && ok 'persönliches Token: ohne Ort kein Zugang, an /api/ weiterhin' || bad 'Ort des Tokens'
W1=$(curl -s "${AUTH[@]}" "$BASE/b/$KEY" | grep -c '"csrf":"' || true); W2=$(code "${AUTH[@]}" "$BASE/b/$KEY/export"); W3=$(code "$BASE/b/$KEY/export"); W4=$(code "${AUTH[@]}" "$BASE/a/$EKEY/print")
[ "$W1" = 0 ] && [ "$W2" = "$W3" ] && [ "$W4" = "$(code "$BASE/a/$EKEY/print")" ] && ok "Board-Seite, Export und Druckseite zeigen mit Token keine Besitzeransicht ($W2/$W4 wie ohne Token)" || bad "Token auf Seiten: $W1 $W2 $W3 $W4"
# Mia (13d) ist angemeldet; ihr Board und ihre Zielscheibe entstehen hier.
MIAID=$(P 'echo (int)user_by_email($argv[1])["id"];' "$MIA")
read -r MKEY MAKEY <<< "$(P 'wb_auth_cli((int)$argv[1]); $b = board_create(["name" => "Mias Board"]); $a = activity_create(["type" => "target", "name" => "Mias Zielscheibe", "aspects" => ["Tempo", "Inhalt"]]); echo $b["bkey"], " ", $a["akey"];' "$MIAID")"
CSA=$(csrf_of "$TMP/mia" /account || true)
R1=$(code -b "$TMP/mia" -H 'X-Tafula: 1' "${JH[@]}" -d '{"body":"Besitzerin ohne CSRF"}' "$BASE/api/b/$MKEY/posts")
R2=$(code -b "$TMP/mia" -H 'X-Tafula: 1' -H "X-CSRF: $CSA" "${JH[@]}" -d '{"body":"Besitzerin mit CSRF"}' "$BASE/api/b/$MKEY/posts")
R3=$(code -b "$TMP/mia" -H 'X-Tafula: 1' "${JH[@]}" -d '{"points":[]}' "$BASE/api/a/$MAKEY/respond")
R4=$(code -b "$TMP/mia" "$BASE/api/b/$MKEY/state?since=0")
R5=$(code -b "$TMP/gastjar" -c "$TMP/gastjar" -H 'X-Tafula: 1' "${JH[@]}" -d '{"body":"Gast ohne CSRF"}' "$BASE/api/b/$MKEY/posts")
[ -n "$CSA" ] && [ "$R1" = 403 ] && [ "$R2" != 403 ] && [ "$R3" = 403 ] && [ "$R4" = 200 ] && [ "$R5" != 403 ] && ok "Besitzerin schreibt über Gäste-Routen nur mit X-CSRF (ohne 403, mit $R2); Lesen und Gäste bleiben frei ($R5)" || bad "CSRF auf Gäste-Routen: [$CSA] $R1 $R2 $R3 $R4 $R5"

echo '13g. U1c, N1: In der Nachfrist überlebt höchstens eine Tokenkette'
OUT=$(P '$c = oauth_client_register(["client_name" => "N1-Test", "redirect_uris" => ["https://n1.example/cb"]]);
  $cid = (int)db_row("SELECT id FROM oauth_clients WHERE client_id = ?", [$c["client_id"]])["id"]; oauth_client_touch($cid);
  $res = mcp_resource_url(null);
  $g = db_insert("INSERT INTO oauth_grants (client_id, user_id, org_id, resource, scope, created_at) VALUES (?, 1, NULL, ?, ?, ?)", [$cid, $res, OAUTH_SCOPE, wb_now()]);
  $p0 = oauth_issue_tokens($g);
  $p1 = oauth_token_refresh(["refresh_token" => $p0["refresh_token"], "client_id" => $c["client_id"]]);
  $p2 = oauth_token_refresh(["refresh_token" => $p0["refresh_token"], "client_id" => $c["client_id"]]);
  $a = fn($t) => oauth_access_auth($t, $res) !== null ? "+" : "-";
  echo $a($p1["access_token"]), $a($p2["access_token"]), " ";
  $p3 = oauth_token_refresh(["refresh_token" => $p2["refresh_token"], "client_id" => $c["client_id"]]);
  echo $a($p3["access_token"]), " ";
  try { oauth_token_refresh(["refresh_token" => $p1["refresh_token"], "client_id" => $c["client_id"]]); echo "angenommen"; } catch (OAuthError $e) { echo $e->error; }
  echo " ", $a($p3["access_token"]), " ", db_row("SELECT revoked_reason FROM oauth_grants WHERE id = ?", [$g])["revoked_reason"] ?? "-", " ";
  echo (int)db_row("SELECT COUNT(*) AS n FROM oauth_tokens WHERE grant_id = ? AND parent_id IS NOT NULL", [$g])["n"];' 2>&1)
[ "$OUT" = '-+ + invalid_grant - refresh_conflict 6' ] && ok 'zweites Einlösen in der Nachfrist widerruft das erste Paar; taucht dessen Token wieder auf, endet die Verbindung' || bad "Nachfrist: $OUT"

echo '13i. U1c, M7: Bremse der Registrierung von Clients'
[ "$(P 'echo wb_ip_net("2001:db8:1:2:3:4:5:6"), " ", wb_ip_net("2001:db8:1:2:3:4:5:6", 48), " ", wb_ip_net("160.79.106.165"), " ", wb_ip_net("::ffff:1.2.3.4");')" = '2001:db8:1:2::/64 2001:db8:1::/48 160.79.106.165 1.2.3.4' ] \
  && ok 'Adressen für Bremsen: IPv4 einzeln, IPv6 pro /64 (und /48), IPv4 in IPv6 als IPv4' || bad "wb_ip_net: $(P 'echo wb_ip_net("2001:db8:1:2:3:4:5:6");')"
OUT=$(P 'db_exec("DELETE FROM rate_limits"); $n = 0; $r = ["redirect_uris" => ["https://m7.example/cb"], "client_name" => "M7"];
  for ($i = 1; $i <= 31; $i++) { $_SERVER["REMOTE_ADDR"] = "2001:db8:7:7::" . dechex($i); try { oauth_client_register($r); $n++; } catch (OAuthError $e) { echo $e->status, " "; } }
  $_SERVER["REMOTE_ADDR"] = "2001:db8:7:8::1"; oauth_client_register($r); echo $n, " ";
  $_SERVER["REMOTE_ADDR"] = "160.79.106.1"; $c = oauth_client_register($r); $e = wb_ts(db_row("SELECT expires_at FROM oauth_clients WHERE client_id = ?", [$c["client_id"]])["expires_at"]) - time(); echo $e > 3500 && $e <= 3600 ? "1h" : $e, " ";
  setting_set("oauth_register_open_max", "1"); $_SERVER["REMOTE_ADDR"] = "160.79.106.2"; try { oauth_client_register($r); echo "offen"; } catch (OAuthError $e) { echo $e->status; }
  db_exec("DELETE FROM settings WHERE k = \"oauth_register_open_max\""); db_exec("DELETE FROM rate_limits");' 2>&1)
[ "$OUT" = '429 30 1h 429' ] && ok 'dreissig pro Stunde und /64, ein anderes /64 kommt durch; unbenutzte Clients verfallen nach einer Stunde; Grenze für gleichzeitig offene Clients' || bad "Bremse der Registrierung: $OUT"

echo '13j. U1c, N6 und N9: Name des Programms, Mail «Neue Verbindung», Erneuern ohne client_id'
OUT=$(P 'echo oauth_client_name_clean("Claude ». Konto gesperrt? https://tafula-top.example/entsperren «b \u{202E}x");')
! echo "$OUT" | grep -q '«\|»\|://' && [ "$(echo -n "$OUT" | wc -m)" -le 40 ] && ok "Programmname gekürzt und entschärft: «$OUT»" || bad "Programmname: $OUT"
OUT=$(P '$c = oauth_client_register(["client_name" => "N6 «Test»", "redirect_uris" => ["https://n6.example/cb"]]); $cl = oauth_client_find($c["client_id"]);
  $v = str_repeat("v", 50); $ch = rtrim(strtr(base64_encode(hash("sha256", $v, true)), "+/", "-_"), "=");
  $code = oauth_code_create($cl, 1, null, mcp_resource_url(null), "https://n6.example/cb", $ch);
  $t = oauth_token_from_code(["client_id" => $c["client_id"], "code" => $code, "code_verifier" => $v]);
  $m = db_row("SELECT body FROM mail_queue WHERE kind = \"notice\" AND to_user_id = 1 ORDER BY id DESC LIMIT 1")["body"];
  echo str_contains($m, "n6.example") ? "host" : "-", " ", str_contains($m, "«N6 Test»") ? "name" : "-", " ";
  $before = wb_setting_int("oauth_refresh_without_client_id", 0);
  $t2 = oauth_token_refresh(["refresh_token" => $t["refresh_token"]]); echo wb_setting_int("oauth_refresh_without_client_id", 0) - $before, " ";
  setting_set("oauth_refresh_require_client_id", "1"); try { oauth_token_refresh(["refresh_token" => $t2["refresh_token"]]); echo "angenommen"; } catch (OAuthError $e) { echo $e->error; }
  echo " ", isset(oauth_token_refresh(["refresh_token" => $t2["refresh_token"], "client_id" => $c["client_id"]])["access_token"]) ? "mit" : "-";
  db_exec("DELETE FROM settings WHERE k IN (\"oauth_refresh_require_client_id\", \"oauth_refresh_without_client_id\")");' 2>&1)
[ "$OUT" = 'host name 1 invalid_request mit' ] && ok 'Mail «Neue Verbindung» nennt den Hostnamen; Erneuern ohne client_id wird gezählt und lässt sich mit oauth_refresh_require_client_id verbieten' || bad "N6/N9: $OUT"
! grep -q "'Serverfehler: ' . \$e->getMessage()" mcp/index.php && ! curl -s -b "$TMP/mia" "$BASE/lehrerzimmer/settings.php" | grep -q 'PHP 8' && ok 'Connector gibt keinen Ausnahmetext weiter; Einstellungen zeigen die PHP-Version nur Systemrollen' || bad 'N9: Ausnahmetext oder PHP-Version'

echo '13k. U1c, N7: zweite Stufe ausschalten senkt die Stufe der Sitzungen'
OUT=$(P '$u = user_create(["email" => "sven@tafula.test", "password" => "svens-passwort-123", "first_name" => "Sven", "last_name" => "Test", "verified" => true, "age_confirmed" => true]);
  $id = (int)$u["id"]; membership_add($id, "system", 0, "admin", 1); totp_enable($id, totp_new_secret(), 0);
  $s = wb_session_create($id, "totp"); $sess = db_row("SELECT * FROM sessions WHERE id = ?", [(int)$s["id"]]);
  $GLOBALS["wb_auth_cache"] = ["kind" => "session", "user" => user_reload($id), "session" => $sess, "org_id" => null]; echo wb_can("audit.read") ? 1 : 0;
  totp_disable($id); echo db_row("SELECT mfa_level FROM sessions WHERE id = ?", [(int)$s["id"]])["mfa_level"], " ";
  $GLOBALS["wb_auth_cache"] = ["kind" => "session", "user" => user_reload($id), "session" => $sess, "org_id" => null]; echo wb_can("audit.read") ? 1 : 0;' 2>&1)
[ "$OUT" = '1mail 0' ] && ok 'App ausgeschaltet: Sitzungen fallen auf «mail», und Systemrechte verlangen ein Konto mit App, auch bei einer alten Sitzung mit Stufe «totp»' || bad "N7: $OUT"

echo '13l. U1c, N8: Mails an unbestätigte Adressen und Reserve für Anmeldecodes'
OUT=$(S '$u = user_create(["email" => "mallory@example.org", "password" => "mallorys-passwort-1", "first_name" => "Besuch bei", "last_name" => "https://boese.example", "age_confirmed" => true]);
  account_send_verification($u); $b = db_row("SELECT body FROM mail_queue WHERE to_addr = ? ORDER BY id DESC LIMIT 1", ["mallory@example.org"])["body"];
  echo str_contains($b, "boese.example") || str_contains($b, "Besuch bei") ? "name" : "ohne", " ";
  for ($i = 0; $i < MAIL_HOURLY_LIMIT - MAIL_RESERVED_CODES; $i++) db_exec("INSERT INTO mail_queue (to_addr, kind, subject, body, status, created_at, sent_at) VALUES (\"fuell@example.org\", \"notice\", \"f\", \"f\", \"sent\", ?, ?)", [wb_now(), wb_now()]);
  $a = mail_enqueue("warte2@example.org", "invite", "x", "y"); $c = mail_enqueue("code@example.org", "login_code", "x", "123456");
  echo db_row("SELECT status FROM mail_queue WHERE id = ?", [$a])["status"], " ", db_row("SELECT status FROM mail_queue WHERE id = ?", [$c])["status"], " ";
  db_exec("DELETE FROM mail_queue WHERE to_addr = \"fuell@example.org\"");
  setting_set("signup_mails_per_hour", "0"); echo account_signup(["email" => "neu@example.org", "first_name" => "A", "last_name" => "B", "age" => 1, "terms" => 1, "password" => "ein-langes-passwort-9", "password2" => "ein-langes-passwort-9"]) ?? "angenommen";
  db_exec("DELETE FROM settings WHERE k = \"signup_mails_per_hour\""); db_exec("DELETE FROM rate_limits");' 2>&1)
[ "$OUT" = 'ohne queued sent Zurzeit kommen zu viele Registrierungen. Bitte später wieder versuchen.' ] && ok 'Bestätigungsmail ohne frei gewählten Namen; 15 Mails pro Stunde bleiben für Anmeldecodes; Gesamtgrenze für Registrierungen' || bad "N8: $OUT"

echo '13m. U1c, N10: Notfall-Skript'
P '$u = user_create(["email" => "notfall@tafula.test", "password" => "notfall-passwort-12", "first_name" => "Not", "last_name" => "Fall", "verified" => true, "age_confirmed" => true]); membership_add((int)$u["id"], "system", 0, "superadmin", 1); db_exec("UPDATE users SET disabled_at = ? WHERE id = ?", [wb_now(), (int)$u["id"]]); brake_hit("login-acct|notfall@tafula.test");'
php tools/rescue.php unlock notfall@tafula.test > "$TMP/r1.out" 2>&1
php tools/rescue.php set-email notfall@tafula.test notfall2@tafula.test > "$TMP/r2.out" 2>&1
[ "$(P 'echo user_by_email("notfall2@tafula.test")["disabled_at"] === null ? "frei" : "gesperrt", brake_count("login-acct|notfall@tafula.test", 15), " ", db_row("SELECT to_addr FROM mail_queue WHERE subject LIKE \"%Notfall-Skript%\" ORDER BY id DESC LIMIT 1")["to_addr"];')" = 'frei0 notfall@tafula.test' ] && grep -q benachrichtigt "$TMP/r2.out" \
  && ok 'rescue.php unlock gibt das Konto frei und hebt die Bremsen auf; set-email benachrichtigt die alte Adresse' || bad "Notfall-Skript: $(cat "$TMP/r1.out" "$TMP/r2.out")"

echo '13n. U1c-2: Sicherung (tools/backup.php, tools/backup-decrypt.php) und tools/config-set.php'
BK="$TMP/bk"; mkdir -p "$BK/site/uploads" "$BK/bin" "$BK/out" && echo bild > "$BK/site/uploads/probe.webp"
read -r BKPUB BKSEC < <(php -r '$k = sodium_crypto_box_keypair(); echo base64_encode(sodium_crypto_box_publickey($k)), " ", base64_encode(sodium_crypto_box_secretkey($k)), "\n";')
php -r 'file_put_contents($argv[1], "<?php\n// Testkonfiguration\nreturn " . var_export(["db" => ["host" => "127.0.0.1", "name" => "wedoboard", "user" => "wedo", "pass" => "wedo"], "backup_public_key" => $argv[2]], true) . ";\n");' "$BK/site/config.php" "$BKPUB"
# Auf dem Pi gibt es mariadb-dump nur im Container der Testdatenbank.
printf '#!/bin/sh\nexec docker exec %s mariadb-dump -uwedo -pwedo --single-transaction --no-tablespaces --routines wedoboard\n' "$DB" > "$BK/bin/mysqldump" && chmod +x "$BK/bin/mysqldump"
touch "$BK/out/tafula-prod-20200101-000000.tfbk"
HOME="$BK" PATH="$BK/bin:$PATH" php tools/backup.php --site="$BK/site" --out="$BK/out" --quiet > "$BK/run.out" 2>&1 || true
ARCH=$(ls "$BK/out"/tafula-prod-2*.tfbk 2>/dev/null | grep -v 20200101 | head -1)
TAFULA_BACKUP_SECRET="$BKSEC" php tools/backup-decrypt.php "$ARCH" "$BK/a.tar.gz" >/dev/null 2>&1 || true
LIST=$(tar -tzf "$BK/a.tar.gz" 2>/dev/null | sort | tr '\n' ' ')
META=$(tar -xzOf "$BK/a.tar.gz" meta.json 2>/dev/null | php -r '$m = json_decode(stream_get_contents(STDIN)); echo $m->kind ?? "", " ", $m->schema_version ?? "";')
[ "$LIST" = 'config.php db.sql meta.json uploads/ uploads/probe.webp ' ] && [ "$META" = "prod $(P 'echo WB_SCHEMA_VERSION;')" ] && [ ! -e "$BK/out/tafula-prod-20200101-000000.tfbk" ] && grep -q ' ok tafula-prod-' "$BK/backups_pi/nightly/backup.log" \
  && [ "$(stat -c %a "$ARCH")" = 600 ] && ! grep -q 'CREATE TABLE' "$ARCH" \
  && ok 'tools/backup.php: Datenbank, uploads und config.php verschlüsselt, Schema im Archiv, alte Archive entfernt, Protokoll' || bad "Sicherung: $LIST / $META / $(cat "$BK/run.out")"
cp "$ARCH" "$BK/bad.tfbk" && printf 'X' | dd of="$BK/bad.tfbk" bs=1 seek=300 conv=notrunc 2>/dev/null
OTHER=$(php -r 'echo base64_encode(sodium_crypto_box_secretkey(sodium_crypto_box_keypair()));')
head -c 200 "$ARCH" > "$BK/cut.tfbk"
! TAFULA_BACKUP_SECRET="$BKSEC" php tools/backup-decrypt.php "$BK/bad.tfbk" "$BK/b.tar.gz" >/dev/null 2>&1 && [ ! -e "$BK/b.tar.gz" ] \
  && ! TAFULA_BACKUP_SECRET="$OTHER" php tools/backup-decrypt.php "$ARCH" "$BK/c.tar.gz" >/dev/null 2>&1 \
  && ! TAFULA_BACKUP_SECRET="$BKSEC" php tools/backup-decrypt.php "$BK/cut.tfbk" "$BK/d.tar.gz" >/dev/null 2>&1 && [ ! -e "$BK/d.tar.gz" ] \
  && ok 'tools/backup-decrypt.php weist veränderte, abgeschnittene und fremd verschlüsselte Archive ab' || bad 'Entschlüsseln prüft nicht'
printf "<?php\n// Kopf\nreturn [\n    // Pfad\n    'admin_path' => 'alt-pfad',\n    'smtp' => [\n        'host' => 'x',\n    ],\n];\n" > "$BK/cfg.php"
php tools/config-set.php "$BK/cfg.php" mail_bounces true admin_path '"__random_path__"' >/dev/null 2>&1 || true
CFG=$(php -r '$c = require $argv[1]; echo var_export($c["mail_bounces"] ?? null, true), " ", preg_match("/^b-[0-9a-f]{18}$/", $c["admin_path"]), " ", $c["smtp"]["host"];' "$BK/cfg.php")
[ "$CFG" = 'true 1 x' ] && grep -q '// Pfad' "$BK/cfg.php" && ! php tools/config-set.php "$BK/cfg.php" smtp '"y"' >/dev/null 2>&1 \
  && ok 'tools/config-set.php ändert nur einzelne Zeilen, behält Kommentare und weist mehrzeilige Werte ab' || bad "config-set: $CFG"

kill "$SMTPPID" 2>/dev/null || true
P 'db_exec("DELETE FROM rate_limits");'
set -o pipefail
