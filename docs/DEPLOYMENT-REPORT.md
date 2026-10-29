# Ogami ERP — Production Deployment Handoff Report

**Prepared:** 2026-10-29 (Asia/Manila)
**For:** Codex / next operator
**Server:** AWS EC2 `52.221.206.245` (ap-southeast-1b, Ubuntu 26.04.1 LTS, t3.xlarge, 16 GB RAM)
**Domain:** `https://ogamiph.dev` (Cloudflare proxy in front of this server)
**Repo:** `kwat0g/kwatog`, branch `main`, commit `e0942681`
**Deploy path:** `/opt/ogami-erp`

---

## 1. Executive summary

The full production stack is deployed and running on the Singapore VPS:

- Nginx serves the built React SPA over HTTPS and proxies `/api`, `/sanctum`, and `/app` (Reverb WebSocket).
- Laravel 12 API, PostgreSQL 16, Redis 7, Reverb, Horizon, scheduler, and mail worker are all in Docker.
- 826 migrations applied; no pending migrations.
- Seeder completed; `admin@ogami.test` exists with role `system_admin`.
- SMTP TLS + AUTH verified against Amazon SES Mail Manager (`235 Authentication successful`).
- Reverb WebSocket handshake verified internally (`101`) and through Cloudflare from a real browser (`open`).
- Horizon processes queued broadcast events successfully; zero failed jobs.
- Scheduler has 71 scheduled commands and reports healthy.
- Database and uploads backups are installed as daily cron jobs and were test-run successfully.
- Cloudflare **Full (strict)** now accepts the origin because a **Cloudflare Origin CA certificate** (valid to 2041) was installed.
- Browser end-to-end test passed: landing page → sign-in → `/dashboard/admin` with live API calls all returning 200 through Cloudflare.

Two caveats remain: the ERP is using test/placeholder company identity values (TIN, phone, CoC manager), and the admin mailbox `admin@ogami.test` is not a real deliverable address. See §8.

---

## 2. Access and operations

### SSH

```powershell
ssh -i C:\Users\Administrator\Desktop\kwatog\ogami.pem ubuntu@52.221.206.245
```

- Password for `ubuntu`: `kwatog` (password SSH login is enabled).
- `ubuntu` has passwordless sudo.
- Git is cloned to `/opt/ogami-erp`.
- Old Sydney server `3.25.115.35` is unreachable (SSH timeout) and was not used.

### Application login

- URL: `https://ogamiph.dev/sign-in`
- Email: `admin@ogami.test`
- Password: `password1234`
- Role: `system_admin`
- `must_change_password=false`

> The requested password `"password"` was rejected by `AdminUserSeeder`, which throws when the password is shorter than 12 characters. `password1234` was used instead.

### Common commands

```bash
cd /opt/ogami-erp
sudo docker compose -f docker-compose.prod.yml ps
sudo docker compose -f docker-compose.prod.yml logs -f api
sudo docker compose -f docker-compose.prod.yml exec api php artisan about
sudo docker compose -f docker-compose.prod.yml exec api php artisan migrate:status --pending
sudo docker compose -f docker-compose.prod.yml exec api php artisan queue:failed
sudo docker compose -f docker-compose.prod.yml exec api php artisan scheduler:health --stale-minutes=15 --no-interaction
```

### Rebuilding after code changes

**Important:** every PHP service has its own image (`ogami-erp-api`, `ogami-erp-horizon`, `ogami-erp-scheduler`, `ogami-erp-queue-mail`, `ogami-erp-reverb`, `ogami-erp-migrate`). Rebuilding only `api` leaves the scheduler/workers on stale code. Always rebuild all of them:

```bash
cd /opt/ogami-erp
sudo docker compose -f docker-compose.prod.yml build api migrate reverb horizon scheduler queue-mail
sudo docker compose -f docker-compose.prod.yml up -d --force-recreate migrate api reverb horizon scheduler queue-mail
```

---

## 3. Running stack

`sudo docker compose -f docker-compose.prod.yml ps` at handoff:

