# Overnight LIVE E2E/RBAC Audit - Final Report

**Run date:** 2026-09-14 overnight window
**System under audit:** `http://localhost`
**Database:** seeded `ogami_overnight_20260914`, not reset
**Audit evidence:** `coverage.md`, `findings.md`, `roles.md`, and the ten JSON ledgers under `artifacts/`

## Executive Disposition

The audit reconciles 84 planned packs across B1-B13, the A01-A18 approval packs, and the L01-L17 long-chain packs. The post-fix disposition is:

| Final unit | PASS | FAIL | FIXED | BLOCKED | OBSERVED | NOT RUN |
|---|---:|---:|---:|---:|---:|---:|
| Scenario packs | 71 | 0 unresolved | 0 pack status | 12 | 0 pack status | 1 |
| Confirmed product findings | 0 new | 0 unresolved | 26 | 0 | 1 gap | 0 |
| Raw JSON assertion ledger | 476 | 28 historical/raw | 0 status | 28 | 75 | n/a |

The raw JSON totals are not the final product verdict. They include pre-fix failures, expected terminal responses, wrong-role probes, fixture collisions, and harness-contract mistakes. The final product disposition uses the documented post-fix live reruns and focused regression results.

There is **no unresolved confirmed product failure in the exercised and post-fix-verified scope**. This statement does not mean the ERP is fully chain-complete: the blocked and unrun branches below remain unverified.

## Run, Environment, And Resource Discipline

- Each live batch used Playwright Firefox serially: one browser, one context, one page, and one actor session at a time.
- Internal role switches logged out through `POST /api/v1/auth/logout` and cleared cookies before the next login. Supplier and customer portal switches used their portal logout paths.
- No Docker stop/restart, database reset, concurrent browser, parallel worker, broad PHPUnit run, or source edit was used by the overnight audit.
- `system_admin` was used only for technical recovery, admin reads, or failure visibility. It was not substituted as a business approver.
- `finance2@ogami.test` was used as the distinct same-role Finance checker where the live chain required a Finance maker/checker split.
- No seeded active business record was intentionally mutated for a missing fixture. Disposable records were created with run-specific values and retained, archived, cancelled, rejected, or voided according to their available lifecycle.
- Cross-module IDs were resolved under the owning role. The correction workers distinguished view, create, mutate, retry, and approval permissions instead of treating a legitimate `403` as a product failure.
- The audit did not read or print environment secrets, bearer tokens, cookies, reset tokens, or deployment credentials. Portal reset completion was not claimed because no reset-token handoff was available.
- The current live database remained populated with audit evidence. No rollback/reset was performed.

## Scope And Pack Counts

The authoritative planned scope is 84 executable packs:

| Scope group | Packs | IDs | Final disposition |
|---|---:|---|---|
| Small CRUD and local surfaces | 49 | S01-S49 | 45 PASS, 3 BLOCKED, 1 NOT RUN |
| Approval and maker-checker workflows | 18 | A01-A18 | 15 PASS, 3 BLOCKED |
| Long chains and cross-module effects | 17 | L01-L17 | 11 PASS, 6 BLOCKED; L15 historical failure fixed |
| **Total** | **84** | **S01-S49, A01-A18, L01-L17** | **71 PASS, 12 BLOCKED, 1 NOT RUN** |

### Batch Reconciliation

| Batch | Covered packs | Historical/raw disposition | Final reconciled disposition |
|---|---|---|---|
| B1 | S01, S03-S07, S13, S46-S49 | 254 PASS, 0 FAIL after normalization; tenant comparison blocked | 11 PASS, with tenant comparison still blocked |
| B2 | S08-S14 | 138 PASS, 1 FAIL, 2 BLOCKED initially | 5 PASS, 2 BLOCKED; the 2 product findings were fixed and live-regressed |
| B3 | S15-S23 | 5 PASS, 4 product FAIL at initial pack level | 9 PASS after fix verification; 4 findings fixed; 3 process/path blocks remain evidence only |
| B4 | S24-S31 | 124 PASS, 7 raw FAIL, 3 BLOCKED initially | 8 PASS; 4 findings fixed; 4 threshold/fixture blocks remain |
| B5 | S32-S40 | 5 PASS, 3 product FAIL, 1 BLOCKED initially | 8 PASS, S33 BLOCKED; 5 findings fixed and the Quality/MRP regressions are closed |
| B6 | S41-S45 | 65 PASS, 8 raw FAIL, 4 BLOCKED in primary worker | 5 PASS with PPAP, assignment, depreciation, and RMA subchecks blocked; 2 findings fixed |
| B7 | S46-S49 | 98 PASS, 1 FAIL, 4 BLOCKED, 4 OBSERVED initially | 4 PASS scoped; delivery confirmation finding fixed; tenant/action/mail blocks remain |
| B8 | A01-A09 | 251 PASS, 13 FAIL, 2 BLOCKED, 21 OBSERVED raw | 8 PASS, A01 BLOCKED; Finance2/VP asset-disposal checker and PO approval-board projection fixes verified; notification gap OBSERVED |
| B9 | A10-A18 | 174 normalized PASS, 11 OBSERVED; 2 A14 failures | A11-A12 and A14-A18 PASS; A10/A13 BLOCKED; customer response replay and CRM acknowledgement fixes verified |
| B10 | L01-L05 | H2R foundation and request paths pass; payroll queue and final-pay terminal path blocked | L01-L03 PASS; L04-L05 BLOCKED, with L05 partial evidence; queued payroll listener fix and one-row compute verified |
| B11 | L06-L09 | 17 PASS, 3 OBSERVED, 4 BLOCKED, 0 unresolved FAIL | L06 PASS through queued MRP; L07-L09 BLOCKED at the legitimate shortage/source gate |
| B12 | L10-L14 | 2 PASS, 2 FAIL, 1 BLOCKED at initial normalized pack level | L10, L11, L13, L14 PASS after fixes; L12 BLOCKED behind outgoing inspection/CoC source |
| B13 | L15-L17 | 69 PASS, 27 OBSERVED, 6 BLOCKED, 1 product FAIL | L15 failure fixed and regression-tested; L16/L17 PASS with cross-chain recovery blocks |

