#!/bin/bash
# Runs once, when the database volume is created: lets GLPI read the time zone list
# (Setup > General > Time zones), which it otherwise reports as missing.
set -e
mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -e "GRANT SELECT ON mysql.time_zone_name TO '${MARIADB_USER}'@'%'; FLUSH PRIVILEGES;"
