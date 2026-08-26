# Fix Log — Platform / Audit Activity (M004)

Session: 2026-08-25  
Claim: `audit/scripts/claim-module.sh platform audit-activity`  
Final status: ✅ Verified

## 1. Canonical activity producers

- Added `RecordActivityFromEvent` at `api/app/Modules/Admin/Listeners/RecordActivityFromEvent.php:23-74` and registered the wildcard listener in `api/app/Providers/AppServiceProvider.php:199-200`. It projects durable module events plus chain and permission-override events into the existing activity feed, classifies transaction/approval/automation/alert types, resolves model subjects and links, records scalar event context, and logs projection failures without breaking the business event.
- Added `RecordAuthActivity` at `api/app/Modules/Admin/Listeners/RecordAuthActivity.php:14-50` and registered it as the `AuditLog` created observer. Existing `auth.event` rows now become activity entries with the original actor, IP, timestamp, action, severity, and audit-log link.
- Extended `ActivityFeedService::record()` at `api/app/Common/Services/ActivityFeedService.php:31-99` with actor/context overrides, idempotency keys, race-safe `insertOrIgnore`, and JSON-safe query-builder inserts. Event producers deduplicate through the unique key without mutating immutable rows.

## 2. Retention / partition decision

- Removed the dead `CreateAuditLogPartition` command because `audit_logs` is an ordinary table, not a partitioned table; no scheduler or code references remain.
- Added archive-only `activity:archive` at `api/app/Console/Commands/ArchiveActivityEvents.php:20-152`. It archives closed calendar months to locked gzipped JSON files, validates the archive, is rerun-safe, and retains source rows.
- Scheduled activity archiving at `api/routes/console.php:232-237` for 04:05, after the existing audit archive. The archive-only decision is documented in `docs/SCHEMA.md:60-69`.

## 3. Date boundaries and input validation

- Activity `from`/`to` filters now use start/end-of-day boundaries in `api/app/Common/Services/ActivityFeedService.php:121-155`.
- Audit list, CSV/PDF export, and entity-trail inputs are validated and date-only `to` filters include the selected day in `api/app/Modules/Admin/Controllers/AuditLogController.php:146-172` and `244-266`.
- Activity `type` is validated against `ActivityType` in `api/app/Modules/Admin/Controllers/ActivityFeedController.php:29-43`.

## 4. Audit API contract and identifier handling

- Added actor type, source command, correlation ID, reason, and canonical diff metadata to list/detail/entity responses through `api/app/Modules/Admin/Resources/AuditLogResource.php:10-36`, `api/app/Modules/Admin/Controllers/AuditLogController.php:54-90`, and `api/app/Modules/Admin/Support/AuditDiffBuilder.php:8-58`.
- Detail, list, entity, CSV, and PDF surfaces now expose hashed public record IDs; raw numeric decoding is testing-only in `api/app/Modules/Admin/Controllers/AuditLogController.php:259-266`.
- Updated the SPA contract in `spa/src/api/admin/audit-logs.ts:3-45`; audit detail/entity rendering uses the shared diff labels/types and masks encrypted values.

## 5. Complete entity trails

- Added `page`/`per_page` support to the entity API and SPA client, URL-backed Previous/Next controls, and loading/error/empty/data states in `spa/src/pages/admin/audit-logs/entity.tsx:80-223`.
- Entity and detail views now consume the same backend diff metadata, including money/date/enum/datetime formatting and encrypted-field handling.

## 6. Test coverage

- Added `api/tests/Feature/Admin/AuditActivityTest.php:18-181` covering idempotent writes, date boundaries, event classification/replay, auth projection, hashed IDs and CSV output, entity pagination/diff metadata, enum validation, and model immutability.
- Existing `AuditLogSearchTest` and `AuthEventsAuditTest` remain green with the new observer and public-ID behavior.

## 7. Activity-event integrity

- Added `idempotency_key` and PostgreSQL update/delete triggers in `api/database/migrations/0475_harden_activity_events.php:12-63`.
- Added Eloquent append-only guards in `api/app/Common/Models/ActivityEvent.php:21-62`.
- `activity:archive` retains source rows; a no-op `--months=999` run completed successfully.

## 8. Investigation UI