The ten JSON ledgers contain 607 raw result entries: `476 PASS`, `28 FAIL`, `28 BLOCKED`, and `75 OBSERVED`. The raw `FAIL` count includes historical pre-fix evidence and corrected harness probes; it is not an unresolved-failure count.

## Seeded Role Matrix Summary

The planning role matrix lists job expectations, not blanket permissions. The live run exercised these identity families:

| Identity family | Seeded actor(s) | Evidence summary |
|---|---|---|
| Technical administration | `admin` / `system_admin` | Admin routes, recovery reads, operations health, and technical cleanup only; not counted as business SoD approval |
| Executive approval | `vp` / `vice_president` | Approval-board and terminal money-read checks; no ordinary master-data or execution ownership |
| HR | `hr` / `hr_officer` | HR master data, attendance, payroll maker, separation, statutory and training surfaces |
| Finance | `finance`, `finance2` / `finance_officer` | Accounting and Finance-owned maker/checker paths; `finance2` supplied distinct checker evidence |
| Production | `production` / `production_manager` | Work-order execution, output, OEE, loan manager step, salary/RMA approval steps |
| PPC | `ppc` / `ppc_head` | BOM, MRP, scheduler, forecast and routing-authoring surfaces |
| Purchasing | `purchasing`, `buyer2` / `purchasing_officer` | PR/PO creation and sourcing; `buyer2` is same-role creator/replay evidence, not a Finance/VP substitute |
| Warehouse | `warehouse` / `warehouse_staff` | GRN, stock, movements, counts, transfers, MRB and picking boundaries |
| Quality | `qc` / `qc_inspector` | Inspection, measurement, NCR, PPAP, calibration and quality analytics |
| Maintenance | `maintenance` / `maintenance_tech` | Corrective MWO execution and downtime; assignment/schedule permission blocks preserved |
| Logistics | `impex` / `impex_officer` | Shipment, container, document, landed-cost and delivery visibility |
| Department approval | `depthead` / `department_head` | Department-scoped employee reads, leave/OT/loan/RMA first legs and PR creation |
| Employee self-service | `employee` / `employee` | Own self-service and request ownership; own-vs-other employee comparison remained fixture-blocked |
| Driver | `driver` / `driver` | Assigned-delivery read scope; broad delivery and vehicle APIs denied |
| CRM/Sales | `crm` / `sales_officer` | Customer, SO, complaint and RMA maker paths |
| Supplier portal | `portal@supp.test` | One vendor tenant only; opposite-guard isolation passed |
| Customer portal | `portal@cust.test` | One customer tenant only; opposite-guard isolation passed |

Positive UI rendering was always paired with direct API evidence. Examples include the department-head employee response: 86 PROD rows out of 200 total, `view_sensitive=false`, and employee creation denied with `403`; employee self-service own reads returned `200`; supplier/customer opposite guards returned `401`; and driver broad delivery access returned `403`.

The second supplier/customer tenant comparison, second driver target comparison, distinct QC checker, and several distinct business checker fixtures were not present. Those are BLOCKED evidence, not permission grants and not product failures.

## Confirmed Findings And Fix Status

The table below lists every confirmed product finding. The first evidence in each row is historical pre-fix evidence. The status and verification column is the later post-fix state. A historical `500`, duplicate, `404`, or data leak is retained as evidence even when the live fix rerun passed.

