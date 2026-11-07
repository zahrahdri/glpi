# Run GLPI with Docker Compose

A self-contained setup: one image with GLPI 12, PHP 8.5, Apache and the plugins in `plugins/`
(Data injection, Orders, Project reports), plus a MariaDB 11.4 database.
Works on Mac (Apple Silicon and Intel), Linux and Windows with Docker Desktop.

## First run

From the repository root:

```bash
cp deploy/.env.example deploy/.env      # then edit deploy/.env and set the two passwords
docker compose -f deploy/compose.yaml up -d --build
docker compose -f deploy/compose.yaml logs -f app   # wait for "[glpi] GLPI is ready.", then Ctrl+C
```

Open **http://localhost:8080** and log in with `glpi` / `glpi`.
Change the passwords of the default accounts (`glpi`, `tech`, `normal`, `post-only`) straight away.

The first build takes 10–20 minutes (it downloads PHP and JavaScript libraries). The first start installs the database,
enables the three plugins and imports the four capacity request forms into Administration › Forms.

Set `GLPI_DEMO_DATA=1` in `deploy/.env` before the first start to load sample projects, budgets, orders and racks for
Tools › Project reports. Everything it creates is named `DEMO`.

## Everyday commands

| What | Command (from the repository root) |
|---|---|
| Stop | `docker compose -f deploy/compose.yaml stop` |
| Start again | `docker compose -f deploy/compose.yaml start` |
| Logs | `docker compose -f deploy/compose.yaml logs -f app` |
| GLPI console | `docker compose -f deploy/compose.yaml exec app runuser -u www-data -- php bin/console <command>` |
| Update after `git pull` | `docker compose -f deploy/compose.yaml up -d --build` |
| Remove everything, data included | `docker compose -f deploy/compose.yaml down -v` |

Updating rebuilds the image and restarts it; on start the database and the plugins are updated automatically.
Your data stays in the Docker volumes (`glpi_config`, `glpi_files`, `glpi_marketplace`, `db_data`).

## Backups

```bash
docker compose -f deploy/compose.yaml exec db sh -c 'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" glpi' > glpi.sql
docker run --rm -v glpi-capacity_glpi_files:/data -v "$PWD":/backup alpine tar czf /backup/glpi-files.tgz -C /data .
docker run --rm -v glpi-capacity_glpi_config:/data -v "$PWD":/backup alpine tar czf /backup/glpi-config.tgz -C /data .
```

Keep `glpi-config.tgz` with the database dump: it holds the key GLPI uses to encrypt stored passwords.

## Files

- `Dockerfile` builds the image (JavaScript libraries in a Node stage, then PHP + Apache).
- `docker-entrypoint.sh` waits for the database, installs or updates GLPI and the plugins, then starts Apache.
- `apache-glpi.conf`, `php-glpi.ini` are the web server and PHP settings.
- `initdb/` runs once when the database is created (time zone access for GLPI).

This is separate from the repository's `docker-compose.yaml`, which is GLPI's development environment.
For a server reachable from a network, put it behind HTTPS (a reverse proxy) and use strong passwords.
