#!/bin/bash
# Tafula auf cyon ausrollen. Läuft auf dem Raspberry Pi im Workspace /srv/workspaces/wedo-board
# (Connector «AlayaPi5», Werkzeug run_command), nicht auf einem Laptop. SSH über den Alias «cyon»
# mit dem eigenen Schlüssel des Pi, ohne Passphrase. Ziele und Zweige stehen in tools/deploy.conf.
#
#   tools/cyon-deploy.sh               Testinstanz (TEST_BRANCH), danach Produktion (PROD_BRANCH)
#   tools/cyon-deploy.sh --test        nur die Testinstanz
#   tools/cyon-deploy.sh --prod        nur die Produktion
#   tools/cyon-deploy.sh --seed test   Testbestand der Testinstanz neu laden (auch: --seed demo)
#   tools/cyon-deploy.sh --check       nur lesend: Stand, Zweig, Instanzart und Schema beider Installationen
#
# Vor jedem Ausrollen und vor --seed sichert das Skript auf cyon den Code-Ordner ohne uploads/ und einen
# Abzug der Datenbank nach ~/backups_pi (eigene Sicherungen älter als 30 Tage werden gelöscht).
# Der Bibliothekspfad (admin_path) steht nur in config.php auf cyon; das Skript prüft nur, dass er gesetzt
# ist, und gibt ihn nie aus. config.php und uploads/ werden nicht verändert. Zugangsdaten der Datenbank
# liest PHP auf cyon aus config.php in eine kurzlebige Datei mit Rechten 600; sie erscheinen nie in der Ausgabe.
set -euo pipefail
cd "$(dirname "$0")/.."
# shellcheck source=deploy.conf
. tools/deploy.conf

MODE=${1:-all}
case "$MODE" in
  all|--test|--prod|--check) ;;
  --seed) SEED=${2:-}; [ "$SEED" = test ] || [ "$SEED" = demo ] || { echo 'Aufruf: --seed test|demo'; exit 1; } ;;
  *) echo "Unbekannte Option: $MODE"; exit 1 ;;
esac

git fetch -q origin
STAMP=$(date +%Y%m%d-%H%M%S)

# Zeigt Stand, Zweig, Instanzart und Schema einer Installation (nur lesend).
check_one() {  # $1 Bezeichnung, $2 Ordner, $3 Adresse, $4 erwarteter Zweig
  local target live
  target=$(git rev-parse --short "origin/$4")
  live=$(ssh cyon "cd ~/$2 && git rev-parse --short HEAD" 2>/dev/null || echo '–')
  echo "== $1 ($3)"
  echo "Stand: $live · GitHub $4: $target"
  ssh cyon bash -s <<EOF
set -euo pipefail
cd ~/$2
git log -1 --format='Live: %h %s (%ci)'
echo "Zweig: \$(git rev-parse --abbrev-ref HEAD) (erwartet $4)"
if [ -n "\$(git status --porcelain --untracked-files=no)" ]; then echo 'WARNUNG: Änderungen an versionierten Dateien'; fi
php -r '\$c = require "config.php"; echo empty(\$c["admin_path"]) ? "WARNUNG: admin_path fehlt in config.php\n" : "admin_path ist gesetzt\n"; \$i = \$c["instance"] ?? null; echo "Instanzart: ", in_array(\$i, ["sandbox", "test"], true) ? \$i : "Produktion", "\n";'
php -r 'require "lib/bootstrap.php"; echo "Schema: ", setting_get("schema_version"), " (Code erwartet ", WB_SCHEMA_VERSION, ")\n"; \$m = function_exists("wb_instance") ? setting_get("instance_marker") : null; echo "Marker: ", \$m ?? "keiner", "\n"; \$r = setting_get("reserved_renamed"); if (\$r) echo "Ersetzte Kurzwörter: \$r\n";'
EOF
  curl -sS -o /dev/null -w "Startseite: HTTP %{http_code}\n" "$3/"
}

check_test_guard() {
  ssh cyon bash -s <<EOF
cd ~/$TEST_DIR
if [ "\$(cat .user.ini 2>/dev/null)" = '$TEST_USER_INI' ] && [ "\$(stat -c %a .user.ini)" = 444 ]; then echo 'open_basedir: .user.ini unverändert (444)'; else echo 'WARNUNG: .user.ini der Testinstanz fehlt oder wurde verändert'; fi
php -r '\$c = require "config.php"; if ((\$c["instance"] ?? null) !== "test") echo "WARNUNG: Testinstanz ohne instance = test\n";'
EOF
}