| Container | Image | State |
|---|---|---|
| `ogami-db` | `postgres:16-alpine` | running, healthy |
| `ogami-redis` | `redis:7-alpine` | running, healthy |
| `ogami-api` | `ogami-erp-api` | running |
| `ogami-nginx` | `nginx:1.27-alpine` | running, publishes `80` and `443` |
| `ogami-reverb` | `ogami-erp-reverb` | running, internal port 8080 |
| `ogami-horizon` | `ogami-erp-horizon` | running, healthy |
| `ogami-scheduler` | `ogami-erp-scheduler` | running, healthy |
| `ogami-queue-mail` | `ogami-erp-queue-mail` | running (`queue:work --queue=mail`) |
| `ogami-migrate` | `ogami-erp-migrate` | exited 0 |

All long-running services have `restart: unless-stopped`. Docker is enabled at boot. Only Nginx publishes host ports; Postgres and Redis are internal-only.

Volumes: `ogami-erp_pgdata`, `ogami-erp_redisdata`, `ogami-erp_appstorage`.

---

## 4. Configuration

### `/opt/ogami-erp/.env`

- Mode `600`, owner `ubuntu:ubuntu`.
- Written from `.env.production.example` with fresh secrets.
- Docker Compose reads it with `format: raw`; the SMTP password containing `, = # ( )` was verified byte-for-byte by SHA-256.

Key values:

```dotenv
APP_NAME=Ogami ERP
APP_ENV=production
APP_DEBUG=false
APP_URL=https://ogamiph.dev
APP_TIMEZONE=Asia/Manila

ADMIN_NAME=System Administrator
ADMIN_EMAIL=admin@ogami.test
ADMIN_PASSWORD=password1234

FRONTEND_URL=https://ogamiph.dev
SERVER_NAME=ogamiph.dev
SANCTUM_STATEFUL_DOMAINS=ogamiph.dev,www.ogamiph.dev
SESSION_DOMAIN=ogamiph.dev
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
SESSION_DRIVER=database

DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=ogami
DB_USERNAME=ogami

REDIS_HOST=redis
REDIS_PORT=6379
REDIS_CLIENT=phpredis
CACHE_STORE=redis
QUEUE_CONNECTION=redis
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=ogami
REVERB_HOST=ogamiph.dev
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=reverb
REVERB_SERVER_PORT=8080
REVERB_SERVER_SCHEME=http
REVERB_ALLOWED_ORIGINS=https://ogamiph.dev,https://www.ogamiph.dev

MAIL_MAILER=smtp
MAIL_HOST=twvfmgma6ztt.uemp.mail-manager-smtp.amazonaws.com
MAIL_PORT=587
MAIL_SCHEME=smtp
MAIL_FROM_ADDRESS=donotreply@ogamiph.dev
MAIL_FROM_NAME=Ogami Philippines
```

`REVERB_SERVER_*` is required: server-side broadcasting must push to the internal `reverb:8080` service, not to the public domain. Pushing to `https://ogamiph.dev` hits Cloudflare and was failing with a Cloudflare challenge HTML page (`BroadcastException`).

Secrets (`APP_KEY`, `DB_PASSWORD`, `HASHIDS_SALT`, `REVERB_APP_KEY/SECRET`, `HEALTH_DETAIL_TOKEN`, `BACKUP_RECOVERY_SECRET`, SMTP password) are only in `/opt/ogami-erp/.env` and must be backed up securely. They are intentionally not reproduced here.

### Company identity in DB/settings

- `company.legal_name`: `Philippine Ogami Corporation`
- `company.address`: `Block 2, Lots 1 & 2, First Cavite Industrial Estate (FCIE), Barangay Langkaan 1, Dasmariñas, Cavite, Philippines` (correct UTF-8 `ñ`, `c3 b1`)
- `company.tin`: **`Not Configured`** (placeholder — must be corrected)
- `company.vat_status`: **`VAT Registered`** (placeholder — must be confirmed)
- `company.certification`: `IATF 16949`
- `company.email`: `donotreply@ogamiph.dev`
- `company.public_url`: `https://ogamiph.dev`
- `company.phone`: empty
- `quality.coc.manager_name` / `quality.coc.manager_role`: empty

### Critical alert recipients

