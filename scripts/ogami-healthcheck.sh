#!/usr/bin/env bash
# ogami-healthcheck.sh — independent production smoke check.
#
# This is intentionally host-level rather than an in-app route. It detects a
# dead API/container, a broken public Cloudflare path, a stale scheduler, a
# missing/corrupt database backup, or a nearly full root filesystem.

set -euo pipefail

APP_ROOT="${OGAMI_APP_ROOT:-/opt/ogami-erp}"
COMPOSE_FILE="${OGAMI_COMPOSE_FILE:-docker-compose.prod.yml}"
HEALTH_URL="${OGAMI_HEALTH_URL:-https://ogamiph.dev/api/v1/health}"
BACKUP_DIR="${OGAMI_BACKUP_DIR:-/var/backups/ogami}"
BACKUP_MAX_AGE_MINUTES="${OGAMI_BACKUP_MAX_AGE_MINUTES:-1560}"
DISK_LIMIT_PERCENT="${OGAMI_DISK_LIMIT_PERCENT:-85}"

fail() {
    echo "ogami-healthcheck: $*" >&2
    exit 1
}

cd "$APP_ROOT"

command -v docker >/dev/null 2>&1 || fail "docker is unavailable"
command -v curl >/dev/null 2>&1 || fail "curl is unavailable"

expected_services=(ogami-api ogami-db ogami-nginx ogami-queue ogami-redis ogami-reverb ogami-scheduler)
running_services="$(docker compose -f "$COMPOSE_FILE" ps --format '{{.Name}} {{.State}}')"
for service in "${expected_services[@]}"; do
    grep -Eq "^${service}[[:space:]]+running([[:space:]]|$)" <<<"$running_services" \
        || fail "container is not running: ${service}"
done

health_body="$(curl --fail --silent --show-error --max-time 15 "$HEALTH_URL")" \
    || fail "public health endpoint failed: ${HEALTH_URL}"
grep -q '"status":"ok"' <<<"$health_body" \
    || fail "public health endpoint did not report status=ok"

docker compose -f "$COMPOSE_FILE" exec -T api \
    php artisan scheduler:health --stale-minutes=15 --no-interaction >/dev/null \
    || fail "scheduler health probe failed"

latest_backup="$(find "$BACKUP_DIR" -maxdepth 1 -type f -name 'ogami-*.sql.gz' -mmin "-${BACKUP_MAX_AGE_MINUTES}" -printf '%T@ %p\n' 2>/dev/null \
    | sort -nr | head -n 1 | cut -d' ' -f2-)"
[ -n "$latest_backup" ] || fail "no fresh database backup in ${BACKUP_DIR}"
gzip -t "$latest_backup" || fail "latest database backup is corrupt: ${latest_backup}"

disk_used="$(df -P "$APP_ROOT" | awk 'NR == 2 {gsub(/%/, "", $5); print $5}')"
[[ "$disk_used" =~ ^[0-9]+$ ]] || fail "could not determine disk usage"
[ "$disk_used" -lt "$DISK_LIMIT_PERCENT" ] || fail "disk usage is ${disk_used}% (limit ${DISK_LIMIT_PERCENT}%)"

echo "ogami-healthcheck: OK site=ok scheduler=ok backup=$(basename "$latest_backup") disk=${disk_used}%"
