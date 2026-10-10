# Schedule-aware Resource Assignment Implementation Plan

> **For agentic workers:** Use `superpowers:executing-plans` task by task. Delegation is optional only when authorized by the user or applicable instructions. Steps use checkbox syntax for tracking.

**Goal:** Suggest and apply compatible scheduled equipment and Production-department crews without double-booking physical resources or workers, while keeping the WO detail compact and informative.

**Architecture:** Reuse `CapacityPlanningService`, `WorkOrderAssignmentService`, existing schedule/crew tables, and current notification/outbox flows. Put shared equipment reservation, current-owner, duration, and capacity rules in one focused `ProductionResourceService`; all scheduler, direct WO, repair, and maintenance callers use that seam. Crew planned availability derives from authoritative schedule contexts; it does not create employee permission windows.

**Tech Stack:** Laravel 11, PHP 8.3, PostgreSQL exclusion constraints and row locks, React/TypeScript, TanStack Query, existing Panel/Modal, Vitest, real headless Playwright.

**Spec:** [Schedule-aware resource assignment design](../specs/2026-10-05-schedule-aware-resource-assignment-design.md).

## Global constraints

- The user approved implementation on October 5, 2026. Product code and the compatible development schema changes below are authorized; unrelated business records remain untouched.
- Production worker department is active HR department `PROD`; PPC owns planning, not production worker eligibility.
- Minimum workers defaults to 1; maximum is optional; exactly one designated recorder has an active internal account.
- A worker can have multiple non-overlapping WO reservations. Neither department nor overlap restrictions has a manual bypass.
- Reuse HR shifts and approved leave. No separate permission windows or global role changes. Actual overtime output remains allowed under valid runtime assignment.
- Equipment interval overlap uses `[start, end)`. Running/InUse does not itself block a future reservation; current ownership blocks execution.
- Retain equipment on pause unless management explicitly releases both. Resume must reacquire equipment and workers and honor approved remaining timing.
- Never rewrite started schedule timing or historical output. Preserve completed schedule history while releasing capacity reservations.
- Do not change the customer's agreed delivery date automatically. Do not automatically start production, issue material early, or approve QC.
- Existing material, dependency, QC, receipt, idempotency, output target, and closure gates remain mandatory.
- No new dependency, optimization engine, skill requirement catalog, maintenance calendar, background daemon, or multi-day split production.
- Keep mold life checks conservatively piece-based until actual counter units are validated. Never silently divide existing counters by cavity count. Enforce lifetime and maintenance ceilings.
- Preserve unrelated workspace changes. No commits, deploys, or production data repair as part of writing this plan.

## File map

| Responsibility | Files |
| --- | --- |
| Additive schema and constraint migration | Create `api/database/migrations/2026_10_05_235000_add_schedule_resource_ownership.php`; modify `api/app/Modules/Production/Models/ProductionSchedule.php`, `Enums/ProductionScheduleStatus.php` only if serialization needs it, `Models/WorkOrder.php`; `api/app/Modules/MRP/Models/Machine.php`, `Models/Mold.php` |
| Shared equipment rules | Create `api/app/Modules/Production/Services/ProductionResourceService.php`; modify existing `WorkOrderService.php`, `WorkOrderReworkService.php`, `WorkOrderOutputService.php`, `WoOperationService.php` |
| Scheduler proposal, confirm, reassign, reorder | Modify `api/app/Modules/MRP/Services/CapacityPlanningService.php`, `Controllers/SchedulerController.php`, `Requests/ConfirmScheduleRequest.php`, `Services/MrpAutomationService.php` |
| Crew eligibility and automatic suggestions | Modify `api/app/Modules/Production/Services/WorkOrderAssignmentService.php`, `Controllers/WorkOrderAssignmentController.php`, `Requests/AssignWorkOrderCrewRequest.php`, `Requests/ConfirmWorkOrderRequest.php`, `Controllers/WorkOrderController.php`, `Controllers/AssignedWorkOrderController.php`, `routes.php`; consume existing HR/Attendance/Leave models and `ShiftAssignmentService` |
| Resource changes and notices | Modify `api/app/Modules/MRP/Services/MachineService.php`, `Services/MoldService.php`; `api/app/Modules/Maintenance/Services/MaintenanceWorkOrderService.php`; `api/app/Modules/Production/Listeners/HandleMachineBreakdown.php`, `NotifyOnMachineBreakdown.php`, `api/app/Modules/Leave/Listeners/NotifyOnLeaveApproved.php`, `api/app/Modules/HR/Services/EmployeeService.php`, `api/app/Modules/Attendance/Services/ShiftAssignmentService.php`; `api/app/Common/Services/NotificationCatalog.php` if catalog entries are required |
| Compact assignment UI | Modify `spa/src/pages/production/work-orders/CrewPanel.tsx`, `detail.tsx`, `ReworkPanel.tsx`, `spa/src/pages/production/schedule.tsx`, `spa/src/api/production/workOrders.ts`, `spa/src/api/mrp/scheduler.ts`, `spa/src/types/production-crew.ts`, `spa/src/types/mrp.ts`; reuse existing Modal |
| Backend proof | Extend `api/tests/Feature/MRP/CapacityPlanningServiceTest.php`, `api/tests/Feature/Production/WorkOrderCrewTest.php`, `WorkOrderStartResumeOccupancyTest.php`, `api/tests/Feature/Quality/WorkOrderInlineReworkTest.php`; add `api/tests/Feature/Production/ProductionResourceReservationTest.php` |
| Browser proof and data preflight | Extend `spa/e2e-real/wo-worker-assignment.spec.ts`, `scripts/wo-crew-e2e-fixture.php`; create read-only `scripts/wo-resource-readiness.php` and `docs/testing/schedule-aware-assignment-20261005.md` |