| Finding | Severity | Current status | Implementation paths | Regression/live evidence |
|---|---|---|---|---|
| LIVE-B2-F01 position duplicate accepted | Medium | FIXED | `api/database/migrations/0490_guard_active_position_titles.php`; `api/app/Modules/HR/Requests/StorePositionRequest.php`; `api/app/Modules/HR/Requests/UpdatePositionRequest.php`; `api/app/Modules/HR/Services/PositionService.php`; `api/tests/Feature/HR/PositionIntegrityTest.php` | Historical create returned `201` for a duplicate active title. Post-fix case/whitespace replay returned field-keyed `422`; no second active row; focused test passed. |
| LIVE-B2-F02 debug trace in permission denial | High | FIXED | `api/bootstrap/app.php`; `api/tests/Feature/HR/PositionIntegrityTest.php` | Historical employee `403` exposed exception/file/line/trace. Post-fix department create returned only the generic permission message; debug fields were absent. |
| LIVE-B3-F01 payroll adjustment rounds 3-decimal money | High | FIXED | `api/app/Modules/Payroll/Requests/CreatePayrollAdjustmentRequest.php` | Historical `1.999` returned `201` and stored `2.00`. Post-fix same request returned `422` with the decimal rule and the adjustment count did not change. |
| LIVE-B3-F02 manual loan payment replay duplicates ledger payment | Critical | FIXED | `api/database/migrations/0491_add_manual_payment_idempotency.php`; `api/app/Modules/Loans/Controllers/LoanController.php`; `api/app/Modules/Loans/Models/LoanPayment.php`; `api/app/Modules/Loans/Requests/RecordLoanPaymentRequest.php`; `api/app/Modules/Loans/Services/LoanService.php`; `api/tests/Feature/Loans/LoanPaymentSerializationTest.php` | Historical exact replay created two payments and reduced balance twice. Post-fix missing key returned `422`; keyed replay returned the same resource; payment rows changed 0 to 1 only. |
| LIVE-B3-F03 CRM customer restore 404 | Medium | FIXED | `api/app/Modules/CRM/routes.php`; CRM customer restore binding | Historical CRM archive followed by owner restore returned `404`. Post-fix archive/restore returned `204/200`, lookup returned `200`, and the restored UI detail rendered. |
| LIVE-B3-F04 AP bill payment replay duplicates payment | Critical | FIXED | `api/database/migrations/0491_add_manual_payment_idempotency.php`; `api/app/Modules/Accounting/Models/BillPayment.php`; `api/app/Modules/Accounting/Requests/StoreBillPaymentRequest.php`; `api/app/Modules/Accounting/Services/BillService.php`; `api/tests/Feature/Accounting/BillServiceTest.php` | Historical replay created two payments and two AP effects. Post-fix replay returned the same payment and journal; one payment row existed; void restored the disposable bill aggregate and created one reversal. |
| LIVE-B4-F01 inventory category restore 404 | Medium | FIXED | `api/app/Modules/Inventory/routes.php` | Historical archived category could not be restored. Post-fix trashed-aware restore returned `200` live. |
| LIVE-B4-F02 UOM restore route unreachable | Medium | FIXED | `api/app/Modules/Inventory/routes.php` | Historical UOM restore returned `404` because no route was mounted. Post-fix `PATCH /api/v1/inventory/uoms/{hash}/restore` returned `200`. |
| LIVE-B4-F03 approved supplier restore 404 | Medium | FIXED | `api/app/Modules/Purchasing/Controllers/ApprovedSupplierController.php`; `api/app/Modules/Purchasing/Services/ApprovedSupplierService.php`; `api/app/Modules/Purchasing/routes.php` | Historical archived link returned `404`. Post-fix clean pair restored with `200`; active duplicate pair returned clear `422`, not `404/500`. |
| LIVE-B4-F04 raw GRN stock movement reference ID | High | FIXED | `api/app/Modules/Inventory/Resources/StockMovementResource.php` | Historical GRN movement returned numeric `reference_id: 2`. Post-fix live response over 19 movements contained zero numeric reference IDs. |
| LIVE-B5-F01 calibration partial PATCH rejects documented shape | Medium | FIXED | `api/app/Modules/Quality/Requests/StoreCalibrationRecordRequest.php` | Historical remarks-only PATCH returned `422` for omitted required fields. Post-fix remarks-only PATCH returned `200` and preserved other fields. |
| LIVE-B5-F02 archived machine cannot restore | Medium | FIXED | `api/app/Modules/MRP/routes.php` | Historical machine archive/restore returned `204/404`. Post-fix trashed-aware restore returned `200`. |
| LIVE-B5-F03 archived mold cannot restore | Medium | FIXED | `api/app/Modules/MRP/routes.php` | Historical mold archive/restore returned `204/404`. Post-fix archived retired mold restored with `200`. |
| LIVE-B5-F04 finalized 8D PDF returns 500 | High | FIXED | `api/resources/views/pdf/complaint-8d.blade.php` | Historical finalized complaint PDF returned `500`. Post-fix same finalized complaint returned `200 application/pdf`; complaint/NCR lifecycle remained closed. |
| LIVE-B5-F05 manual MRP response leaks machine integer ID | High | FIXED | `api/app/Modules/MRP/Resources/MrpPlanningResponseSerializer.php`; `api/app/Modules/MRP/Services/CapacityPlanningService.php` | Historical scheduler summary contained `machine_id: 1`. Post-fix stored-run reads contained zero numeric `machine_id`/`mold_id` values. |
| LIVE-B6-F01 QC can approve own PPAP | High | FIXED | `api/app/Modules/Quality/Services/PpapService.php` | Historical QC created, reviewed and approved its own PPAP. Post-fix same-user review returned `403`; independent checker approval remains BLOCKED because no distinct QC checker is seeded. |
| LIVE-B6-F02 RMA PATCH uses full create contract | Medium | FIXED | `api/app/Modules/ReturnManagement/Requests/UpdateReturnRequestRequest.php`; `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php`; `api/tests/Feature/ReturnManagement/ReturnRequestScenarioTest.php` | Historical notes-only draft PATCH returned `422`. Post-fix top-level partial PATCH succeeded and focused HTTP regression passed. |
| LIVE-B7-F01 customer delivery confirmation commits then returns 500 | High | FIXED | `api/app/Modules/SupplyChain/Services/DeliveryService.php` (`show()` eager-load) | Historical customer confirm returned `500` after durable confirmation/invoice handoff. Post-fix confirmation and exact replay returned `200` with confirmed state and four serialized items; the eager-load fix removed the lazy-loading error and the focused customer portal suite passed 4 tests. |
| LIVE-B8-F01 Finance maker deadlocks asset disposal | High | FIXED | `api/database/seeders/DemoAccountSeeder.php`; `docs/SEEDS.md` | Historical Finance maker could not pass its own Finance first step and VP could not act. Distinct `finance2` approved the first step and VP approved the terminal step live; asset reached `disposed` without admin bypass. |
| LIVE-B8-F03 approval-board PO filter returns 500 | High | FIXED | `api/app/Common/Services/ApprovalBoardService.php` | Historical `type=po` board returned `500` for department head, Finance, and VP. Post-fix board reads returned `200`; focused ApprovalBoard suites passed 15 tests/56 assertions. |
| LIVE-B9-F01 customer SO response replay duplicates accepted response | High | FIXED | `api/app/Modules/CRM/Services/SalesOrderResponseService.php`; `api/tests/Feature/B2B/SalesOrderResponsePortalTest.php` | Historical exact portal replay inserted a second accepted response. Post-fix replay returned the existing accepted response and did not increase the row count; focused suite passed 9 tests/48 assertions. Two historical rows remain as evidence. |
| LIVE-B9-F02 CRM cannot resolve already accepted response | High | FIXED | `api/app/Modules/CRM/Services/SalesOrderResponseService.php`; `api/tests/Feature/B2B/SalesOrderResponsePortalTest.php` | Historical CRM accept returned `422` after portal response was already `accepted`. Post-fix CRM accept returned `200`, retained accepted state, and stamped `resolved_at` once. |
| LIVE-B10-F01 payroll compute loses scoped employee and stays processing | Critical | FIXED | `api/app/Modules/Payroll/Listeners/RunPayrollComputationOnRequested.php` | Historical scope preview counted one employee but compute produced zero rows and remained processing; queue failures were recorded. Post-fix disposable probationary monthly employee computed to `computed` with one payroll row and `total_gross=12000.00`; `PayrollComputeHandoffTest` passed. |
| LIVE-B12-F01 legitimate inspection creation returns 500 | High | FIXED | `api/app/Modules/Quality/Services/InspectionService.php` | Historical valid in-process and output-backed outgoing creates returned generic `500`. Post-fix replays returned `200` and reused the existing unique inspection without duplicate rows. |
| LIVE-B12-F02 complaint-to-NCR sequence collision returns 500 | High | FIXED | `api/app/Common/Services/DocumentSequenceService.php` | Historical later valid complaint creates returned generic `500` after two successful NCR handoffs. Post-fix complaint without SO returned `201` with generated NCR after sequence reconciliation; focused sequence suite passed 4 tests/16 assertions. |
| LIVE-B13-F01 supplier PO response replay duplicates response/notifications/outbox | High | FIXED | `api/app/Modules/Purchasing/Services/SupplierResponseService.php`; `api/tests/Feature/B2B/SupplierResponsePortalTest.php` | Historical supplier replay created two accepted responses, 12 duplicate recipient notifications, and a second `sent` publication. Post-fix replay returned the existing response without another response, notification, or outbox publication; focused suite passed 12 tests/71 assertions. Historical rows remain. |

