# M053 — Maintenance / machine health audit report

Audit date: 2026-08-25  
Domain: `manufacturing`  
Module: `maintenance-machine-health`  
Roles: system admin, maintenance tech, production manager, PPC head  
Status: 📋 Plan Ready  
Release recommendation: separate implementation work required

## Executive result

M053 has a solid implementation baseline: work-order completion and cancellation re-read locked rows, spare-part costing uses decimal arithmetic and serializes the work-order cost update, preventive-generation requests use a durable outbox handoff, the mobile work-order flow follows the server-provided actions, and the focused maintenance suite passes. It is not ready for verification yet.

The audit found several authoritative-contract gaps: the backend can complete a work order before it is started, assignment can overwrite a concurrent lifecycle result, terminal work orders still accept inventory issues and log mutations, mold lifecycle cost uses float arithmetic, the machine-hours scheduler runs after preventive generation and has no maintenance-hour baseline, and the documented breakdown-to-corrective-work-order handoff is absent. Schedule archive/restore is also unreachable, and the downtime page exposes dead search and raw integer IDs. These are primarily data-integrity, inventory, scheduler, and cross-module issues, so no production-code fix was applied in this session.

## Discovery and scope

- Backend surface inspected: maintenance routes, controllers, requests, resources, enums, models, migrations, work-order/schedule/spare-part/downtime/machine-hours/predictive services, preventive jobs, events, and listeners.
- Frontend surface inspected: maintenance desktop/mobile routes, work-order list/detail/create flows, schedule list/detail/create/edit flows, downtime analytics, API clients, types, mobile layout, and sidebar navigation.
- The condition-reading and machine-health routes are intentionally hidden by the documented scope cut at `api/app/Modules/Maintenance/routes.php:55-67` and `spa/src/routes/maintenanceRoutes.tsx:21-25`. This agrees with the roadmap decision to hide manual IoT-shaped UI and unsourced live analytics at `docs/SYSTEM-IMPROVEMENT-ROADMAP-2026-08-13.md:488-494`; the dormant predictive/condition-reading implementation was not treated as a current exposed defect.
- The documented maintenance lifecycle is `open → assigned → in_progress → completed`, with cancellation as a terminal branch at `docs/PROCESS-FLOWS.md:1283-1288`. The live resource and mobile flow reflect that contract, but the desktop and service paths do not enforce it consistently.

## Findings

### M053-F01 — P1, Broken: a maintenance work order can be completed without being started

Evidence: `api/app/Modules/Maintenance/Services/MaintenanceWorkOrderService.php:216-234` only calls `assertNotTerminal()` before setting `completed`; the guard considers only `completed` and `cancelled` terminal at `api/app/Modules/Maintenance/Enums/MaintenanceWorkOrderStatus.php:8-19`. The resource advertises `complete` only for `in_progress` at `api/app/Modules/Maintenance/Resources/MaintenanceWorkOrderResource.php:91-99`, but desktop detail renders Complete for every non-terminal status at `spa/src/pages/maintenance/work-orders/detail.tsx:112-142`.

Impact: an `open` or `assigned` order can skip `started_at`, the start transition, machine `maintenance` status, and any mold “maintenance started” history. The UI and API response describe one state machine while the write authority accepts a different one.

Action: enforce an explicit source-state matrix in the service under a locked authoritative re-read (`assigned`/`open` → start or assign as policy allows; only `in_progress` → complete; any non-terminal → cancel). Gate desktop buttons from `available_actions`, and add negative API/UI tests for direct completion from every non-`in_progress` state.

### M053-F02 — P1, Broken: assignment is a stale-model lifecycle write

Evidence: `MaintenanceWorkOrderService::assign()` checks the caller's model at `api/app/Modules/Maintenance/Services/MaintenanceWorkOrderService.php:158-168`, then enters a transaction without `lockForUpdate()` or a fresh status check and unconditionally writes `assigned`. By contrast, `start`, `complete`, and `cancel` lock and re-read the work order inside their transactions at `:175-185`, `:219-234`, and `:281-290`.

Impact: a request holding an old `open`/`in_progress` model can assign a work order after another request has completed or cancelled it, or move an in-progress order backward to `assigned`. Last-writer-wins behavior can invalidate machine state and audit history even though the route is permission-gated at `api/app/Modules/Maintenance/routes.php:42-43`.