Reuse `api/app/Common/Services/NotificationCatalog.php`, `NotificationService.php`, existing `api/app/Common/Services/OutboxService.php` and `api/app/Common/Services/AlertEngineService.php`. Do not create a parallel event or alert framework. The breakdown listener currently locks the machine before touching its WO; include that caller in the lock-order correction.

## Task 1: Reservation lifetime and explicit physical ownership

**Interfaces:** Existing schedule ID/history remains stable. `reserves_capacity` determines reservation conflicts; schedule status remains execution/proposal history. Ordinary owner is `(work_order_id, null)`; repair owner is `(work_order_id, rework_ncr_id)`.

- [ ] Add a focused failing test that a completed Executed schedule does not prevent a later reservation for its released equipment. Use the existing `CapacityPlanningServiceTest` fixtures:

```php
public function test_completed_history_does_not_reserve_equipment(): void
{
    $machine = $this->machine('HISTORY-M', 100);
    $mold = $this->mold('HISTORY-D');
    $mold->compatibleMachines()->sync([$machine->id]);
    $finished = $this->workOrder('HISTORY-DONE', WorkOrderStatus::Completed, '100', 1);
    $row = $this->schedule($finished, $machine, $mold, 8, 12);
    $row->update(['status' => ProductionScheduleStatus::Executed, 'reserves_capacity' => false]);
    $next = $this->workOrder('HISTORY-NEXT', WorkOrderStatus::Planned, '50', 1);
    $result = $this->planner->run([$next->id]);
    $this->assertCount(1, $result['scheduled']);
    $this->assertSame('08:00', Carbon::parse($result['scheduled'][0]['scheduled_start'])->format('H:i'));
    $this->assertDatabaseHas('production_schedules', ['id' => $row->id, 'status' => 'executed']);
}
```

- [ ] Run `CapacityPlanningServiceTest` using the isolated PHPUnit configuration; record the new test's failure before editing schema.
- [ ] Add nullable owner fields and reservation metadata. Migration uses `Schema::table` and existing foreign keys; add `reserves_capacity` to model casts. The exclusion predicate becomes:

```sql
WHERE (reserves_capacity AND status IN ('pending', 'confirmed', 'executed'))
```

  Keep the started-schedule trigger for timing/resource changes. Permit only the explicit `reserves_capacity: true -> false` release when every other field except `updated_at` is unchanged. Replace the Eloquent silent false return with a business-rule exception. New rows for a released paused WO are allowed only through the approved remaining-slot path.
- [ ] Produce a dry readiness report before backfilling. Report active WOs lacking slots, expired running slots, ambiguous paused mold owners, terminal blocking schedules, repair ownership, overlapping worker proposals, and unknown counter units. Output IDs/counts and owners; do not write data.
- [ ] Backfill only unambiguous current owners and verified terminal ordinary reservations. An active NCR on a Completed WO is not terminal production history. If multiple runtime contexts claim one mold, stop migration/backfill with a reviewable report rather than selecting one.
- [ ] Run migration up against disposable data and verify history retention and both exclusion constraints. Exercise duplicate reservations and rollback preflight; keep additive data during application rollback. No production migration until implementation is verified and deployment is authorized.

