# Earliest Production Planning Implementation Plan

> **For agentic workers:** Use `superpowers:executing-plans` to implement this plan task by task with review checkpoints. Do not spawn subagents unless the user explicitly requests them. Steps use checkbox syntax for tracking.

**Goal:** Automatically propose the earliest feasible production time and let authorized users choose the production day before confirming a WO.

**Architecture:** Extend the existing MRP and finite-capacity services, assignment review endpoint/modal, and rescheduling flow. Persist one optional preferred day, share placement constraints between automatic/manual scheduling, and retain transactional confirmation and actual Start checks.

**Tech Stack:** Laravel 11, PHP 8.3, PostgreSQL 16, React 18, TypeScript, TanStack Query, PHPUnit, Vitest, Playwright.

**Spec:** [Earliest production planning design](../specs/2026-10-08-earliest-production-planning-design.md).

**Status:** Implemented after user approval. Verification and manual handoff are recorded in [the evidence report](../../testing/earliest-production-planning-20261008.md). Date and preference regressions were added to the existing MRP and crew suites rather than duplicating their fixtures in separate test classes. Existing safety suites supply the applicable attendance, resource, deadline, and rollback cases.

## Global constraints

- Production time uses Asia/Manila, displayed in 12-hour format.
- Number of workers is 1–4; only active Production employees with active internal Employee accounts are assignable.
- Preserve the one-day production-to-delivery buffer, exact deadline timestamps, attendance policy, 15-minute grace, controlled late-start acknowledgement, and all existing quality/material guards.
- Earliest planning applies to unstarted MRP WOs; preserve manual start constraints, saved date preferences, and approved schedules.
- Proposal refresh debounce is 300 ms. No focus, clock, or reading-time refresh.
- No new dependencies, role grants, automatic overtime, or fabricated attendance.
- Never expose raw integer IDs in API responses/URLs. Use existing HashID request resolution.
- No business writes to the live user's WO during planning or automated verification.
- Use a dedicated test database, not shared `ogami_test`. Preserve concurrent workspace edits; do not commit unrelated work.

## File map

| Responsibility | Existing files to extend |
|---|---|
| MRP reference dates and child WO dates | `api/app/Modules/MRP/Services/MrpEngineService.php` |
| Placement, ordering, resource comparison, preferred-date overrides | `api/app/Modules/MRP/Services/CapacityPlanningService.php` |
| Stored preference | `api/app/Modules/Production/Models/WorkOrder.php`; one new additive migration under `api/database/migrations/` |
| Validated proposal generation and atomic apply | `api/app/Modules/Production/Controllers/WorkOrderAssignmentController.php`, `Requests/ApplyWorkOrderAssignmentRequest.php`; one new `Requests/GenerateWorkOrderProposalRequest.php` |
| Reschedule validation/persistence | `api/app/Modules/Production/Requests/ReviewWorkOrderSlotRequest.php`, `Controllers/WorkOrderController.php`, `Services/WorkOrderService.php` |
| Material/setup preview evidence | Existing `WorkOrderService.php`, `WorkOrderMaterialUsageService.php`, `StockLocationSummaryService.php`, and `QualityStockHoldService.php` |
| Pending operation projections within the production slot | `api/app/Modules/Production/Services/WoOperationService.php` |
| API presentation | `api/app/Modules/Production/Resources/WorkOrderResource.php`, `api/app/Modules/MRP/Resources/MrpPlanResource.php` |
| Date-aware confirmation and rescheduling | `spa/src/pages/production/work-orders/CrewPanel.tsx`, `StartProductionDialog.tsx`, `detail.tsx`, `index.tsx` |
| API/types and connected pages | `spa/src/api/production/workOrders.ts`, `spa/src/types/production-crew.ts`, `spa/src/types/production.ts`, `spa/src/pages/mrp/plans/detail.tsx`, `spa/src/pages/production/schedule.tsx` |

Do not extract a generic scheduling framework. Read the current versions of these files again at execution time because the shared workspace contains concurrent edits.