Action: lock and re-read before assignment, reject terminal and invalid source states, define whether reassignment from `assigned`/`in_progress` is allowed, and test stale assignment versus start/complete/cancel plus concurrent reassignment.

### M053-F03 — P1, Broken: spare parts and logs can mutate terminal work orders

Evidence: `api/app/Modules/Maintenance/Services/SparePartUsageService.php:31-43` locks the work-order row but never checks its status before issuing inventory; the controller exposes the mutation at `api/app/Modules/Maintenance/Controllers/MaintenanceWorkOrderController.php:121-138`. The log path likewise calls `MaintenanceWorkOrderService::log()` without a lifecycle guard at `api/app/Modules/Maintenance/Controllers/MaintenanceWorkOrderController.php:107-118` and `api/app/Modules/Maintenance/Services/MaintenanceWorkOrderService.php:309-316`.

Impact: a completed or cancelled order can consume stock, create a cost entry, and change its audit trail after closure. An `open` order can also receive material before the chosen start/assignment policy. The frontend's current visibility is not an API control.

Action: define which non-terminal states allow logs and material issues, enforce that policy after locking the authoritative work order, and reject completed/cancelled mutations with stable 422 responses. Add terminal, replay, and concurrent inventory tests.

### M053-F04 — P1, Broken: mold maintenance cost accumulation uses float arithmetic

Evidence: `api/app/Modules/Maintenance/Services/MaintenanceWorkOrderService.php:236-247` calculates `total_maintenance_cost` with `(float)` casts and `number_format()`. Repository rules require decimal money and explicitly prohibit floats at `CLAUDE.md:447-454`.

Impact: repeated fractional costs can be rounded through binary floating-point before being persisted, so the mold lifecycle total can diverge from the exact spare-part and work-order totals. This is a financial/audit value even though the surrounding transaction and row lock are correct.

Action: replace the float path with decimal-string/bcmath or the repository money abstraction, preserve the database scale, and add boundary and repeated-fraction accumulation tests.

### M053-F05 — P1, Broken: machine-hour preventive maintenance is evaluated from stale and non-baselined hours

Evidence: preventive generation is scheduled at `02:00` in `api/routes/console.php:15-24`, while `maintenance:recompute-hours` runs at `06:30` at `api/routes/console.php:104-109`; the comment incorrectly describes the recompute as running before the evaluation. The trigger at `api/app/Modules/Maintenance/Services/MaintenanceScheduleService.php:168-193` compares cumulative `machines.running_hours_total` directly to the schedule interval. After completion, `:216-226` recomputes `next_due_at` as a wall-clock interval from `last_performed_at`, but no baseline captures the cumulative hours at that maintenance event.

Impact: a machine-hour schedule can be delayed until the next day's run because generation reads yesterday's hours, and a completed PM can become due again after the wall-clock interval even when the machine has accumulated no new runtime. Conversely, a schedule created for a machine with already-high lifetime hours can trigger based on historical runtime rather than runtime since the schedule's last service.

Action: decide whether the schedule means runtime since last service or wall-clock time. For runtime semantics, persist a last-maintenance running-hours baseline (or an equivalent due-hours value), update it atomically with completion/cancellation policy, run the recompute before generation, and test scheduler ordering, first-run behavior, completion with no new hours, and repeated generation.

### M053-F06 — P1, Missing: machine breakdown does not create the documented corrective maintenance work order

Evidence: the process contract says `MachineStatusChanged → HandleMachineBreakdown` auto-creates a corrective MWO at `docs/PROCESS-FLOWS.md:1290-1298`. The live listener only pauses a production work order, restores breakdown status, finds compatible machines, and stages `MachineBreakdownDetected` at `api/app/Modules/Production/Listeners/HandleMachineBreakdown.php:20-37` and `:107-132`; it has no maintenance-work-order creation or maintenance service dependency. The `machine_downtimes.maintenance_order_id` column is declared for this handoff at `api/database/migrations/0084_create_machine_downtimes_table.php:18-32`, but no runtime assignment was found outside the model/migration.

Impact: a breakdown can produce a downtime row and notification without an actionable corrective MWO or a durable downtime-to-maintenance link. Maintenance operators must create a separate order manually, and downstream cost, completion, and downtime reconciliation lose the causal relationship promised by the process documentation.

Action: coordinate Production and Maintenance owners on an idempotent breakdown handoff. Create the corrective MWO and link `maintenance_order_id` in one transaction/outbox flow, preserve the paused production work order and reason, make replay safe, and add authoritative transition, duplicate-event, and restoration tests. This is cross-module work and is separate-recommended.

