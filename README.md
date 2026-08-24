This application is used to store a list of urls for a team of users. 

## Running with Docker

    docker compose up --build

This starts the app at http://localhost:8081 and a MySQL container seeded from `url_list.sql` (schema + sample categories/URLs + a default admin user). DB connection settings come from environment variables read by `global.php` (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`); edit the `environment:` blocks in `docker-compose.yml` to change credentials. Data persists in the `db_data` Docker volume across restarts — the seed data only loads on a fresh volume, so run `docker compose down -v` first if you want to reset back to the seed data.

Default admin login (seeded by `url_list.sql`): username `admin`, password `admin123`. **Change or remove this before using the seed data anywhere but local dev.**

### Schema updates

`submitted_urls` gained a `description` column so public submissions can include one. On a fresh `db_data` volume this is picked up automatically from `url_list.sql`. On an existing volume, run this migration instead:

    ALTER TABLE submitted_urls ADD COLUMN description TEXT AFTER url;
