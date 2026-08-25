# M012 — Backups & system settings audit report

- Audit date: 2026-08-24
- Domain/module: platform / backups-system-settings
- Tier: 4
- Surface: M
- Dependencies: auth-session, rbac, audit-activity
- Roles: system_admin
- Status: Plan Ready
- Source changes: none; this session produced audit artifacts only.

## Scope and summary

The audit covered the queued database/private-file backup and restore workflow,
backup-operation ledger, shell helpers, scheduler and host-cron paths,
production Compose/image topology, RBAC and destructive-operation
confirmation, the system-settings API/cache/validation surface, SPA pages and
contracts, migrations, deployment/restore-drill documentation, and focused
tests.

The module has a substantial implementation and several good safety controls:
backup and restore routes are authenticated and permission-gated; view and
destructive permissions are separate; sensitive actions are throttled; restore
names are constrained to server-generated filename patterns; the restore
confirmation is exact; shell helpers use temporary files and archive/version
checks; a rollback pair is attempted before a restore; operation audit events
are emitted; and many known settings have bounded type/range validation.

It is not production-ready as a recovery control. The default production
restore does not put the live API into maintenance mode, the production image
does not pin the PostgreSQL client to the database major, and a failed restore
after database replacement has no automatic rollback. Operation admission and
queue recovery are not durable, artifact identity is not verified at restore
time, database and private-file artifacts are published independently, and the
repository still lacks a production-like end-to-end restore proof. Settings
also accept unknown keys and retain only a last-editor pointer rather than an
immutable change history. No production source fixes were applied because the
majority of work requires coordinated architecture and target-environment
decisions.

## Production readiness lens

Assessment: **Risky**. This is a release-surface assessment, not an approval to
enable destructive restore in production.

High-value blockers:

- **F-001:** queue-container maintenance mode is not visible to the API
  container under the default production topology.
- **F-002:** the production PHP image uses generic `postgresql-client` while
  the database is PostgreSQL 16; the checked-in backup script rejects a major
  mismatch, so scheduled full backups are not proven to publish.
- **F-003:** database replacement and private-file replacement are not one
  reversible transaction; a later failure can leave mixed application state.
- **F-004:** the active-operation guard is check-then-insert, the queue locks
  are not shared across backup/restore job classes, and there is no stale
  operation/lock recovery path.
- **F-005/F-006:** a filename and configured S3 bucket are treated as enough
  preflight evidence, while the backup pair and recorded checksums are not
  atomically enforced.
- **F-007:** the only retained restore evidence is partial disposable-DB
  evidence; authenticated API, workers, scheduler, uploads, and rollback are
  explicitly still unproven.

Evidence checked:

- `BackupService`, backup/restore jobs, operation model/migration, admin
  controller/request/routes, permission seeder, scheduler commands, and all
  direct backup tests.
- `db-backup.sh`, `db-restore.sh`, `files-backup.sh`, `files-restore.sh`, the
  host cron wrapper, healthcheck, Makefile targets, deployment script, and
  production/development Compose and PHP Dockerfiles.
- Settings controller/request/service/schema, settings cache test, settings
  page/API, backup page/API, SPA routes/permission guards, and deployment and
  restore-drill documentation.
- Recent backup-related worktree changes were inspected and preserved; they
  were not attributed to this audit.
- Shell syntax checks, PHP syntax checks for the audited backend files, and
  `php artisan route:list --path=admin/backups`.

Evidence still missing:

- A built production image proving the actual `pg_dump` major and a real
  PostgreSQL-16 backup/restore run from the scheduler/queue image.
- A target-like restore drill that exercises the live API gate, queue,
  scheduler, Redis/cache, durable uploads, forward migrations, rollback, and
  failure recovery.
- Concurrent HTTP/queue interleaving tests, S3 object/head/checksum behavior,
  and observed backup volume/retention/query/runtime measurements.
- A reviewed policy for automatic rollback versus an explicit operator-led
  partial-recovery state.

Next action: resolve the maintenance/runtime and restore state-machine design
first, then implement a durable operation/manifest protocol and run the
production-like evidence harness before re-auditing the UI and settings
controls.

## Findings

### F-001 — Restore maintenance mode is container-local in production

