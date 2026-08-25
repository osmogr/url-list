This folder holds a legacy php application that is used to display a url list for a team of users. 

The files included here are:

index.php - The public URL list (browsing, click tracking, category tabs/search)

admin.php - The admin panel (login, add/delete URLs, approve/reject submitted URLs). Session-gated; visiting it while logged out shows a login form.

index_new_ui.php - The original standalone UI mockup this design was merged in from. Kept for reference; not served by the app.

style.css - The stylesheet

global.php - PHP Functions and Global Settings. 

url_list.sql - DB structure, plus seed data (a few sample categories/URLs and a default admin user)

migrate.php - Idempotent schema migration runner; brings an existing database up to the latest schema (see "Schema migrations" below)

Dockerfile / docker-compose.yml / entrypoint.sh - Containerized dev/deploy setup (PHP+Apache app container, MySQL container); the entrypoint runs `migrate.php` before Apache starts


## Running with Docker

    docker compose up --build

This starts the app at http://localhost:8081 (and https://localhost:8443 — the container generates a self-signed cert at build time, so browsers/curl will flag it as untrusted; use `curl -k` or click through the browser warning) and a MySQL container seeded from `url_list.sql` (schema + sample categories/URLs + a default admin user). DB connection settings come from environment variables read by `global.php` (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`); edit the `environment:` blocks in `docker-compose.yml` to change credentials. Data persists in the `db_data` Docker volume across restarts — the seed data only loads on a fresh volume, so run `docker compose down -v` first if you want to reset back to the seed data.

Default admin login (seeded by `url_list.sql`): username `admin`, password `admin123`. **Change or remove this before using the seed data anywhere but local dev.**

### Schema migrations

`migrate.php` brings a database up to the latest schema — it creates any missing tables/columns and is safe to run any number of times against an empty database, a database from an older version of this app, or one that's already current; unaffected schema is left untouched either way. The container's `entrypoint.sh` runs it automatically before Apache starts on every `docker compose up`, so upgrading an existing deployment (pulling a new image on top of an existing `db_data` volume) needs no manual steps.

Running outside Docker: run `php migrate.php` (same `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASSWORD` environment variables as the app) after pulling changes and before serving traffic, e.g. as part of your deploy script.

To add a schema change going forward, append a new entry to the `$migrations` array in `migrate.php` rather than editing `url_list.sql` in place or documenting a manual `ALTER TABLE` here.
