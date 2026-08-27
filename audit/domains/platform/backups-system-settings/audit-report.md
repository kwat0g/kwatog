# M012 — Backups & system settings audit report

- Audit date: 2026-08-27
- Domain/module: platform / backups-system-settings
- Tier: 4
- Surface: M
- Dependencies: auth-session, rbac, audit-activity
- Roles: system_admin
- Status: 📋 Plan Ready
- Source changes: none; this re-audit updated module audit artifacts only.
- Claimed module: `platform/backups-system-settings` (preferred M012); fallback
  was not used.

## Scope and evidence

This re-audit covered the queued database/private-file backup and restore
workflow, operation ledger and leases, shell helpers, scheduler and host-cron
paths, production Compose/image topology, RBAC and destructive confirmation,
the system-settings API/cache/validation surface, SPA pages and contracts,
migrations, restore-drill documentation, tests, current git diff, and file
mtimes. The fresh registry listed M012 as `🔁 Needs Re-audit`; the generated
registry was not edited.

The worktree was clean before this session at commit
`7e0194cc161e1e3d61884c0899befb38c3a4d807`. The preceding implementation
commits were inspected rather than treated as proof. The implementation now
has meaningful safeguards: authenticated and separately permissioned backup
routes, sensitive-operation throttling, exact restore confirmation, basename
and artifact-pattern validation, shared Redis maintenance configuration in the
production Compose services, a PostgreSQL-16 client pin, a database singleton
constraint, shared bounded queue overlap locks, leases and stale reconciliation,
committed artifact metadata with local/off-site size and checksum checks,
durable `rollback_required` state, cursor pagination, and settings type/range
validation with immutable audit events.

Verification evidence:

- Focused API suite inside the API container, using only
  `DB_DATABASE=ogami_test_m012_agent_d`: **31 tests, 110 assertions, pass**.
  The host-side attempt could not resolve Docker-only host `db`; the same
  focused command was rerun in the API container and passed.
- PHP syntax checks for the audited backend files: pass.
- Shell syntax checks for `db-backup.sh`, `db-restore.sh`, `files-backup.sh`,
  `files-restore.sh`, and `db-backup-cron.sh`: pass.
- Direct ESLint on M012 SPA/API files (`backups.tsx`, `backups.ts`,
  `settings.tsx`, `settings.ts`): pass.
- `docker compose -f docker-compose.prod.yml config --quiet`: pass.
- `php artisan route:list --path=admin/backups --no-ansi`: pass; the expected
  three admin backup routes are registered.
- Project-wide SPA lint remains red on four unrelated files:
  `src/hooks/useChainProgress.tsx:89-90`,
  `src/pages/accounting/journal-entries/edit.tsx:79`, and
  `src/pages/quality/inspections/create.tsx:80`. Project-wide typecheck
  remains red on the unrelated missing `qrcode` module and implicit parameter
  in `src/pages/assets/detail.tsx:6,67`. These were not changed.
- `docs/RESTORE-DRILL.md:166-200` still records the target-like admin drill as
  not run; no production-like restore proof was claimed.

Assessment: **Risky / Plan Ready**. The focused code paths are substantially
hardened, but recovery remains a release-impacting control and the missing
cross-process evidence is material. No source fix was permitted by the gate.

## Prior finding disposition

- F-001 (container-local maintenance): **mitigated in source** by
  `api/config/app.php:24-30` and the production Compose environment at
  `docker-compose.prod.yml:50-65,163-167`. Target-like multi-container proof
  remains part of F-007.
- F-002 (generic production PostgreSQL client): **mitigated in source** by
  `docker/php/Dockerfile.prod:42-44,105-107`. A built-image/versioned backup
  proof remains part of F-007.
- F-003 (non-atomic restore): remains **Incomplete**; the new durable
  rollback-required state is useful but does not make DB/files replacement
  atomic. See current F-003.
- F-004 (admission/queue recovery): the unique active lock, shared bounded
  job lock, lease fields, and reaper are present, but dispatch durability and
  lease fencing remain incomplete. See current F-004 and F-012.