## Task 2: Shared planning checks and runtime resource lifecycle

**Interfaces:** Add the following concrete service contract; all IDs are decoded integers inside services and hash strings over HTTP:

```php
final class ProductionResourceService
{
    public function durationMinutes(WorkOrder $wo, Mold $mold, ?int $quantity = null): int;
    public function assertReservation(WorkOrder $wo, Machine $machine, Mold $mold,
        Carbon $start, Carbon $end, ?int $scheduleId = null, ?int $ncrId = null): void;
    public function assertCanAcquire(WorkOrder $wo, Machine $machine, Mold $mold,
        ?int $ncrId = null): void;
    public function acquire(WorkOrder $wo, Machine $machine, Mold $mold,
        ?int $ncrId = null): void;
    public function release(WorkOrder $wo, Machine $machine, Mold $mold,
        ?int $ncrId = null): void;
}
```

`durationMinutes` uses `ceil(quantity / positive_output_rate * 60 + setup_time_minutes)`, retaining the existing minimum 30-minute allocation. Quantity defaults to remaining workload, not the original target again after a pause. It rejects missing/zero rate rather than silently substituting 1.

- [ ] Write failing tests for future confirmed mold not blocking current start, other runtime owner blocking start, paused owner retention, explicit release, reacquisition, ownership-specific repair release, and maintenance state preservation. Use `HasAssignedProductionCrew` and `HasPassedInProcessQc`, keeping existing fixture material coverage real.
- [ ] Run `ProductionResourceReservationTest|WorkOrderStartResumeOccupancyTest|WorkOrderInlineReworkTest` and verify the targeted failures.
- [ ] Implement planning checks separately from acquisition. Planning validates product/compatibility, active resource status, exact interval, reservations on machine and mold, due date, daily capacity, outstanding conservative mold usage, and maintenance/lifetime ceilings. It never claims current ownership.
- [ ] Implement exact current ownership checks. Retained paused contexts and active repairs count as physical owners. A future confirmed context does not. Inconsistent status/owner combinations return an actionable error instead of automatically clearing ownership.
- [ ] Add explicit authorized paused release to `WorkOrderController`, request validation, and routes. Require existing lifecycle permission and a reason; reject release from InProgress or terminal states. Atomically release owned resources, set `production_released_at`, release remaining reservation and preserve historical interval/output. Do not return issued materials automatically.
- [ ] Route start/resume/repair start through shared acquisition; complete/cancel/repair finish through ownership-aware release. Recheck compatibility on every fresh start/resume. Do not overwrite breakdown/maintenance/retired state while freeing owner IDs.
- [ ] Gate new normal or machine-repair output on current equipment ownership and usable state before increments. Exact saved replay still resolves first; no new output bypasses a breakdown or exhausted lifetime ceiling. Manual repair has no equipment requirement.
- [ ] Run the lifecycle/material/QC/repair suites and verify no inventory receipt, NCR, or closure guard was bypassed.

## Task 3: Schedule proposals, changes, and mold capacity

**Consumes:** Task 2 service; Task 1 reservation flag and exact repair context.
**Produces:** Valid versioned schedule proposals whose duration/resources/crew time are coherent for later application.

- [ ] Add failing tests using real scheduler fixtures: slower mold extends interval; cumulative commitments across separate runs exceed remaining mold life; same mold waits for another machine's slot; priority reflow misses due date; replanning old pending row does not consume daily hours twice; midnight/day-capacity and round-up boundaries.
- [ ] Run `CapacityPlanningServiceTest` and confirm each added behavioral failure.
- [ ] Change every active-window query to `reserves_capacity = true`; retain excluded historical rows in read-only snapshots. For each candidate, advance to the next jointly free machine-and-mold interval, not just the machine gap. Unknown/zero daily machine hours or zero mold rate yields an explicit missing-capacity blocker instead of unlimited capacity. Stop at WO due date or configured planning horizon; return a conflict when unsupported daily coverage cannot fit.
- [ ] Sum conservative remaining booked usage across pending/confirmed and runtime contexts, excluding the candidate's replaced old proposal and deduplicating WO/NCR ownership. Include lifetime and maintenance limits. No double-counting already recorded usage.
- [ ] Recalculate duration on equipment changes and revalidate whole reflow before writes. Keep confirmed/started rows as anchors. Failed checks roll back, including priority, schedule times, equipment and crew updates. Translate named PostgreSQL overlap violations into clear resource/WO/time messages.
- [ ] Make direct WO confirmation select a pending proposal or obtain an explicit slot through the same checker. It must not assign a new machine while leaving a pending row pointing to the old one. Confirm schedule and WO in one transaction with existing material reservation behavior.
- [ ] Require a current version on proposal application: schedule ID and `updated_at`, plus `expected_crew_id`. Compare under locks; reject stale versions. Retry a response-lost confirmation only after checking whether the exact requested proposal is already confirmed by that action; never apply a new proposal invisibly.
- [ ] Reorder locks consistently for competing WO, equipment, crew and schedule writes: collect all touched IDs, lock WOs in ascending ID order, NCRs, machines, molds, employees, then schedule rows. Any maintenance path discovering an owner must collect the WO first or roll back/retry; do not lock another WO while holding its equipment in the reverse order.
- [ ] Run scheduler and new reservation tests, including two genuinely concurrent commits. The winner reserves once; the loser gets a conflict/stale result, not double-booked equipment or a partial material reservation.

