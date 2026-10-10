# Work-order Start blocked by an incomplete legacy specification

## Cause

The October 8 Start request for WO-202610-0003 passed worker-presence confirmation but failed while automatically creating in-process QC. PC-001 specification revision 1 used legacy measurement capture. Its Length, Width, Height, and Weight checks had valid numerical limits, but Surface Finish had no acceptance criterion, notes, or limits. With checklist capture enabled, that visual check had no instruction defining PASS.

The read-only execution endpoint incorrectly returned `ready: true` because it checked only that an active specification, items, and revision existed. The QC scaffold then rejected the missing instruction with `Every approved checklist check needs an acceptance criterion.` The surrounding transaction kept the work order Confirmed.

## Change

The existing specification service now provides a shared inspectability guard. Proposal preview, execution readiness, actual Start, and new product-bound inspection creation use it. A missing instruction produces an actionable message naming the check: `QC: add a PASS requirement for "Surface Finish" in the inspection specification.` Inspection creation retains its idempotent reuse of existing records before validating a new scaffold.

The demo seeder now supplies the existing Appearance preset requirement for Surface Finish. The live PC-001 specification was repaired through the normal revision service, publishing revision 2 with `No cracks, damage, contamination, flash or short shots.` for Surface Finish. Length, Width, Height, and Weight retain their exact units, targets, and limits. Revision 1 and historical evidence were preserved. No live Start was performed.

A private before-state snapshot is stored at `api/storage/app/qc-start-spec-before-20261008.json`. The transaction verified that the work-order row and counts of stock movements, material reservations, schedules, crews, and inspections remained unchanged. A post-repair read-only check returned readiness with no QC blocker.

## Verification

The regression first failed because readiness returned true for the incomplete specification. After the fix, it proves that readiness names the missing check, Start fails without business changes, publishing a new valid revision preserves the historical item, and Start creates one checklist inspection using the published PASS requirement.

- `WorkOrderExecutionReadinessTest`, `InspectionSpecBoundaryTest`, `OutgoingQcIdempotencyTest`, and `ChecklistSamplingPlanTest`: 68 tests passed, 418 assertions, on the dedicated `ogami_test_earliest_planning` database.
- `StartProductionDialog.test.tsx`: 14 tests passed, including hiding presence confirmation and Start when QC readiness is blocked.
- Targeted Laravel Pint, frontend ESLint, PHP syntax validation for the one-line seeder change, and `git diff --check` passed.

No production UI code changed; the existing blocker presentation consumes the corrected backend result. The full application test suite and a live production Start were not run.

## Related scheduling concern

The user also reported that earliest proposals become Start overdue immediately. Current warning logic compares the scheduled start directly with the current time, despite the existing 15-minute execution grace. Recommended next change: pending proposals should say Awaiting confirmation; confirmed work orders should say Start available during the 15-minute grace and Start overdue afterward. Proposals that expire during review should require an explicit refresh before confirmation. Start now should remain possible when actual attendance and resource checks pass. This recommendation has not changed scheduling or warning behavior in this fix.
