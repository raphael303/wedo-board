#!/bin/bash
# Probe einer Sicherung und Probelauf einer Migration auf einer Wegwerf-Kopie (docs/SICHERUNG.md). Nur auf dem Pi.
#
#   tools/restore-probe.sh up <archiv.tfbk> [ref]   entschlüsseln und eine Kopie mit dem Code von <ref> starten
#                                                   (Vorgabe: Commit aus dem Archiv, also ohne Migration; mit einem
#                                                   neueren Stand wie origin/main laufen dessen Migrationen)
#   tools/restore-probe.sh php <code>               PHP-Code mit geladenem bootstrap.php in der Kopie ausführen
#   tools/restore-probe.sh exec <befehl …>          Befehl im Web-Container (z. B. php tools/rescue.php status)
#   tools/restore-probe.sh sql <abfrage>            Abfrage auf der Datenbank der Kopie
#   tools/restore-probe.sh down                     Kopie, Container und alle entschlüsselten Dateien entfernen
#
# Die Kopie hängt nur an einem internen Netz ohne Ausgang ins Internet; erreichbar ist sie einzig über
# http://127.0.0.1:8017 auf dem Pi. Die config.php kommt aus dem Archiv, aber mit eigener Datenbank, eigener Adresse
# und ohne SMTP (Mails bleiben in mail_queue liegen); app_secret und admin_path bleiben, damit verschlüsselte Werte
# und Links stimmen. Das Skript gibt keine Geheimnisse aus. Der Code und die Daten liegen nur unter /tmp/tafula-restore
# (Rechte 700) und werden nicht in ein Abbild gebaut, sondern eingebunden.
set -euo pipefail
REPO=$(cd "$(dirname "$0")/.." && pwd)
WORK=/tmp/tafula-restore
PORT=8017
NAME=tafula-restore
IMAGE=local/tafula-sandbox-web:latest   # gebaut von /srv/projects/tafula-digest (PHP 8.3 mit Apache wie auf cyon)
dc() { docker compose -p "$NAME" -f "$WORK/compose.yaml" "$@"; }

case "${1:-}" in
up)
  ARCH=${2:?Archiv fehlt}; REF=${3:-}
  [ -e "$WORK" ] && { echo "Es gibt schon eine Kopie unter $WORK; zuerst: $0 down"; exit 1; }
  docker image inspect "$IMAGE" >/dev/null 2>&1 || { echo "Abbild $IMAGE fehlt: /srv/projects/tafula-digest/sandbox.sh rebuild"; exit 1; }
  ( umask 077; mkdir -p "$WORK/x" )
  php "$REPO/tools/backup-decrypt.php" "$ARCH" "$WORK/backup.tar.gz" >/dev/null
  tar -xzf "$WORK/backup.tar.gz" -C "$WORK/x"
  rm "$WORK/backup.tar.gz"
  [ -f "$WORK/x/config.php" ] || { echo "Archiv ohne config.php (nur Datenbank); die Probe braucht eine vollständige Sicherung."; exit 1; }
  COMMIT=$(php -r 'echo json_decode(file_get_contents($argv[1]))->commit ?? "";' "$WORK/x/meta.json")
  REF=${REF:-$COMMIT}
  git -C "$REPO" fetch -q origin
  mkdir -p "$WORK/src"
  git -C "$REPO" archive "$REF" | tar -x -C "$WORK/src"
  rm -f "$WORK/src/config.php"
  php -r '
    $c = require $argv[1];
    $c["db"] = ["host" => "db", "name" => "tafula", "user" => "tafula", "pass" => "probe", "charset" => "utf8mb4"];
    $c["base_url"] = "http://127.0.0.1:" . $argv[2];
    $c["legacy_hosts"] = [];
    unset($c["smtp"], $c["mail_bounces"]);
    file_put_contents($argv[3], "<?php\n// Wegwerf-Kopie aus einer Sicherung (tools/restore-probe.sh), ohne SMTP.\nreturn " . var_export($c, true) . ";\n");
  ' "$WORK/x/config.php" "$PORT" "$WORK/src/config.php"
  mkdir -p "$WORK/src/uploads"
  [ -d "$WORK/x/uploads" ] && cp -a "$WORK/x/uploads/." "$WORK/src/uploads/"
  chmod -R a+rX "$WORK/src"; chmod -R a+rwX "$WORK/src/uploads"
  chmod 755 "$WORK"   # der Container (www-data) muss in src hinein; x/ bleibt 700
  # config.php trägt app_secret: nur www-data (UID 33) darf sie lesen.
  sudo chown 33:33 "$WORK/src/config.php"; sudo chmod 400 "$WORK/src/config.php"
  cat > "$WORK/compose.yaml" <<EOF