## Task 4: Department-aware workers and automatic crew suggestions

**Consumes:** Canonical slot, current owner/release state, existing HR shift and leave records.
**Produces:** One reviewable minimal crew and enforced non-overlapping assignment.

Add methods to the existing crew service:

```php
public function eligibleWorkers(WorkOrder $wo, Carbon $start, Carbon $end,
    ?int $ncrId = null): Collection;
public function suggest(WorkOrder $wo, ProductionSchedule $schedule,
    ?int $ncrId = null): array;
```

`eligibleWorkers` returns minimal employee projections only. `suggest` returns selected members, recorder, existing minimum/maximum, schedule version, expected crew ID, and blocker messages. It performs no assignment, status changes, or notices.

- [ ] Write the essential shared-service regressions in `WorkOrderCrewTest`. Add `Department::firstOrCreate(['code' => 'PROD'], ['name' => 'Production', 'is_active' => true])`, real date-effective HR shifts, and authoritative schedule fixtures to the existing worker helper. Verify:

```php
$this->assertCount(2, WorkOrderCrew::query()->whereNull('ended_at')
    ->whereHas('members', fn ($q) => $q->where('employee_id', $worker->employee_id))->get());
$this->assertDatabaseHas('work_order_crew_members', ['employee_id' => $worker->employee_id]);
$this->assertFalse($worker->hasPermission('production.wo.record'));
```

Include the following scenarios:

```php
// Use two WOs with authoritative 08:00-10:00 and 10:30-12:00 slots,
// an employee whose active department code is PROD, and an HR shift covering both.
// Assign the same employee to both and assert two current crews.
// Move the second pending slot to 09:00-11:00 and assert crew application fails.
// Create an active non-PROD employee and assert both options and POST assignment exclude/reject them.
```

  Also add current runtime overrun, approved leave, overnight shift, department transfer, inactive department, no active recorder account, support without account, missing schedule and insufficient minimum cases. Actual mutation tests must assert no crew ended or notifications sent after failure.
- [ ] Run the crew tests and capture failures before adding worker filters.
- [ ] Implement active `PROD` filtering in options and assignment service. Recheck it in start/resume/new output. Compare context schedules for all current memberships; adjacent intervals pass, any overlap fails. Missing timing blocks automatic selection; existing unresolved runtime contexts conservatively block conflicting new starts.
- [ ] Resolve HR shift on scheduled local dates, including previous-date night coverage. Automatic selection requires whole-slot coverage, no approved leave, and supported working-day coverage. Exclude a half-day leave date conservatively if no existing exact leave-period resolver exists. Manual overtime timing may require a manager reason; it never bypasses department, current ownership or overlap checks and does not add a shift-based output permission cutoff.
- [ ] Suggest exactly minimum workers, respecting maximum and at least one eligible recorder account. Rank eligible employees by booked minutes over the planning horizon then employee number/ID. Preserve already valid selected manual crew. Return an explicit minimum staffing blocker rather than selecting other departments or overbooked employees.
- [ ] Lock selected employee rows in ascending order during apply, then recheck conflicting crew reservations. This closes the race where two different WOs claim the same worker concurrently. Runtime start/resume also locks its crew members and rejects those still executing another unreleased context.
- [ ] Add suggestion GET and versioned apply POST routes under existing assignment permissions. Hash IDs resolve at the request boundary. Combined application requires both `production.wo.assign` and the existing relevant `production.wo.confirm` or `production.schedule.confirm` permission; assignment permission alone must never confirm production. PPC can apply initial Planned/Confirmed crews; only production management handles live handover. Apply equipment proposal plus initial crew plus WO confirmation transactionally; an already Confirmed WO changes only its explicitly reviewed crew.
- [ ] Run crew, output replay, operation handover, material, and repair regressions. Update test fixtures to genuine Production department and scheduled contexts instead of exempting tests from the new guards.

