# M018 — Attendance & DTR audit report

Audit date: 2026-08-24  
Claim: people / attendance-dtr  
Registry tier: 4  
Status: 📋 Plan Ready  
Session recommendation: separate-recommended

## Verdict

Production-readiness score: **43/100 — not ready for an unqualified release**.

The DTR calculation core is coherent and well covered by pure unit tests, and the self-service read/request paths are visibly present. The release is blocked by two critical server-side controls: department heads can decide OT records outside their department when they obtain a record identifier, and manual/paired attendance writes do not share the finalized-payroll lock used by the raw-punch path. Restore endpoints for all three soft-deletable attendance resources are also unreachable, and the back-office SPA has no attendance correction workflow despite exposing correction APIs.

The majority of remediation requires a coordinated authorization/data-integrity session and negative regression coverage, so no production-code fix was applied during this audit.

## Evidence checked

- Refreshed the module registry, atomically claimed M018, reviewed the existing dirty worktree and recent commits, and confirmed the M018 scaffold and lock.
- Attendance routes, controllers, form requests, resources, models, services, settings migrations, payroll-period status semantics, shift-assignment persistence, holiday caching, and the shared hash-ID/soft-delete binding trait.
- SPA attendance, import, overtime, shift, holiday, self-service DTR/OT pages, API clients, route guards, role/permission mapping, mobile self-service E2E coverage, and OT bulk-action E2E coverage.
- `php artisan test tests/Unit/DTRComputationServiceTest.php tests/Unit/AutoDetectOvertimeTest.php tests/Feature/Attendance` — the two unit suites passed: **32 tests / 60 assertions**. The 27 feature tests could not reach the database because PostgreSQL host `db` was unavailable (`could not translate host name "db"`).
- `php -l` over all `api/app/Modules/Attendance` PHP files — passed.
- `npm run typecheck` in `spa` — passed.
- `npm run lint` in `spa` — passed.
- Focused Playwright mobile run was attempted. Vite could not start because `spa/node_modules/.vite-temp` is root-owned and returned `EACCES`; no browser assertions ran.
- No live authenticated API/SPA environment, representative production dataset, query plan, or payroll lock integration rehearsal was available.

## Strengths

- The Attendance API is behind Sanctum plus the attendance feature middleware, and each write family has a permission gate (`api/app/Modules/Attendance/routes.php:11-40`).
- DTR computation has explicit handling for shifts, grace minutes, holidays, rest days, undertime, approved OT, night differential, missing punches, and invalid non-night inversions (`api/app/Modules/Attendance/Services/DTRComputationService.php:43-101,137-223`). The pure calculation suite passed all 32 tests run.
- Attendance has a database uniqueness invariant for one employee/date and a lifecycle status check; OT has an enum status check (`api/database/migrations/0024_create_attendances_table.php:13-39`; `api/database/migrations/2026_08_13_220000_add_remaining_lifecycle_status_checks.php:61-64`).
- Auto-OT detection uses a transaction, row lock, a partial unique source index, and an outbox event to make biometric replays idempotent (`api/app/Modules/Attendance/Services/OvertimeService.php:46-140`; `api/database/migrations/2026_08_13_100000_add_auto_overtime_source_unique.php:20-49`).
- Self-service OT resolves the employee from the authenticated session and never accepts an employee ID; cancellation and restore re-check ownership (`api/app/Modules/HR/Controllers/SelfServiceController.php:172-225,268-329`).
- The SPA has visible loading/error/empty states for the attendance list and import summary, and a mobile DTR render test (`spa/src/pages/attendance/index.tsx:139-149`; `spa/src/pages/attendance/import.tsx:106-133`; `spa/e2e/mobile/self-service-mobile.spec.ts:105-118`).

## Findings

### M018-F01 — Broken/critical: OT approve/reject endpoints do not enforce department scope

Priority: **P0**  
Scope: **medium**  
Recommendation: **separate-recommended**

