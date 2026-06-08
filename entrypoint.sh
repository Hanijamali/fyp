#!/bin/bash
set -e

PORT="${PORT:-8080}"
sed -i "s/Listen 8080/Listen ${PORT}/" /etc/apache2/sites-available/000-default.conf
sed -i "s/<VirtualHost \*:8080>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

php /var/www/html/docker/init-db.php

exec "$@"
