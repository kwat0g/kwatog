# M050 — Capacity Scheduling Audit Report

**Audit date:** 2026-08-24  
**Status:** 📋 Plan Ready  
**Scope:** `manufacturing/capacity-scheduling` only

## Executive summary

M050 has a real capacity-planning service, authenticated MRP routes, pending/confirmed schedule rows, machine/mold assignment, automatic MRP integration, a snapshot API, and a SPA Gantt page. Transaction boundaries, resource row locks, hashed route IDs, machine-window overlap checks, and focused service tests are present.

The implementation is not yet a reliable finite-capacity schedule. It places work orders against machine windows, but does not consistently reserve all constrained resources: molds can be double-booked and their remaining shot capacity is not accumulated across proposals; a running machine with no schedule row can be assigned again; planned maintenance, shifts, operator capacity, routing operations, and work-order due windows are not modeled. Schedule rows also have no lifecycle handoff when a work order completes, is cancelled, or is disrupted. The SPA loses pending proposals on refresh and the dashboard renders a different schedule source than the Gantt page.

No production code was changed. The high-risk fixes span MRP, Production, Maintenance, Dashboard, permissions, and state-machine boundaries, so the module is released as **Plan Ready**.

## What was checked

- MRP scheduler routes, controller, request decoding, capacity service, models, enums, migrations, automatic MRP job, and role grants.
- Production work-order lifecycle, routing/operation models, machine-breakdown listener, dashboard Gantt source, SPA scheduler API/page/Gantt, sidebar, and design-system guidance.
- The BOM, routing, work-order, and maintenance modules were read only for dependency evidence; they were not audited or modified.

## Findings

### M050-001 — Broken — schedule rows do not follow the work-order lifecycle

`ProductionScheduleStatus` defines `pending`, `confirmed`, `superseded`, and `executed` (`api/app/Modules/Production/Enums/ProductionScheduleStatus.php:7-16`). The planner treats pending, confirmed, and executed rows as blocking windows (`api/app/Modules/MRP/Services/CapacityPlanningService.php:45-50`, `406-432`), but the work-order `complete` and `cancel` transactions update the work order and machine/mold only; they do not transition or close the related schedule row (`api/app/Modules/Production/Services/WorkOrderService.php:467-515`, `539-581`). No audited path writes `executed` for a completed schedule.

Completed or cancelled work can therefore remain visible as active planned work, and stale rows can continue to participate in overlap checks or block a future proposal. The schedule state machine has no authoritative terminal handoff.

**Recommendation:** define schedule transitions for start, pause/resume, complete, cancel, and disruption; update them in the owning work-order transaction or through a durable, idempotent lifecycle listener. Add reconciliation for legacy rows and tests for every terminal path.

### M050-002 — Broken — molds are not modeled as a constrained time/resource ledger

Placement selects molds in `available` or `in_use` state and checks `current_shot_count + quantity_target` for each work order (`api/app/Modules/MRP/Services/CapacityPlanningService.php:551-555`). The conflict index and `nextFreeSlot` contain machine windows only (`api/app/Modules/MRP/Services/CapacityPlanningService.php:595-600`, `509-533`); there is no mold window check and no in-memory cumulative shot consumption as multiple work orders are placed in one run. The schedule table stores `mold_id` but only has a machine/start index (`api/database/migrations/0085_create_production_schedules_table.php:17-33`).

Two machines can receive overlapping jobs using the same mold, and several sequential jobs can collectively exceed the mold's remaining maintenance-shot capacity even though each job individually passes the unchanged database check.

**Recommendation:** reserve mold time and cumulative shot capacity in the same planning ledger as machine time, with lock-safe persisted checks and explicit maintenance/reset behavior. Add overlap and cumulative-shot regression tests.

### M050-003 — Broken — a live running machine can be scheduled twice

The run locks and considers both idle and running machines (`api/app/Modules/MRP/Services/CapacityPlanningService.php:85-97`), and placement accepts both statuses (`api/app/Modules/MRP/Services/CapacityPlanningService.php:561-579`). `Machine` has a `current_work_order_id` field (`api/app/Modules/MRP/Models/Machine.php:29-45`), but placement never excludes a machine whose current work order is active and has no persisted schedule window. The later work-order confirmation guard can reject some of these proposals, but that is after the planner has persisted a misleading pending schedule (`api/app/Modules/Production/Services/WorkOrderService.php:761-819`).

