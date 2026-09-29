#!/bin/sh
# Everything here exists because of one requirement: `docker compose up -d` on a
# clean machine has to deliver a running Laravel, with no manual steps.
set -e

cd /var/www/html

# Compose's bind mount covers the image's /var/www/html, so the vendor/ installed
# at build time is invisible. And on a clean machine the host has no vendor/
# either, because it is gitignored. Installing here is what removes the need for
# composer on the host.
if [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] vendor/ missing — installing dependencies"
    composer install --no-interaction --no-progress --prefer-dist
    chown -R www-data:www-data vendor
fi

# .env is gitignored for the same reason. Without it Laravel does not start.
if [ ! -f .env ]; then
    echo "[entrypoint] .env missing — copying from .env.example"
    cp .env.example .env
    chown www-data:www-data .env
fi

if ! grep -qE '^APP_KEY=.+' .env; then
    echo "[entrypoint] generating APP_KEY"
    php artisan key:generate --force
fi

mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# MySQL's healthcheck can pass during the init phase, before the application's
# user exists — hence the retry rather than trusting depends_on alone. The session
# and cache tables come from migrations, and without them any web route answers
# 500.
attempt=1
until php artisan migrate --force; do
    if [ "$attempt" -ge 10 ]; then
        echo "[entrypoint] database did not answer after $attempt attempts"
        exit 1
    fi
    echo "[entrypoint] database unavailable — retrying in 3s ($attempt/10)"
    attempt=$((attempt + 1))
    sleep 3
done

exec "$@"