`alerts.critical.notification_roles` was seeded without `scheduler_stale` and several newer alert types. Those mappings were added:

```text
scheduler_stale        -> system_admin
chain_bottleneck       -> production_manager, ppc_head
mrp_shortage           -> ppc_head, purchasing_officer
mrp_schedule_conflict  -> ppc_head, production_manager
mrp_run_failed         -> ppc_head, system_admin
mrp_data_error         -> ppc_head, system_admin
replenishment_blocked  -> purchasing_officer, ppc_head
```

Existing seeded mappings were preserved.

> `admin@ogami.test` is a `.test` address and is not deliverable. Critical-alert emails will attempt delivery but cannot reach a real mailbox until a real admin email is configured.

---

## 5. TLS / Cloudflare

### Current setup

- Cloudflare proxies `ogamiph.dev` and `www.ogamiph.dev` to `52.221.206.245`.
- Origin certificate: **Cloudflare Origin CA**, SAN `*.ogamiph.dev` + `ogamiph.dev`.
- Valid: `2026-10-29` → `2041-10-25`.
- Installed on the host at:
  - `/etc/letsencrypt/live/ogamiph.dev/fullchain.pem`
  - `/etc/letsencrypt/live/ogamiph.dev/privkey.pem` (mode `600`)
- Nginx mounts `/etc/letsencrypt` read-only and reads `${SERVER_NAME}` paths from `docker/nginx/prod.conf`.
- Previous temporary self-signed files are preserved as:
  - `fullchain.selfsigned.pem`
  - `privkey.selfsigned.pem`
- Cloudflare SSL mode is **Full (strict)** and now accepts the origin.

### Why not Let's Encrypt

Cloudflare has "Always Use HTTPS" active: an HTTP request to `/.well-known/acme-challenge/...` receives a Cloudflare `301` to HTTPS, and HTTPS to the origin returned `526` while the self-signed cert was installed. HTTP-01 could not complete. DNS-01 would require Cloudflare API credentials. The user supplied a Cloudflare Origin CA cert/key instead, which is a supported production setup behind Cloudflare.

### Cloudflare behavior observed

- Direct server access: `http://52.221.206.245/` → `301 https://ogamiph.dev/`; `https://52.221.206.245/` → 200 (self-signed/local CA warning expected).
- Through Cloudflare with a real browser: landing page, sign-in, dashboard, API, and WebSocket all work.
- `curl` from the public internet gets Cloudflare `403` with `Cf-Mitigated: challenge`. This is Cloudflare's bot challenge, not the ERP. If API clients, webhooks, or monitoring need to call the public API with curl, add a Cloudflare WAF skip rule for the relevant paths or disable Bot Fight Mode/Under Attack for API traffic.

### Nginx warning

`nginx -t` reports `ssl_stapling` ignored because the Cloudflare Origin CA issuer cert is not in `fullchain.pem`. This is harmless for Cloudflare Full (strict); OCSP stapling does not apply to Origin CA certs. Leave as-is unless the repo's Nginx template is changed to remove `ssl_stapling`.

---

## 6. What was verified

All checks below were run on 2026-10-29 and passed unless noted.

