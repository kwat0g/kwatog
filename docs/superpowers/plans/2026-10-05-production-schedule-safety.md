# Production Schedule Safety Implementation Plan

> **For agentic workers:** Use `superpowers:executing-plans` to implement this plan task by task. Work inline by default. Never deploy more than two subagents. The user's requested models are GPT-5.6-Luna and GPT-6.1-Sol; GPT-5.6-Luna is not currently exposed by the collaboration tool, so do not silently substitute another model. Steps use checkboxes for tracking. Do not commit or deploy as part of writing this plan.

**Goal:** Make every production approval and execution path respect an eligible crew, realistic remaining time, and authoritative schedules, while giving users clear recovery actions.

**Architecture:** Keep orchestration in `CapacityPlanningService` and `WorkOrderService`; keep worker eligibility in `WorkOrderAssignmentService` and resource timing/ownership in `ProductionResourceService`. Reuse the existing assignment review, crew history, Attendance records, HR shift assignments, and operational alert sweep. Add only the missing calendar field and one current scheduling-diagnostic field; do not build a new scheduling engine or attendance subsystem.

**Tech Stack:** Laravel/PHP, PostgreSQL, React/TypeScript, TanStack Query, PHPUnit, Vitest, and headless Playwright.

**Spec:** [Production scheduling audit](../../testing/production-schedule-audit-20261005.md). This plan resolves its 12 findings; policy decisions below form the implementation specification.

## Global constraints

- Production workers must be active HR employees in active department `PROD`, with active `employee`-role accounts. Managers remain excluded.
- Crew size remains 1–4 with exactly one output recorder. Preserve automatic and manual modes in initial confirmation; Change workers stays a simple editor.
- Run Scheduler saves proposals, not crews, WO confirmations, or stock movements.
- All confirmation routes enforce the same crew requirement. A frontend-disabled button is not a server safeguard.
- Keep schedule and crew version checks, transactional stock reservations, and exact equipment ownership.
- Use one lock order for mutations: affected WOs in ID order, machines, molds, employees, then schedules/crews. Recheck mutable evidence after acquiring locks; concurrent HR or attendance changes must either participate in employee locking or be detected before commit.
- Never automatically unconfirm an existing WO, move a confirmed plan, extend a customer delivery date, fabricate attendance, or release occupied equipment.
- A running WO may complete after its expected finish. Do not block completion or remove recorder access merely because its planning window expired.
- Missing imported attendance is unknown, not proof of absence. Explicit absence, approved leave, and a closed attendance interval are not overridable through a production checkbox.
- Store and validate timestamps with offsets; display the application timezone. Night-shift work dates can be the preceding calendar date.
- No new dependencies, optimization engine, rotating-roster engine, automatic payroll overtime approval, or permanent permission grants.
- Preserve unrelated workspace edits. Database tests run serially against the disposable test database; browser fixtures use the isolated audit application.

## Intended user flow

1. PPC runs the scheduler. Each proposed slot must have compatible equipment and enough eligible workers covering the interval. Workers are suggested when reviewed, not assigned when the proposal is saved.
2. On Schedule list, an unstaffed proposal offers **Review and assign**. This opens the existing WO confirmation review with the exact selected schedule. User chooses the worker count or manual crew, reviews one recorder, and applies crew plus confirmation atomically.
3. Bulk confirmation remains available only for staffed proposals with no approval blockers. Confirmation checks exact schedule and crew versions again on the server.
4. At Start or Resume, Production Management sees execution readiness. The remaining job must fit the approved time and the crew must cover the remaining interval. Known absent or off-duty workers must be replaced.
5. When attendance has not been imported, show **Confirm these workers are present** only for the unknown workers. Record this attestation with actor, crew, schedule, timestamp, and employee IDs. It authorizes that action only and does not create attendance or override known absence.
6. Change workers retains one recorder and a handover reason during live production. Review production slot handles a delayed schedule. If a replacement time also needs a new crew, review both together and save atomically; do not force the user through mutually blocking forms.
7. Scheduling failures show one concise explanation and next action per WO. A green result is reserved for runs with no conflicts; partial results use warning styling.

## Policy decisions

