# Ogami ERP — Production Deployment Runbook

Last checked: 2026-10-29. The verified production stack is Ubuntu 26.04.1 LTS,
Docker Compose using [docker-compose.prod.yml](../docker-compose.prod.yml), a
static React SPA, Nginx, Cloudflare Full (strict), PostgreSQL 16, Redis 7, and
Laravel 12. Production uses the repository-root .env created from
[.env.production.example](../.env.production.example); it does not use api/.env.

Always pass -f docker-compose.prod.yml to Compose commands. The default Compose
file is for development and bind-mounts source code; omitting -f on the VPS can
select the wrong stack.

## Choose one deployment layout

The existing VPS is an in-place checkout under /opt/ogami-erp. Its manual
update entry point is scripts/deploy-update.sh. The GitHub Actions workflow
uses timestamped releases under DEPLOY_PATH/releases, shared mutable state
under DEPLOY_PATH/shared, and a DEPLOY_PATH/current symlink. These layouts are
not interchangeable. Use the in-place script only on the existing flat
checkout. For a new host, provision the releases/shared layout before running
the GitHub workflow. Do not point that workflow at a flat checkout.

Both deployment paths now stop the mail worker before migrations, rebuild and
recreate it with the new release, and verify it is running. They build and
check the framework caches before restarting PHP-FPM and starting consumers.
See [the deployment report](DEPLOYMENT-REPORT.md) for other known source-level
seed and release blockers.

## Security and data handling

The deployment report was previously committed with SSH and application
credentials. Treat every credential that appeared there or in Git history as
compromised: rotate it, remove values from the current document, and clean
public Git history separately. Editing a file does not erase old commits.

Never copy a development .env or api/.env to production. Keep secrets in the
host's root .env (mode 600) or a secret manager, and do not put credentials in
Markdown, shell history, screenshots, or Git. Generate a fresh APP_KEY,
database password, HASHIDS_SALT, Reverb secret, backup recovery secret, and
SMTP credential. Preserve HASHIDS_SALT only when restoring the same database,
because changing it can invalidate existing obfuscated links.

Production defaults must include APP_ENV=production, APP_DEBUG=false,
SESSION_SECURE_COOKIE=true, SEED_DEMO_DATA=false, and
SEED_REFERENCE_DATA=false. Use a real administrator email and a unique
administrator password. The demo account seeder creates active accounts with
a shared known password and does not force a password change.

Demo seeding is destructive. ComprehensiveDemoSeeder truncates transaction
tables with PostgreSQL CASCADE. Run it only against a fresh, disposable demo
database or after a verified backup and an explicit reset decision. Never
include demo seeding in a routine production deploy.

## Moving or rebuilding the current VPS

Before migrating a host, create database and private-file backups, copy them
off-host, and prove both can be restored to a disposable environment. Store an
encrypted copy of .env separately. Restore data and private files before
opening traffic. Do not run AdminUserSeeder against a restored database.

For the current Cloudflare topology, use a Cloudflare Origin CA certificate
and Full (strict). Restrict the AWS security group so SSH is reachable only
from the operator's fixed address and web ports only from Cloudflare's
published IP ranges. UFW alone is not sufficient protection for
Docker-published ports. If direct API clients or webhooks are needed, create
explicit Cloudflare WAF rules for those paths instead of exposing an
unrestricted origin.

---

## 1. Provision a VPS

1. Create an Ubuntu VPS:
   - Use a maintained Ubuntu LTS release. Ubuntu 26.04.1 LTS is the version
     checked with this deployment on 2026-10-29.
   - Starting size: **4 GB RAM, 2 vCPU, 80 GB SSD**
   - Region near the users and database backups
   - SSH key: add yours; **disable password auth**.
2. Point `ogamiph.dev` and `www.ogamiph.dev` at the new public IPv4/IPv6.
3. SSH in with the provisioned key and create a non-root deploy/admin user:
   ```bash
   sudo adduser --disabled-password --gecos "" ogami
   sudo usermod -aG sudo ogami
   sudo install -d -m 700 -o ogami -g ogami /home/ogami/.ssh
   sudo install -m 600 -o ogami -g ogami ~/.ssh/authorized_keys /home/ogami/.ssh/authorized_keys
   ```
