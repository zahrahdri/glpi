# syntax=docker/dockerfile:1
#
# Self-contained GLPI image: code, PHP/JS dependencies, compiled translations and the plugins in plugins/.
# Built from the repository root:  docker compose -f deploy/compose.yaml build   (see deploy/README.md)

ARG PHP_VERSION=8.4.27

# ---- 1. JavaScript/CSS libraries (public/lib, public/build) ----
# Full image (not -slim): it has git, python3, make and a compiler, which some npm install scripts call.
FROM node:24-bookworm AS assets
WORKDIR /src
# .npmrc matters: it allows a known peer-dependency mismatch (legacy-peer-deps); without it npm ci stops with ERESOLVE.
COPY package.json package-lock.json .npmrc ./
COPY tools/patches/npm tools/patches/npm
# --ignore-scripts: npm would otherwise run package.json's "dependencies" script, which calls PHP (not in this stage)
# to write .package.hash; that file is written in the PHP stage below. The only script the build needs is
# patch-package (GLPI's fix for gettext.js), run by hand. On failure, npm's own log is printed.
RUN { npm ci --no-audit --no-fund --ignore-scripts \
      && node node_modules/patch-package/index.js --patch-dir tools/patches/npm; } \
    || { code=$?; echo "===== npm failed (exit $code); end of npm's log:"; tail -n 60 /root/.npm/_logs/*-debug-0.log 2>/dev/null; \
         echo "===== node $(node -v), npm $(npm -v), $(uname -m)"; exit $code; }
COPY . .
RUN npm run build:pack && npm run build:vue

# ---- 2. Runtime: PHP + Apache ----
FROM php:${PHP_VERSION}-apache

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Debian packages over HTTPS (some networks block plain HTTP to deb.debian.org). DEBIAN_MIRROR replaces
# deb.debian.org for the main packages (<mirror>/debian); security updates stay on deb.debian.org, over HTTPS.
ARG DEBIAN_MIRROR=https://deb.debian.org
RUN set -eux; \
    for f in /etc/apt/sources.list.d/debian.sources /etc/apt/sources.list; do \
        [ -f "$f" ] || continue; \
        sed -i -E 's#http://(deb|security)\.debian\.org#https://\1.debian.org#g' "$f"; \
        sed -i -E "s#https://deb\.debian\.org/debian([[:space:]]|\$)#${DEBIAN_MIRROR%/}/debian\1#g" "$f"; \
    done; \
    grep -rh '^URIs:\|^deb ' /etc/apt/sources.list.d/ /etc/apt/sources.list 2>/dev/null || true

RUN set -eux; \
    apt-get -o Acquire::Retries=3 -o Acquire::http::Timeout=30 update; \
    apt-get install -y --no-install-recommends gettext git patch unzip; \
    install-php-extensions bcmath bz2 exif gd intl ldap mysqli zip; \
    rm -rf /var/lib/apt/lists/*; \
    a2enmod rewrite headers

COPY deploy/apache-glpi.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/php-glpi.ini "$PHP_INI_DIR/conf.d/zz-glpi.ini"
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /var/www/glpi
COPY . .
COPY --from=assets /src/public/build public/build
COPY --from=assets /src/public/lib public/lib

ENV COMPOSER_ALLOW_SUPERUSER=1
RUN set -eux; \
    composer install --no-dev --optimize-autoloader --no-interaction --no-progress; \
    php -r "file_put_contents('.package.hash', sha1_file('package-lock.json'));"; \
    for po in locales/*.po; do msgfmt -o "${po%.po}.mo" "$po"; done; \
    # Developer-only parts: tools/ also holds console commands that need dev dependencies.
    rm -rf tools tests .docker .devcontainer .github deploy node_modules; \
    rm /usr/local/bin/composer; \
    mkdir -p marketplace; \
    chown -R www-data:www-data /var/www/glpi

# Data outside the code, so a rebuilt image keeps the configuration, documents and logs.
ENV GLPI_CONFIG_DIR=/var/glpi/config \
    GLPI_VAR_DIR=/var/glpi/files \
    GLPI_LOG_DIR=/var/glpi/files/_log \
    TZ=UTC

COPY deploy/docker-entrypoint.sh /usr/local/bin/glpi-entrypoint
RUN chmod +x /usr/local/bin/glpi-entrypoint

VOLUME ["/var/glpi/config", "/var/glpi/files", "/var/www/glpi/marketplace"]
EXPOSE 80
ENTRYPOINT ["glpi-entrypoint"]
CMD ["apache2-foreground"]
