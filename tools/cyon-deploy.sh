#!/bin/bash
# Tafula auf cyon ausrollen. Läuft auf dem Raspberry Pi im Workspace /srv/workspaces/wedo-board
# (Connector «AlayaPi5», Werkzeug run_command), nicht auf einem Laptop. SSH über den Alias «cyon»
# mit dem eigenen Schlüssel des Pi, ohne Passphrase.
#
#   tools/cyon-deploy.sh --check   nur lesend: Stand auf cyon mit GitHub main vergleichen
#   tools/cyon-deploy.sh           sichern, ausrollen (git pull --ff-only), Migrationen, Prüfung
#
# Der Bibliothekspfad (admin_path) steht nur in config.php auf cyon; das Skript prüft nur, dass er
# gesetzt ist, und gibt ihn nie aus. config.php und uploads/ werden nicht verändert.
set -euo pipefail
SITE_DIR='public_html/tafula.top'
URL='https://tafula.top'
cd "$(dirname "$0")/.."

git fetch -q origin main
TARGET=$(git rev-parse --short origin/main)
LIVE=$(ssh cyon "cd ~/$SITE_DIR && git rev-parse --short HEAD")
echo "cyon: $LIVE · GitHub main: $TARGET"

if [ "${1:-}" = "--check" ]; then
  ssh cyon bash -s <<EOF
set -euo pipefail
cd ~/$SITE_DIR
git log -1 --format='Live: %h %s (%ci)'
if [ -n "\$(git status --porcelain --untracked-files=no)" ]; then echo 'WARNUNG: Änderungen an versionierten Dateien auf cyon'; fi
php -r '\$c = require "config.php"; echo empty(\$c["admin_path"]) ? "WARNUNG: admin_path fehlt in config.php\n" : "admin_path ist gesetzt\n";'
php -r 'require "lib/bootstrap.php"; echo "Schema: ", setting_get("schema_version"), " (Code erwartet ", WB_SCHEMA_VERSION, ")\n";'
EOF
  curl -sS -o /dev/null -w "Startseite: HTTP %{http_code}\n" "$URL/"
  exit 0
fi

if [ "$LIVE" = "$TARGET" ]; then echo 'cyon ist auf dem Stand von main, nichts zu tun.'; exit 0; fi

STAMP=$(date +%Y%m%d-%H%M%S)
ssh cyon bash -s <<EOF
set -euo pipefail
mkdir -p ~/backups_pi
tar -czf ~/backups_pi/tafula-$STAMP.tar.gz -C ~/$SITE_DIR --exclude=./uploads .
cd ~/$SITE_DIR
git pull -q --ff-only origin main
php -r '\$c = require "config.php"; if (empty(\$c["admin_path"])) fwrite(STDERR, "WARNUNG: admin_path fehlt in config.php\n");'
php -r 'require "lib/bootstrap.php"; echo "Migrationen ausgeführt: ", db_migrate(), ", Schema ", setting_get("schema_version"), "\n";'
mkdir -p ~/.cyon && printf '%s Tafula: Update auf %s vom Pi (Sicherung ~/backups_pi/tafula-%s.tar.gz)\n' "\$(date -Is)" "\$(git rev-parse --short HEAD)" "$STAMP" >> ~/.cyon/journal.md
git log -1 --format='Aktualisiert auf Stand %h %s'
EOF
curl -sS -o /dev/null -w "Startseite: HTTP %{http_code}\n" "$URL/"
mkdir -p .cyon
printf '%s Ausrollen %s -> %s, Sicherung ~/backups_pi/tafula-%s.tar.gz\n' "$(date -Is)" "$LIVE" "$TARGET" "$STAMP" >> .cyon/journal.md