name: $NAME
services:
  db:
    image: mariadb:11.4
    restart: "no"
    environment: { MARIADB_ROOT_PASSWORD: probe-root, MARIADB_DATABASE: tafula, MARIADB_USER: tafula, MARIADB_PASSWORD: probe }
    tmpfs: [/var/lib/mysql]
    networks: [inner]
    healthcheck: { test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"], interval: 3s, timeout: 3s, retries: 40 }
  web:
    image: $IMAGE
    restart: "no"
    environment: { TZ: Europe/Zurich }
    volumes: ["$WORK/src:/var/www/html"]
    depends_on: { db: { condition: service_healthy } }
    networks: [inner]
  proxy:
    image: alpine/socat:latest
    restart: "no"
    command: ["TCP-LISTEN:8080,fork,reuseaddr", "TCP:web:80"]
    ports: ["127.0.0.1:$PORT:8080"]
    depends_on: [web]
    networks: [inner, edge]
networks:
  inner: { internal: true }
  edge: {}
EOF
  dc up -d --quiet-pull 2>&1 | grep -vE '^ *(#|=>)' | tail -3 || true
  dc exec -T db sh -c 'mariadb -utafula -pprobe tafula' < "$WORK/x/db.sql"
  echo "Sicherung: $(php -r '$m = json_decode(file_get_contents($argv[1])); echo "$m->kind vom $m->created, Commit ", substr((string)$m->commit, 0, 7), ", Schema $m->schema_version";' "$WORK/x/meta.json")"
  echo "Code der Kopie: $(git -C "$REPO" rev-parse --short "$REF")"
  if [ "$(git -C "$REPO" rev-parse "$REF")" != "$COMMIT" ]; then
    dc exec -T web php -r 'require "lib/bootstrap.php"; $t = microtime(true); echo "Migrationen ausgeführt: ", db_migrate(), ", Schema ", setting_get("schema_version"), sprintf(" (%.1f s)", microtime(true) - $t), "\n"; $r = setting_get("reserved_renamed"); if ($r) echo "Ersetzte Kurzwörter: $r\n";'
  fi
  dc exec -T db mariadb -utafula -pprobe tafula -N -e "SELECT CONCAT('Boards ', (SELECT COUNT(*) FROM boards), ', Beiträge ', (SELECT COUNT(*) FROM posts), ', Aktivitäten ', (SELECT COUNT(*) FROM activities), ', Konten ', (SELECT COUNT(*) FROM users))"
  echo "Startseite: HTTP $(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/")"
  ;;
php)
  dc exec -T web php -r 'require "lib/bootstrap.php"; '"${2:?Code fehlt}"
  ;;
exec)
  shift; dc exec -T web "$@"
  ;;
sql)
  dc exec -T db mariadb -utafula -pprobe tafula -e "${2:?Abfrage fehlt}"
  ;;
down)
  [ -f "$WORK/compose.yaml" ] && dc down --remove-orphans -v 2>&1 | tail -1 || true
  # Dateien, die der Container (www-data) angelegt hat, gehören nicht claude.
  [ -d "$WORK" ] && { rm -rf "$WORK" 2>/dev/null || sudo rm -rf "$WORK"; }
  echo "Kopie entfernt."
  ;;
*)
  sed -n '2,12p' "$0"; exit 1 ;;
esac