| Situation | Planned behavior |
|---|---|
| Future crew selection | Check department/account, assigned working days and hours, approved leave, explicit off-duty evidence, and other WO commitments; do not require future attendance. |
| Recorded absence or leave | Exclude from selection and block execution. HR corrects incorrect attendance/leave; production cannot override it. |
| Recorded time-in without time-out | Accept only when the actual timestamp is at or before the action and the correct work-date/shift interval matches. |
| Recorded time-out at or before the action | Worker is off duty; replace worker or correct the attendance record through HR. |
| No reliable attendance for the shift | Production Management must confirm physical presence for the current action. Never silently assume present. |
| Payroll Sunday/rest-day label | Do not treat the payroll multiplier as an authoritative production roster. Actual assigned working days determine ordinary coverage. A completed time-out still blocks current presence. |
| HR shift calendar not configured | Show a setup blocker for new planning/approval. Preserve existing execution and history. HR explicitly configures the calendar; no guessed weekday backfill. |
| Holiday | Treat as non-working for automatic planning. Holiday production requires a reviewed slot and explicit manager authorization naming the interval and holiday, within an HR-assigned working interval. This does not approve payroll overtime. |
| Job crossing midnight | Allow one continuous job covered by an assigned night shift, split equipment-capacity accounting by calendar day. No multi-shift job splitting. |
| Start delayed beyond available duration | Block Start with required minutes and available minutes; offer Review production slot. Use the existing setup/run estimate without inventing a grace period. |
| Resume | Estimate remaining pieces with the existing duration calculation. Conservative setup time remains unless the repository already records a reliable completed-setup state. |
| Already executing beyond scheduled end | Retain ownership and output recording; raise review/delivery-risk alerts. Never automatically release or stop production. |

## File ownership

| Files | Responsibility |
|---|---|
| `api/app/Modules/Production/Services/WorkOrderAssignmentService.php` | Shared crew validation, shift interval resolution, attendance and live handover eligibility. |
| `api/app/Modules/Production/Services/ProductionResourceService.php` | Remaining-time fit, reviewed execution interval, machine/mold reservations. |
| `api/app/Modules/Production/Services/WorkOrderService.php` | Atomic approval/start/resume and preserving lifecycle/material behavior. |
| `api/app/Modules/Production/Controllers/WorkOrderAssignmentController.php`, `WorkOrderController.php`, and existing Requests | Validated reviewed versions, presence attestations, combined timing/crew recovery, and permission checks. |
| `api/app/Modules/MRP/Services/CapacityPlanningService.php` | Worker-aware slot search, approval checks, rerun/reorder safety, schedule readiness and current diagnostics. |
| `api/app/Modules/MRP/Requests/ConfirmScheduleRequest.php`, `Controllers/SchedulerController.php` | Batch approval payload and exact reviewed versions. |
| `api/app/Modules/Attendance/Models/EmployeeShiftAssignment.php`, `Services/ShiftAssignmentService.php`, `Requests/AssignEmployeeShiftRequest.php`, `Requests/BulkAssignShiftRequest.php` | Date-effective working-day calendar. |
| `spa/src/pages/production/schedule.tsx`, `work-orders/CrewPanel.tsx`, `work-orders/detail.tsx` | Consistent approval, worker changes, execution readiness and actionable failures. |
| `spa/src/pages/attendance/shifts/assign.tsx`, `spa/src/api/attendance/shifts.ts`, `spa/src/types/attendance.ts` | Working-day selection on existing HR assignment form. |
| `spa/src/api/mrp/scheduler.ts`, `spa/src/types/mrp.ts`, `spa/src/api/production/workOrders.ts`, `spa/src/types/production-crew.ts` | Reviewed payloads and readiness types. |
| `api/app/Common/Services/AlertEngineService.php`, `scripts/wo-resource-readiness.php` | Existing operational alerts and read-only recovery report. |

## Task 1: Close approval bypasses and unify review

**Audit coverage:** findings 1, 7, 8, and approval portion of 11.

**Files:** production assignment/resource/work-order services and controllers; MRP confirmation request/controller/service; schedule page and APIs; existing `WorkOrderCrewTest`, `CapacityPlanningServiceTest`, `schedule.test.tsx`, and `CrewPanel.test.tsx`.

