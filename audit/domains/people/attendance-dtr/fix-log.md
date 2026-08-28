# M018 — Attendance & DTR fix log

Audit date: 2026-08-24  
Implementation session: 2026-08-25  
Module status: 🔁 Needs Re-audit

## Session record

- Refreshed the registry and atomically claimed `people / attendance-dtr` as an unlocked `📋 Plan Ready` module at Tier 4, upstream of remaining people/platform consumers.
- Read the existing audit report, action plan, and fix log in full. Git history and module-path inspection showed no Attendance source changes after the report that would invalidate the plan.
- Continued directly into the existing plan as required for a resumed Plan Ready module.

## Fixed findings

### M018-F01 — OT decision authorization

- `api/app/Modules/Attendance/Services/OvertimeDecisionPolicy.php:21-57` adds the authoritative actor policy: system admin/HR all-record access, department-head same-department scope, and self-decision denial.
- `api/app/Modules/Attendance/Services/OvertimeService.php:205-309` locks the OT request and target employee inside approve/reject transactions and invokes the policy before mutation; bulk approval continues through the same approve path at `:256-274`.
- `api/app/Modules/Attendance/Controllers/OvertimeController.php:45-123` aligns detail/cancel visibility with the all-record policy and preserves 422 business-rule responses.
- `api/tests/Feature/Attendance/AttendanceHardeningTest.php:34-74` covers cross-department approve/reject/bulk denial and same-department success.

Before: list/detail filtering was the only department boundary; a caller with the approval permission and a valid hash could reach approve/reject/bulk service mutation.  
After: authorization is repeated against locked, current employee data inside each decision transaction.

### M018-F02 — Locked-payroll attendance mutability

- `api/app/Modules/Attendance/Services/AttendanceDateMutabilityGuard.php:22-87` centralizes employee/date scope matching and Finalized/Disbursed/Voided payroll locking, locking overlapping periods before the write continues.
- `api/app/Modules/Attendance/Services/AttendanceService.php:92-156` applies the guard to manual create/update/delete/restore and recomputation, with authoritative row locks.
- `api/app/Modules/Attendance/Services/DTRImportService.php:99-113` and `:228-245` apply the same guard inside paired and raw per-row write transactions.
- `api/tests/Feature/Attendance/AttendanceHardeningTest.php:76-137` covers manual mutation, restore, paired CSV, and Voided-period blocking. `api/tests/Feature/Attendance/RawPunchImportTest.php:114-149` retains finalized raw-import blocking, and `:172-202` checks the per-day transactional guard.

Before: manual/paired writes had no payroll fence; raw import used a one-time Finalized/Disbursed snapshot and omitted Voided.  
After: every Attendance write path shares the enum-aligned lock fence and rechecks within its own write transaction.

### M018-F03 — Archive restore and holiday cache

- `api/app/Modules/Attendance/routes.php:18,30,39` binds soft-deleted shifts, holidays, and attendances for restore routes.
- `api/app/Modules/Attendance/Controllers/AttendanceController.php:67-71`, `ShiftController.php:59-63`, and `HolidayController.php:57-61` delegate restore to services.
- `api/app/Modules/Attendance/Services/AttendanceService.php:130-139`, `ShiftService.php:62-68`, and `HolidayService.php:68-76` lock trashed rows before restoring; HolidayService also invalidates the affected year cache.
- `api/tests/Feature/Attendance/AttendanceHardeningTest.php:199-226` covers all three route-level restore paths.

Before: model binding excluded trashed records and controllers restored directly; holiday restore skipped cache invalidation.  
After: all restore paths resolve trashed rows and execute through locked, cache-aware service methods.

### M018-F04 — Attendance correction workflow

- `spa/src/pages/attendance/index.tsx:38-178` adds a role-gated create/edit modal with employee/date immutability on edit, shift/time/rest-day/remarks fields, and exact payroll-lock/server validation feedback.
- `spa/src/pages/attendance/index.tsx:188-237,258-374` adds HR correction actions, active/archived visibility, row-click edit, keyboard/right-click row actions, guarded archive/restore, and cache invalidation.
- `spa/src/types/attendance.ts:63-83` exposes the archive marker used by the active/archived workflow.

Before: the back-office attendance page only listed records and navigated to shifts, holidays, and import; no correction, archive, or restore action was exposed.  
After: attendance editors can add/correct records and archive/restore them through the same guarded API paths; locked-period messages stay visible in the modal.

