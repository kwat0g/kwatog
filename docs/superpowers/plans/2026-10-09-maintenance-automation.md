# Maintenance Automation and Verified Recovery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make corrective and preventive maintenance a shift-aware, role-based workflow with suggested assignments, safe production handoff, verified recovery, and measured downtime and parts cost.

**Architecture:** Keep maintenance and production database state authoritative. Reuse the existing breakdown, preventive-generation, attendance, stock, and downtime services; add narrow suggestion, verification, and scheduling seams. Ship independently testable releases with additive migrations and a default-off verification gate for existing operational orders.

**Tech Stack:** Laravel/PHP, PostgreSQL, React/TypeScript, TanStack Query, PHPUnit, Playwright.

**Spec:** `docs/superpowers/specs/2026-10-09-maintenance-automation-design.md`

## Global Constraints

- The target demonstration site is `47.129.228.44`; treat its database as operational until confirmed otherwise.
- No administrator account in manual tests. Never run `DemoAccountSeeder` or reset the target database.
- Preserve `MWO-202610-0007` and every pre-release order on the legacy completion path.
- Desktop retains the system table and detail layout; phones use cards and touch controls on the same URLs.
- Suggestions never auto-start work, release production, issue stock, approve recovery, or resume production.
- Label financial data `Parts cost`; labor cost and savings are not measured.
- New remote deployment, migration, account provisioning, and live testing require their own reviewed release; this plan only changes local documentation.

## File map and release boundaries

| Release | Owned files and responsibility |
| --- | --- |
| A: staffing | `api/app/Modules/Attendance/Services/EmployeeAvailabilityService.php` owns shared shift/presence evaluation; Production's `WorkOrderAssignmentService.php` delegates without behavior change. `api/app/Modules/Maintenance/Services/MaintenanceAssigneeSuggestionService.php` ranks Maintenance candidates. Maintenance controller/routes/request/service enforce a fresh choice for new verified-workflow orders. Work-order detail and API client display reasons. |
| A: routing | `api/app/Modules/Maintenance/Listeners/NotifyMaintenanceWorkOrder.php` and `api/app/Console/Commands/CheckMaintenanceAssignment.php` route new work, assignment, and one critical escalation through `NotificationService`. Verification notices join the same channel in release B. Existing catalog, event registration, and scheduler receive only needed entries. |
| B: recovery | An additive maintenance-work-order migration stores verification state/evidence. Maintenance service/controller/resource/routes own submission, rejection, and acceptance. Production Manager and Maintenance Supervisor permissions are granted through the seeder for fresh databases and a narrow data migration for existing ones. Work-order detail and responsive detail expose readiness, work log, verifier actions, and result. |
| C: preventive | Add duration to Maintenance schedules and a confirmed maintenance-window table. `MaintenanceWindowService.php` suggests and confirms intervals. Production capacity/reservation reads include confirmed maintenance windows. Schedule and work-order UI support review. |
| D: assistance | Extend existing part lookup and stock response with target-history ranking and availability reasons. Add a repeat-breakdown query/notification using distinct downtime incidents. No new purchasing or stock movement path. |

Execution order: Tasks 1–3 establish staffing and routing; Tasks 4–6 add verified recovery; Task 7 adds shift handover; Tasks 8–9 add preventive windows; Task 10 adds parts/reliability assistance; Task 11 proves the full flow. Each release must pass its own focused tests before the next begins. Stage only files belonging to the release: this workspace already has unrelated changes.

### Task 1: Share Production's attendance interpretation

**Files:**
- Create: `api/app/Modules/Attendance/Services/EmployeeAvailabilityService.php`
- Modify: `api/app/Modules/Production/Services/WorkOrderAssignmentService.php`
- Test: `api/tests/Feature/Maintenance/MaintenanceAssigneeSuggestionTest.php`
- Test: existing Production worker-assignment tests

**Interfaces:** Produce `presence(Employee $employee, Carbon $at): array` and `coverageBlocker(Employee $employee, Carbon $start, Carbon $end): ?string`. Maintenance consumes both; Production's public `presence` preserves its existing response shape and delegates.

