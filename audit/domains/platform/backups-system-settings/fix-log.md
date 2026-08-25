# M012 — backups & system settings fix log

## Session 2026-08-24 (audit only)

Audit date: 2026-08-24
Final disposition: Plan Ready

### Before

- The registry was refreshed and M012 was selected as the first dependency-
  ready Not Started module after M008.
- auth-session, rbac, and audit-activity were already Plan Ready.
- M012 was claimed atomically. The worktree contained user-owned backup,
  Docker, Compose, SPA, and unrelated changes; those changes were preserved
  and not overwritten.

### Decision

No production source fixes were applied. The audit found a non-shared
production maintenance gate, an unpinned PostgreSQL client, a non-reversible
partial restore path, non-durable operation locking/dispatch, unenforced
artifact identity, independent DB/files publication, missing target-like
restore evidence, and permissive/non-immutable settings administration. These
findings require coordinated design and environment evidence; isolated edits
would create a misleading recovery claim.

### Verification after audit

- Shell syntax: passed for the four backup/restore helpers and host backup
  wrapper (`bash -n`).
- PHP syntax: passed for the audited backup jobs/service/controller/request and
  settings service/controller/request files (`php -l`).
- Route discovery: passed; `php artisan route:list --path=admin/backups`
  resolved 3 routes.
- Focused PHPUnit was attempted for `BackupControllerTest` and
  `SettingsCacheTest` but all 7 tests errored before assertions because the
  local environment could not resolve PostgreSQL host `db` (`SQLSTATE[08006]`,
  temporary DNS failure). No test result is claimed from that run.
- No application source files outside `audit/` were modified by this session.

### Deferred findings

All findings remain pending implementation and re-audit: F-001 through F-009.
This is a Plan Ready handoff, not a claim that backup, restore, or settings
governance is production-verified.

---

## Session 2026-08-26 (implementation)

Claim: `RECLAIMED` (the 2026-08-25 21:21 lock was orphaned by a crashed
session).

### State inherited, not authored by this session

The plan was written 2026-08-24. Between then and now, `b269eafd feat(backup):
add admin backup and restore center` landed, and a **crashed session on
2026-08-25 21:21–21:48** left substantial uncommitted backup work in the
worktree without logging it. Reading the plan against current code first, as
instructed, showed most of it already implemented. Attribution below is explicit
so this log does not claim credit for work it did not do.

**Already resolved before this session (verified against current code, not
assumed):**

| Finding | Resolved by | Evidence |
|---|---|---|
| F-001 shared maintenance gate | prior worktree work | `api/config/app.php:24-30` driver `cache`/store `redis`; `docker-compose.prod.yml` sets `APP_MAINTENANCE_DRIVER`/`_STORE` on api, queue, scheduler, reverb, migrate; `BackupService::runRestore()` re-asserts `app()->maintenanceMode()->active()` after `down` and refuses to proceed otherwise |
| F-002 PostgreSQL client major | prior worktree work | `docker/php/Dockerfile.prod:43` `postgresql16-client`; build-time assertion `pg_dump --version \| grep -Eq ' 16\.'` at `Dockerfile.prod:105-107`; `docker/php/Dockerfile:19` pins the dev image too |
| F-003 restore state machine / rollback | prior worktree work | `STATUS_ROLLBACK_REQUIRED`/`STATUS_ROLLED_BACK`, `restore_phase` metadata, `BackupService::rollback()`, `api/app/Console/Commands/RollbackBackupOperation.php`; `scripts/files-restore.sh` now stages + directory-swaps instead of deleting the live tree first; DB-only restores no longer require a private-files rollback artifact |
| F-004 durable admission (partly) | prior worktree work | `2026_08_25_220000_harden_backup_operations.php` adds `active_lock` UNIQUE + `lease_token`/`attempts`/`heartbeat_at`/`lease_expires_at`; `WithoutOverlapping(...)->shared()->expireAfter(...)` on both jobs; dispatch moved after commit; `backup:reconcile-stale` scheduled every 10 min in `api/routes/console.php` |
| F-005 artifact identity | prior worktree work | `resolveRestoreManifest()` binds a restore to a completed manifest; size + SHA-256 enforced locally and via `aws s3api head-object`; `scripts/db-backup.sh`/`files-backup.sh` now upload `--metadata sha256=` |
| F-006 pair publication (partly) | prior worktree work | `runBackup()` refuses to commit unless **both** artifacts appear; only `manifest_committed` operations are restorable |
| F-008 settings governance (partly) | prior worktree work | `SettingsService::updateFromAdmin()` rejects unknown keys, locks the row, writes an immutable `settings.updated` AuditLog with old/new + actor + reason + correlation id; `UpdateSettingRequest::withStoredTypeGuard()` |
| F-009 catalog honesty (partly) | prior worktree work | `availability`/`integrity`/`manifest_committed`/`restorable` in the serializer; `spa/src/pages/admin/backups.tsx` distinguishes committed vs legacy and gates the Restore button |