- Classification: Broken
- Tags: [large] [separate-recommended]
- Evidence: `BackupService::runRestore()` calls `Artisan::call('down')` from
  the queued restore worker at
  `api/app/Common/Services/BackupService.php:274-278` and calls `up` from the
  same worker at `:320-329`. The default driver is `file` at
  `api/config/app.php:24-27`; Laravel stores the active marker at
  `storage_path('framework/down')` in
  `api/vendor/laravel/framework/src/Illuminate/Foundation/FileBasedMaintenanceMode.php:15-19,56-63`.
  Production mounts only `storage/app` into `api` and `queue` at
  `docker-compose.prod.yml:59-60,162-163`; `storage/framework` is baked into
  each image and is not shared.
- Impact: the queue worker can drop and recreate the database while the live
  API continues serving requests against the old/no database. Concurrent
  writes, reads, sessions, and uploads can observe a partial restore. The UI
  and controller promise maintenance mode, but the default production
  topology does not provide that guarantee.
- Recommendation: use a shared, non-restored coordination mechanism for the
  maintenance gate (for example a cache-based maintenance driver on the shared
  Redis store, or an edge/API drain gate), or explicitly share the framework
  marker. Stop or pause consumers and verify every API instance observes the
  gate before destructive DB work. Add a multi-container smoke test for down,
  restore, failed restore, and up.

### F-002 — Production backup client major is not pinned to PostgreSQL 16

- Classification: Broken
- Tags: [large] [separate-recommended]
- Evidence: production uses `postgres:16-alpine` at
  `docker-compose.prod.yml:89-102`, while
  `docker/php/Dockerfile.prod:42-44` installs generic `postgresql-client`.
  The development Dockerfile explicitly documents that generic Alpine client
  packages track a newer major and pins `postgresql16-client` at
  `docker/php/Dockerfile:17-21`. The checked-in backup helper compares the
  dump client/server major and refuses to publish a mismatch at
  `scripts/db-backup.sh:77-97`; the scheduler runs `db:full-backup` daily at
  `api/routes/console.php:232-239`.
- Impact: a production image built from the generic package can make every
  scheduler/admin full backup fail closed. The failure is safer than
  publishing an unrestorable dump, but it leaves the promised daily recovery
  point absent; the repository has no built-image evidence proving the current
  production package matches PostgreSQL 16.
- Recommendation: pin the production package to the database major, fail the
  image/release check when `pg_dump --version` is not 16, and run a real
  scheduler-image dump plus restore against PostgreSQL 16 in CI or staging.

### F-003 — Restore failure after database replacement leaves mixed state

- Classification: Incomplete
- Tags: [large] [separate-recommended]
- Evidence: the restore performs destructive DB restore, purges the DB
  connection, migrates, and only then restores private files at
  `api/app/Common/Services/BackupService.php:280-295`. A private-file failure
  is reported as “the database restore may already be complete” at `:540-548`.
  The catch path records `failed`, but does not restore the pre-restore pair at
  `:299-319`; the finally block only attempts to leave maintenance mode at
  `:320-329`. `scripts/files-restore.sh:23-29` deletes the existing private
  tree before extraction, so an extraction failure can also leave partial
  files. The runbook records only manual post-restore steps at
  `docs/RESTORE-DRILL.md:46-74`.
- Impact: a restore can complete the database replacement and then fail during
  migration or file extraction, leaving a database/files combination that did
  not exist in either snapshot. The operation ledger says failed, but the
  system has no automatic rollback or explicit `rollback_required` state.
  Database-only restores also always create a fresh database-plus-files
  rollback pair first (`:242-256`), so a missing/private-files backup can
  prevent an otherwise requested database-only restore.
- Recommendation: define the recovery state machine before implementation.
  Preflight all inputs, drain writes and consumers, use a reversible staged
  restore where possible, run health checks, and either automatically restore
  the pre-restore pair on any post-drop failure or expose a durable
  `partial/rollback-required` state with an operator-safe recovery command.
  Test DB failure, migration failure, file failure, worker death, and failed
  maintenance-up explicitly.

### F-004 — Active-operation admission and queue recovery are not durable

