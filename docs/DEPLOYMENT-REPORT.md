# Ogami ERP — Production Deployment Handoff Report

**Prepared:** 2026-10-29; updated after the authorized demo seed and PHP security review
**For:** Codex / next operator
**Server:** AWS EC2, ap-southeast-1, Ubuntu 26.04.1 LTS, t3.xlarge, 16 GB RAM (public IP held outside this repository)
**Domain:** `https://ogamiph.dev` (Cloudflare proxy in front of this server)
**Repo:** kwat0g/kwatog; report originally described e0942681. The checkout inspected for this update is main at 87ed280b with uncommitted changes.
**Deploy path:** /opt/ogami-erp (current host uses an in-place checkout; the GitHub Actions workflow expects a releases/shared/current layout)

**Source update (2026-11-04):** This report records the production handoff as it
stood on 2026-10-29. Delta 867 has since corrected the source-level deploy
ordering, framework-cache generation, OPcache restart, and `queue-mail`
rebuild/restart/smoke-check gaps in `Makefile`, `scripts/deploy-update.sh`, and
the GitHub workflow. Those changes have not been deployed to production; use
the current procedure in [DEPLOY.md](DEPLOY.md), and do not treat this source
change as proof that production has received it.

---

## 1. Executive summary

The production stack is deployed with the production Compose file: Nginx serves
the built React SPA over HTTPS; Laravel 12, PostgreSQL 16, Redis 7, Reverb,
Horizon, the scheduler, and the mail worker run in Docker. At the time of this
update, all eight long-running containers were up, API health returned OK,
scheduler health passed, and there were no failed jobs.

The full reference and thesis-demo fixture set was seeded on 2026-10-29 after
creating a pre-seed database and files backup. The database now contains 20
users, 200 employees, 15 departments, 5 customers, 8 products, 12 inventory
items, 8 BOMs, 12 sales orders, 12 MRP plans, 12 work orders, 6 purchase
requests, 1 RFQ with 2 supplier quotes, 2 purchase orders, 3 invoices, 7
payroll periods, 1,070 payroll rows, 3 inspections, 150 inspection
measurements, 10 item quality plans, 5 budgets, and 2 shipments. There are 474
settings. The only empty settings found are company.logo_path, company.phone,
company.tagline, pdf.terms_conditions, quality.coc.manager_name, and
quality.coc.manager_role. The company TIN is a placeholder and the VAT
registration status needs confirmation.

Every running PHP service was checked at PHP 8.3.36. This is the PHP 8.3 fix
release for CVE-2026-109618 and CVE-2026-109619. A PHP minor-version upgrade is
not required to address those CVEs; maintain the current branch at its newest
security patch and schedule a staged move before PHP 8.3 security support ends
on 2027-12-31. The repository's development and production Dockerfiles now pin
`php:8.3.36-fpm-alpine3.24`; the live runtime already had this PHP patch, so no
container restart was needed for the pinning change.

The report's credential values have been removed from this current copy. They
were previously committed to a public repository, so all affected credentials
must still be rotated and Git history must be cleaned separately. Editing this
file does not revoke old credentials or erase previous commits.

The deployment is not yet fully reproducible from a clean checkout: demo
seeding has a production-image factory failure, route operations are removed
by a later cascading truncate unless re-seeded, deployment automation omits
queue-mail from its restart/smoke checks, alert mappings include manual DB
changes, and the DSS fix is uncommitted. See sections 7 and 8.

---

## 2. Access and operations

### SSH

Use the operator's managed SSH key and the non-root deploy account. At the time
of audit, effective sshd configuration still allowed password authentication,
and the account had passwordless sudo. The report previously disclosed an SSH
password; treat it as compromised, rotate it, disable password and root SSH,
verify key-only login in a second session, and restrict the security-group SSH
source to the operator's fixed address.

### Application login

The demo administrator account is admin@ogami.test with the system_admin role.
It is not a deliverable mailbox. DemoAccountSeeder resets this account to the
shared fixture password, marks it active, and does not require a password
change. Do not use or publish its seeded credential; rotate it before opening
the demo to anyone else. The bootstrap administrator should use a unique real
email that is not one of the demo fixture accounts.

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
SEED_DEMO_DATA=false
SEED_REFERENCE_DATA=false
APP_URL=https://ogamiph.dev
APP_TIMEZONE=Asia/Manila

ADMIN_NAME=System Administrator
ADMIN_EMAIL=<real unique administrator email>
# ADMIN_PASSWORD is stored only in the protected host .env or secret manager.

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

