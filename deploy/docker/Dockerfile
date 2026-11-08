# GLPI 11.0.11 + redesigned login page, on PHP 8.4.27 / Apache
#
# Build:  docker build -t glpi-custom:11.0.11 .
# Run:    docker compose up -d   (see docker-compose.yml and README.md)

FROM php:8.4.27-apache

ARG GLPI_VERSION=11.0.11

# ---------------------------------------------------------------------
# PHP extensions required / recommended by GLPI 11
# (curl, dom, fileinfo, filter, libxml, simplexml, xmlreader, xmlwriter,
#  mbstring, openssl, sodium and zlib are already built into the image)
# ---------------------------------------------------------------------
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates curl \
        libbz2-dev libfreetype6-dev libicu-dev libjpeg62-turbo-dev \
        libldap2-dev libpng-dev libxml2-dev libzip-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-configure ldap --with-libdir="lib/$(dpkg-architecture -qDEB_HOST_MULTIARCH)"; \
    docker-php-ext-install -j"$(nproc)" \
        bcmath bz2 exif gd intl ldap mysqli opcache zip; \
    rm -rf /var/lib/apt/lists/*; \
    php -v; \
    php -m

COPY php.ini /usr/local/etc/php/conf.d/glpi.ini

# ---------------------------------------------------------------------
# Apache: serve GLPI from /public with URL rewriting
# ---------------------------------------------------------------------
RUN a2enmod rewrite headers
COPY apache-glpi.conf /etc/apache2/sites-available/000-default.conf

# ---------------------------------------------------------------------
# GLPI official release + login page overlay
# ---------------------------------------------------------------------
RUN set -eux; \
    curl -fsSL -o /tmp/glpi.tgz \
        "https://github.com/glpi-project/glpi/releases/download/${GLPI_VERSION}/glpi-${GLPI_VERSION}.tgz"; \
    tar -xzf /tmp/glpi.tgz -C /var/www; \
    rm /tmp/glpi.tgz

COPY overlay/ /var/www/glpi/

RUN set -eux; \
    cd /var/www/glpi; \
    php bin/console build:compile_scss --file=css/standalone/login --allow-superuser --no-interaction; \
    chown -R www-data:www-data /var/www/glpi

# Persistent data (configuration, documents, plugins, logs)
VOLUME ["/var/www/glpi/config", "/var/www/glpi/files", "/var/www/glpi/marketplace"]

WORKDIR /var/www/glpi
EXPOSE 80
