# Schedule-aware WO equipment and worker assignment

Status: approved and implemented on October 5, 2026. Verification and rollout details are recorded in `docs/testing/schedule-aware-assignment-20261005.md`.

## Outcome and confirmed requirements

Automate equipment and crew suggestions using the existing scheduler. PPC reviews one concrete proposal before applying it. A production employee may belong to several WOs whose effective scheduled intervals do not overlap. Assigned names, one output recorder, machine, mold, and approved schedule remain visible on the WO detail; editing moves into an existing modal instead of extending the page.

Eligible workers must have active employment and an active HR Production department (`PROD`). PPC is the planning department, not the worker department. Use existing date-effective HR shifts and approved leave. Do not silently restore the prior rejected manual shift selector or access-window fields. Minimum workers remains 1 by default; maximum is optional per WO. Do not infer worker skills, production qualifications, or a headcount formula that the company has not configured.

Planning reservations and current physical occupancy are distinct. A running machine or installed mold can receive a later non-overlapping reservation; it cannot execute two WOs at once. Scheduling does not automatically start a machine, issue materials, accept QC, or change the customer's delivery promise.

## Evidence from the existing application

Read-only application database audit on 2026-10-05 found 20 active WOs, 15 without an active pending/confirmed/executed schedule, one completed/closed/cancelled WO with a blocking schedule, and 85 active Production-department employees. These are readiness counts, not proof that each record has an invalid schedule or staffing need. Both machine and mold PostgreSQL overlap constraints are installed.

