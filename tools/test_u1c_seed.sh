# Teil von tools/test.sh (wird dort nach tools/test_u1a_seed.sh eingebunden): Befunde der Sicherheitsprüfung S1
# auf dem Testbestand der Testinstanz (config.php mit instance = test). $TMP/gate trägt den ersten Zugangscode.

set +o pipefail
echo '13f. U1c, M4: Testkonten mit Systemrolle nur über /test, Sammelpostfach nur mit «sink»'
T 'db_exec("DELETE FROM rate_limits");'
for r in adam.admin sara.super; do
  cp "$TMP/gate" "$TMP/m4-$r"
  CS4=$(csrf_of "$TMP/m4-$r" /login)
  R=$(curl -s -o "$TMP/m4.html" -w '%{http_code} %{redirect_url}' -b "$TMP/m4-$r" -c "$TMP/m4-$r" --data-urlencode "csrf=$CS4" --data-urlencode "email=$r@tafula.test" --data-urlencode 'password=tafula-test-passwort' "$BASE/login")
  [ "$R" = '200 ' ] && grep -q 'class="error"' "$TMP/m4.html" || bad "Testkonto $r über /login: $R"
done
cp "$TMP/gate" "$TMP/m4-tom"
CS4=$(csrf_of "$TMP/m4-tom" /login)
R=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b "$TMP/m4-tom" -c "$TMP/m4-tom" --data-urlencode "csrf=$CS4" --data-urlencode 'email=tom.lehrer@tafula.test' --data-urlencode 'password=tafula-test-passwort' "$BASE/login")
[ "$R" = "302 $BASE/login/code" ] && ok 'Test-Admin und Test-Super-Admin kommen mit dem öffentlichen Passwort nicht über /login hinein; Testkonten ohne Systemrolle schon' || bad "Testkonto ohne Systemrolle über /login: $R"
REAL=$(T 'echo db_insert("INSERT INTO mail_queue (to_addr, kind, subject, body, status, created_at, sent_at) VALUES (?, ?, ?, ?, ?, ?, ?)", ["owner@tafula.test", "reset", "ECHTE-MAIL-AN-DIE-BESITZERIN", "LINK-ECHT", "queued", wb_now(), null]);')
curl -s "${G[@]}" "$BASE/test/mail" > "$TMP/sink2.html"; curl -s "${G[@]}" "$BASE/test/mail?id=$REAL" > "$TMP/sink3.html"
! grep -q 'ECHTE-MAIL' "$TMP/sink2.html" && ! grep -q 'LINK-ECHT\|ECHTE-MAIL' "$TMP/sink3.html" && grep -q 'Einladung zu Tafula' "$TMP/sink2.html" \
  && ok 'Sammelpostfach zeigt nur Mails mit Stand «sink», nicht die echten Mails der Allowlist' || bad 'Sammelpostfach zeigt echte Mails'
T 'db_exec("DELETE FROM mail_queue WHERE id = ?", [(int)$argv[1]]); db_exec("DELETE FROM rate_limits");' "$REAL"
echo '13h. U1c, N3: Sperren nur im Arbeitsbereich des Aufrufs'
NID=$(uid_of nora.zwei)
IDS=$(T 'wb_auth_cli((int)$argv[1], null); $priv = db_row("SELECT * FROM boards WHERE owner_user_id = ? AND org_id IS NULL AND deleted_at IS NULL ORDER BY id LIMIT 1", [(int)$argv[1]]);
  $sch = db_row("SELECT * FROM boards WHERE owner_user_id = ? AND org_id IS NOT NULL AND deleted_at IS NULL ORDER BY id LIMIT 1", [(int)$argv[1]]);
  $a = guest_block_create(board_by_id((int)$priv["id"]), str_repeat("a", 32), "Gast Privat", false, false);
  $b = guest_block_create(board_by_id((int)$sch["id"]), str_repeat("b", 32), "Gast Schule", false, false);
  $c = guest_block_create(board_by_id((int)$sch["id"]), str_repeat("c", 32), "Gast Überall", true, false);
  echo $a["id"], " ", $b["id"], " ", $c["id"];' "$NID")
read -r BPRIV BSCH BALL <<< "$IDS"
curl -s -o /dev/null -b "$TMP/nora" --data-urlencode "csrf=$CSN2" -d "org=$SCHOOL" "$BASE/account/workspace"
L=$(curl -s -b "$TMP/nora" "$BASE/api/blocks?status=all" | jq -c '[.blocks[].id] | sort')
R=$(code -b "$TMP/nora" -H "X-CSRF: $CSN2" -X POST "$BASE/api/blocks/$BPRIV/lift")
[ "$L" = "$(echo "[$BSCH,$BALL]" | jq -c 'sort')" ] && [ "$R" != 200 ] && [ "$(T 'echo db_row("SELECT lifted_at FROM guest_blocks WHERE id = ?", [(int)$argv[1]])["lifted_at"] === null ? "aktiv" : "aufgehoben";' "$BPRIV")" = aktiv ] \
  && [ "$(code -b "$TMP/nora" -H "X-CSRF: $CSN2" -X POST "$BASE/api/blocks/$BSCH/lift")" = 200 ] \
  && ok "Schule: listet die Sperren der Schule und «auf allen Boards», nicht die eines privaten Boards; diese lässt sich dort nicht aufheben ($R)" || bad "Sperren über Arbeitsbereiche: [$L] $R ($IDS)"

set -o pipefail