| Area | Check | Result |
|---|---|---|
| Containers | All eight long-running containers running; migrate exited 0; restart policies `unless-stopped` | PASS |
| API health | `GET /api/v1/health` → 200; detail `app=true, db=true, redis=true, queue=0` | PASS |
| HTTP redirect | `http://ogamiph.dev/` → `301 https://ogamiph.dev/` | PASS |
| SPA | `/`, `/login`, `/dashboard` → 200 with `<div id="root">`; JS asset → 200 | PASS |
| SPA config | Built bundle references `ogamiph.dev`, `/api/v1`, and the correct Reverb key | PASS |
| Sanctum CSRF | `GET /sanctum/csrf-cookie` → 204; XSRF cookie captured | PASS |
| Login | `POST /api/v1/auth/login` with `admin@ogami.test` → 200; `role.slug=system_admin` | PASS |
| Session | `GET /api/v1/auth/user` → 200; `GET /api/v1/dashboards/badges` → 200 | PASS |
| Unauthenticated guard | `GET /api/v1/auth/user` without session → 401 | PASS |
| Reverb handshake | Internal Nginx → Reverb `GET /app/<key>` → 101 | PASS |
| Reverb via Cloudflare | Browser `new WebSocket("wss://ogamiph.dev/app/<key>...")` → open | PASS |
| Broadcasting | `BadgesChanged` event processed by Horizon in 27 ms; no `BroadcastException` after `REVERB_SERVER_*` fix | PASS |
| Database | PostgreSQL 16.15; 826 migrations; `migrate:status --pending` → no pending | PASS |
| Seed data | users=1, roles=17, settings=474; admin role `system_admin`; `Dasmariñas` stored as UTF-8 | PASS |
| Redis | `PING` → `PONG` | PASS |
| Horizon | `horizon:status` → running; queue failed jobs = 0 | PASS |
| Scheduler | 71 scheduled commands; `scheduler:health --stale-minutes=15` → OK | PASS |
| Mail config | `mailer=smtp`, host/port/from match the CSV; `queue-mail` logs no errors | PASS |
| SMTP auth | `openssl s_client -starttls smtp` + `AUTH LOGIN` → `235 Authentication successful` | PASS |
| DB backup | `/var/backups/ogami/ogami-20261029-133214.sql.gz` (166 KB), `gzip -t` OK, PostgreSQL dump header | PASS |
| Files backup | `/var/backups/ogami/files/ogami-files-20261029-140202.tar.gz` (4 KB), `tar -tzf` OK | PASS |
| Backups cron | `/etc/cron.daily/ogami-pgdump`, `/etc/cron.daily/ogami-files` executable | INSTALLED |
| Error logs | No fatal/uncaught/SQLSTATE lines after stabilization | PASS |
| Host | `.env` mode 600; 85 GB free; Docker enabled at boot; UFW inactive | OBSERVED |

Browser end-to-end evidence (real Chromium through Cloudflare):

- Landing page loaded as `Ogami ERP` / `Philippine Ogami Corporation`.
- Sign-in succeeded and navigated to `https://ogamiph.dev/dashboard/admin`.
- Dashboard title: `System Administrator · Dashboard · ERP`.
- API calls from the page returned 200: `/auth/user`, `/dashboards/badges`, `/dashboards/admin`, `/notifications`, `/broadcasting/auth`, `/business-policies`, `/dashboard/dispatch`, `/attendance/punch/status`.
- WebSocket to `wss://ogamiph.dev/app/<key>` opened successfully.

---

## 7. Issues found and fixes applied

### 7.1 Bring-up order: seeding before the API existed

`_bringup.sh` ran `db:seed` before starting the `api` container. Seeding requires the API container. Fix: start `api nginx reverb horizon scheduler queue-mail` first, then run:

```bash
sudo docker compose -f docker-compose.prod.yml exec -T api php artisan db:seed --force
sudo docker compose -f docker-compose.prod.yml exec -T api php artisan storage:link
```

Seeder completed successfully with exit 0.

### 7.2 Reverb server-side push hit Cloudflare

`REVERB_HOST=ogamiph.dev` / `REVERB_PORT=443` made the API's broadcaster POST to the public domain. Cloudflare returned its "Just a moment..." challenge HTML, producing `Illuminate\Broadcasting\BroadcastException` on `BadgesChanged`. Fix: add internal server-side settings:

```dotenv
REVERB_SERVER_HOST=reverb
REVERB_SERVER_PORT=8080
REVERB_SERVER_SCHEME=http
```

`api/config/broadcasting.php` already supports these keys. After recreating services, the event processed in 27 ms with zero failed jobs.

### 7.3 `ñ` corrupted to `??`

The Windows → SSH pipeline converted `Dasmariñas` to `Dasmari??as` in `.env` and in the seeded setting. Fix:

- Rewrote the `COMPANY_ADDRESS` line in `/opt/ogami-erp/.env` using explicit UTF-8 (`chr(0xF1)`), verified bytes `c3 b1`.
- Updated `company.address` through `App\Common\Services\SettingsService::set()` so JSON/cache were handled correctly.
- Verified DB hex ends in `...4461736d617269c3b16173...`.

