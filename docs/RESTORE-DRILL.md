# OGAMI ERP — Backup & Restore Drill Runbook

Owner: Platform / Ops. Last reviewed: 2026-08-25 (OGAMI-018).

This runbook covers (1) how backups are produced, (2) how to restore from one,
and (3) a repeatable drill checklist to prove the backups actually work. A
backup you have never restored is not a backup — run the drill at least
quarterly.

---

## 1. How backups are produced

Three paths exist. The Laravel scheduler and admin center use the full-backup
workflow; the host and manual database paths use `scripts/db-backup.sh`
(plain `pg_dump` → `gzip`, 14-file retention, optional S3 upload):

| Path | Trigger | Output |
|------|---------|--------|
| Scheduler | `php artisan db:full-backup` daily **03:17** (`api/routes/console.php`) | `storage/app/backups/ogami-<ts>.sql.gz` + `ogami-files-<ts>.tar.gz` in the api container |
| Host cron | `scripts/db-backup-cron.sh` (system crontab) | `./backups/` on the host |
| Manual | `make backup` (dev) / `make prod-backup` (prod) | `./backups/` on the host |

Off-site copies: set `BACKUP_S3_BUCKET` (e.g. `s3://ogami-backups`) so each
dump is also `aws s3 cp`'d. Verify off-site retention separately.

The admin **Backup & Restore** center adds a queued full-backup path using
`db:full-backup`. It pairs the database dump with an archive of private
application uploads (`ogami-files-<ts>.tar.gz`), validates both artifacts, and
records their SHA-256 checksums in `backup_operations`. Restore requests always
create a new rollback pair before entering maintenance mode. The API accepts
only generated artifact filenames; it does not accept arbitrary paths or SQL.

Filename format: `ogami-YYYYMMDD-HHMMSS.sql.gz`.

> NOTE — audit logs. `audit_logs` is append-only (Postgres BEFORE UPDATE/DELETE
> trigger from `2026_06_09_100001_add_audit_log_immutability_trigger.php`).
> `php artisan audit:prune` ARCHIVES old rows to
> `storage/app/audit-archives/audit-YYYY-MM.json.gz` and never deletes them.
> A full `pg_dump` captures `audit_logs` in its entirety regardless.

---

## 2. Restore procedure (DESTRUCTIVE — drops & recreates the DB)

Restore uses `scripts/db-restore.sh`, which terminates connections, drops the
target database, recreates it, and pipes the gunzipped dump into `psql`. It
refuses to run without `--yes`.

### Dev

```bash
make restore FILE=backups/ogami-20260616-031700.sql.gz
```

### Prod

```bash
# On the prod VPS, /opt/ogami-erp, with DB_PASSWORD exported:
make prod-restore FILE=backups/ogami-20260616-031700.sql.gz
```

### Direct (inside the db container)

```bash
docker cp backups/ogami-<ts>.sql.gz ogami-db:/tmp/restore.sql.gz
docker cp scripts/db-restore.sh   ogami-db:/tmp/db-restore.sh
docker exec -e DB_HOST=localhost -e DB_PORT=5432 \
  -e DB_USERNAME=ogami -e DB_PASSWORD=*** -e DB_DATABASE=ogami \
  ogami-db bash /tmp/db-restore.sh --yes /tmp/restore.sql.gz
```

After restore, re-run any post-restore steps the app needs:

```bash
docker compose exec api php artisan migrate --force   # apply any newer migrations
docker compose exec api php artisan config:cache
```

### Admin restore safety contract

The admin Backup & Restore workflow is the production recovery path. Production
Compose explicitly sets `APP_MAINTENANCE_DRIVER=cache` and
`APP_MAINTENANCE_STORE=redis` for the API, queue, scheduler, and migration
containers, so the maintenance gate is visible across containers. Before the
destructive step, the restore worker checks the selected manifest's size and
SHA-256 locally or through S3 `head-object`, creates a rollback point, pauses
the configured Redis queue, and then enters the shared gate.

The operation ledger is the committed pair manifest. A restore can be queued
only from a completed manifest; a filename is not trusted as an independent
source of restore metadata. If database or private-file replacement fails
after the destructive phase starts, the operation becomes
`rollback_required`. Do not run `artisan up` manually in that state. From the
API/queue image, run the operator recovery command and verify the resulting
ledger state:

```bash
docker compose exec api php artisan backup:rollback <rollback-required-operation-uuid>
docker compose exec api php artisan backup:reconcile-stale
```