- [ ] Add cases for present, unverified, absent, leave, clock-out, missing working days, and a previous-day night shift. Run `docker compose exec -T api php artisan test tests/Feature/Maintenance/MaintenanceAssigneeSuggestionTest.php`; expect red.
- [ ] Move only shared HR shift, leave, and punch interpretation into `EmployeeAvailabilityService`. Keep Production crew conflicts inside Production. Make the existing public method delegate:

```php
public function presence(Employee $employee, Carbon $at): array
{
    return $this->availability->presence($employee, $at);
}
```

- [ ] Run the new test and Production worker-assignment suites. Compare each presence state and reason with the baseline. Commit only Task 1 files.

### Task 2: Suggest and validate Maintenance technicians

**Files:**
- Create: `api/app/Modules/Maintenance/Services/MaintenanceAssigneeSuggestionService.php`
- Modify: `api/app/Modules/Maintenance/Controllers/MaintenanceWorkOrderController.php`, `api/app/Modules/Maintenance/Services/MaintenanceWorkOrderService.php`, `api/app/Modules/Maintenance/Requests/AssignMaintenanceWorkOrderRequest.php`, `api/app/Modules/Maintenance/routes.php`
- Modify: `api/database/seeders/RolePermissionSeeder.php`
- Create: `api/database/migrations/2026_10_09_230100_add_maintenance_supervisor_role.php` for existing deployments
- Modify: `spa/src/api/maintenance/workOrders.ts`, `spa/src/types/maintenance.ts`, `spa/src/pages/maintenance/work-orders/detail.tsx`, `spa/src/pages/maintenance/work-orders/responsive-detail.tsx`
- Test: `api/tests/Feature/Maintenance/MaintenanceAssigneeSuggestionTest.php`, `spa/e2e/maintenance-assignment-suggestion.spec.ts`

**Interfaces:** `suggest(MaintenanceWorkOrder $order, Carbon $start, Carbon $end): array` returns `suggested_employee_id`, `evaluated_at`, and ordered candidate rows with `presence`, `active_load`, `can_assign`, `can_attest_presence`, and `blocked_reason`. `GET /maintenance/work-orders/{workOrder}/assignee-suggestions` requires `maintenance.wo.assign`. Assignment accepts `employee_id`, `expected_assignee_id`, optional `presence_attestation_reason`, and an in-progress `handover_note`.

- [ ] Write red tests: two eligible technicians rank by current presence then active load; off-shift, leave, absent, clocked-out, inactive, non-Maintenance, and busy staff show reasons; no eligible candidate returns no suggestion. Future intervals do not claim a worker is already present.
- [ ] Implement one ranking pass over active MAINT technicians with active Maintenance Technician accounts. For immediate work, require current shift coverage and verified presence; allow unverified presence only with supervisor attestation. Use employee number as deterministic final tie-breaker. Do not score skills because the employee model has no qualification evidence.
- [ ] Put the Maintenance Supervisor role/grants in a narrow additive data migration for existing deployments; keep `RolePermissionSeeder` aligned for fresh installs. Do not create demo users. Recalculate eligibility inside `assign()` for verification-required orders after locking the work order. Reject a stale expected assignee or changed attendance with a field error; require a handover note when replacing an In progress assignee. Append an actor-attributed maintenance log. For new verified-workflow orders, recheck at Start; legacy orders retain their old assignment/start contract.

```php
if (! $candidate['can_assign'] && ! ($candidate['can_attest_presence'] && trim((string) $presenceAttestationReason) !== '')) {
    throw ValidationException::withMessages(['employee_id' => [$candidate['blocked_reason']]]);
}
```

- [ ] In the desktop assignment panel and phone detail, show Suggested, Available, Unverified, and Blocked choices with reasons. Legacy orders show advisory reasons without changing their assignment contract. Keep manual supervisor selection and server validation for new orders. Add a Playwright case that changes attendance after loading suggestions and receives a useful refresh error.
- [ ] Run Maintenance role tests, Production worker-assignment tests, `npm run typecheck`, and the new browser test. Commit only Task 2 files.

