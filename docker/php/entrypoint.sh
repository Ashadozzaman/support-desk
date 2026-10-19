#!/bin/sh
set -e

if [ -z "$APP_KEY" ]; then
    echo "APP_KEY is empty. Set it in .env.docker (see README \"Run with Docker\")." >&2
    exit 1
fi

# Only the app container migrates (RUN_MIGRATIONS=true); queue/scheduler/reverb wait for it to be healthy.
if [ "$RUN_MIGRATIONS" = "true" ]; then
    php artisan migrate --force
fi

# Each container caches config/routes/views/events from its own environment.
php artisan optimize

exec "$@"
