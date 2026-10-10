# Automatic production finish after reaching the target

Approved behavior: finish-first work orders finish production when their recorded physical quantity reaches the target. The existing completion service still enforces material handover, pending historical reports, routing operations, stock receipt and ledger posting. It releases equipment and schedule reservations, records the existing completion event, and retains outgoing QC and stock holds. Short production runs still require an explicit short-completion reason. Legacy QC and repair work orders retain their existing workflow.

The shared output service covers both manager and assigned-worker recording. Receipt recovery, ledger recovery, material handover, final operation completion or skipping, approved or rejected historical reports, quality recovery and resume also retry completion. Row locks and existing idempotency checks prevent duplicate output, receipt or completion events. The detail page hides further recording and the redundant finish action at the target and displays the exact pending prerequisite.

## Validation

- Isolated database: `ogami_test_auto_finish_20261009`; no test suite ran against the live database.
- Backend regression: 68 tests, 391 assertions passed. Final focused verification after the last historical-report change: 32 tests, 242 assertions passed.
- UI: 47 tests passed, including full-target action visibility and the exact completion blocker.
- SPA production build and focused ESLint passed; PHP syntax and changed-file whitespace checks passed.
- Live browser: authenticated production manager opened `WO-202610-0009` at 1440 and 390 pixels. The page displayed 200 produced pieces and the final inspection action, without Record production or Finish production buttons. No page errors occurred.
- Retry proof: one completion event, unchanged output count and physical quantity, no duplicate receipt. Replaying outgoing QC creates one inspection per original output batch and retains held stock.
- The first broad backend run inherited the isolated application's enabled finish-first feature flag and failed legacy fixtures. Pinning that test configuration to the default disabled flag, with finish-first tests explicitly enabling it, restored the legacy regression checks.

## Live reconciliation

Only `WO-202610-0009` was reconciled through the same guarded service after deployment. Its 200/200 pieces, three completed material handovers, generated receipt and settled ledger were checked first. The service changed `in_progress` to `completed`, released machine and mold ownership, and marked its schedule executed without capacity reservation.

Before and after: one output batch, five total production receipt movements, receipt quantity 200, total PC-001 inventory quantity 700, and held quantity 200. A repeat completion attempt made no change. The queue automatically created draft inspection `QC-202610-0015`. QC must approve those 200 pieces before they become available for shipment.

Artifacts and rollback source copies: `/tmp/wo-auto-finish-20261009/`. No dependency or schema changes. The entire repository test suite was not run; concurrent unrelated workspace changes were preserved.
