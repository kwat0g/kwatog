# Work-order crew assignment verification

Verified on 2026-10-05 in the shared workspace.

## Results

| Gate | Result |
| --- | --- |
| Focused production, materials, output replay, operation handover, QC closure, and inline-repair backend regressions | 85 tests, 358 assertions, passed |
| Crew, WO detail, office output, repair output, employee workspace UI tests | 19 tests across 5 files, passed |
| Real headless Chromium against the isolated audit application | 1 scenario with 6 real role accounts, passed in 24.3 seconds |
| TypeScript and Vite build (`npm run build`) | Passed |
| Scoped ESLint with `--max-warnings 0` | Passed |
| Laravel Pint for the 9 new backend and migration files | Passed |
| Relevant `git diff --check` | Passed |

## Runnable checks

From the repository root:

```bash
docker exec ogami-api php -d memory_limit=-1 vendor/bin/phpunit -c phpunit.pricing.20261004.xml --filter 'WorkOrderCrewTest|WorkOrderOutputIdempotencyKeyTest|WorkOrderProcessQualityGateTest|WorkOrderStartQcGateTest|WorkOrderMaterialIssueHandoffTest|WorkOrderCloseQualityGateTest|WorkOrderStartResumeOccupancyTest|WorkOrderOutputFgReceiptTest|WorkOrderGoodOutputTargetTest|WorkOrderRejectReplacementCoverageTest|WoOperationOutputRaceTest|WorkOrderInlineReworkTest'
```

This PHPUnit configuration pins a disposable test database. Do not substitute the production database.

From `spa`:

```bash
npx vitest run src/pages/production/work-orders/CrewPanel.test.tsx src/pages/production/work-orders/detail.test.tsx src/pages/production/work-orders/record-output.test.tsx src/pages/production/work-orders/ReworkPanel.test.tsx src/pages/self-service/work-orders.test.tsx
npm run build
```

Prepare a fresh disposable browser fixture from the repository root, then run the scenario from `spa`:

```bash
docker exec ogami-audit-20261002-api php scripts/wo-crew-e2e-fixture.php
```

```bash
E2E_BASE_URL=http://localhost:18080 npx playwright test -c playwright.real.config.ts e2e-real/wo-worker-assignment.spec.ts --project=real-chromium
```

The fixture refuses any database except `ogami_test_audit_20261002`; the browser scenario refuses any base URL except the isolated audit application. No API mocks are used. Final fixture tag: `crew-8184e7`.

## Browser coverage

PPC assigns two active workers, minimum 2 and maximum 3. Production management starts the WO. QC releases its real in-process inspection. The designated employee recorder records 45 good and 5 rejected pieces with a defect breakdown. Support-worker submission and unrelated employee access are denied. An exact retry produces one output row. Production management changes the recorder; PPC cannot change the live crew, the old crew revision cannot submit new output, and the replacement recorder receives access. The original actor can retrieve the saved receipt after handover.

Desktop and 390-pixel mobile workspace screenshots were inspected. Captures are available under `spa/e2e-real/results/wo-crew-desktop.png` and `wo-crew-mobile.png`.

## Backend coverage and review corrections

Tests exercise minimum/maximum bounds, one-recorder membership, inactive crew members, portal denial, office receipt ownership without an employee link, HR shift snapshots, durable replay after completion, stale crew rejection, material issue coverage, finished-goods receipt handling, good-output targets, replacement-material requirements, QC start and closure gates, and inline repair conservation.

Review corrections include the repair assignment response using the already-resolved NCR identifier, replacing a removed operation operator during resume, checking current operator membership before operation output, and preserving exact payloads and idempotency keys after uncertain office or repair saves. A regression first reproduced the removed-operator output bug before the shared guard was added. UI regressions verify identical retries after lost responses, including a live crew revision change.

## Applied changes and limits

Both additive staffing/output migrations were applied successfully to the development application database and the isolated audit database. PPC and production-manager assignment permission grants were verified, and their cached role permissions were invalidated. Existing production business WOs were not assigned, started, or edited during these tests.

Existing WOs require a valid crew before start, resume, or new output. HR shifts are reused; there are no separate clock-based access windows. Assignment-derived access does not grant employees general production, inventory, HR, QC, or financial permissions.

This browser scenario validates the new crew slice through production output and handover. It does not claim a complete Sales Order-to-payment browser test. Repair, material, and closure behavior is covered by the backend regression suite. Unknown-save state is retained while the form stays mounted; page reload or navigation discards that local state, so operators should resolve the receipt before leaving.