The planner can publish a proposal that is physically impossible or guaranteed to fail at confirmation.

**Recommendation:** treat current in-progress work as a locked occupancy interval or disallow running resources for new placement; make the planner and confirmation use the same authoritative availability model.

### M050-004 — Missing/Incomplete — finite capacity does not include calendars, maintenance, shifts, or labor

Machine records carry `available_hours_per_day` and `operators_required` (`api/app/Modules/MRP/Models/Machine.php:29-45`), and maintenance schedules carry due dates plus active/due scopes (`api/app/Modules/Maintenance/Models/MaintenanceSchedule.php:26-45`, `62-69`). The planner uses only machine status and persisted schedule overlap; it never queries available hours, shift calendars, planned maintenance, maintenance work orders, operator qualifications, or downtime windows (`api/app/Modules/MRP/Services/CapacityPlanningService.php:63-99`, `551-612`).

A machine can be scheduled outside its operating calendar or across a known maintenance window. The result is an infinite-time slot placer, not the finite-capacity schedule described by the MRP II workflow.

**Recommendation:** define the plant calendar/resource-calendar contract, then subtract maintenance/downtime and labor capacity before slot selection. Keep the integration with Maintenance explicit and test a planned-maintenance conflict.

### M050-005 — Missing — routing operations are not scheduled

Work orders expose ordered operations (`api/app/Modules/Production/Models/WorkOrder.php:120-123`), and operation records carry sequence, machine, mold, operator, and planned start/end fields (`api/app/Modules/Production/Models/WoOperation.php:21-57`). Routing operations likewise define work centers, resources, setup, cycle, labor, and machine rates (`api/app/Modules/Production/Models/RoutingOperation.php:18-42`). M050 instead loads only the work-order product and places one machine/mold schedule using the mold output rate (`api/app/Modules/MRP/Services/CapacityPlanningService.php:66-83`, `551-607`).

Multi-operation work cannot be sequenced, cannot reserve operation-specific resources, and cannot express operation-to-operation precedence in the Gantt. The registry’s routing dependency is therefore not reflected in the scheduling algorithm.

**Recommendation:** decide whether M050 schedules whole injection jobs or operation-level routings. If routings are authoritative, materialize/plan operation slots with precedence, resource alternatives, setup/cycle times, labor, and operation-level execution feedback.

### M050-006 — Incomplete — horizon and due-date policy are not applied when running the scheduler

`RunSchedulerRequest` accepts only an optional work-order ID list (`api/app/Modules/MRP/Requests/RunSchedulerRequest.php:25-30`). `CapacityPlanningService::run` loads every planned work order in the selected scope and orders by priority, planned start, and ID (`api/app/Modules/MRP/Services/CapacityPlanningService.php:63-83`); placement reads `planned_start` but does not compare the result with `planned_end` (`api/app/Modules/MRP/Services/CapacityPlanningService.php:586-600`). The configured horizon is exposed by `options` and used by snapshot defaults, not enforced by `run` (`api/app/Modules/MRP/Controllers/SchedulerController.php:70-82`).

The service can propose work outside the visible horizon or after the work order’s requested end, while priority order can override an earlier due date without an explicit lateness decision.

**Recommendation:** define horizon, due-date, lateness, and expedite rules; pass a bounded planning window to the service and return late/overflow diagnostics. Use EDD or a documented setup-aware rule alongside priority rather than silently sorting only by the work-order priority field.

### M050-007 — Incomplete — manual reorder/reassign controls are exposed but not usable in the SPA

The backend exposes reorder and reassign routes and the client has methods for both (`api/app/Modules/MRP/routes.php:72-78`, `spa/src/api/mrp/scheduler.ts:14-17`). Reorder only changes `production_schedules.priority_order` (`api/app/Modules/MRP/Services/CapacityPlanningService.php:254-266`), while a new run sorts `WorkOrder::priority`, `planned_start`, and ID instead (`api/app/Modules/MRP/Services/CapacityPlanningService.php:72-76`). The schedule page invokes only run and confirm (`spa/src/pages/production/schedule.tsx:79-100`), and the Gantt explicitly documents drag-to-reschedule as unbuilt (`spa/src/components/production/GanttChart.tsx:1-11`).