## Task 1: Correct MRP date semantics and add a saved preference

**Files:** MRP engine, WO model/resources, one new migration; `api/tests/Feature/MRP/MrpRerunSafetyTest.php`, `SubassemblyWorkOrderTest.php`, with date regressions added to the existing suites.

**Interfaces:** Nullable `preferred_production_date` stored as a date. Omitted request field preserves the current value; explicit null clears it. New MRP `planned_start` is the run-time planning reference. Manual `planned_start` remains a user lower bound.

- [x] Read current migrations, model casts, MRP rerun reconciliation, and API consumers before editing.
- [x] Add frozen-clock regressions for an October 20 delivery with October 8 planning: root planning reference is October 8, production deadline remains October 19 at 12:00 AM; requested/current delivery dates remain unchanged.
- [x] Add child-tree cases proving no child receives an expired start/deadline and repeated MRP runs preserve WO IDs and the saved preference. Confirmed/manual WOs retain their dates.
- [x] Run the new tests and confirm they fail for the existing delivery-minus-two-days behavior.
- [x] Add `api/database/migrations/2026_10_08_120000_add_preferred_production_date_to_work_orders.php` with one nullable date column and cast; this name was unused when planning. Recheck before implementation, and advance the timestamp if another task has taken it. Do not backfill speculative preferences.
- [x] Replace root start calculation and recursive child date subtraction. All new tree nodes inherit the root production deadline and a non-past planning reference; dependency order owns their proposed start order.
- [x] Expose the preference additively in WO and MRP plan resources and types. Document generated versus manual date meaning.
- [x] Run focused MRP/date tests; inspect rerun branches for lost preferences or accidental edits to progressed WOs.

Illustrative regression using existing MRP test fixtures:

```php
Carbon::setTestNow(Carbon::parse('2026-10-08 10:00:00', 'Asia/Manila'));
$so = $this->createConfirmedSo(lineQty: 10, daysAhead: 12);
$this->postJson('/api/v1/mrp/runs')->assertAccepted();
$wo = WorkOrder::where('sales_order_id', $so->id)->whereNull('parent_wo_id')->firstOrFail();
$this->assertSame('2026-10-08 10:00:00', $wo->planned_start->toDateTimeString());
$this->assertSame('2026-10-19 00:00:00', $wo->planned_end->toDateTimeString());
```

Use `createBom`, `setOnHand`, and `ppcActor` from the current `MrpRerunSafetyTest` fixture before this assertion; do not weaken setup or authorization to make it pass. Restore the frozen clock in teardown.

## Task 2: Make placement actually choose the earliest feasible window

**Files:** Capacity planner and `api/tests/Feature/MRP/CapacityPlanningServiceTest.php`.

**Interfaces:** Extend `run(?array $workOrderIds = null, array $optionsByWorkOrder = []): array`. Internal options keyed by decoded WO ID contain `preferred_production_date` and validated `minimum_workers` for the current proposal. Existing callers with one argument remain compatible. Placement uses overrides when present, otherwise stored preference and current staffing limits.

- [x] Add a legacy MRP-WO test with October 18 `planned_start` that schedules earlier on October 8 when no saved preference exists. Pair it with a manual WO test that still honors October 18.
- [x] Add cases for preferred day available, unavailable with a clearly reported later alternative, past/expired deadline, exact deadline equality, and insufficient full-window crew for requested counts 1 versus 4.
- [x] Add a two-machine case where the smallest machine is busy until tomorrow but a compatible larger machine is free today. Add an earlier-gap case hidden by the current machine cursor.
- [x] Add dependency cases for child before parent, multiple descendants, child failures, and a scoped parent request whose children were omitted. Add equal-priority orders with different deadlines.
- [x] Run the placement regressions to prove the current first-pair/cursor/reference behavior fails them.
- [x] Compute the lower bound from now, saved/requested preferred day, manual lower bound where applicable, and actual dependency readiness. Keep original MRP reference dates as history; do not mass-update legacy WOs.
- [x] Evaluate candidate slots for all compatible equipment pairs. Choose earliest start, then earliest end, then tonnage and stable IDs. Keep occupied-window and daily-capacity checks; eliminate the cursor as a restriction on usable earlier gaps.
- [x] Pass the requested worker count into eligibility and staffing suggestion. Preserve lock order and database overlap constraints. Never treat availability checks as named crew reservations.
- [x] Preserve dependency scheduling and current child good-output confirmation guards. Scope a parent request to required descendants or explicitly block missing dependency scheduling.
- [x] Keep failed-replan preservation and clear diagnostics. Do not supersede a valid old reservation until a replacement is selected successfully. Preserve the minimum two-hour reservation or longer rate-based estimate with its existing buffer.
- [x] Run focused placement/dependency tests and review every `run`, `reorder`, `reassign`, and reschedule caller for policy drift.