**Interfaces:** Add `assertReadyForConfirmation(WorkOrder $wo, ProductionSchedule $slot): WorkOrderCrew` to the existing assignment service. It validates exact current membership, count bounds, employee accounts, one recorder, HR coverage and conflicts without requiring attendance. Keep start-specific checks separate.

- [ ] Add a failing regression that recreates `test_confirm_promotes_the_work_order_and_schedule_as_one_process` without a crew and expects rejection with WO, schedule and material reservations unchanged. Change the successful existing test to assign an eligible crew explicitly.
- [ ] Add tests for direct WO confirmation without crew, invalid recorder, expired slot, stale crew, and stale schedule. Validate complete batch rollback when its second row fails.
- [ ] Call the shared confirmation guard inside `WorkOrderService::confirm` before status/material mutation. Validate `scheduled_end > now()` and remaining-time fit for a slot already started in clock time.
- [ ] Replace the ID-only batch request with entries containing the reviewed schedule and crew versions. Decode hash IDs through the existing request trait and require distinct schedule IDs. Verify each entry belongs to the same WO before mutation.

```json
{
  "schedules": [{
    "schedule_id": "hashed-schedule-id",
    "schedule_updated_at": "2026-10-11T03:00:00.000000+08:00",
    "expected_crew_id": "hashed-crew-id"
  }]
}
```

- [ ] Expose version timestamps at database precision, not second-truncated comparisons. If `updated_at` does not change reliably in a schedule-writing path, fix that path; do not add a generic versioning framework.
- [ ] Add approval readiness to the snapshot. Disable bulk selection for unstaffed or blocked rows, show the reason and **Review and assign**, and pass the selected slot/version into the existing `AssignmentReview`. Guard against it generating or silently switching to another proposal.
- [ ] Align permission behavior with the current assignment endpoint: an actor applying a new crew needs crew-assignment permission plus WO/schedule confirmation permission. Bulk approval of an existing valid crew requires schedule confirmation. Do not grant permissions merely to make tests pass.
- [ ] Run the focused approval tests and inspect existing direct-confirm callers. Stop when all public confirmation paths enforce crew readiness and the single-row review can assign and confirm atomically.

**Regression assertion:** after rejected approval, `status === planned`, schedule remains pending, crew history is unchanged, and no new reserved stock exists.

```php
// Add this service-level regression using the existing planner test helpers.
public function test_confirmation_without_crew_preserves_pending_state(): void
{
    $machine = $this->machine('CREW-GUARD-M', 100);
    $mold = $this->mold('CREW-GUARD-D');
    $mold->compatibleMachines()->sync([$machine->id]);
    $wo = $this->workOrder('CREW-GUARD-WO', WorkOrderStatus::Planned, '100', 1);
    $this->planner->run([$wo->id]);
    $slot = ProductionSchedule::where('work_order_id', $wo->id)->firstOrFail();
    try {
        $this->planner->confirm([$slot->id], $this->user->id);
        $this->fail('Confirmation without a crew must be rejected.');
    } catch (BusinessRuleException $error) {
        $this->assertStringContainsString('crew', strtolower($error->getMessage()));
    }
    $this->assertSame(WorkOrderStatus::Planned, $wo->fresh()->status);
    $this->assertSame(ProductionScheduleStatus::Pending, $slot->fresh()->status);
    $this->assertSame(0, $wo->crews()->count());
}
```

## Task 2: Model actual working days on HR shift assignments

**Audit coverage:** calendar foundation for findings 2, 5, 6, and 10.

**Files:** existing attendance assignment model/service/requests and HR assignment form; add one migration `api/database/migrations/2026_10_05_000010_add_working_days_to_employee_shift_assignments.php`; focused shift-assignment tests.

**Interface:** Nullable `working_days` JSON array on `EmployeeShiftAssignment`, using ISO weekdays 1–7. Null means calendar not configured; an explicit array is validated and preserved with the date-effective assignment. No rotating patterns.