- Added actor and date filters to `spa/src/pages/admin/audit-logs/index.tsx:61-103`.
- Fixed the detail label typo and added audit context rows in `spa/src/pages/admin/audit-logs/detail.tsx:174-211`.
- Preserved list/timeline loading, error, empty, data, and permission-gated states.

## Verification evidence

- `APP_ENV=testing php artisan test --filter=AuditActivityTest`: **8 passed, 28 assertions**.
- `APP_ENV=testing php artisan test --filter=AuditLogSearchTest`: **8 passed, 22 assertions**.
- `APP_ENV=testing php artisan test --filter="AuthEventsAuditTest|MaterialDetailAuditTest"`: **9 passed, 42 assertions**.
- `APP_ENV=testing php artisan migrate:status`: migration `0475_harden_activity_events` **Ran**.
- `APP_ENV=testing php artisan schedule:list`: audit archive at 04:00 and activity archive at 04:05 confirmed.
- Host-side SPA `tsc --noEmit --pretty false --incremental false`: **passed**; targeted ESLint for all four changed audit SPA files: **passed**.
- PHP syntax checks for all new/modified PHP implementation files: **passed**; `git diff --check`: **passed**.
- Pint could not load the repository path because pre-existing `api/pint.json` is a directory; no formatting changes were applied outside this module.

---

# Session 2026-08-27 — verification session (the "separate session" items 1/2/7 waited for)

Claim: `bash audit/scripts/claim-module.sh platform audit-activity 0` → **CLAIMED** (orphan lock reclaimed).

## Why the 2026-08-25 evidence above did not hold

Every command in the block above was run as `APP_ENV=testing php artisan test …`. The
suite is *not* invoked that way — `.github/workflows/api-tests.yml:125` sets
`APP_ENV: testing` as a job-scoped process variable, but a local `php artisan test`
inherits `APP_ENV=local` from `docker-compose.yml:12`. Prefixing the command changed
the one value the entity-trail endpoint branches on, so the 2026-08-25 run measured a
configuration the suite never uses. Items 1–8 were *implemented*; three of their tests
were **unverified**, and failed the moment the suite ran normally.

## 9. Entity-trail 422 — the tests sent an identifier shape production never sends

**Cause (one, shared by all three failures).** `AuditLogController::entityTrail()` decodes
`model_id` through `decodePublicId()` at
`api/app/Modules/Admin/Controllers/AuditLogController.php:158-159`, and
`decodePublicId()` (`:259-266`) accepts a raw integer **only** when
`app()->environment('testing')`. That branch never fires locally:

| source | `APP_ENV` |
|---|---|
| `$_SERVER['APP_ENV']` (docker-compose.yml:12) | `local` |
| `$_ENV['APP_ENV']` (phpunit.xml `<env force="true">`) | `testing` |
| `getenv('APP_ENV')` (same, via putenv) | `testing` |
| **resolved `config('app.env')`** | **`local`** |

PHPUnit's `<env force="true">` writes `$_ENV` and `putenv()` but not `$_SERVER`, and
phpdotenv's `RepositoryBuilder::createWithDefaultAdapters()` puts `ServerConstAdapter`
ahead of `EnvConstAdapter`, so `$_SERVER` wins. Measured, not inferred (throwaway probe
test, since deleted): status 422, message `"Invalid model_id"`, `app()->environment()`
= `local`. The rule is therefore **right** and the **tests were wrong** — they passed
raw integers and leaned on an escape hatch that only fires in CI.

The production contract is hash IDs on both sides: `AuditLogResource:18` emits
`app('hashids')->encode(...)`, `spa/src/api/admin/audit-logs.ts:20` types `model_id` as
`string | null`, and `spa/src/pages/admin/audit-logs/entity.tsx:7` documents the query
param as "model_id (hashid)". Audit-report finding F-06 called this out; plan item 4
asked for exactly this contract.

- **before** `api/tests/Feature/Admin/AuditLogSearchTest.php:86` —
  `…/entity?model_type=PurchaseOrder&model_id=42`
  **after** `:98` — `…&model_id='.app('hashids')->encode(42)`