### Task 3: Route new work and escalate unassigned critical orders

**Files:**
- Create: `api/app/Modules/Maintenance/Listeners/NotifyMaintenanceWorkOrder.php`, `api/app/Console/Commands/CheckMaintenanceAssignment.php`
- Modify: `api/app/Providers/AppServiceProvider.php`, `api/app/Common/Services/NotificationCatalog.php`, `api/routes/console.php`, `api/app/Modules/Maintenance/Services/MaintenanceWorkOrderService.php`, `spa/src/pages/maintenance/work-orders/index.tsx`, `spa/src/api/maintenance/workOrders.ts`
- Test: `api/tests/Feature/Maintenance/MaintenanceRoutingNotificationTest.php`

**Interfaces:** The creation listener consumes `MaintenanceWorkOrderCreated` and sends supervisor notices with dedupe key `maintenance:new:<order-id>`. Assignment sends a user-targeted notice using the same `NotificationService` contract. The scheduled command scans Critical/Open/unassigned orders older than 15 minutes; it sends once per unassigned episode. Task 5 adds submission and rejection notices.

- [ ] Write red tests: duplicate event/command deliveries produce one notice per recipient; a due preventive order and breakdown order both reach a supervisor; no configured supervisor records a setup failure; assigning closes the escalation episode.
- [ ] Register a narrow listener, notification catalog types, and a five-minute command schedule. Use `NotificationService::send()` with durable dedupe keys; keep mail opt-in. The escalation does not change priority, assignment, or machine status.

```php
$this->notifications->send($supervisors, 'maintenance.assignment_needed', $payload, 'maintenance:new:'.$order->id);
```

- [ ] Add an assigned-tech notice to the transition that commits the assignment, not to the frontend click. Give supervisors server-filtered Unassigned and Critical queues on the existing work-order list. Run the focused test and `php artisan schedule:list` locally. Commit only Task 3 files.

### Task 4: Show production handoff and maintenance readiness

**Files:**
- Modify: `api/app/Modules/Maintenance/Services/MaintenanceWorkOrderService.php`, `api/app/Modules/Maintenance/Resources/MaintenanceWorkOrderResource.php`
- Modify: `spa/src/types/maintenance.ts`, `spa/src/pages/maintenance/work-orders/detail.tsx`, `spa/src/pages/maintenance/work-orders/responsive-detail.tsx`
- Test: `api/tests/Feature/Maintenance/MaintenanceOperationalSafetyTest.php`, `spa/e2e/maintenance-handoff.spec.ts`

**Interfaces:** Work-order detail gains a `readiness` object: machine status, linked production work-order ID/number, production ownership or reservation blocker, sibling maintenance blocker, and required human action. The existing Start endpoint remains authoritative.

- [ ] Write a red test for the observed case: an Open order on a machine with current production ownership identifies the linked production work order and its Release resources action; Start remains rejected. An idle machine reports ready. A second active maintenance order blocks Start.
- [ ] Read Production's existing `releaseResources()` and machine ownership fields; return a scoped link and plain blocker copy. Do not automatically call release. Show the blocker near Start and link Production's detail page for users with access.
- [ ] Browser-test desktop and phone: the blocked state is visible before Start; the production link survives refresh. Run the focused API/browser tests. Commit only Task 4 files.

### Task 5: Add compatible, independently verified repair closure