4. Configure UFW, and enforce the same restrictions in the AWS security group.
   Docker-published ports can bypass UFW rules:
   ```bash
   sudo ufw default deny incoming
   sudo ufw default allow outgoing
   sudo ufw allow OpenSSH
   sudo ufw allow 80
   sudo ufw allow 443
   sudo ufw enable
   ```
5. Disable root SSH login and password authentication with a drop-in:
   ```bash
   sudo tee /etc/ssh/sshd_config.d/99-ogami-hardening.conf >/dev/null <<'EOF'
   PermitRootLogin no
   PasswordAuthentication no
   KbdInteractiveAuthentication no
   PubkeyAuthentication yes
   EOF
   sudo sshd -t
   sudo systemctl reload ssh
   ```
   Keep the current SSH session open and verify a second key-based login before
   closing it. Never enable password SSH for the deploy user.

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
  docker-buildx-plugin docker-compose-plugin
sudo usermod -aG docker ogami
# log out and back in for group change to take effect
```

## 3. Prepare an in-place checkout (existing VPS only)

The existing VPS uses this flat checkout. A new GitHub Actions deployment
ships releases from the CI runner and does not clone source into the host root.

```bash
sudo mkdir -p /opt/ogami-erp && sudo chown ogami:ogami /opt/ogami-erp
cd /opt/ogami-erp
git clone https://github.com/kwat0g/kwatog.git .
git checkout main
```

## 4. Provision TLS for Cloudflare

For the documented topology, create a Cloudflare Origin CA certificate for
the apex and www hostnames. Install it on the VPS at
/etc/letsencrypt/live/ogamiph.dev/fullchain.pem and privkey.pem, with the
private key readable only by root. Production Compose mounts /etc/letsencrypt
read-only into Nginx. Set Cloudflare SSL/TLS mode to Full (strict), proxy the
DNS records, and verify the certificate names match SERVER_NAME.

An Origin CA certificate is trusted between Cloudflare and the origin;
browsers must use the proxied hostname. It is not a public browser certificate
for direct-IP access. The current certificate has no automated renewal job, so
record its expiry and replace it before expiration.

If a future deployment will not sit behind Cloudflare, use a publicly trusted
certificate and document its renewal path instead. Do not mix Caddy, Vite's
development server, and this Nginx production Compose setup. See the official
[Cloudflare IP ranges](https://www.cloudflare.com/ips/) when configuring the
origin security group.

### Cloudflare rules for the SPA and API

The SPA calls same-origin Laravel routes under `/api/v1/` and
`/sanctum/csrf-cookie`; Reverb uses `/app/` and `/apps/` WebSocket paths. A
Cloudflare challenge on an API request prevents the SPA from receiving JSON and
can make features appear slow or broken. If a challenge appears, find its Ray
ID in **Security → Events** and identify the service/rule that challenged it
before changing protection. The Ray ID is the quickest way to isolate the
specific request. See [Cloudflare's Ray ID
guide](https://developers.cloudflare.com/fundamentals/reference/cloudflare-ray-id/).

For a custom WAF or Super Bot Fight Mode rule, edit the matching challenge rule
or add a narrowly scoped exception for the affected application paths. Keep
rate limits and the other managed protections enabled where possible. The
free Bot Fight Mode cannot be bypassed by a custom Skip rule; if Security
Events identifies it as the cause, turn Bot Fight Mode off or use Super Bot
Fight Mode for path-specific control. Do not disable the Cloudflare proxy or
exempt the whole hostname to fix an API false positive. See [Skip rule
limits](https://developers.cloudflare.com/waf/custom-rules/skip/) and [Bot
Fight Mode false-positive guidance](https://developers.cloudflare.com/bots/troubleshooting/false-positives/).

## 5. Configure environment

For the existing in-place host, put the production environment file at
/opt/ogami-erp/.env. For a new GitHub Actions atomic release, put it at
$DEPLOY_PATH/shared/.env; the workflow symlinks it into each release. Do not
place secrets in a release directory or release tarball.

```bash
cd /opt/ogami-erp
cp .env.production.example .env
chmod 600 .env
nano .env   # fill generated secrets, SMTP credentials, admin, and company settings
```

For direct outbound mail through Amazon SES, use the standard SMTP submission
endpoint `email-smtp.<region>.amazonaws.com` and SES SMTP credentials created in
that same region. Verify the sender address or domain in SES. A hostname ending
in `.mail-manager-smtp.amazonaws.com` is a Mail Manager ingress endpoint. It
applies its traffic policy and rule set after SMTP accepts a message; use it
for application mail only when the configured rules explicitly send the
message to the intended external recipient and provider logs confirm delivery.
Do not treat SMTP `235` or a successful Laravel queue job as proof that Gmail
received the message. If the organization deliberately uses Mail Manager,
review the ingress rule set and its CloudWatch/S3 event logs for each test.
Configure sender authentication for the actual outbound service. For SES,
publish the identity's DKIM CNAMEs. SES's default MAIL FROM uses an
`amazonses.com` subdomain for SPF; if a custom MAIL FROM domain is configured,
publish the exact SES-provided MX and SPF TXT records on that subdomain. Do not
add a guessed root SPF record; keep at most one SPF record on each hostname.
See [SES SPF guidance](https://docs.aws.amazon.com/ses/latest/dg/send-email-authentication-spf.html)
and [custom MAIL FROM setup](https://docs.aws.amazon.com/ses/latest/dg/mail-from.html).
The production settings are `MAIL_PORT=587` and `MAIL_SCHEME=smtp`.
The production Compose file uses `env_file` with `format: raw`: values pass
through unchanged, including `$`, `#`, and quote characters. Do not quote
values, even when they contain spaces; keep the password exactly as supplied
and do not shell-source `.env`.

