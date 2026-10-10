# Module process visibility audit — 4 October 2026

This audit checks failures that leave users with empty controls, stale progress, or no useful recovery action. It supplements the previous operations and B2B restart audits. It does not claim that every possible lifecycle or data combination has been tested.

## Findings and repairs

| Area | Observed failure | Repair |
| --- | --- | --- |
| Shared process progress | Missed or unavailable broadcasts could leave a record and its process query stale indefinitely. | Refresh active detail and process queries every 30 seconds while the document is visible; stop on unmount. Work orders now include their separate process and operations queries. |
| Customer orders | A failed process read disappeared while the order itself remained readable. | Persistent retry message and periodic progress refresh for open orders. |
| Supplier documents | Failed document reads hid the panel; failed document-type reads left an empty upload selector. | Visible errors and separate retry actions; block upload while its required options are unavailable. |
| Delivery assignment | Driver or vehicle lookup failure looked like no available resources. Workflow/proof option failures hid actions. | Distinguish failed reads from empty results, offer retry, and block dependent submission. |
| Material issue | Failed item catalogue read became an empty selector. | Persistent retry and submission guard while required reads fail or load. |
| Accounting entry | Failed cash-account/payment-option reads left empty payment and collection forms. | Persistent retry and block dependent entry until options load. |
| Stock adjustment | Failed item/location/policy reads became empty controls. | Persistent retry and guards on entry and confirmation. |
| Warehouse count and transfers | Failed selected-record reads appeared as an unselected record. Missing count policy hid variance approvals; failed warehouse reads emptied transfer destinations. Count confirmation hardcoded a 2% threshold. | Selected-record retry messages, prerequisite guards, and confirmation text using the configured variance threshold. |
| Scanner handoffs | Count, picking, and bin destinations did not select the scanned record; some links used numeric IDs where pages expect hashes. Receiving used a parameter its form does not read. | Generate destination-compatible links; select the count session/line, picking slip, and warehouse/zone/bin. Recover failed bin and picking detail reads and scanner options. |
| Goods receiving | Failed PO or warehouse reads became empty controls; rejected fields produced a generic instruction. | Retry prerequisites, block unsafe submission, and reuse the existing error reporter to show the actual rejected field. Receipt input survives rejection. |
| Purchasing | Failed request policy/options reads did not clearly explain why creation could not proceed. | Persistent retry and draft/submit guards. |
| Maintenance | Failed work-order options looked empty. | Persistent retry and creation guard; existing target recovery remains in place. |
| Quality creation | Failed stage/product/output reads appeared empty. A delayed old AQL preview overwrote the currently selected batch plan. | Prerequisite recovery; derive the preview from its query key rather than a separate side-effect state. Failed previews have their own retry. |
| Returns | Failed disposition/warehouse reads left unusable controls. | Persistent retry and guards for dispositions requiring stock movement. |
| Employee self-service | Failed leave policy and holiday reads became empty options or a misleading estimate. Failed loan preview reads left a blank estimate. | Retry required reads, block submission until policy/holiday data loads, and separately recover balance and loan preview reads. Loan submission retains server calculation as its authority. |
| HR and assets | Failed leave type/policy, loan type/limits, employee, and asset-option reads became empty controls. Leave balance failures were explicitly suppressed by the shared client. | Persistent retry and prerequisite submission guards; leave balance failures now explain server eligibility checks and offer retry. |
| Budgeting and training | Failed budget edit reads remained on an endless skeleton. Failed allocation, department, and training reads appeared empty or falsely missing. | Retry failed records and prerequisite options, preserve entered values, and block dependent saves while required reads fail or load. |
| Payroll | Bank preview used the finalize permission instead of bank-file permission. Failed employee payroll reads left an empty tab; option/preview errors left unusable controls. | Match preview permission to generation, require successful review before generation, and recover failed bank/proof option and employee payroll reads. |
| Dashboard | Failed live value refresh left historical layout values visible without warning. | Persistent outdated-value warning and retry without discarding the layout. |
| MRP broadcast outbox | Updating a generated plan before dispatch caused repeated version mismatch failures. | Opt the fact event into existing newer-state support. Refresh current plan and suppress cancelled/superseded broadcasts. Strict version checks remain for other events. |
| Payroll finalization outbox | Later bank-file updates prevented a finalized event from decoding. A delayed payslip-ready listener could notify employees after the period was voided. | Reuse newer-state fact-event support and the existing payroll publication policy; read current period state before notifying employees. |

## Data and test harness findings

The isolated browser database still contained `PR-DEMO-CONVERT` and `PR-DEMO-BUDGET` with RM-001 lines in `pcs`, although the inventory base unit is `kg`. The same seed defect had already been corrected in the application database and seeder. The isolated database was repaired under exact demo-record, quantity, status, reason, and unconverted-line guards. Quantities, prices, and approvals were preserved, with a before snapshot and audit entries. No physical conversion was invented.

The returns retry browser test intentionally returned HTTP 422 but inherited a fixture that rejects all browser console errors. It now uses a local empty-read fallback and still checks unhandled page errors, retry quantities, and a stable idempotency key. Procurement test locators were updated to match current combined selectors.