### 7.4 Merge regression: `RecommendationService::expirePastValidity()` missing

`App\Console\Commands\MaintainDss` calls `$recommendations->expirePastValidity()`, but the method is absent at HEAD `e0942681` (it existed in commit `ace82c17`, then was lost during a merge). Every scheduled `dss:maintain` run failed with:

```text
Call to undefined method App\Modules\DSS\Services\RecommendationService::expirePastValidity()
```

The exact method was restored from git history and inserted before `expireForPause()`:

```php
public function expirePastValidity(): int
{
    return $this->expireMatching(
        DssRecommendation::query()->whereNotNull('expires_at'),
        static fn (DssRecommendation $recommendation): bool => $recommendation->expires_at !== null && $recommendation->expires_at->lte(now()),
        'Recommendation validity window ended.',
        ['source' => 'scheduled_expiry'],
    );
}
```

File: `/opt/ogami-erp/api/app/Modules/DSS/Services/RecommendationService.php`.

This is an **uncommitted local change** and the only intended source modification in the deployment checkout. `git status` should show only this file. A future `git pull`/fresh clone from `main` will reintroduce the bug unless the upstream repo is fixed. Re-apply the method (or cherry-pick the fix) before rebuilding. A local patch copy is recommended; see §9.

### 7.5 Only the API image was rebuilt (scheduler kept old code)

After adding `expirePastValidity()`, `api` was rebuilt but `scheduler`, `horizon`, `queue-mail`, `reverb`, and `migrate` still used their pre-fix images. Scheduled `dss:maintain` kept failing at 21:55 even though manual runs inside `ogami-api` succeeded. Fix: rebuild **all** PHP service images and force-recreate them. After that, manual `dss:maintain` inside `ogami-scheduler` returned success.

### 7.6 Missing critical-alert role mappings

The seeded `alerts.critical.notification_roles` catalog lacked `scheduler_stale` and MRP/chain/replenishment alert types. A scheduler failure could not email anyone and logged:

```text
No critical-alert recipient roles are configured for scheduler_stale.
```

Fix: added the mappings listed in §4 via `SettingsService::set()`. `alerts:run` then resolved the stale scheduler alert; open alerts dropped to 0.

### 7.7 Accidental zero-byte `AlertType.php`

During inspection, `api/app/Common/Enums/AlertType.php` was transiently truncated to 0 bytes. It was restored with:

```bash
git checkout -- api/app/Common/Enums/AlertType.php
```

Current file is 4125 bytes, intact. `git status` after restore shows only `RecommendationService.php` modified.

### 7.8 Cloudflare 526 → Origin CA install

Cloudflare Full (strict) returned `526 Invalid SSL certificate` against the temporary self-signed cert. The supplied Cloudflare Origin CA cert/key were validated (`openssl x509`, `openssl pkey -check`, public-key SHA-256 match) and installed into the Nginx certificate paths, then Nginx reloaded. Cloudflare 526 disappeared; the remaining 403 is Cloudflare's bot challenge, and real browsers pass.

### 7.9 Backups

Installed and test-ran:

- `/etc/cron.daily/ogami-pgdump` — DB dump via `scripts/db-backup-cron.sh`, host archive `/var/backups/ogami/`, log `/var/log/ogami-backup.log`, retention 30.
- `/etc/cron.daily/ogami-files` — uploads archive via `scripts/files-backup.sh`, source `/var/lib/docker/volumes/ogami-erp_appstorage/_data`, destination `/var/backups/ogami/files/`, retention 30, log `/var/log/ogami-files-backup.log`.

A test DB backup (166 KB) and files backup (4 KB) both passed integrity checks. The files test archive still contains the manual `queue-proof.txt` test marker; that marker was removed from the volume afterward, so the next archive will be clean.

### 7.10 Transient Nginx DNS errors during recreates

Nginx logged `api could not be resolved` / `reverb could not be resolved` while containers were being force-recreated. These were transient and stopped once services were up. If they recur on every deploy, add `resolver` / upstream changes or use static upstreams; not a current functional issue.

---

## 8. Outstanding items / risks