Redis uses two internal services. `redis` is the persistent queue/default
connection with a 256 MiB `noeviction` cap; `redis-cache` is disposable with a
96 MiB `volatile-lru` cap. Keep `REDIS_HOST=redis` and set the independent
`REDIS_CACHE_HOST=redis-cache`, port, DB and credentials from
`.env.production.example`. Do not point `REDIS_CACHE_URL` at the queue service.
The API and migration service wait for both Redis health checks. On an upgrade
from the previous single-Redis layout, restart every API/worker/scheduler
consumer onto `redis-cache` before deleting only the old cache-prefix keys
from the old cache DB; leave queue DB 0 untouched. Count and record the
one-time cleanup during the first deployment of this topology.

The general queue/default Redis connection uses logical DB 0. Broadcast jobs
use logical DB 1 by default (`REDIS_BROADCAST_DB`) on the same non-evicting
`redis` service, so this adds no Redis process or memory limit. Cache remains
on the separate `redis-cache` service and sessions remain in PostgreSQL. If
`REDIS_BROADCAST_HOST` is configured, the broadcast lane can use a dedicated
Redis host instead. On first deployment of the split, already queued broadcast
jobs may still be in DB 0. After rebuilding config and restarting API/Horizon,
drain those jobs with the retained legacy connection, then repeat the drain
after the longest configured broadcast retry delay:

```bash
docker compose -f docker-compose.prod.yml exec -T api \
  php artisan queue:work redis_broadcasts_legacy --queue=broadcasts --stop-when-empty
```

Do not delete or flush queue DB 0. Check the legacy broadcasts queue and delayed
retries are empty before removing this one-off drain step; the normal broadcast
worker consumes DB 1 after cutover.

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

Keep the seeding flags explicitly false in the production file:

    SEED_DEMO_DATA=false
    SEED_REFERENCE_DATA=false

The deployment script checks the required SMTP values and performs a TLS
reachability check. SMTP reachability is not the same as deliverability: the
Mail Manager traffic policy and rule set must permit the authenticated sender
and route the message to the internet. Confirm the message in the Mail
Manager archive and the recipient inbox when testing Gmail/Yahoo delivery.

Generate values:

```bash
# APP_KEY (standalone PHP; does not need the application dependencies)
docker run --rm composer:2 php -r 'echo "base64:", base64_encode(random_bytes(32)), PHP_EOL;'

# DB_PASSWORD, HASHIDS_SALT, REVERB_APP_KEY, REVERB_APP_SECRET,
# HEALTH_DETAIL_TOKEN, BACKUP_RECOVERY_SECRET
openssl rand -hex 32      # run separately for each secret
```

`REVERB_APP_KEY` is public client configuration, not the secret. Set GitHub's
`production` environment variable `VITE_REVERB_APP_KEY` to the same value; the
deploy workflow embeds it in the SPA build. `VITE_REVERB_HOST`, port, and scheme
come from `server_name` and HTTPS defaults.

