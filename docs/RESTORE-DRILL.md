# OGAMI ERP — Backup & Restore Drill Runbook

Owner: Platform / Ops. Last reviewed: 2026-06-16 (OGAMI-018).

This runbook covers (1) how backups are produced, (2) how to restore from one,
and (3) a repeatable drill checklist to prove the backups actually work. A
backup you have never restored is not a backup — run the drill at least
quarterly.

---

## 1. How backups are produced

Three paths, all using the same `scripts/db-backup.sh` (plain `pg_dump` →
`gzip`, 14-file retention, optional S3 upload):

| Path | Trigger | Output |
|------|---------|--------|
| Scheduler | `php artisan db:backup` daily **03:17** (`api/routes/console.php`) | `storage/app/backups/ogami-<ts>.sql.gz` in the api container |
| Host cron | `scripts/db-backup-cron.sh` (system crontab) | `./backups/` on the host |
| Manual | `make backup` (dev) / `make prod-backup` (prod) | `./backups/` on the host |

Off-site copies: set `BACKUP_S3_BUCKET` (e.g. `s3://ogami-backups`) so each
dump is also `aws s3 cp`'d. Verify off-site retention separately.

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

### Drill log

| Date | Dump file | RTO | Pass/Fail | Notes / signer |
|------|-----------|-----|-----------|----------------|
| _2026-06-16_ | _example — fill on first real drill_ | _–_ | _–_ | _–_ |

---

## 5. Follow-up recommendation (out of scope for OGAMI-018)

`audit:prune` no longer deletes, by design. If true physical pruning of very
old `audit_logs` ever becomes a hard storage requirement, it must be done by an
operator who first drops the immutability trigger via a **dedicated migration**
(reverse of `2026_06_09_100001_add_audit_log_immutability_trigger.php` `down()`),
performs a one-off bounded delete, then reinstalls the trigger — all inside a
single transaction and recorded in the audit trail. This is intentionally NOT
automated. Until then, archives + full dumps are the retention strategy.
