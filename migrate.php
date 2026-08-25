<?php
// migrate.php
//
// Idempotent schema migration runner. Safe to run against a brand new
// (empty) database, a database from a previous version of this app, or a
// database that's already fully up to date — running it is always a no-op
// except for whatever has actually changed. Intended to run automatically
// on every container startup (see entrypoint.sh) as well as manually:
//
//   php migrate.php
//
// CLI only: this file lives in the webroot alongside the rest of the app,
// so refuse to run if it's ever hit over HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$servername = getenv('DB_HOST') ?: 'localhost';
$username = getenv('DB_USER') ?: 'username';
$password = getenv('DB_PASSWORD') ?: 'password';
$dbname = getenv('DB_NAME') ?: 'url_list';

// The DB container can report "healthy" slightly before it's ready to
// accept new connections, so retry for a bit instead of failing outright.
$conn = null;
$attempts = 0;
$maxAttempts = 15;
while ($conn === null) {
    $attempts++;
    try {
        $conn = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8mb4", $username, $password);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        if ($attempts >= $maxAttempts) {
            fwrite(STDERR, "migrate.php: could not connect to database after $attempts attempts: " . $e->getMessage() . "\n");
            exit(1);
        }
        sleep(2);
    }
}

function tableExists(PDO $conn, $table) {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table"
    );
    $stmt->execute([':table' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function columnExists(PDO $conn, $table, $column) {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column"
    );
    $stmt->execute([':table' => $table, ':column' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

// Base schema bootstrap for a completely empty database (no tables at
// all — e.g. migrate.php run against a bare MySQL instance outside of the
// docker-compose seed flow). Uses CREATE TABLE IF NOT EXISTS throughout,
// so on any database that already has these tables (which is the normal
// case — the app's tables are created either by url_list.sql on first
// boot, or by a previous version of this app) every statement here is a
// guaranteed no-op and touches nothing. This intentionally mirrors the
// *current* target schema (post all migrations below); the incremental
// migrations exist to bring older, already-populated tables up to date,
// not to describe history.
function ensureBaseSchema(PDO $conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `categories` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
          `sort_order` int(11) NOT NULL DEFAULT '0',
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS `submitted_urls` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
          `description` text COLLATE utf8mb4_unicode_ci,
          `category_id` int(11) DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `category_id` (`category_id`),
          CONSTRAINT `submitted_urls_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS `urls` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
          `category_id` int(11) DEFAULT NULL,
          `click_count` int(11) DEFAULT '0',
          `description` text COLLATE utf8mb4_unicode_ci,
          PRIMARY KEY (`id`),
          KEY `category_id` (`category_id`),
          CONSTRAINT `urls_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS `users` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
          `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
          `is_admin` tinyint(1) DEFAULT '0',
          `is_ne` tinyint(1) NOT NULL DEFAULT '0',
          `role` enum('read_only','approver','full_admin') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `username` (`username`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

// Ordered list of incremental migrations applied on top of the base
// schema. Each `up` callback re-checks the current schema itself before
// changing anything, so a migration is safe to invoke even if the
// `schema_migrations` bookkeeping below is missing or out of sync —
// the bookkeeping is purely to avoid redundant work, not a safety net.
//
// To add a new migration: append an entry with a new, never-reused
// version string. Never edit or remove an already-shipped entry.
$migrations = [
    [
        'version' => '2024_01_01_add_submitted_urls_description',
        'description' => "submitted_urls: add 'description' column",
        // Each `up` returns whether it actually changed anything, purely so
        // the runner can log accurately — a schema that already has the
        // column (e.g. a fresh install seeded straight from the latest
        // url_list.sql) is not itself a no-op run of the whole script, just
        // of this one step.
        'up' => function (PDO $conn) {
            if (columnExists($conn, 'submitted_urls', 'description')) {
                return false;
            }
            $conn->exec("ALTER TABLE `submitted_urls` ADD COLUMN `description` TEXT COLLATE utf8mb4_unicode_ci AFTER `url`");
            return true;
        },
    ],
    [
        'version' => '2026_08_25_add_categories_sort_order',
        'description' => "categories: add 'sort_order' column",
        'up' => function (PDO $conn) {
            if (columnExists($conn, 'categories', 'sort_order')) {
                return false;
            }
            $conn->exec("ALTER TABLE `categories` ADD COLUMN `sort_order` INT(11) NOT NULL DEFAULT '0' AFTER `name`");
            // Backfill: preserve existing display order (by id) as the
            // initial manual sort order, same as the documented manual
            // migration this replaces.
            $conn->exec("UPDATE `categories` SET `sort_order` = `id`");
            return true;
        },
    ],
];

try {
    ensureBaseSchema($conn);

    $conn->exec("
        CREATE TABLE IF NOT EXISTS `schema_migrations` (
          `version` varchar(191) NOT NULL,
          `applied_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`version`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $applied = $conn->query("SELECT `version` FROM `schema_migrations`")->fetchAll(PDO::FETCH_COLUMN);
    $applied = array_flip($applied);

    $ran = 0;
    foreach ($migrations as $migration) {
        if (isset($applied[$migration['version']])) {
            continue;
        }

        $changed = $migration['up']($conn);
        if ($changed) {
            echo "migrate.php: applied {$migration['version']} — {$migration['description']}\n";
            $ran++;
        } else {
            echo "migrate.php: {$migration['version']} already satisfied — recording as applied\n";
        }

        $stmt = $conn->prepare("INSERT INTO `schema_migrations` (`version`) VALUES (:version)");
        $stmt->execute([':version' => $migration['version']]);
    }

    echo $ran > 0
        ? "migrate.php: applied $ran migration(s). Database is up to date.\n"
        : "migrate.php: database is already up to date.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'migrate.php: migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