- **before** `api/tests/Feature/Admin/AuditLogSearchTest.php:123` — `&model_id=999999`
  **after** `:140` — `&model_id='.app('hashids')->encode(999999)`
- **before** `api/tests/Feature/Admin/AuditActivityTest.php:160` — `&model_id=42&per_page=25`
  **after** `:163` — `&model_id='.app('hashids')->encode(42).'&per_page=25`
- **before** `api/tests/Feature/Admin/AuditLogSearchTest.php:109` (`requires_both_params`,
  missing-`model_type` leg) — `&model_id=42`, which 422s on the *identifier* rather than
  the missing param, so the assertion proved nothing.
  **after** `:118-123` (call at `:123`) — hashed `model_id`, leaving `model_type => required` as the only
  rule that can fail.

`decodePublicId()`'s testing branch is left in place (plan item 4 asked to "preserve
testing-only compatibility"); after this change nothing in the suite depends on it. See
the open question below — it is dead locally and live in CI.

## 10. Last active system admin — fixture leak, guard intact (module `platform/user-administration`, coordinator-authorised)

**Verdict: the guard is present and correct; the fixture stopped reaching it.**
`UserAdminService::deactivate()` gates on
`api/app/Modules/Admin/Services/UserAdminService.php:218-220` and
`assertAtLeastOneActiveSystemAdminRemains()` at `:571-587` throws
`BusinessRuleException('At least one active system administrator must remain.')`. The
last system_admin **cannot** be deleted — no product defect.

`UserAdministrationHardeningTest` passes in isolation (7/7). It fails only after
`RbacConcurrencyTest` has run in the same process. That class declares
`protected array $connectionsToTransact = [];` at
`api/tests/Feature/Admin/RbacConcurrencyTest.php:31`, which turns RefreshDatabase's
per-test transaction **off** (it forks, so fixtures must be visible on a second
connection), and its `cleanupConcurrencyFixtures()` at `:281-302` deletes
`notifications`, `user_permission_overrides` and `role_permissions` but never the
`users`/`roles` rows it created. Reproduced on a private database:

```
$ php artisan test --filter='RbacConcurrencyTest|UserAdministrationHardeningTest'
  PASS  RbacConcurrencyTest (2)
  FAIL  UserAdministrationHardeningTest
  ⨯ last active system admin cannot be removed
  Failed asserting that exception of type "App\Common\Exceptions\BusinessRuleException" is thrown.

$ psql -d ogami_platform_audit -c 'select u.id,u.email,u.is_active,r.slug from users u …'
 1 | concurrency-admin-6a8f6ae85f6d3@test.local  | t | system_admin
 2 | concurrency-target-6a8f6ae861cbc@test.local | t | concurrency_employee_…
```

`R` sorts before `U` in `tests/Feature/Admin`, so the full suite always hits this order.
With two active system admins, deactivating one is correctly **allowed**, so the guard
was right not to throw — the test's premise had been silently removed.

- **before** `api/tests/Feature/Admin/UserAdministrationHardeningTest.php:53-60` — created
  two admins and assumed no others existed.
- **after** `:53-86` — resolves the system_admin role id, clears `is_active` on every
  pre-existing system_admin **inside this test's own transaction** (rolled back, so no
  other test is affected), then asserts the precondition
  (`assertSame(1, …active system admins…)`) before `expectException`. The
  `expectException(BusinessRuleException::class)` assertion is unchanged and the test is
  now strictly stronger: it proves the premise it depends on.

The leak itself is **not fixed** — see the open question below. It is in
`platform/rbac`, and hard-deleting the leaked rows is blocked by the audit-integrity
triggers, so the remedy is a judgement call, not a mechanical patch.

## Status of the eight plan items, re-derived by execution rather than by reading the log

Everything was already implemented in source; this session was about running it. Private
database `ogami_platform_audit`, plain `php artisan test` (no `APP_ENV` prefix — that is
the point).