## Task 5: Resource changes, readiness messages, and bounded automation

**Consumes:** Shared resource and staffing readiness rules.
**Produces:** Explicit owner/action messages; affected unstarted proposals require review instead of failing silently.

- [ ] Add tests for compatibility removal, reduced mold limit, archive/retirement during ownership, maintenance start/finish, employee transfer/leave change, and schedule overrun. Assert affected work is visible and no confirmed/customer promise is changed automatically.
- [ ] Guard master-data mutations under resource locks. Block changes that break active ownership; active maintenance cannot be cancelled by an unrelated reset or release. Audit mold reset/commission/decommission paths as well as normal edit/delete endpoints.
- [ ] Future affected proposals receive a derived readiness blocker from current data; request-time preview/apply/start always revalidates. Existing event/outbox listeners enqueue one notice using their existing deduplication key conventions. Use the existing scheduled alert sweep for overdue unresolved execution and dependent reservations; no new polling daemon.
- [ ] Notifications contain affected WO/context/resource and time, responsible team, and direct link. PPC and management get scheduling conflicts; Maintenance gets equipment problems; assigned workers receive only scoped assignment changes. Sales gets delivery risk, never an automatically accepted changed delivery date.
- [ ] After maintenance resolution, suggest replanning only unstarted work. A next_due_at without a reliable end does not define free equipment. Do not auto-release overdue work, automatically resume a paused machine, or silently reassign an existing crew.
- [ ] Run the maintenance and relevant alert regressions. Simulate duplicate event delivery and assert one visible conflict notice per conflict revision.

## Task 6: Compact WO summary and proposal review

**Consumes:** Existing crew plus versioned suggestion/apply responses.
**Produces:** Persistent visible assigned workers with an editable modal and short review flow.

Use existing `Panel`, `Modal`, `Button`, `SearchableSelect`, and current 300 ms debouncing. Keep the incumbent design system. No new drawer dependency.

- [ ] Add UI tests that all assigned names and the Recorder marker stay on the record page, while fields/history are absent until Manage assignment opens:

```tsx
expect(screen.getByText('Ada Production')).toBeVisible();
expect(screen.getByText('Ben Production')).toBeVisible();
expect(screen.getByText('Output recorder')).toBeVisible();
expect(screen.queryByLabelText('Add worker')).not.toBeInTheDocument();
await user.click(screen.getByRole('button', { name: 'Manage assignment' }));
expect(screen.getByRole('dialog')).toBeVisible();
expect(screen.getByLabelText('Add worker')).toBeVisible();
```

- [ ] Run focused UI tests and confirm failure with the current expanded form.
- [ ] Replace inline assignment form with modal controls. Always show equipment, full approved local date/time/timezone, staffing count/limits, all worker names and recorder in summary. Preserve read-only views. Keep repair context/equipment distinct. Display machine's decimal operators_required as advisory only.
- [ ] Add Suggest assignment review: selected equipment, duration, schedule, crew names/recorder, readiness reasons, Apply and Cancel. Apply remains disabled on loading errors, stale preview, missing schedule, or inadequate crew. Successful apply refreshes WO, crew and schedule; unsuccessful apply preserves prior record and entered selections.
- [ ] Schedule list shows crew readiness and WO links. Current occupied resource is labeled with its actual owner separately from future bookings. Failed requests name resource, conflicting WO, time and responsible team. Do not silently hide running/in-use equipment from future slot selection.
- [ ] Add role/keyboard/modal-focus/failed-lookup tests and inspect desktop/mobile once together; fix discovered layout issues together and confirm once. Do not repeat cosmetic iterations after acceptance.

## Task 7: End-to-end proof and reviewed rollout