| Priority | Gap or risk | Code evidence | Required correction |
| --- | --- | --- | --- |
| High | Direct confirmation uses precise machine windows only if both WOs have schedule rows; otherwise it treats another active WO as a blanket conflict. It does not share the scheduler's mold-window validation. | `WorkOrderService.php:989`; `CapacityPlanningService.php:243` | All assignment/confirmation paths require an authoritative slot and use the same machine and mold reservation checks. Missing timing is an explicit blocker, not inferred availability. |
| High | A future Confirmed WO is treated as physically occupying the mold. | `WorkOrderService.php:1088` | Runtime ownership must track the currently installed mold; future confirmation reserves time only. |
| High | Pause clears current machine ownership but leaves the mold in use; paused WOs are excluded by the normal mold-occupancy query. Another WO can reuse the mold without an explicit removal/release step. | `WorkOrderService.php:398`, `:1088` | Pause retains physical resources by default. Explicit manager release frees machine and mold together under locks; resume reacquires both. |
| High | Start and resume do not rerun product/mold compatibility after master data changes. | `WorkOrderService.php:325`, `:480`, `:957` | Recheck current compatibility, status, remaining capacity, and archived resources at start/resume. Block resource-critical edits during active ownership. |
| High | Completion marks schedules Executed, but Executed remains a blocking status in queries and both exclusion constraints. Historical planned hours can keep reserving released capacity. | `WorkOrderService.php:611`; `CapacityPlanningService.php:47`; migrations `0479` and `0498` | Preserve history and explicitly end the reservation when physical work ends. Do not remove QC or closure evidence. |
| High | Mold-shot budgets start from zero for each scheduler run; older pending/confirmed commitments are absent. Confirmation has only a per-WO check. | `CapacityPlanningService.php:109`, `:626`; `WorkOrderService.php:957` | Budget remaining mold capacity across all active reservations and outstanding runtime work, deduplicated by WO/context. Recheck under the mold lock. |
| High | Mold counters increment by produced pieces even for multi-cavity molds; maintenance/lifetime units may be misunderstood. | `WorkOrderOutputService.php:351`; `MoldService.php:142` | Keep the existing piece-based upper bound conservatively for this slice, disclose it as estimated cycle usage, and never silently convert historical counters. Validate counter units before relaxing the estimate. |
| High | Lifetime mold ceiling is not included in assignment validation or normal shot increment. | `WorkOrderService.php:957`; `MoldService.php:142` | Enforce maintenance and lifetime ceilings. Maintenance can reset the maintenance counter, never lifetime usage. |
| High | Reassigning to a different mold keeps the old interval even if its rate or setup time differs. | `CapacityPlanningService.php:279` | Recompute duration using the new mold and validate the new end before applying the change. |
| High | Reordering validates machine gaps but omits mold conflicts, planned_end, and subassembly ordering checks during reflow. Database mold constraint can raise a generic error. | `CapacityPlanningService.php:789` | Revalidate the complete proposed reflow before writing, then atomically apply or leave the original plan intact. |
| High | Runtime inline repair blocks equipment merely committed to another future Confirmed/Paused WO; repair has no timed schedule context. Release can overwrite a maintenance state. | `WorkOrderReworkService.php:38`, `:59` | Give machine repair an NCR-scoped reservation and owner. Share runtime checks and preserve breakdown/maintenance status when releasing. |
| High | Worker picker accepts any active employee; staffing guards do not check department, schedule overlap, or competing runtime work. | `WorkOrderAssignmentController.php:39`; `WorkOrderAssignmentService.php:68`, `:145` | Enforce Production-department eligibility and temporal conflicts in the shared service, not just the picker. |
| Medium | Future worker options show today's HR shift; approved leave and overnight shifts are not considered for planned assignment. | `WorkOrderAssignmentService.php:38` | Evaluate shift coverage on the proposed dates; actual output still snapshots the actual date-effective shift. |
| Medium | Daily capacity ignores an old pending row for overlap but counts it again in dayBooked; crossing midnight can skip capacity checks. Duration rounds down. | `CapacityPlanningService.php:536`, `:569`, `:660` | Remove a replanned WO from every capacity sum; round duration up; validate each intersected local day. Bound search by due date or configured horizon. |
| Medium | Setup time is included, but mold transfer/removal and crew handover buffers have no explicit data. | `CapacityPlanningService.php:658` | Keep existing setup as the minimum preparation allowance. Show immediate adjacency; PPC can reserve extra time in the explicit slot. Do not invent a universal transfer duration. |
| Medium | Machine hours, mold compatibility, retirement/archive, and reset paths can change plans or active equipment without common validation. | `MachineService.php:78`; `MoldService.php:95`, `:107`, `:124`, `:182`, `:237` | Lock resources; block ownership-breaking edits while active; flag affected future proposals for review; do not delete history. |
| Medium | Started schedule model updates silently return false; DB trigger throws instead. | `ProductionSchedule.php:65`; migration `0479` | Return a clear business error for forbidden time/resource edits. Permit only an explicit reservation-release transition while keeping started schedule timing immutable. |
| Medium | Lock ordering differs across scheduler, direct confirm, and lifecycle paths. New worker checks could introduce deadlocks. | `CapacityPlanningService.php:188`; `WorkOrderService.php:263` | Use consistent WO, NCR, machine, mold, employee, schedule lock order; sort IDs. Apply bounded transaction retries for deadlock victims. Never trust preview availability during commit. |
| Medium | Machine `operators_required` is decimal and currently disconnected from per-WO staffing. | `UpdateMachineRequest.php:27`; `WorkOrderAssignmentService.php:145` | Display as an advisory planning field. Keep approved per-WO limits authoritative; do not silently round or override them. |

Code paths in the table are below `api/app/Modules/Production/Services`, `api/app/Modules/MRP/Services`, or the named controller/model unless otherwise stated. The implementation plan names full paths.

## Schedule and ownership rules

All slot comparisons use half-open intervals: `[start, end)`. Overlap exists exactly when `a.start < b.end && b.start < a.end`. Adjacent intervals are allowed when setup/preparation is already included and all other constraints pass. Persist and compare in the application's established schedule timezone; accept explicit offset-bearing timestamps and show timezone labels. Do not reinterpret existing timestamp columns during this change.

A WO due/planned end is a deadline, not automatically an occupied interval. Canonical occupancy comes from `production_schedules.scheduled_start/scheduled_end`. A manual confirmation must select an existing valid proposal or create an explicit slot through the same duration and reservation validator.