An operator cannot use the advertised reassign/reorder capabilities from the page, and a manual priority change does not affect the next proposal algorithm or current time slot.

**Recommendation:** either remove/defer the endpoints or build a complete manual adjustment workflow with effective ordering semantics, collision checks, pending-state refresh, and audit history.

### M050-008 — Broken — pending proposals are not recoverable after refresh or across users

The planner persists pending rows before returning its proposal summary (`api/app/Modules/MRP/Services/CapacityPlanningService.php:128-150`), and confirmation accepts pending schedule IDs (`api/app/Modules/MRP/Services/CapacityPlanningService.php:188-248`). The SPA stores the latest proposal only in component state (`spa/src/pages/production/schedule.tsx:43-49`); its confirm action is rendered only when that in-memory list is non-empty (`spa/src/pages/production/schedule.tsx:92-100`, `147-157`). The snapshot includes pending bars, but `GanttChart` renders them as non-selectable display buttons and provides no pending-row selection model (`api/app/Modules/MRP/Services/CapacityPlanningService.php:309-339`, `spa/src/components/production/GanttChart.tsx:160-189`).

After a browser reload, worker handoff, or another user’s run, pending proposals remain in the database but cannot be selected for confirmation. They can continue to block reruns while the UI offers no recovery path.

**Recommendation:** expose a canonical pending-proposal list/selection state, show proposal ownership and age, allow refresh-and-confirm with conflict revalidation, and expire/discard abandoned proposals deliberately.

### M050-009 — Broken — snapshot omits schedules that overlap the window from before its start

Snapshot filtering uses `whereBetween('scheduled_start', [$from, $to])` (`api/app/Modules/MRP/Services/CapacityPlanningService.php:304-318`). A job that starts before `from` and ends after `from` is not returned, even though it occupies the visible machine window. The Gantt derives its visible range only from returned bars (`spa/src/components/production/GanttChart.tsx:63-83`).

Long-running or already-started work disappears from the beginning of the requested view, which can make a machine look available and hide an active conflict.

**Recommendation:** query interval overlap (`scheduled_start < to` and `scheduled_end > from`) and clamp/render the visible portion at the window edges. Add a crossing-boundary test.

### M050-010 — Incomplete — snapshot date input is unvalidated, unbounded, and N+1

`SchedulerController::snapshot` parses arbitrary `from` and `to` query strings without a FormRequest, ordering check, or maximum range (`api/app/Modules/MRP/Controllers/SchedulerController.php:77-82`). The service loads machines once and then executes a separate schedule query for each machine (`api/app/Modules/MRP/Services/CapacityPlanningService.php:304-318`). The default horizon setting is not a server-side upper bound.

Malformed dates can become 500s, reversed/very large ranges can produce nonsensical or expensive responses, and plants with many machines incur one query per machine.

**Recommendation:** validate ISO dates, require `to >= from`, enforce a maximum window, return a 422 for invalid input, and replace the per-machine query pattern with a bounded eager load or one indexed schedule query grouped in memory.

### M050-011 — Hardening — scheduler proposal responses leak raw machine and mold IDs

`scheduleSummary` returns `machine_id` and `mold_id` directly from database columns while only the schedule and work-order IDs use hashes (`api/app/Modules/MRP/Services/CapacityPlanningService.php:615-627`). The SPA types consequently declare those fields as numbers (`spa/src/types/mrp.ts:186-197`). This conflicts with the project’s ID-obfuscation rule requiring API resources to return hash IDs (`CLAUDE.md:151-154`, `570-572`).

The proposal endpoint exposes sequential internal resource identifiers and creates an inconsistent contract with snapshot/reassign, which use hashed IDs.

**Recommendation:** return hashed machine/mold IDs (and consistent resource objects where useful), update the types, and add an endpoint assertion that no scheduler payload exposes raw primary keys.

### M050-012 — Broken — the dashboard and scheduler page use different schedule sources

