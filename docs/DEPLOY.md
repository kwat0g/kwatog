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

```bash
sudo tee /etc/cron.daily/ogami-pgdump <<'EOF'
#!/bin/sh
set -eu
BACKUP_DIR=/var/backups/ogami
mkdir -p "$BACKUP_DIR"
DATE=$(date +%Y%m%d-%H%M)
docker compose -f /opt/ogami-erp/docker-compose.prod.yml exec -T db \
    pg_dump -U "$(grep ^DB_USERNAME /opt/ogami-erp/.env | cut -d= -f2)" \
            -d "$(grep ^DB_DATABASE /opt/ogami-erp/.env | cut -d= -f2)" \
    | gzip > "$BACKUP_DIR/ogami-$DATE.sql.gz"
# Prune anything older than 30 days.
find "$BACKUP_DIR" -name 'ogami-*.sql.gz' -mtime +30 -delete
EOF
sudo chmod +x /etc/cron.daily/ogami-pgdump
sudo /etc/cron.daily/ogami-pgdump   # test once
```

For off-site backups, configure rclone or `aws s3 cp` in the same script and
upload to a private bucket.

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

## 11. Rollback

If a deploy goes wrong:

```bash
cd /opt/ogami-erp
git checkout <previous-tag>
docker compose -f docker-compose.prod.yml build --pull
docker compose -f docker-compose.prod.yml up -d
# Migrations should be backwards-compatible; if not, restore from latest pg_dump.
gunzip -c /var/backups/ogami/ogami-YYYYMMDD-HHMM.sql.gz | \
    docker compose -f docker-compose.prod.yml exec -T db \
    psql -U "$DB_USERNAME" -d "$DB_DATABASE"
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
```

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
