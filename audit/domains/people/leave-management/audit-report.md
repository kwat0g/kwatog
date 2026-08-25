# M019 — People / Leave Management audit report

Audit date: 2026-08-24  
Claim: people / leave-management  
Registry tier: 4  
Status: 📋 Plan Ready  
Session recommendation: separate-recommended

## Verdict

Production-readiness score: **32/100 — not ready for an unqualified release**.

The module has a real employee-row lock for overlap submission, scoped list/show/balance reads, a two-stage approval workflow, locked balance consume/restore operations, and a durable year-end handoff. The release is blocked by server-side decision/cancellation authorization gaps and by leave approval/cancellation writes that can corrupt attendance/payroll inputs. Rollover and leave-type/document invariants also need explicit contracts. The material work is predominantly cross-module and policy-heavy, so no production-code fix was applied during this audit.

## Evidence checked

- Refreshed the registry, atomically claimed M019, reviewed the dirty worktree, current branch, and recent Leave commits, and preserved all pre-existing application changes.
- Reviewed Leave routes/controllers/requests/resources/models/services, role grants, shared approval behavior, hash-ID soft-delete binding, migrations, employee-created balance initialization, year-end jobs/commands/scheduler, notification listeners, and the SPA API/pages/E2E chain.
- Reviewed the row-scope and approval tests, half-day overlap/concurrency tests, balance tests, calendar tests, and year-end reconciliation/durable-handoff tests.
- `php artisan route:list --path=leaves` — passed; 20 Leave routes are registered.
- PHP lint over `api/app/Modules/Leave`, year-end commands, and related Leave/Workflow seeders — passed.
- `php artisan test tests/Feature/Leave --no-coverage` — blocked before assertions: PostgreSQL host `db` could not be resolved; **42 tests / 0 assertions**.
- `npm run typecheck` in `spa` — passed.
- `npm run lint` in `spa` — passed.
- `npm run test:run` — blocked during Vite config startup by `EACCES` writing the pre-existing root-owned `spa/node_modules/.vite-temp` path.
- No live authenticated API/SPA environment, writable browser/Vite cache, production-like HR/payroll dataset, or finalized-payroll integration rehearsal was available.

## Strengths

- The API is behind Sanctum and the Leave feature gate, and routes carry explicit view/create/approval/type-management permissions (`api/app/Modules/Leave/routes.php:11-45`).
- List reads use the shared department/self scope and the show/balance controllers enforce corresponding row visibility (`api/app/Modules/Leave/Services/LeaveRequestService.php:130-153`; `api/app/Modules/Leave/Controllers/LeaveRequestController.php:66-85`; `api/app/Modules/Leave/Controllers/LeaveBalanceController.php:28-51`).
- Submission locks the authoritative employee row before checking the empty-gap overlap, and the existing tests specifically assert AM/PM overlap behavior and the employee lock (`api/app/Modules/Leave/Services/LeaveRequestService.php:156-228`; `api/tests/Feature/Leave/HalfDayLeaveOverlapTest.php:23-45,106-127`; `api/tests/Feature/Leave/LeaveOverlapTwoConnectionHarnessTest.php:14-89`).
- Balance consumption/restoration lock the balance row and prevent over-consumption/over-crediting (`api/app/Modules/Leave/Services/LeaveBalanceService.php:27-63`; `api/tests/Feature/Leave/LeaveBalanceTest.php:100-214`).
- Year-end processing records an outbox request, deduplicates by year/scope, uses queue overlap protection, records per-employee dispositions, and fails rollover closed when positive prior balances lack a disposition (`api/app/Modules/Leave/Services/YearEndLeaveProcessingService.php:27-58`; `api/app/Modules/Leave/Listeners/RunYearEndLeaveOnRequested.php:23-43`; `api/app/Console/Commands/ResetLeaveBalancesForYear.php:51-82`).
- The SPA has visible leave filing/detail/type/calendar/year-end surfaces, and the detail page hides cancellation for non-owners. That UI control is useful polish but is not a substitute for the missing API authorization (`spa/src/pages/leaves/detail.tsx:86-120`).

