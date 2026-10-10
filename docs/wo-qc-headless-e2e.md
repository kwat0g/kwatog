# Work order and quality inspection browser verification

Latest full six-scenario run verified on 2026-10-04 with tag `rwrk12` against the local audit stack at `http://localhost:18080`, using headless Chromium, the real Laravel API, and PostgreSQL database `ogami_test_audit_20261002`. Focused rework-gate and audit-trail regression passed separately with tag `rwrk13`. The app reported `local`; no API mocks or database resets were used.

Full result: **6 passed, 0 failed, 0 skipped** in 1.5 minutes. The focused audit-trail regression: **1 passed** in 36.2 seconds. The full run asserted each inline repair step before advancing, including both posted, balanced **₱5.00** stock-movement journals.

Accounts used: the existing QC inspector, production manager, warehouse staff, and employee demo accounts, plus a tagged second QC inspector account for independent sorting approval. Each account used a separate browser context and authenticated through the actual Sanctum sign-in endpoint. The two QC accounts have the same role; they are different people for maker/checker controls.

## Verified behavior

| Scenario | Evidence |
| --- | --- |
| PASS/FAIL workload | Five QC stage screens displayed three checklist requirements for a 100-piece batch, with three PASS/FAIL pairs and no numeric measurement inputs. Submission stayed disabled until all decisions and the failed requirement's problem text were present. |
| Independent inspection approval | The QC maker's own review returned 403. A production checker confirmed the failure through the browser; accepted quantity became zero and one linked NCR was created. |
| Role boundaries | Warehouse and employee accounts could not read or mutate QC records. Production could read and review QC but could not enter inspection results or create NCRs. QC could not record production output. Unauthorized actions were absent from the relevant screens. |
| Late defect after WO closure | A QC inspector reported one defective piece through the inspection's Report defect button. The original 100-piece WO stayed closed, and its inspection's accepted quantity became zero. |
| Stock protection | Warehouse material issue requests were refused while the batch was held, both with an explicit lot and with no lot specified. |
| Sorting and repair | The sorter could not approve their own recovery. A second QC inspector documented full sorting of 100 pieces, released 99 good pieces, and created a repair WO for exactly one piece. Repair cancellation was refused. |
| Open-work-order repair | A QC inspector reported one defect against an open 100-piece WO. Independent full-batch sorting released 99 pieces and confirmed one repair. Production started repair, recorded one piece, and finished repair inside the original WO. The output received fresh QC, original production stayed at 100, and the WO closed automatically only after checked repair QC and NCR closure. Production received the linked notification. |
| Repair completion | Production confirmed, started, recorded one repaired piece, and completed the repair through browser forms. The test waited for queued outgoing QC, entered PASS decisions, and obtained independent production approval. |
| Rework API gates | Direct API attempts from QC, warehouse, and employee were forbidden. Production could not use another WO's NCR, record or finish before start, start twice, exceed quantity, finish early, finish twice, or close the NCR before fresh QC. Concurrent same-key output replays returned the one UI-created output. Reads after denials showed no extra output, stock movement, journal, or status transition. |
| Audit actors | System Admin's read-only entity trail showed the real QC creator, independent QC sorter/checker, and Production actor for start, output, and finish. One audit row remained for the UI-created rework output after concurrent replay. |
| NCR closure | Closing the NCR before repair completion or before repair QC approval was refused. After corrective and preventive actions and passed repair QC, NCR closure succeeded and the repair WO closed automatically. |
| Inventory conservation | The fixture contains two 100-piece lots, one from a closed WO and one from an open WO. Rework moved one piece out for repair and back as accepted finished goods, while two balanced **₱5.00** journals posted. Final stock remained exactly 200 pieces after both repairs; each original WO retained its 100-piece production quantity. |
| WO and operation gates | WO closure was refused with unsettled QC. Completing a required operation persisted pending QC and was refused. Further operation output was frozen. After passing its QC, operation completion succeeded. |
| Browser/server failures | The final run recorded no browser page errors or API responses with status 500 or above. |

## Defect found and fixed

The inspection's Report defect button linked to `/quality/ncrs/create`, which was interpreted as an NCR detail identifier. The application rendered “Failed to load NCR.” The correct creation route is `/quality/ncrs/new`. The button and its existing component regression test now use that route; the dedicated headless navigation test passed.

Backend checks passed 567 Production/Quality/MRP/work-order notification tests with 2,039 assertions, followed by 66 final inline repair, inventory, closure, chain, and outbox tests with 237 assertions. Frontend checks passed 28 focused component tests, TypeScript, ESLint, production build, and `git diff --check`.

## Reproduce

Use an unused lowercase letter, digit, or hyphen tag of at most six characters. Fixture accounts and products use unique tag-derived keys, so changing the tag is required for a repeat run. Each run writes its own tagged manifest, so concurrent runs do not replace one another's fixture references. The fixture script only creates tagged records; it does not truncate or refresh the database. The command below refuses to create fixtures outside the local audit database.

```bash
docker exec -e E2E_QC_TAG=rwrk12 -w /var/www ogami-audit-20261002-api php artisan tinker --execute='if (app()->environment() !== "local" || DB::connection()->getDatabaseName() !== "ogami_test_audit_20261002") { throw new RuntimeException("Refusing fixture creation outside local audit database."); } require "/var/www/tests/Browser/work_order_qc_fixture.php";'
cd spa
E2E_QC_TAG=rwrk12 E2E_BASE_URL=http://localhost:18080 ./node_modules/.bin/playwright test -c playwright.real.config.ts --project=real-chromium e2e-real/work-order-qc-connection.spec.ts --reporter=line --output=/tmp/ogami-audit-wo-qc-rwrk12-final
```

The fixture command requires `local` and `ogami_test_audit_20261002`. The run manifest is `api/storage/app/work-order-qc-e2e-rwrk12.json`. Failed runs retain Playwright traces.

## Coverage limits

Stage screen coverage uses inspection fixtures sharing the same production specification; it does not prove supplier receiving or customer/supplier return creation workflows. The late-defect recovery workflow exercises real UI forms and queued integrations. Permission denials and lifecycle gates were asserted through authenticated API requests within those browser contexts. First-attempt concurrent start/output races, role changes or deactivation mid-chain, recovery with no eligible next actor, actual delivery dispatch, sales-order MRP replanning, multi-bin concurrent consumption, and simultaneous inspector races remain unverified.

## Inline repair implementation checks

The open-WO path keeps repair outputs and fresh QC on the original order. Equipment starts only through an explicit production action; original BOM consumption and production totals are not repeated. Rejected repair pieces are scrapped and handed to the existing sales-order or standalone replacement flow. MRP counts outstanding inline repair as committed production and credits repaired output without double-counting source pieces. Mold ownership and shot counts cover machine repairs. Repair consumption uses the same accounting offset as repaired receipts.

Automatic closure checks the authoritative WO under a lock in the resolving transaction and also records a durable retry event. It refuses pending/unchecked QC, missing stock receipts, unresolved NCRs, and unfinished repairs. Historical separate repair orders remain separate; defects found on already-closed WOs still create a linked repair order.

Frontend validation passed 28 focused tests, TypeScript checking, ESLint, production build, and the scoped Impeccable detector. The mobile repair panel was checked at 390 pixels without horizontal page overflow. Independent code review found no remaining material findings after its stock, MRP, equipment, nested repair, and accounting findings were corrected.