**Files:**
- Create: `api/database/migrations/2026_10_09_230200_add_maintenance_repair_verification.php` for `verification_required`, diagnosis, repair action, test result, submitted/verified actor IDs and timestamps
- Create: `api/app/Modules/Maintenance/Requests/SubmitMaintenanceRepairRequest.php`, `api/app/Modules/Maintenance/Requests/VerifyMaintenanceRepairRequest.php`
- Modify: `api/app/Modules/Maintenance/Models/MaintenanceWorkOrder.php`, `api/app/Modules/Maintenance/Controllers/MaintenanceWorkOrderController.php`, `api/app/Modules/Maintenance/Services/MaintenanceWorkOrderService.php`, `api/app/Modules/Maintenance/Services/SparePartUsageService.php`, `api/app/Modules/Maintenance/Resources/MaintenanceWorkOrderResource.php`, `api/app/Modules/Maintenance/routes.php`, `api/database/seeders/RolePermissionSeeder.php`, `api/database/seeders/SettingsSeeder.php`
- Create: `api/database/migrations/2026_10_09_230300_grant_maintenance_verification.php` for already-deployed databases
- Test: `api/tests/Feature/Maintenance/MaintenanceVerifiedRecoveryTest.php`

**Interfaces:** `POST /maintenance/work-orders/{workOrder}/submit-repair` requires assigned-tech execution permission and three nonempty evidence fields. `POST /maintenance/work-orders/{workOrder}/verify` accepts `decision` (`accept` or `reject`) and a required rejection reason. Corrective machine verification requires Production Manager; preventive machine and mold verification requires Maintenance Supervisor. The legacy Complete route rejects `verification_required=true`.

- [ ] Write red tests for submit, reject, resubmit, accept, cross-role denial, self-verification denial, part/log immutability while pending, and duplicate accepted request. Include historical orders and `MWO-202610-0007`-shaped fixtures proving old completion remains valid.
- [ ] Add nullable evidence/timestamp columns and `verification_required boolean default false`. Seed `maintenance.closed_loop.enabled=false` through an additive migration; new order creation reads it. The production data migration grants `maintenance.wo.verify` to Production Manager and Maintenance Supervisor without granting administrator permissions; fresh installs get the same grants through the role seeder.
- [ ] Under the work-order lock, submit evidence and append a full snapshot log. While pending, block part issue/return, log edits, cancellation, and direct Complete. On rejection append reason and clear pending marker while equipment and downtime stay unchanged. Extract the existing completion transaction into a private finalization method used by legacy Complete and accepted verification; it closes downtime, restores eligible equipment/asset, updates schedule, stores verifier, and appends a log. A repeated acceptance by the same verifier returns the already-completed order without repeating side effects. Send submission and rejection notices after commit.

```php
if ($order->verification_required && $order->repair_submitted_at === null) {
    throw ValidationException::withMessages(['status' => ['Submit repair evidence before verification.']]);
}
```

- [ ] Run Maintenance, Asset association, Machine breakdown concurrency, stock-cost, and GL posting tests. Verify no direct Complete bypass for new orders and no service-side change to historical orders. Commit only Task 5 files.

### Task 6: Make execution and recovery evidence visible

**Files:**
- Modify: `api/app/Modules/Maintenance/Resources/MaintenanceWorkOrderResource.php`, `api/app/Modules/Maintenance/Services/MaintenanceWorkOrderService.php`
- Modify: `spa/src/api/maintenance/workOrders.ts`, `spa/src/types/maintenance.ts`, `spa/src/pages/maintenance/work-orders/detail.tsx`, `spa/src/pages/maintenance/work-orders/responsive-detail.tsx`
- Modify: `spa/src/pages/maintenance/work-orders/index.tsx`
- Test: `spa/e2e/maintenance-verified-recovery.spec.ts`, `api/tests/Feature/Maintenance/MaintenanceVerifiedRecoveryTest.php`

**Interfaces:** The resource adds `repair_evidence`, `repair_submitted_at`, `verifier`, `verified_at`, `open_downtime_started_at`, `server_time`, `readiness`, and action tokens `submit_repair` and `verify_repair`. The API client exposes `addLog` (already present), `submitRepair`, and `verifyRepair`.