- [ ] Add regression examples for Monday–Friday, Sunday night working, an explicit Sunday rest day, and a null legacy calendar. Validate unique integers 1–7 and reject an empty working-week assignment.
- [ ] Add the nullable field without backfilling fabricated calendars. Migration rollback removes only this field and must not rewrite assignment dates or attendance.
- [ ] Extend individual and bulk HR shift assignment requests/services to require working days for new assignments. Existing historical assignments remain readable. Provide an HR-authorized way to configure days on an existing assignment while preserving the change in audit history.
- [ ] Add weekday selection to the existing shift assignment page, with the same choices for bulk assignment. Do not add a separate calendar page. Show null calendars as **Working days not configured**.
- [ ] Expose one shared date-effective shift interval resolver in `WorkOrderAssignmentService`. It resolves the assignment, working day, hours, and preceding night-shift work date. Reuse it for suggestions, live checks and attendance matching.

```php
// Planned method contract within WorkOrderAssignmentService:
public function shiftInterval(Employee $employee, Carbon $at): ?array;
// ['start' => Carbon, 'end' => Carbon, 'work_date' => 'YYYY-MM-DD', 'shift_id' => int]
```

- [ ] Keep payroll's existing Sunday/rest-day calculations unchanged. An eight-hour shift is not evidence of a seven-day workweek.
- [ ] Run shift-assignment and night-shift tests. Produce a read-only list of existing Production assignments missing calendar configuration; do not silently choose their days.

## Task 3: Add attendance-aware execution and safe handovers

**Audit coverage:** findings 5 and 6, plus execution portion of 11.

**Files:** assignment service, existing start/resume/crew requests and controllers, WO detail/crew forms and API types; existing Attendance and crew tests. Reuse existing audit logging and crew history.

**Interfaces:** Add `presence(Employee $employee, Carbon $at): array` returning `state` (`present`, `unknown`, `blocked`), `reason`, and `work_date`. Add a validated `presence_confirmed_employee_ids` list to execution and live handover payloads. It applies to the current reviewed action only.

- [ ] Write cases for no import, recorded absent, approved leave, matching open time-in, future time-in, completed time-out, night shift on the preceding work date, and a payroll rest-day label on a genuinely working shift.
- [ ] At execution, reject explicit `absent`/`on_leave`, approved leave, an HR non-working day, and time-out at/before the action. Do not permit a production attestation to override these states.
- [ ] Treat missing, incomplete or contradictory imported data as unknown. Accept it only when an authorized Production Management actor confirms presence for exactly the unknown members of the current reviewed crew. Audit IDs and timestamps, not sensitive leave details.
- [ ] Recheck attendance in the same transaction as execution or handover. Reject a stale crew and any submitted IDs outside it. Attestation is not reusable after a crew change or for another WO/action.
- [ ] For handover, check proposed workers against the remaining interval beginning at now or the reviewed future start, rather than the elapsed original interval. Include active accounts, working days, leave and conflicting runtime responsibilities. Keep one recorder and a required live handover reason.
- [ ] If attendance changes while production is running, flag the crew through the existing sweep and require manager review. Do not auto-stop equipment or auto-remove the recorder; protect physical continuity and recorded output history.
- [ ] Add a conditional presence checkbox to Start/Resume and live Change workers only when needed. Known blocks show **Change workers** or **Ask HR to correct attendance**. No extra field on ordinary future assignment.
- [ ] Test forged attestations from PPC, workers and outsiders, and retained original-actor output replay after handover.
- [ ] Verify permitted HR attendance/leave corrections coordinate with employee locking or trigger a fresh eligibility read. Do not let an assignment or execution commit against an already changed record.

## Task 4: Validate remaining time and provide recoverable rescheduling

**Audit coverage:** finding 4 and execution recovery.

**Files:** `ProductionResourceService`, `WorkOrderService`, existing reviewed-slot/crew controllers and requests, `CrewPanel.tsx`, `detail.tsx`; existing resource/start-resume tests.