### M018-F05 — Shift assignment overlap invariant

- `api/app/Modules/Attendance/Services/ShiftAssignmentService.php:23-54` locks employees and delegates every assignment to a range-aware replacement helper.
- `api/app/Modules/Attendance/Services/ShiftAssignmentService.php:75-121` locks all employee assignments, truncates the prior interval only where appropriate, rejects future overlap, and validates end dates.
- `api/tests/Feature/Attendance/AttendanceHardeningTest.php:139-169` covers truncating the current range and rejecting a future overlap.

Before: only open-ended rows were closed, so future-ended assignments could overlap and the resolver hid the conflict by choosing the latest row.  
After: employee-row locking serializes assignments; every intersecting range is truncated or rejected before a new row is inserted.

### M018-F06 — Holiday-date invariant

- `api/app/Modules/Attendance/Services/HolidayService.php:31-107` validates active-date uniqueness inside create/update/restore transactions and orders the cache source deterministically by date/id; delete/restore bust the year cache.
- `api/database/migrations/2026_08_25_210000_enforce_one_active_holiday_per_date.php:20-53` adds a partial unique active-date index without deleting or silently deduplicating existing records.
- `api/tests/Feature/Attendance/AttendanceHardeningTest.php:171-189` covers conflicting active holidays.

Before: `(date,name)` allowed multiple active holidays on one date and `mapWithKeys()` silently collapsed them nondeterministically.  
After: the application and PostgreSQL/SQLite database backstop allow at most one active holiday per date; archived rows remain restorable.

### M018-F07 — Merged shift-time validation

- `api/app/Modules/Attendance/Services/ShiftService.php:25-59,81-91` compares submitted values with authoritative stored counterparts inside the update transaction and rejects equal start/end values for create and partial update.
- `api/tests/Feature/Attendance/AttendanceHardeningTest.php:191-196` covers a partial update that would otherwise create an invalid equal-time shift.

Before: UpdateShiftRequest compared only fields present in the request, so changing one side could bypass the invariant.  
After: service-level validation merges submitted and stored values before persisting.

### OT error-detail polish

- `spa/src/pages/attendance/overtime/index.tsx:55-74` now routes approve/reject failures through `reportMutationError`, so server authorization/business-rule messages are shown instead of always displaying a generic failure toast. Detail and bulk paths already use the same reporter.

Before: list-page approve/reject failures discarded the server reason.  
After: the shared error reporter preserves actionable 422/authorization copy while avoiding duplicate interceptor toasts.

## Deferred items

- M018-F08 remains open: `importRawPunches()` is an internal service path with no route or SPA surface. A product owner must decide whether raw biometric-punch support is contractual before exposing or removing it.
- M018-F09 remains open for environment verification: the focused regression suite was added, but PostgreSQL is unavailable in this checkout (`db` cannot be resolved), so the feature tests could not execute. The full SPA typecheck also reports three pre-existing errors in `assets/detail.tsx` and `return-management/detail.tsx`; the Attendance page and Attendance OT page pass targeted ESLint.
- Authenticated browser verification remains unrun because the existing Vite cache path is root-owned (`spa/node_modules/.vite-temp`, `EACCES`). No destructive permission or cache change was made.
- Concurrency behavior is implemented with row locks but still needs execution against PostgreSQL; it was not claimed as verified without the database.

## Verification session — 2026-08-27

The 2026-08-25 session wrote this log with every finding marked fixed, but a
repo-wide broken migration meant `migrate:fresh` failed and **not one line of it
ever executed**. `migrate:fresh` works now, so this session's job is execution:
treat every item above as done-but-unverified until a test says otherwise.

### V1 — OvertimeNotificationTest fixture, not the departmental guard

`api/tests/Feature/Notifications/OvertimeNotificationTest.php` failed 2 of 4 with
`BusinessRuleException: "You may only decide overtime requests for your department."`
thrown from `OvertimeDecisionPolicy.php:44`.

**Verdict: the fixture was unlinked; the guard is correct. No guard change made.**

Evidence the guard is right, not over-refusing:

- `api/app/Modules/Attendance/Services/OvertimeDecisionPolicy.php:36-45` resolves the
  approver's department through `$actor->employee_id`. A null link yields a null
  department and is refused.
