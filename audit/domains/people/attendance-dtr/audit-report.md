# M018 — Attendance & DTR re-audit report

Audit date: 2026-08-27
Claim: people / attendance-dtr (M018)
Registry tier: 4
Status: 📋 Plan Ready
Session recommendation: separate-recommended

## Verdict

The previous hardening work is present in the current tree: OT decision policy, payroll-date mutability checks, soft-delete restore binding, the correction modal, assignment overlap handling, the active-holiday invariant, and merged shift-time validation are all implemented. Focused backend and SPA checks pass against the agent-owned database.

M018 is not ready for Verified status. The re-audit found a payroll-lock scope-drift gap, raw exception leakage from bulk OT approval, an unreachable raw-punch capability, and correction/holiday/shift edge cases. The findings span payroll integrity, API security, product behavior, and missing concurrency/browser evidence; the gate therefore requires a separate hardening session and no production-code fix was applied here.

## Evidence checked

- Refreshed `audit/00-MODULE-REGISTRY.md`, atomically claimed `people/attendance-dtr`, and verified the M018 lock, target docs, implementation, tests, current diff, and mtimes. The only pre-existing uncommitted change is the coordinator's generated registry timestamp; it was not edited.
- Reviewed Attendance routes/controllers/requests/resources/models/services, migrations, tests, SPA attendance/import/OT/shift/holiday/self-service surfaces, and relevant Payroll/HR/Auth dependencies for context only.
- Created and used only `DB_DATABASE=ogami_test_m018_agent_d`; no `ogami_test` database was used.
- Backend `tests/Feature/Attendance`: **33 tests, 113 assertions — OK**.
- Backend `tests/Unit/DTRComputationServiceTest.php` and `tests/Unit/AutoDetectOvertimeTest.php`: **32 tests, 60 assertions — OK**.
- `tests/Feature/Notifications/OvertimeNotificationTest.php`: **4 tests, 5 assertions — OK**.
- Focused PHP syntax checks: passed. `git diff --check` on M018 files: clean.
- SPA targeted ESLint for attendance sources with `--max-warnings 0`: passed. SPA `npm run typecheck`: passed. Vitest found no attendance-page test files, so it provided no page behavior coverage.
- No production-code changes were made during this session; no fix-log entry was required.

## Remediated prior findings

The earlier F01–F07 findings are not carried forward as current defects: the locked OT decision policy is in `api/app/Modules/Attendance/Services/OvertimeDecisionPolicy.php:21-58`; the shared attendance write fence is called by create/update/delete/restore/recompute and both import paths (`api/app/Modules/Attendance/Services/AttendanceDateMutabilityGuard.php:22-57`, `api/app/Modules/Attendance/Services/AttendanceService.php:92-156`, `api/app/Modules/Attendance/Services/DTRImportService.php:99-113,229-246`); restore routes opt into trashed binding (`api/app/Modules/Attendance/routes.php:18,30,39`); the correction UI is present (`spa/src/pages/attendance/index.tsx:60-137`); assignment replacement rejects future overlaps (`api/app/Modules/Attendance/Services/ShiftAssignmentService.php:75-113`); the active-date holiday index is present (`api/database/migrations/2026_08_25_210000_enforce_one_active_holiday_per_date.php:29-55`); and shift updates validate merged times (`api/app/Modules/Attendance/Services/ShiftService.php:39-58`). The focused tests above verify the available regression coverage, but do not replace the missing concurrency/browser checks below.

## Findings

### M018-F08 — Missing: raw-punch import has no reachable product path

Priority: **P2**
Scope: **medium**
Recommendation: **separate-recommended**

`DTRImportService` implements raw biometric event parsing and sessionization (`api/app/Modules/Attendance/Services/DTRImportService.php:125-262`), but the only HTTP import action calls the paired-row importer (`api/app/Modules/Attendance/Controllers/AttendanceController.php:74-78`). The SPA upload page documents and submits only `employee_no, date, time_in, time_out` (`spa/src/pages/attendance/import.tsx:44-49,79-102`). There is no request mode, route, or UI path for `importRawPunches()`.

Action: decide whether raw biometric support is contractual. If yes, expose an explicit format/mode with row-level results, payroll-lock behavior, and browser coverage; otherwise label the service internal and remove any product implication that raw events are supported.

### M018-F09 — Incomplete: high-risk authorization and lifecycle paths lack negative/concurrency coverage

Priority: **P1**
Scope: **large**
Recommendation: **separate-recommended**