**Critical:** `SERVER_NAME` in `.env` must be the real production DNS name.
The production Compose file mounts [`docker/nginx/prod.conf`](../docker/nginx/prod.conf)
as an official Nginx template; the Nginx entrypoint expands `SERVER_NAME` on
container start. The API and queue processes enforce the same requirement, so a
missing or localhost value fails closed.

## 6. First-time deploy

Use this section only for a new host and an empty database. For a restored or
existing database, use the subsequent-deploy procedure; do not run the demo
seeders. The GitHub Actions workflow is the release path for a new
releases/shared/current layout. Its required secrets and first-time layout are
documented at the top of .github/workflows/deploy.yml. The existing flat VPS
uses scripts/deploy-update.sh instead. Never mix the two layouts.

For a new host, prepare the atomic layout as the deploy user before dispatching
the workflow. Set DEPLOY_PATH to the value configured in GitHub Actions:

    mkdir -p "$DEPLOY_PATH/releases" "$DEPLOY_PATH/shared/storage"
    git clone --depth 1 https://github.com/kwat0g/kwatog.git /tmp/ogami-env-template
    cp /tmp/ogami-env-template/.env.production.example "$DEPLOY_PATH/shared/.env"
    rm -rf /tmp/ogami-env-template
    chmod 600 "$DEPLOY_PATH/shared/.env"

Fill that shared .env with unique production secrets, real company data, SMTP
settings, and SERVER_NAME. Configure GitHub production secrets DEPLOY_HOST,
DEPLOY_USER, DEPLOY_SSH_KEY, DEPLOY_PATH and variable VITE_REVERB_APP_KEY to
match REVERB_APP_KEY in the shared .env. Verify the deploy user can write to
the releases and shared directories. The workflow creates current on its first
successful release.

Before deploying, confirm the release ref is committed and clean, the root
.env is mode 600, SERVER_NAME matches the DNS name, and the database and
private-file backup destinations are configured. Keep AWS inbound 80/443
closed until seed verification and account-password changes are complete. Do
not copy secrets into the release tarball. The workflow packages committed
source and SPA artifacts; it does not include uncommitted working-tree fixes.

Run the following commands from the deployment root: /opt/ogami-erp for the
existing in-place host, or $DEPLOY_PATH/current for the GitHub Actions layout.
For a fresh non-demo installation, keep both seed flags false and run the
ordinary seeder after migrations, before exposing the application. Keep the
AWS 80/443 ingress closed. If the workflow already started the consumers, stop
them first; leave only the database and Redis running:

    docker compose -f docker-compose.prod.yml stop api nginx reverb horizon scheduler queue-mail
    docker compose -f docker-compose.prod.yml run --rm --no-deps api php artisan db:seed --force

AdminUserSeeder skips its bootstrap account if ADMIN_NAME, ADMIN_EMAIL, or
ADMIN_PASSWORD is missing. Confirm the intended system administrator exists
before opening traffic; a successful seeder exit alone is not sufficient.

For a thesis/demo installation, first use a fresh database and keep the
production .env flags false. Keep the app consumers stopped and leave only DB
and Redis running. The temporary one-shot override below enables the reference
and demo fixture groups without persisting demo mode in production
configuration:

    docker compose -f docker-compose.prod.yml run --rm --no-deps \
      -e APP_ENV=local -e SEED_DEMO_DATA=true -e SEED_REFERENCE_DATA=true \
      api php artisan db:seed --force

The demo seeder truncates transaction tables with PostgreSQL CASCADE. Do not
run that command against a populated database unless that reset is intended
and a verified backup exists. The current source has two known clean-seed
issues: DemoRfqBudgetSeeder reaches a PurchaseRequestFactory that uses Faker,
which is omitted from the production image, and ComprehensiveDemoSeeder's
CASCADE removes routing-operation rows created earlier in DatabaseSeeder. Do
not treat a late seeder failure as success. Fix those source issues and verify
all fixtures on a disposable database before using this sequence for a new
public demo. Until the routing-order fix is merged, run ProductRoutingSeeder
after the full demo seed and confirm active routings have operations.

