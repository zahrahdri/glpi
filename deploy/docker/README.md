# Rebuild the GLPI app image with the new login page

For a setup with two containers: `glpi-db` (MariaDB, unchanged) and
`glpi-app` (image `glpi-php84:1`, GLPI 11.0.11 in the `glpi_data` volume).

The new image `glpi-php84:2`:

- is built **from your current `glpi-php84:1`**, so PHP 8.4.27, extensions
  and Apache stay exactly the same;
- on start, copies the new login page into `/var/www/html/glpi`
  (the `glpi_data` volume), compiles its stylesheet and clears GLPI's
  template cache;
- saves the original page once as `templates/pages/login.html.twig.orig`;
- only does this if GLPI **11.0.11** is installed, otherwise leaves the
  page alone and starts normally.

The database container, `glpi_db` and all other GLPI data are not touched.

## Steps

1. Put this folder next to your `docker-compose.yml`, named `glpi-login`:

   ```
   your-folder/
   ├── docker-compose.yml
   ├── glpi-vhost.conf
   └── glpi-login/
       ├── Dockerfile
       ├── glpi-login-entrypoint.sh
       └── overlay/...
   ```

2. In `docker-compose.yml`, change only the `glpi` service's `image:` line:

   ```yaml
     glpi:
       build: ./glpi-login
       image: glpi-php84:2
   ```

   Leave everything else (db, volumes, ports, vhost) as it is.

3. Build and restart only the app:

   ```bash
   docker compose build glpi
   docker compose up -d glpi
   docker compose logs glpi | grep glpi-login
   ```

   You should see `Login page files installed.`

4. Open `http://YOUR-SERVER:8080` and reload with Ctrl+F5.

## Roll back

```bash
docker exec glpi-app sh -c 'cd /var/www/html/glpi &&
  cp templates/pages/login.html.twig.orig templates/pages/login.html.twig &&
  rm -rf files/_cache/*/templates'
```

Then set `image: glpi-php84:1` again (remove the `build:` line) and run
`docker compose up -d glpi`.

## Notes

- After a GLPI upgrade, the page is skipped until `GLPI_LOGIN_VERSION`
  in the `Dockerfile` and the files in `overlay/` are updated for the new
  version.
- To change the login page, edit `overlay/` and repeat step 3.
