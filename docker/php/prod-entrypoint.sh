#!/bin/sh
set -eu

# Local development and PHPUnit need runtime environment overrides (notably
# DB_DATABASE=ogami_test). A cached config would ignore those overrides and
# let an isolated test migrate:fresh against the development database.
if [ "${APP_ENV:-production}" = "local" ]; then
    php artisan config:clear
    php artisan route:clear
    php artisan view:clear
else
    # Cache only after the deployment environment has supplied APP_KEY,
    # HASHIDS_SALT, database settings, and other production configuration.
    # ProductionAssertions deliberately aborts startup for unsafe defaults.
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

exec "$@"