- [ ] Add red API assertions that an open downtime row supplies its actual start timestamp and that a completed order supplies closed duration, net parts cost, and verifier attribution. Add a browser test for a technician log note, evidence submission, rejection, resubmission, acceptance, and hidden actions for the wrong role.
- [ ] Add an Add work log control to the existing Logs panel. Show a repair-evidence form to the assigned technician, a review panel to the permitted verifier, and a chronological result panel after acceptance. Keep the existing desktop detail grid and phone layout.
- [ ] Derive the live timer from the API-provided downtime start and server time; mark it provisional. On completion, replace it with stored actual downtime. Present three different measures with exact labels:

```text
Response time = started_at - created_at
Technician repair time = repair_submitted_at - started_at
Recovery downtime = closed MachineDowntime duration
Parts cost = issued stock value - returned stock value
```

- [ ] Preserve the existing legacy Complete control only when `verification_required=false`. Add links to the stock movement, paused production order, and Downtime analytics only when the viewer has their ordinary read permission.
- [ ] Add server-filtered Blocked by production, Awaiting verification, In progress, and Overdue preventive views to the existing list. Compute each category on the server so pagination cannot hide matching orders.
- [ ] Run `npm run typecheck`, the new Playwright test at desktop and phone widths, and the focused API tests. Commit only Task 6 files.

### Task 7: Suggest shift-end handover without moving ownership

**Files:**
- Modify: `api/app/Console/Commands/CheckMaintenanceAssignment.php`, `api/app/Modules/Maintenance/Services/MaintenanceAssigneeSuggestionService.php`, `api/app/Modules/Maintenance/Services/MaintenanceWorkOrderService.php`
- Modify: `spa/src/pages/maintenance/work-orders/detail.tsx`, `spa/src/pages/maintenance/work-orders/responsive-detail.tsx`
- Test: `api/tests/Feature/Maintenance/MaintenanceShiftHandoverTest.php`

**Interfaces:** The assignment check command proposes a replacement only for an In progress order whose assignee's covered shift ends within 30 minutes and whose repair is not pending verification. It sends one deduplicated notice per order/shift. `assign()` requires `handover_note` when replacing the assignee of an In progress order.

- [ ] Write red tests for night-shift boundaries, approved overtime without shift coverage, zero eligible replacements, duplicate scheduler runs, and a supervisor-approved handover with immutable log attribution.
- [ ] Compute the reminder from the employee's actual HR shift end. Suggest a present available replacement; do not mutate `assigned_to` in the command. When the supervisor confirms, save the note and old/new employee IDs in the log.
- [ ] Run the focused test and Maintenance role tests. Commit only Task 7 files.

### Task 8: Reserve preventive maintenance windows across modules

**Files:**
- Create: `api/database/migrations/2026_10_09_230400_add_maintenance_windows.php` for `maintenance_schedules.estimated_duration_minutes` and `maintenance_windows`
- Create: `api/app/Modules/Maintenance/Models/MaintenanceWindow.php`, `api/app/Modules/Maintenance/Services/MaintenanceWindowService.php`
- Modify: `api/app/Modules/Maintenance/Models/MaintenanceSchedule.php`, `api/app/Modules/Maintenance/Requests/StoreMaintenanceScheduleRequest.php`, `api/app/Modules/Maintenance/Requests/UpdateMaintenanceScheduleRequest.php`, `api/app/Modules/Maintenance/Services/MaintenanceScheduleService.php`
- Modify: `api/app/Modules/MRP/Services/CapacityPlanningService.php`, `api/app/Modules/Production/Services/ProductionResourceService.php`, `api/app/Modules/Maintenance/Services/MaintenanceWorkOrderService.php`
- Test: `api/tests/Feature/Maintenance/MaintenanceWindowConflictTest.php`, existing MRP capacity/reservation tests

**Interfaces:** `MaintenanceWindowService::confirm(MaintenanceWorkOrder $order, Carbon $start, Carbon $end, User $actor): MaintenanceWindow` locks the target and rejects overlapping confirmed production or maintenance intervals. Only the supervisor confirms. Production capacity checks read confirmed maintenance windows before booking the same machine or mold.

