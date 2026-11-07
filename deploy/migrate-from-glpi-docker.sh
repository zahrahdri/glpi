#!/bin/bash
# Moves an existing GLPI running in Docker (e.g. the "glpi-docker" setup: containers glpi-app + glpi-db,
# GLPI in /var/www/html/glpi) to this setup, keeping its database, settings and documents.
#
# The old containers and volumes are only READ and stopped, never changed or deleted, so you can go back:
#   docker compose -f deploy/compose.yaml stop && docker start glpi-db glpi-app
#
# Run from the repository root:   bash deploy/migrate-from-glpi-docker.sh
# Options (environment variables): OLD_APP=glpi-app OLD_DB=glpi-db OLD_GLPI_PATH=/var/www/html/glpi
set -euo pipefail

OLD_APP="${OLD_APP:-glpi-app}"
OLD_DB="${OLD_DB:-glpi-db}"
OLD_GLPI_PATH="${OLD_GLPI_PATH:-/var/www/html/glpi}"
OUR_PLUGINS="datainjection order projectreports"

cd "$(dirname "$0")/.."
COMPOSE="docker compose -f deploy/compose.yaml"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="$PWD/backups/$STAMP"

say()  { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
fail() { printf '\n\033[31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

# ---------------------------------------------------------------- 1. checks
say "Checking the old setup ($OLD_APP + $OLD_DB)"
docker inspect "$OLD_APP" >/dev/null 2>&1 || fail "Container $OLD_APP not found. Set OLD_APP=<name> (see: docker ps -a)."
docker inspect "$OLD_DB"  >/dev/null 2>&1 || fail "Container $OLD_DB not found. Set OLD_DB=<name> (see: docker ps -a)."

OLD_VOLUME="$(docker inspect -f '{{range .Mounts}}{{if eq .Destination "'"$OLD_GLPI_PATH"'"}}{{.Name}}{{end}}{{end}}' "$OLD_APP")"
[ -n "$OLD_VOLUME" ] || fail "No volume mounted at $OLD_GLPI_PATH in $OLD_APP. Set OLD_GLPI_PATH=<path>."
echo "Old GLPI volume: $OLD_VOLUME"

if [ ! -f deploy/.env ]; then
    say "Creating deploy/.env with new random database passwords"
    cp deploy/.env.example deploy/.env
    sed -i.bak "s/^DB_PASSWORD=.*/DB_PASSWORD=$(openssl rand -hex 16)/; s/^DB_ROOT_PASSWORD=.*/DB_ROOT_PASSWORD=$(openssl rand -hex 16)/" deploy/.env
    rm -f deploy/.env.bak
fi
grep -q '^GLPI_DEMO_DATA=1' deploy/.env && fail "GLPI_DEMO_DATA=1 in deploy/.env: set it to 0 so no demo data is added to your real data."

echo
echo "This will:"
echo "  - build the new image (nothing is stopped if the build fails)"
echo "  - stop $OLD_APP and $OLD_DB (not delete them) and back them up to backups/$STAMP/"
echo "  - RESET the new setup's own volumes (glpi-capacity_*) and fill them with a copy of your data"
echo "  - upgrade that copy from GLPI 11 to 12 and update the plugins"
read -r -p "Continue? [y/N] " answer
case "$answer" in y|Y|yes|YES) ;; *) echo "Nothing changed."; exit 0 ;; esac

# ---------------------------------------------------------------- 2. build first
say "Building the new image (10-20 minutes the first time)"
$COMPOSE build app

# ---------------------------------------------------------------- 3. back up the old setup
mkdir -p "$BACKUP_DIR"
say "Backing up the old database"
docker start "$OLD_DB" >/dev/null
for i in $(seq 1 30); do
    docker exec "$OLD_DB" sh -c 'mariadb-admin ping -uroot -p"${MARIADB_ROOT_PASSWORD:-$MYSQL_ROOT_PASSWORD}" --silent' >/dev/null 2>&1 && break
    [ "$i" -eq 30 ] && fail "The old database did not start."
    sleep 2
