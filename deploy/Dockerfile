# syntax=docker/dockerfile:1
#
# Self-contained GLPI image: code, PHP/JS dependencies, compiled translations and the plugins in plugins/.
# Built from the repository root:  docker compose -f deploy/compose.yaml build   (see deploy/README.md)

ARG PHP_VERSION=8.5

# ---- 1. JavaScript/CSS libraries (public/lib, public/build) ----
FROM node:24-bookworm-slim AS assets
WORKDIR /src
COPY package.json package-lock.json ./
COPY tools/patches/npm tools/patches/npm
RUN npm ci --no-audit --no-fund
COPY . .
RUN npm run build:pack && npm run build:vue

# ---- 2. Runtime: PHP + Apache ----
FROM php:${PHP_VERSION}-apache

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends gettext patch unzip; \
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