- [ ] Write red cross-module tests: production booking blocks a maintenance window; a confirmed maintenance window blocks a later production booking; concurrent confirmations yield one winner; cancelling or completing releases the future reservation; a proposed window never changes equipment status.
- [ ] Add `estimated_duration_minutes` with 60-minute default and validation from 15 to 480 minutes. Add `maintenance_windows` with target type/id, work-order ID, interval, state, confirming actor, and timestamps. Historical schedule rows get the safe 60-minute value without moving their existing next due date.
- [ ] Use the same equipment row lock in Maintenance confirmation and Production booking. Under that lock, requery both reservation tables for `start < other_end && other_start < end`. Keep Production's normal exclusion/reservation protections intact.

```php
if ($start->lt($otherEnd) && $otherStart->lt($end)) {
    throw ValidationException::withMessages(['planned_start_at' => ['Equipment is already reserved for this interval.']]);
}
```

- [ ] Run Maintenance window and MRP/Production capacity tests, including a database-level concurrency test on PostgreSQL. Commit only Task 8 files.

### Task 9: Suggest a preventive slot and technician together

**Files:**
- Modify: `api/app/Modules/Maintenance/Services/MaintenanceWindowService.php`, `api/app/Modules/Maintenance/Controllers/MaintenanceScheduleController.php`, `api/app/Modules/Maintenance/Controllers/MaintenanceWorkOrderController.php`, `api/app/Modules/Maintenance/routes.php`, `api/app/Modules/Maintenance/Resources/MaintenanceScheduleResource.php`
- Modify: `spa/src/pages/maintenance/schedules/create.tsx`, `spa/src/pages/maintenance/schedules/edit.tsx`, `spa/src/pages/maintenance/schedules/detail.tsx`, `spa/src/api/maintenance/schedules.ts`, `spa/src/types/maintenance.ts`
- Test: `api/tests/Feature/Maintenance/MaintenanceWindowSuggestionTest.php`, `spa/e2e/maintenance-preventive-window.spec.ts`

**Interfaces:** `GET /maintenance/schedules/{schedule}/window-suggestions` returns up to three eligible intervals in the next seven days, each with a current candidate technician and explicit production/shift blockers. `POST /maintenance/work-orders/{workOrder}/window` confirms a reviewed interval through Task 8.

- [ ] Write red tests for no free interval, a production booking appearing between suggestion and confirmation, a night shift, approved leave, a mold reservation, and two scheduled jobs trying to create the same preventive order.
- [ ] Search ordered candidate intervals within seven days of now using the schedule's estimated duration. Exclude production and confirmed maintenance reservations; call Task 2's employee eligibility for the whole proposed interval. Return no suggestion with reasons when capacity or staffing is unavailable.
- [ ] Show the duration field and three candidate cards on the supervisor's Schedule detail; confirm one via the locked endpoint. Mark the time as a reservation, not a machine shutdown.
- [ ] Run focused API, Production capacity, scheduler-idempotency, typecheck, and browser tests. Commit only Task 9 files.

### Task 10: Guide part selection and flag repeated breakdowns

**Files:**
- Modify: `api/app/Modules/Maintenance/Controllers/MaintenanceWorkOrderController.php`, `api/app/Modules/Maintenance/Services/SparePartUsageService.php`, `api/app/Modules/Maintenance/Services/DowntimeAnalyticsService.php`
- Modify: `spa/src/pages/maintenance/work-orders/SparePartSheet.tsx`, `spa/src/api/maintenance/workOrders.ts`, `spa/src/types/maintenance.ts`
- Create: `api/app/Console/Commands/CheckMaintenanceRepeatBreakdowns.php`
- Modify: `api/routes/console.php`, `api/app/Common/Services/NotificationCatalog.php`
- Test: `api/tests/Feature/Maintenance/MaintenancePartSuggestionTest.php`, `api/tests/Feature/Maintenance/MaintenanceRepeatBreakdownTest.php`, `spa/e2e/maintenance-part-suggestion.spec.ts`