The M050 snapshot/Gantt reads `production_schedules` (`api/app/Modules/MRP/Services/CapacityPlanningService.php:304-347`, `spa/src/pages/production/schedule.tsx:71-77`). The PPC dashboard’s production Gantt instead reads work orders’ `planned_start`, `planned_end`, `machine_id`, and status (`api/app/Modules/Dashboard/Services/PpcDashboardService.php:147-182`); its scalar widget also counts work orders rather than schedule rows (`api/app/Modules/Dashboard/Services/DashboardWidgetDataService.php:94-98`).

Confirming or reassigning a schedule can leave the dashboard showing the old planned window, while unconfirmed/planned work can appear as scheduled. Operators receive contradictory views of the same capacity plan.

**Recommendation:** choose `production_schedules` as the canonical committed/proposed schedule source, or label the dashboard explicitly as work-order intent. Align the rich panel, scalar count, and detail page with the same status/window contract.

### M050-013 — Incomplete — scheduler page has a silent confirm failure and incomplete setup failure state

Run has a visible error handler, but confirm has only an `onSuccess` callback and no `onError` (`spa/src/pages/production/schedule.tsx:79-100`). Options and machine-list failures are not rendered; the date range is initialized only after options succeed (`spa/src/pages/production/schedule.tsx:50-69`), so an options failure can leave the page without a snapshot query or explanation. The project convention requires every mutation to expose success/error behavior and invalidate affected queries (`CLAUDE.md:568-575`).

Permission, stale-proposal, validation, and server failures can leave the operator with no actionable explanation. A failed options request can look like an empty page rather than a configuration problem.

**Recommendation:** add visible errors/retry for options and machines, toast/map confirm errors, disable stale controls while pending, and invalidate proposal/snapshot/work-order data after every successful state change.

### M050-014 — Incomplete — machine breakdown does not invalidate or replan future schedule rows

The breakdown listener pauses the current work order, restores the machine’s breakdown status, and publishes alternate-machine candidates (`api/app/Modules/Production/Listeners/HandleMachineBreakdown.php:65-129`). It does not update future `production_schedules` rows or dispatch a M050 replan. The planner continues to treat all pending/confirmed/executed rows as occupied windows (`api/app/Modules/MRP/Services/CapacityPlanningService.php:406-432`).

The system can correctly mark a machine unavailable while leaving its future committed work stranded with no affected-order list, reschedule action, or due-date impact.

**Recommendation:** define disruption semantics: freeze in-process work, mark affected future rows, identify customer/constraint impact, and issue a durable replan task with operator approval and stability-window rules.

### M050-015 — Hardening — schedule invariants are checked late rather than protected at storage

The migration creates time columns, a priority, status, and indexes but no `scheduled_end > scheduled_start`, `is_confirmed`/status consistency, or active-resource exclusion invariant (`api/database/migrations/0085_create_production_schedules_table.php:17-33`). The service validates window order only while loading or confirming rows (`api/app/Modules/MRP/Services/CapacityPlanningService.php:464-475`); a malformed direct write can therefore make the next scheduler run fail globally. The later lifecycle migration guards status values but not these relationships (`api/database/migrations/2026_08_13_220000_add_remaining_lifecycle_status_checks.php:40-45`).

**Recommendation:** add compatible database/domain invariants and a preflight migration audit. Keep service checks for readable business errors, but prevent invalid rows from bypassing the service through jobs/imports or future callers.

### M050-016 — Hardening — concurrent scheduler actions acquire shared locks in different orders

`run` locks selected work orders, then all eligible machines, then all eligible molds (`api/app/Modules/MRP/Services/CapacityPlanningService.php:65-97`). `confirm` locks schedule rows, machines/molds, and then work orders (`api/app/Modules/MRP/Services/CapacityPlanningService.php:188-227`), while `reassign` locks the schedule, work order, machine, and mold (`api/app/Modules/MRP/Services/CapacityPlanningService.php:269-294`). The resource comment says these flows serialize, but the acquisition order is not shared.

A concurrent run and confirmation can hold different rows while waiting for the next lock, producing a database deadlock or retryable 500 under ordinary operator activity.

**Recommendation:** define one lock order for schedule → work order → machine → mold (or another documented order), apply it to every write path, and add a two-transaction concurrency test with deadlock retry policy.

