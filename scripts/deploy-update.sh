#!/usr/bin/env bash
# ─── Ogami ERP — Production update / redeploy ─────────────────────────────────
# Pulls the latest code from GitHub, rebuilds the API images + SPA bundle, runs
# migrations, rebuilds runtime caches, and restarts services — idempotently and
# with a pre-migration DB backup so a bad deploy is recoverable.
#
# Designed to run ON the VPS:  cd /opt/ogami-erp && ./scripts/deploy-update.sh
#
# It encodes the gotchas learned during the first deploy:
#   • docker/nginx/prod.conf is rendered in-place with envsubst, so a raw
#     `git pull` would conflict — we reset it before pulling, re-render after.
#   • api / migrate / queue / reverb / scheduler are separate image consumers
#     that bake the source at build time. Code changes need a rebuild, not just
#     a restart.
#   • `config:cache` must run at RUNTIME with the live .env, never the build-time
#     placeholder env.
#   • `docker compose restart` does NOT re-read env_file; only up --force-recreate
#     re-injects environment changes.
#
# Flags:
#   --no-spa        Skip the SPA rebuild (backend-only change).
#   --no-build      Skip image rebuilds (config/env-only change).
#   --no-backup     Skip the pre-migration DB backup (NOT recommended).
#   --branch NAME   Deploy a specific branch/tag instead of main.
#   -h, --help      Show usage.
# ──────────────────────────────────────────────────────────────────────────────
set -euo pipefail

# ── Resolve paths ─────────────────────────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${REPO_DIR}"

COMPOSE_FILE="docker-compose.prod.yml"
COMPOSE="docker compose -f ${COMPOSE_FILE}"
NGINX_CONF="docker/nginx/prod.conf"
ENV_FILE=".env"
BACKUP_DIR="/var/backups/ogami"

# ── Defaults / flags ──────────────────────────────────────────────────────────
DO_SPA=1
DO_BUILD=1
DO_BACKUP=1
BRANCH="main"

usage() { sed -n '2,24p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0; }

while [ $# -gt 0 ]; do
    case "$1" in
        --no-spa)    DO_SPA=0 ;;
        --no-build)  DO_BUILD=0 ;;
        --no-backup) DO_BACKUP=0 ;;
        --branch)    BRANCH="${2:?--branch needs a value}"; shift ;;
        -h|--help)   usage ;;
        *) echo "Unknown option: $1 (try --help)" >&2; exit 2 ;;
    esac
    shift
done

# ── Pretty logging ────────────────────────────────────────────────────────────
log()  { printf '\033[1;36m▶ %s\033[0m\n' "$*"; }
ok()   { printf '\033[1;32m✓ %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m! %s\033[0m\n' "$*"; }
die()  { printf '\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

# Read one simple KEY=value entry without sourcing .env. Production secrets can
# contain shell metacharacters (the SMTP password currently contains literal
# '$' characters); sourcing the file would expand them or execute substitutions.
env_value() {
    local key="$1" value
    value="$(awk -v wanted="${key}" '
        $0 !~ /^[[:space:]]*#/ && index($0, "=") {
            key = $0
            sub(/=.*/, "", key)
            if (key == wanted) {
                sub(/^[^=]*=/, "", $0)
                sub(/[[:space:]]*\r$/, "", $0)
                print $0
                exit
            }
        }
    ' "${ENV_FILE}")"
    if [[ "${value}" == '"'*'"' ]]; then
        value="${value:1:${#value}-2}"
    elif [[ "${value}" == "'"*"'" ]]; then
        value="${value:1:${#value}-2}"
    fi
    printf '%s' "${value}"
}

START_TS=$(date +%s)
trap 'die "Update FAILED at line $LINENO. Services left as-is; investigate with: ${COMPOSE} ps && ${COMPOSE} logs --tail=50 api"' ERR