A fresh discovery pass **was** required, because that inherited work introduced
new defects of its own and left the remaining plan items unaddressed. Six of the
eight fixes below are defects in the inherited code, not in the code the
2026-08-24 audit read.

### Fixes applied this session

**1. Settings type guard froze every whole-number decimal setting — regression
in the F-008 work**
`api/app/Common/Services/SettingsService.php:232-256` (new
`valueKeepsStoredJsonType()`), `api/app/Modules/Admin/Requests/UpdateSettingRequest.php:407-433`

- Before: the guard split int from float (`is_int($stored) => is_int($value)`).
- `json_encode(0.0)` emits `"0"` and `json_encode(1.0)` emits `"1"` — verified in
  the container — which decode back as **int**. Confirmed against the live dev
  DB: `loans.company_loan.annual_interest_rate` and
  `loans.cash_advance.annual_interest_rate` store `0`;
  all four `loans.*.max_salary_multiplier` store `1`.
- Effect: setting a company loan rate to `0.05`, or a salary multiplier to
  `1.5`, was rejected as a type change. Six loan settings were permanently
  uneditable to any fractional value.
- After: one shared helper treats every JSON number as one class. Int-versus-
  decimal is left to the 122 per-key `integer` rules that already exist, which
  is where a numeric contract belongs. The duplicated `match` in the FormRequest
  now calls the service helper — the duplicate is how the split survived.

**2. Audit redaction hid 69 of 420 settings keys, including security policy —
regression in the F-008 work**
`api/app/Common/Services/SettingsService.php:258-297`

- Before: `preg_match('/…|bank|routing|swift|ssn|tin|…/i', $key)` — a substring
  match against the whole key.
- `tin` is a substring of "accoun**tin**g", "forecas**tin**g", "budge**tin**g"
  and "ra**tin**g"; `bank`/`routing` matched GL account codes. Counted against
  the live dev DB: **69 of 420** keys matched, so every `accounting.*` settings
  change was audited as `***` — and `password` matched
  `security.password_min_length`, `security.password_expiry_days`,
  `security.password_history_depth`, hiding exactly the security-policy history
  F-008 exists to preserve.
- After: match the key's **last dot-segment** against an explicit segment list
  plus compound suffixes (`_password`, `_secret`, …). `company.tin` and
  `mail.smtp_password` still redact; `accounting.accounts.salary_expense_code`
  and `security.password_min_length` no longer do.

**3. `DB::table('users')->whereKey(...)` — every restore threw at its first
ledger write**
`api/app/Common/Services/BackupService.php:928-935` and `:1590-1593`

- `Illuminate\Database\Query\Builder` has no `whereKey()`, so the call fell
  through to `__call` and became a dynamic `where('key', …)` against a
  non-existent `users.key` column.
- Effect: `persistOperation()` and `auditFromSnapshot()` threw
  `SQLSTATE[42703] column "key" does not exist` on every invocation with a
  non-null `requested_by` — i.e. `runRestore()` died immediately after
  preflight, and `reconcileStaleOperations()` threw on every row it tried to
  reconcile. The restore path had never worked. Found by the new tests, not by
  reading.
- After: `where('id', …)`.

**4. Stale-lease reconciliation waited two full lease periods — residual F-004**
`api/app/Common/Services/BackupService.php:574-604`

- Before: `$threshold = now()->subSeconds($lease)` then
  `where('lease_expires_at', '<', $threshold)`. `lease_expires_at` is already
  `heartbeat + lease`, so this required **2 × lease** (4h at the default) before
  a dead worker's row released the singleton `active_lock`.
- Also `orWhereNull('lease_expires_at')` had no age guard, so a row momentarily
  without a lease was failed instantly.
- After: `lease_expires_at < now()`, with a separate grace window for null
  leases. Regression-tested.