- F-005 (artifact identity): committed-manifest and checksum checks are now
  present on the normal path, but the resolver fails to enforce the committed
  flag on old completed ledger rows. See current F-010.
- F-006 (paired publication): remains **Incomplete**; the full-backup command
  still publishes its two members in separate phases. See current F-006.
- F-007 (production-like proof): remains **Missing**. See current F-007.
- F-008 (settings governance): the admin path now rejects unknown rows,
  locks the row, preserves JSON class, and writes immutable audit data. The
  browser still does not collect an operator reason. See current F-013.
- F-009 (catalog/UI): cursor pagination and availability/integrity fields are
  now present. The UI still contains misleading success-state polish. See
  current F-014.

## Findings

### F-003 — Restore replacement is not atomic and rollback remains operator-led

- Classification: Incomplete
- Tags: [large] [separate-recommended]
- Evidence: `runRestore()` replaces the database, runs migrations, and only
  then restores private files at
  `api/app/Common/Services/BackupService.php:431-456`; a private-file failure
  is explicitly reported as “the database restore may already be complete” at
  `:1396-1405`. The catch path records a durable rollback-required state, but
  does not automatically apply the saved pre-restore pair at `:457-495`.
  `RunFullBackup` invokes the database backup and private-file backup as
  separate publication phases at
  `api/app/Console/Commands/RunFullBackup.php:29-37,39-83`, with independent
  final moves in `scripts/db-backup.sh:99-100` and
  `scripts/files-backup.sh:48-50`.
- Impact: a migration or file failure can leave a database/files combination
  that did not exist in either snapshot. The new ledger state makes the risk
  visible and blocks normal reuse, but recovery still depends on a separate
  operator command and there is no verified automatic rollback or health-check
  gate.
- Recommendation: choose and document automatic verified rollback versus an
  explicit partial-recovery contract; stage and validate the pair, hold all
  writers, run post-restore health checks, and test DB, migration, file,
  worker-death, and rollback-failure paths.

### F-004 — Queue dispatch has no durable outbox or immediate retry contract

- Classification: Incomplete
- Tags: [medium] [separate-recommended]
- Evidence: both request paths commit a queued operation and then dispatch
  outside the transaction at
  `api/app/Common/Services/BackupService.php:75-78,159-164`. The row owns the
  singleton lock from creation (`:56-60,134-150`), while the stale sweep only
  handles an old queued row after the lease grace window at `:600-603,639-649`.
  Queue configuration has `after_commit => false` at
  `api/config/queue.php:7-18`; there is no outbox, dispatch receipt, idempotency
  key, or immediate retry path.
- Impact: a process or broker failure after the database commit and before the
  queue push can leave the caller with an error and the recovery surface blocked
  for the full stale window. The unique database lock prevents a second active
  row, but it does not make the original operation run.
- Recommendation: use a transactional outbox or durable dispatcher with an
  idempotent operation key, observable dispatch state, bounded retry, and an
  operator-visible retry/unblock action. Add a test that fails dispatch after
  commit and proves eventual delivery without duplicate destructive work.

### F-006 — Database and private-file artifacts are still published independently

- Classification: Incomplete
- Tags: [medium/large] [separate-recommended]
- Evidence: the full-backup command returns after the database phase succeeds
  and only then starts the private-file process at
  `api/app/Console/Commands/RunFullBackup.php:29-37,39-83`. Each helper moves
  its own validated temporary archive to a final name at
  `scripts/db-backup.sh:70-100` and `scripts/files-backup.sh:38-50`.
  `BackupService::runBackup()` discovers both after the command and commits the
  ledger metadata at `api/app/Common/Services/BackupService.php:279-305`; a
  second-phase crash leaves the first published artifact behind.
- Impact: failed runs leave plausible unpaired snapshots and retention can
  remove members independently. The ledger correctly avoids marking that run
  committed, but the directory still contains recovery-looking material that
  can be mistaken for a coherent point or later merged incorrectly.
- Recommendation: stage both members under a run-specific directory, validate
  and upload both, then atomically publish one manifest/commit marker containing
  pair identity, kind, size, checksums, and remote keys. Quarantine incomplete
  runs and make catalog/restore admission manifest-only.

