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
nano .env   # fill in DB_PASSWORD, APP_KEY, HASHIDS_SALT, MAIL_*, etc.
```

Generate values:

```bash
# APP_KEY (run inside a temporary container so PHP isn't required on the host)
docker run --rm -v "$PWD:/app" -w /app/api composer:2 php artisan key:generate --show

# DB_PASSWORD, HASHIDS_SALT, REVERB_APP_KEY, REVERB_APP_SECRET, MEILISEARCH_KEY
openssl rand -base64 32   # run multiple times
```

**Critical:** the `${SERVER_NAME}` placeholder in
[`docker/nginx/prod.conf`](../docker/nginx/prod.conf) must be substituted at deploy
time. The Makefile target below does that with `envsubst`.

## 6. First-time deploy

The repo's [`Makefile`](../Makefile) gains a production-flavoured target. Add it
on the host (or use the inline command):

```bash
# Build images, run migrations, seed once, start services.
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml build --no-cache
# Render nginx config with the right SERVER_NAME.
export SERVER_NAME=erp.ogami.example
envsubst '${SERVER_NAME}' < docker/nginx/prod.conf > docker/nginx/prod.conf.rendered
mv docker/nginx/prod.conf.rendered docker/nginx/prod.conf
# Start everything.
docker compose -f docker-compose.prod.yml up -d
# Wait for db.
sleep 10
# Run migrations + seed once.
docker compose -f docker-compose.prod.yml exec api php artisan migrate --force
docker compose -f docker-compose.prod.yml exec api php artisan db:seed --force
docker compose -f docker-compose.prod.yml exec api php artisan storage:link
```

> **DO NOT** run `migrate:fresh` in production. Ever. Laravel only re-runs
> migrations it hasn't seen, so subsequent deploys are safe with `migrate --force`.

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

```bash
# CSRF cookie endpoint (should return 204 + Set-Cookie XSRF-TOKEN)
curl -i -c /tmp/c.jar https://erp.ogami.example/sanctum/csrf-cookie

# Login
TOKEN=$(grep XSRF-TOKEN /tmp/c.jar | awk '{print $7}')
curl -i -b /tmp/c.jar -c /tmp/c.jar \
    -H "X-XSRF-TOKEN: $TOKEN" \
    -H "Content-Type: application/json" \
    -d '{"email":"admin@ogami.test","password":"AdminPassword1!"}' \
    https://erp.ogami.example/api/v1/auth/login

# Authenticated user fetch
curl -i -b /tmp/c.jar https://erp.ogami.example/api/v1/auth/user
```

In a browser, open the site and confirm in DevTools → Application → Cookies:

- `ogami_session` (or your `SESSION_NAME`) is **HttpOnly**, **Secure**, **SameSite=Lax**
- `XSRF-TOKEN` is **Secure**, **SameSite=Lax**

Run [SSL Labs](https://www.ssllabs.com/ssltest/) against the domain — target grade is **A** or higher.

## 9. Daily backups

The repo ships `scripts/db-backup.sh` which handles dump, retention,
and optional off-site upload to S3 in one script. Cron entry on the
host runs it via `docker compose exec`:

```bash
sudo tee /etc/cron.daily/ogami-pgdump <<'EOF'
#!/bin/sh
set -eu
cd /opt/ogami-erp
# Source env so BACKUP_S3_BUCKET / AWS_* (if configured) reach the script.
set -a; . ./.env; set +a
docker compose -f docker-compose.prod.yml exec -T \
    -e DB_HOST -e DB_PORT \
    -e DB_USERNAME -e DB_PASSWORD -e DB_DATABASE \
    -e BACKUP_DIR=/var/backups/ogami \
    -e BACKUP_KEEP=30 \
    -e BACKUP_S3_BUCKET -e BACKUP_S3_PREFIX \
    -e AWS_ACCESS_KEY_ID -e AWS_SECRET_ACCESS_KEY -e AWS_DEFAULT_REGION \
    db /opt/scripts/db-backup.sh
EOF
sudo chmod +x /etc/cron.daily/ogami-pgdump
sudo /etc/cron.daily/ogami-pgdump   # test once
```

The script mounts at `/opt/scripts/db-backup.sh` inside the db container
(add `- ./scripts:/opt/scripts:ro` to the `db` service volumes in
`docker-compose.prod.yml` if not already there).

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
3. The db container needs the AWS CLI. Either bake it into a custom
   Dockerfile or install at runtime:
   ```bash
   docker compose -f docker-compose.prod.yml exec db \
     apk add --no-cache aws-cli
   ```
4. Re-run the cron entry; you should see `uploading to s3://...` in the
   stderr stream and the bucket should pick up the gzipped dump.

When BACKUP_S3_BUCKET is unset (default), the script is local-only — no
errors, no warnings.

## 10. Subsequent deploys

Once the initial deploy is in, deploys are a `git pull + rebuild + migrate`:

```bash
cd /opt/ogami-erp
git fetch origin
git checkout v0.1-sem1   # or a tag, or main
git pull --ff-only

docker compose -f docker-compose.prod.yml build --pull
docker compose -f docker-compose.prod.yml up -d

# Backwards-compatible migrations only — Laravel skips already-run files.
docker compose -f docker-compose.prod.yml exec api php artisan migrate --force
docker compose -f docker-compose.prod.yml exec api php artisan config:cache
docker compose -f docker-compose.prod.yml exec api php artisan route:cache
docker compose -f docker-compose.prod.yml exec api php artisan view:cache

# Rebuild the SPA if /spa changed.
cd spa && docker run --rm -v "$PWD:/app" -w /app node:20-alpine sh -c \
    "npm ci --no-audit --no-fund && npm run build"
docker compose -f /opt/ogami-erp/docker-compose.prod.yml exec nginx nginx -s reload
```

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
