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
#   • api / queue / reverb / scheduler are FOUR separate images that each bake
#     the source at build time. Code changes need a rebuild, not just a restart.
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

START_TS=$(date +%s)
trap 'die "Update FAILED at line $LINENO. Services left as-is; investigate with: ${COMPOSE} ps && ${COMPOSE} logs --tail=50 api"' ERR

# ── Preflight ─────────────────────────────────────────────────────────────────
log "Preflight checks"
[ -f "${COMPOSE_FILE}" ] || die "Run from the repo root (no ${COMPOSE_FILE} here)."
[ -f "${ENV_FILE}" ]     || die "No ${ENV_FILE} — this host isn't configured yet. See docs/DEPLOY.md."
command -v docker >/dev/null || die "docker not installed."
docker compose version >/dev/null 2>&1 || die "docker compose plugin missing."

# SERVER_NAME drives the nginx render + smoke test. Pull it from .env.
SERVER_NAME="$(grep -E '^SERVER_NAME=' "${ENV_FILE}" | head -1 | cut -d= -f2- | tr -d '"'"'"' ')"
[ -n "${SERVER_NAME}" ] || die "SERVER_NAME not set in ${ENV_FILE}."
ok "Target domain: ${SERVER_NAME}"

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
    DB_CID="$(${COMPOSE} ps -q db 2>/dev/null || true)"
    if [ -n "${DB_CID}" ] && [ "$(docker inspect -f '{{.State.Running}}' "${DB_CID}" 2>/dev/null)" = "true" ]; then
        mkdir -p "${BACKUP_DIR}" 2>/dev/null || sudo mkdir -p "${BACKUP_DIR}"
        set -a; . "./${ENV_FILE}"; set +a
        TS="$(date +%Y%m%d-%H%M%S)"
        OUT="${BACKUP_DIR}/predeploy-${TS}-${PREV_SHA}.sql.gz"
        ${COMPOSE} exec -T -e PGPASSWORD="${DB_PASSWORD}" db \
            pg_dump --username="${DB_USERNAME}" --dbname="${DB_DATABASE}" \
            --format=plain --no-owner --no-privileges | gzip > "${OUT}"
        ok "Backup: ${OUT} ($(du -h "${OUT}" | cut -f1))"
    else
        warn "db container not running yet — skipping pre-migration backup (first deploy?)."
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

# ── 6. Recreate services ──────────────────────────────────────────────────────
# --force-recreate so any env_file change is re-injected (restart won't do it).
log "Recreating services"
${COMPOSE} up -d --force-recreate
log "Waiting for database to report healthy"
for i in $(seq 1 30); do
    st="$(docker inspect --format '{{.State.Health.Status}}' ogami-db 2>/dev/null || echo none)"
    [ "${st}" = "healthy" ] && break
    sleep 2
done
[ "${st:-none}" = "healthy" ] || warn "db health = ${st:-unknown} (continuing; migrate will surface real errors)."
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
${COMPOSE} exec -T api php artisan storage:link 2>/dev/null || true
ok "Caches rebuilt"

# ── 9. Restart workers so they pick up the fresh config cache ─────────────────
log "Restarting workers (queue reverb scheduler) + flushing queue restart signal"
${COMPOSE} exec -T api php artisan queue:restart >/dev/null 2>&1 || true
${COMPOSE} restart queue reverb scheduler >/dev/null
ok "Workers restarted"

# ── 10. Reload nginx ──────────────────────────────────────────────────────────
log "Validating + reloading nginx"
${COMPOSE} exec -T nginx nginx -t
${COMPOSE} exec -T nginx nginx -s reload
ok "nginx reloaded"

# ── 11. Smoke test ────────────────────────────────────────────────────────────
log "Smoke test against https://${SERVER_NAME}"
HEALTH="$(curl -fsS --max-time 15 "https://${SERVER_NAME}/api/v1/health" || echo 'FAILED')"
case "${HEALTH}" in
    *'"status":"ok"'*) ok "Health: ${HEALTH}" ;;
    *) warn "Health probe did not return ok: ${HEALTH}" ;;
esac
SPA_CODE="$(curl -fsS -o /dev/null -w '%{http_code}' --max-time 15 "https://${SERVER_NAME}/" || echo 000)"
[ "${SPA_CODE}" = "200" ] && ok "SPA index: HTTP ${SPA_CODE}" || warn "SPA index returned HTTP ${SPA_CODE}"

# ── Done ──────────────────────────────────────────────────────────────────────
ELAPSED=$(( $(date +%s) - START_TS ))
printf '\n'
ok "Deploy complete in ${ELAPSED}s — now at ${NEW_SHA} on ${SERVER_NAME}"
${COMPOSE} ps --format '    {{.Name}}: {{.Status}}'
