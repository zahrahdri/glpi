#!/bin/sh
# Installs the redesigned login page into GLPI (in the glpi_data volume),
# then starts the container the same way as the base image.
set -u

OVERLAY=/opt/glpi-login-overlay
TPL=templates/pages/login.html.twig
SCSS=css/standalone/login.scss
log() { echo "[glpi-login] $*"; }

install_login_page() {
    if [ ! -f "$GLPI_DIR/version/$GLPI_LOGIN_VERSION" ]; then
        log "GLPI $GLPI_LOGIN_VERSION not found in $GLPI_DIR (found: $(ls "$GLPI_DIR/version" 2>/dev/null)). Login page NOT changed."
        return 0
    fi

    if cmp -s "$OVERLAY/$TPL" "$GLPI_DIR/$TPL" && cmp -s "$OVERLAY/$SCSS" "$GLPI_DIR/$SCSS"; then
        log "Login page already up to date."
        return 0
    fi

    # Keep the original login template once, for rollback
    if [ ! -f "$GLPI_DIR/$TPL.orig" ]; then
        cp -p "$GLPI_DIR/$TPL" "$GLPI_DIR/$TPL.orig"
        log "Original saved as $TPL.orig"
    fi

    cp "$OVERLAY/$TPL" "$GLPI_DIR/$TPL"
    cp "$OVERLAY/$SCSS" "$GLPI_DIR/$SCSS"
    chown www-data:www-data "$GLPI_DIR/$TPL" "$GLPI_DIR/$SCSS"
    log "Login page files installed."

    # Compile the stylesheet (GLPI compiles it on the fly if this fails)
    if su -s /bin/sh www-data -c "cd '$GLPI_DIR' && php bin/console build:compile_scss --file=css/standalone/login --no-interaction" >/dev/null 2>&1; then
        log "Stylesheet compiled."
    else
        log "Stylesheet not precompiled; GLPI will compile it on first load."
    fi

    # Clear GLPI's compiled-template cache so the new page is used
    for dir in "$GLPI_DIR"/files/_cache/*/templates; do
        [ -d "$dir" ] && rm -rf "$dir" && log "Cleared $dir"
    done
}

install_login_page || log "Login page step failed; starting GLPI anyway."

exec docker-php-entrypoint "$@"