### Non-Confirmed Finding Records

- `LIVE-B1-F01` was a FALSE POSITIVE. The department-head employee list returned the intended 86 department-scoped rows, not all 200 employees. No fix was required.
- `LIVE-B8-F02` is OBSERVED, not a confirmed product failure. Approval notifications were not observable in the queried recipient feeds, but the run did not reconcile queue/outbox state sufficiently to prove delivery loss.
- `LIVE-B10-F02` is BLOCKED environment evidence. Welcome/password-reset mail jobs failed against the local mail transport, while in-app `email.delivery_failed` records and technical recovery were available. External inbox delivery was not tested.

## Remaining Fixture And Process Blocks

- S02 public marketing/recruitment remains NOT RUN.
- S13 employee A versus employee B self-service ownership remains BLOCKED; only own-session APIs and records were available.
- S14/A01 leave request lifecycle needs a disposable employee with usable balance. Existing Batch 2 balance had no usable row; the later L03 disposable employee did have balances, but the separate S14/A01 fixture assertion was not rerun as a complete independent pack.
- S33 delivery proof/customer confirmation write needs a role with the relevant write permission and a disposable outgoing-passed delivery.
- S40/L12 outgoing inspection, CoC, delivery, failed-QC block, invoice, collection, and AR GL remain BLOCKED behind a valid output-backed outgoing inspection and complete delivery fixture. The original inspection 500 is fixed, but the full downstream chain was not rerun to completion in the available evidence.
- A10/L04 payroll compute-to-disbursement remains BLOCKED in the original chain evidence. A focused post-fix compute verification exists, but Finance approval, finalization, GL, bank file, payslip notice, disbursement proof, and final-pay dependency were not all completed in one clean end-to-end run.
- A13/L15 supplier AP requires an accepted GRN and linked supplier invoice. The selected PO has a draft GRN and no linked bill.
- A16 high-value stock adjustment checker was not armed by live threshold/configuration; the disposable adjustment auto-approved and created stock/GL evidence.
- A17 PPAP needs all 18 elements and a distinct QC checker. Same-user review denial is fixed and live-tested; independent approval is not claimed.
- L07-L09 need a shortage-capable disposable MRP source, a sent PO, accepted/rejected GRN targets, incoming QC branches, standard bill, and AP payment target.
- L17 needs explicit manual-required stock GL, incoming QC, payroll GL/bank, supplier AP, and complaint/NCR recovery targets. The production receipt retry was exercised once and correctly returned a prerequisite `422`; zero eligible targets were not treated as failure.
- Supplier A/B, customer A/B, and driver own-versus-other comparisons remain BLOCKED because only one portal tenant of each type and one driver assignment were seeded.
- Portal password reset completion remains BLOCKED without a safe test mail/token channel.
- Maintenance assignment/schedule management and condition-reading threshold routes remain unavailable or scope-cut; no missing maintenance role was invented.

