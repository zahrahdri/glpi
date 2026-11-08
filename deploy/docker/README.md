# GLPI 11.0.11 with the redesigned login page (Docker)

This folder builds a Docker image with:

- **PHP 8.4.27** on Apache (`php:8.4.27-apache`)
- the official **GLPI 11.0.11** release
- the split-screen **login page** (`overlay/`) copied on top of it

It uses your **existing GLPI database**: no database container is
created and the database is not modified. GLPI reads the connection
from your current `config/config_db.php`.

## Files

| File | Purpose |
|---|---|
| `Dockerfile` | Builds the image |
| `docker-compose.yml` | GLPI + cron worker (no database) |
| `php.ini` | PHP settings for GLPI |
| `apache-glpi.conf` | Apache site serving `/var/www/glpi/public` |
| `overlay/` | The new login page files |
| `.env.example` | Paths to your existing GLPI folders |

## Requirements

- Your current GLPI is already **11.0.11** (the image runs 11.0.11 code
  against your database, so the versions must match).
- Docker with the Compose plugin.

## Install on your server

1. **Back up** your database and your GLPI `config`, `files` and
   `marketplace` folders.

2. Point the container at your existing folders:

   ```bash
   cp .env.example .env
   nano .env      # set GLPI_CONFIG_DIR, GLPI_FILES_DIR, GLPI_MARKETPLACE_DIR
   ```

   `GLPI_CONFIG_DIR` must contain `config_db.php` **and** `glpicrypt.key`.
   The key decrypts passwords stored in the database (LDAP, mail
   collectors...), so it must be the original one.

3. **If the database runs on this same server**, the container cannot
   use `localhost`. In `config_db.php` change:

   ```php
   public $dbhost = 'localhost';
   ```
   to
   ```php
   public $dbhost = 'host.docker.internal';
   ```

   Then make sure MySQL/MariaDB accepts the connection from Docker:
   - `bind-address` must not be `127.0.0.1` only (use `0.0.0.0` or the
     Docker bridge IP, usually `172.17.0.1`), and
   - the GLPI database user must be allowed from the Docker network, e.g.
     `'glpi'@'172.%'` instead of only `'glpi'@'localhost'`.

   If the database is on another server, nothing changes.

4. Let the web server user in the container (uid 33, `www-data`) write
   to the folders:

   ```bash
   sudo chown -R 33:33 /path/to/config /path/to/files /path/to/marketplace
   ```

5. Build and start:

   ```bash
   docker compose build
   docker compose up -d
   ```

6. Open `http://YOUR-SERVER:8080` (or the `GLPI_PORT` you set).

Stop your old web server's GLPI site (or its cron job) once the
container works, so GLPI's automatic actions don't run twice.

## Build the image only

```bash
docker build -t glpi-custom:11.0.11 deploy/docker
```

To move it to a server without internet access:

```bash
docker save glpi-custom:11.0.11 | gzip > glpi-custom-11.0.11.tar.gz
# copy the file to the server, then:
docker load < glpi-custom-11.0.11.tar.gz
```

## Notes

- If you serve GLPI over HTTPS, set `session.cookie_secure = On` in
  `php.ini` and rebuild.
- The time zone is set to `Asia/Tehran` in `php.ini`; change it if needed.
- To change the login page, edit the files in `overlay/` and run
  `docker compose build && docker compose up -d`.