| # | item | state |
|---|---|---|
| 1 | activity producers | ✅ verified — `AuditActivityTest` domain-event + auth-projection cases pass |
| 2 | retention/partition decision | ✅ verified — `CreateAuditLogPartition` has zero references left in `api/`; `activity:archive` scheduled at `api/routes/console.php:263` (monthlyOn 1 @ 04:05) |
| 3 | date boundaries + input validation | ✅ verified — `date_only_to_filter_includes_the_selected_day`, `activity_endpoint_rejects_unknown_type` |
| 4 | audit API contract | ✅ verified — `audit_api_exposes_context_and_hashes_model_id` (was passing); entity-trail identifier contract fixed above (was **failing**) |
| 5 | complete entity trails | ✅ verified — `entity_trail_is_paginated_and_uses_shared_diff_metadata` (was **failing**, now green) |
| 6 | activity-feed coverage | ✅ verified — 8/8 in `AuditActivityTest` |
| 7 | activity-event integrity | ✅ verified at the database, not just the model — see trigger probe below |
| 8 | investigation UI | ⚠️ carried over as verified-by-source. No SPA file was touched this session and the `spa` container is deliberately not running (4-agent RAM budget), so the prior host-side `tsc`/ESLint evidence stands unre-run. |

## Verification evidence (2026-08-27)

```
$ php artisan test --filter='AuditLogSearchTest'
  PASS  Tests\Feature\Admin\AuditLogSearchTest — Tests: 8 passed (22 assertions)

$ php artisan test --filter='AuditActivityTest'
  PASS  Tests\Feature\Admin\AuditActivityTest — Tests: 8 passed (28 assertions)

$ php artisan test --filter='RbacConcurrencyTest|UserAdministrationHardeningTest'
  PASS  Tests\Feature\Admin\RbacConcurrencyTest (2)
  PASS  Tests\Feature\Admin\UserAdministrationHardeningTest (7)
  Tests: 9 passed (34 assertions)          # ← ordering-sensitive case, now green

$ php artisan test --filter='AuthEventsAuditTest|MaterialDetailAuditTest|PruneAuditLogsTest'
  Tests: 12 passed (52 assertions)
```

Item 7, proven against PostgreSQL rather than against the Eloquent guard:

```
$ psql -d ogami_platform_audit -c "select event_object_table, trigger_name, event_manipulation
                                   from information_schema.triggers where event_object_table
                                   in ('audit_logs','activity_events')"
 activity_events | activity_events_prevent_delete | DELETE
 activity_events | activity_events_prevent_update | UPDATE
 audit_logs      | audit_logs_prevent_delete      | DELETE
 audit_logs      | audit_logs_prevent_update      | UPDATE

$ update activity_events set summary='mutated' where action='trigger_probe';
ERROR:  Activity events are immutable.
$ delete from activity_events where action='trigger_probe';
ERROR:  Activity events are immutable.

activity_events.idempotency_key varchar(128), UNIQUE CONSTRAINT activity_events_idempotency_key_unique
```

`php -l` clean on all three modified test files; `git diff --check` clean. The full suite
was **not** run (4 agents on a 3.7 GiB host; the stack was OOM-killed doing exactly that
yesterday).

## Open questions for the coordinator — decisions, not fixes

Neither is mine to take: one edits a repo-wide shared file, the other belongs to
`platform/rbac` and trades off against the audit-integrity triggers.

### Q1 — the suite runs with `app.env = 'local'`, so every `environment('testing')` branch is dead locally and live in CI

Established above. This is not confined to the audit module: `HasHashId::resolveRouteBinding()`
(`api/app/Common/Traits/HasHashId.php:33`) and
`resolveSoftDeletableRouteBinding()` (`:58`) carry the same
`app()->environment('testing') && ctype_digit(...)` branch, so **any** test anywhere that
routes by a raw integer passes in CI and 404s/422s locally. That is a systematic
local↔CI divergence on identifier handling.

Note the block comment at `api/phpunit.xml` (finding F-042) asserts that
`force="true"` resolves this. Measured, it does not — `force` writes `$_ENV` and
`putenv()`, never `$_SERVER`, and `$_SERVER` is the adapter phpdotenv consults first.
That comment should be corrected whichever option is chosen.

- **Option A — make local match CI.** Add `<server name="APP_ENV" value="testing" force="true"/>`
  alongside the existing `<env>` in `api/phpunit.xml`. One line, and every testing-only
  branch starts firing locally. Blast radius is the whole 2400-test suite: any test that
  currently passes *because* `app.env` is `local` would flip. Must not be done by a
  module session mid-audit, and not while 4 agents share the tree.