| Action | Reservations | Physical state |
| --- | --- | --- |
| Propose assignment | Select a compatible, non-overlapping slot; do not change production state | Running/in-use is allowed for a later slot when expected release is known |
| Apply or confirm | Revalidate schedule, crew, resource state, and mold capacity under locks | Do not claim runtime ownership or start production |
| Start | Approved slot and staffing must still be valid; an early start outside the slot requires PPC to revise it first | Atomically acquire equipment and ensure workers are not still executing another context |
| Pause | Preserve schedule history and signal delay risk | Retain equipment and worker runtime responsibility by default |
| Release paused production | Mark remaining reservation as released or needing a new slot; no output is changed | Manager explicitly releases machine and mold together and ends runtime worker occupancy; assignment membership may remain for paused visibility |
| Resume | Reapproved remaining slot if released/expired; recheck remaining workload and crew | Reacquire resources; no takeover of another runtime owner |
| Complete production | End reservation immediately; preserve original timing and Actual end | Release only owned equipment; leave outgoing QC and WO closure rules intact |
| Cancel unstarted work | End reservation and crew eligibility | Release only resources actually owned by that context; retain history |
| Repair | Use a separate NCR-scoped slot and crew | Manual repair requires workers but no machine/mold; machine repair claims both resources |

A delay never releases ownership automatically. Elapsed scheduled_end is not proof that a running or paused WO finished. Early completion may make equipment free now, but must not automatically move or start the next WO or alter the customer's delivery schedule.

## Minimal data changes

1. Add `production_schedules.reserves_capacity` with default true; migrate terminal/executed historical production reservations to false only after classifying the actual parent context. Update both exclusion predicates to reserve only rows with this flag. Preserve status and original timing.
2. Add nullable `production_schedules.rework_ncr_id` to support timed inline repair without creating another production WO.
3. Add nullable current owner fields to the mold: `current_work_order_id`, `current_rework_ncr_id`. Add machine `current_rework_ncr_id` alongside its existing `current_work_order_id` so multiple NCRs on one WO cannot release each other's equipment.
4. Add nullable `work_orders.production_released_at` for explicit paused resource release. Reset it on successful resume; never use it to reopen completed output.
5. No employee permission window or new HR roster. Worker planned reservations derive from current crew memberships joined to the context's active schedule; runtime responsibility derives from active ordinary/repair contexts. Existing ended crew revisions remain immutable.

The reservation flag is not a second status workflow. It exists because execution history and future capacity consumption have different lifetimes. Schedule state/flag combinations are written only through explicit lifecycle operations. Rollback must restore old constraint semantics with a conflict preflight; it must not discard new owner or history fields. Keep application and database changes compatible during rollout.

## Automated proposal and application

Reuse `CapacityPlanningService` for compatible equipment and duration. After a successful pending equipment proposal, suggest Production-department workers with shift coverage and no approved leave or overlapping crew reservation. A support worker may lack a login; the selected recorder must have an active internal account. Do not include company-wide HR or financial data in worker options.

Missing or zero machine daily hours, zero mold output rate, or unresolved equipment ownership blocks automatic scheduling and names PPC/Maintenance as owner. Do not treat zero capacity as an unlimited day. Keep the supported machine-day and employee-shift coverage explicit; missing factory rest-day information is a readiness warning, not an invented working calendar.

Select the minimum required count by default. Choose the recorder from eligible selected members. Use least booked minutes in the visible planning horizon, then employee number/ID for deterministic tie-breaking; no optimization engine. Additional workers remain PPC's choice up to the optional maximum.

A proposal is not a committed crew. Regenerating proposals must not end an existing crew, send worker notices, or rewrite confirmed WOs. PPC applies a preview carrying its schedule timestamp/version and expected crew revision. Combined confirmation requires the existing confirmation permission as well as crew assignment permission; suggestion/apply cannot expand role authority. The server rechecks both. Applying crew and confirming a planned WO is atomic with existing material reservations; an already Confirmed WO may receive only a valid crew revision through existing permission rules. Any failed member/resource leaves the whole application unchanged.

When minimum staffing cannot be met, show the available count and exact blockers. Do not fill with other departments or overlap workers to make the automation look successful. A valid current manual crew is preserved on rerun. Confirmed/live assignments change only through an explicit authorized review.