# Sichert Code und Datenbank einer Installation nach ~/backups_pi.
backup_one() {  # $1 Präfix, $2 Ordner
  ssh cyon bash -s <<EOF
set -euo pipefail
mkdir -p ~/backups_pi && chmod 700 ~/backups_pi
tar -czf ~/backups_pi/$1-$STAMP.tar.gz -C ~/$2 --exclude=./uploads .
cd ~/$2
umask 077
CNF=\$(mktemp ~/.tafula-dump-XXXXXX.cnf)
trap 'rm -f "\$CNF"' EXIT
php -r '\$c = require "config.php"; \$d = \$c["db"]; \$h = explode(";", \$d["host"])[0]; \$p = preg_match("/port=(\\d+)/", \$d["host"], \$m) ? \$m[1] : "3306"; echo "[client]\nhost=\$h\nport=\$p\nuser=\$d[user]\npassword=\"", addcslashes(\$d["pass"], "\\\\\""), "\"\n";' > "\$CNF"
DBNAME=\$(php -r '\$c = require "config.php"; echo \$c["db"]["name"];')
mariadb-dump --defaults-extra-file="\$CNF" --single-transaction --no-tablespaces --routines "\$DBNAME" | gzip > ~/backups_pi/$1-db-$STAMP.sql.gz
echo "Sicherung: ~/backups_pi/$1-$STAMP.tar.gz und $1-db-$STAMP.sql.gz (\$(du -h ~/backups_pi/$1-db-$STAMP.sql.gz | cut -f1))"
find ~/backups_pi -maxdepth 1 -name 'tafula-*' -type f -mtime +30 -delete
EOF
}

deploy_one() {  # $1 Bezeichnung, $2 Präfix, $3 Ordner, $4 Adresse, $5 Zweig
  local target live
  target=$(git rev-parse --short "origin/$5")
  live=$(ssh cyon "cd ~/$3 && git rev-parse --short HEAD")
  local branch
  branch=$(ssh cyon "cd ~/$3 && git rev-parse --abbrev-ref HEAD")
  echo "== $1: cyon $live ($branch) · GitHub $5: $target"
  if [ "$live" = "$target" ] && [ "$branch" = "$5" ]; then echo "$1 ist auf dem Stand von $5, nichts zu tun."; return; fi
  backup_one "$2" "$3"
  ssh cyon bash -s <<EOF
set -euo pipefail
cd ~/$3
git fetch -q origin
if [ "\$(git rev-parse --abbrev-ref HEAD)" != "$5" ]; then
  git checkout -q "$5" 2>/dev/null || git checkout -q -b "$5" "origin/$5"
fi
git pull -q --ff-only origin "$5"
php -r '\$c = require "config.php"; if (empty(\$c["admin_path"])) fwrite(STDERR, "WARNUNG: admin_path fehlt in config.php\n");'
php -r 'require "lib/bootstrap.php"; echo "Migrationen ausgeführt: ", db_migrate(), ", Schema ", setting_get("schema_version"), "\n"; \$r = setting_get("reserved_renamed"); if (\$r) echo "Ersetzte Kurzwörter: \$r\n";'
mkdir -p ~/.cyon && printf '%s %s: Update auf %s (%s) vom Pi (Sicherung ~/backups_pi/$2-%s)\n' "\$(date -Is)" "$1" "\$(git rev-parse --short HEAD)" "$5" "$STAMP" >> ~/.cyon/journal.md
git log -1 --format='Aktualisiert auf Stand %h %s'
EOF
  curl -sS -o /dev/null -w "Startseite: HTTP %{http_code}\n" "$4/"
  mkdir -p .cyon
  printf '%s %s: %s -> %s (%s), Sicherung ~/backups_pi/%s-%s\n' "$(date -Is)" "$1" "$live" "$target" "$5" "$2" "$STAMP" >> .cyon/journal.md
}

case "$MODE" in
  --check)
    check_one 'Produktion' "$PROD_DIR" "$PROD_URL" "$PROD_BRANCH"
    if ssh cyon "test -d ~/$TEST_DIR/.git"; then
      check_one 'Testinstanz' "$TEST_DIR" "$TEST_URL" "$TEST_BRANCH"
      check_test_guard
    else
      echo "== Testinstanz ($TEST_URL): noch nicht eingerichtet"
    fi
    ;;
  --seed)
    backup_one tafula-test "$TEST_DIR"
    ssh cyon "cd ~/$TEST_DIR && php tools/seed.php $SEED --reset"
    printf '%s Testinstanz: Testbestand %s neu geladen\n' "$(date -Is)" "$SEED" >> .cyon/journal.md
    ;;
  --test) deploy_one 'Testinstanz' tafula-test "$TEST_DIR" "$TEST_URL" "$TEST_BRANCH" ;;
  --prod) deploy_one 'Produktion' tafula "$PROD_DIR" "$PROD_URL" "$PROD_BRANCH" ;;
  all)
    deploy_one 'Testinstanz' tafula-test "$TEST_DIR" "$TEST_URL" "$TEST_BRANCH"
    deploy_one 'Produktion' tafula "$PROD_DIR" "$PROD_URL" "$PROD_BRANCH"
    ;;
esac