- Cloudflare proxies `ogamiph.dev` and `www.ogamiph.dev` to `[origin IP omitted]`.
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

- Direct server access: `http://[origin IP omitted]/` → `301 https://ogamiph.dev/`; `https://[origin IP omitted]/` → 200 (self-signed/local CA warning expected).
- Through Cloudflare with a real browser: landing page, sign-in, dashboard, API, and WebSocket all work.
- `curl` from the public internet gets Cloudflare `403` with `Cf-Mitigated: challenge`. This is Cloudflare's bot challenge, not the ERP. If API clients, webhooks, or monitoring need to call the public API with curl, add a Cloudflare WAF skip rule for the relevant paths or disable Bot Fight Mode/Under Attack for API traffic.

### Nginx warning

`nginx -t` reports `ssl_stapling` ignored because the Cloudflare Origin CA issuer cert is not in `fullchain.pem`. This is harmless for Cloudflare Full (strict); OCSP stapling does not apply to Origin CA certs. Leave as-is unless the repo's Nginx template is changed to remove `ssl_stapling`.

---

## 6. What was verified

Checks below were run on 2026-10-29. Authentication and browser E2E rows are
from before the demo seeding changed fixture-account credentials; recheck
login with the newly rotated accounts before opening the demo publicly.

| Area | Check | Result |
|---|---|---|
| Containers | All eight long-running containers running; migrate exited 0; restart policies `unless-stopped` | PASS |
| App runtime | `APP_ENV=production`, `APP_DEBUG=false`, HTTPS app URL, Redis queue | PASS |
| API health | `GET /api/v1/health` → 200; detail `app=true, db=true, redis=true, queue=0` | PASS |
| Cloudflare app requests | Current public `GET /api/v1/health` → 403 with `cf-mitigated: challenge`; direct-origin API → 200 in 57–73 ms and `/` → 200 in 6–8 ms; screenshot shows repeated challenge Ray IDs | FAIL; identify and correct the specific Cloudflare challenge rule |
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
| PHP runtime | API, Horizon, scheduler, queue-mail, and Reverb report PHP 8.3.36 | PASS; fixes CVE-2026-109618 and CVE-2026-109619 |
| Seed data after demo run | users=20, employees=200, departments=15, products=8, settings=474; 12 work orders; 1 RFQ + 2 supplier quotes | PASS, with seed caveats in §8 |
| Redis | `PING` → `PONG` | PASS |
| Horizon | `horizon:status` → running; queue failed jobs = 0 | PASS |
| Scheduler | 71 scheduled commands; `scheduler:health --stale-minutes=15` → OK | PASS |
| Mail config | `mailer=smtp`, host/port/from match the CSV; `queue-mail` logs no errors | PASS |
| SMTP auth | `openssl s_client -starttls smtp` + `AUTH LOGIN` → `235 Authentication successful` | PASS |
| SMTP send | Laravel sent one test message through the configured production SMTP relay on 2026-10-29; no transport error | SMTP accepted; recipient inbox not checked |
| Career interview email | Candidate stage event was recorded; `InterviewScheduledMail` failed on its first attempt and completed on retry; no permanent failed jobs in the last 24 hours | Queue processed; candidate reports no message received |
| Sender DNS | Cloudflare shows three SES DKIM CNAME records and DMARC `p=none`; no root SPF TXT record; SES identity/DKIM verification and custom MAIL FROM configuration not checked | CHECK SES identity and MAIL FROM settings; publish only the provider's required records |
| DB backup | `/var/backups/ogami/ogami-20261029-133214.sql.gz` (166 KB), `gzip -t` OK, PostgreSQL dump header | PASS |
| Pre-seed backup | `/var/backups/ogami/ogami-20261029-151028.sql.gz` (199,077 bytes), mode 600 | PASS, same host only |
| Pre-seed files archive | `/var/backups/ogami/files/ogami-files-20261029-151028.tar.gz` (139 bytes), mode 600 | Created; contains little/no upload data |
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

`App\Console\Commands\MaintainDss` calls `$recommendations->expirePastValidity()`. The method was absent at the original handoff commit e0942681 and remains absent from the current committed HEAD 87ed280b; it exists only in the uncommitted working tree. Every scheduled `dss:maintain` run failed with:

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

This remains an uncommitted local change. The current checkout also contains uncommitted Nginx and SPA changes, so inspect and commit only reviewed work before any release. A future deploy from GitHub will not include this DSS method until the fix is committed. See §8.

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