- Classification: Broken
- Tags: [large] [separate-recommended]
- Evidence: `queueBackup()` and `queueRestore()` perform a plain active-row
  query before creating a queued row at
  `api/app/Common/Services/BackupService.php:41-60,83-110`; the check itself
  is `exists()` with no transaction/lock at `:406-417`. The migration has no
  singleton constraint, lease, heartbeat, attempt, or terminal-reconciliation
  fields at
  `api/database/migrations/2026_08_22_120000_create_backup_operations_table.php:13-27`.
  Both jobs set `tries = 1` and use
  `new WithoutOverlapping('ogami-backup-recovery')` at
  `api/app/Common/Jobs/CreateBackupJob.php:20-39` and
  `RestoreBackupJob.php:20-39`, but Laravel's default lock key includes the
  job class unless `shared()` is called at
  `api/vendor/laravel/framework/src/Illuminate/Queue/Middleware/WithoutOverlapping.php:157-167`.
  The default lock expiry is zero (`:57-61`), and Redis implements that as a
  non-expiring `SETNX` lock at
  `api/vendor/laravel/framework/src/Illuminate/Cache/RedisLock.php:34-41`.
  Queue `after_commit` is false at `api/config/queue.php:7-18`.
- Impact: two concurrent requests can both pass the check and create active
  operations. Backup and restore jobs can pass their supposedly shared lock
  because they have different class-qualified keys. A worker crash can leave a
  queued/running operation that permanently blocks new requests and can leave
  a Redis overlap lock behind. An audit or dispatch exception after row
  creation can orphan a queued row because there is no outbox or reaper.
- Recommendation: make admission a DB/Redis-atomic state transition with a
  durable operation lease and idempotency key. Use one genuinely shared lock
  with a bounded expiry/renewal policy, or one dispatcher that owns the
  serialized state machine. Add heartbeat/stale recovery, explicit operator
  unblock/retry behavior, after-commit dispatch/outbox handling, and
  PostgreSQL interleaving tests for backup-vs-backup and backup-vs-restore.

### F-005 — Restore preflight does not verify artifact identity or remote existence

- Classification: Incomplete
- Tags: [medium] [separate-recommended]
- Evidence: completed local artifacts record SHA-256 metadata at
  `api/app/Common/Services/BackupService.php:209-214,436-448`, but restore
  requests accept only a filename and do not bind it to a ledger manifest at
  `:65-110`. `assertArtifactAvailable()` accepts any filename when an S3
  bucket is configured without checking whether the object exists at
  `:470-482`. Remote materialization only checks `aws s3 cp` success and
  `is_file` at `:504-521`; it never compares a stored checksum/size and does
  not perform a preflight `head-object`. Local materialization also returns a
  same-named file without rechecking the recorded checksum at `:498-502`.
- Impact: the API can queue a restore for a nonexistent remote object and
  discover the failure only in the worker. A same-named local or remote
  object can be replaced by different valid data and still be treated as the
  selected snapshot. The archive scripts validate gzip/tar/SQL restorability,
  but not that the bytes are the ledger's intended bytes.
- Recommendation: make the restore request select a manifest/operation, not a
  free filename; persist expected checksum, size, kind, and remote key; verify
  local bytes and remote `head-object`/download bytes before maintenance; and
  refuse restore when the manifest is missing or mismatched. Add tests for
  missing remote objects, same-name swaps, checksum mismatch, and legacy
  artifacts.

### F-006 — Database and private-file artifacts are published independently

- Classification: Incomplete
- Tags: [medium] [separate-recommended]
- Evidence: `RunFullBackup` publishes the DB backup through `db:backup` and
  returns immediately if that succeeds at
  `api/app/Console/Commands/RunFullBackup.php:29-37`; the DB script publishes
  its final name at `scripts/db-backup.sh:99-103` before the files script runs.
  The private-file script publishes independently at
  `scripts/files-backup.sh:38-50`. If the second phase fails, the full command
  returns failure at `RunFullBackup.php:75-83`, while the first artifact remains
  in the backup directory. `BackupService::runBackup()` only marks the ledger
  completed after discovering both artifacts at `:203-223`; it does not create
  a pair manifest or quarantine/clean up partial publication.
- Impact: crashes and partial failures leave plausible unpaired snapshots.
  Retention can remove members independently, and the ledger/UI can report a
  failed operation beside a live database artifact that is not a coherent
  database-plus-files recovery point.
- Recommendation: write both artifacts into a per-run staging area, validate
  both, upload both if configured, then atomically publish a manifest/commit
  marker containing the pair and checksums. Retain/quarantine partial runs
  explicitly and make the UI restore only committed manifests.

### F-007 — Production-like restore evidence is still missing