Future selection and manual assignment use full HR shift coverage, approved leave, holidays, and day boundaries. Start and resume recheck that coverage. Half-day leave is conservative: exclude that date until an established half-day interval resolver is reused. Missing HR coverage requires a roster or scheduling review; an assignment reason does not bypass this check. There is no requirement to revoke output access at HR shift end; live crew handover, overtime output and original submission replay remain supported.

No configured WO/machine skill requirements currently exist. Department matching is the implemented qualification boundary for this slice. Do not advertise automatic certification matching until a required-skill mapping exists.

## Replanning and resource changes

Every proposed machine/mold change or time change recalculates duration, daily capacity, due date, subassembly prerequisites, worker overlaps, and mold life. Do not silently move a confirmed job. A affected crew remains visible and gets a scheduling blocker until PPC approves the revised timing; output guards still use actual runtime state.

Machine breakdown, maintenance start, mold maintenance/retirement, employee suspension/department transfer, approved leave, and shift reassignment invalidate affected future availability. Reuse existing in-app notification/outbox and Action Center mechanisms. Emit one notice per changed conflict context, with WO, resource, affected time, owner, and link. Suppress unrelated/repeated notices. Notify PPC plus production management; Maintenance receives equipment issues; assigned employees see only their assignment change. Sales receives a delivery-risk notice without an automatic date proposal or promise change.

Maintenance has due dates, not reliable reserved start/end durations. Exclude resources under active maintenance/breakdown/offline/retired state. Show upcoming due maintenance as requiring Maintenance/PPC review; a next_due_at point is not a fabricated downtime interval. Do not automatically promise a currently unavailable machine's future recovery time.

Overrun detection uses the existing scheduled alert sweep and request-time readiness checks. Flag downstream conflicts; keep runtime ownership. Allow PPC to generate replacement proposals for unstarted jobs only. No new daemon, live factory takeover, or silent confirmed-plan rewrite.

## WO layout

Always show machine, mold, approved local date/time, crew count and limits, all assigned worker names, and a visible Recorder marker. Wrap names rather than hiding workers behind a history dropdown. Use existing Panel and Modal components; no new drawer library.

`Manage assignment` opens workers, recorder, staffing limits, reason, and equipment/time controls permitted by WO state. `Suggest assignment` shows a reviewable proposal plus reasons. Existing crew history moves inside the modal. Successful apply refreshes summary and schedule; failed loading or stale previews disable apply. Support read-only roles see the summary without edit controls. The schedule list shows staffing readiness and links to each WO; the Gantt remains useful for machine timing.

## Legacy data and rollout

Run a dry report first. For each active WO without an authoritative slot, distinguish unstarted planning, active execution, paused execution, and repair. Do not manufacture elapsed slots or assign random workers to ongoing historical WOs. PPC repairs unstarted timing; production management records real current ownership and reviews active/paused work. Release verified terminal reservations only. Conflicting installed mold owners are blockers requiring human resolution.

Before enabling automation, verify counter units, resource compatibility, available machine hours, HR Production membership, shifts, and recorder accounts. Existing manual assignment remains available while proposals are reviewed. Readiness checks identify missing data with an owner; never let missing configuration degrade into a success message.

## Acceptance and explicit limits

Acceptance includes different-slot confirmation while equipment is currently running, one installed mold at a time, retained pause ownership and explicit release, exact repair ownership, non-overlapping multi-WO crews, department filtering at HTTP and service boundaries, cumulative mold limits, changed-rate duration, stale preview/concurrent application, precise timezone/day capacity, preserved material/QC/receipt/closure rules, and visible names on desktop/mobile.

Use real PPC, manager, Maintenance, recorder, support, outsider, and portal accounts in headless Playwright against the isolated audit application. Backend regressions cover the same shared guards plus concurrency and transaction rollback.

No global role grants, automatic machine startup, fixed skill catalog, predictive optimizer, new equipment calendar module, automatic customer delivery-date changes, or multi-day production splitting. Jobs that cannot fit supported daily/shift coverage get an explicit scheduling conflict.