done
docker stop "$OLD_APP" >/dev/null 2>&1 || true   # no changes while copying
docker exec "$OLD_DB" sh -c 'exec mariadb-dump -uroot -p"${MARIADB_ROOT_PASSWORD:-$MYSQL_ROOT_PASSWORD}" \
    --single-transaction --routines --triggers --default-character-set=utf8mb4 \
    "${MARIADB_DATABASE:-$MYSQL_DATABASE}"' > "$BACKUP_DIR/glpi-db.sql"
grep -q 'CREATE TABLE `glpi_configs`' "$BACKUP_DIR/glpi-db.sql" || fail "The database dump does not look like GLPI (see $BACKUP_DIR/glpi-db.sql)."
echo "Database: $(du -h "$BACKUP_DIR/glpi-db.sql" | cut -f1)"

say "Backing up the old settings, documents and plugins"
docker run --rm --entrypoint sh -v "$OLD_VOLUME":/old:ro -v "$BACKUP_DIR":/backup glpi-capacity:latest -c '
    test -f /old/config/config_db.php || { echo "No config/config_db.php in the old volume."; exit 1; }
    tar czf /backup/glpi-config.tgz -C /old config
    tar czf /backup/glpi-files.tgz --exclude="files/_cache" --exclude="files/_sessions" -C /old files
    if [ -d /old/marketplace ]; then tar czf /backup/glpi-marketplace.tgz -C /old marketplace; ls /old/marketplace > /backup/old-marketplace.txt; fi
' || fail "Could not read the old volume."
docker stop "$OLD_DB" >/dev/null
ls -lh "$BACKUP_DIR"

# ---------------------------------------------------------------- 4. fill the new setup
say "Preparing the new setup (its own volumes are reset; the old ones are not touched)"
$COMPOSE down -v --remove-orphans >/dev/null 2>&1 || true
$COMPOSE up -d db
for i in $(seq 1 60); do
    $COMPOSE exec -T db healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1 && break
    [ "$i" -eq 60 ] && fail "The new database did not start (docker compose -f deploy/compose.yaml logs db)."
    sleep 2
done

say "Loading your database into the new MariaDB"
$COMPOSE exec -T db sh -c 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' < "$BACKUP_DIR/glpi-db.sql"

say "Copying your settings and documents"
$COMPOSE run --rm --no-deps --entrypoint sh -v "$OLD_VOLUME":/old:ro app -c '
    cp -a /old/config/. /var/glpi/config/
    cp -a /old/files/. /var/glpi/files/
    rm -rf /var/glpi/files/_cache/* /var/glpi/files/_sessions/*
    chown -R www-data:www-data /var/glpi
'

# ---------------------------------------------------------------- 5. start and upgrade
say "Starting GLPI 12: upgrading the database and plugins (a few minutes)"
$COMPOSE up -d app
for i in $(seq 1 180); do
    logs="$($COMPOSE logs app 2>&1 || true)"
    if echo "$logs" | grep -q '\[glpi\] GLPI is ready.'; then break; fi
    if echo "$logs" | grep -q 'update failed\|giving up\|Install failed'; then
        echo "$logs" | tail -40
        fail "The upgrade stopped. Your old GLPI is unchanged: docker compose -f deploy/compose.yaml stop && docker start $OLD_DB $OLD_APP"
    fi
    [ "$i" -eq 180 ] && { echo "$logs" | tail -40; fail "Timed out waiting for GLPI."; }
    sleep 5
done
$COMPOSE logs app 2>&1 | grep '\[glpi\]' | tail -12

port="$(grep -E '^GLPI_PORT=' deploy/.env | cut -d= -f2)"; port="${port:-8080}"
say "Done. Open http://localhost:$port and log in with your usual account."
others="$(grep -v -x -E "$(echo $OUR_PLUGINS | tr ' ' '|')|\.gitkeep|" "$BACKUP_DIR/old-marketplace.txt" 2>/dev/null || true)"
if [ -n "$others" ]; then
    echo "Other plugins in the old marketplace were NOT moved (they may not support GLPI 12):"
    echo "$others" | sed 's/^/  - /'
fi
echo "Backups: backups/$STAMP/"
echo "Go back to the old GLPI at any time: docker compose -f deploy/compose.yaml stop && docker start $OLD_DB $OLD_APP"
