#!/bin/bash
# Umstellung der Produktion in Etappe U1c (einmalig; nach der Umstellung wird diese Datei gelöscht).
# Läuft auf dem Pi im Workspace. Ablauf und Gründe: Projektdokument «nutzerverwaltung-u1c-2-…» und docs/SICHERUNG.md.
#
#   tools/u1c-switch.sh prepare         Vorprüfung und frische verschlüsselte Sicherung der Produktion (ohne Änderung)
#   tools/u1c-switch.sh deploy          PROD_BRANCH=main (Commit), Ausrollen mit Migration 15–17, Rückläufer an die
#                                       Produktion, neuer Bibliothekspfad, Cronjobs, client_id-Pflicht, Prüfung
#   tools/u1c-switch.sh status          Super-Admin, Einstellungen, alte und neue Connector-Adresse
#   tools/u1c-switch.sh reset-password  Notfall: Link zum Zurücksetzen an superadmin@tafula.top
#   tools/u1c-switch.sh revoke-legacy   alte Connector-Adresse sofort abschalten (erst nach Raphaels Bestätigung)
#   tools/u1c-switch.sh finish          Zweig stable auf GitHub löschen (erst wenn alles läuft)
#
# Gibt nie Passwörter, Tokens oder den Bibliothekspfad aus.
set -euo pipefail
cd "$(dirname "$0")/.."
# shellcheck source=deploy.conf
. tools/deploy.conf
PHP=/opt/alt/php83/usr/bin/php
H=/home/subconsc
prod_php() { ssh cyon "cd ~/$PROD_DIR && $PHP -r 'require \"lib/bootstrap.php\"; $1'"; }

case "${1:-}" in
prepare)
  git pull -q --ff-only
  echo "== Pi und cyon"; tools/cyon-deploy.sh --check 2>&1 | sed -n '1,12p'
  echo "== Produktion vor der Umstellung"
  ssh cyon "cd ~/$PROD_DIR && git rev-parse --short HEAD && $PHP -r '\$c = require \"config.php\"; foreach ([\"owner_email\", \"app_secret\", \"backup_public_key\", \"smtp\", \"admin_path\"] as \$k) echo \$k, \": \", empty(\$c[\$k]) ? \"FEHLT\" : \"gesetzt\", \"\\n\"; echo \"mail_bounces: \", var_export(\$c[\"mail_bounces\"] ?? false, true), \"\\n\";'"
  echo "== Testinstanz: mail_bounces $(ssh cyon "$PHP -r '\$c = require \"$H/$TEST_DIR/config.php\"; var_export(\$c[\"mail_bounces\"] ?? false);'")"
  echo "== Frische Sicherung der Produktion"
  ssh cyon "$PHP $H/$TEST_DIR/tools/backup.php --site=$H/$PROD_DIR"
  echo "== crontab"; ssh cyon 'crontab -l | grep -v "^#" | grep -v "^$" | grep -v SHELL' || true
  ;;
deploy)
  git pull -q --ff-only
  if [ "$PROD_BRANCH" != main ]; then
    sed -i "s/^PROD_BRANCH='stable'/PROD_BRANCH='main'/" tools/deploy.conf
    sed -i 's/^# auf «stable» (dringende Korrekturen gehen dorthin und werden nach main übernommen), ab U1c wieder auf «main».$/# auf «stable»; seit der Umstellung in U1c (2026) wieder auf «main», und «stable» entfällt./' tools/deploy.conf
    git add tools/deploy.conf
    git commit -q -m "U1c: Produktion folgt wieder main

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
    git push -q origin main
    . tools/deploy.conf
  fi
  [ "$PROD_BRANCH" = main ] || { echo "PROD_BRANCH ist nicht main"; exit 1; }
  echo "== Ausrollen (Sicherung, git pull, Migrationen)"
  tools/cyon-deploy.sh --prod
  echo "== Rückläufer: Produktion liest system@tafula.top, die Testinstanz nicht mehr; neuer Bibliothekspfad; crontab"
  ssh cyon bash -s <<EOF