## Findings

### M019-F01 — Broken/critical: department-head decision endpoints do not enforce department scope

Priority: **P0**  
Scope: **medium**  
Recommendation: **separate-recommended**

The single and bulk department-approval routes require only `leave.approve_dept`; reject uses the same broad department permission (`api/app/Modules/Leave/routes.php:37-44`). The controller passes the route-bound request directly to the service (`api/app/Modules/Leave/Controllers/LeaveRequestController.php:88-115,128-180`). `LeaveRequestService::approveDept()` and `reject()` check workflow state and delegate role/SoD checks to `ApprovalService`, but never compare the target employee's department with the approver's department (`api/app/Modules/Leave/Services/LeaveRequestService.php:257-276,366-383`; `api/app/Common/Services/ApprovalService.php:82-125,172-185`). The first workflow step is simply the `department_head` role (`api/database/seeders/WorkflowSeeder.php:24-30`).

List/show reads are department-scoped (`api/app/Modules/Leave/Services/LeaveRequestService.php:130-153`; `api/app/Modules/Leave/Controllers/LeaveRequestController.php:71-82`), but a department head who obtains another request's hash ID can still approve, reject, or bulk-approve it. Hash IDs are identifiers, not authorization. This is the same mutation/read split that the existing visibility matrix does not cover (`api/tests/Feature/Leave/LeaveRequestVisibilityTest.php:144-158`; `api/tests/Feature/Leave/LeaveRequestBulkApproveTest.php:27-72`).

Action: add one actor-aware decision policy inside the service transaction. Permit HR/system-admin all-record action explicitly, require same-department ownership for department heads, apply it to approve/reject and both bulk paths, and add cross-department negative API tests.

### M019-F02 — Broken/critical: any self-service user can cancel another employee's request

Priority: **P0**  
Scope: **small-to-medium**  
Recommendation: **separate-recommended**

The cancel route is protected only by `leave.create` (`api/app/Modules/Leave/routes.php:43-44`), and every employee-type role receives that permission (`api/database/seeders/RolePermissionSeeder.php:725-738`). The controller passes the route-bound model and authenticated user to the service without an ownership check (`api/app/Modules/Leave/Controllers/LeaveRequestController.php:117-121`). The service accepts the `$user` argument but never reads it; it only rejects already-cancelled/rejected rows and then mutates the request (`api/app/Modules/Leave/Services/LeaveRequestService.php:386-403`).

An arbitrary self-service caller can therefore cancel a pending or approved request by hash ID. For an approved request this also restores the target employee's balance and deletes the leave-marked attendance rows. The SPA's owner-only button (`spa/src/pages/leaves/detail.tsx:86-120`) does not protect direct API calls, and no cancellation authorization regression test was found.

Action: enforce owner-only cancellation for ordinary users and an explicitly documented HR/admin override inside the service transaction; lock and refresh the request before mutation, audit the actor, and add pending/approved cross-employee denial tests.

### M019-F03 — Broken/critical: leave approval and cancellation bypass locked-payroll attendance protection

Priority: **P0**  
Scope: **medium**  
Recommendation: **separate-recommended**

HR approval consumes the balance and directly calls `markAttendance()`; cancellation restores the balance and directly calls `unmarkAttendance()` (`api/app/Modules/Leave/Services/LeaveRequestService.php:279-305,386-403`). Neither path checks `PayrollPeriod` before changing attendance. `markAttendance()` uses `Attendance::updateOrCreate()` and `unmarkAttendance()` deletes rows by leave remark (`api/app/Modules/Leave/Services/LeaveRequestService.php:416-444`). Attendance contains payroll-relevant punch, hour, status, and manual-entry fields (`api/app/Modules/Attendance/Models/Attendance.php:20-42`).