The approve, reject, and bulk-approve routes require only the broad `attendance.ot.approve` permission (`api/app/Modules/Attendance/routes.php:42-51`). The approve/reject form requests repeat that permission check but do not inspect the target employee (`api/app/Modules/Attendance/Requests/ApproveOvertimeRequestRequest.php:9-20`; `api/app/Modules/Attendance/Requests/RejectOvertimeRequestRequest.php:9-20`). The controller passes the route-bound record straight to the service, and bulk approval decodes arbitrary record hashes before calling the same service (`api/app/Modules/Attendance/Controllers/OvertimeController.php:62-85,129-154`).

The service locks the authoritative row and checks pending state plus self-approval, but neither `approve()` nor `reject()` checks the approver's department or an HR/admin all-record policy (`api/app/Modules/Attendance/Services/OvertimeService.php:203-238,272-295`). In contrast, list/show explicitly scope an OT approver to their own department (`api/app/Modules/Attendance/Services/OvertimeService.php:172-188`; `api/app/Modules/Attendance/Controllers/OvertimeController.php:41-59`). The seeded `department_head` role has `attendance.ot.approve` (`api/database/seeders/RolePermissionSeeder.php:670-688`), and the catalog defines that permission as the approval capability (`api/database/seeders/RolePermissionSeeder.php:89-97`).

Therefore a department head can approve or reject an out-of-department OT request if they obtain its hash ID, even though the list and detail surfaces hide it. This can change payroll inputs and is a server-side authorization bypass; hash IDs are identifiers, not authorization.

Action: centralize an actor-aware OT decision policy and invoke it inside the locked service transaction for single and bulk decisions. Permit system admin and explicitly defined HR/all-record roles; require same-department ownership for department heads; reject cross-department records before state mutation. Add negative feature tests for approve, reject, and bulk approve, including a same-department success and cross-department 403.

### M018-F02 — Broken/critical: manual and paired-import attendance writes can mutate locked payroll dates

Priority: **P0**  
Scope: **medium**  
Recommendation: **separate-recommended**

Manual create/update/delete write through `AttendanceService` without consulting `PayrollPeriod` (`api/app/Modules/Attendance/Services/AttendanceService.php:91-116`). The corresponding API writes are exposed directly by the attendance routes (`api/app/Modules/Attendance/routes.php:33-40`). The paired CSV importer also performs `firstOrNew`, recalculation, and save without a finalized-period check (`api/app/Modules/Attendance/Services/DTRImportService.php:24-121`).

The raw-punch path is the only attendance writer that snapshots payroll ranges and blocks finalized/disbursed dates (`api/app/Modules/Attendance/Services/DTRImportService.php:207-250,287-311`). Payroll semantics additionally define `voided` as locked (`api/app/Modules/Payroll/Enums/PayrollPeriodStatus.php:30-40`; `api/app/Modules/Payroll/Models/PayrollPeriod.php:139-147`), while the raw importer checks only finalized and disbursed statuses. The write paths therefore disagree about whether already-closed payroll attendance may change, and at least the manual and paired paths bypass the lock entirely.

Action: create one shared attendance-date mutability guard covering finalized, disbursed, and voided periods, and call it from manual create/update/delete/restore plus both import paths before mutation. Define whether the sanctioned correction is payroll void/force-unlock or a controlled adjustment workflow; lock/recheck the period and attendance row in the same transaction. Add feature tests for every write path and for a period transitioning to locked during an import.

### M018-F03 — Broken/high: attendance, shift, and holiday restore routes cannot bind archived rows

Priority: **P1**  
Scope: **small**  
Recommendation: **same-session only after authorization and payroll fixes**

All three restore routes omit `->withTrashed()` (`api/app/Modules/Attendance/routes.php:18,30,39`), while Attendance, Shift, and Holiday use `SoftDeletes` (`api/app/Modules/Attendance/Models/Attendance.php:14-18`; `api/app/Modules/Attendance/Models/Shift.php:12-16`; `api/app/Modules/Attendance/Models/Holiday.php:12-16`). The shared hash-ID trait documents that soft-deleted binding is available only when the route opts in and otherwise uses a normal query excluding trashed rows (`api/app/Common/Traits/HasHashId.php:23-43,45-67`). The controller methods then call `restore()` only after binding (`api/app/Modules/Attendance/Controllers/AttendanceController.php:68-71`; `api/app/Modules/Attendance/Controllers/ShiftController.php:60-64`; `api/app/Modules/Attendance/Controllers/HolidayController.php:58-61`).

