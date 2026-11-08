# Rebuild of the GLPI app image with the redesigned login page.
#
# Based on your current image (PHP 8.4.27 + Apache), so PHP, extensions
# and Apache settings stay exactly the same.
#
# GLPI itself lives in the glpi_data volume (/var/www/html/glpi), so the
# login page files are copied into it when the container starts.

ARG BASE_IMAGE=glpi-php84:1
FROM ${BASE_IMAGE}

COPY overlay/ /opt/glpi-login-overlay/
COPY glpi-login-entrypoint.sh /usr/local/bin/glpi-login-entrypoint
RUN chmod 755 /usr/local/bin/glpi-login-entrypoint

ENV GLPI_DIR=/var/www/html/glpi \
    GLPI_LOGIN_VERSION=11.0.11

# Same start command as the base image, with the login step in front
ENTRYPOINT ["glpi-login-entrypoint"]
CMD ["apache2-foreground"]