- Classification: Missing
- Tags: [large] [external]
- Evidence: `docs/RESTORE-DRILL.md:118-119` records two partial disposable
  PostgreSQL restores but explicitly says application-container login/health,
  VPS freshness/off-site checks, and staging work remain. The release-evidence
  section says its hooks are missing unless supplied by the staging operator
  and that the workflow makes no restore/authentication/worker/scheduler claim
  at `docs/RESTORE-DRILL.md:130-160`. The repository therefore proves shell
  and disposable-DB behavior, not the production Compose restore path.
- Impact: backup success and restore completion can be over-interpreted while
  the live API gate, queue/scheduler restart behavior, durable uploads,
  migration compatibility, and rollback behavior remain unverified.
- Recommendation: run and retain a target-like drill using the actual built
  images and isolated data: backup, authenticated API smoke, real queued job,
  scheduler health, S3/local artifact retrieval, private upload restore,
  forward migration, rollback/failure drill, and post-restore health. Keep
  timestamped logs and checksums as release evidence.

### F-008 — System settings accept arbitrary keys and lack immutable change history

- Classification: Incomplete
- Tags: [medium] [separate-recommended]
- Evidence: `UpdateSettingRequest::rules()` falls back to only `present` for
  keys not in its rule maps at
  `api/app/Modules/Admin/Requests/UpdateSettingRequest.php:398`; the
  controller passes the caller-supplied key to `SettingsService::set()` at
  `api/app/Modules/Admin/Controllers/SettingsController.php:43-50`; and
  `SettingsService::set()` inserts unknown keys at
  `api/app/Common/Services/SettingsService.php:104-133`. The settings table
  enforces uniqueness but not a catalog/type contract at
  `api/database/migrations/0013_create_settings_table.php:13-21`. The update
  path records only `updated_by` on the current row at
  `SettingsController.php:48-50`; it does not create an AuditLog old/new
  record, and no settings audit call is present in the inspected code.
- Impact: a settings manager can create unreviewed configuration keys and
  values that future code may consume, while security, payroll, accounting,
  and feature-flag changes have no immutable before/after history. The last
  editor pointer is not enough for incident reconstruction or rollback.
- Recommendation: require a catalog entry and schema for every writable key,
  reject unknown keys, and separate high-impact/security/feature-flag
  permissions where appropriate. Record immutable old/new values, actor,
  request/correlation ID, and reason in the audit trail; add endpoint tests for
  unknown keys, invalid types, module toggles, and two-user history.

### F-009 — Recovery catalog and UI overstate availability and do not scale past one page

- Classification: Incomplete
- Tags: [medium] [same-session-ok]
- Evidence: `BackupService::index()` loads only the latest 50 ledger rows at
  `api/app/Common/Services/BackupService.php:117-124`, appends local globbed
  artifacts, then slices the combined list to 50 at `:136-166`. It does not
  enumerate remote-only objects, and managed rows are serialized without
  rechecking whether retention has removed their files. The SPA polls while
  active and has a manual refresh at
  `spa/src/pages/admin/backups.tsx:296-300,351-374`, but renders the fixed
  response and labels completed/available entries “Verified” and “Ready for
  recovery” at `:53-79` without a successful restore proof. The restore button
  is enabled from ledger status and a database artifact at `:96-102`, even
  when local retention has removed the file.
- Impact: older operation history and remote-only recovery points are not
  operator-discoverable; a retained ledger row can look restorable until the
  request fails later; and “verified” can be read as restore-tested although
  only archive/hash metadata was recorded.
- Recommendation: expose cursor pagination and explicit artifact availability
  (`local`, `remote`, `missing`, `checksum_mismatch`) from a committed
  manifest/catalog. Make the UI distinguish format-validated, checksum-
  verified, and restore-tested states, and disable restore for unavailable or
  uncommitted artifacts.

## Implemented safeguards worth retaining

- Admin backup routes require Sanctum authentication, session timeout, and
  password-expiry checks; view and create/restore permissions are split; and
  create/restore are sensitive-throttled at
  `api/app/Modules/Admin/routes.php:82-93`.
- Restore names are basename- and pattern-validated, the confirmation uses
  `hash_equals`, local paths are constrained by `realpath`, and the restore
  helper validates the database name before SQL interpolation.
- Database and file helpers publish through temporary files, check gzip/tar
  integrity, and perform PostgreSQL major checks before destructive restore.
- The restore path attempts a fresh rollback pair before entering its current
  maintenance step, migrations are applied after a historical DB restore, and
  operation request/completion/failure audit events carry a correlation ID.
- Known settings have extensive type/range rules and cache invalidation is
  per-key rather than a global cache flush.