Payroll defines finalized, disbursed, and voided periods as locked (`api/app/Modules/Payroll/Enums/PayrollPeriodStatus.php:30-40`), and the raw biometric importer explicitly blocks finalized/disbursed dates (`api/app/Modules/Attendance/Services/DTRImportService.php:132-134,207-226`). Leave approval/cancellation is an additional attendance writer with no equivalent guard. A late approval or cancellation can therefore rewrite or delete attendance that payroll considers immutable, changing a paid period's input after the fact.

Action: use the same authoritative attendance-date mutability guard as the Attendance hardening plan, including finalized/disbursed/voided semantics. Re-check payroll periods and attendance/request rows in one transaction, and define whether correction requires payroll void/force-unlock or an adjustment workflow.

### M019-F04 — Broken/high: half-day leave is persisted as full-day attendance and cancellation is lossy

Priority: **P1**  
Scope: **medium**  
Recommendation: **separate-recommended**

The request model and migration explicitly distinguish full-day (`NULL`) from AM/PM half-day requests (`api/database/migrations/0180_add_half_day_to_leave_requests.php:9-20`; `api/app/Modules/Leave/Models/LeaveRequest.php:35-43`). Submission correctly supports 0.5 days and AM/PM non-collision (`api/app/Modules/Leave/Services/LeaveRequestService.php:171-184,204-228`). But approval loops over every date and writes the same full-day `on_leave` status with zero hours, without reading `half_day_period` (`api/app/Modules/Leave/Services/LeaveRequestService.php:416-434`).

For an approved AM or PM request, DTR/payroll sees the whole date as leave rather than the requested half. If an attendance row already existed, the update overwrites its computed hours/status/remarks; cancellation then deletes the row because its remark now matches the leave request (`api/app/Modules/Leave/Services/LeaveRequestService.php:419-443`). The half-day tests stop at submission and do not exercise approval or rollback (`api/tests/Feature/Leave/HalfDayLeaveOverlapTest.php:23-104`).

Action: define the canonical attendance representation for half-day leave, preserve the prior attendance snapshot/lineage, make cancellation restore rather than delete unrelated data, and add AM/PM/full-day approval-cancel payroll tests.

### M019-F05 — Broken/high: the January retry window can erase new-year leave usage

Priority: **P1**  
Scope: **small-to-medium**  
Recommendation: **separate-recommended**

The rollover command runs daily during the first seven days of January (`api/routes/console.php:183-198`). Its `updateOrInsert()` always writes the target year's `used` to `0` and `remaining` to the recalculated total, even when the target balance row already exists (`api/app/Console/Commands/ResetLeaveBalancesForYear.php:112-135`). Thus, if an employee files and receives approved leave after the first rollover run, a retry on the next day resets that consumption. The existing reconciliation test proves only the initial carry-forward and same-year duplicate year-end job behavior; it does not consume a target-year balance between rollover retries (`api/tests/Feature/Leave/YearEndLeaveReconciliationTest.php:111-149`).

Action: make rollover idempotent with respect to an existing target balance: lock and preserve its used/remaining state, or record a one-time initialization marker and reject unsafe recalculation. Add a test sequence of first rollover → approved leave consumption → retry rollover.

### M019-F06 — Broken/high: cross-year requests charge one year's balance for multiple calendar years

Priority: **P1**  
Scope: **small-to-medium**  
Recommendation: **separate-recommended**

The default future window is 365 days (`api/database/migrations/0318_seed_remaining_reporting_window_settings.php:15-16`), while request validation applies the date window independently to start and end and does not require the dates to share a calendar year (`api/app/Modules/Leave/Requests/StoreLeaveRequestRequest.php:25-40`). Submission calculates all business days across the range but assigns one balance year from the start date (`api/app/Modules/Leave/Services/LeaveRequestService.php:182-199,405-414`). HR approval and cancellation also consume/restore only that start year (`api/app/Modules/Leave/Services/LeaveRequestService.php:293-298,395-399`).

