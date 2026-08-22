# Ogami ERP — Production Deployment Runbook

> Sprint 4 / Task 38 — VPS deployment for Semester 1 defense.
>
> Stack: DigitalOcean droplet (Ubuntu 22.04 LTS, 4 GB RAM, 2 vCPU, 80 GB SSD)
> running [`docker-compose.prod.yml`](../docker-compose.prod.yml) with Let's
> Encrypt SSL terminated at Nginx, daily `pg_dump` backups via cron, and a
> non-interactive `make deploy` flow.

---

## 1. Provision the droplet

1. Create a DigitalOcean droplet:
   - Image: **Ubuntu 22.04 LTS x64**
   - Plan: 4 GB / 2 vCPU / 80 GB SSD (`s-2vcpu-4gb`)
   - Datacenter: **SGP1** (Singapore — lowest latency to PH)
   - SSH key: add yours; **disable password auth**.
2. Point an `A` record from your domain (e.g. `erp.ogami.example`) at the droplet's public IPv4.
3. SSH in as `root` and create a non-root admin:
   ```bash
   adduser ogami
   usermod -aG sudo ogami
   rsync --archive --chown=ogami:ogami ~/.ssh /home/ogami
   ```
4. Configure UFW:
   ```bash
   ufw default deny incoming
   ufw default allow outgoing
   ufw allow OpenSSH
   ufw allow 80
   ufw allow 443
   ufw enable
   ```
5. Disable root SSH login and password auth:
   ```bash
   sed -i 's/^PermitRootLogin .*/PermitRootLogin no/' /etc/ssh/sshd_config
   sed -i 's/^#?PasswordAuthentication .*/PasswordAuthentication no/' /etc/ssh/sshd_config
   systemctl restart sshd
   ```

## 2. Install Docker and Docker Compose plugin

```bash
sudo apt update && sudo apt install -y ca-certificates curl gnupg
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | \
  sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg

echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
  https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" | \
  sudo tee /etc/apt/sources.list.d/docker.list >/dev/null

sudo apt update && sudo apt install -y docker-ce docker-ce-cli containerd.io \
  docker-buildx-plugin docker-compose-plugin certbot

sudo usermod -aG docker ogami
# log out and back in for group change to take effect
```

## 3. Clone the repository

```bash
sudo mkdir -p /opt/ogami-erp && sudo chown ogami:ogami /opt/ogami-erp
cd /opt/ogami-erp
git clone https://github.com/kwat0g/kwatog.git .
git checkout main
```

## 4. Provision SSL (Let's Encrypt)

The droplet must already have port 80 reachable from the internet.

```bash
# Stop anything bound to port 80 first.
sudo certbot certonly --standalone \
    -d erp.ogami.example \
    -m admin@ogami.example \
    --agree-tos --no-eff-email
```

Certs land at `/etc/letsencrypt/live/erp.ogami.example/`. The compose file
mounts the parent directory **read-only** into the nginx container.

### Auto-renew

Certbot installs a systemd timer (`certbot.timer`) that runs twice a day. Add
a post-renew hook to reload nginx:

```bash
sudo tee /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh <<'EOF'
#!/bin/sh
docker exec ogami-nginx nginx -s reload
EOF
sudo chmod +x /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh

# Test
sudo certbot renew --dry-run
```

## 5. Configure environment

```bash
cd /opt/ogami-erp
cp .env.production.example .env
nano .env   # fill in DB_PASSWORD, APP_KEY, HASHIDS_SALT, and Mail Manager MAIL_*, etc.
```

For Amazon SES Mail Manager, use the authenticated ingress endpoint hostname
ending in `.mail-manager-smtp.amazonaws.com`, together with that endpoint's
username and SMTP password. Use an approved sender for `MAIL_FROM_ADDRESS`.
The production settings are `MAIL_PORT=587` and `MAIL_SCHEME=smtp`.
The production Compose file uses `env_file` with `format: raw`, so literal
`$` characters in SMTP credentials are passed unchanged to Laravel. Keep the
password in `.env` as the provider supplied it; do not shell-source `.env`.

If the organization uses another SMTP relay, use that provider's SMTP login,
SMTP key/password, hostname, and verified sender instead. Do not use an API key,
the provider's account password, or `MAIL_MAILER=log`; the latter only writes a
local log entry and makes queued jobs appear successful without delivering
anything.