### F-007 — Production-like restore evidence is still missing

- Classification: Missing
- Tags: [large] [external]
- Evidence: the runbook’s target-like checklist requires the actual production
  image, committed pair, maintenance response, queue drain, successful restore,
  forced post-drop failure, rollback, stale-worker reconciliation, and settings
  endpoint checks at `docs/RESTORE-DRILL.md:166-191`. The drill log explicitly
  records that this was not run in the checkout because local host `db` is
  unavailable at `docs/RESTORE-DRILL.md:193-200`.
- Impact: source inspection and the focused suite do not prove that all API
  instances observe the Redis gate, the real queue/scheduler recover, private
  uploads survive, migrations are compatible, off-site retrieval works, or a
  failed rollback is safe.
- Recommendation: run and retain a target-like isolated staging drill using
  built production images, authenticated API health, scheduler/queue, local and
  off-site artifacts, uploads, forward migration, forced failure, rollback,
  post-restore health, timestamps, checksums, and measured RTO.

### F-010 — Restore admission can select a completed row without a committed manifest

- Classification: Broken
- Tags: [medium] [separate-recommended]
- Evidence: the catalog and serializer correctly expose `manifest_committed`
  only when metadata says so at
  `api/app/Common/Services/BackupService.php:865-890`, and the source comment
  says the resolver refuses non-manifest artifacts at `:1010-1014`. However,
  `resolveRestoreManifest()` selects any `backup` row with `status=completed`
  at `:1024-1049`; it never checks
  `metadata.manifest_committed === true`, a manifest version, or required
  checksum/size/kind fields. `queueRestore()` then passes that row’s artifact
  metadata to availability checks at `:103-121`.
- Impact: a direct API caller can queue a restore from a legacy completed
  ledger row that the catalog would mark non-restorable, with absent checksum
  metadata accepted as presence-only. The normal committed-manifest safety
  contract is therefore bypassable precisely on the compatibility path.
  `BackupRecoveryHardeningTest.php:56-72,319-338` covers an untracked legacy
  file/catalog entry, not a completed legacy ledger row selected by ID.
- Recommendation: require a committed manifest flag/version and complete
  artifact identity before either ID or filename resolution; reject or migrate
  legacy completed rows; add an endpoint regression for a completed row with
  `manifest_committed=false` and missing integrity fields.

### F-011 — Failed rollback can release the maintenance gate and queue

- Classification: Broken
- Tags: [medium] [separate-recommended]
- Evidence: `rollback()` persists `rollback_required` and rethrows after any
  rollback error at `api/app/Common/Services/BackupService.php:780-806`, but its
  `finally` block unconditionally calls `Artisan::call('up')` and resumes the
  queue whenever the gate/queue was entered at `:807-823`. The return code from
  `up` is ignored. The ordinary restore path also ignores the `up` return code
  and has no postcondition that the shared gate is inactive at `:496-509`.
- Impact: a failed rollback can expose partially restored database/files state
  to API requests and queued writers while the ledger still says
  `rollback_required`. A successful restore can likewise be marked completed
  while the application remains in maintenance if `up` returns non-zero. This
  contradicts the operator-facing contract at
  `docs/RESTORE-DRILL.md:182-184`.
- Recommendation: make gate release fail-safe: on rollback failure keep
  maintenance and queue paused, persist the failure, and require an explicit
  verified operator action. Check command exit codes and shared gate/queue
  postconditions before declaring recovery complete; add forced rollback and
  failed-`up` tests.

### F-012 — Lease and stale-reaper updates are not fenced against a live worker

- Classification: Broken
- Tags: [medium/large] [separate-recommended]
- Evidence: the reaper reads expired rows as a collection at
  `api/app/Common/Services/BackupService.php:581-605`, then later saves a
  failure or rollback-required status without matching the observed
  `lease_token`, status, or expiry at `:607-649`. Heartbeats likewise load by ID
  and save a new expiry without a token predicate at `:548-565`. `markRunning()`
  accepts both `queued` and `running` rows and replaces the lease token without
  a conditional claim at `:904-922`. The migration adds the token and unique
  index at `api/database/migrations/2026_08_25_220000_harden_backup_operations.php:18-27`,
  but the service does not use it as a compare-and-swap fence.
