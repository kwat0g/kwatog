#!/bin/sh
set -eu

# Cache only after the deployment environment has supplied APP_KEY,
# HASHIDS_SALT, database settings, and other production configuration.
# ProductionAssertions deliberately aborts startup for unsafe defaults.
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