### M053-F07 — P2, Broken: breakdown notifications link to a removed machine page

Evidence: `api/app/Modules/Production/Listeners/NotifyOnMachineBreakdown.php:37-43` emits `/maintenance/machines/{hash_id}`. The maintenance SPA route list explicitly says the machine-health page was removed and contains no `/maintenance/machines/:id` route at `spa/src/routes/maintenanceRoutes.tsx:21-38`.

Impact: the operator-facing breakdown notification sends maintenance users to a 404/dead destination at the moment they need the recovery workflow. This is especially confusing because the same scope-cut decision intentionally removed machine-health navigation.

Action: point the notification to the supported corrective MWO/downtime destination once F06 exists, or remove the link until a sourced machine page is restored. Do not re-expose unsourced machine-health UI merely to satisfy the old URL.

### M053-F08 — P1, Broken: schedule archive/restore cannot complete its lifecycle

Evidence: schedules use `SoftDeletes` at `api/app/Modules/Maintenance/Models/MaintenanceSchedule.php:19-45`; the SPA sends `trashed` and calls restore at `spa/src/pages/maintenance/schedules/index.tsx:32-59`. `MaintenanceScheduleService::list()` never applies `TrashedFilter` at `api/app/Modules/Maintenance/Services/MaintenanceScheduleService.php:30-47`, even though the shared filter supports `only`/`with` at `api/app/Common/Support/TrashedFilter.php:15-26`. The restore route binds a normal `MaintenanceSchedule` at `api/app/Modules/Maintenance/Controllers/MaintenanceScheduleController.php:67-70`, while the hash binding contract requires `->withTrashed()` for soft-deleted route binding at `api/app/Common/Traits/HasHashId.php:46-67`.

Impact: archived schedules are not retrievable through the archive view, and a deleted schedule cannot bind for restoration. The UI presents archive/restore controls that cannot recover the record, creating an operational data-recovery gap.

Action: apply the shared trashed filter in the list service, opt the restore route into `withTrashed()`, and make archive/restore transactional with tests for active, only-trashed, with-trashed, invalid, and restored states.

### M053-F09 — P2, Missing: authorized assignment has no usable web surface

Evidence: the backend route and permission exist at `api/app/Modules/Maintenance/routes.php:42-43`, and the SPA client defines `workOrdersApi.assign()` at `spa/src/api/maintenance/workOrders.ts:25-40`. No SPA caller or assignment control was found. The resource's `available_actions` type omits `assign` at `api/app/Modules/Maintenance/Resources/MaintenanceWorkOrderResource.php:91-100` and `spa/src/types/maintenance.ts:63-70`.

Impact: roles that are allowed to assign maintenance work orders have no discoverable browser workflow; the feature is effectively API-only. The current maintenance-tech restriction on assignment is intentional, but it does not explain the absence of an admin/dispatcher surface for the permission that remains seeded.

Action: decide the assignment owner and lifecycle policy, then add an authorized assignee selector/action, include `assign` only when valid, align the HashID request/client contract, and cover role/action visibility plus direct API denial.

### M053-F10 — P2, Incomplete: downtime analytics search is a dead control

Evidence: the page stores `filters.search` and includes it in query keys at `spa/src/pages/maintenance/downtime/index.tsx:74-103`, but every query calls the API without `search`. The client advertises the parameter at `spa/src/api/maintenance/downtimeAnalytics.ts:10-33`, while the controller validation and service queries do not accept or apply it at `api/app/Modules/Maintenance/Controllers/DowntimeAnalyticsController.php:23-42` and `api/app/Modules/Maintenance/Services/DowntimeAnalyticsService.php:35-42`.

Impact: typing a machine search term causes refetches with unchanged data, so the filter appears functional while the dashboard remains unfiltered. This is a misleading operator control and increases query traffic.

Action: either implement a validated machine-code/name filter consistently across the analytics endpoints or remove the search control and its client contract. Add a UI/API test proving a term changes the result set.

### M053-F11 — P2, Incomplete: downtime analytics returns raw machine IDs and disagrees with its SPA type contract