## Process Improvements

### Implemented During The Audit Or Fix Work

- Role fixtures now distinguish read, create, mutate, retry, approval, and confirmation expectations.
- SPA paths and API paths are recorded separately, including `/hr/leaves` versus `/api/v1/leaves/requests`.
- Permission-derived dashboard redirects are accepted as routing outcomes; API-only paths are not used as SPA paths.
- Actor sessions are grouped and serial, with explicit logout/cookie clearing; auth throttle responses are not cascaded into product findings.
- Multipart and method-spoofed requests are handled as first-class API checks.
- Disposable IDs and resource-type cleanup are retained across continuation runs; historical residuals are explicitly preserved rather than silently deleted.
- Expected status variants, safe same-resource idempotency, terminal denial, stale ownership, and wrong-role denial are distinct ledger outcomes.
- Cross-module source hashes are resolved under the owning role; nested handoff fields and operation-specific retry actors are recorded.
- Direct API error envelopes redact framework exception details, paths, line numbers, and traces.
- The application fixes recorded above add active-position uniqueness, payment idempotency, trashed-aware restores, HashID reference serialization, PPAP SoD, portal response idempotency, payroll listener resolution, inspection idempotency, complaint sequence reconciliation, and qualified approval-board source projections.
- Admin operations health exposes non-zero failed-job visibility; the B13 read-only reconciliation matched `Failed Jobs=32`.

### Deferred Or Required Before The Next Full Audit

- Seed a reusable manifest with two self-service employees, leave balance, complete PPAP, distinct QC checker, Finance checker/threshold adjustment, sent supplier PO, accepted GRN, outgoing inspection/output, delivery/proof, standard AR/AP, and manual recovery targets.
- Seed two supplier tenants, two customer tenants, and at least two driver assignments for horizontal row-scope tests.
- Provide a safe fixture reset or disposable-record protocol that preserves audit evidence and does not require admin business bypass.
- Add a test mail capture/token handoff for portal and employee password flows.
- Add a unified notification/outbox/listener ledger with recipient aliases, action links, delivery state, and email failure state as separate fields.
- Add a queue-health/result probe that distinguishes zero eligible work, worker failure, timeout, and successful completion before checker assertions.
- Normalize scheduler responses around a `work_order_id` keyed resource and declare PPC schedule ownership separately from Production confirmation ownership.
- Add fixture preflight gates for shortage, threshold, import, standard invoice, outgoing QC, source-backed RMA, manual recovery, and completed-period depreciation.
- Document the customer and supplier response state machines and use one explicit response-cycle idempotency contract under both portal guards.
- Decide whether reserved approval definitions should be hidden from live approval catalogs and document owners for the remaining maker-checker gaps.

## Three-Chain Completion Truth Table

This table reports only what the evidence proves. A PASS at one stage does not imply the downstream chain is complete.

