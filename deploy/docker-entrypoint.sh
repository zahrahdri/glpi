#!/bin/bash
# Prepares GLPI before Apache starts:
#   first start  -> creates the database, installs and enables the plugins (which also imports the capacity forms)
#   later starts -> applies database updates and updates plugins whose version changed in the image
set -euo pipefail

: "${GLPI_DB_HOST:=db}"
: "${GLPI_DB_PORT:=3306}"
: "${GLPI_DB_NAME:=glpi}"
: "${GLPI_DB_USER:=glpi}"
: "${GLPI_DB_PASSWORD:?Set GLPI_DB_PASSWORD (see deploy/.env.example)}"
: "${GLPI_LANGUAGE:=en_GB}"
: "${GLPI_PLUGINS:=datainjection order projectreports}"
: "${GLPI_DEMO_DATA:=0}"

log() { echo "[glpi] $*"; }
console() { runuser -u www-data -- php /var/www/glpi/bin/console --no-interaction "$@"; }

# Folders GLPI expects in its data volume.
for d in "$GLPI_CONFIG_DIR" "$GLPI_VAR_DIR" /var/www/glpi/marketplace; do mkdir -p "$d"; done
for d in _cache _cron _dumps _graphs _inventories _locales _lock _log _pictures _plugins _rss _sessions _themes _tmp _uploads; do
    mkdir -p "$GLPI_VAR_DIR/$d"
done
chown -R www-data:www-data "$GLPI_CONFIG_DIR" "$GLPI_VAR_DIR" /var/www/glpi/marketplace

# Wait for the database (up to 2 minutes).
log "Waiting for the database at $GLPI_DB_HOST:$GLPI_DB_PORT..."
for i in $(seq 1 60); do
    if php -r '
        mysqli_report(MYSQLI_REPORT_OFF);
        $c = @new mysqli(getenv("GLPI_DB_HOST"), getenv("GLPI_DB_USER"), getenv("GLPI_DB_PASSWORD"), getenv("GLPI_DB_NAME"), (int) getenv("GLPI_DB_PORT"));
        exit($c->connect_errno ? 1 : 0);'; then
        break
    fi
    [ "$i" -eq 60 ] && { log "Database not reachable, giving up."; exit 1; }
    sleep 2
done

if [ ! -f "$GLPI_CONFIG_DIR/config_db.php" ]; then
    log "First start: installing GLPI (this takes a few minutes)..."
    if ! console database:install \
        --db-host="$GLPI_DB_HOST" --db-port="$GLPI_DB_PORT" --db-name="$GLPI_DB_NAME" \
        --db-user="$GLPI_DB_USER" --db-password="$GLPI_DB_PASSWORD" \
        --default-language="$GLPI_LANGUAGE"; then
        log "Install failed. If the database already holds GLPI tables but the config volume is new,"
        log "remove both volumes (docker compose down -v) to start fresh, or restore the config volume."
        exit 1
    fi
else
    log "Applying database updates, if any..."
    console database:update --skip-db-checks || log "database:update reported a problem; check the logs above."
fi

# Plugins: install (or update when the image has a newer version), then enable.
for p in $GLPI_PLUGINS; do
    [ -d "/var/www/glpi/plugins/$p" ] || { log "Plugin $p not found, skipped."; continue; }
    console plugin:install --username=glpi "$p" >/dev/null 2>&1 || true
    console plugin:activate "$p" >/dev/null 2>&1 || true
    log "Plugin $p ready."
done

# Optional sample data for the reports, created once.
if [ "$GLPI_DEMO_DATA" = "1" ] && [ ! -f "$GLPI_VAR_DIR/.demo-data-loaded" ]; then
    log "Loading Project reports demo data..."
    if console plugins:projectreports:demo; then
        touch "$GLPI_VAR_DIR/.demo-data-loaded"
    fi
fi

chown -R www-data:www-data "$GLPI_CONFIG_DIR" "$GLPI_VAR_DIR"
log "GLPI is ready."
exec "$@"