A request spanning December and January can therefore consume/restore the wrong annual bucket, while the database stores no per-year allocation. Action: either reject cross-year ranges at validation/service level or split them into year-specific allocations with independent balance checks and approval/cancellation accounting; add boundary tests.

### M019-F07 — Incomplete/high: required-document leave types are configuration-only

Priority: **P1**  
Scope: **medium**  
Recommendation: **separate-recommended**

The seeded Sick, Maternity, Paternity, Solo Parent, VAWC, and Special Leave for Women types require documents (`api/database/seeders/LeaveTypeSeeder.php:14-22`). The request contract makes `document_path` an optional arbitrary string and has no conditional requirement or uploaded-file ownership/storage validation (`api/app/Modules/Leave/Requests/StoreLeaveRequestRequest.php:32-40`). The service persists the string as-is (`api/app/Modules/Leave/Services/LeaveRequestService.php:233-243`), and the filing SPA has no document field or upload path (`spa/src/pages/leaves/create.tsx:101-109,137-149`).

An employee can submit and progress a leave type marked `requires_document` with no supporting document, so the configured compliance rule is not part of the production workflow. Action: define the document storage/retention/access contract, validate a server-owned upload or signed attachment before submission/approval, expose it in the SPA, and test missing/valid/unauthorized attachments.

### M019-F08 — Broken/high: inactive types and missing balances fail open at submission and fail as a server error at approval

Priority: **P1**  
Scope: **small-to-medium**  
Recommendation: **separate-recommended**

The request service loads a leave type with `findOrFail()` but never requires `is_active`; its balance check is conditional and allows submission when no employee/type/year balance row exists (`api/app/Modules/Leave/Services/LeaveRequestService.php:182-199`). HR approval later calls `LeaveBalanceService::consume()`, which uses `firstOrFail()` for that same row (`api/app/Modules/Leave/Services/LeaveBalanceService.php:27-46`). The controller catches business-rule and insufficient-balance exceptions but not the missing-model failure (`api/app/Modules/Leave/Controllers/LeaveRequestController.php:99-105`). The SPA also lists leave types without requesting `is_active=true` (`spa/src/api/leave/index.ts:23-26`; `spa/src/pages/leaves/create.tsx:45-51`).

An inactive type or a balance-seeding gap can enter `pending_dept`, then produce a 500 instead of a controlled business response at HR approval. Action: enforce active type and balance existence/availability at the authoritative submission boundary, return a typed 422/manual-recovery state for seeding gaps, and filter the UI as a convenience only.

### M019-F09 — Broken/high: archived leave types cannot be restored

Priority: **P1**  
Scope: **small**  
Recommendation: **same-session only after authorization controls**

`LeaveType` uses `SoftDeletes`, and the controller calls `restore()` (`api/app/Modules/Leave/Models/LeaveType.php:14-16`; `api/app/Modules/Leave/Controllers/LeaveTypeController.php:52-56`). The restore route does not opt into `withTrashed()` (`api/app/Modules/Leave/routes.php:13-18`). The shared hash-ID binding trait only includes soft-deleted rows when that route opt-in is present (`api/app/Common/Traits/HasHashId.php:45-67`). The archived type consequently binds to 404 before the restore action, and `LeaveTypeService` has no alternate restore path (`api/app/Modules/Leave/Services/LeaveTypeService.php:14-41`).

Action: add soft-deleted binding, move restore through a service transaction, and add archive→restore API coverage.

### M019-F10 — Incomplete/high: employee leave-balance proration is bypassed by the synchronous seed

Priority: **P1**  
Scope: **small-to-medium**  
Recommendation: **separate-recommended**