set -euo pipefail
S=\$(date +%Y%m%d-%H%M%S)
cp -p ~/$PROD_DIR/config.php ~/backups_pi/tafula-config-\$S.php
cp -p ~/$TEST_DIR/config.php ~/backups_pi/tafula-test-config-\$S.php
crontab -l > ~/backups_pi/crontab-\$S.txt
chmod 600 ~/backups_pi/*-\$S.*
$PHP ~/$PROD_DIR/tools/config-set.php ~/$TEST_DIR/config.php mail_bounces false
$PHP ~/$PROD_DIR/tools/config-set.php ~/$PROD_DIR/config.php mail_bounces true admin_path '"__random_path__"'
crontab -l > /tmp/u1c-cron
# Sicherung der Produktion ab jetzt mit dem Code der Produktion statt dem der Testinstanz.
sed -i 's#$H/$TEST_DIR/tools/backup.php --site=$H/$PROD_DIR#$H/$PROD_DIR/tools/backup.php#' /tmp/u1c-cron
grep -q "$PROD_DIR/tools/cron.php" /tmp/u1c-cron || printf '\n# Tafula Produktion: Mails, Rückläufer von system@tafula.top, Aufräumen (seit U1c)\n* * * * * $PHP -f $H/$PROD_DIR/tools/cron.php >/dev/null 2>&1\n' >> /tmp/u1c-cron
crontab /tmp/u1c-cron && rm /tmp/u1c-cron
rm -f ~/$PROD_DIR/uploads/.schema
EOF
  echo "== Pflicht zu client_id beim Erneuern"
  prod_php '$old = setting_get("oauth_refresh_require_client_id"); setting_set("oauth_refresh_require_client_id", "1"); audit_log("system.settings", "settings", null, ["oauth_refresh_require_client_id" => [$old, "1"], "via" => "U1c-2 Umstellung"]); echo "oauth_refresh_require_client_id = ", wb_setting_int("oauth_refresh_require_client_id", 0), "\n";'
  "$0" status
  ;;
status)
  echo "== Super-Admin"; ssh cyon "cd ~/$PROD_DIR && $PHP tools/rescue.php status"
  prod_php 'echo "Schema ", setting_get("schema_version"), " · Registrierung ", wb_registration_mode(), " · client_id-Pflicht ", wb_setting_int("oauth_refresh_require_client_id", 0), " · liest Rückläufer ", wb_config("mail_bounces") ? "ja" : "nein", "\n"; foreach (db_all("SELECT purpose, expires_at, revoked_at FROM api_tokens WHERE purpose = ?", ["legacy_connector"]) as $t) echo "Alter Connector: läuft ab ", $t["expires_at"], $t["revoked_at"] ? ", WIDERRUFEN " . $t["revoked_at"] : ", aktiv", "\n"; foreach (db_all("SELECT g.created_at, g.revoked_at, c.client_name, g.resource FROM oauth_grants g JOIN oauth_clients c ON c.id = g.client_id ORDER BY g.id") as $g) echo "Verbindung: ", $g["client_name"], " → ", $g["resource"], " seit ", $g["created_at"], $g["revoked_at"] ? " (widerrufen)" : "", "\n"; $c = (int)setting_get("cron_last_at", "0"); echo "Cronjob: ", $c ? "zuletzt vor " . (time() - $c) . " s" : "noch nie", "\n";'
  echo "Testinstanz liest Rückläufer: $(ssh cyon "$PHP -r '\$c = require \"$H/$TEST_DIR/config.php\"; echo empty(\$c[\"mail_bounces\"]) ? \"nein\" : \"ja\";'")"
  for u in / /login /mcp/private; do printf '%s %s\n' "$u" "$(curl -s -o /dev/null -w '%{http_code}' "$PROD_URL$u")"; done
  ;;
reset-password)
  ssh cyon "cd ~/$PROD_DIR && $PHP tools/rescue.php reset-password superadmin@tafula.top"
  ;;
revoke-legacy)
  prod_php '$n = 0; foreach (db_all("SELECT id, user_id FROM api_tokens WHERE purpose = ? AND revoked_at IS NULL", ["legacy_connector"]) as $t) $n += api_token_revoke((int)$t["id"], (int)$t["user_id"]) ? 1 : 0; echo "Alter Connector widerrufen: $n Token\n";'
  "$0" status
  ;;
finish)
  . tools/deploy.conf
  [ "$PROD_BRANCH" = main ] || { echo "Die Produktion folgt noch nicht main."; exit 1; }
  [ "$(ssh cyon "cd ~/$PROD_DIR && git rev-parse --abbrev-ref HEAD")" = main ] || { echo "Die Produktion steht nicht auf main."; exit 1; }
  git push -q origin --delete stable && echo "Zweig stable auf GitHub gelöscht."
  git branch -D stable 2>/dev/null || true
  ;;
*)
  sed -n '2,13p' "$0"; exit 1 ;;
esac