Archived records consequently resolve to 404 instead of reaching the restore action. Holiday restore also bypasses the service's year-cache invalidation: delete busts the cache, but controller restore does not (`api/app/Modules/Attendance/Services/HolidayService.php:53-58,64-89`).

Action: opt all three routes into soft-deleted binding, move restore through services, invalidate the affected holiday year, and add archive→restore API tests for each resource.

### M018-F04 — Missing/high: no back-office attendance correction workflow is reachable from the SPA

Priority: **P1**  
Scope: **medium**  
Recommendation: **separate-recommended**

The backend exposes manual attendance create/update/delete/restore operations and marks records as manual (`api/app/Modules/Attendance/routes.php:33-40`; `api/app/Modules/Attendance/Services/AttendanceService.php:91-116`). The SPA attendance page renders filters, a table, and import/shift/holiday navigation, but has no create action, row navigation, or row actions (`spa/src/pages/attendance/index.tsx:104-163`). The API client contains create/update/delete/restore methods, but no attendance page calls them (`spa/src/api/attendance/attendances.ts:32-50`).

HR/attendance users therefore have no supported UI path to correct a missing punch, add a manual DTR, inspect a record, or restore an archived row. Import is the only visible correction route, and its paired CSV contract is a poor substitute for targeted correction.

Action: define the correction workflow and permissions, then add a detail/edit form with explicit employee/date immutability, finalized-period messaging, audit context, and archive/restore actions. Add browser coverage for validation, server errors, and the locked-period response.

### M018-F05 — Incomplete/high: shift assignment writes can create overlapping effective intervals

Priority: **P1**  
Scope: **medium**  
Recommendation: **separate-recommended**

The assignment table has only employee/date and shift indexes; it has no interval-exclusion or overlap invariant (`api/database/migrations/0022_create_employee_shift_assignments_table.php:13-23`). Both assignment methods close only rows whose `end_date` is null, then insert the new interval (`api/app/Modules/Attendance/Services/ShiftAssignmentService.php:22-47,53-71`). An existing future or explicitly ended assignment can therefore overlap the new effective date. `current()` silently chooses the most recent effective date when overlaps exist (`api/app/Modules/Attendance/Services/ShiftAssignmentService.php:74-90`), masking ambiguous historical data rather than rejecting it.

Action: in one transaction, truncate or reject every existing interval that intersects the new interval, including future-ended rows. Add a database-level PostgreSQL exclusion constraint where supported, or a documented application invariant for other drivers, plus overlap and concurrent-assignment tests.

### M018-F06 — Incomplete/high: multiple holidays on one date collapse nondeterministically in DTR computation

Priority: **P1**  
Scope: **small-to-medium**  
Recommendation: **separate-recommended**

The holidays table permits multiple names on the same date because its unique key is `(date, name)`, not `date` (`api/database/migrations/0023_create_holidays_table.php:13-23`). `HolidayService::loadYear()` converts the collection into a date-keyed map with `mapWithKeys`, so a later row silently overwrites an earlier holiday, with no explicit ordering or conflict rule (`api/app/Modules/Attendance/Services/HolidayService.php:60-84`). `forDate()` then returns at most one holiday type to DTR (`api/app/Modules/Attendance/Services/HolidayService.php:60-73`).

Regular versus special holiday selection can therefore change pay-rate computation when duplicate-date rows exist. The UI calendar can display several rows for one date, but the calculation engine cannot represent that ambiguity.

Action: decide the domain invariant: one effective holiday type per date, or an explicit precedence/combination rule. Enforce it in validation and the database where possible, order the cache query deterministically, and add regular+special same-date tests.

### M018-F07 — Incomplete/medium: partial shift updates can admit equal start/end times

Priority: **P2**  
Scope: **small**  
Recommendation: **same-session only after the separate hardening work**