Comparator intent, within the existing planner:

```php
$candidateKey = [$slot['start']->getTimestamp(), $slot['end']->getTimestamp(),
    $machine->tonnage ?? PHP_INT_MAX, $machine->id, $mold->id];
// Retain the candidate with the lowest key after all guards pass.
```

## Task 3: Add validated day-aware proposal and atomic confirmation

**Files:** Assignment controller, new proposal request, apply request, WO service, existing crew tests with preferred-day regressions added there.

**Interfaces:** `POST /production/work-orders/{id}/assignment-suggestion` accepts staffing limits plus optional `preferred_production_date`; null means earliest. `assignment-apply` accepts the reviewed preference and existing schedule/crew versions. Proposal response adds `preferred_production_date`, optional `preferred_day_message`, and structured `readiness_blockers` entries with `code`, `message`, and `owner`. Old clients may omit all new fields.

- [x] Add HTTP validation for date format, today through 90 days, exact production-deadline fit, Planned-only proposal generation, row scope, and actors lacking confirm authority.
- [x] Add tests that day/count overrides reach placement, read endpoints remain pure, cancellation does not save a preference, and repeated apply requests do not duplicate crews or material reservations.
- [x] Add stale schedule/crew, changed attendance/leave, archived equipment, invalid recorder, material shortage, and overlapping crew confirmation cases. Assert complete rollback of preference, status, crew, and material changes.
- [x] Introduce the FormRequest using existing HashID and permission conventions; validate new fields rather than reading an unvalidated arbitrary date from `Request`.
- [x] Generate proposals through the extended scoped planner without saving the WO preference. Include worker-count overrides in the actual equipment search, not only subsequent staffing.
- [x] Save the reviewed preference only in successful assignment/confirmation. Lock and recheck the exact pending proposal and crew versions; do not regenerate inside apply and silently confirm a different slot.
- [x] Reuse existing stock/quality availability to return concise preview blockers for unavailable material or incomplete setup. Keep current atomic reservation and issue services as the final authority.
- [x] Distinguish stock physically usable now from open POs, incoming QC, held/expired lots, and other WO reservations. Keep subassembly proposal versus actual readiness separate.
- [x] Run focused HTTP, crew, material-handoff, and rollback tests. Review the legacy scheduler confirmation API for an equivalent no-bypass guard; do not restore schedule-page confirmation.

Example proposal request:

```json
{
  "minimum_workers": 2,
  "maximum_workers": 2,
  "preferred_production_date": "2026-10-08"
}
```

The apply request includes the same preference together with the existing HashID schedule, timestamp, selected employee IDs, recorder, and expected crew ID. An explicit later alternative is confirmed only after the user sees its actual date.

## Task 4: Put production-day choice in Confirm and simplify the workflow

**Files:** `CrewPanel.tsx`, `workOrders.ts`, production types, `CrewPanel.test.tsx`; extend existing modal rather than adding a new page.

**Interfaces:** `generateAssignmentSuggestion` accepts the proposal request above. Confirmation state includes `preferred_production_date`, mode, worker count, and the currently reviewed proposal identity. Use the existing `scheduleDate` and `productionDateTime` helpers.