## Verification

This section records completed checks and the limits of the interrupted broad runs. Real browser mutation tests use only `http://localhost:18080` and database `ogami_test_audit_20261002`. PHPUnit uses forced dedicated test databases, never the application database.

- Role-access crawl: **872 checks across 16 role accounts, zero failures**. Covers permitted visible pages, denied pages, mobile/desktop navigation, page exceptions, and failed page API reads.
- Customer and supplier portal authentication: **2 real headless tests passed**, including isolation and server logout.
- Lookup recovery and procure-to-pay browser checks: **11 passed**. Returns and AQL race recovery subsequently passed **2 tests**. Receiving field-error recovery passed **1 test**. HR and asset lookup recovery passed **3 tests**. The existing payroll lifecycle suite passed **4 tests**. The final payroll/employee self-service recovery run passed **3 tests**, covering bank review, proof options, employee payroll, leave policy, and loan preview.
- Outbox fact-event regressions: **6 tests, 13 assertions passed**. The application’s previously stuck MRP outbox row subsequently published automatically and cleared its error.
- Earlier full frontend run: **475 tests passed**. A later 480-test run had four timing-sensitive failures under high parallel load; all four files passed with one worker (**37 tests**). The bounded final full run passed **480 tests across 93 files**.
- Real headless procurement: **11 distinct scenarios passed across two serial runs**. Includes sales confirmation/MRP/auto-PR, approval thresholds, rejection, accepted receiving/QC, draft-bill automation recovery, posted partial payment, unpaid-bill cancellation/payment rejection, and supplier return/disposition/replacement PO.
- Final QC/maintenance lookup and delayed-sample-response check: **3 headless tests passed**.
- Final scoped ESLint and production build passed after payment, collection, stock-adjustment, and self-service changes.
- Final nearby frontend checks passed **11 tests across seven files** after the employee self-service changes.
- Accounting payment/collection and stock-adjustment recovery: **3 headless tests passed**; nearby invoice regressions passed **7 tests**.
- Warehouse count/transfer, scanner count handoff, budget creation/edit/transfer, and training creation/edit: **8 headless tests passed**. Bin/picking scanner handoffs and scanner-option recovery passed **3 additional headless tests**. Expanded scanner destination regressions passed **4 backend tests, 24 assertions**.
- Payroll finalization, notification deduplication, voided-period suppression, bank generation, payslip email, and fact-event decoding: **18 backend tests, 45 assertions passed**. Existing historical failed payroll messages remain available for Finance recovery; they were not replayed into accounting as part of a code audit.
- Independent import/customs regression group passed **55 tests, 299 assertions**. Final visibility frontend regressions passed **10 tests across six files**, and the final production build passed after scanner changes.
- Broad backend first pass: **4,625 tests executed**, with one parallel fake-storage assertion collision. The affected delivery concurrency class passed independently (**4 tests, 219 assertions**). The isolated broad rerun completed one shard with **1,542 tests, 11,113 assertions passed**. Two workers exited without final summaries. One required cancellation of a permission lookup that consumed CPU for more than eight minutes in its disposable test database. Their remaining groups subsequently passed **150 tests, 801 assertions** and **419 tests, 1,532 assertions**, including overlap with earlier executed tests. The affected customs class also passed independently. These are completed checks across multiple runs; no complete single all-green broad run is claimed.

See [operations hardening](operations-cross-module-hardening.md) for transaction, stock, UoM, maintenance, and accounting controls and [B2B restart readiness](b2b-restart-readiness.md) for the complete sales-to-cash evidence and required business setup.

## Repeatable checks

```bash
cd spa
BASE_URL=http://localhost:18080 NODE_PATH=./node_modules node ../scripts/role-permission-audit.js
E2E_BASE_URL=http://localhost:18080 npx playwright test -c playwright.real.config.ts e2e-real/portal-auth.spec.ts
PW_BROWSER=chromium npx playwright test e2e/*lookup-recovery.spec.ts e2e/inspection-sampling-race.spec.ts e2e/grn-validation-recovery.spec.ts e2e/rma-workflow.spec.ts --project=desktop-chromium
PW_BROWSER=chromium npx playwright test e2e/warehouse-budget-training-recovery.spec.ts e2e/scanner-handoff-recovery.spec.ts --project=desktop-chromium
npx vitest run --maxWorkers=2
npm run build
```

Run the real procurement suite serially. Its receipt, bill, payment, and supplier return scenarios share record identifiers and cannot be selected independently without the earlier setup test. The final five scenarios may be selected together after their shared setup remains included.

```bash
cd spa
E2E_BASE_URL=http://localhost:18080 npx playwright test -c playwright.real.config.ts e2e-real/live-procurement.spec.ts
```

Backend regression command:

```bash
docker exec -e TEST_TOKEN=module_outbox ogami-api php -d memory_limit=-1 vendor/bin/phpunit -c phpunit.pricing.20261004.xml --filter=OutboxFactEventToleranceTest
```

Captured logs: [process visibility probes](probes/module-process-visibility-20261004/). Deliberate HTTP failures in recovery tests are expected; passing assertions require a named error, safe submission behavior, retained input where relevant, and successful retry.