The rollback command restores the recorded pre-restore artifacts, re-runs
migrations, marks the operation `rolled_back`, resumes the queue, and releases
the shared gate only after the rollback succeeds. A failed rollback keeps the
gate held and the operation visible for another operator attempt.

**What the catalog claims, and what it does not.** The Backup & Restore list is
polled every 5s while an operation runs, so it verifies each artifact's *size*
against its manifest and reports `matches manifest` — never
`checksum verified`. Re-hashing every retained archive on each poll is
gigabytes of I/O per request. The SHA-256 is enforced where the verdict has to
be true of the bytes actually about to be replayed: restore preflight, and the
off-site download. An artifact shown as matching can therefore still fail
preflight, and that is the intended ordering.

Legacy and cron-produced archives appear in the catalog as **Legacy archive**
and are never restorable through the UI: they have no committed manifest, so
`resolveRestoreManifest()` refuses them. Recover one with the shell path in
section 2 instead. A backup that failed after publishing its database dump
records the leftover filenames in the failed operation's
`metadata.orphan_artifacts` — the dump is deliberately kept, because deleting a
usable recovery point to tidy the ledger is the wrong trade.

**Restoring re-imports old ledger rows.** A dump always contains the
`backup_operations` row of the run that produced it, captured mid-run as
`running` with the singleton `active_lock` held. The restore retires those
imported rows after migrations (`metadata.retired_imported_operations`), which
is what stops the first successful recovery from permanently blocking every
later backup. An imported `rollback_required` row is deliberately left alone;
resolve it with `backup:rollback` before queueing anything else.

---

## 3. Recovery objectives

| Objective | Target | Notes |
|-----------|--------|-------|
| RPO (max data loss) | ≤ 24h | Daily backup cadence. Tighten with more frequent `db:backup` runs if needed. |
| RTO (time to restore) | ≤ 30 min | Single-DB `pg_dump` restore on current data volumes. Measure during each drill and record below. |

If a drill exceeds the RTO target, raise an ops ticket and re-evaluate cadence
or restore tooling (e.g. parallel `pg_restore` with custom-format dumps).

---

## 4. Quarterly restore drill — execute this checklist

Run against a **throwaway / staging** database, never production.

- [ ] Pick the most recent dump from `./backups/` (or pull one from S3).
- [ ] Record start time.
- [ ] Spin up a scratch Postgres (e.g. `docker run --rm -e POSTGRES_PASSWORD=... -p 5433:5432 postgres:16`) OR target a staging compose.
- [ ] Run the restore against the scratch DB (point `DB_HOST`/`DB_PORT`/`DB_DATABASE` at it; use `scripts/db-restore.sh --yes <dump>`).
- [ ] Confirm restore exits `restore complete.` with no `ON_ERROR_STOP` failures.
- [ ] Record end time → compute RTO. Compare against the 30-min target.
- [ ] Spot-check integrity:
  - [ ] `SELECT count(*) FROM users;` is non-zero and plausible.
  - [ ] `SELECT count(*) FROM audit_logs;` matches/near production.
  - [ ] Latest `journal_entries` / `payroll_periods` rows look current.
  - [ ] Immutability trigger present: `SELECT tgname FROM pg_trigger WHERE tgname LIKE 'audit_logs_prevent%';` returns 2 rows.
- [ ] Point a throwaway api container at the restored DB; hit `/sanctum/csrf-cookie` and log in to confirm app-level health.
- [ ] Tear down the scratch DB.
- [ ] Log the drill: date, dump used, measured RTO, issues, sign-off.

### Target-like admin recovery drill

The disposable database test above proves dump compatibility, but it does not
prove the multi-container admin contract. At least once per release, run the
following against an isolated staging Compose deployment and retain the
command output, operation UUID, and timestamps:

- [ ] Build `docker-compose.prod.yml` and confirm the API image reports
      PostgreSQL client major version 16 (`pg_dump --version`).
- [ ] Trigger an admin full backup and confirm one completed operation exposes
      a committed manifest with both database and private-file artifacts.
- [ ] Remove or alter a copied artifact and confirm restore preflight rejects
      the size/checksum mismatch before maintenance begins.
- [ ] Start a restore from the committed operation ID; confirm an API request
      receives the maintenance response and queued writers stop consuming work.