- [ ] Add a deterministic test with a 95-minute estimate and a 03:06–04:41 slot. At 04:40, Start must fail with **95 minutes required; 1 minute available**. At the planned start, equivalent checks pass; freeze time in tests so a few milliseconds do not make the boundary flaky.
- [ ] Evaluate fit as `max(now(), slot.start) + duration(remaining quantity) <= slot.end`. Apply it to Start and Resume, with consistent timestamp precision. Do not apply this guard to recording output or completing an already executing job.
- [ ] Display expected finish and reason on blocked execution. Preserve actual start/output and existing machine ownership during scheduling review.
- [ ] Support a conditional **Workers for this slot** section inside the existing Review production slot modal when the current crew cannot cover the proposed time. Reuse worker fields, and require a recorder; keep this section hidden for ordinary timing changes with valid crew.
- [ ] Validate replacement crew and timing against the proposed interval, then save schedule and crew in one transaction using expected schedule and crew versions. Neither an invalid crew nor an invalid slot may be saved first. This prevents the crew-change/reschedule deadlock.
- [ ] Test rollback for one missing worker, overlap, approved leave, stale versions, and material history. Verify completion after overrun remains possible and takeover stays blocked until actual release.

## Task 5: Make proposal generation respect workers and overnight intervals

**Audit coverage:** findings 2, 3, 9 and 10.

**Files:** capacity planner and assignment/resource services; existing `CapacityPlanningServiceTest`, `ProductionResourceReservationTest`, and `WorkOrderCrewTest`.

- [ ] Add tests proving equipment-free time without enough workers is rejected or moved to the next covered interval; exact count 1–4 is honored; assigned crews survive a rerun only when they cover the replacement; priority changes respect individual earliest starts.
- [ ] Generate candidates from the existing compatible-resource windows plus HR shift starts, ends and reservation ends. Bound the search by the WO deadline and current 90-day planning horizon. Reuse eligible-worker checks; do not scan every minute or invent a scoring engine.
- [ ] For each candidate, validate equipment and daily capacity, then require enough eligible workers for the whole slot. If a current crew exists, require that crew to cover the candidate before saving a replacement. Preserve the old proposal when no valid replacement exists.
- [ ] Explain that unassigned proposals are not worker reservations. Two equipment proposals may rely on the same available pool; actual assignment/confirmation locks employees and resolves competition. Recheck at approval, and report shortages rather than promising capacity based only on a preview.
- [ ] Clamp each reordered WO to `max(shared cursor, wo.planned_start, now())`; preserve deadlines, dependencies and mold reservations.
- [ ] Remove the blanket cross-midnight rejection for a continuous night-shift job. Use existing per-day overlap accounting to enforce machine capacity on both dates. Reject jobs requiring a shift split with **Job exceeds one continuous covered shift**.
- [ ] Automatic proposals skip holidays. For explicitly reviewed holiday production, validate the interval, assigned workers and manager authorization; record authorization on the schedule audit entry. Do not infer authorization from a generic payroll overtime-hours record because that record contains no exact interval.
- [ ] Test that reruns do not affect confirmed/executed schedules, that night-date attendance matches, and that changing HR coverage is caught again on approval.

## Task 6: Make conflicts concise and recoverable after reload

**Audit coverage:** findings 11 and 12.

**Files:** capacity planner/readiness, Schedule model, one additive migration `api/database/migrations/2026_10_05_000011_add_work_order_scheduling_diagnostic.php`, WorkOrder model, scheduler types/page, WO detail, alert sweep.

**Interface:** Nullable `scheduling_diagnostic` JSON on WorkOrder contains only the latest failed proposal result: checked timestamp, distinct codes/messages, and next action. Clear it on successful placement, approved replacement or cancellation. No new scheduler-run table.

- [ ] Add tests for repeated identical capacity errors, an already-expired WO deadline, a mold maintenance limit, missing working days, partial scheduling success and reload after failure.
- [ ] Check expired planning deadlines before trying equipment pairs. Return one code such as `planning_deadline_expired` and **Review WO deadline and delivery impact**. Preserve real mold-life reasons instead of replacing them with only `no_mold_with_capacity`.
- [ ] Deduplicate candidate messages before storing/returning them. Show one summary per WO; expose distinct machine/mold details only when useful. Avoid one paragraph containing repeated sentences.
- [ ] Store latest failed results in the existing run transaction. Snapshot returns unresolved diagnostics independently of visible scheduled rows, so a WO with no slot remains discoverable. Include `checked_at`; do not present old diagnostics as a freshly checked result.
- [ ] Reuse the shared non-mutating approval/execution readiness in snapshot and alerts. Return separate approval and execution blockers, current staffing, recorder validity and action labels. Do not label a future slot executable now.
- [ ] Use warning styling for partial success and error styling when nothing was proposed. Keep **Run scheduler evaluates all planned WOs; filters only change this view** visible in the confirmation dialog.
- [ ] Preserve no-auto-change recovery for expired deadlines. Let PPC review internal WO dates through the existing authorized edit path; warn Sales about delivery impact and keep the customer's agreed date unchanged.
- [ ] Update backend error copy from **Manage assignment** to **Review production slot** or **Change workers**, matching the actual recovery.
- [ ] Use existing alert deduplication for invalid confirmed commitments and overdue execution. Do not repeatedly notify people on an unchanged failed rerun or during read-only snapshot requests.