Evidence: `DowntimeAnalyticsService::topMachines()` returns an integer `machine_id` at `api/app/Modules/Maintenance/Services/DowntimeAnalyticsService.php:140-161`, and `allMachinesSummary()` returns raw `machine.id` at `:169-188`. The corresponding SPA types declare `TopMachineDowntime.machine_id` as a string but `MachineDowntimeSummary.machine.id` as a number at `spa/src/types/maintenance.ts:173-187`. Repository conventions require API resources to expose HashIDs rather than raw integer IDs at `CLAUDE.md:151-154` and `:568-573`.

Impact: the analytics API leaks internal identifiers, and the two endpoints do not share one stable identifier contract. Even though the current tables do not link to detail pages, consumers can persist or expose the raw values and future clickable views will inherit the mismatch.

Action: return hash IDs (or explicitly make these non-identity aggregates with no ID field), align TypeScript types, and add response-shape tests that reject raw integer identifiers.

### M053-F12 — P2, Incomplete: downtime metrics mis-handle outages crossing the report window

Evidence: summary and daily trend select rows only by `start_time` using `whereBetween` at `api/app/Modules/Maintenance/Services/DowntimeAnalyticsService.php:35-42` and `:102-120`, then sum the stored full `duration_minutes`. They do not intersect each downtime interval with the requested `[from,to]` window or handle an open row whose end is outside/null.

Impact: an outage that starts before the window is omitted, while an outage that starts inside and ends after the window contributes time beyond the requested period. MTBF, MTTR, availability, trend, and Pareto values can therefore be wrong at reporting boundaries.

Action: define interval clipping for closed and open downtime rows, use the same window semantics across summary/trend/top/Pareto, and add before/inside/after-boundary fixtures with open downtime coverage.

### M053-F13 — P2, Incomplete: the work-order broadcast has no current SPA consumer

Evidence: `MaintenanceWorkOrderCreated` advertises the `maintenance.dashboard` channel and `maintenance.wo_created` event at `api/app/Modules/Maintenance/Events/MaintenanceWorkOrderCreated.php:18-49`. A repository search found no SPA subscription to that channel/event, so generated work orders do not update a live maintenance surface through this contract.

Impact: the broadcast adds operational complexity without providing the documented live-tile behavior. Users must refresh or wait for another query invalidation to see newly generated work orders.

Action: either subscribe and invalidate/update the relevant maintenance queries with a Reverb regression test, or remove/defer the broadcast contract until a supported live surface exists.

## Strengths observed

- `start`, `complete`, and `cancel` use lock-then-re-read patterns, and the focused race/regression tests for completion, schedule recomputation, and spare-part cost accumulation pass.
- Spare-part issues use decimal/bcmath arithmetic and lock both the work order and item before recording stock movement and usage.
- Preventive-generation requests are staged through an outbox with deduplication and a durable listener handoff.
- The mobile work-order detail reads `available_actions`, uses the touch shell, and correctly avoids presenting completion before start; the desktop mismatch is isolated and actionable.
- Permission guards and the intentional machine-health/condition-reading scope cut are explicit. The audit did not recommend restoring unsourced predictive UI.
- PHP syntax, focused SPA lint, and TypeScript checks are clean.

## Verification performed

- Clean Compose-network run: `docker compose run --rm api php artisan test tests/Feature/Maintenance --compact` — 19 passed, 60 assertions.
- SPA: `npm run typecheck` — passed.
- Narrow SPA ESLint run over the maintenance routes/pages/API/types — passed with zero warnings.
- PHP syntax check over the Maintenance module and the two inspected Production breakdown listeners — passed.
- A first host-side PHPUnit attempt could not resolve the Compose-only `db` hostname and stopped before assertions; it was not treated as a product failure and was rerun successfully inside the Compose network.

## Evidence missing / follow-up validation

- Browser tests for desktop work-order transitions, schedule archive/restore, assignment, downtime search, and notification destinations.
- Negative API tests for direct completion, terminal spare-part/log mutation, raw analytics identifiers, boundary-clipped downtime, and stale assignment.
- Scheduler integration evidence showing recompute-before-generation and machine-hour behavior after completion.
- Cross-module breakdown tests covering corrective MWO creation, downtime linkage, duplicate event replay, and notification navigation.
- Production Reverb/channel verification and the final role matrix for assignment.

## Release decision

No production-code fixes were applied. The majority of findings require a coordinated work-order state machine, exact financial arithmetic, scheduler semantics, archive binding, or Production–Maintenance integration. M053 is released as 📋 Plan Ready with the ordered action plan in `action-plan.md`.