Before opening traffic, verify all of the following:

```bash
grep -E '^(MAIL_MAILER|MAIL_HOST|MAIL_PORT|MAIL_SCHEME|MAIL_FROM_ADDRESS)=' .env
test "$(grep '^MAIL_MAILER=' .env | cut -d= -f2-)" = smtp
```

The deployment script checks the required SMTP values and performs a TLS
reachability check. SMTP reachability is not the same as deliverability: the
Mail Manager traffic policy and rule set must permit the authenticated sender
and route the message to the internet. Confirm the message in the Mail
Manager archive and the recipient inbox when testing Gmail/Yahoo delivery.

Generate values:

```bash
# APP_KEY (run inside a temporary container so PHP isn't required on the host)
docker run --rm -v "$PWD:/app" -w /app/api composer:2 php artisan key:generate --show

# DB_PASSWORD, HASHIDS_SALT, REVERB_APP_KEY, REVERB_APP_SECRET
openssl rand -base64 32   # run multiple times
```

**Critical:** `SERVER_NAME` in `.env` must be the real production DNS name.
The production Compose file mounts [`docker/nginx/prod.conf`](../docker/nginx/prod.conf)
as an official Nginx template; the Nginx entrypoint expands `SERVER_NAME` on
container start. The API and queue processes enforce the same requirement, so a
missing or localhost value fails closed.

## 6. First-time deploy

The repo's [`Makefile`](../Makefile) gains a production-flavoured target. Add it
on the host (or use the inline command):

```bash
# Build images, migrate before any application or queue process starts, then
# bring up the consumers.
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml build --no-cache
docker compose -f docker-compose.prod.yml up -d db redis
# Wait with a bounded loop; a failed healthcheck must stop the release.
status=missing
for i in $(seq 1 60); do
    status="$(docker inspect -f '{{.State.Health.Status}}' ogami-db 2>/dev/null || echo missing)"
    [ "$status" = healthy ] && break
    sleep 3
done
[ "$status" = healthy ] || { echo "ERROR: production database did not become healthy" >&2; exit 1; }
# The one-shot service must complete before any application consumer starts.
docker compose -f docker-compose.prod.yml up migrate
# Start the API, realtime server, queue worker, scheduler, and Nginx only after
# the schema is ready.
docker compose -f docker-compose.prod.yml up -d api nginx reverb queue scheduler
# Seed once on a new installation, after migration and before opening traffic.
docker compose -f docker-compose.prod.yml exec api php artisan db:seed --force
docker compose -f docker-compose.prod.yml exec api php artisan storage:link

# Queue one real integration message to the configured administrator. This
# exercises the same SMTP + Redis queue path used by application mail. Inspect
# the queue logs and the recipient inbox; a queued/DONE job alone is not proof
# of delivery.
docker compose -f docker-compose.prod.yml exec -T api php artisan tinker \
    --execute='Mail::to(env("ADMIN_EMAIL"))->queue(new \App\Common\Mail\EmailIntegrationTestMail);'
docker compose -f docker-compose.prod.yml exec -T api php artisan queue:restart
docker compose -f docker-compose.prod.yml logs --since=2m queue

# Confirm the administrator exists. AdminUserSeeder SKIPS silently when any of
# ADMIN_NAME / ADMIN_EMAIL / ADMIN_PASSWORD is blank, and db:seed still exits 0,
# so a zero-user install looks like a clean one. Check rather than assume:
docker compose -f docker-compose.prod.yml exec -T api \
    php artisan tinker --execute='echo DB::table("users")->count(), PHP_EOL;'
# Expect 1. If it prints 0, set the three keys in .env, then recreate the api
# container so Compose re-injects env_file (`restart` does NOT re-read it) and
# run the one seeder on its own:
#   docker compose -f docker-compose.prod.yml up -d --force-recreate api
#   docker compose -f docker-compose.prod.yml exec -T api \
#       php artisan db:seed --class=AdminUserSeeder --force
```

> **DO NOT** run `migrate:fresh` in production. Ever. Laravel only re-runs
> migrations it hasn't seen, so subsequent deploys are safe with `migrate --force`.

