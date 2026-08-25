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

## Verification performed

| Check | Result | Notes |
|---|---|---|
| Attendance PHP syntax | PASS | All modified/new Attendance services, controllers, migration, and focused test passed `php -l`. |
| Attendance targeted diff check | PASS | `git diff --check` passed for module source, tests, migration, SPA Attendance pages, and audit artifacts. |
| Attendance SPA ESLint | PASS | `index.tsx` and `overtime/index.tsx` pass with zero warnings. |
| SPA typecheck | BLOCKED | Full check reaches three unrelated pre-existing errors outside Attendance. |
| Focused Attendance feature suite | BLOCKED | PostgreSQL host `db` does not resolve; no assertions executed. |
| Browser verification | BLOCKED | Existing root-owned Vite cache path returns `EACCES`; no permission changes made. |

## Release handoff

The module is released as `🔁 Needs Re-audit`. Re-run the PostgreSQL feature suite and authenticated browser flow after the environment is available, and resolve the raw-punch product decision before marking M018 Verified.