- [ ] Confirm the restore operation reaches `completed` and the queue resumes.
- [ ] In a disposable rehearsal, force a post-drop failure; confirm
      `rollback_required`, the maintenance gate remains active, and
      `backup:rollback <uuid>` returns the operation to `rolled_back`.
- [ ] Run `backup:reconcile-stale` after a worker interruption and record the
      resulting status and audit event.
- [ ] Verify the settings endpoint rejects an unknown key, preserves the
      stored JSON type, and writes an immutable `settings.updated` audit row
      with old/new values and the operator reason.
- [ ] Record authenticated API health, queue, scheduler, upload/restart,
      migration, rollback, and measured RTO evidence in the drill log.

### Drill log

| Date | Dump file | RTO | Pass/Fail | Notes / signer |
|------|-----------|-----|-----------|----------------|
| _2026-06-16_ | _example — fill on first real drill_ | _–_ | _–_ | _–_ |
| 2026-08-11 | `backups/ogami-20260810-211704.sql.gz` | ~10s | Partial pass | Disposable PostgreSQL restore completed with `ON_ERROR_STOP`; `users=14`, `event_outbox=0`. App-container login/health checks still require staging. |
| 2026-08-11 | `backups/ogami-20260810-214257.sql.gz` | ~10s | Partial pass | Fresh backup restored into disposable PostgreSQL with `ON_ERROR_STOP`; `users=14`, `event_outbox=0`. App-container login/health checks and VPS freshness/off-site checks still require staging. |
| 2026-08-25 | _target-like admin drill not run in this checkout_ | _–_ | Not run | Local PostgreSQL host `db` is unavailable; authenticated multi-container, queue, rollback, and upload evidence still require an isolated staging deployment. |

---

## 5. Release evidence harness (F-030)

`scripts/release-evidence.sh --run` is the reproducible release-evidence
entrypoint. It refuses to run unless the operator supplies a real dump,
`DB_DATABASE` exactly matches a disposable name of the form
`ogami_release_evidence_<id>`, `SCRATCH_CONFIRM=I_UNDERSTAND_SCRATCH_ONLY`, a
non-broad `EVIDENCE_DIR`, and an explicitly non-production `DB_HOST`. The
restore helper is therefore still destructive, but only against the named
scratch database. Do not point it at `docker-compose.prod.yml`'s live database.

Example (staging host with an isolated database/container):

```bash
BACKUP_FILE=backups/ogami-<timestamp>.sql.gz \
SCRATCH_DB=ogami_release_evidence_20260813 \
SCRATCH_CONFIRM=I_UNDERSTAND_SCRATCH_ONLY \
EVIDENCE_DIR="$PWD/artifacts/release-evidence" \
DB_HOST=<scratch-db-host> DB_PORT=5432 DB_USERNAME=ogami DB_PASSWORD='***' \
DB_DATABASE=ogami_release_evidence_20260813 \
API_HEALTH_URL=https://<scratch-host>/api/v1/health \
AUTH_CHECK_COMMAND='...' QUEUE_CHECK_COMMAND='...' \
SCHEDULER_CHECK_COMMAND='...' UPLOAD_CHECK_COMMAND='...' \
MIGRATION_CHECK_COMMAND='...' \
scripts/release-evidence.sh --run
```

The command hooks are intentionally explicit: authentication, a real queue
job, a scheduler tick/health check, upload + restart verification, and
migration upgrade/rollback must be supplied by the staging operator because
credentials, employee IDs, storage mounts, and compose topology are external
to this repository. Missing hooks are recorded as `not_run` and make the run
fail. The timestamped JSON report and log are artifacts, not evidence that a
run happened until the files are retained and reviewed.

The deploy workflow's `release-evidence-contract` job only checks the harness
and shell contract on GitHub; it makes no restore, authentication, worker, or
production-readiness claim. A failed post-swap deploy records the previous
release and attempts a bounded symlink rollback; if no validated prior release
exists, the workflow fails with an explicit manual-recovery signal.

## 6. Follow-up recommendation (out of scope for OGAMI-018)

`audit:prune` no longer deletes, by design. If true physical pruning of very
old `audit_logs` ever becomes a hard storage requirement, it must be done by an
operator who first drops the immutability trigger via a **dedicated migration**
(reverse of `2026_06_09_100001_add_audit_log_immutability_trigger.php` `down()`),
performs a one-off bounded delete, then reinstalls the trigger — all inside a
single transaction and recorded in the audit trail. This is intentionally NOT
automated. Until then, archives + full dumps are the retention strategy.