**5. A successful restore permanently blocked the backup surface — new
discovery**
`api/app/Common/Services/BackupService.php:658-703` (new
`retireImportedActiveOperations()`), called at `:442` (restore) and `:767`
(rollback)

- `runBackup()` marks its row `running` with `active_lock` held, *then* shells
  out to `pg_dump`, and only completes the row afterwards. Every artifact
  therefore contains its own operation row, captured mid-run holding the
  singleton admission lock.
- Effect: restoring any admin-created backup re-imported that row.
  `rejectIfOperationActive()` then refused every subsequent backup and restore,
  and the next `active_lock` write collided with
  `backup_operations_active_lock_unique`. The recovery surface bricked itself on
  its first successful recovery. Combined with fix 4, recovery took 4 hours;
  before fix 3, it never happened at all because the restore could not complete.
- After: imported `queued`/`running` rows are retired to `failed` with the lock
  released and `metadata.retired_by_restore` recorded, after migrations, in both
  the restore and rollback paths. An imported `rollback_required` row is
  deliberately **not** cleared — it is an unresolved destructive-state signal,
  and an admin-created artifact cannot contain one. Both behaviours tested.

**6. The recovery catalog re-hashed every artifact on a 5-second poll —
regression in the F-005/F-009 work**
`api/app/Common/Services/BackupService.php:1155-1229`,
`:1488-1523` (cached remote probe), `api/config/backup.php:22-26`

- Before: `index()` → `decorateArtifact()` → `artifactAvailability()` →
  `assertArtifactIntegrity()` → `hash_file('sha256', …)` for every artifact of
  every listed operation, plus one `aws s3api head-object` subprocess (30s
  timeout each) per non-local artifact. The admin page polls every 5s while an
  operation runs, and cost scales with `backup.keep`.
- After: the list path verifies recorded **size** and reports
  `integrity: manifest_match` — never `checksum_verified`, because a list
  request cannot honestly claim more. SHA-256 stays enforced on the restore path
  (`assertArtifactAvailable()`, `materializeArtifact()`), which is the only place
  the verdict must be true of the bytes about to be replayed. Remote head-object
  results are cached for `backup.remote_probe_cache_seconds` (120s) on the
  catalog path only; restore preflight always reads through. New
  `describeLocalArtifact()` avoids hashing legacy artifacts that have no
  manifest to compare against.
- SPA contract updated in step with the honesty change:
  `spa/src/api/admin/backups.ts:10-12`, labels at
  `spa/src/pages/admin/backups.tsx:122-133`.

**7. Cursor pagination for the recovery catalog — plan item 7 (F-009)**
`api/app/Common/Services/BackupService.php:170-252`,
`api/app/Modules/Admin/Controllers/BackupController.php:14-27`,
`spa/src/api/admin/backups.ts:36-56`, `spa/src/pages/admin/backups.tsx:335-370,
496-510`

- Before: hardcoded `limit(50)` then `array_slice(…, 50)`; older history was not
  reachable.
- After: `cursorPaginate` on `created_at desc, id desc` with `per_page` (5–100,
  default 25) and `next_cursor`; SPA uses `useInfiniteQuery` with a "Load older
  operations" control. Legacy local artifacts are merged into the first page only
  and are **not** sliced back to `per_page` — slicing would drop a ledger row the
  cursor had already advanced past, skipping it on the next page too.
- Legacy entries now carry `manifest_committed: false`, `restorable: false` and a
  derived `availability`, so the response shape is uniform. They were previously
  missing those keys entirely.

**8. A failed backup left unexplained orphan artifacts — residual F-006**
`api/app/Common/Services/BackupService.php:311-327`, `markFailed()` at `:522-547`

- `db:full-backup` publishes the database dump before the private-files archive,
  so a second-phase failure leaves a real, usable dump on disk that surfaced only
  as an unexplained "legacy" archive.
- After: the failed operation records `metadata.orphan_artifacts`. The dump is
  deliberately **kept**, not quarantined — the audit recommended quarantine, but
  deleting a usable recovery point to tidy the ledger is the wrong trade. See
  "Questions" below.

### Documentation

`docs/RESTORE-DRILL.md:103-133` — added what the catalog does and does not claim
(size vs checksum, and why), the legacy/orphan artifact rules, and the imported
ledger-row behaviour with the operator command sequence.

### Verification

**Test database:** `ogami_test_r5`, created for this session. `ogami_test` was
never used.