**Interfaces:** The existing spare-part options endpoint accepts the work-order ID and returns usual items for the same target, plus usable stock by location; no history returns the ordinary list. The daily repeat check counts distinct breakdown downtime incidents over rolling 30 days and flags the third incident with a deduplicated supervisor notice.

- [ ] Write red tests: same-target completed history ranks ahead of unrelated parts; no history falls back; quality-held, expired, reserved, or insufficient stock is not represented as usable; issuing still rechecks stock; replaying one breakdown event creates no extra downtime incident or second threshold notice.
- [ ] Rank from `SparePartUsage` joined to completed orders for the same target. Use the existing stock-availability summary for quantities and location/lot, not a new stock ledger. Expose only a stock/replenishment status permitted to Maintenance users, without purchasing cost or supplier details. Do not auto-issue, auto-reserve, or auto-purchase.
- [ ] Count distinct linked breakdown incidents and send one review notice at three incidents within 30 days. Link machine history and preventive schedule; do not infer root cause.
- [ ] Run focused Maintenance, Inventory issue/return, GL posting, typecheck, and browser tests. Commit only Task 10 files.

### Task 11: Prove the non-admin end-to-end story and prepare rollout

**Files:**
- Modify: `docs/MAINTENANCE-E2E-MANUAL-TEST-RUNBOOK.md`
- Create: `spa/e2e/maintenance-automated-recovery.spec.ts`
- Test: all new and existing Maintenance API tests, Machine breakdown concurrency, Production capacity/reservation, Asset association, stock issue/return, GL posting

**Interfaces:** One repeatable non-admin browser path covers breakdown creation, machine release, technician suggestion, supervisor assignment, repair log, part issue/return, submit, reject/resubmit, verifier acceptance, actual downtime/cost, and production resume. A second path covers a due preventive schedule and confirmed window. Browser fixtures use a disposable local/test database.

- [ ] Extend the runbook with exact role/account prerequisites, expected alerts, a negative off-shift/absent candidate, stale-suggestion recovery, production blocker, verifier rejection, and evidence table. State that live `maintenance2@ogami.test` is absent and that no demo seeder runs on 47.129.228.44.
- [ ] Run focused tests after each task, then run `docker compose exec -T api php artisan test tests/Feature/Maintenance tests/Feature/Production/MachineBreakdownLifecycleConcurrencyTest.php` and the affected MRP, Inventory, and Asset suites. Run `npm run typecheck` and the desktop/phone Playwright specs. Record exact counts and failures; do not claim a clean full suite from focused results.
- [ ] Review permissions and data compatibility: existing open/in-progress orders retain legacy completion; direct API calls cannot bypass verification; no second active maintenance order or double stock movement; Production cannot book confirmed maintenance capacity.
- [ ] Prepare, but do not execute, a production deployment checklist: database backup; migration and role grant review; active non-admin technician/supervisor/production/warehouse account audit; HR shift/punch audit; queue and scheduler health; deploy with `maintenance.closed_loop.enabled=false`; smoke old orders; enable only after account readiness; choose safe test machine and stock; record rollback. Obtain separate approval before any remote migration, account write, or deployment.
- [ ] Commit only the runbook and E2E test with its matching release. Preserve all unrelated workspace edits.

## Completion criteria

- A supervisor sees a ranked, explainable suggestion for a current present technician; stale availability is caught on save and on new-workflow Start.
- Production ownership is visible before Start. Only Production releases its resources.
- New corrective machine orders need technician evidence and independent Production verification; a rejected repair keeps downtime and Maintenance status open. Historical orders still complete as before.
- A finished order displays real ledger downtime, response/repair time, net Parts cost, actors, and restored state. No fabricated savings or labor cost.
- Preventive window suggestions cannot collide with confirmed production/maintenance reservations, including concurrent confirmations.
- Parts and repeated-breakdown assistance never issue stock, purchase, or prescribe a root cause without human action.
- All proof uses non-admin accounts in local/test fixtures. The live demonstration is blocked until equivalent real accounts, shifts, safe data, and separate deployment approval exist.