DemoAccountSeeder assigns a shared known password and sets
must_change_password=false. Set ADMIN_EMAIL to a real address that is not one
of the demo fixture accounts, then change every demo account password before
opening a public demo. The standard account admin@ogami.test is not a
deliverable email address. Do not publish demo credentials in this runbook.

After seeding succeeds, start the application services:

    docker compose -f docker-compose.prod.yml up -d api nginx reverb horizon scheduler queue-mail
    docker compose -f docker-compose.prod.yml exec -T api php artisan storage:link

Verify the API and SPA over the origin, check scheduler health and failed jobs,
confirm all services are running including queue-mail, and send a real test
message to a deliverable inbox. A successful SMTP handshake alone does not
prove that email delivery works.

### PHP security patch level

As checked on 2026-10-29, every running PHP service uses PHP 8.3.36. This is the
fixed PHP 8.3 release for CVE-2026-109618 and CVE-2026-109619, the critical
remote-code-execution vulnerabilities announced on 2026-10-21. No PHP minor
upgrade is needed just to address those CVEs while the runtime is 8.3.36.
The live runtime was already patched, so no PHP container restart was needed
for this deployment. Both development and production Dockerfiles now pin the
PHP patch and Alpine minor to `php:8.3.36-fpm-alpine3.24` ([official image
tag](https://hub.docker.com/_/php/tags?name=8.3.36-fpm-alpine3.24)). For the next
PHP security release, check the PHP advisory and a matching official image tag,
then update both `docker/php/Dockerfile` and `docker/php/Dockerfile.prod` in the
same change. Keep the PHP major/minor compatible with `api/composer.json` and
record the resulting runtime version in the deployment report.

Rebuild and recreate every production PHP image together after a PHP patch
change. This includes the one-shot `migrate` image and all five long-running PHP
services, including `queue-mail`:

```bash
docker compose -f docker-compose.prod.yml build --pull migrate api reverb horizon scheduler queue-mail
docker compose -f docker-compose.prod.yml up -d --force-recreate migrate api reverb horizon scheduler queue-mail

for service in api horizon scheduler queue-mail reverb; do
  printf '%s: ' "$service"
  docker compose -f docker-compose.prod.yml exec -T "$service" php -r 'echo PHP_VERSION, PHP_EOL;'
done
docker compose -f docker-compose.prod.yml run --rm --no-deps migrate php -r 'echo PHP_VERSION, PHP_EOL;'
```

All six runtime checks must report the intended patch. The PHP 8.3 branch
receives security-only support until 2027-12-31; plan and stage a move to a
newer supported branch before then. See [the PHP security
advisory](https://www.php.net/archive/2026.php#2026-10-21-5), [PHP 8.3.36
release notes](https://www.php.net/releases/8_3_36.php), and [PHP's support
schedule](https://www.php.net/supported-versions.php). PHP also published a
follow-up conditional session-upload-progress advisory on 2026-10-23. The
app/config scan found no runtime session-serializer switch; recheck this
configuration-specific issue on each PHP advisory and apply the vendor
mitigation if the affected handler sequence is introduced.

Nginx also uses the floating nginx:1.27-alpine tag. The official stable
release was 1.30.6 on 2026-10-29; plan a staged upgrade, pin a reviewed version
or digest, and verify SPA/API/TLS/WebSocket behavior before release. Check the
[official Nginx releases](https://nginx.org/en/download.html) again when
planning that change.

> Do not run migrate:fresh on a production database. Routine deploys run only
> pending migrations and never run DatabaseSeeder.

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
cd /opt/ogami-erp
export SERVER_NAME=ogamiph.dev
export VITE_REVERB_APP_KEY='<same public value as REVERB_APP_KEY in .env>'
make build-spa
# `dist/` is mounted into nginx via the compose volume; no further action needed.
```

The SPA build receives `SERVER_NAME` and `VITE_REVERB_APP_KEY`; do not build it
with the default key if the backend uses a different `REVERB_APP_KEY`.

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
ORIGIN=https://ogamiph.dev           # must match a SANCTUM_STATEFUL_DOMAINS entry
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
exec /opt/ogami-erp/ops/cron/ogami-pgdump
EOF
sudo chmod 0755 /etc/cron.daily/ogami-pgdump
sudo /etc/cron.daily/ogami-pgdump   # test once
```

The wrapper runs the checked-in script through `/opt/scripts/db-backup.sh`
inside the db container, where `/var/backups/ogami` is a persistent host mount.
It validates the archive, bounds host retention, and performs an optional S3
upload from the host (the stock Postgres image is not assumed to contain the
AWS CLI). A configured off-site target fails the backup command if the host
tool or upload is unavailable.

The Laravel scheduler runs `db:full-backup` daily at 03:17. Production API
images include both backup scripts, `pg_dump`, and the optional AWS CLI, and the
default scheduler output is retained in the shared application storage. The
host-cron path above remains the operator-visible database copy used for restore
drills.

### Off-site (S3) replication

Daily backups live on the same VPS by default. To replicate off-site:

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

### Admin Backup & Restore center

The admin UI at **Administration → Backup & Restore** uses the queued
`db:full-backup` command. Each full backup contains:

- a validated PostgreSQL dump;
- a validated archive of `storage/app/private` (employee documents, resumes,
  delivery proofs, and other private uploads); and
- SHA-256 metadata recorded in the `backup_operations` ledger.

Set these optional values in the production `.env` to keep the UI artifacts in
the persistent application volume explicitly:

```dotenv
BACKUP_DIR=/var/www/storage/app/backups
BACKUP_FILES_DIRECTORY=/var/www/storage/app/private
BACKUP_FILES_KEEP=30
```

The restore button requires the `admin.backups.manage` permission and a typed
`RESTORE <database-filename>` confirmation. It creates a fresh rollback backup,
enters application maintenance mode, restores the selected database and
private files, applies pending migrations, and then exits maintenance mode.
Do not start a restore during an active business cutover; it is a destructive
operation and should still be exercised against a disposable database during
the quarterly drill.

After the migration is deployed, run the normal permission seeder so the
system administrator role receives the new backup permissions:

```bash
docker compose -f docker-compose.prod.yml exec -T api php artisan db:seed \
  --class=RolePermissionSeeder --force
```

The UI reports whether S3 is configured, but a local backup is not a disaster
recovery plan. Configure the off-site settings above before relying on the
restore center after VPS loss.

## 10. Subsequent deploys

### Independent production health check

The repository ships `scripts/ogami-healthcheck.sh`, which checks the local
HTTPS origin, all production containers, the durable scheduler ledger, the
freshness and integrity of the latest Postgres backup, and root-disk usage. Its
health request resolves the app domain to loopback, bypassing Cloudflare while
still validating the origin certificate. Use an external uptime monitor for
the public Cloudflare path. The host check runs outside the application so it
can detect a dead API or container.

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

### Deploy the existing in-place VPS

The existing host uses /opt/ogami-erp directly, not the releases/current
symlink layout. Before updating, ensure all fixes are committed and the
checkout has no local changes. Take and verify the pre-migration database
backup, then stop the mail worker so it cannot process jobs against a changing
schema:

    cd /opt/ogami-erp
    git status --short
    sudo /etc/cron.daily/ogami-pgdump
    docker compose -f docker-compose.prod.yml stop queue-mail
    ./scripts/deploy-update.sh --branch main

The deploy script now handles queue-mail and framework cache ordering. Do not
use --no-backup for routine releases. Do not run DatabaseSeeder after a deploy.
Verify the API health endpoint, SPA, all services, queue failures, scheduler
heartbeat, and the external Cloudflare path. Cloudflare may challenge
curl or webhook clients even when browser traffic works; configure an explicit
WAF exception for intended integrations.

### Deploy through GitHub Actions

The workflow dispatch deploys an atomic release and expects the releases/shared
layout described at the top of this document. It is not a drop-in replacement
for the existing flat checkout. It stops old consumers before migration,
rebuilds config/route/event/view caches and checks Reverb settings, restarts API
to reload OPcache, then recreates and verifies nginx, Reverb, Horizon,
queue-mail, and scheduler.

PHP security updates require rebuilding every PHP image, including api,
migrate, reverb, horizon, scheduler, and queue-mail. Use --pull so the base
image receives the latest patch. Verify the version inside every running
worker; restarting a container does not update its image.

## 11. Rollback

### GitHub Actions atomic layout

The workflow retains the five newest release directories. Use this procedure
only when DEPLOY_PATH/current is a symlink into DEPLOY_PATH/releases. Select a
known-good release, atomically retarget current, then recreate all application
services including queue-mail:

    cd "$DEPLOY_PATH"
    ls -1dt releases/release-* | head -n 5
    PREV="releases/<known-good-release>"
    ln -sfn "$PREV" current.new && mv -Tf current.new current
    docker compose -f current/docker-compose.prod.yml up -d --force-recreate api nginx reverb horizon scheduler queue-mail

A release rollback does not roll back database migrations. Restore a database
backup only after stopping application consumers, placing the site in
maintenance, and confirming the chosen backup is correct. Database restore is
destructive; follow the restore procedure in §9 and verify private-file state
as well.

### Existing in-place layout

The existing /opt/ogami-erp checkout has no atomic release symlink. Roll back
by deploying a reviewed prior commit with scripts/deploy-update.sh after a
fresh backup. Confirm migrations remain backward-compatible; a code rollback
does not undo schema changes.

Do not create a genesis symlink by moving the current .env/storage paths by
hand. The existing VPS is a flat in-place checkout. Moving it to the atomic
layout needs a separately planned migration with a verified backup and
explicit shared-state symlinks; do not improvise it during a release.

## 12. Monitoring & ops cheatsheet

`make deploy`, `scripts/deploy-update.sh`, and the GitHub workflow stop consumers
before migration, build Laravel's config, route, event and view caches, check
the effective Reverb configuration, restart API to reload OPcache, then start
fresh Nginx, Reverb, Horizon, queue-mail and scheduler containers. For local
PHP/config changes, use `make dev-refresh-php` to clear framework artifacts and
restart the PHP consumers; Vite HMR alone does not reload Horizon or Reverb.

```bash
# Tail logs
docker compose -f docker-compose.prod.yml logs -f api
docker compose -f docker-compose.prod.yml logs -f nginx

# Tinker shell
docker compose -f docker-compose.prod.yml exec api php artisan tinker

# Queue status / restart — Horizon supervises every lane (high, default,
# broadcasts, mail, automation, bulk). Terminate workers gracefully, then restart the
# container; `horizon:terminate` alone leaves the container without a master.
docker compose -f docker-compose.prod.yml exec horizon php artisan horizon:status
docker compose -f docker-compose.prod.yml exec horizon php artisan horizon:terminate
docker compose -f docker-compose.prod.yml restart horizon

# Redis memory policy and cache/queue isolation
docker compose -f docker-compose.prod.yml exec redis redis-cli INFO memory
docker compose -f docker-compose.prod.yml exec redis-cache redis-cli INFO memory
docker compose -f docker-compose.prod.yml exec redis redis-cli CONFIG GET maxmemory maxmemory-policy
docker compose -f docker-compose.prod.yml exec redis-cache redis-cli CONFIG GET maxmemory maxmemory-policy

# Lease invariant, asserted by QueueLaneAssignmentTest: each supervisor's
# retry_after must exceed its OWN timeout (a job reserved for less time than it
# may run gets handed to a second worker mid-flight), and the connection-wide
# floor must exceed the longest timeout — bulk runs up to 3600s, so 3900s.
docker compose -f docker-compose.prod.yml exec api php artisan config:show horizon.defaults
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
docker compose -f docker-compose.prod.yml logs --tail=200 horizon scheduler

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

1. SSH into the VPS:
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
| Payroll GL classification uses the configured direct-labor department codes: production wages post to direct labor (`5020`), while operating salary/overtime/13th-month expense uses `6010/6015/6020`. Legacy `5050/5060/5070` remain in the COA for historical entries only; migration `0496` updates the defaults. | Verify the direct-labor department-code setting and the payroll GL regression before first deploy. |
| HashIDs `HASHIDS_SALT` MUST stay constant after first deploy. Changing it invalidates every URL/foreign reference in the wild. | Treat the value like an APP_KEY — back it up out-of-band. |
| Reverb WebSocket connections require `wss://` with `connect-src` allowing it in CSP. Already configured in `prod.conf`. | If clients can't connect, check the browser console for CSP violations. |

**Acceptance:** open `https://ogamiph.dev/login` in a browser, sign in
as the seeded admin user, navigate Accounting → Trial Balance, see the
Sprint 3 payroll JE reflected, click Print → PDF downloads. Defense ready.