**Blocker encountered (not this module, not caused by this session):**
`api/database/migrations/2026_08_25_210000_enforce_one_active_holiday_per_date.php:42`
runs `DROP INDEX IF EXISTS holidays_date_name_unique`, but on this schema that
name backs a UNIQUE **constraint**, so PostgreSQL refuses with
`SQLSTATE[2BP01] … cannot drop index … because constraint … requires it`. This
breaks `migrate:fresh`, and therefore `RefreshDatabase`, repo-wide. The file is
untracked and belongs to the attendance/holidays module. It was **not** edited,
moved, or deleted by this session; the coordinator is fixing it centrally.

First verification route (schema built manually with that one migration marked
applied, module test classes temporarily on `DatabaseTransactions`, then
restored to `RefreshDatabase`): 24 passed / 88 assertions for the two new
classes, then 7 passed / 22 assertions for the two pre-existing ones.

Second verification route, through the real `RefreshDatabase` → `migrate:fresh`
path during a window when another agent had that migration moved aside:

```
PASS  Tests\Feature\Admin\BackupControllerTest            (6 tests)
PASS  Tests\Feature\Admin\BackupRecoveryHardeningTest     (13 tests)
PASS  Tests\Feature\Admin\SettingsGovernanceTest          (11 tests)
PASS  Tests\Feature\Common\SettingsCacheTest              (1 test)

Tests:    31 passed (110 assertions)
Duration: 58.75s
```

This second run also proves `2026_08_25_220000_harden_backup_operations`
applies cleanly on a fresh database.

Both pre-existing test files were restored byte-identically
(`git diff --exit-code` clean on `BackupControllerTest.php` and
`SettingsCacheTest.php`). All four module test classes are on `RefreshDatabase`
in their final state.

Other checks:

- `php -l` clean on all 7 changed/added PHP files.
- `php artisan route:list --path=admin/backups` resolves 3 routes.
- `npx tsc --noEmit`: no errors in `spa/src/pages/admin/backups.tsx` or
  `spa/src/api/admin/backups.ts`. Four pre-existing errors remain in other
  modules' files (`CommandPalette.tsx`, `assets/detail.tsx`,
  `return-management/detail.tsx`) and were not touched.
- `npx eslint` clean on both changed SPA files.
- No real restore was executed. Every test stops at admission, ledger state, or
  serialization; none reaches `runRestore()`/`runBackup()`, so no destructive
  shell helper ran and the dev database `ogami` was only ever read.

### Deferred — and why

1. **F-002 / F-007 "prove the runtime" and the staging drill.** External. Needs a
   built `docker-compose.prod.yml` image and an isolated staging deployment to
   run an authenticated multi-container backup → restore → rollback drill with
   retained evidence. The pin and the image-build assertion are in place; the
   *proof* cannot be produced from this checkout, and building/running it here
   would risk the shared dev environment other agents depend on. The drill
   checklist and an honest "Not run" drill-log row are already recorded in
   `docs/RESTORE-DRILL.md`.
2. **Plan item 8's multi-container cases.** Same reason: maintenance-gate
   visibility across containers, real worker death, and S3 object behaviour need
   more than one process and a real bucket.
3. **F-006 per-run staging area with atomic pair publication.** Not implemented.
   Currently mitigated rather than solved: an unpaired artifact can never be
   restored (no committed manifest), and the orphan is now recorded on the failed
   operation. See the question below — the audit's own recommendation is not
   obviously the right one.

### Questions for a human

1. **Should a partially published backup be quarantined?** F-006 recommends
   staging both artifacts and quarantining incomplete runs. But if the database
   dump succeeded and only the private-files archive failed, that dump is a
   genuine, restorable recovery point. Quarantining it trades a real recovery
   option for ledger tidiness. This session recorded the orphan instead of
   removing it. Confirm the intended policy before implementing staging.
2. **Should an imported `rollback_required` row block new operations?** After a
   restore, such a row's recorded pre-restore artifacts describe a database that
   no longer exists. It is currently left holding the admission lock, which is
   safe but can only be cleared by an operator. Reachable only via a cron dump
   taken during an unresolved rollback.
3. **Out of scope, flagged not changed:** `api/database/seeders/SettingsSeeder.php`
   dropped the `inventory.allow_negative` key in someone else's working-tree
   change. If any inventory code still reads it, that is a cross-module break —
   it belongs to the inventory module, so it was left alone.