- [x] Add tests showing date selection before confirmation, earliest default, saved preference restoration, day/count changes causing one debounced generation, and unchanged controls causing no request while reading/focusing.
- [x] Add out-of-order response, failed request/retry, no-capacity alternative, invalid date, manual crew invalidation, and stale-context tests. Confirmation must be disabled until a current complete proposal exists.
- [x] Add `Earliest available` / `Choose day` controls above worker selection. Show deadline and delivery references, then equipment/crew proposal and one `Confirm work order` action.
- [x] Include day and count in query keys and in-flight generation identity. Ignore stale responses. Keep prior preview visible under a status spinner with confirmation disabled.
- [x] Regenerate a pending proposal for an explicit day/count change even when a pending schedule already exists. Keep routine reads read-only. Disable focus/clock/background retriggers.
- [x] Revalidate manual workers against the new slot, retain eligible selections, explain blocked ones, and require replacement. Do not silently switch the recorder.
- [x] Preserve automatic initial reasons, red remove-worker controls, concise semantic warnings, keyboard interaction, and responsive modal scrolling.
- [x] Run focused Vitest tests, inspect the modal on desktop and narrow width, and review callback/state ownership for unnecessary resets.

Request stability test shape:

```tsx
fireEvent.change(screen.getByLabelText('Production day'), {
  target: { value: '2026-10-08' },
});
await waitFor(() => expect(workOrdersApi.generateAssignmentSuggestion)
  .toHaveBeenLastCalledWith('wo-1', expect.objectContaining({
    preferred_production_date: '2026-10-08', minimum_workers: 2,
  })));
const count = vi.mocked(workOrdersApi.generateAssignmentSuggestion).mock.calls.length;
fireEvent(window, new Event('focus'));
expect(workOrdersApi.generateAssignmentSuggestion).toHaveBeenCalledTimes(count);
```

Use controlled timers and deferred promises in the existing QueryClient fixture to prove debounce and stale-response behavior; restore timers after each test.

## Task 5: Align rescheduling, Start, and connected date displays

**Files:** Start dialog, review-slot request/controller/service, WO detail/list/resources, MRP plan resource/detail, schedule page; existing readiness, warning, reminder, and frontend tests.

**Interfaces:** Review-slot accepts the optional preference with existing exact scheduled timestamps and version checks. The preference saves only with successful explicit approval. Start consumes the approved schedule without choosing a day or mutating it silently.

- [x] Add tests for approval without Start, Start without rescheduling, future/early/15-minute-late/very-late paths, expired deadlines, and preservation of valid approved schedules after automatic scheduler runs.
- [x] Add tests for today's absence, missing attendance, upcoming shift, overnight Saturday coverage into Sunday, approved leave, and attendance changes between proposal and action.
- [x] Reuse day-choice conventions in explicit Reschedule. Save timing, preference, crew, and recorder atomically; preserve history and existing late-production controls.
- [x] Add a pending-operation timing regression: an MRP planning reference many days before the deadline must not spread two-hour production operations across those days. Extend `WoOperationService` to project pending operations from the authoritative slot and refresh them after proposal/approval changes. Update only pending timestamps; never regenerate routing/QC definitions or rewrite operations that already started. Add this case to a focused new `api/tests/Feature/Production/WoOperationScheduleProjectionTest.php`.
- [x] Keep Start concise and readiness-focused. Show recovery actions for the actual blocker; do not automatically open date controls or reintroduce Check again on the ordinary upcoming-schedule message.
- [x] Update WO/MRP date labels and 12-hour display so users see requested delivery, current delivery, production deadline, selected day, and actual proposed/approved slot distinctly.
- [x] Avoid false late-start attention cards from an MRP planning reference set to today. Use authoritative schedules for late-start notices, while preserving deadline-overdue and reminder behavior.
- [x] Preserve Production Schedule's review-only UI and links to WO confirmation. Confirm no bulk/single confirmation controls return.
- [x] Run focused frontend/readiness/warning/notification tests and inspect date copy in the connected pages.