## Task 7: Inspect existing records and apply compatibility-safe rollout

**Files:** `scripts/wo-resource-readiness.php`, migrations above, audit documentation.

- [ ] Extend the read-only report to show confirmed WOs without crews, expired slots, invalid recorder accounts, missing working-day calendars, and invalid assigned coverage. Include WO-202610-0002 explicitly in the current development report.
- [ ] Preview additive migrations and new guards in the isolated application. Preserve existing schedules, crews, stock movements, output, passwords and payroll records.
- [ ] HR configures actual working days before worker-aware planning is enabled for new commitments. Report missing setup clearly; never assume all employees work every day.
- [ ] Existing confirmed WOs without crews remain confirmed but cannot start until Assign workers establishes valid coverage. Existing active jobs keep ownership/output and show manager review requirements.
- [ ] Ship API payload and frontend approval changes together. Legacy ID-only approval requests fail validation with clear updated-client guidance; do not retain an unsafe compatibility bypass.
- [ ] Record a rollback procedure: restore application changes only while preventing new approvals, preserve diagnostic/calendar data until no caller relies on it, and leave business transitions untouched. Do not roll back data by deleting crews or stock reservations.

## Task 8: Prove the complete role-based paths and stop

**Files:** focused PHPUnit/Vitest suites; `scripts/wo-crew-e2e-fixture.php`, `spa/e2e-real/wo-worker-assignment.spec.ts`, and existing QC connection scenario. Add attendance and working-day fixtures only in the disposable environment.

- [ ] Test single-row Schedule list confirmation: no crew initially, review assigns workers, exact slot is confirmed, one recorder and material reservations exist.
- [ ] Test batch confirmation: valid staffed proposals pass; missing crew, stale schedule, stale crew, off-duty or insufficient staffing blocks without partial mutations.
- [ ] Test a 03:06 night shift, attendance on the preceding work date, explicit absence, missing-import presence attestation, late-start rejection, reviewed timing/crew replacement, live recorder handover, and rejected equipment takeover.
- [ ] Test concurrent approvals/assignments for the same employees with overlapping schedules: one commits, one rejects; adjacent intervals both pass. Run the existing separate-process concurrency helper.
- [ ] Use separate PPC, Production Manager, QC, recorder, supporting employee and outsider accounts. Confirm workers/PPC cannot attest managerial presence or approve unauthorized schedule changes.
- [ ] Inspect desktop and mobile once: worker count/manual controls, disabled workers with reasons, concise failed-run panel, relevant conditional presence field, and the existing far-right Change workers action. Fix demonstrated defects in one batch, then confirm once.
- [ ] Run targeted checks below, record exact results, and stop after acceptance passes. Do not claim full Sales Order-to-payment certification.

```bash
# Backend: disposable test database; do not run database suites concurrently.
docker exec ogami-api php -d memory_limit=-1 vendor/bin/phpunit -c phpunit.pricing.20261004.xml --filter 'WorkOrderCrewTest|CapacityPlanningServiceTest|ProductionResourceReservationTest|ProductionResourceChangeTest|WorkOrderStartResumeOccupancyTest|WorkOrderInlineReworkTest|WorkOrderMaterialIssueHandoffTest|WorkOrderCloseQualityGateTest'

# Frontend: run from spa/.
npx vitest run src/pages/production/schedule.test.tsx src/pages/production/work-orders/CrewPanel.test.tsx src/pages/production/work-orders/detail.test.tsx src/pages/production/work-orders/ReworkPanel.test.tsx
npx eslint src/pages/production/schedule.tsx src/pages/production/work-orders/CrewPanel.tsx src/pages/production/work-orders/detail.tsx
npm run build

# Browser: run fixture only against isolated audit application, then from spa/.
docker exec ogami-audit-20261002-api php scripts/wo-crew-e2e-fixture.php
E2E_BASE_URL=http://localhost:18080 npx playwright test -c playwright.real.config.ts --project=real-chromium wo-worker-assignment.spec.ts

# Final whitespace gate.
git diff --check
```