- `api/app/Modules/Attendance/Services/OvertimeService.php:175-188` scopes the OT
  **list** off the same all-record set (`system_admin`, `hr_officer`) and the same
  employee→department hop. An unlinked department head's list collapses to
  `where('employee_id', null)` — it sees nothing. Refusing to let that same actor
  decide is consistent with what it can see, which is exactly the invariant the
  leave module pinned: an unlinked department head collapses to nothing, never
  widens to everything.
- `api/database/factories/Modules/Auth/Models/UserFactory.php:37-45` — `withRole()`
  sets `role_id` only and never links an employee. The test's own
  `userWithRole('department_head')` helper did the same, so the approver had no
  department to match.
- `api/tests/Feature/Attendance/AttendanceHardeningTest.php:59-67` already builds a
  *linked* same-department approver for the positive case, confirming the intended
  fixture shape.

Fix — supply the missing link instead of weakening the check:

- `api/tests/Feature/Notifications/OvertimeNotificationTest.php:39-60` replaces
  `userWithRole()` with `departmentHeadFor(OvertimeRequest $ot)`, which creates an
  `Employee` in the requester's own `department_id`/`position_id` and links it via
  `employee_id`. The unused `userWithRole()` helper is removed so the trap is not
  reused; the class docblock reference is updated.
- `api/tests/Feature/Notifications/OvertimeNotificationTest.php:76,94` call the new
  helper.

Before: approver had `employee_id = null` → no department → correctly refused.
After: approver is an employee of the requester's department → decision permitted.

Result: `--filter=OvertimeNotificationTest` → **4 passed (5 assertions)**.

### V2 — the whole module executed for the first time

Every suite below had never run: the repo-wide `migrate:fresh` failure meant each
`RefreshDatabase` test died before reaching an assertion. Run one class per
invocation on a private database (`ogami_test_att`) so parallel sessions cannot
tear the schema out from under each other.

| Suite | Result | Covers |
|---|---|---|
| `AttendanceHardeningTest` | **6 passed, 33 assertions** | F01 cross-dept approve/reject/bulk/cancel denial + same-dept success; F02 manual create/update/delete/restore + paired-CSV lock fence (Finalized and Voided); F03 all three route-level restores; F05 assignment truncate + future-overlap reject; F06 duplicate active holiday; F07 merged partial shift-time update |
| `OvertimeBulkApproveTest` | 1 passed, 6 assertions | bulk partial success survives the new decision policy |
| `OvertimeSelfApprovalSodTest` | 3 passed, 6 assertions | self-approval SoD, incl. 422-not-500 over HTTP |
| `OvertimeRequestOptionsAndCancelTest` | 7 passed, 32 assertions | options route not param-bound, owner/admin/unrelated cancel |
| `AutoDetectOvertimeFeatureTest` | 8 passed, 14 assertions | auto-OT idempotency, replay, default-shift fallback |
| `PairedCsvImportTest` | 3 passed, 9 assertions | HHMM/datetime time_out, inverted-shift refusal |
| `RawPunchImportTest` | 5 passed, 13 assertions | sessionizing, dedupe, finalized block, per-day transactional guard |
| `DTRComputationServiceTest` (unit) | 28 passed, 56 assertions | holiday/rest-day/ND/OT-cap matrix unaffected by the hardening |
| `AutoDetectOvertimeTest` (unit) | 4 passed, 4 assertions | OT minute arithmetic |

Total: **65 tests, 0 failures.** No pre-existing Attendance suite regressed under
the new authorization policy or payroll fence — the specific risk of adding a
guard to `approve()`, which `bulkApprove()` and the SoD suite both route through.

### V3 — F06 database backstop proven, not just asserted

The suite exercises `HolidayService`'s application check. The migration's claim of
a *database* backstop was verified directly against PostgreSQL, bypassing the
service, in a rolled-back transaction:

- `holidays_active_date_unique UNIQUE, btree (date) WHERE deleted_at IS NULL` is
  present, and the old `holidays_date_name_unique` constraint is gone (`\d holidays`).
- A second **active** row on one date is refused:
  `ERROR: duplicate key value violates unique constraint "holidays_active_date_unique"`.
- An **archived** row on that same date is accepted, so the restorability claim in
  `2026_08_25_210000_enforce_one_active_holiday_per_date.php` holds.