## Task 6: Integrated verification, safe rollout, and manual test handoff

**Files:** New test evidence `docs/testing/earliest-production-planning-20261008.md`; only targeted fixtures and tests needed by the implementation.

- [x] Create a dedicated database such as `ogami_test_earliest_planning` owned by the test database role. Run migrations and suites only there.
- [x] Run focused backend suites, for example:

```bash
docker exec -e DB_DATABASE=ogami_test_earliest_planning ogami-api php artisan test \
  --filter='MrpRerunSafetyTest|SubassemblyWorkOrderTest|CapacityPlanningServiceTest|WorkOrderCrewTest|WorkOrderAttendanceSafetyTest|WorkOrderExecutionReadinessTest|WorkOrderMaterialIssueHandoffTest|WoOperationScheduleProjectionTest|WorkOrderScheduleWarningTest|WorkOrderScheduleReminderTest'
```

- [x] Run focused frontend tests, build/type checks, and lint on changed files:

```bash
cd spa
npm run test:run -- src/pages/production/work-orders/CrewPanel.test.tsx \
  src/pages/production/work-orders/StartProductionDialog.test.tsx \
  src/pages/production/work-orders/detail.test.tsx src/pages/production/schedule.test.tsx
npm run build
```

- [x] Run the Impeccable detector once on changed UI targets; inspect desktop/narrow modal and page screenshots together, fix actual findings in one batch, and do at most one confirmation pass.
- [x] Verify overlap and stale-version rejection on isolated fixtures: two WOs trying the same crew, proposal changed while the modal is open, unavailable requested count, and rejected confirmation leaving existing commitments unchanged. These are deterministic conflict tests; simultaneous multi-process load testing was not performed.
- [x] Test migration rollback on the isolated database: removing the nullable preference must preserve existing dates, WOs, crew/history, and material ledgers. Take a private schema/data snapshot before live migration; do not downgrade a deployed schema with saved preferences without preserving those values.
- [x] Apply the additive migration through the existing local deployment workflow after approval. Preview existing Planned MRP WOs under the new policy without live business writes. Already approved schedules retain their timing. Read-only alternatives were checked; generating saved live proposals is left to the user opening Confirm, in accordance with the global verification constraint.
- [x] Record exact check results, preserved behavior, limitations, and any preexisting unrelated failures. Stop after acceptance conditions pass.

### Manual test sequence

1. Open `WO-202610-0003` as an authorized Production Management or PPC user; choose Confirm.
2. Leave Earliest available selected and choose two workers. Verify the proposed date can be earlier than October 18 if resources/crew allow.
3. Choose today, then another day before the production deadline. Verify one refresh per change, 12-hour times, and eligible crew for the selected interval.
4. Try a date with no capacity: read the reason and approve a later alternative only deliberately. Try past dates and an interval beyond the exact deadline: confirmation stays blocked.
5. Switch to Manual assign and try a worker absent on the selected day; assignment remains blocked. Missing attendance must show its separate state.
6. Confirm only when ready for this business test. Verify saved preference, approved schedule, crew, and material reservations. No actual production has begun.
7. Click Start. Verify readiness and conditional presence/late-start controls; completing Start records actual time and issues materials once.
8. Run the scheduler again. Verify the confirmed WO keeps its approved schedule; future Planned WOs can use earliest remaining capacity.

## Risks and completion boundary

Earlier production holds finished stock longer and may reserve material earlier. Preserve priority, safety-stock policy, and explicit confirmation; do not automatically begin every WO. A proposed slot is not a guarantee of future attendance, material arrival, or completed QC. The current one-day buffer is preserved but does not guarantee enough time for every QC/replacement/delivery case.

The implementation finishes only after all six tasks and their relevant checks pass. This plan does not change purchase deadlines, add scheduling strategy settings, solve global factory optimization, rewrite attendance, or start live WOs. Review each task's connected code before moving to the next; do not leave an earlier broken task behind to begin another process.
