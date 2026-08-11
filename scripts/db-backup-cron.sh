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
#   CONTAINER_BACKUP_DIR persistent dump path in the db container
#                       (default: /var/backups/ogami)
#   HOST_BACKUP_DIR  where to copy dumps on the host  (default: ./backups)
#   DB_USERNAME / DB_PASSWORD / DB_DATABASE           (required for the dump)
#   BACKUP_KEEP    retention count                    (default: 14)
#   BACKUP_S3_BUCKET optional off-site target         (uploaded by host aws CLI)

set -euo pipefail

: "${DB_CONTAINER:=ogami-db}"
: "${CONTAINER_BACKUP_DIR:=/var/backups/ogami}"
: "${HOST_BACKUP_DIR:=./backups}"
: "${DB_USERNAME:?DB_USERNAME required}"
: "${DB_PASSWORD:?DB_PASSWORD required}"
: "${DB_DATABASE:?DB_DATABASE required}"
: "${BACKUP_KEEP:=14}"

case "${BACKUP_KEEP}" in
    ''|*[!0-9]*)
        echo "ERROR: BACKUP_KEEP must be a non-negative integer" >&2
        exit 2
        ;;
esac

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

mkdir -p "${HOST_BACKUP_DIR}"

echo "==> [$(date -Is)] copying db-backup.sh into ${DB_CONTAINER}"
docker cp "${SCRIPT_DIR}/db-backup.sh" "${DB_CONTAINER}:/tmp/db-backup.sh"

echo "==> running pg_dump inside ${DB_CONTAINER}"
docker exec \
    -e BACKUP_DIR="${CONTAINER_BACKUP_DIR}" \
    -e DB_HOST=localhost \
    -e DB_PORT=5432 \
    -e DB_USERNAME="${DB_USERNAME}" \
    -e DB_PASSWORD="${DB_PASSWORD}" \
    -e DB_DATABASE="${DB_DATABASE}" \
    -e BACKUP_KEEP="${BACKUP_KEEP}" \
    "${DB_CONTAINER}" sh -c 'mkdir -p "$BACKUP_DIR" && bash /tmp/db-backup.sh'

echo "==> locating the newly published dump"
REMOTE_FILE="$(docker exec -e BACKUP_DIR="${CONTAINER_BACKUP_DIR}" "${DB_CONTAINER}" sh -c '
    set -eu
    latest="$(ls -1t "$BACKUP_DIR"/ogami-*.sql.gz 2>/dev/null | head -n 1)"
    test -n "${latest}"
    gzip -t "${latest}"
    printf "%s" "${latest}"
')"

LOCAL_FILE="${HOST_BACKUP_DIR}/$(basename "${REMOTE_FILE}")"
if [ "${CONTAINER_BACKUP_DIR}" != "${HOST_BACKUP_DIR}" ]; then
    echo "==> copying ${REMOTE_FILE} to ${HOST_BACKUP_DIR}"
    docker cp "${DB_CONTAINER}:${REMOTE_FILE}" "${HOST_BACKUP_DIR}/"
else
    echo "==> validating persistent host archive ${LOCAL_FILE}"
fi
test -s "${LOCAL_FILE}"
gzip -t "${LOCAL_FILE}"

if [ -n "${BACKUP_S3_BUCKET:-}" ]; then
    command -v aws >/dev/null 2>&1 || {
        echo "ERROR: BACKUP_S3_BUCKET is set but 'aws' CLI is not installed on the host" >&2
        exit 2
    }
    PREFIX="${BACKUP_S3_PREFIX:-}"
    if [ -n "${PREFIX}" ] && [ "${PREFIX%/}" = "${PREFIX}" ]; then
        PREFIX="${PREFIX}/"
    fi
    REMOTE="${BACKUP_S3_BUCKET%/}/${PREFIX}$(basename "${LOCAL_FILE}")"
    echo "==> uploading ${LOCAL_FILE} to ${REMOTE}"
    aws s3 cp "${LOCAL_FILE}" "${REMOTE}" --only-show-errors
    echo "==> off-site copy ok"
fi

# Keep the host copy bounded as well. The container's retention cannot clean
# the host directory when the two paths differ.
if [ "${BACKUP_KEEP}" -gt 0 ]; then
    ls -1t "${HOST_BACKUP_DIR}"/ogami-*.sql.gz 2>/dev/null \
        | tail -n "+$((BACKUP_KEEP + 1))" \
        | xargs -r rm -f --
fi

echo "==> [$(date -Is)] backup cron complete"