### Release evidence before opening traffic

Run the disposable staging gate from a checkout whose Docker services point at
the staging database/Redis. It creates a constrained `ogami_chain_smoke_*`
database, applies the complete migration chain, runs a real Redis worker
against a narrow listener replay, checks lineage/outcome/failed-jobs, and
removes its temporary state automatically:

```bash
make chain-smoke
```

This gate must pass before a new worker or migration tranche is considered
ready. It does not write to the application database.

For a disposable worker interruption/recovery check, run this from the same
checkout. It uses a unique Redis namespace, kills a real worker during a
test-only probe, waits for `retry_after`, and verifies the second attempt
completes before cleaning its keys:

```bash
make worker-recovery-smoke
```

## 7. Build & deploy the SPA

The SPA is built into static files and served by nginx; there is no Vite dev
server in production.

```bash
cd /opt/ogami-erp/spa
docker run --rm -v "$PWD:/app" -w /app node:20-alpine sh -c \
    "npm ci --no-audit --no-fund && npm run build"
# `dist/` is mounted into nginx via the compose volume; no further action needed.
```

Reload nginx so it picks up the new index.html:

```bash
docker compose -f /opt/ogami-erp/docker-compose.prod.yml exec nginx nginx -s reload
```

## 8. Smoke test

**Send `Origin` and `Referer` on every call.** Sanctum's
`EnsureFrontendRequestsAreStateful` decides whether to apply the `web`
middleware group — and therefore whether a session exists — by matching the
request's `Origin`/`Referer` host against `SANCTUM_STATEFUL_DOMAINS`. A bare
`curl` sends neither, so Sanctum treats the call as token-based, `StartSession`
never runs, and `/auth/login` fails with **HTTP 500 `Session store not set on
request`** *after* verifying the password. That is the documented design working
as intended, not a deployment fault, but it makes an Origin-less smoke test
report a broken site. A browser always sends these headers; `curl` must be told
to.

```bash
ORIGIN=https://erp.ogami.example      # must match a SANCTUM_STATEFUL_DOMAINS entry
H=(-H "Origin: $ORIGIN" -H "Referer: $ORIGIN/" -H "Accept: application/json" \
   -H "X-Requested-With: XMLHttpRequest")

# CSRF cookie endpoint (should return 204 + Set-Cookie XSRF-TOKEN)
curl -i -c /tmp/c.jar "${H[@]}" $ORIGIN/sanctum/csrf-cookie

# Login. The cookie is URL-encoded, so decode %3D back to = before sending it.
TOKEN=$(grep XSRF-TOKEN /tmp/c.jar | awk '{print $7}' | sed 's/%3D/=/g')
curl -i -b /tmp/c.jar -c /tmp/c.jar "${H[@]}" \
    -H "X-XSRF-TOKEN: $TOKEN" \
    -H "Content-Type: application/json" \
    -d "{\"email\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PASSWORD\"}" \
    $ORIGIN/api/v1/auth/login

# Authenticated user fetch
curl -i -b /tmp/c.jar "${H[@]}" $ORIGIN/api/v1/auth/user
```

Use the `ADMIN_EMAIL` and `ADMIN_PASSWORD` you set in section 5 — those are the
only credentials that exist on a fresh install. A successful login returns the
user with a **hashed** `id` (`"id":"pqYKqmK8N4"`, never `"id":1`) and the
permission array for its role.

In a browser, open the site and confirm in DevTools → Application → Cookies:

- `ogami_session` (or your `SESSION_NAME`) is **HttpOnly**, **Secure**, **SameSite=Lax**
- `XSRF-TOKEN` is **Secure**, **SameSite=Lax**