The file was restored and verified intact during the original deployment. The current working tree has additional uncommitted edits; see §8.

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

1. **Credential exposure is critical.** The previous public report contained
   SSH and administrator credentials. At audit, sshd still allowed password
   authentication and the deploy account had passwordless sudo. Rotate SSH,
   application, SMTP, and any other exposed credentials; disable SSH password
   login; verify key-only access; restrict the AWS security group; and remove
   the leaked values from public Git history. The current document has had the
   values removed, but old commits remain exposed.
2. **Demo passwords are shared and active.** The demo-account seeder writes a
   hard-coded shared password and sets must_change_password=false. The
   admin@ogami.test fixture is overwritten by this seeder. Change all demo
   passwords and use a separate real bootstrap admin before public access.
3. **Cloudflare blocks application API traffic with challenges.** A live
   public `GET /api/v1/health` returns 403 with `cf-mitigated: challenge`, and
   the browser showed multiple challenge Ray IDs. The same root page served
   directly from Nginx returns 200 in 6–8 ms; host load and memory were low.
   Search the screenshot Ray IDs under Security → Events, then adjust only the
   challenge-producing rule for the affected API/auth/WebSocket paths. A WAF
   Skip rule cannot bypass free Bot Fight Mode. Separately, Nginx publishes
   ports 80/443 on all interfaces; restrict the AWS security group to
   Cloudflare IP ranges or another origin access control.
4. **Backups are same-host only.** Daily DB and file archives are stored under
   /var/backups/ogami, with no confirmed off-site copy or restore drill. The
   .env is not included in backups. Store an encrypted configuration backup
   separately and test both DB and private-file restores off-host.
5. **Fresh demo seeding is not yet one-command reproducible.**
   DemoRfqBudgetSeeder uses PurchaseRequestFactory, whose Faker helper is not
   available in the production image. The run was completed by creating its
   purchase-request/RFQ prerequisite through the real application service and
   then running the remaining demo seeders. Fix the fixture so a clean
   production image completes DatabaseSeeder without manual intervention.
6. **Routing seed order loses child rows.** ComprehensiveDemoSeeder uses
   TRUNCATE ... CASCADE after ProductRoutingSeeder; the cascade removed route
   operations. ProductRoutingSeeder was run afterward, leaving 8 active routes
   with operations and 8 inactive empty route versions. Fix the seed order or
   truncate scope, then verify on a disposable database.
7. **Deployment automation omits queue-mail.** Both scripts/deploy-update.sh
   and .github/workflows/deploy.yml omit queue-mail from their restart and/or
   health-check service lists. An old mail worker can remain after a release.
   Add it to build, stop, start, recreation, and smoke checks before relying on
   unattended deploys.
8. **The Git checkout is dirty.** The current checkout is main at 87ed280b and
   contains uncommitted edits, including RecommendationService's
   expirePastValidity() method and Nginx/SPA changes. The DSS method is absent
   from the committed checkout; a deployment from GitHub can regress
   dss:maintain. Commit and review intended changes before deploying, and pin
   releases to a reviewed commit.
9. **Alert recipient mappings are partly manual.** New alert mappings were
   inserted into this database but are not all present in migration
   0417_seed_alert_critical_notification_roles.php. A fresh database may omit
   those recipients until the migration/seeder is updated.
10. **Runbook and deployment modes previously disagreed.** The current VPS is
    an in-place checkout. GitHub Actions expects releases/shared/current. Do
    not use the workflow on the flat checkout or the in-place script on the
    atomic release layout. Update the documented canonical path when the
    migration is completed.
11. **Career email delivery is unproven.** The user's interview transition was
    recorded. The queued `InterviewScheduledMail` failed on the first attempt
    and completed on retry, but the candidate reports no email. The configured
    SMTP host is an AWS Mail Manager ingress-style endpoint; SMTP acceptance
    and queue completion do not prove the ingress rule sent it to Gmail. Check
    the Mail Manager rule set and provider delivery logs, or switch to the
    standard SES outbound SMTP endpoint with region-matched credentials and a
    verified sender identity. The Cloudflare screenshot shows three SES DKIM
    CNAMEs and DMARC `p=none`; SES identity verification and custom MAIL FROM
    settings still need checking. No root SPF TXT exists. SES uses
    `amazonses.com` for SPF by default; when using a custom MAIL FROM domain,
    publish the exact SES-provided MX and SPF records on that subdomain instead
    of guessing a root record. Check the recipient's Gmail authentication
    results after the sender route is confirmed.
    The .test administrator mailbox cannot receive password-reset or alert
    messages; configure a real administrator address.