| Chain | Stage | Evidence truth | Completion status |
|---|---|---|---|
| H2R | Hire foundation | L01 created disposable department/position/employee, provisioned account, onboarding state and leave balances | PASS |
| H2R | Attendance | L02 covered shifts, late/undertime, night differential, holiday premium, auto-OT, CSV replay | PASS |
| H2R | Employee requests/approvals | L03 leave, OT, and company loan approval paths completed with SoD and balance evidence | PASS |
| H2R | Payroll close | L04/A10 original chain stayed processing/blocked; post-fix compute produced one row, but approve/finalize/disburse/GL/bank/payslip proof was not completed as one chain | BLOCKED, not complete |
| H2R | Separation/final pay | L05 clearance completed and final pay computed, but finalization was correctly blocked because covering payroll was not disbursed | PARTIAL/BLOCKED, not complete |
| **H2R** | **Hire to retire overall** | Hire, attendance, and requests pass; payroll close and final pay terminal handoff remain unverified | **NOT COMPLETE** |
| P2P | MRP shortage bridge | L06 confirmed SO linked to queued MRP plan and planned WO; live materials were sufficient, so no shortage/auto-PR was created | PASS through planning; shortage branch BLOCKED |
| P2P | PR to PO | L07 had no legitimate shortage source; A05/A06 separately proved manual threshold PR/PO approval and SoD, but queued conversion was not claimed | BLOCKED as long-chain handoff |
| P2P | Import and receipt | L08 had no source sent PO in L06-L09; B4/B13 separately exercised GRN records, but not the requested shortage-derived chain | BLOCKED |
| P2P | Inventory and AP | L09 had no accepted GRN source; supplier AP and payment recovery also lacked a safe linked target | BLOCKED |
| **P2P** | **Procure to pay overall** | Planning and isolated procurement/receipt controls pass, but the shortage-to-AP chain is not evidenced end to end | **NOT COMPLETE** |
| O2C | Sales order and planning | L10 created/confirmed SOs, customer response, MRP plan, scheduler handoff, and Production ownership correction | PASS |
| O2C | Production and in-process QC | L11 production lifecycle, output, material issue, in-process inspection, mold shots, scrap, and OEE passed after inspection fix | PASS with finished-goods receipt manual-required |
| O2C | Outgoing to collection | L12 is blocked at output-backed outgoing inspection/CoC and therefore has no delivery, invoice, collection, or AR GL proof | BLOCKED, not complete |
| O2C | Quality feedback | L13 complaint-origin scrap/rework NCR, CAPA, 8D, closure and Pareto passed; later disposition branches need further fixtures | PARTIAL PASS |
| O2C | Portal/customer loop | L16 customer-owned reads, response replay, proof access, delivery confirmation replay and complaint/RMA reads passed; A/B tenant comparison blocked | PASS scoped, not full tenant proof |
| **O2C** | **Order to cash overall** | SO/planning/production/in-process quality pass, but outgoing QC through collection is not evidenced | **NOT COMPLETE** |

No chain is reported as fully complete. The audit proves substantial stage controls and post-fix behavior without overclaiming the missing downstream stages.

## Test Commands And Results

The report-writing session did not run Docker, a browser, tests, or subagents. The following are documented results from the overnight evidence and fix-verification records, not commands rerun while producing this report:

- Live E2E execution used Playwright Firefox serial runs against `http://localhost`; no broad browser suite or concurrent worker was used.
- No broad PHPUnit suite was run. The audit explicitly records no broad PHPUnit run in each live batch.
- `PositionIntegrityTest` passed, including the permission-denial error-redaction assertion.
- `PayrollComputeHandoffTest` passed; post-fix live compute produced one row and `total_gross="12000.00"`.
- Focused ApprovalBoard suites passed 15 tests / 56 assertions.
- Focused customer portal confirmation suite passed 4 tests; customer response suite passed 9 tests / 48 assertions.
- Focused supplier response suite passed 12 tests / 71 assertions.
- Focused complaint-sequence suite passed 4 tests / 16 assertions.
- The live queue was observed, not reset. B10 records failed payroll listener jobs and local mail failures; B13 operations health showed `Failed Jobs=32`.
- No test result in this report should be read as evidence that the full 1900-test suite, scheduler, Docker deployment, or all queue paths passed.

## Residual Database Records And Cleanup

The JSON ledgers contain 23 residual manifest entries. They are manifest entries, not a unique-row count: some IDs recur across continuation workers and historical fix evidence. The residual database is intentionally non-empty.

### Cleanup Completed Or Reconciled

