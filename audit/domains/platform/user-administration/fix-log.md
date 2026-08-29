# M003 — User Administration Fix Log

Implementation pass: 2026-08-25  
Release status: `🔁 Needs Re-audit`

All nine planned implementation items were addressed in this session. The
release remains `Needs Re-audit` only because the live browser role-permission
run was inconclusive after the local Nginx/SPA/Reverb containers were killed
mid-run; the backend and static verification gates are green.

## 1. Privileged-role escalation and administrator lockout — UA-B01, UA-B02

- Before: delegated managers could submit `system_admin` assignments, and
  self-targeting/last-active-system-admin mutations were not consistently
  protected.
- After: user-management service methods lock target and role rows, restrict
  `system_admin` assignment and system-admin target management, reject
  self-role changes and self-deactivation, and require at least one active
  system administrator to remain. See
  `api/app/Modules/Admin/Services/UserAdminService.php:143-300,554-586`.
- Verification: delegated assignment, self-deactivation, and last-admin tests
  pass in `UserAdministrationHardeningTest`.

## 2. Recoverable password reset delivery — UA-B03

- Before: a reset could leave the administrator without a recoverable
  delivery path when notification delivery failed.
- After: the reset uses the provisioning service's fallback path and returns a
  one-time temporary credential through the controlled admin response, with
  explicit approved-channel messaging in
  `api/app/Modules/Admin/Controllers/UserAdminController.php:133-145`.
- Verification: `UserAdministrationHardeningTest::test_reset_returns_one_time_credential_and_writes_audit`
  passes and confirms the credential matches the stored password.

## 3. Administrative mutation audit coverage — UA-I01

- Before: create, unlock, activate, deactivate, and reset actions did not
  share a complete actor/target/old-new/reason audit contract.
- After: `RbacAuditService` centralizes actor, source, correlation, request
  metadata, old/new snapshots, and reason capture; lifecycle, role, profile,
  and reset mutations use it. Bulk role changes retain their multi-target
  conflict/missing detail in the audit row. See
  `api/app/Modules/Admin/Services/RbacAuditService.php:19-84` and
  `api/app/Modules/Admin/Services/UserAdminService.php:187-483`.
- Verification: password-reset and profile-correction audit assertions pass;
  the existing role/override audit tests also pass.

## 4. Hashed IDs and stale/deleted references — UA-I02

- Before: stale role/department filters could silently produce empty results,
  and bulk updates did not distinguish missing decoded users from invalid
  hashes.
- After: list requests and the service reject unavailable role/department
  references with stable validation/business-rule responses; bulk responses
  return `conflicts`, `missing`, and `invalid_ids`. See
  `api/app/Modules/Admin/Requests/ListUsersRequest.php:20-56`,
  `api/app/Modules/Admin/Services/UserAdminService.php:88-132,318-420`, and
  `api/app/Modules/Admin/Controllers/UserAdminController.php:151-174`.
- Verification: the missing-decoded-user hardening test and existing
  concurrency tests pass.

## 5. User-management and override authority reconciliation — UA-B04, UA-I03, UA-I04

- Before: role assignment/permission override authority was coupled
  implicitly, and deleted override restoration was not reachable or fully
  scoped.
- After: `/admin/users/options` is available at the user-management boundary;
  override routes require `RequireSystemAdmin` plus the explicit permission,
  restore/delete use trashed binding, and controllers verify the route user is
  the override parent. The UI uses the same system-admin guard and supports
  showing/restoring removed rows. See
  `api/app/Modules/Admin/Middleware/RequireSystemAdmin.php:12-21`,
  `api/app/Modules/Admin/routes.php:55-85`, and
  `spa/src/pages/admin/users/_components/PermissionOverrides.tsx:52-207`.
- Verification: override authorization/restore tests, options authorization
  tests, RBAC static audit, and API route audit pass.

## 6. Deliberate role-change interaction — UA-I05

- Before: role changes could be submitted without confirmation or a reason.
- After: the request requires a five-character reason, the detail page opens a
  confirmation modal, shows the target role, preserves optimistic-concurrency
  checks, and reports conflicts. See
  `api/app/Modules/Admin/Requests/ChangeUserRoleRequest.php:17-45` and
  `spa/src/pages/admin/users/detail.tsx:115-127,376-431`.
- Verification: stale-role and role-audit concurrency tests pass; SPA
  typecheck, lint, and production build pass.

## 7. Admin profile correction contract — UA-I06

- Before: the account detail surface had no correction path for standalone
  user identity data.
- After: standalone accounts can update validated unique name/email values via
  `PATCH /admin/users/{user}/profile`; linked employee accounts receive the
  supported employee-profile correction message. The mutation is audited. See
  `api/app/Modules/Admin/Requests/UpdateUserProfileRequest.php:10-40`,
  `api/app/Modules/Admin/Services/UserAdminService.php:460-485`, and
  `spa/src/pages/admin/users/detail.tsx:433-477`.
- Verification: standalone profile correction and audit assertions pass.

## 8. Users & Roles hub consolidation — UA-I07

- Before: `/admin/users-roles` exposed a broader legacy hub guard than its
  individual tabs.
