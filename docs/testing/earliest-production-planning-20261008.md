# Earliest production planning verification

Verified on October 8, 2026, against the approved [design](../superpowers/specs/2026-10-08-earliest-production-planning-design.md) and [implementation plan](../superpowers/plans/2026-10-08-earliest-production-planning.md).

## Delivered behavior

MRP-generated work orders now use the planning run time as their planning reference. The existing production deadline remains one day before the current agreed delivery date. Child work orders inherit the root deadline instead of subtracting a day at each level. Existing manual start constraints and progressed work-order dates are preserved.

Placement compares all compatible equipment pairs and chooses the earliest feasible start, with end time, tonnage, and resource IDs as tie breakers. Earlier gaps remain usable. The selected worker count reaches placement as well as crew suggestion. A preferred date is a search lower bound; if that day is unavailable, the returned later alternative is identified explicitly. A failed search preserves the previous pending reservation.

Confirm offers **Earliest available** or **Choose day**, followed by the existing automatic or manual crew controls. Date and worker-count changes trigger one debounced generation; window focus and background reconnect do not regenerate the proposal. Pending requests disable confirmation, stale responses do not replace the current preview, and manual crew choices remain visible until the user replaces an unavailable worker. Ordinary confirmation does not require another explanation field.

The reviewed preference is saved with successful confirmation or explicit rescheduling. Read-only previews and cancellation do not save it. Confirmation still reserves material transactionally and checks crew, equipment, dependencies, and proposal versions. Start remains a separate action that checks actual readiness and records execution. The existing attendance policy, early-start checks, 15-minute grace, controlled late-start acknowledgement, and deadline protections remain in place.

Pending routing operations use the authoritative production interval rather than the potentially multi-day planning-reference interval. Started operations and routing definitions are preserved. MRP detail includes the authoritative timing of progressed work orders from previous plan versions. Production Schedule remains a review surface without confirmation controls.

## Automated checks

Backend checks ran sequentially against the dedicated PostgreSQL database `ogami_test_earliest_planning`, not the live database or shared test database.

| Check | Result |
| --- | --- |
| `MrpRerunSafetyTest`, `SubassemblyWorkOrderTest`, `CapacityPlanningServiceTest`, `WorkOrderCrewTest`, `WoOperationScheduleProjectionTest` | 62 tests passed; 300 assertions |
| `ProductionRoutingTest`, `WorkOrderAttendanceSafetyTest`, `WorkOrderExecutionReadinessTest`, `WorkOrderMaterialIssueHandoffTest`, `WorkOrderScheduleWarningTest`, `WorkOrderScheduleReminderTest` | 124 tests passed; 717 assertions |
| Final rerun of `CapacityPlanningServiceTest` plus `MrpPlanDetailTest` after placement caching and prior-plan display correction | 23 tests passed; 106 assertions; 21 overlap the first group |
| Vitest: `CrewPanel.test.tsx`, `StartProductionDialog.test.tsx`, `detail.test.tsx`, `schedule.test.tsx` | 57 tests passed |
| `npm run build` | Passed, including TypeScript compilation |
| Targeted ESLint | Passed |
| Targeted Laravel Pint | Passed; final two changed files also passed |
| Targeted `git diff --check` | Passed |
| Impeccable detector on five changed UI files | Passed; no findings |

These checks cover 188 distinct backend tests. Regressions cover legacy MRP versus manual lower bounds, earlier compatible equipment, gaps before later commitments, required descendants, full-window crew count, stale confirmation rollback, exact confirmation replay, attendance changes, material handover and reversal, overnight coverage, late execution, and pending-operation projections. Date-specific checks were added to existing suites rather than creating parallel fixture-heavy test classes.

Tests also exercise overlapping crews and stale schedule versions on isolated fixtures. This verifies rejection and preservation behavior; it is not a simultaneous multi-process load test.

A new prior-plan display assertion first failed because the approved schedule was omitted. Adding the existing schedule relation to the prior-plan eager load made both plan-detail endpoints pass. Earlier intermediate failures during development were corrected before the passing runs above; they are not counted as passing checks.

Representative commands:

```bash
docker exec -e DB_DATABASE=ogami_test_earliest_planning ogami-api php artisan test \
  --filter='MrpRerunSafetyTest|SubassemblyWorkOrderTest|CapacityPlanningServiceTest|WorkOrderCrewTest|WoOperationScheduleProjectionTest'
docker exec -e DB_DATABASE=ogami_test_earliest_planning ogami-api php artisan test \
  --filter='WorkOrderAttendanceSafetyTest|WorkOrderExecutionReadinessTest|WorkOrderMaterialIssueHandoffTest|WorkOrderScheduleWarningTest|WorkOrderScheduleReminderTest|ProductionRoutingTest'
docker exec -e DB_DATABASE=ogami_test_earliest_planning ogami-api php artisan test \
  --filter='CapacityPlanningServiceTest|MrpPlanDetailTest'
cd spa
npm run test:run -- src/pages/production/work-orders/CrewPanel.test.tsx \
  src/pages/production/work-orders/StartProductionDialog.test.tsx \
  src/pages/production/work-orders/detail.test.tsx src/pages/production/schedule.test.tsx
npm run build
```

## Browser checks

The actual confirmation component was rendered with isolated mocked API responses, without business writes. Chromium checks at 1440 × 1000 and 390 × 844 verified the date control, debounce, stable preview on focus, responsive scrolling, and absence of horizontal overflow or page errors. Screenshots were inspected together:

- `/tmp/earliest-review-evidence/desktop.png`
- `/tmp/earliest-review-evidence/mobile.png`

The browser check observed two generation requests for two deliberate changes and no focus-triggered regeneration. Separate deferred-response Vitest checks verified that an older response cannot replace a newer date's proposal. The authenticated live confirmation and Start flows remain for the user's business test.

## Migration and live preview

The additive `2026_10_08_120000_add_preferred_production_date_to_work_orders.php` migration was applied through the existing local API container. It adds one nullable date without a speculative backfill. Private schema and business-table snapshots were taken first. Comparing the business rows before and after, excluding the new null column, found no changes.

Rollback and reapplication were verified inside a transaction on the isolated database. Existing dates, work orders, crew/history, operations, and material ledgers were preserved. Removing the column would lose saved preferences, so preserve those values before any later production downgrade.

Read-only placement previews on existing local data found these independent alternatives with two eligible workers and no material/setup preview blockers:

| Work order | Existing planning reference | Production deadline | Read-only alternative, Asia/Manila |
| --- | --- | --- | --- |
| WO-202610-0002 | October 15, 2026, 12:00 AM | October 16, 2026, 12:00 AM | October 8, 2026, 7:42 PM–9:42 PM |
| WO-202610-0003 | October 18, 2026, 12:00 AM | October 19, 2026, 12:00 AM | October 8, 2026, 7:42 PM–9:42 PM |

These were separate availability probes, not combined bookings. They prove the legacy future planning reference no longer prevents an earlier feasible proposal. Actual times depend on current capacity when the user opens Confirm. The live scheduler was not run to replace the user's proposals, and no live work order was confirmed or started during verification.

## Manual test handoff

1. Refresh the application and open **WO-202610-0003** as an authorized PPC or Production Management user. Click **Confirm**.
2. Leave **Earliest available** selected and choose **2** workers. Check the actual proposed interval, equipment, and crew. It can be earlier than October 18 when resources allow.
3. Select **Choose day**, pick today or another day before the exact production deadline, and change the worker count. Expect one automatic refresh for each deliberate change and no refresh while reading.
4. Switch to **Manual assign**. A recorded absent or off-shift worker cannot be confirmed for that interval. Missing attendance remains a separate state and does not mean recorded absence.
5. Confirm only when ready to make this business reservation. Check that the preferred date, approved schedule, crew, and material reservations are saved together. Production must still be Confirmed, with no actual Start.
6. Click **Start** and review actual readiness. Future timing, attendance, unavailable resources, and deadline problems require the appropriate recovery action. Starting production records actual time and issues materials once.
7. If intentionally running the scheduler afterward, verify that this confirmed work order keeps its approved schedule.

## Limits and risks

The planner is a bounded greedy planner, not a global factory optimizer. Pending proposals prove enough eligible workers exist but do not reserve named employees; confirmation rechecks and locks the reviewed crew. Future attendance, QC completion, and material arrival cannot be guaranteed by a preview.

The reservation remains at least two hours, or longer when the production estimate plus the existing 15-minute buffer requires it. This does not change the production rate estimate or promise that every order finishes in two hours. The existing one-day production-to-delivery buffer remains unchanged.

Earlier production can reserve materials sooner and hold finished stock longer. No new dependencies, automatic overtime, fabricated attendance, new permission grants, purchase-date rewrites, or unrelated module changes were introduced for this task.
