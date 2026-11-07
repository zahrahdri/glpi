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
: "${GLPI_ADMIN_USER:=glpi}"

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
    # Existing install (also settings copied from another server): point it at this database.
    log "Existing installation found: updating the database connection settings..."
    console database:configure --reconfigure \
        --db-host="$GLPI_DB_HOST" --db-port="$GLPI_DB_PORT" --db-name="$GLPI_DB_NAME" \
        --db-user="$GLPI_DB_USER" --db-password="$GLPI_DB_PASSWORD" >/dev/null

    # This code is GLPI's 12.0 development line (x.y.z-dev), so updating from a stable release needs --allow-unstable.
    log "Applying database updates, if any (GLPI 11 -> 12 takes a few minutes)..."
    update_log="$(mktemp)"
    if ! console database:update --allow-unstable --skip-db-checks 2>&1 | tee "$update_log"; then
        log "The database update failed; GLPI is not started. Check the messages above."
        exit 1
    fi
    if ! grep -q 'No migration needed' "$update_log"; then
        # After an upgrade GLPI pauses all plugins until an admin resumes them. The plugins in this image are
        # the versions made for this GLPI, so resume them; their own updates run just below.
        log "GLPI was upgraded: resuming plugins so they can be updated too."
        console plugin:resume_execution >/dev/null 2>&1 || true
    fi
    rm -f "$update_log"
    console cache:clear >/dev/null 2>&1 || true
fi

# Plugins run their install/update as a Super-Admin account: GLPI_ADMIN_USER if it exists,
# otherwise the first active Super-Admin (a migrated server may have renamed "glpi").
PLUGIN_USER="$(php -r '
    mysqli_report(MYSQLI_REPORT_OFF);
    $c = @new mysqli(getenv("GLPI_DB_HOST"), getenv("GLPI_DB_USER"), getenv("GLPI_DB_PASSWORD"), getenv("GLPI_DB_NAME"), (int) getenv("GLPI_DB_PORT"));
    if ($c->connect_errno) { exit; }
    $want = $c->real_escape_string(getenv("GLPI_ADMIN_USER"));
    $r = $c->query("SELECT u.name FROM glpi_users u JOIN glpi_profiles_users pu ON pu.users_id = u.id JOIN glpi_profiles p ON p.id = pu.profiles_id
                    WHERE u.is_active = 1 AND u.is_deleted = 0 AND p.interface = \"central\" AND (p.id = 4 OR p.name = \"Super-Admin\")
                    ORDER BY (u.name = \"$want\") DESC, u.id LIMIT 1");
    if ($r && ($row = $r->fetch_row())) { echo $row[0]; }
')"
[ -n "$PLUGIN_USER" ] && log "Plugins are installed as user \"$PLUGIN_USER\"." || log "No active Super-Admin found; plugins are installed without a user."

# Plugins: install (or update when the image has a newer version), then enable.
for p in $GLPI_PLUGINS; do
    [ -d "/var/www/glpi/plugins/$p" ] || { log "Plugin $p not found, skipped."; continue; }
    if [ -n "$PLUGIN_USER" ]; then
        out="$(console plugin:install --username="$PLUGIN_USER" "$p" 2>&1)" || true
    else
        out="$(console plugin:install "$p" 2>&1)" || true
    fi
    console plugin:activate "$p" >/dev/null 2>&1 || true
    version="$(console plugin:list 2>/dev/null | awk -F'|' -v p="$p" '$2 ~ "^ *"p" *$" {gsub(/ /,"",$4); gsub(/^ +| +$/,"",$5); print $4" ("$5")"}')"
    log "Plugin $p: ${version:-see below}"
    case "$out" in *"already installed"*|*"has been installed"*|"") ;; *) echo "$out" | tail -5 ;; esac
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
