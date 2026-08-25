This folder holds a legacy php application that is used to display a url list for a team of users. 

The files included here are:

index.php - The public URL list (browsing, click tracking, category tabs/search)

admin.php - The admin panel (login, add/delete URLs, approve/reject submitted URLs). Session-gated; visiting it while logged out shows a login form.

index_new_ui.php - The original standalone UI mockup this design was merged in from. Kept for reference; not served by the app.

style.css - The stylesheet

global.php - PHP Functions and Global Settings. 

url_list.sql - DB structure, plus seed data (a few sample categories/URLs and a default admin user)

Dockerfile / docker-compose.yml - Containerized dev/deploy setup (PHP+Apache app container, MySQL container)


## Running with Docker

    docker compose up --build

This starts the app at http://localhost:8081 (and https://localhost:8443 — the container generates a self-signed cert at build time, so browsers/curl will flag it as untrusted; use `curl -k` or click through the browser warning) and a MySQL container seeded from `url_list.sql` (schema + sample categories/URLs + a default admin user). DB connection settings come from environment variables read by `global.php` (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`); edit the `environment:` blocks in `docker-compose.yml` to change credentials. Data persists in the `db_data` Docker volume across restarts — the seed data only loads on a fresh volume, so run `docker compose down -v` first if you want to reset back to the seed data.

Default admin login (seeded by `url_list.sql`): username `admin`, password `admin123`. **Change or remove this before using the seed data anywhere but local dev.**

### Schema updates

`submitted_urls` gained a `description` column so public submissions can include one. On a fresh `db_data` volume this is picked up automatically from `url_list.sql`. On an existing volume, run this migration instead:

    ALTER TABLE submitted_urls ADD COLUMN description TEXT AFTER url;