- After: the legacy route redirects to the canonical permission-aware Users
  page; role administration remains on its dedicated route. See
  `spa/src/routes/dashboardRoutes.tsx:110`.
- Verification: SPA route audit, typecheck, lint, and production build pass.

## 9. Frontend polish and filter completeness — UA-P01, UA-P02, discovery gap

- Before: visible `LuUser` icon names appeared in user-facing copy, role
  loading failures were opaque, and the supported department filter was not
  exposed on the canonical Users page.
- After: labels use user-facing terminology, role/options queries expose
  loading/error/retry states, and role, department, and status filters are
  sourced from `/admin/users/options`. See
  `spa/src/pages/admin/users/index.tsx:53-90,162-207` and
  `spa/src/pages/admin/users/create.tsx:30-105`.
- Verification: no `LuUser` user-facing copy remains in the touched surfaces;
  SPA typecheck, lint, and production build pass.

## Verification record

- PASS — PHP syntax check for all `api/app/Modules/Admin` files.
- PASS — Pint check for changed PHP files.
- PASS — targeted backend suite inside Compose: **27 tests, 110 assertions**.
- PASS — `npm run typecheck` and `npm run lint -- --no-warn-ignored`.
- PASS — `npm run build` inside the SPA container (`vite v7.3.6`, 7,990
  modules transformed).
- PASS — `npm run audit:rbac` and `npm run audit:api-routes`.
- PASS — admin route listing and `git diff --check`.
- INCONCLUSIVE — `npm run audit:role-permissions`: the local web gateway and
  SPA/Reverb containers exited with environment-level connection/reset errors
  during the 13-role run; a focused read-only check also could not establish a
  stable seeded login session. Re-run this browser audit against a stable
  local or preview stack before marking the module `✅ Verified`.

## Interrupted-session recovery

The parallel implementation worker terminated before completing Step 8. Preserve the existing fixes and the inconclusive browser gate, but keep this module at Needs Re-audit until the current worktree and the action plan are rechecked and the remaining verification is recorded.

---

## Re-audit pass: 2026-08-30

Release status: `🔁 Needs Re-audit`

Context correction for the note above: the interrupted 2026-08-25 session did
**not** leave work stranded. All nine items were already committed (swept into
`167de85e`), and re-measurement on 2026-08-30 confirms 9 of 9 no longer
reproduce. This pass therefore did not re-implement anything; it measured, added
a regression lock, and fixed the two genuinely contained items it found. The
remaining nine new findings are gated in `action-plan.md` — see that file for how
Tier 1 cross-module risk was weighted.

### R1. `RbacConcurrencyTest` committed an ACTIVE `system_admin` that disarmed later tests — UA-I09

- **Before** — `api/tests/Feature/Admin/RbacConcurrencyTest.php` set
  `protected array $connectionsToTransact = []` (`:31`) to make its fixtures
  visible across a `pcntl_fork`, so every row it wrote was COMMITTED, and it never
  reset `RefreshDatabaseState::$migrated`. `cleanupConcurrencyFixtures()` (`:283`)
  cannot delete its `users` rows — they are referenced by `audit_logs`, which
  carries an append-only trigger (`SQLSTATE[P0001] Audit logs are immutable.`,
  measured while writing the probe) — so an active `system_admin`
  (`concurrency-admin-…@test.local`) survived for the rest of the PHPUnit process.

  Measured directly, on a fresh database:

  ```
  $ php artisan test tests/Feature/Admin/RbacConcurrencyTest.php
    Tests: 2 passed (13 assertions)
  $ psql -d ogami_test_useradm -c 'SELECT u.id,u.email,u.is_active,r.slug FROM users u LEFT JOIN roles r ON r.id=u.role_id'
    1 | concurrency-admin-6a93632171b86@test.local  | t | system_admin
    2 | concurrency-target-6a936321739ce@test.local | t | concurrency_employee_…
  ```

  Because the class sorts before every `User*` class in `tests/Feature/Admin`, any
  later test whose premise is "no active system administrator" passed for the
  wrong reason. `UserAdministrationHardeningTest:57-73` documents and works around
  it; it also caused a false failure in the Assets module.

- **After** — added `tearDownAfterClass()` at
  `api/tests/Feature/Admin/RbacConcurrencyTest.php:51-91` (plus the
  `RefreshDatabaseState` import at `:16`), so the next `RefreshDatabase` class
  re-runs `migrate:fresh` and the committed rows go at the schema level instead of
  fighting the audit trigger. Same remedy as
  `api/tests/Feature/Accounting/AccountingPeriodPostingConcurrencyTest.php:61-66`.
  The docblock records why deleting the rows is not an option.