Create validation rejects equal start/end values (`api/app/Modules/Attendance/Requests/StoreShiftRequest.php:23-35`), but update validation compares only submitted fields and the service performs no persisted-state invariant check (`api/app/Modules/Attendance/Requests/UpdateShiftRequest.php:24-37`; `api/app/Modules/Attendance/Services/ShiftService.php:38-51`). A PATCH that changes only `end_time` can therefore set it equal to the existing `start_time`. DTR defensively rolls a non-night end forward by a day (`api/app/Modules/Attendance/Services/DTRComputationService.php:137-145`), turning the invalid shift into a 24-hour schedule instead of rejecting it.

Action: validate the merged existing/new times in the request or service and add a partial-update regression test.

### M018-F08 — Missing/medium: raw-punch import is implemented but not reachable as a product path

Priority: **P2**  
Scope: **small-to-medium**  
Recommendation: **separate-recommended if raw biometric support is contractual**

`DTRImportService` contains an additive raw-event importer and sessionizer (`api/app/Modules/Attendance/Services/DTRImportService.php:123-262`; `api/app/Modules/Attendance/Services/PunchSessionizer.php:9-127`), and raw import has feature tests. The only HTTP controller import action calls the paired `import()` method (`api/app/Modules/Attendance/Controllers/AttendanceController.php:74-78`), and the SPA upload page documents and submits only `employee_no, date, time_in, time_out` (`spa/src/pages/attendance/import.tsx:44-49,79-102`). No route, request mode, or UI path selects `importRawPunches()`.

Action: either remove/label the raw path as an internal service, or expose an explicit raw-punch import contract with format detection, validation, finalized-period behavior, result reporting, and browser coverage. Do not advertise raw biometric support until the path is reachable.

### M018-F09 — Incomplete/high: security and lifecycle regression coverage does not match the risk

Priority: **P1**  
Scope: **medium**  
Recommendation: **separate-recommended**

The focused feature suites include auto-OT, OT lifecycle, and CSV import tests, but they could not execute because the configured PostgreSQL host `db` was unavailable. Existing tests do not cover cross-department approve/reject/bulk decisions, the three archive/restore routes, finalized-period protection on manual and paired writes, assignment overlaps, or duplicate-date holiday resolution. Browser coverage renders self-service DTR (`spa/e2e/mobile/self-service-mobile.spec.ts:105-118`) and tests OT bulk-action messaging (`spa/e2e/bulk-actions.spec.ts:304-381`), but there is no mobile OT test or attendance correction/restore flow.

Action: add negative authorization and lifecycle tests before implementation is considered complete. Run them with a writable dependency cache, a live PostgreSQL test service, and an authenticated browser/API smoke path.

## Polish pass

- Attendance list and paired-import screens have loading, error, empty, retry, and import-summary states (`spa/src/pages/attendance/index.tsx:139-149`; `spa/src/pages/attendance/import.tsx:106-133`).
- HR OT list approval/rejection failures are reduced to generic toasts (`spa/src/pages/attendance/overtime/index.tsx:55-74`), so server-side reasons such as a locked period or authorization failure are not shown. This is P2 polish after the backend contract is corrected.
- No additional visual blocker was promoted above the authorization, payroll-integrity, restore, and workflow findings.

## Production-audit assessment

### Blockers / high-value risks

1. M018-F01 allows a department-scoped approver to mutate OT decisions outside their department.
2. M018-F02 permits attendance changes to dates that payroll treats as locked, with inconsistent status coverage across import paths.
3. M018-F03 makes archive recovery unavailable, while M018-F04 leaves manual DTR correction without a supported UI.
4. M018-F09 leaves the critical authorization and lifecycle paths without executable negative regression coverage in this environment.

### Evidence still missing

- A live PostgreSQL run of the Attendance feature suite and new cross-department/locked-period tests.
- Authenticated API proof that same-department OT approval succeeds while cross-department approve/reject/bulk requests fail.
- Archive/restore fixtures for Attendance, Shift, and Holiday, including holiday cache refresh after restore.
- Overlapping assignment and duplicate-date holiday fixtures against production-like data.
- A live browser run with a writable Vite cache covering DTR, OT, import, correction, and mobile paths.

### Next action

Do not promote M018 to Verified. In a separate hardening session, first centralize OT decision authorization and the payroll-date mutability guard, then repair restore binding and define the attendance correction workflow. Add the negative regression suite before addressing interval/holiday invariants and raw-import/product polish.
