# GLPI 11.0.11 with the redesigned login page (Docker)

This folder builds a Docker image with:

- **PHP 8.4.27** on Apache (`php:8.4.27-apache`)
- the official **GLPI 11.0.11** release
- the split-screen **login page** (`overlay/`) copied on top of it

## Files

| File | Purpose |
|---|---|
| `Dockerfile` | Builds the image |
| `docker-compose.yml` | GLPI + cron worker + MariaDB 11.4 |
| `php.ini` | PHP settings for GLPI |
| `apache-glpi.conf` | Apache site serving `/var/www/glpi/public` |
| `overlay/` | The new login page files |
| `.env.example` | Database passwords |

## Install on your server

Requires Docker with the Compose plugin.

```bash
cd deploy/docker
cp .env.example .env        # then edit .env and set strong passwords
docker compose build
docker compose up -d
```

Open `http://YOUR-SERVER:8080` and follow the GLPI installer. On the
database step use:

- **SQL server:** `db`
- **SQL user:** `glpi`
- **SQL password:** the `DB_PASSWORD` value from `.env`
- **Database:** `glpi`

After installation, GLPI asks you to delete the installer; run:

```bash
docker compose exec glpi rm -f /var/www/glpi/install/install.php
```

Default GLPI accounts are `glpi/glpi`, `tech/tech`, `normal/normal` and
`post-only/postonly`. Change these passwords right away.

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

- Data is kept in Docker volumes (`glpi_config`, `glpi_files`,
  `glpi_marketplace`, `glpi_db`), so rebuilding the image does not lose it.
- If you serve GLPI over HTTPS, set `session.cookie_secure = On` in
  `php.ini` and rebuild.
- The time zone is set to `Asia/Tehran` in `php.ini`; change it if needed.
- To change the login page, edit the files in `overlay/` and run
  `docker compose build && docker compose up -d`.