# ── Preflight ─────────────────────────────────────────────────────────────────
log "Preflight checks"
[ -f "${COMPOSE_FILE}" ] || die "Run from the repo root (no ${COMPOSE_FILE} here)."
[ -f "${ENV_FILE}" ]     || die "No ${ENV_FILE} — this host isn't configured yet. See docs/DEPLOY.md."
command -v docker >/dev/null || die "docker not installed."
docker compose version >/dev/null 2>&1 || die "docker compose plugin missing."

# Read only the values needed by this host-side preflight and backup. Do not
# source the live .env: Docker Compose reads it safely, while a shell would
# expand '$' in passwords and can execute command substitutions.
SERVER_NAME="$(env_value SERVER_NAME)"
[ -n "${SERVER_NAME}" ] || die "SERVER_NAME not set in ${ENV_FILE}."
ok "Target domain: ${SERVER_NAME}"

MAIL_MAILER="$(env_value MAIL_MAILER)"
MAIL_HOST="$(env_value MAIL_HOST)"
MAIL_PORT="$(env_value MAIL_PORT)"
MAIL_SCHEME="$(env_value MAIL_SCHEME)"
MAIL_USERNAME="$(env_value MAIL_USERNAME)"
MAIL_PASSWORD="$(env_value MAIL_PASSWORD)"
MAIL_FROM_ADDRESS="$(env_value MAIL_FROM_ADDRESS)"
DB_USERNAME="$(env_value DB_USERNAME)"
DB_DATABASE="$(env_value DB_DATABASE)"
DB_PASSWORD="$(env_value DB_PASSWORD)"

if [ "${MAIL_MAILER}" != "smtp" ]; then
    die "MAIL_MAILER must be smtp in production (currently ${MAIL_MAILER:-unset}); log/array mailers do not deliver messages."
fi
for mail_key in MAIL_HOST MAIL_PORT MAIL_SCHEME MAIL_USERNAME MAIL_PASSWORD MAIL_FROM_ADDRESS; do
    [ -n "${!mail_key:-}" ] || die "${mail_key} must be set for production SMTP delivery."
done
case "${MAIL_PORT}" in
    465|587|2525|2587) ;;
    *) die "MAIL_PORT=${MAIL_PORT} is not an approved submission port (use 465, 587, 2525, or 2587)." ;;
esac
ok "SMTP configured: ${MAIL_HOST}:${MAIL_PORT} from ${MAIL_FROM_ADDRESS}"

if command -v openssl >/dev/null 2>&1 && command -v timeout >/dev/null 2>&1; then
    log "Checking SMTP TLS reachability"
    # Mail Manager keeps the SMTP session open after the TLS handshake, so
    # openssl can time out even after a valid certificate and SMTP greeting
    # have been received. Validate the certificate line instead of requiring
    # the remote server to close the session.
    SMTP_TLS_OUTPUT="$(timeout 15 openssl s_client -starttls smtp \
        -connect "${MAIL_HOST}:${MAIL_PORT}" \
        -servername "${MAIL_HOST}" </dev/null 2>&1 || true)"
    if ! grep -Fq 'Verify return code: 0 (ok)' <<<"${SMTP_TLS_OUTPUT}"; then
        die "SMTP TLS connection failed for ${MAIL_HOST}:${MAIL_PORT}."
    fi
    ok "SMTP TLS endpoint reachable"
else
    warn "openssl/timeout unavailable; SMTP TLS reachability will be checked after the containers start."
fi

# ── 1. Sync code ──────────────────────────────────────────────────────────────
# The nginx conf is rendered in place (SERVER_NAME substituted), so it shows as
# a local modification. Reset just that file so --ff-only can fast-forward.
log "Fetching latest code (branch: ${BRANCH})"
if ! git diff --quiet -- "${NGINX_CONF}" 2>/dev/null; then
    warn "Resetting in-place-rendered ${NGINX_CONF} before pull"
    git checkout -- "${NGINX_CONF}"