- **Verification — red before, green after, in the order that was red.** The new
  `UserAdministrationEscalationTest::test_last_active_system_admin_survives_every_admin_module_verb`
  asserts the premise instead of forcing it, so it is the drift guard for this
  defect. Against unmodified source:

  ```
  $ php artisan test tests/Feature/Admin/RbacConcurrencyTest.php \
                     tests/Feature/Admin/UserAdministrationEscalationTest.php
   FAILED  UserAdministrationEscalationTest > last active system admin survives…
   Premise: exactly one ACTIVE system administrator must exist. A committed row
   from another test class (see RbacConcurrencyTest) silently disarms this assertion.
   Failed asserting that 2 is identical to 1.
    Tests: 2 failed, 11 passed (99 assertions)
  ```

  Same two files, same order, after the fix:

  ```
    Tests: 13 passed (117 assertions)
  ```

- **`UserAdministrationHardeningTest` workaround rechecked, kept, comment
  corrected.** The pre-emptive
  `User::query()->where('role_id',$systemRoleId)->update(['is_active'=>false])`
  at `:73` is now redundant for an `Admin`-directory run, but it is what makes
  that test's premise self-owned rather than dependent on another class's
  teardown, so removing it would trade a real guarantee for tidiness. Its comment
  claimed `cleanupConcurrencyFixtures()` simply "does not delete the users rows",
  which understates the situation (it *cannot*), and described the leak as
  outstanding. Rewritten at `:57-79` to state that the leak is fixed at source,
  why the write is retained anyway, and that
  `UserAdministrationEscalationTest` is the class that fails loudly if the leak
  returns.

- Related, **reported not fixed** (belongs to Accounting):
  `api/tests/Feature/Accounting/AccountingPeriodDuplicateRecoveryTest.php:39`
  calls `DB::commit()` with no `RefreshDatabaseState::$migrated` reset either.

### R2. `LuUser` still reached the screen on the empty users list — UA-P04

- **Before** — `spa/src/lib/emptyStateCopy.ts:335`:
  `actionLabel: 'Add First LuUser'`, under the `'/admin/users'` key. The
  2026-08-25 pass fixed the three page files and missed the shared registry, so
  the icon identifier was still rendered as the primary call-to-action button —
  but only when the list has zero rows, which is why it survived a visual check.
  `ListEmptyState.tsx:60-64` renders `copy.actionLabel` verbatim;
  `spa/src/pages/admin/users/index.tsx:212` mounts it for the empty case.
- **After** — `actionLabel: 'Add First User'`.
- **Verification** — `npm run typecheck` clean;
  `npx eslint src/lib/emptyStateCopy.ts --max-warnings 0` clean.
- Deliberately **not** touched: `header: 'LuUser'` at
  `spa/src/pages/admin/sessions.tsx:24` is on `/admin/sessions`, outside this
  module.

### R3. Regression lock added

`api/tests/Feature/Admin/UserAdministrationEscalationTest.php` (new, 13 tests /
117 assertions) pins the measurements from this pass so the closed escalation
paths cannot silently reopen:

- every path a delegated `admin.users.manage` holder could take toward
  `system_admin` (create / role / bulk-role / self-role / self-lateral), plus
  every verb against an existing administrator including `profile`
- per-user overrides staying system-admin-only even for a holder of
  `admin.users.manage_permissions`
- four non-privileged roles refused on the read surface
- every last-administrator verb the Admin module exposes, **premise asserted**
- role revocation and override revocation biting on the next request
- a soft-deleted user failing to authenticate
- no raw integer id in list payloads, detail payloads, or 404 bodies
- `sort`/`direction` whitelisting — the `docs/PATTERNS.md:262-268` bug
- actor + IP + user agent + reason on all six audited lifecycle mutations
- admin reset forcing a change, clearing the lock, and writing the superseded
  hash to `password_history`

**Honesty label:** exactly **1 of the 13** goes red against unmodified source
(the last-administrator premise test, shown above). The other 12 pass either
way — they are regression locks over already-correct behaviour, not proofs of a
bug, and are labelled as such rather than presented as fixes.

### Verification record — 2026-08-30

Isolated database `ogami_test_useradm` (created and dropped by this session);
`db` and `redis` were already up and were not restarted.

- PASS — `tests/Feature/Admin` (whole directory, to prove the `migrate:fresh`
  reset in R1 breaks nothing downstream): **149 tests, 614 assertions.**
- PASS — `php -l` on all three changed PHP files.
- PASS — `phpstan analyse` on the changed PHP files: no errors.
- PASS — `pint --test` on `UserAdministrationEscalationTest.php` and
  `UserAdministrationHardeningTest.php`.
- INHERITED, proven — `pint --test RbacConcurrencyTest.php` fails on
  `single_quote, unary_operator_spaces, not_operator_with_successor_space,
  ordered_imports`. Running Pint against the `git show HEAD:` extract of that
  file returns the **identical** fixer list, so this pass introduced no new
  violation and the file was not reformatted.
- PASS — `npm run typecheck`; `npx eslint src/lib/emptyStateCopy.ts --max-warnings 0`.
- NOT RUN — `npm run audit:role-permissions`. Still the standing blocker on
  `✅ Verified`; it needs the Nginx/SPA stack, which is intentionally down for
  this pipeline.
- Throwaway probe files (`ZzUserAdminProbeTest.php`,
  `ZzLastAdminRaceProbeTest.php`) were used for the measurements in the audit
  report and deleted; `git status` confirms neither remains.