- Batch 2 disposable departments, positions, employees, training/skills, shifts, attendance, leave type, profile request, and recruitment application were archived, rejected, or deleted where the route allowed it. Duplicate position rows were deleted immediately during reproduction; post-fix duplicate attempts created no row.
- Batch 3 invalid adjustments were rejected, the prior duplicate loan was settled, AP payment rows were voided, bill aggregates returned to unpaid/zero paid, vendor/account rows were archived or inactivated, and no seeded finalized financial record was changed.
- Batch 4 disposable category/UOM/supplier links were restored for verification, disposable stock/MRB cleanup was completed where possible, and child warehouse/location rows were retained only when movement references prevented deletion.
- Batch 5 shipments, documents, containers, vehicles, customers, machines, and molds were archived where possible. Terminal SO/WO/inspection/complaint/NCR/calibration/plan/run evidence remains.
- Batch 6 disposable assets were archived; maintenance WOs, PPAPs, forecasts, and RMAs remain as explicit audit evidence. No blocked downstream stock, replacement PO, credit note, or NCR effect was manufactured.
- Batch 7 disposable portal access users were deactivated. Seeded portal users remained active. The delivery confirmation that historically returned `500` remains server-confirmed as historical state evidence.
- Batch 8 disposable approval/rejection/cancel records were retained; no admin repair was used to erase the SoD evidence. The fixed asset disposal path was later completed with `finance2` and VP.
- Batch 9 payroll period was force-unlocked to draft during technical cleanup, the budget was closed as terminal evidence, listings and schedules retain final states, and the historical customer response rows remain for replay evidence.
- Batch 10 the disposable employee account was deactivated after clearance; the original failed payroll processing evidence and completed-but-not-finalized clearance remain.
- Batch 11 the confirmed disposable SO, active MRP plan, and planned WO remain because no safe rollback removes the linked planning evidence.
- Batch 12 confirmed SOs, customer-confirmation response, MRP plan, closed WO/output, complaint/NCR pairs, and completed maintenance MWO remain. The finished-goods output remains `manual_required` with no receipt movement.
- Batch 13 PO/dispatch/shipment/document/draft-GRN evidence remains. Historical duplicate supplier responses, notifications, and outbox publications remain immutable audit evidence; the post-fix replay did not add another one.

### Important Retained Residuals

- Payroll: one original processing/draft evidence period and a completed clearance whose final pay was computed but not finalized because payroll was not disbursed; the post-fix compute verification is separate.
- Financial: disposable partial invoice, applied credit note, reversed JEs, closed budget, voided payment rows, and a paid disposable loan remain as audit evidence with aggregate effects reconciled.
- Procurement: approved/acknowledged disposable PR/PO evidence, one draft GRN, one accepted GRN from another batch, supplier dispatch/shipment/document rows, and historical supplier response duplicates remain.
- Production/quality: confirmed SOs, MRP plans, WOs, output `2 good + 1 reject`, closed NCR/complaint pairs, PPAPs, calibration, and manual-required finished-goods receipt evidence remain.
- Portal: inactive disposable portal access users, one draft customer order, open disposable complaint, customer response/delivery proof, and one supplier PO tenant record remain; seeded tenant records were not cross-mutated.
- Failure visibility: the database retained 32 failed jobs at the B13 read-only reconciliation, matching the admin operations-health KPI. This is an operational residual requiring triage, not a claim that all 32 are product defects.

## Recommended Next Actions

### Critical

1. Complete a fixture-safe H2R rerun from payroll compute through Finance approval, finalization, GL, bank file, payslip, disbursement, and final-pay finalization. Keep the successful post-fix one-row compute evidence separate from full-chain completion.
2. Complete an O2C rerun with a valid output-backed outgoing inspection, AQL pass and fail branches, CoC, delivery, customer confirmation, invoice, collection, and AR GL. Do not bypass the Quality gate or use a seeded delivered record.
3. Seed and run the complete P2P shortage-to-AP path: shortage, consolidated PR, threshold PO, sent PO, GRN, incoming QC pass/fail, stock/WAC, three-way match, bill, payment, and replay.
4. Triage the 32 failed jobs with redacted server-side categories and distinguish payroll listener failures, email transport failures, and any remaining product failures.

### High

1. Add disposable two-tenant supplier/customer fixtures and a second driver assignment for horizontal isolation evidence.
2. Add the complete PPAP 18-element fixture and a distinct real QC checker; rerun independent review and approval.
3. Add explicit high-value stock-adjustment and asset-disposal checker fixtures with threshold assertions and one-journal expectations.
4. Add a safe test mail/token channel for welcome, reset, and portal password completion.
5. Add manual-recovery fixtures for incoming QC, stock GL, payroll GL/bank, supplier AP, and complaint/NCR retry, each with a documented cleanup route.
6. Re-run the fixed findings under concurrency or exact HTTP replay where the original impact was duplicate financial, portal-response, notification, or outbox effects.

### Medium

1. Make the fixture manifest mandatory and preflight cross-module source hashes, active price agreements, threshold state, inspection specs, source quantities, and cleanup paths before mutation.
2. Unify supplier and customer response-cycle idempotency semantics and document accepted terminal/idempotent status variants.
3. Separate approval board, exception queue, notification feed, outbox/listener, and email-delivery assertions in the audit schema.
4. Normalize scheduler and nested handoff resources around operation-specific ownership and explicit recovery actors.
5. Decide whether reserved workflows should be hidden from live approval catalogs and assign business owners for payroll, PPAP, maintenance, and direct-action process gaps.
6. Run the full PHPUnit and browser suites, Docker deployment checks, and scheduler/queue verification in a separate controlled verification window. They were intentionally not run for this report.

## Worktree Snapshot At Report Review

Before this report was created, `git status` showed 60 tracked modified files and 298 untracked paths. The tracked diff included application code, tests, module audit files, `docs/SEEDS.md`, and SPA test configuration; untracked paths included the live audit runners/artifacts and audit-related migrations/tests. This report adds only `audit/live-2026-09-14/FINAL-REPORT.md`. No existing source, module audit, or application file was modified while producing this report.