- [ ] Extend the guarded audit fixture with Production-department employees, two compatible scheduled WOs, one shared mold, separate machines, HR shifts, approved-leave and non-PROD users. Keep the existing isolated database/base-URL guards. Add no production fixtures.
- [ ] Add headless real-account scenario: PPC previews and applies a crew/equipment slot, same workers receive a second non-overlapping WO, overlapping worker/equipment proposal is blocked, current WO starts despite a future confirmed mold booking, retained pause blocks actual takeover, explicit release permits reviewed reuse, and resume rejects a new occupant. Manager/worker output retains QC/material gates and original-actor replay.
- [ ] Add Maintenance scenario that active equipment cannot be taken through unsafe state reset and that breakdown marks downstream readiness issues. Include NCR machine repair and exact owner release in backend coverage and a browser review if the existing repair fixture supports it.
- [ ] Run scoped frontend tests and production build, backend scheduler/crew/lifecycle/material/repair/maintenance suites, and real headless Playwright. Record counts, commands, limits and screenshots. No whole Sales Order-to-payment claim from this slice alone.
- [ ] Re-run the dry readiness report and prepare an exact list of legacy timing/owner repairs. PPC reviews unstarted slots; production management reviews active/paused/repair ownership. No bulk random worker assignment, guessed elapsed schedule, or counter conversion.
- [ ] Deploy additive fields and compatible application checks together only within authorized deployment scope. Verify both resource constraints, Production department membership, own-receipt replay, and actual role permissions. Release verified terminal reservations; do not clear ongoing owners.
- [ ] Stop when the acceptance matrix below passes. Future features require a separate request.

## Verification commands

Use the existing isolated PHPUnit configuration, never the application business database:

```bash
docker exec ogami-api php -d memory_limit=-1 vendor/bin/phpunit -c phpunit.pricing.20261004.xml --filter 'ProductionResourceReservationTest|CapacityPlanningServiceTest|WorkOrderCrewTest|WorkOrderStartResumeOccupancyTest|WorkOrderInlineReworkTest|WorkOrderMaterialIssueHandoffTest|WorkOrderCloseQualityGateTest|WorkOrderOutputIdempotencyKeyTest|MoldStatusRestoreOnCompleteTest'
```

From `spa`:

```bash
npx vitest run src/pages/production/work-orders/CrewPanel.test.tsx src/pages/production/work-orders/detail.test.tsx src/pages/production/work-orders/ReworkPanel.test.tsx src/pages/production/schedule.test.tsx src/pages/self-service/work-orders.test.tsx
npm run build
E2E_BASE_URL=http://localhost:18080 npx playwright test -c playwright.real.config.ts e2e-real/wo-worker-assignment.spec.ts --project=real-chromium
```

Concurrency checks use separate database connections/processes on disposable seeded data. Verify distinct employee claims and machine/mold claims, not two stale model instances in one transaction. Never run database-mutating suites concurrently against the same test database.

## Acceptance matrix

| Condition | Required result |
| --- | --- |
| Machine running now; future slot separate | Proposal/confirmation permitted; current execution cannot be taken over |
| Future Confirmed WO using same mold | Does not count as physical owner now |
| Same employee, two separate slots | Both crews allowed; recorder scoped per WO |
| Same employee, overlapping slots or runtime overrun | Apply/start blocked with conflicting WO and time |
| Non-Production or inactive employee | Picker excludes and backend rejects |
| Paused WO | Retains machine/mold until explicit authorized release |
| Completed production waiting for QC | Equipment reservation released; QC/closure remain required |
| Multiple NCRs on one WO | Exact repair owner prevents cross-release |
| Different mold rate/setup | New duration validated; no old short slot retained |
| Reordered proposal | Mold, workers, due date, dependencies and daily capacity revalidated atomically |
| Pending commitments from earlier planner run | Included once in outstanding mold-life budget |
| Resource/shift/department changes after preview | Apply/start rechecks; stale or invalid proposal rejected |
| Missing schedule, shift, counter certainty or minimum staff | Actionable blocker; no fabricated successful assignment |
| Legacy records | Dry report and reviewed repair; historical output/counters unchanged |
| Expanded staffing form | Moved into modal; worker names/recorder stay visible |
| Lost response or duplicate apply/output | Resolve exact saved action; no duplicate crew/output/material effects |

## Execution ledger

Implementation is present across the seven tasks. Focused verification and rollout evidence is recorded in [the test report](../../testing/schedule-aware-assignment-20261005.md). Checklist items above remain the original verification recipe; they are not a claim that every optional scenario was run. Final verification passed 128 backend tests with 460 assertions, 27 frontend tests, a production build, a real role-based headless scenario and a separate-process crew race. See the test report for commands, results and limits.