Run [SSL Labs](https://www.ssllabs.com/ssltest/) against the domain — target grade is **A** or higher.

## 9. Daily backups

The repo ships `scripts/db-backup.sh` for the dump itself and
`scripts/db-backup-cron.sh` for the host-level validation/copy/upload wrapper.
Prepare the persistent host directory once; the production compose file mounts
it into the Postgres container:

```bash
sudo install -d -m 0750 /var/backups/ogami
```

```bash
sudo tee /etc/cron.daily/ogami-pgdump <<'EOF'
#!/bin/sh
set -eu
cd /opt/ogami-erp
# Source env so the host wrapper can upload to BACKUP_S3_BUCKET / AWS_*.
set -a; . ./.env; set +a
DB_CONTAINER=ogami-db \
CONTAINER_BACKUP_DIR=/var/backups/ogami \
HOST_BACKUP_DIR=/var/backups/ogami \
DB_USERNAME="$DB_USERNAME" DB_PASSWORD="$DB_PASSWORD" DB_DATABASE="$DB_DATABASE" \
BACKUP_KEEP=30 BACKUP_S3_BUCKET="${BACKUP_S3_BUCKET:-}" \
BACKUP_S3_PREFIX="${BACKUP_S3_PREFIX:-}" \
/opt/ogami-erp/scripts/db-backup-cron.sh
EOF
sudo chmod +x /etc/cron.daily/ogami-pgdump
sudo /etc/cron.daily/ogami-pgdump   # test once
```

The wrapper runs the checked-in script through `/opt/scripts/db-backup.sh`
inside the db container, where `/var/backups/ogami` is a persistent host mount.
It validates the archive, bounds host retention, and performs an optional S3
upload from the host (the stock Postgres image is not assumed to contain the
AWS CLI). A configured off-site target fails the backup command if the host
tool or upload is unavailable.

The Laravel scheduler also runs `db:backup` daily at 03:17. Production API
images include the backup script, `pg_dump`, and the optional AWS CLI, and the
default scheduler output is retained in the shared application storage. The
host-cron path above remains the operator-visible copy used for restore drills.

### Off-site (S3) replication

Daily backups live on the same droplet by default. To replicate off-site:

1. Provision an S3 (or S3-compatible) bucket with versioning + lifecycle
   rules — never reuse the prod AWS account/key for anything else.
2. Set in `.env`:
   ```
   BACKUP_S3_BUCKET=s3://ogami-backups
   BACKUP_S3_PREFIX=postgres/
   AWS_ACCESS_KEY_ID=AKIA...
   AWS_SECRET_ACCESS_KEY=...
   AWS_DEFAULT_REGION=ap-southeast-1
   ```
3. Re-run the cron entry; you should see `uploading to s3://...` in the
   stderr stream and the bucket should pick up the gzipped dump.

When BACKUP_S3_BUCKET is unset (default), the script is local-only — no
errors, no warnings.

## 10. Subsequent deploys

### Independent production health check

The repository ships `scripts/ogami-healthcheck.sh`, which checks the public
Cloudflare path, all production containers, the durable scheduler ledger, the
freshness and integrity of the latest Postgres backup, and root-disk usage. It
is designed to run outside the application so it can detect a dead API or
container.

Install the systemd timer once on the VPS:

```bash
sudo install -m 0644 ops/systemd/ogami-healthcheck.service /etc/systemd/system/ogami-healthcheck.service
sudo install -m 0644 ops/systemd/ogami-healthcheck.timer /etc/systemd/system/ogami-healthcheck.timer
sudo systemctl daemon-reload
sudo systemctl enable --now ogami-healthcheck.timer
sudo systemctl start ogami-healthcheck.service
sudo journalctl -u ogami-healthcheck.service -n 20 --no-pager
```

The timer records failures in the system journal. Pair it with an external
uptime/alerting service for phone or email notifications; a host that is down
cannot notify from its own timer.

For the host-level daily database backup, install the repository's safe cron
entry instead of sourcing `.env` directly from a shell:

```bash
sudo install -m 0755 ops/cron/ogami-pgdump /etc/cron.daily/ogami-pgdump
sudo /etc/cron.daily/ogami-pgdump
tail -n 20 /var/log/ogami-backup.log
```

Once the initial deploy is in, deploys are a `git pull + rebuild + migrate`:

```bash
cd /opt/ogami-erp
git fetch origin
git checkout v0.1-sem1   # or a tag, or main
git pull --ff-only

docker compose -f docker-compose.prod.yml build --pull
docker compose -f docker-compose.prod.yml up -d db redis
# Take the pre-migration backup before changing the schema.
DB_PASSWORD="$DB_PASSWORD" make prod-backup
status=missing
for i in $(seq 1 60); do
    status="$(docker inspect -f '{{.State.Health.Status}}' ogami-db 2>/dev/null || echo missing)"
    [ "$status" = healthy ] && break
    sleep 3
done
[ "$status" = healthy ] || { echo "ERROR: production database did not become healthy" >&2; exit 1; }
# Migrate before starting the new code's consumers. Laravel skips already-run
# files, so this remains safe for additive/backwards-compatible migrations.
docker compose -f docker-compose.prod.yml up migrate
docker compose -f docker-compose.prod.yml up -d api nginx reverb queue scheduler
docker compose -f docker-compose.prod.yml exec api php artisan config:cache
docker compose -f docker-compose.prod.yml exec api php artisan route:cache
docker compose -f docker-compose.prod.yml exec api php artisan view:cache

# Rebuild the SPA if /spa changed.
cd spa && docker run --rm -v "$PWD:/app" -w /app node:20-alpine sh -c \
    "npm ci --no-audit --no-fund && npm run build"
docker compose -f /opt/ogami-erp/docker-compose.prod.yml exec nginx nginx -s reload
```

The production Compose file also contains a one-shot `migrate` dependency:
direct `docker compose up` waits for that service to complete successfully
before starting the API and its consumers. The explicit `up migrate` command in
this runbook makes that gate visible and rerunnable for an operator-controlled
release.

## 11. Rollback (atomic deploy)

Phase 5b switched the GitHub Actions deploy to atomic releases: each
deploy extracts a tarball into `$DEPLOY_PATH/releases/release-<ts>-<sha>/`
and atomically retargets `$DEPLOY_PATH/current` to it. The last 5
releases are retained on disk so rollback is a one-command operation.

```bash
cd /opt/ogami-erp
ls -1dt releases/release-* | head -n 5
PREV=releases/release-20260605-101212-abc1234     # whichever you want
ln -sfn $PREV current.new && mv -Tf current.new current
docker compose -f current/docker-compose.prod.yml up -d
# Migrations should be backwards-compatible. If not, restore from backup:
gunzip -c /var/backups/ogami/ogami-YYYYMMDD-HHMM.sql.gz | \
    docker compose -f current/docker-compose.prod.yml exec -T db \
    psql -U "$DB_USERNAME" -d "$DB_DATABASE"
```

The `current/.env` and `current/api/storage/` symlinks point at the
shared mutable state under `shared/`, so rolling back the release does
NOT lose uploaded files or env config.

**First-time prep (one-off, only required when migrating from the old
in-place deploy):**

```bash
mkdir -p /opt/ogami-erp/{releases,shared/storage}
mv /opt/ogami-erp/.env /opt/ogami-erp/shared/.env
mv /opt/ogami-erp/api/storage/* /opt/ogami-erp/shared/storage/
# Seed an initial release that points at the old checkout if you want a
# rollback target before the next deploy:
ln -s /opt/ogami-erp /opt/ogami-erp/releases/release-genesis
ln -s /opt/ogami-erp/releases/release-genesis /opt/ogami-erp/current
```

## 12. Monitoring & ops cheatsheet

```bash
# Tail logs
docker compose -f docker-compose.prod.yml logs -f api
docker compose -f docker-compose.prod.yml logs -f nginx

# Tinker shell
docker compose -f docker-compose.prod.yml exec api php artisan tinker

# Queue status / restart
docker compose -f docker-compose.prod.yml exec api php artisan queue:restart

# Queue lease invariant: REDIS_QUEUE_RETRY_AFTER must stay above the longest
# queued job timeout (1800s in the shipped production compose; default 2400s).
# Restart workers after changing it.
docker compose -f docker-compose.prod.yml exec api php artisan config:show queue.connections.redis.retry_after

# Cross-module automation recovery
docker compose -f docker-compose.prod.yml exec api \
  php artisan supplier:dispatch-recover
# Only after reviewing provider errors and the idempotency key:
docker compose -f docker-compose.prod.yml exec api \
  php artisan supplier:dispatch-recover --retry-failed
docker compose -f docker-compose.prod.yml exec api \
  php artisan outbox:dispatch --retry-failed

# Inspect the worker and scheduler that drive the recovery paths
docker compose -f docker-compose.prod.yml logs --tail=200 queue scheduler

# Run one operator-visible scheduler tick. The fail-fast wrapper returns
# non-zero if any due task fails, which is the same signal Compose uses to
# restart the scheduler container.
docker compose -f docker-compose.prod.yml exec -T api \
  php artisan schedule:run-fail-fast --no-interaction

# Durable scheduler heartbeat/task probe. Run from an independent monitor or
# during incident response; it fails non-zero for a dead/stuck tick, a failed
# task, or a scheduler restart gap that may have missed a calendar window. The
# production scheduler container also exposes this probe as its Docker
# healthcheck; keep an external monitor because a dead container cannot run it.
docker compose -f docker-compose.prod.yml exec -T api \
  php artisan scheduler:health --stale-minutes=15

# Cache flush (admin escape hatch)
docker compose -f docker-compose.prod.yml exec api php artisan cache:clear

# Healthcheck (Phase 4 deep probe — db + redis + queue depth)
curl -sS https://$SERVER_NAME/api/v1/health | jq

# Slow-query log (Phase 5b)
docker compose -f docker-compose.prod.yml exec api \
  tail -f storage/logs/slow-queries-$(date +%F).log
```

### Sentry / error tracking (optional)

Phase 5b ships error-tracking *hooks* but does not bundle the SDK. To
enable Sentry on a deployment:

1. SSH into the droplet:
   ```bash
   cd /opt/ogami-erp
   docker compose -f docker-compose.prod.yml exec api \
     composer require sentry/sentry-laravel
   ```
2. Publish + edit config:
   ```bash
   docker compose -f docker-compose.prod.yml exec api \
     php artisan sentry:publish --dsn=https://<your-public-key>@<your-org>.ingest.sentry.io/<project-id>
   ```
3. In `.env`, add:
   ```
   SENTRY_LARAVEL_DSN=https://...
   SENTRY_TRACES_SAMPLE_RATE=0.1
   SENTRY_PROFILES_SAMPLE_RATE=0.1
   SENTRY_RELEASE=
   ```
4. Verify capture:
   ```bash
   docker compose -f docker-compose.prod.yml exec api \
     php artisan sentry:test
   ```

The Phase 4 `X-Request-ID` middleware is already in place, so Sentry
events carry the request-correlation id automatically via the log
context (Sentry's Laravel integration picks up `Log::shareContext`).

To leave Sentry disabled, do nothing — the absence of
`SENTRY_LARAVEL_DSN` is a no-op.

---

## Risks & gotchas (read before first deploy)

| Issue | What to do |
|---|---|
| `migrate --force` skips already-run migrations, but a faulty migration with a `down()` that drops data will hurt on rollback. Always test migrations against a copy of prod data first. | Run `pg_dump` BEFORE every deploy; test downgrade in staging if you have one. |
| Sanctum cookie auth needs `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE`, `SANCTUM_STATEFUL_DOMAINS` to all match the live domain. Mismatched values silently break auth. | Verify section 8 smoke test passes after every deploy. |
| Geist fonts are SPA-only. PDF generation (DomPDF) uses **DejaVu Sans** — do not try to embed Geist; rendering becomes flaky and slow. | Already enforced in [`api/resources/views/pdf/_layout.blade.php`](../api/resources/views/pdf/_layout.blade.php). |
| The Sprint 4 COA seeder co-exists with three legacy payroll codes (5050/5060/5070) used by [`PayrollGlPostingService`](../api/app/Modules/Payroll/Services/PayrollGlPostingService.php). Reconciliation is queued for Sprint 8. | Mention in defense if asked: "Sprint 3 hardcoded operational codes; Sprint 4 establishes the canonical chart and we kept the legacy codes mapped during the bridge period to preserve audit history." |
| HashIDs `HASHIDS_SALT` MUST stay constant after first deploy. Changing it invalidates every URL/foreign reference in the wild. | Treat the value like an APP_KEY — back it up out-of-band. |
| Reverb WebSocket connections require `wss://` with `connect-src` allowing it in CSP. Already configured in `prod.conf`. | If clients can't connect, check the browser console for CSP violations. |

**Acceptance:** open `https://erp.ogami.example/login` in a browser, sign in
as the seeded admin user, navigate Accounting → Trial Balance, see the
Sprint 3 payroll JE reflected, click Print → PDF downloads. Defense ready.
