#!/bin/sh
set -e

php /var/www/html/migrate.php

exec apache2-foreground