The current focused suites execute successfully, but the repository still lacks executable coverage for cross-department approve/reject/bulk denial, employee-scope changes after payroll computation, all archive/restore flows, archived-shift assignment, and concurrent payroll/attendance writes. The SPA has no attendance correction/restore browser test, and Vitest reports no page tests for `spa/src/pages/attendance`.

Action: add focused negative feature tests and database contention tests, then run an authenticated browser smoke path covering attendance correction, restore, OT decisions, import errors, and locked-period messaging.

### M018-F10 — Broken: payroll mutability guard authorizes against live employee scope instead of frozen payroll membership

Priority: **P0**
Scope: **large**
Recommendation: **separate-recommended**

The guard locks overlapping periods and blocks finalized, disbursed, and voided periods, but decides applicability only from the employee's current department, employment type, and pay type (`api/app/Modules/Attendance/Services/AttendanceDateMutabilityGuard.php:31-46,61-86`). Payroll computes the scoped employee set using the same attributes as of the period end (`api/app/Modules/Payroll/Services/PayrollPeriodService.php:523-551`) and persists an employee/cycle claim inside the payroll transaction (`api/app/Modules/Payroll/Services/PayrollCalculatorService.php:291-303`; `api/app/Modules/Payroll/Models/PayrollCycleClaim.php:12-18`).

For a scoped period, if an employee is paid and the employee is later moved to another department, pay type, or employment type, the live-attribute check can return false even though a payroll row/claim for that employee and cycle exists. Attendance for the locked date can then be edited, deleted, restored, or recomputed without the intended payroll fence. This is a payroll-integrity bypass, not merely a stale-list issue.

Action: make the write fence consult immutable payroll membership/employee claims (or persist a period membership snapshot) in addition to the period lock; test a paid employee moved out of scope, a moved-in employee, and a company-wide period inside the same transaction fence.

### M018-F11 — Broken: bulk OT approval returns raw exception messages to the client

Priority: **P1**
Scope: **small**
Recommendation: **separate-recommended**

`bulkApprove()` catches every `Throwable` and places `$e->getMessage()` directly into the failed response (`api/app/Modules/Attendance/Services/OvertimeService.php:256-274`). The controller serializes those failure reasons without sanitization (`api/app/Modules/Attendance/Controllers/OvertimeController.php:141-158`). A database/query or unexpected runtime failure can therefore expose SQL, table names, or internal infrastructure details to the caller; only expected business-rule failures should become user-facing row reasons.

Action: distinguish expected domain/validation failures from unexpected exceptions, return a stable safe message/code for the latter, and log the exception with the request/OT ID. Add a regression test that injects an unexpected exception and asserts no raw message reaches JSON.

### M018-F12 — Incomplete: shift assignment accepts archived or inactive shift IDs

Priority: **P2**
Scope: **small**
Recommendation: **separate-recommended**

The assignment requests only decode the submitted hash (`api/app/Modules/Attendance/Requests/AssignEmployeeShiftRequest.php:18-33`; `api/app/Modules/Attendance/Requests/BulkAssignShiftRequest.php:18-37`), and the service passes the resulting integer straight into the assignment row (`api/app/Modules/Attendance/Services/ShiftAssignmentService.php:23-40,47-54,107-113`). `Shift` is soft-deletable (`api/app/Modules/Attendance/Models/Shift.php:14-16`), while `current()` resolves the assignment through a normal relation and can return null when the assigned shift is archived (`api/app/Modules/Attendance/Services/ShiftAssignmentService.php:61-72`).

A caller with a known old shift hash can create a valid-FK assignment to a trashed shift; DTR then loses the assigned shift and may fall back to another schedule. The same request path also permits a new assignment to an inactive shift without an explicit policy decision.

Action: resolve the shift inside the write transaction, reject trashed IDs, and define whether inactive shifts may be newly assigned. Add single/bulk tests for missing, trashed, inactive, and active shifts.

### M018-F13 — Incomplete: correction form cannot clear an existing punch or shift

Priority: **P1**
Scope: **small**
Recommendation: **same-session-ok**

The correction form maps empty controls to `undefined` for shift, time-in, and time-out (`spa/src/pages/attendance/index.tsx:99-117`). The API type also models those fields as optional strings rather than nullable values (`spa/src/api/attendance/attendances.ts:13-23`), while the backend explicitly supports `sometimes|nullable` for all three (`api/app/Modules/Attendance/Requests/UpdateAttendanceRequest.php:18-24`). JSON serialization omits `undefined`, so an edit that clears a bad punch or removes a shift sends no field and leaves the old value in place.