The queued `InitializeLeaveBalances` listener documents and calculates hire-date proration, but uses `insertOrIgnore()` to preserve rows already created by the employee service (`api/app/Modules/HR/Listeners/InitializeLeaveBalances.php:14-26,38-66`). `EmployeeService::create()` synchronously inserts the current year's full default balance before emitting `EmployeeCreated` (`api/app/Modules/HR/Services/EmployeeService.php:217-251`). A mid-year hire therefore keeps a full-year credit and the queued prorating listener becomes a no-op.

Action: choose one authoritative initialization path, apply the intended hire-date rule exactly once, and add current-year mid-year-hire tests plus replay/idempotency coverage.

### M019-F11 — Incomplete/high: critical mutation and cross-module behavior lacks executable regression coverage

Priority: **P1**  
Scope: **medium**  
Recommendation: **separate-recommended**

The available tests cover read visibility, filing-for-others, balance arithmetic, overlap/employee locking, calendar reporting, bulk failure copy, and year-end disposition. They do not cover department-scope denial on approve/reject/bulk, cancel ownership, locked-payroll leave writes, approval/cancel attendance restoration, required documents, inactive/missing balances, cross-year boundaries, prorated hires, or archive restore. The focused suite could not execute in this environment, so none of those controls has current executable evidence here (`api/tests/Feature/Leave/LeaveRequestVisibilityTest.php:198-244`; `api/tests/Feature/Leave/LeaveRequestBulkApproveTest.php:27-188`; `api/tests/Feature/Leave/YearEndLeaveReconciliationTest.php:111-170`).

Action: add negative and positive API tests first, then integration tests against Attendance/PayrollPeriod and browser coverage for filing, approval, cancellation, documents, restore, and year-end retry behavior. Run them against PostgreSQL and a writable SPA/Vite cache.

## Polish pass

- The leave pages use the existing design-token vocabulary and provide detail loading/error states, confirmation dialogs, calendar loading/error handling, and a year-end modal (`spa/src/pages/leaves/detail.tsx:81-124`; `spa/src/pages/leaves/calendar.tsx`; `spa/src/pages/leaves/year-end.tsx`).
- The create page calculates estimated days client-side and shows a balance preview, but that is advisory; it does not replace the backend's missing active-type, document, cross-year, and balance contracts (`spa/src/pages/leaves/create.tsx:76-109,150-168`).
- Error messaging for the missing-balance approval path is not user-safe because the exception is outside the controller's typed 422 catches. Treat this as part of F08 rather than cosmetic polish.

## Production-audit assessment

### Blockers / high-value risks

1. M019-F01 allows a department-scoped approver to mutate another department's leave decisions.
2. M019-F02 allows any self-service user to cancel arbitrary requests and trigger balance/attendance side effects.
3. M019-F03 allows leave state changes to mutate attendance inside payroll-locked dates.
4. M019-F04 makes half-day approval and cancellation lossy for DTR/payroll data.
5. M019-F05 can erase approved January leave usage during the configured retry window.
6. M019-F11 leaves the critical controls without executable PostgreSQL/browser evidence.

### Evidence still missing

- A live PostgreSQL run of all 42 Leave feature tests, including the two-connection harness.
- Authenticated API proof for same-department success and cross-department approve/reject/bulk denial, arbitrary cancel denial, and HR/admin overrides.
- Attendance/payroll integration fixtures for approved/cancelled leave on finalized, disbursed, and voided periods, including existing punches and AM/PM leave.
- A rollover retry fixture with a target-year balance consumed between runs.
- Required-document upload/storage behavior, inactive/missing-balance behavior, cross-year allocation, prorated hire, and archive/restore fixtures.
- A writable Vite/Vitest cache and authenticated browser run for the leave chain and cancel/document/year-end UX.

### Next action

Do not promote M019 to Verified. In a separate hardening session, first centralize leave decision/cancellation authorization and payroll-date mutability, then define attendance/half-day rollback semantics. Repair rollover idempotency and type/document/balance contracts next; add the negative/integration/browser suite before any polish-only work.