- **Option B — delete the escape hatch.** Remove the `environment('testing')` branches
  and require HashIDs everywhere, tests included. Removes the divergence permanently and
  matches the stated project rule ("NEVER expose integer IDs"), but is a wide test-suite
  migration and would flip CI-only passes into failures that have to be worked through.
- **Option C — leave it, document it.** Cheapest; keeps a trap where a test can be green
  in CI and red locally for reasons unrelated to the code under test. Audit-report F-06
  already flags this class of inconsistency.

This session took neither: it made the audit module's three tests assert the production
contract, so they now pass under both resolutions. That is compatible with A, B and C.

### Q2 — `RbacConcurrencyTest` commits fixtures it never removes

`api/tests/Feature/Admin/RbacConcurrencyTest.php:31` disables the RefreshDatabase
transaction (legitimately — it forks and the child needs a second connection to see the
rows), and `cleanupConcurrencyFixtures()` at `:281-302` cleans three child tables but
leaves the `users` and `roles` rows committed for the remainder of the PHPUnit process.
Cost so far: it silently disarmed the last-system-admin guard test. The next cost is
unpredictable — any later test that **counts** users or roles is exposed, and the row set
grows with every concurrency test added.

The obvious remedy is blocked. Hard-deleting the leaked `users` rows fails: `audit_logs.user_id`
is `ON DELETE SET NULL`, and that cascade is an UPDATE on `audit_logs`, which
`audit_logs_prevent_update` refuses. The `audit_logs` rows cannot be deleted first either
(`audit_logs_prevent_delete`). Neither trigger may be loosened — they are the
audit-integrity guarantee.

- **Option A — soft-delete the fixture users in `cleanupConcurrencyFixtures()`.**
  `update(['is_active' => false, 'deleted_at' => now()])` is an UPDATE on `users`, touches
  no audit row, and removes them from every default-scoped query. Small and targeted.
  Caveat: do **not** extend this to `roles` — `fixtureRoleIds` can contain the
  `system_admin` role (it is `firstOrCreate`d at `:66-69`, so `wasRecentlyCreated` is true
  in that class), and soft-deleting it would hide the row from a later
  `RolePermissionSeeder` while `roles.slug`'s unique index still sees it, turning a
  silent-count bug into a hard unique-violation across the suite.
- **Option B — restructure the class to clean up by truncating into a savepoint**, or to
  create its fixtures via a dedicated non-audited path. Larger, and needs the fork
  semantics re-derived.
- **Option C — treat per-test premises as each test's own responsibility.** This session's
  change to `UserAdministrationHardeningTest` is that, applied to the one known victim.
  It leaves the leak in place for the next victim to discover.

Recommended: A (plus keeping this session's C for the guard test, since a test asserting
"the LAST active admin" should own that premise regardless).



## Pending — why this session closes as 🔁 Needs Re-audit rather than ✅ Verified

The four reported test failures are fixed and green, and 7 of the 8 plan items were
verified by execution rather than by reading source. Three things remain open, so the
honest status is not "Verified":

1. **Plan item 8 (investigation UI) was not re-verified this session.** No SPA file was
   touched, and the `spa` container is intentionally down for the 4-agent RAM budget, so
   `tsc`/ESLint/browser evidence is carried over from 2026-08-25 — the same session whose
   *backend* evidence turned out to have been produced under a non-representative
   `APP_ENV`. The frontend evidence is not implicated by that defect (it ran host-side and
   does not depend on `app.env`), but it has not been re-run, so it should not be counted
   as this session's verification.
2. **Q1 — `app.env` resolves to `local` for the whole local suite.** Repo-wide
   local↔CI divergence on identifier handling. Needs a ruling; `api/phpunit.xml` is a
   shared file and must not be edited by a module session while 4 agents share the tree.
3. **Q2 — `RbacConcurrencyTest` leaks committed `users`/`roles` rows.** A live ordering
   hazard for any test in the suite that counts users or roles. Owned by `platform/rbac`;
   the obvious remedy is blocked by the audit-integrity triggers, so it needs a decision.

Nothing above is a defect in the audit-activity module's own product code.