12. **Company settings need review.** Six optional settings are empty: logo,
    phone, tagline, PDF terms, and CoC manager name/role. The TIN is a
    placeholder and VAT status is unconfirmed. Confirm these before generating
    invoices, statutory reports, or quality certificates.
13. **PHP maintenance.** All currently running PHP services are at 8.3.36,
    which fixes the critical October 2026 PHP RCE advisories. Keep the 8.3
    branch patched and plan a staged branch upgrade before security support
    ends on 2027-12-31. Rebuild every PHP service image for each security
    update; a container restart does not update its image.
14. **Nginx image is old and floating.** Compose uses nginx:1.27-alpine. Check
    the supported stable release before each update, pin a reviewed version or
    digest, and smoke-test the TLS and WebSocket routes.
15. **TLS certificate renewal is manual.** The Cloudflare Origin CA certificate
    is not managed by Certbot. Track its expiration and replace it before the
    date; keep the origin behind Cloudflare Full (strict).
16. **The files backup is nearly empty.** The pre-seed files archive was 139
    bytes. Confirm the private storage volume contains the intended demo
    documents and verify that future file archives are non-empty when uploads
    exist.

---

## 9. Files and artifacts

### Server

| Path | Purpose |
|---|---|
| `/opt/ogami-erp` | In-place production checkout; current inspected HEAD is `87ed280b`, with uncommitted changes |
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

### Local workspace

Private deployment artifacts such as SSH keys, SMTP credentials, and Origin CA
private keys must stay outside this repository. Store them in an approved
secret manager and rotate any copies that were committed or shared.

---

## 10. Recovery and first-time demo seeding

A reboot is not a reason to reseed. Start the existing services and check
health; do not run DatabaseSeeder on a populated database. Routine deploys
must run migrations only, never demo fixtures.

For a new empty thesis-demo database, first complete the source fixes listed
in §8, create a verified backup, and keep the app consumers stopped while
seeding. The only demo flags should be temporary overrides on a one-shot
container; leave both production flags false in .env. The fixture command is
in §6. Do not run it against the existing database unless you deliberately
intend its cascading transaction reset.

After a new seed, verify table counts and business flows rather than relying
only on the seeder's success message. In particular, check active routing
operations, MRP plans/work orders, the RFQ and submitted quotes, inspection
evidence, budgets, scheduler health, and failed jobs. Change all seeded demo
passwords before opening the site publicly.

For the current in-place VPS, deploy committed changes with
scripts/deploy-update.sh. That script currently omits queue-mail, so follow
the explicit worker recovery/deploy steps in docs/DEPLOY.md until the script
is corrected. For the GitHub Actions atomic layout, roll back by retargeting
current to the last known-good release; do not apply that procedure to the
flat checkout.

## 11. Change log (server-side)

1. Installed Docker 29.1.3 + Compose 2.40.3.
2. Cloned repo to `/opt/ogami-erp`; the handoff recorded e0942681, while the checkout inspected in this update is main at 87ed280b.
3. Generated `.env` with fresh secrets; verified raw SMTP password handling.
4. Built PHP production images and SPA `dist`.
5. Created `/etc/letsencrypt/live/ogamiph.dev` with temporary self-signed cert; installed certbot 4.0.0 (not used for the final Origin CA cert).
6. Ran 826 migrations.
7. Started API/Nginx/Reverb/Horizon/Scheduler/Mail worker.
8. Seeded the full reference/demo fixture set on 2026-10-29 after a pre-seed backup; linked storage. The RFQ fixture needed a deterministic prerequisite workaround, and ProductRoutingSeeder was rerun after the comprehensive cascade.
9. Added `REVERB_SERVER_*` and recreated services; fixed broadcast path.
10. Fixed `company.address` UTF-8 in `.env` and DB.
11. Restored `expirePastValidity()`, rebuilt all PHP images, force-recreated services.
12. Installed Cloudflare Origin CA certificate and reloaded Nginx; Cloudflare 526 resolved.
13. Added missing critical-alert role mappings; resolved stale scheduler alert.
14. Installed and tested DB + files backup cron jobs.
15. Rechecked the post-seed API health, scheduler health, failed jobs, and container state. PHP 8.3.36 is running in all PHP services.

---

*End of report.*
