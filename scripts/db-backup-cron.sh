#!/usr/bin/env bash
# db-backup-cron.sh — host-level cron wrapper around db-backup.sh.
#
# The Laravel scheduler already runs `php artisan db:backup` daily at 03:17
# inside the api container (see api/routes/console.php). THIS script is the
# belt-and-suspenders host-cron path for operators who prefer a system crontab
# entry that does not depend on the app container being healthy.
#
# It execs into the running Postgres container (default name: ogami-db) and
# runs db-backup.sh there, then copies the result back to a host directory.
#
# Install (example — daily 03:17, log to /var/log):
#   17 3 * * *  /opt/ogami-erp/scripts/db-backup-cron.sh >> /var/log/ogami-backup.log 2>&1
#
# Env (override as needed):
#   DB_CONTAINER   Postgres container name           (default: ogami-db)
#   HOST_BACKUP_DIR  where to copy dumps on the host  (default: ./backups)
#   DB_USERNAME / DB_PASSWORD / DB_DATABASE           (required for the dump)
#   BACKUP_KEEP    retention count                    (default: 14)
#   BACKUP_S3_BUCKET optional off-site target         (passed through)

set -euo pipefail

: "${DB_CONTAINER:=ogami-db}"
: "${HOST_BACKUP_DIR:=./backups}"
: "${DB_USERNAME:?DB_USERNAME required}"
: "${DB_PASSWORD:?DB_PASSWORD required}"
: "${DB_DATABASE:?DB_DATABASE required}"
: "${BACKUP_KEEP:=14}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

mkdir -p "${HOST_BACKUP_DIR}"

echo "==> [$(date -Is)] copying db-backup.sh into ${DB_CONTAINER}"
docker cp "${SCRIPT_DIR}/db-backup.sh" "${DB_CONTAINER}:/tmp/db-backup.sh"

echo "==> running pg_dump inside ${DB_CONTAINER}"
docker exec \
    -e BACKUP_DIR=/backups \
    -e DB_HOST=localhost \
    -e DB_PORT=5432 \
    -e DB_USERNAME="${DB_USERNAME}" \
    -e DB_PASSWORD="${DB_PASSWORD}" \
    -e DB_DATABASE="${DB_DATABASE}" \
    -e BACKUP_KEEP="${BACKUP_KEEP}" \
    -e BACKUP_S3_BUCKET="${BACKUP_S3_BUCKET:-}" \
    "${DB_CONTAINER}" sh -c 'mkdir -p /backups && bash /tmp/db-backup.sh'

echo "==> copying dumps back to ${HOST_BACKUP_DIR}"
docker cp "${DB_CONTAINER}:/backups/." "${HOST_BACKUP_DIR}/" 2>/dev/null || true

echo "==> [$(date -Is)] backup cron complete"