## Acceptance and audit coverage

| Audit finding | Task | Required outcome |
|---|---|---|
| 1. Crew confirmation bypass | 1 | Every confirmation endpoint requires a valid crew. |
| 2. Equipment-only planning | 2, 5 | Proposed time has enough eligible workers with configured coverage. |
| 3. Unsafe reruns | 5 | Invalid replacement leaves prior pending proposal intact. |
| 4. Late-start fit | 4 | Remaining duration fits before Start/Resume. |
| 5. Absence/rest-day checks | 2, 3 | Explicit off-duty evidence blocks; unknown imported data requires audited presence confirmation. |
| 6. Live handover exception | 3, 4 | Replacement crew covers the remaining interval and presence policy. |
| 7. Expired approval | 1 | Expired slot cannot be confirmed. |
| 8. Stale approval | 1 | Exact reviewed schedule and crew versions are checked under locks. |
| 9. Earliest start in reorder | 5 | New start respects each WO's planned earliest start. |
| 10. Calendar limitations | 2, 5 | Configured working days, continuous night shifts and explicit holiday-review policy. |
| 11. Weak readiness | 1, 3, 6 | Display and mutation checks use consistent phase-specific rules. |
| 12. Noisy/transient failures | 6 | Distinct actionable diagnostics survive page reload. |

Plan complete when each finding has a tested behavior and recovery path. Implementation complete only after all required gates pass; this document alone does not authorize claiming the fixes have shipped.

## Plan review

- [x] Every audit finding maps to implementation and a recovery path.
- [x] No new permanent employee permission or automatic equipment release is planned.
- [x] Attendance import delay and Sunday payroll labeling are accounted for.
- [x] Timing and worker replacement can be reviewed atomically, avoiding mutually blocking edits.
- [x] Existing dirty-workspace changes, business records and isolated test databases are protected.
- [x] Two-subagent ceiling respected. Both requested models launched successfully for final rollout review; no substitute was used.

## Implementation checkpoints

Detailed evidence and compatibility limits are recorded in [Production schedule safety verification](../../testing/production-schedule-safety-20261005.md). The individual instructions above describe the intended checks; these checkpoints record the verified task outcomes.

- [x] Shared crew approval guard and reviewed schedule/crew versions applied across confirmation paths. Final user steering removes Schedule-page confirmation; WO record retains assignment review.
- [x] Explicit working-day calendar and authorized HR configuration form implemented without guessing legacy calendars.
- [x] Attendance-aware execution and live handover preserve managerial attestation and known-absence restrictions.
- [x] Remaining-time guard and atomic timing/crew recovery implemented; retries and invalid-recorder rollback verified.
- [x] Worker-aware proposals, rerun preservation, earliest-start restrictions, overnight capacity, and reviewed holiday authorization implemented.
- [x] Phase-specific readiness and persistent, distinct failed-run diagnostics implemented.
- [x] Additive development migrations applied and read-only recovery report inspected; rollback instructions recorded.
- [x] Focused backend/frontend, role-based headless browser, concurrency, lint, formatting, build, and whitespace checks passed.
- [x] Approved operational setup applied: 148 shop-floor calendars Monday–Saturday, 58 office calendars Monday–Friday; repeat preview has zero changes. WO-202610-0002 has one eligible recorder. WO-202610-0011/0012 remain planned after scoped proposal repair; existing approved WO-202610-0013 crew/slot preserved.

## Final user steering

The user requested removal of WO confirmation from Production Schedule. This supersedes earlier steps specifying single-row or bulk page confirmation. Production Schedule retains timeline, proposal generation, diagnostics, readiness, and WO links. Confirmation remains on the WO record and keeps the shared backend guards. Updated unit and headless role-based browser checks verify this handoff.