### M050-017 — Missing — focused coverage does not exercise the HTTP, lifecycle, resource, or UI contract

`CapacityPlanningServiceTest` covers seven service scenarios—window stacking, dependency order, confirmation, reassignment, and assignment validation (`api/tests/Feature/MRP/CapacityPlanningServiceTest.php:49-234`). It does not cover mold overlap/shot accumulation, live machine occupancy, maintenance/routing/due-date constraints, terminal schedule transitions, snapshot boundary/invalid input, raw-ID response shape, lock ordering, or controller permissions. No scheduler page or Gantt test file was found under `spa/src`.

The current test suite can remain green while the principal planning and recovery defects above regress.

**Recommendation:** add endpoint tests, service edge/concurrency tests, and UI tests for run/confirm/reload/retry/filter/Gantt accessibility. Run them with a resolvable database and browser-capable frontend environment.

### M050-018 — Polish — scheduler route/documentation naming is inconsistent

Process documentation tells operators to open `/mrp/scheduler` (`docs/PROCESS-FLOWS.md:164-182`), while the actual SPA route and sidebar entry are `/production/schedule` (`spa/src/routes/productionRoutes.tsx:34-35`, `spa/src/components/layout/Sidebar.tsx:215-230`). The backend API remains under `/mrp/scheduler` (`api/app/Modules/MRP/routes.php:72-78`).

The page is reachable through the sidebar, but runbooks, links, and support conversations can send users to a route that does not exist.

**Recommendation:** choose one user-facing route, update process documentation and test fixtures, and keep the API path separate in developer-facing documentation.

### M050-019 — Polish — Gantt semantics rely on visual layout and hover text

The chart is implemented as nested `div` grids with visual date cells (`spa/src/components/production/GanttChart.tsx:112-158`). Bars are buttons, but status, resource, and date context are primarily supplied through a `title` string (`spa/src/components/production/GanttChart.tsx:168-189`); there is no table/grid role, accessible axis/header relationship, legend, or text summary for the machine timeline.

Keyboard users can activate a bar, but screen-reader users do not receive a reliable schedule table or a non-visual conflict/status representation.

**Recommendation:** provide an accessible tabular/list alternative or grid semantics with labelled axes, status text, and a visible legend. Keep the dense visual Gantt as the complementary view.

## Existing safeguards

- MRP routes require Sanctum authentication and the MRP feature flag; run/confirm/view permissions are separated (`api/app/Modules/MRP/routes.php:18-18`, `72-78`). FormRequests also authorize the write operations (`api/app/Modules/MRP/Requests/RunSchedulerRequest.php:15-18`, `ConfirmScheduleRequest.php:15-18`).
- `run`, `confirm`, `reorder`, and `reassign` use database transactions; run locks selected work orders and resource rows, while confirmation revalidates pending state, assignment compatibility, and windows (`api/app/Modules/MRP/Services/CapacityPlanningService.php:63-77`, `182-251`, `260-295`).
- Hash-ID decoding is centralized for scheduler write payloads (`api/app/Common/Concerns/ResolvesHashIds.php:9-16`, `51-80`).
- The previous PPC read-permission gap was explicitly addressed by granting `production.schedule.view`; current role documentation describes production manager as the schedule owner and PPC as MRP/routing owner (`api/database/seeders/RolePermissionSeeder.php:557-576`, `docs/AUTO-BROWSER-TESTS.md:119-120`). Confirmation ownership should still be confirmed as policy because PPC can run but not confirm.
- The database has a status-value guard for `production_schedules` (`api/database/migrations/2026_08_13_220000_add_remaining_lifecycle_status_checks.php:40-45`).

## Verification notes

- Focused backend command: `php artisan test --filter='CapacityPlanningServiceTest' --colors=never` — 7 tests failed during setup with 0 assertions because PostgreSQL host `db` could not resolve (`SQLSTATE[08006] [7] could not translate host name "db" to address`).
- PHP syntax checks passed for `CapacityPlanningService.php`, `SchedulerController.php`, `RunSchedulerRequest.php`, and `ConfirmScheduleRequest.php`.
- No dedicated SPA scheduler/Gantt tests were present to execute.
- `git diff --check` was clean for the audited paths before report creation.