1. **Company legal data is placeholder.** `COMPANY_TIN=Not Configured`, `COMPANY_VAT_STATUS=VAT Registered`, `COMPANY_PHONE` empty, `QUALITY_COC_MANAGER_NAME/ROLE` empty. Correct these in `/opt/ogami-erp/.env` and the `settings` table (or via Admin → Settings) before using invoices, BIR reports, or quality certificates.
2. **Admin mailbox is fake.** `admin@ogami.test` uses the reserved `.test` TLD. Critical-alert emails and password resets cannot be delivered. Create a real admin user/mailbox and update `ADMIN_EMAIL`/the user record.
3. **Admin password is `password1234`, not `"password"`.** The seeder enforces ≥12 characters. Change it through the UI if a different password is required.
4. **No live email delivery test.** SMTP TLS + `AUTH LOGIN` succeeded (`235`), but no message was sent to a real recipient. Send a test password-reset or use a real mailbox to validate end-to-end delivery.
5. **Cloudflare bot challenge.** Curl/webhook/API clients receive `403 Cf-Mitigated: challenge`. Browser SPA works. Add a WAF skip rule or adjust Bot Fight Mode if external API clients need access.
6. **Uncommitted source patch.** `RecommendationService::expirePastValidity()` exists only in `/opt/ogami-erp` working tree. A fresh deploy from `main` will regress `dss:maintain`. Fix upstream or re-apply the patch.
7. **Scheduled `dss:maintain` after final rebuild was not observed.** Manual runs in both `ogami-api` and `ogami-scheduler` succeeded after the full rebuild, and `scheduler:health` reported OK at 22:03 PHT. The 22:05 PHT scheduled tick was not confirmed because the wait command was interrupted. Codex should verify:
   ```bash
   sudo docker compose -f docker-compose.prod.yml exec -T db psql -U ogami -d ogami \
     -c "select status, started_at, last_error from scheduler_task_runs where task_name like '%dss:maintain%' order by started_at desc limit 5;"
   ```
   Also note: `scheduler_task_runs` showed duplicate rows at identical timestamps (one `failed`, one `succeeded`) for `dss:maintain` around 21:45–21:55, likely two scheduler instances overlapping during container recreates. Check whether duplicates persist after stabilization.
8. **No off-site backup.** DB and uploads archives are local under `/var/backups/ogami`. `BACKUP_S3_BUCKET` is unset. Configure S3/off-site replication for disaster recovery.
9. **`.env` is not in backups.** Secrets are only on the server. Store an encrypted copy in a secure secret manager.
10. **Redis persistence.** The `ogami-erp_redisdata` volume exists, but Redis is used for cache/queue; a Redis loss would drop queued jobs, not committed DB data. Acceptable for most ERP workloads; use Horizon retries/alerts as configured.
11. **Cloudflare Origin CA cert expires 2041-10-25.** No renewal automation is needed now. If the cert is replaced, update both files and reload Nginx.
12. **UFW is inactive.** AWS security group is the effective firewall; ports 80/443 are open. If UFW is enabled later, allow 80/443 and Docker's forwarding rules carefully.

---

## 9. Files and artifacts

### Server

| Path | Purpose |
|---|---|
| `/opt/ogami-erp` | Production checkout at `e0942681` |
| `/opt/ogami-erp/.env` | Production secrets/config, mode 600 |
| `/opt/ogami-erp/spa/dist` | Built SPA mounted into Nginx |
| `/opt/ogami-erp/api/app/Modules/DSS/Services/RecommendationService.php` | Locally patched with `expirePastValidity()` |
| `/etc/letsencrypt/live/ogamiph.dev/fullchain.pem` | Cloudflare Origin CA cert |
| `/etc/letsencrypt/live/ogamiph.dev/privkey.pem` | Origin private key, mode 600 |
| `/etc/letsencrypt/live/ogamiph.dev/*.selfsigned.pem` | Preserved temporary self-signed cert |
| `/var/backups/ogami/` | DB dumps |
| `/var/backups/ogami/files/` | Upload archives |
| `/var/log/ogami-backup.log` | DB backup log |
| `/var/log/ogami-files-backup.log` | Files backup log |
| `/etc/cron.daily/ogami-pgdump` | DB backup cron |
| `/etc/cron.daily/ogami-files` | Files backup cron |