Note: this migration was the repo-wide blocker. `git show c7b2c483` records that its
original `DROP INDEX IF EXISTS holidays_date_name_unique` is refused on PostgreSQL
(SQLSTATE 2BP01) because `0023_create_holidays_table` backed that name with a UNIQUE
*constraint*. That single line is why all five modules resumed today had zero
executed tests. The coordinator already repaired it; no further change needed.

### V4 — F04 SPA correction workflow typechecks and lints

- `npx tsc --noEmit`: **no errors in the Attendance slice.** The only 2 remaining
  repo-wide errors are `src/pages/assets/detail.tsx` (missing `qrcode` module and a
  resulting implicit `any`) — a different module. The three errors the previous
  session reported are down to two; the `return-management/detail.tsx` one was
  cleared by another session.
- `npx eslint src/pages/attendance src/api/attendance src/types/attendance.ts
  --max-warnings 0`: **clean, exit 0.**
- Contracts the page depends on were checked by hand and match:
  `attendancesApi.create/update/delete/restore` (`spa/src/api/attendance/attendances.ts:31-42`),
  `UpdateAttendanceData` omitting `employee_id`/`date` (`:23`, which is what makes the
  modal's edit-mode immutability a type-level guarantee rather than a UI convention),
  `ListParams.trashed` (`spa/src/types/index.ts:36`), and `Attendance.deleted_at`
  (`spa/src/types/attendance.ts:82`).

Formatting was deliberately left alone. `prettier --check` flags 10 files in
`spa/src/pages/attendance`, but formatting lands separately in this repo as its own
`style(spa):` commit; mixing it into a functional diff would make the diff
unreviewable.

## Verification performed — 2026-08-25 session (source-only, nothing executed)

| Check | Result | Notes |
|---|---|---|
| Attendance PHP syntax | PASS | All modified/new Attendance services, controllers, migration, and focused test passed `php -l`. |
| Attendance targeted diff check | PASS | `git diff --check` passed for module source, tests, migration, SPA Attendance pages, and audit artifacts. |
| Attendance SPA ESLint | PASS | `index.tsx` and `overtime/index.tsx` pass with zero warnings. |
| SPA typecheck | BLOCKED | Full check reaches three unrelated pre-existing errors outside Attendance. |
| Focused Attendance feature suite | BLOCKED | PostgreSQL host `db` does not resolve; no assertions executed. |
| Browser verification | BLOCKED | Existing root-owned Vite cache path returns `EACCES`; no permission changes made. |

## Release handoff

### 2026-08-25 handoff (superseded)

The module is released as `🔁 Needs Re-audit`. Re-run the PostgreSQL feature suite and authenticated browser flow after the environment is available, and resolve the raw-punch product decision before marking M018 Verified.

### 2026-08-27 handoff

Plan status after execution:

| Item | State |
|---|---|
| M018-F01 OT decision authorization | **Verified** — cross-dept approve/reject/bulk/cancel all refused, same-dept succeeds |
| M018-F02 locked-payroll mutability | **Verified** — manual + paired + raw paths share the fence; Finalized and Voided both bite |
| M018-F03 restore binding + holiday cache | **Verified** — all three route-level restores return 200 and un-trash the row |
| M018-F04 correction workflow (SPA) | **Verified at type/lint level**; no browser run (see below) |
| M018-F05 assignment overlap invariant | **Verified** — truncate current, reject future overlap |
| M018-F06 holiday-date invariant | **Verified** — application check *and* PostgreSQL partial index proven |
| M018-F07 merged shift-time validation | **Verified** |
| M018-F09 regression suite executable | **Verified** — 65 tests, 0 failures, on PostgreSQL |
| OT error-detail polish | **Verified at lint level** (same browser caveat) |
| M018-F08 raw-punch product surface | **Deferred — needs a human decision, see below** |

### Still open

1. **M018-F08 — business decision, not mine to make.** Written up as a question below.
2. **Browser verification not run.** Deliberate, on two grounds: the shop-floor
   Playwright suite measures layout and so needs Chromium (Lightpanda fabricates
   `getBoundingClientRect`), and this host is running three audit sessions inside
   ~1.1 GiB of free RAM after being OOM-killed earlier today. Starting Vite plus
   Chromium here risks killing the other two sessions' database work. The F04 flow
   is covered by typecheck, targeted ESLint, and hand-checked API/type contracts;
   what remains unproven is only pixel-level rendering.
3. **Concurrency under real contention.** The row locks in `OvertimeService`,
   `ShiftAssignmentService`, and `AttendanceDateMutabilityGuard` are exercised
   single-threaded. Two-connection interleaving was not attempted.

### Convention deviation left in place (flagged, not changed)

`api/database/migrations/2026_08_25_210000_enforce_one_active_holiday_per_date.php`
is timestamp-style, but CLAUDE.md says numbered `0NNN_` migrations are the
convention and "don't introduce more" timestamp ones. It is left alone on purpose:

- It is functionally correct and orders correctly (the migrator sorts by full
  filename, and `0023_` < `2026_08_25_`), so nothing is broken.
- Renaming is the destructive operation CLAUDE.md warns about — Laravel matches on
  the recorded filename, so a rename re-runs it wherever it has already run. `up()`
  would then fail on `CREATE UNIQUE INDEX` (no `IF NOT EXISTS`).
- The `0NNN_` space is being written into by two other sessions right now (`0475`
  through `0478` appeared today, past the `0474` high-water mark in CLAUDE.md), so
  picking a number from a concurrent session is exactly how a fifth duplicate
  prefix gets created.

Verified the dev database has not recorded it, so a rename is *possible* later:
`SELECT migration FROM migrations WHERE migration LIKE '%holiday%'` returns only
`0023_create_holidays_table`. Best done by the coordinator once the sessions are
merged and the number space is quiet.

## Question for a human — M018-F08 raw-punch import

`DTRImportService::importRawPunches()` and `PunchSessionizer` are complete, guarded
by the payroll fence, and covered by `RawPunchImportTest` (5 passing). They are
also **unreachable**: no route, no controller action, no SPA affordance. The only
import endpoint calls the paired `import()`, and
`spa/src/pages/attendance/import.tsx` documents only
`employee_no, date, time_in, time_out`.

This is a product-surface question, so it is not decided here. The options:

- **A — Expose it.** Add a raw-punch route/mode, format detection, validation, result
  reporting, and browser coverage. Correct if raw biometric export is contractual —
  a real biometric device emits punch events, not pre-paired rows, so requiring the
  customer to pre-pair is pushing sessionizing onto them.
- **B — Keep it internal.** Leave the service unexposed, keep its tests as a unit
  contract, and remove any claim of raw biometric support from product/thesis copy.
  Cheapest, and honest, if the deployed devices already export paired rows.
- **C — Delete it.** Only if raw support is definitively out of scope; forfeits
  working, tested code that would have to be rewritten later.

Evidence needed to decide: what the FCIE Dasmariñas biometric terminals actually
export. If they emit raw punches, A is required for the module to work at all on
real hardware and B would be a latent gap. Recommend confirming the device export
format before choosing.

## M018-F13 — explicit clearing of correction fields — 2026-08-28

Claimed first with:

```text
audit/scripts/claim-module.sh people attendance-dtr
```

Result: `CLAIMED`.

The edit form now maps empty shift, time-in, and time-out controls to explicit
`null` values, while create requests retain their existing `undefined` omission
behavior. `UpdateAttendanceData` models those three correction fields as
`string | null` when present. The focused UI regression exercises an existing
attendance, clears all three controls, and checks both the update payload and its
JSON serialization contain all three keys as `null`.

### TDD evidence

RED, before the production change:

```text
cd spa
npm run test:run -- src/pages/attendance/index.test.tsx
```

Result: **1 test failed** as intended. The update call contained
`shift_id: undefined`, `time_in: undefined`, and `time_out: undefined`; JSON
serialization omitted those keys.

GREEN, after the production change:

```text
cd spa
npm run test:run -- src/pages/attendance/index.test.tsx
```

Result: **1 test passed**.

### Verification

```text
cd spa
npx eslint src/pages/attendance/index.tsx src/pages/attendance/index.test.tsx src/api/attendance/attendances.ts --max-warnings 0
```

Result: **PASS**, exit 0.

```text
cd spa
npm run typecheck
```

Result: **PASS**, exit 0.

```text
cd api
php -l app/Modules/Attendance/Requests/UpdateAttendanceRequest.php
```

Result: **PASS** — no syntax errors detected. No backend feature test was
needed because no backend production code changed; the existing request already
accepts `sometimes|nullable` for all three fields.

```text
git diff --check -- spa/src/api/attendance/attendances.ts spa/src/pages/attendance/index.tsx spa/src/pages/attendance/index.test.tsx
```

Result: **PASS**.
