#!/bin/sh
# Entrypoint for the api, horizon and reverb containers.
# Only the api service sets CAMPUS_TAB_BOOTSTRAP=1: it installs Composer
# packages into the backend_vendor volume (never onto the Windows drive) and
# creates backend/.env on first run. horizon and reverb wait for the api to
# be healthy, so they always start with vendor/ ready.
set -eu

cd /app

if [ "${CAMPUS_TAB_BOOTSTRAP:-0}" = "1" ]; then
    if [ ! -f vendor/autoload.php ] || [ composer.lock -nt vendor/autoload.php ]; then
        echo "[campus-tab] installing Composer packages into the vendor volume..."
        composer install --no-interaction --prefer-dist --no-progress
        touch vendor/autoload.php
    fi

    if [ ! -f .env ]; then
        echo "[campus-tab] creating backend/.env from .env.example"
        cp .env.example .env
    fi

    if ! grep -q '^APP_KEY=base64:' .env; then
        php artisan key:generate --force --no-interaction
    fi

    php artisan config:clear >/dev/null 2>&1 || true
fi

exec "$@"