### Local workspace (`C:\Users\Administrator\Desktop\kwatog`)

| File | Notes |
|---|---|
| `ogami.pem` | SSH private key for both servers (sensitive) |
| `smtp-credentials.csv` | SMTP endpoint/user/password (sensitive) |
| `cloudflare-origin.crt` | Cloudflare Origin CA cert copy |
| `cloudflare-origin.key` | Origin private key copy (sensitive) |
| `DEPLOYMENT-REPORT.md` | This report |
| `_bringup.sh` | Original bring-up script; note its seed-before-api ordering issue |
| `_verify.sh` | First verification pass |
| `_verify2.sh` | DB/queue/scheduler/mail checks |
| `_finalverify.sh` | Final full verification (containers, health, SPA, auth, WS, DB, queue, scheduler, mail, logs, host) |
| `_fixreverb.sh` | Adds `REVERB_SERVER_*` and recreates services |
| `_fixdss3.sh` | Restores/patches `expirePastValidity`, fixes `.env` address, rebuilds |
| `_rebuildall.sh` | Rebuilds all PHP service images and force-recreates |
| `_installcert.sh` | Installs Cloudflare Origin cert and reloads Nginx |
| `_filesbackup2.sh` | Tests files backup and installs `/etc/cron.daily/ogami-files` |
| `_waitdss.sh` | Waits for the next scheduler tick (was interrupted) |
| `_smtpcheck.sh` | SMTP TLS + AUTH test |
| `_postfixverify.sh`, `_finalsched.sh`, `_dssruns.sh` | Post-fix scheduler/DB probes |

> The private key copies are sensitive. Protect or delete them once no longer needed.

---

## 10. Quick recovery / redeploy sequence

If rebuilding from scratch or after a reboot:

```bash
cd /opt/ogami-erp

# 1. Confirm secrets/config
test -f .env && stat -c '%a %U:%G %n' .env

# 2. Bring up data services
sudo docker compose -f docker-compose.prod.yml up -d db redis

# 3. Run migrations (one-shot)
sudo docker compose -f docker-compose.prod.yml up migrate

# 4. Start app services BEFORE seeding
sudo docker compose -f docker-compose.prod.yml up -d api nginx reverb horizon scheduler queue-mail

# 5. Seed only on a fresh database
sudo docker compose -f docker-compose.prod.yml exec -T api php artisan db:seed --force
sudo docker compose -f docker-compose.prod.yml exec -T api php artisan storage:link

# 6. Verify
bash /tmp/finalverify.sh   # or re-upload _finalverify.sh
```

If the repo is re-cloned from `main`, re-apply the `expirePastValidity()` method in `api/app/Modules/DSS/Services/RecommendationService.php` before rebuilding.

---

## 11. Change log (server-side)

1. Installed Docker 29.1.3 + Compose 2.40.3.
2. Cloned repo to `/opt/ogami-erp` at `e0942681`.
3. Generated `.env` with fresh secrets; verified raw SMTP password handling.
4. Built PHP production images and SPA `dist`.
5. Created `/etc/letsencrypt/live/ogamiph.dev` with temporary self-signed cert; installed certbot 4.0.0 (not used for the final Origin CA cert).
6. Ran 826 migrations.
7. Started API/Nginx/Reverb/Horizon/Scheduler/Mail worker.
8. Seeded all data; created admin; linked storage.
9. Added `REVERB_SERVER_*` and recreated services; fixed broadcast path.
10. Fixed `company.address` UTF-8 in `.env` and DB.
11. Restored `expirePastValidity()`, rebuilt all PHP images, force-recreated services.
12. Installed Cloudflare Origin CA certificate and reloaded Nginx; Cloudflare 526 resolved.
13. Added missing critical-alert role mappings; resolved stale scheduler alert.
14. Installed and tested DB + files backup cron jobs.
15. Ran full verification and public browser E2E test (landing → login → dashboard, API 200, WSS open).

---

*End of report.*