- Impact: a live worker can heartbeat or complete after the reaper has selected
  its old lease, then be overwritten as failed while a new operation is
  admitted. Conversely, a replayed job can replace a live worker’s token and
  both workers can perform destructive work. The sequential “live lease remains
  untouched” test at `BackupRecoveryHardeningTest.php:174-190` does not exercise
  the interleaving.
- Recommendation: atomically claim/reconcile with `WHERE id AND status AND
  lease_token AND lease_expires_at`, reject stale worker writes, make
  `markRunning` a one-time queued-to-running transition, and add PostgreSQL
  interleaving tests for heartbeat/reaper/replay/admission.

### F-013 — Settings changes have no operator reason in the admin UI

- Classification: Incomplete
- Tags: [small] [same-session-ok]
- Evidence: the API client sends only `{ value }` from
  `spa/src/api/admin/settings.ts:30-37`, and the settings mutation passes only
  key/value at `spa/src/pages/admin/settings.tsx:210-220`. The backend accepts
  an optional reason and substitutes the generic string `Admin settings update`
  when omitted at
  `api/app/Common/Services/SettingsService.php:201-223`; the runbook describes
  an operator reason as part of the acceptance contract at
  `docs/RESTORE-DRILL.md:187-189`.
- Impact: the immutable audit row exists, but routine browser edits cannot
  capture why a security, payroll, accounting, or module-toggle change was
  made. Incident reconstruction is weaker than the documented governance
  contract.
- Recommendation: require a reason for high-impact settings (or all admin
  edits), collect it in the UI, send it with the update, and surface the reason
  in the change attribution/validation path.

### F-014 — Settings and recovery UI show optimistic/static success state

- Classification: Polish
- Tags: [small] [same-session-ok]
- Evidence: `ScalarRow` sets its “Saved” indicator on blur before the mutation
  has succeeded at `spa/src/pages/admin/settings.tsx:590-595`; the mutation’s
  actual success/error callbacks run later at `:210-220`. The backup recovery
  posture icon is hard-coded to success styling at
  `spa/src/pages/admin/backups.tsx:428-446`, even when the adjacent posture
  chip is warning or no restorable snapshot exists.
- Impact: a failed settings request can briefly tell an operator it was saved,
  and a warning/no-snapshot recovery state still presents a green shield. These
  do not change backend state, but they can mislead an operator during a
  sensitive recovery decision.
- Recommendation: set the saved state only from mutation success, clear or
  restore it on error, synchronize edited values after refresh, and derive the
  posture icon styling from the same state as the posture chip.

## Implemented safeguards worth retaining

- `api/config/app.php:24-30` defaults the maintenance driver/store to the
  shared cache/Redis configuration, and production API/queue/scheduler/migrate
  services set those values in Compose.
- `docker/php/Dockerfile.prod:42-44,105-107` installs and asserts the
  PostgreSQL-16 client; the backup helper also rejects dump/server major skew.
- Admin backup routes require authentication/session/password checks, separate
  view/manage permissions, and sensitive-operation throttling.
- Restore names, confirmations, local paths, SQL database names, archive
  formats, local checksums, remote object metadata, and downloaded bytes are
  validated before destructive work.
- `active_lock`, lease fields, shared bounded `WithoutOverlapping`, heartbeat,
  stale reconciliation, cursor pagination, and explicit rollback-required/
  rolled-back statuses are present. Their remaining concurrency limitations
  are recorded above rather than discarded.
- Admin settings now use an existing-row lock, unknown-key rejection, shared
  JSON-class validation, per-key cache invalidation, and immutable audit rows
  with old/new values, actor, request metadata, correlation, and redaction.

## Release decision

The gate is **📋 Plan Ready**. F-003, F-004, F-006, F-007, F-010, F-011, and
F-012 are release-impacting or cross-process recovery work; F-013 and F-014
are the only same-session-ok items, and the overall plan is not small or a
majority same-session-ok. No production source fixes were applied. The next
action is a coordinated implementation pass for the recovery protocol followed
by a fresh M012 audit and the target-like drill.