fi
PREV_SHA="$(git rev-parse --short HEAD)"
git fetch origin --quiet
git checkout "${BRANCH}" --quiet
git pull --ff-only --quiet
NEW_SHA="$(git rev-parse --short HEAD)"
if [ "${PREV_SHA}" = "${NEW_SHA}" ]; then
    ok "Already at latest (${NEW_SHA}) — proceeding anyway (rebuild/cache refresh)."
else
    ok "Updated ${PREV_SHA} → ${NEW_SHA}"
    log "Changes:"; git --no-pager log --oneline "${PREV_SHA}..${NEW_SHA}" | sed 's/^/    /'
fi

# ── 2. Pre-migration DB backup ────────────────────────────────────────────────
if [ "${DO_BACKUP}" -eq 1 ]; then
    log "Pre-migration database backup"
    DB_CID="$(${COMPOSE} ps -q db 2>/dev/null)"
    if [ -n "${DB_CID}" ] && [ "$(docker inspect -f '{{.State.Running}}' "${DB_CID}" 2>/dev/null)" = "true" ]; then
        mkdir -p "${BACKUP_DIR}" 2>/dev/null || sudo mkdir -p "${BACKUP_DIR}"
        TS="$(date +%Y%m%d-%H%M%S)"
        OUT="${BACKUP_DIR}/predeploy-${TS}-${PREV_SHA}.sql.gz"
        TMP="$(mktemp "${BACKUP_DIR}/.predeploy-${TS}.XXXXXX")"
        if ! ${COMPOSE} exec -T -e PGPASSWORD="${DB_PASSWORD}" db \
            pg_dump --username="${DB_USERNAME}" --dbname="${DB_DATABASE}" \
            --format=plain --no-owner --no-privileges | gzip > "${TMP}"; then
            rm -f "${TMP}"
            die "Pre-migration database backup failed; deployment stopped before consumers were changed."
        fi
        if [ ! -s "${TMP}" ] || ! gzip -t "${TMP}"; then
            rm -f "${TMP}"
            die "Pre-migration database backup is empty or corrupt; deployment stopped."
        fi
        mv -f "${TMP}" "${OUT}"
        ok "Backup: ${OUT} ($(du -h "${OUT}" | cut -f1))"
    else
        die "db container is not running; refusing to deploy without a pre-migration backup. Use --no-backup only for an explicitly approved first bootstrap."
    fi
fi

# ── 3. Rebuild SPA bundle ─────────────────────────────────────────────────────
# Built into spa/dist, which nginx mounts read-only. No Vite server in prod.
if [ "${DO_SPA}" -eq 1 ]; then
    log "Building SPA bundle (npm ci + vite build)"
    ( cd spa && docker run --rm -v "$PWD:/app" -w /app node:20-alpine \
        sh -c "npm ci --no-audit --no-fund && npm run build" )
    ok "SPA built → spa/dist"
else
    warn "Skipping SPA build (--no-spa)"
fi

# ── 4. Rebuild API images ─────────────────────────────────────────────────────
# api / queue / reverb / scheduler each have their OWN build block and bake the
# source at build time. All four must be rebuilt for code/config changes to land.
if [ "${DO_BUILD}" -eq 1 ]; then
    log "Rebuilding API images (api queue reverb scheduler)"
    ${COMPOSE} build api queue reverb scheduler
    ok "Images rebuilt"
else
    warn "Skipping image rebuild (--no-build)"
fi

# ── 5. Render nginx config with the live domain ───────────────────────────────
log "Rendering ${NGINX_CONF} for ${SERVER_NAME}"
SERVER_NAME="${SERVER_NAME}" envsubst '${SERVER_NAME}' < "${NGINX_CONF}" > "${NGINX_CONF}.rendered"
mv "${NGINX_CONF}.rendered" "${NGINX_CONF}"
ok "nginx config rendered"

