# Audit: leave — 2026-09-06

## Summary

The leave module is in good shape where it was hardest hit before: row scoping now delegates to the shared `DepartmentScope` ladder on list, the AQ-1/2/3 aggregator fix is in place (`ApprovalSourceScope` reuses the same scope), self-approval is blocked via `LeaveRequest::approvalSubmitterId()`, and balance races are genuinely closed (employee-row lock on submit, `lockForUpdate` in consume/restore, unique-index idempotency on year-end with passing tests). Balance mutations are transactional, cancel restores balance + attendance atomically with a fail-closed snapshot check. The real defects are at the edges: `max_carryover_days` is unwireable through API/UI despite driving year-end carryover; mid-year hires silently get FULL annual credits (the pro-rating listener is dead code); year-end processing can be pointed at a live year with no guard and auto-approves payroll adjustments; `is_paid` drives no behavior; and separation conversion is recorded nowhere on the leave side. 107 Leave-filtered tests pass (~92s).

## Findings

| ID | Category | Severity | Effort | Title | Location | Evidence/Repro |
|---|---|---|---|---|---|---|
| LV-01 | Broken process | High | S | `max_carryover_days` cannot be set: SPA sends it, FormRequests drop it, Resource omits it | api/app/Modules/Leave/Requests/StoreLeaveTypeRequest.php:26-38, UpdateLeaveTypeRequest.php:28-41; api/app/Modules/Leave/Resources/LeaveTypeResource.php:14-29; spa/src/pages/leaves/types.tsx:30,46,180 | LeaveType fillable + column + year-end cap logic (ProcessYearEndLeave.php:106,133) exist, but `'max_carryover_days'` is absent from both rule arrays → `$request->validated()` silently discards it; resource never returns it, so the admin form field "Max carryover (days)" always reads blank and never persists. Seeded types are all NULL = unlimited carryover. |
| LV-02 | Broken process | High | S | Mid-year hires receive FULL annual leave credits; pro-rating listener never wins | api/app/Modules/HR/Services/EmployeeService.php:224-241 vs api/app/Modules/HR/Listeners/InitializeLeaveBalances.php:41-66 | `EmployeeService::create()` synchronously `updateOrInsert`s `default_balance` (no proration) inside the create transaction; the queued `InitializeLeaveBalances` listener — whose docblock promises `default_balance × remaining_days/365` — uses `insertOrIgnore`, so it always finds the full-credit rows and no-ops ("leave_balances_already_present"). A July hire gets 15.0 VL; VL is `is_convertible_on_separation`, so the overgrant cashes out at separation. Re-hire in same year also resets `used=0` via `updateOrInsert`. |
| LV-03 | Risk | Medium | S | Year-end processing can run against a live/future year; encashment adjustments self-approve | api/app/Modules/Leave/Jobs/ProcessYearEndLeave.php:68-188; api/app/Modules/Leave/Requests/ProcessYearEndLeaveRequest.php:105-110 | Contract accepts any year 2020–2099; nothing asserts the year has ended. Running Sept 2026 for year 2026 zeroes every active employee's current balances mid-year and creates APPROVED PayrollAdjustments (created with `status=Approved`, `approved_by=runBy`, no maker-checker) for SIL encashment. Dedupe only prevents re-runs, not a wrong-year first run. |
| LV-04 | Risk | Medium | S | `conversion_rate` valid to 9.99; clamped at year-end but unclamped at separation | api/app/Modules/Leave/Requests/StoreLeaveTypeRequest.php:37 (`max:9.99`); api/app/Modules/Leave/Jobs/ProcessYearEndLeave.php:105 clamps to [0,1]; api/app/Modules/HR/Services/FinalPayService.php:331-338 unclamped `SUM(remaining × conversion_rate)` | Set rate=2.00 via API (allowed): year-end path clamps to 1.0, but separation final pay multiplies remaining × 2.0 → double cash-out of unused convertible leave. Money via config drift. |
| LV-05 | Gap | Medium | M | `LeaveType.is_paid` drives no behavior anywhere | api/app/Modules/Payroll/Services/PayrollCalculatorService.php:201-207 (`$leavePay = Money::zero()`; flat basic pays through leave); grep of is_paid usage | Payroll comment: paid leave "already inside basic_pay"; unpaid leave likewise adds nothing and subtracts nothing (flat cutoff basic). All 8 seeded types are paid, so impact today is zero, but the flag is dead: an unpaid type created via admin UI would still be fully paid. |
| LV-06 | Risk | Medium | M | Self-approval guard misses the actual filer when HR files on behalf; no guard if employee has no user | api/app/Modules/Leave/Models/LeaveRequest.php:77-84; api/app/Common/Services/ApprovalService.php:243-256; api/app/Modules/Leave/Controllers/LeaveRequestController.php:49-54 | `approvalSubmitterId()` resolves the EMPLOYEE's linked user, not the user who POSTed. HR (leave.approve_hr) may file for others; the filing HR user can then approve both stages (dept stage bypasses dept scope for approve_hr holders, HR stage is their own step) of a request they personally created. Employee without a user account → guard returns null → never fires. No `created_by` column on leave_requests to record the actual filer. |
| LV-07 | Gap | Medium | M | Separation conversion is not recorded or decremented on the leave side | api/app/Modules/HR/Services/FinalPayService.php:331-340 (reads); nothing in api/app/Modules/Leave writes | Final pay sums `remaining × conversion_rate` over ALL years (no year filter) but never zeroes/marks the balances; there is no separation analogue of `year_end_leave_dispositions`. Idempotency relies entirely on HR's clearance terminal-state guard; leave has no trace of what was converted. Cross-module → HR. |
| LV-08 | Gap | Medium | S | SL document enforced for ANY duration; documented rule is "3+ days" | api/app/Modules/Leave/Services/LeaveRequestService.php:216-221; docs/SEEDS.md:79 | `requires_document` is a type-level bool checked unconditionally at submit. A 1-day SL is refused without a medical certificate, contradicting the seeded "(3+ days)" policy note. Fails stricter than documented, not looser. |
| LV-09 | Risk | Low | S | Leave can be filed/approved for dates before hire | api/app/Modules/Leave/Requests/StoreLeaveRequestRequest.php:35-36; LeaveRequestService.php:167-209 | Window check is only `today − past_window_days` (seeded 30). An employee hired last week can submit (and get approved) leave dated before `date_hired`; balance exists (seeded at hire), attendance markers get created for pre-hire dates. No hire-date comparison anywhere in submit/approve. |
| LV-10 | Bad practice | Low | S | SPA "file for others" gate uses role slugs instead of the permission the backend checks | spa/src/pages/leaves/create.tsx:69 | `isAdmin = can('leave.view') && (role.slug === 'system_admin' \|\| role.slug === 'hr_officer')` while backend gates on `leave.approve_hr` (LeaveRequestController.php:49). Any other role granted `leave.approve_hr` loses the employee picker; violates the repo's "never add a role-name branch" rule. |
| LV-11 | Gap | Low | M | No API to initialize or correct balances; `LeaveBalanceService::seedFor()` is dead code | api/app/Modules/Leave/Services/LeaveBalanceService.php:16-26; api/app/Modules/Leave/routes.php (read-only balance routes) | `seedFor()` has zero callers (balance init lives in HR's EmployeeService/listener). HR has no endpoint to add credits, fix a wrong year, or initialize a balance row the error message tells employees to "Contact HR" about — corrections require direct DB writes or CLI rollover. |
| LV-12 | Other (doc drift) | Low | S | SCHEMA.md LEAVE section stale; Resource/SPA type mismatch on max_carryover_days | docs/SCHEMA.md:133-142; spa/src/types/leave.ts:8 | SCHEMA lists 3 tables (missing processed_year_end_leave_types, year_end_leave_dispositions), leave_requests statuses omit `cancelled`, and columns half_day_period/attendance_snapshot/cancelled_by/cancelled_at are absent; LeaveTypeResource never emits `max_carryover_days` that spa types declare (see LV-01). |
| LV-13 | Gap | Low | S | Detail page balance panel always fetches current year, not the request's year | spa/src/pages/leaves/detail.tsx:45-49,157-158 | `leaveBalancesApi.forEmployee(id)` with no year param; for a prior-year request the panel shows this year's balances (or "No balance record for {start_date year}" while having queried the wrong year). |

### Detail — LV-01 (max_carryover_days unwireable)
The carryover cap is load-bearing in `ProcessYearEndLeave` (`$cap = max_carryover_days`, non-convertible types carry `min(remaining, cap)` and forfeit the excess) and has a dedicated migration (0263) and a UI field. But both FormRequests' rule arrays omit the key, so `validated()` strips it before `LeaveTypeService::create/update` ever sees it, and the resource doesn't return it, so the edit form can't even display the stored value. Net effect: the cap is NULL for every type (unlimited carryover) and can only be changed by hand-editing the database. This is the kind of silent-drop the project's Zod-mirrors-FormRequest convention exists to prevent.

### Detail — LV-02 (full credits for mid-year hires)
Two writers race on the same unique key with opposite intents: the synchronous path (EmployeeService::create) writes FULL credits via `updateOrInsert`, the queued listener writes PRO-RATED credits via `insertOrIgnore`. Because the synchronous path always lands first, the listener's documented pro-ration never executes — its own docblock ("mid-year hires get a fraction") is false in production. Consequences compound: paid leave taken beyond earned entitlement, and `is_convertible_on_separation` types (VL, SIL) pay the unearned remainder out in cash at final pay. The fix is one-sided: either prorate in EmployeeService or let the listener update rows it didn't create.

### Detail — LV-03 (year-end against a live year)
`POST /leaves/process-year-end` validates the year's FORMAT (2020–2099) but not its plausibility. The job processes whatever year it is given: for a live year it zeroes every active employee's current balances and, for convertible types (SIL), immediately creates APPROVED PayrollAdjustments — money into the next payroll run, authorized by a single click, no second approver. The Dec-31 schedule and January recovery window are correct; the manual/API surface is not bounded to "year that has ended". Combined with LV-04's rate handling, this endpoint is the module's largest single-action blast radius.

## Cross-module flags

- **HR**: LV-02 (balance seeding belongs to HR's EmployeeService/listener pair), LV-07 (FinalPayService reads convertible balances without recording/decrementing; also no year filter in the SUM), and note `EmployeeService::create()` uses `updateOrInsert` which resets `used=0` on re-hire.
- **HR/Payroll**: LV-04 clamp asymmetry (ProcessYearEndLeave clamps conversion_rate; FinalPayService does not).
- **Payroll**: LV-05 — `is_paid` is inert because flat-cutoff basic pay pays through all leave; if unpaid leave types are ever seeded, payroll must consume `is_paid` (e.g. deduct via attendance OnLeave days) or the flag should be removed.
- **Common/Approvals**: workflow steps enforce `role_slug` match (department_head/hr_officer) even when a custom role holds the leave.approve_* permission — permissions open the route, role slug closes the step. System-wide, not leave-specific. Also `ApprovalService::resolveSubmitterUserId` returns null when the model can't resolve a submitter (guard silently off) — see LV-06.
- **Docs**: LV-12 — docs/SCHEMA.md LEAVE section needs refresh.

## What was NOT checked

- spa/src/pages/leaves/calendar.tsx internals (endpoint scoping WAS checked — dept head forced to own department, HR company-wide).
- Leave notification listeners/events (NotifyOn*) content; OutboxService and ChainListenerRunService internals.
- GlobalSearchService leave wiring beyond confirming ApprovalSourceScope delegation (AQ fix).
- Migrations 0026–0028 column-by-column vs SCHEMA (trusted existing schema + code casts).
- AttendanceDateMutabilityGuard internals (treated as attendance-owned; its fail-closed behavior is exercised by passing hardening tests).
- ApprovalDelegation edge cases beyond the documented delegator-submitter guard.
- No full-suite run; only `--filter='Leave'` (107 passed / 0 failed, 92s).

## Code-reading re-audit — 2026-09-14 (56e0d431)

### Re-audit verdict

The current commit materially closes LV-01 and LV-02. The backend now carries
`max_carryover_days` through validation, persistence, and the resource, and new
employee balance initialization uses one insert-if-absent, hire-date-prorated
path for both the synchronous service and queued listener. LV-03 through LV-13
remain open unless marked otherwise below. The new durable year-end handoff is a
real improvement, but it also exposes a retry dead end when the original actor
becomes unavailable.

### Prior finding status

| ID | Status at 56e0d431 | Current evidence |
|---|---|---|
| LV-01 | Resolved in backend; residual frontend type drift | Both LeaveType FormRequests accept the cap and the resource returns it (`api/app/Modules/Leave/Requests/StoreLeaveTypeRequest.php:37`, `UpdateLeaveTypeRequest.php:39`, `Resources/LeaveTypeResource.php:24`). See LV-17 for the duplicate SPA DTO. |
| LV-02 | Resolved | `EmployeeService::create()` calls `seedProratedFor()` synchronously (`api/app/Modules/HR/Services/EmployeeService.php:224-229`), and the listener delegates to the same insert-if-absent implementation (`api/app/Modules/HR/Listeners/InitializeLeaveBalances.php:43-59`; `LeaveBalanceService.php:45-79`). |
| LV-03 | Partially mitigated, unresolved | Durable outbox staging and actor checks exist, but there is still no assertion that the target year has ended before balances are zeroed and adjustments are created. |
| LV-04 | Unresolved | Year-end clamps the rate, but final-pay conversion still accepts the stored rate unchanged and sums every balance year. |
| LV-05 | Unresolved | Payroll still sets leave pay to zero without consulting `LeaveType::is_paid`. |
| LV-06 | Unresolved | Self-approval resolves the employee's linked user, not the authenticated filer; leave requests still have no actual submitter column. |
| LV-07 | Unresolved | Final pay reads convertible balances but leave-side conversion remains unrecorded and unconsumed. |
| LV-08 | Unresolved | `requires_document` is still enforced for every duration. |
| LV-09 | Unresolved | The request window still has no hire-date lower bound. |
| LV-10 | Unresolved | The create page still branches on `system_admin` and `hr_officer` role names. |
| LV-11 | Unresolved | `LeaveBalanceService::seedFor()` still has no caller and no balance correction endpoint exists. |
| LV-12 | Unresolved documentation drift | `docs/SCHEMA.md:133-142` still omits current leave tables, columns, and `cancelled` status. |
| LV-13 | Unresolved | The detail page still requests the current/default year instead of the request's year. |

### Findings

#### LV-03 - Live or future year-end processing remains possible

- **Category:** Risk
- **Severity:** Medium
- **Effort:** S
- **Location:** `api/app/Modules/Leave/Requests/ProcessYearEndLeaveRequest.php:104-110`; `api/app/Modules/Leave/Controllers/LeaveTypeController.php:61-70`; `api/app/Modules/Leave/Jobs/ProcessYearEndLeave.php:70-150`; `spa/src/pages/leaves/year-end.tsx:47-57`
- **Evidence/reproduction:** The request validates only the supported numeric year contract. In September 2026, submit `year=2026` (the SPA defaults to the current year); the queued job will lock and zero active employees' 2026 balances and create approved encashment adjustments. The Dec-31 scheduler does not protect the manual API, CLI, or modal path.
- **Status change:** The durable outbox/chain handoff reduces lost-run risk, but it does not address the destructive target-year invariant or the maker-checker bypass for the generated payroll adjustment.

#### LV-04 - Separation conversion rate and year selection remain unsafe

- **Category:** Risk
- **Severity:** Medium
- **Effort:** S
- **Location:** `api/app/Modules/Leave/Requests/StoreLeaveTypeRequest.php:36`; `api/app/Modules/HR/Services/FinalPayService.php:490-504`
- **Evidence/reproduction:** The API accepts `conversion_rate=2.00` through `9.99`. Year-end clamps this value to `1.0` (`api/app/Modules/Leave/Jobs/ProcessYearEndLeave.php:105`), but final pay computes `SUM(elb.remaining * lt.conversion_rate)` across all years with no `[0,1]` clamp or target-year filter. A convertible balance can therefore be paid at more than 100 percent and old years can be paid again.

#### LV-05 - `is_paid` still has no payroll effect

- **Category:** Gap
- **Severity:** Medium
- **Effort:** M
- **Location:** `api/app/Modules/Payroll/Services/PayrollCalculatorService.php:201-211`; `api/database/seeders/LeaveTypeSeeder.php:15-22`
- **Evidence/reproduction:** `PayrollCalculatorService` always assigns `Money::zero()` to leave pay and relies on flat cutoff basic pay. Create or edit a leave type with `is_paid=false`, approve a request for it, and the employee's pay is unchanged from a paid leave request. The field remains exposed and editable, so the UI presents a policy switch with no effect.

#### LV-06 - Actual filer is still absent from self-approval evidence

- **Category:** Risk
- **Severity:** Medium
- **Effort:** M
- **Location:** `api/app/Modules/Leave/Models/LeaveRequest.php:29-34,73-84`; `api/app/Modules/Leave/Controllers/LeaveRequestController.php:43-53`; `api/app/Common/Services/ApprovalService.php:129-132,243-255`
- **Evidence/reproduction:** HR can submit a request for another employee, but the model derives the submitter from that employee's linked user. If the target employee has no linked user, `approvalSubmitterId()` returns null and the SoD guard is disabled; if the target has a different linked user, the authenticated HR filer is not the submitter as far as approval is concerned. There is no `created_by`/`submitted_by` field on the request.

#### LV-07 - Separation conversion remains unrecorded on the leave side

- **Category:** Gap
- **Severity:** Medium
- **Effort:** M
- **Location:** `api/app/Modules/HR/Services/FinalPayService.php:490-505`; `api/app/Modules/Leave/Models/YearEndLeaveDisposition.php:23-35`
- **Evidence/reproduction:** Final pay reads every convertible balance directly and multiplies it by the type rate. No Leave service creates a separation disposition, marks the balance converted, or links a clearance/final-pay run. Recomputing before the HR clearance terminal guard, or inspecting leave history after payment, has no leave-side record of what was cashed out.

#### LV-08 - Required documents still ignore the documented duration rule

- **Category:** Broken process
- **Severity:** Medium
- **Effort:** S
- **Location:** `api/app/Modules/Leave/Services/LeaveRequestService.php:216-221`; `docs/SEEDS.md:79`
- **Evidence/reproduction:** Any active leave type with `requires_document=true` is rejected without an upload, including a one-day SL request. The seeded policy says Sick Leave requires a document for 3+ days, but the current schema and service have no duration threshold.

#### LV-09 - Pre-hire leave dates are still accepted

- **Category:** Risk
- **Severity:** Low
- **Effort:** S
- **Location:** `api/app/Modules/Leave/Requests/StoreLeaveRequestRequest.php:28-36`; `api/app/Modules/Leave/Services/LeaveRequestService.php:179-209`
- **Evidence/reproduction:** A newly hired employee whose hire date is within the configured 30-day past window can submit a leave range dated before `employees.date_hired`. The service checks calendar year, business days, type, document, balance, and overlap, but never the employee hire date; approval can then create attendance `on_leave` markers for pre-employment dates.

#### LV-10 - Filing-for-others UI remains role-name gated

- **Category:** Bad practice
- **Severity:** Low
- **Effort:** S
- **Location:** `spa/src/pages/leaves/create.tsx:67-83`; `api/app/Modules/Leave/Controllers/LeaveRequestController.php:46-53`
- **Evidence/reproduction:** The backend uses `hasPermission('leave.approve_hr')`, while the SPA enables the employee picker only for `system_admin` or `hr_officer`. A custom role granted the backend permission can file for others but receives the self-only form; a role-name change silently breaks the UI without changing authorization.

#### LV-11 - Balance correction remains a dead-end

- **Category:** Gap
- **Severity:** Low
- **Effort:** M
- **Location:** `api/app/Modules/Leave/Services/LeaveBalanceService.php:17-27`; `api/app/Modules/Leave/routes.php:29-32`
- **Evidence/reproduction:** `seedFor()` is the only occurrence of that method and is not called. The exposed balance routes are read-only, while submission tells an employee to contact HR when a row is missing. HR has no authorized API to initialize, correct, or reconcile a balance without direct database/CLI intervention.

#### LV-12 - Schema reference remains stale

- **Category:** Gap
- **Severity:** Low
- **Effort:** S
- **Location:** `docs/SCHEMA.md:133-142`; `api/app/Modules/Leave/Models/LeaveRequest.php:29-45`; `api/app/Modules/Leave/Models/ProcessedYearEndLeaveType.php:12-32`; `api/app/Modules/Leave/Models/YearEndLeaveDisposition.php:19-35`
- **Evidence/reproduction:** The schema document still describes three leave tables only, omits `processed_year_end_leave_types` and `year_end_leave_dispositions`, omits `half_day_period`/attendance snapshot/cancellation fields, and does not list `cancelled` among request statuses. Current models and resources use those fields.

#### LV-13 - Detail balance panel still queries the wrong year

- **Category:** Gap
- **Severity:** Low
- **Effort:** S
- **Location:** `spa/src/pages/leaves/detail.tsx:43-48`; `spa/src/api/leave/index.ts:39-45`
- **Evidence/reproduction:** The API accepts an optional year, but the detail page calls `leaveBalancesApi.forEmployee(req.employee.id)` without passing `new Date(req.start_date).getFullYear()`. Opening a prior-year request displays current-year balances or says no balance exists for the request year despite the correct row being available.

#### LV-14 - Manual-required year-end runs cannot be re-staged with a replacement actor

- **Category:** Stuck process
- **Severity:** Medium
- **Effort:** M
- **Location:** `api/app/Modules/Leave/Services/YearEndLeaveProcessingService.php:39-56`; `api/app/Modules/Leave/Listeners/RunYearEndLeaveOnRequested.php:46-63`; `api/app/Common/Services/OutboxService.php:34-56`; `api/app/Common/Services/OutboxDispatcher.php:48-99`
- **Evidence/reproduction:** The year/scope dedupe key excludes `runById`. If the actor is deactivated after staging, the listener records `manual_required` and returns (`RunYearEndLeaveOnRequested.php:52-63`). The outbox message is then published; a later API/CLI request for the same year returns the existing payload with the old actor, and the dispatcher ignores published messages. Recovery can requeue failed outbox rows, but this path is not failed and has no Leave-specific replacement-actor or re-stage action.

#### LV-15 - Bulk approval failure responses expose raw integer IDs

- **Category:** Risk
- **Severity:** Medium
- **Effort:** S
- **Location:** `api/app/Modules/Leave/Services/LeaveRequestService.php:371-388,400-418`; `api/app/Modules/Leave/Controllers/LeaveRequestController.php:151-155,180-184`; `spa/src/api/leave/index.ts:86-101`
- **Evidence/reproduction:** A failed bulk row is returned as `['id' => $id, 'reason' => ...]`, where `$id` is the decoded integer primary key. The controller passes that array directly into JSON. The SPA type intentionally omits `id`, but type omission does not remove the raw integer from the network response and violates the project HashID-only response rule.

#### LV-16 - Approval option labels disagree with the canonical status enum

- **Category:** Bad practice
- **Severity:** Low
- **Effort:** S
- **Location:** `api/app/Modules/Leave/Controllers/LeaveRequestController.php:27-34`; `api/app/Modules/Leave/Enums/LeaveRequestStatus.php:15-23`; `api/app/Modules/Leave/Resources/LeaveRequestResource.php:36-37`
- **Evidence/reproduction:** The options endpoint generates `Pending dept` and `Pending hr` by replacing underscores, while the resource emits the canonical `Pending department head` and `Pending HR approval` labels. The list and detail pages consume the options endpoint, so the same request has different status copy from resource-backed consumers; the E2E fixture currently preserves the discrepancy rather than detecting it.

#### LV-17 - Duplicate SPA leave-type DTO omits the now-supported carryover cap

- **Category:** Bad practice
- **Severity:** Low
- **Effort:** S
- **Location:** `spa/src/api/leave/index.ts:9-21`; `spa/src/types/leave.ts:20-33`; `spa/src/pages/leaves/types.tsx:46-49`
- **Evidence/reproduction:** The canonical `CreateLeaveTypeData` includes `max_carryover_days`, and the page sends it, but the API module redeclares a second DTO without that field. Runtime payloads currently work because excess properties survive through an inferred object, but callers using the exported API type cannot express the supported field and the two contracts can drift again.

#### LV-18 - Leave-type mutation form does not disable submit while pending

- **Category:** Bad practice
- **Severity:** Low
- **Effort:** S
- **Location:** `spa/src/pages/leaves/types.tsx:78-90,170-195`
- **Evidence/reproduction:** Create and update mutations expose `isPending`, but the Save button only receives `loading` and never `disabled`. A double click can issue duplicate create/update requests; the create path relies on the unique code constraint to reject the second request rather than preventing it in the UI. This violates the mandatory form pending-state pattern.

### Clean areas confirmed by source reading

- Leave resources and employee/type/request relations expose HashIDs rather than integer primary keys in normal success payloads (`api/app/Modules/Leave/Resources/*.php`).
- Leave request submission now serializes on the employee row before overlap evaluation and locks the balance row before consumption (`api/app/Modules/Leave/Services/LeaveRequestService.php:172-243`); the scoped tests include a two-connection harness.
- Half-day overlap semantics, Sunday-only rejection, cross-year rejection, private-disk document storage, MIME validation, and attendance snapshot restoration are represented in current code and focused tests.
- Department row visibility delegates to `DepartmentScope`, and the approval board has Leave visibility coverage for self, department, HR, and unlinked users.
- Approval transitions, balance consumption/restoration, attendance mutation, and outbox recording occur inside transaction boundaries; approval refusals are typed as `ForbiddenActionException` and rendered as 403s.
- Year-end processing now has durable outbox staging, idempotent type/year and employee/type/year records, a January recovery schedule, and rollover preflight that refuses positive balances without a disposition.

### Verification limits

- This was a read-only source and test-reading audit. No PHPUnit/Vitest/Playwright tests, Docker commands, Artisan commands, queue workers, database queries, or browser sessions were run.
- Existing test files and the prior audit's reported Leave-filtered result were inspected but not independently verified at this commit.
- Shared approval, outbox, DepartmentScope, HR final-pay, payroll-calculation, scheduler, and rollover code was read only where directly relevant to Leave findings; this is not a re-audit of those owning units.
- Migration history was not re-run or compared column-by-column; `docs/SCHEMA.md` drift is reported from the current source/document mismatch.
- Browser and live-provider behavior, queue timing, Redis locks, and concurrent production database behavior remain unverified beyond the static harnesses and focused assertions present in the repository.

### No code changes

No application code, migration, test, registry, or roadmap was modified. The only write in this pass was appending this section to `audit/leave.md`; pre-existing unrelated worktree changes were left untouched.

## Live browser/RBAC verification — 2026-09-14

Environment: dedicated `ogami_e2e_20260914` PostgreSQL database, full reference
and demo seed, Firefox 150 headless at 1440x900, one browser context, and logout
between every role switch. The seeded employee account initially had no balance
rows; the existing `LeaveBalanceService::seedFor()` was used only to add the
missing test fixture after recording the failure.

### LV-LIVE-001 - Seeded employee cannot start the leave workflow

- **Category:** Gap
- **Severity:** Medium
- **Effort:** S
- **Location:** `api/database/seeders/DemoAccountSeeder.php` (employee account creation); `api/app/Modules/Leave/Services/LeaveBalanceService.php:17-27`
- **Evidence/reproduction:** Log in as the seeded `employee@ogami.test`, open `/self-service/leave`, choose a valid non-document leave type, and submit a one-day request. The UI showed the normal form, but the live API returned HTTP 422: `Leave balance is not initialized for this employee, leave type, and year. Contact HR before submitting.` `GET /api/v1/leaves/balances/me` returned `{"data":[]}`. The seeded user therefore cannot create the representative hire-to-retire leave document until an operator runs a CLI/service fixture manually.
- **Impact:** The documented demo account cannot exercise its primary self-service leave capability from a reset environment. This is a seed/reset completeness gap, not an authorization bypass.
- **Verification:** After invoking the existing balance seeding service for the employee and 2026, the same UI flow created a request successfully with hash ID `jlBNQENmr3` and status `pending_dept`.
- **Screenshot:** `spa/test-results/live-failure.png` captured at the live failure point; later failures reused this path.

### Live controls verified

- Admin reached `/admin/users`, `/hr/employees`, `/accounting/journal-entries`, `/production/work-orders`, and `/quality/inspections`; the admin employee API returned 200.
- Employee direct API access to `/api/v1/hr/employees` and `/api/v1/admin/users` returned 403; employee navigation to `/hr/employees` rendered the denied/not-found state.
- Employee leave form validation displayed a field-level error on an empty submit.
- Department head approval moved the request `pending_dept → pending_hr`; a direct department-head call to the HR approval endpoint returned 403.
- HR approval moved the request `pending_hr → approved`; the employee balance response changed after approval and the approved request appeared in the refreshed self-service list.
- The department-head notification feed returned HTTP 200 after submission. The live pass did not independently prove a specific notification item was delivered, so notification delivery remains a verification gap.

### Live verification limits

- The full three-chain lifecycle was not completed in the browser during this pass; only the Leave representative chain was walked end to end.
- The repository API RBAC harness ran separately: 21 checks passed, while two expectations were stale or fixture/permission dependent (`finance` JE self-post expected 422 but returned 403; loan over-cap expected 422 but returned 403). These were not classified as application defects without reconciling the harness endpoints and current seeded permissions.
- No application code was modified. The dedicated test database and seeded fixture were mutated only by the tested workflows and the missing-balance setup described above.
