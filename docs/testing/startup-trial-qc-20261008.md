# Startup trial production and QC verification

Implemented and deployed on 2026-10-08. Approved design: [startup trial/QC](../superpowers/specs/2026-10-08-trial-production-qc-design.md).

## Behavior

Start prepares draft QC after the work order actually starts. Warehouse confirms material handover, then the assigned recorder or authorized manager records the pinned startup sample count with explicit physical confirmation. Setup is allowed under existing crew, attendance, resource, material and handover gates; ordinary production stays blocked.

QC cannot save physical results before samples exist. Size checks require an actual numeric reading; other checks use PASS/FAIL. PASS classifies those same samples once. FAIL holds only the actual samples; an independent authorized QC reviewer approves correction, reinspection and explicit kept-good/scrap quantities. Original FAIL remains recorded. Pending samples cannot receive finished-goods stock or outgoing release.

Paused startup can resume under readiness checks without approving QC. Operation inspections use actual whole output, not the parent target. Routing reconciliation excludes startup sample rows. Exact capture retries preserve the original batch and quantities.

## Verification

Disposable PostgreSQL databases isolated all test fixtures. No live stock, attendance, handover, sample result or production quantity was fabricated.

- Final integrated ledger/routes/setup/QC/inventory: **59 tests, 233 assertions passed**.
- Adjacent receipt/idempotency/handover/process-QC: **37 tests, 227 assertions passed**.
- Quality/spec/sampling follow-up: **47 tests, 217 assertions passed**; overlapping checks are not added to the above totals.
- UI focused/adjacent: **78 tests passed** before final polish; later recorder/QC/recovery checks passed 64 tests; final three-file mobile/time polish passed 8 tests. Overlapping counts are not summed.
- PHP Pint and touched-file ESLint passed. TypeScript/Vite production build passed.
- Chromium with genuine cookie-based role accounts: [PASS flow results](evidence/trial-role-playwright-20261008/results.json), **9 scenarios passed, zero browser exceptions/API 500s**. Warehouse and assigned workers signed in through UI; workers punched attendance, Warehouse confirmed receipt, recorder captured real trial, QC approved, ordinary output resumed.
- Separate failed sample captured through recorder UI; QC FAIL preserved five physical samples with no assumed scrap. Recovery saved through independent QC reviewer UI: four good, one scrap, original FAIL retained; normal output resumed. [Recovery proof](evidence/trial-role-playwright-20261008/failure-results.json).
- [Mobile polish proof](evidence/trial-role-playwright-20261008/polish-results.json): Manila capture time and recovery details without viewport overflow.

## Review fixes and rollout

Review found and fixed smaller declared trial coverage being overwritten by the sample count, preparation metadata excluding untouched drafts from backfill, paused-startup deadlock, operation checklist target fallback, and output-history lazy loading. No new dependencies or permission grants were added. Production managers remain unable to approve NCR disposition; an independent QC user with `quality.ncr.manage` performs that step.

Additive migration `2026_10_08_232000_add_startup_trial_production_ledger.php` applied successfully. Existing active untouched drafts for WO-202610-0002 and WO-202610-0003 were marked trial-required without changing output, stock or shot counts. Keep additive schema during rollback once sample evidence exists; deleting it would destroy production history.

Full repository suite and true simultaneous multi-process stress were not run. Durable unique constraints, transactional source locks and exact retry tests cover repeated capture/settlement. Existing manual receipt fallback remains when finished-goods setup needs Inventory action.