# ── 6. Replace the API without starting consumers before migration ───────────
# Queue, scheduler, Reverb, and Nginx must not run the new image (or see a
# partially migrated schema) while migrations are pending. They are started
# only after the API has migrated and rebuilt its runtime caches below.
log "Stopping consumers before migration"
${COMPOSE} stop nginx reverb queue scheduler
log "Starting infrastructure and API only"
${COMPOSE} up -d --force-recreate db redis api
log "Waiting for database to report healthy"
for i in $(seq 1 30); do
    st="$(docker inspect --format '{{.State.Health.Status}}' ogami-db 2>/dev/null || echo none)"
    [ "${st}" = "healthy" ] && break
    sleep 2
done
[ "${st:-none}" = "healthy" ] || die "db health is ${st:-unknown}; refusing to migrate or start consumers."
ok "Services up"

# ── 7. Migrate (backwards-compatible only; NEVER migrate:fresh in prod) ───────
log "Running migrations"
${COMPOSE} exec -T api php artisan migrate --force
ok "Migrations applied"

# ── 8. Rebuild runtime caches with the LIVE env ───────────────────────────────
log "Rebuilding framework caches (config/route/view) + storage link"
${COMPOSE} exec -T api php artisan config:cache
${COMPOSE} exec -T api php artisan route:cache
${COMPOSE} exec -T api php artisan view:cache
${COMPOSE} exec -T api php artisan storage:link
ok "Caches rebuilt"

# ── 9. Start consumers only after migration and cache rebuild ────────────────
log "Starting consumers (nginx reverb queue scheduler)"
${COMPOSE} up -d --force-recreate nginx reverb queue scheduler
${COMPOSE} exec -T api php artisan queue:restart >/dev/null
ok "Consumers started"

# ── 10. Reload nginx ──────────────────────────────────────────────────────────
log "Validating + reloading nginx"
${COMPOSE} exec -T nginx nginx -t
${COMPOSE} exec -T nginx nginx -s reload
ok "nginx reloaded"

# ── 11. Smoke test ────────────────────────────────────────────────────────────
log "Smoke test against https://${SERVER_NAME}"
HEALTH=""
for attempt in $(seq 1 10); do
    if HEALTH="$(curl -fsS --max-time 15 "https://${SERVER_NAME}/api/v1/health" 2>/dev/null)"; then
        case "${HEALTH}" in
            *'"status":"ok"'*) break ;;
        esac
    fi
    HEALTH=""
    sleep 2
done
case "${HEALTH}" in
    *'"status":"ok"'*) ok "Health: ${HEALTH}" ;;
    *) die "Health probe did not return ok: ${HEALTH:-unreachable}" ;;
esac

SPA_CODE=""
for attempt in $(seq 1 10); do
    if SPA_CODE="$(curl -fsS -o /dev/null -w '%{http_code}' --max-time 15 "https://${SERVER_NAME}/" 2>/dev/null)" && [ "${SPA_CODE}" = "200" ]; then
        break
    fi
    SPA_CODE=""
    sleep 2
done
[ "${SPA_CODE}" = "200" ] || die "SPA index returned HTTP ${SPA_CODE:-unreachable}; deployment is not complete."
ok "SPA index: HTTP ${SPA_CODE}"

for service in api nginx db redis reverb queue scheduler; do
    cid="$(${COMPOSE} ps -q "${service}")"
    [ -n "${cid}" ] || die "Expected service ${service} has no container."
    state="$(docker inspect -f '{{.State.Status}}' "${cid}")"
    [ "${state}" = "running" ] || die "Expected service ${service} is ${state}, not running."
done
ok "All production services are running"

# ── Done ──────────────────────────────────────────────────────────────────────
ELAPSED=$(( $(date +%s) - START_TS ))
printf '\n'
ok "Deploy complete in ${ELAPSED}s — now at ${NEW_SHA} on ${SERVER_NAME}"
${COMPOSE} ps --format '    {{.Name}}: {{.Status}}'