## Post-Fix Closeout

The overnight fix pass is closed against the confirmed product findings. All 26 confirmed findings from B2-B13 are now marked `FIXED` with live or focused regression evidence. Historical failures, duplicate rows, notifications, and outbox publications remain in the report and database where they are required audit evidence; they are not current product failures.

| Batch | Closeout result | Current evidence |
|---|---|---|
| B2 | Position uniqueness and error redaction fixed | Active position duplicate replay returns field-keyed `422` with no second row; wrong-role API errors no longer expose exception, file, line, or trace details. |
| B3 | Financial idempotency, decimal validation, and CRM restore fixed | Loan and AP payment replays require a key and reuse the original effect; over-precision payroll adjustment returns `422`; CRM owner archive/restore resolves through the CRM route. |
| B4 | Restore and HashID serialization fixed | Category, UOM, and approved-supplier restores are trashed-aware; polymorphic stock movement references contain no raw integer IDs. |
| B5 | Quality and MRP defects fixed | Calibration partial PATCH, machine/mold restore, finalized 8D PDF, inspection parameter handling, and nested MRP machine/mold serialization regressions are closed. S33 delivery proof/customer confirmation remains fixture/permission-blocked. |
| B6 | PPAP self-review and RMA partial PATCH fixed | Same-user PPAP review is denied; top-level notes-only RMA PATCH succeeds. A distinct QC checker and source-backed downstream RMA effects remain blocked. |
| B7 | Customer delivery response eager-load fixed | Confirmation and exact replay return `200` with the confirmed resource and four serialized items. Tenant A/B, notification delivery, and write-fixture checks remain unproven. |
| B8 | Finance2 asset-disposal checker and approval-board projection fixed | `finance2` completed the Finance step and VP completed disposal without admin bypass; filtered PO approval-board reads return `200` for business actors. |
| B9 | Customer response replay and CRM acknowledgement fixed | Portal response replay returns the accepted response without a new row; CRM acknowledges the already-accepted response idempotently and stamps `resolved_at` once. |
| B10 | Queued payroll listener fixed and live one-row compute verified | A disposable scoped probationary monthly employee computed asynchronously to `computed` with one payroll row and `total_gross="12000.00"`. Full payroll close/disbursement was not rerun. |
| B12 | Inspection idempotency and complaint/NCR sequence fixed | Legitimate inspection replay reuses the unique inspection; later valid complaints allocate collision-free NCR numbers and return `201` with the NCR handoff. |
| B13 | Supplier response replay fixed | Exact supplier response replay reuses the existing response and creates no second response, notification, or outbox publication; `SupplierResponsePortalTest` passed 12 tests / 71 assertions. |

### Focused Regression Totals

The focused totals documented in the audit evidence are:

- Supplier response suite: **12 tests / 71 assertions**.
- Approval-board suites: **15 tests / 56 assertions**.
- Customer SO response suite: **9 tests / 48 assertions**.
- Complaint/NCR sequence suite: **4 tests / 16 assertions**.
- Customer portal delivery-confirmation suite: **4 tests**; the audit notes do not state an assertion total.
- `PositionIntegrityTest`, `PayrollComputeHandoffTest`, and `ReturnRequestScenarioTest` also passed their recorded focused regressions; no test/assertion totals are invented where the evidence does not state them.

These are focused results only. No browser, Docker command, broad test suite, or subagent was run while writing this report. The full 1900-test suite, deployment checks, scheduler coverage, and all queue paths remain outside this report's verification.

### Remaining Proof Work

- **H2R is not fully proven:** the payroll-to-disbursement path still lacks one clean run through Finance approval, finalization, GL, bank file, payslip notice, disbursement proof, and final-pay finalization. The one-row compute is a focused/live fix verification, not full chain completion.
- **P2P is not fully proven:** no explicit shortage-derived PR through accepted GRN, incoming QC, stock/WAC, three-way match, AP bill, payment, and replay was available. L07-L09 stopped at the explicit no-shortage/source-fixture gate.
- **O2C is not fully proven:** outgoing inspection, CoC, delivery, customer confirmation, invoice, collection, and AR GL were not completed in one run. The output-backed outgoing inspection and delivery fixture gate remained explicit, so no downstream pass was manufactured.
- Seed two supplier tenants, two customer tenants, and a second driver assignment for horizontal row-scope proof.
- Seed reusable leave-balance, complete PPAP/independent QC checker, threshold stock-adjustment, accepted-GRN/AP, outgoing-QC/delivery, manual GL/bank, and complaint/NCR recovery fixtures.
- Provide safe mail/token capture, fixture preflight/reset or disposable cleanup protocol, and queue/outbox/notification ledgers that distinguish eligible work, worker failure, dispatch, publication, and delivery.

**Final unresolved product-finding count: 0.** The remaining items are explicit process, fixture, environment, or unrun-scope gaps, not unresolved confirmed product findings.