Action: model update fields as `string | null`, send null for an explicit clear, and add a UI/API regression test for clearing time-in, time-out, and shift.

### M018-F14 — Incomplete: attendance date validation accepts values that are then concatenated as dates

Priority: **P2**
Scope: **small**
Recommendation: **same-session-ok**

Create validation uses the broad `date` rule (`api/app/Modules/Attendance/Requests/StoreAttendanceRequest.php:18-28`), then concatenates the raw value with an `H:i` time (`api/app/Modules/Attendance/Requests/StoreAttendanceRequest.php:40-43`). A valid datetime string can pass the rule and produce an invalid compound value such as `2026-04-15 12:00:00 08:00:00`, causing a parse failure or ambiguous timestamp instead of a field validation response.

Action: require `date_format:Y-m-d` for attendance date inputs or normalize to a date before combining times; add a malformed/date-time input regression test.

### M018-F15 — Missing: recurring holidays are stored but ignored outside the source year

Priority: **P1**
Scope: **medium**
Recommendation: **separate-recommended**

The API and UI expose `is_recurring` and label it “Recurs annually” (`api/app/Modules/Attendance/Requests/StoreHolidayRequest.php:25-32`; `spa/src/pages/attendance/holidays/index.tsx:337-364`). DTR lookup loads only rows whose stored date is in the requested year and keys them by the exact date (`api/app/Modules/Attendance/Services/HolidayService.php:78-104`). A recurring holiday created for 2026 therefore does not affect the same month/day in 2027, so holiday pay/rates silently disappear after one year.

Action: define recurrence semantics, including leap-day behavior, then expand recurring records during lookup or materialize yearly instances. Add DTR tests across years and cache invalidation tests.

### M018-F16 — Polish: cancellation notification is presented as a rejection

Priority: **P2**
Scope: **small**
Recommendation: **same-session-ok**

Self-service and approver cancellation set the request to the rejected enum and emit `OvertimeRequestDecided(..., false)` (`api/app/Modules/Attendance/Services/OvertimeService.php:320-352`). The queued listener maps every false decision to “Rejected” and the `attendance.ot_rejected` notification type (`api/app/Modules/Attendance/Listeners/NotifyOnOvertimeDecided.php:16-34`). An employee who withdraws a request consequently receives a rejection notice rather than a cancellation/withdrawal notice.

Action: carry an explicit decision reason/type in the event or emit a cancellation event, then add notification assertions for approved, rejected, and cancelled requests.

### M018-F17 — Incomplete: zero-minute auto-OT threshold creates zero-hour requests

Priority: **P2**
Scope: **small**
Recommendation: **same-session-ok**

The auto-OT setting accepts a threshold of zero (`api/app/Modules/Attendance/Services/OvertimeService.php:56-60`). The detector only skips when `$extra < $threshold` and then rounds/creates the request (`api/app/Modules/Attendance/Services/OvertimeService.php:87-116`). With threshold `0`, an attendance ending exactly at shift end satisfies the condition and can create a `0.0`-hour pending OT request.

Action: require positive extra minutes before creating an OT row, or enforce a strictly positive setting and document the behavior; add the exact-shift-end regression test.

## Polish pass

- Attendance list and import have loading, error, empty, retry, and result states (`spa/src/pages/attendance/index.tsx:139-149`; `spa/src/pages/attendance/import.tsx:106-133`).
- HR OT action errors are generally reduced to safe generic copy (`spa/src/pages/attendance/overtime/index.tsx:55-74`), but F11 must first establish the backend's safe error contract.
- The HR OT list renders “New OT request” without a permission guard (`spa/src/pages/attendance/overtime/index.tsx:190-205`). Department-head users have approval visibility but not the create/edit permission in the seeded role map (`api/database/seeders/RolePermissionSeeder.php:89-100,670-688`), so the button can navigate them to an unauthorized form. This is low-risk UI polish and should be addressed with the OT permission work.

## Gate decision

No fix was applied. Only four of the ten ordered actions are `same-session-ok`, while the remaining actions require payroll/security/product decisions or larger regression work; the total scope is not small and the same-session-ok actions are not a majority.

## Next action

Keep M018 at 📋 Plan Ready. In a separate hardening session, first close the payroll membership fence and bulk-error disclosure, then decide the raw/recurring product contracts and add the negative/concurrency/browser evidence. The small correction/date/notification/threshold fixes can follow in the same or a later focused session after those contracts are settled.
