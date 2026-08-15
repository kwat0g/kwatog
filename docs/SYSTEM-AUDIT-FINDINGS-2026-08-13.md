# Ogami ERP — System Audit Findings Register

**Audit date:** 2026-08-13  
**Scope:** implemented backend/API, SPA contracts, persistence, cross-module
workflows, and existing automated-test evidence.  
**Method:** source and test inventory; no production or external-provider claim
is implied. File/line references are to the current worktree.  
**Priority:** P0 = immediate authorization/financial safety; P1 = material
money, security, compliance, or traceability risk; P2 = important control,
operability, or lifecycle weakness; P3 = polish, maintainability, or explicitly
policy-dependent follow-up.

## Priority summary

| Priority | Finding IDs | Executive disposition |
|---|---|---|
| P0 | F-001 | Fix before granting ordinary users self-service delegation. |
| P1 | F-002–F-010, F-030, F-038 | Security, money, statutory, provenance, idempotency, and production-readiness acceptance tests required. |
| P2 | F-011–F-024, F-026–F-029, F-031–F-033 | Harden current control, security, UX, schema, and audit gaps. |
| P3 | F-025, F-034–F-037 | Structural cleanup, regression governance, and audit-meta follow-up. |

## Closure overlay (authoritative current worktree)

The authorized implementation pass is complete for every repository-controlled
finding. The canonical lifecycle and acceptance-manifest gates classify the
register as follows:

| Disposition | Findings | Current evidence boundary |
|---|---|---|
| **Verified — 36** | F-001–F-029, F-031, F-033–F-038 | Implemented controls have bounded source, constraint, reconciliation, or focused regression evidence. |
| **Mitigated — 1** | F-032 | The cited responsive surfaces and static/type gates are repaired; an authenticated narrow-browser run with representative records is still absent. |
| **Open — 1** | F-030 | Local release-contract tooling exists, but a production-like restore/deploy run and retained target-environment artifacts cannot be supplied by source changes. |
| **Decision required — 0** | — | All seven recommended safe defaults were adopted and encoded. |

`SYSTEM-AUDIT-FINDING-LIFECYCLE.json` is authoritative for per-finding status,
owner, policy disposition, evidence scope, and regression proof.

## Intermediate remediation overlay (historical)

The findings below preserve the intermediate audited baseline for traceability.
They are superseded by the closure overlay and lifecycle registry above and do
not represent unfinished work.

| Finding | Intermediate status | Verification at that stage |
|---|---|---|
| F-001 | **Remediated:** delegation is limited to the delegator's current exact role and revalidated when used. | 9 focused tests / 13 assertions. |
| F-002 | **Partially remediated:** manual-payment and payroll-deduction aggregate mutations now serialize on authoritative loan rows with deterministic lock ordering. External payment idempotency and ledger reconciliation remain. | 2 focused tests / 10 assertions. |
| F-003 | **Remediated in service:** reset-token consumption and password/history mutation are one locked transaction. A real two-connection race harness remains a release-hardening test. | 9 focused tests / 24 assertions. |
| F-004 | **Remediated in service:** failure, expired-lock reset, and success-reset mutations use the authoritative locked user row. A real two-connection threshold test remains. | 20 focused tests / 82 assertions. |
| F-006 | **Remediated:** outgoing QC releases an exact work-order output batch; delivery requires explicit passed output provenance and serializes finite accepted-quantity reservations. Legacy WO/product-only inspections cannot authorize new deliveries. | 24 focused tests / 48 assertions, including multi-output replay, cross-lineage rejection, partial capacity, and cancellation reuse. |
| F-009 | **Remediated:** output keys and canonical payload fingerprints are durable per work order behind a database unique constraint; cache is performance-only. | 6 focused tests / 14 assertions. |
| F-011 | **Remediated in service:** each payroll compute claim/takeover receives a durable fencing token propagated through outbox, listener, job, employee transaction, failure marker, and terminal release. The stale-run reaper locks and rechecks its candidate so it cannot release a fresh owner. A real paused-worker/two-connection harness remains a release-hardening test. | 32 focused payroll compute tests / 94 assertions; independent fencing/recovery run 21 / 58. |
| F-012 | **Remediated in service:** every leave submission serializes on its authoritative employee row before evaluating active date/half-day overlap, closing the empty-gap race. | 5 focused tests / 6 assertions. |
| F-013 | **Remediated:** one auto-detected overtime row is enforced per attendance employee/date source with a partial unique index; duplicate preflight is non-destructive and replay does not emit another outbox event. | 8 focused tests / 14 assertions. |
| F-015 | **Remediated:** generic immediate stock-adjustment methods are removed. The only threshold/freeze bypass is a stock-count reconciliation command that locks the authoritative session/item, derives its own variance/WAC, and atomically posts the movement, audit row, and adjusted state. | 14 focused tests / 39 assertions; no legacy application callers remain. |
| F-019 | **Remediated:** PostgreSQL enforces one non-void annual 13th-month period and the service serializes creation with a per-year advisory transaction lock. Unsafe pre-existing duplicates fail migration preflight. | 12 focused tests / 51 assertions. |
| F-021 | **Remediated:** PostgreSQL `NULLS NOT DISTINCT` uniqueness covers total and customer-specific forecast keys; canonical writes take a stable transaction advisory lock and migration preflight refuses destructive deduplication. | 3 focused tests / 10 assertions. |
| F-023 | **Remediated:** the supplier delivery endpoint now uses a dedicated, tenant-scoped field allowlist and hash IDs. | 1 focused test / 21 assertions; SPA typecheck passed. |
| F-024 | **Remediated:** personal dashboard layout saves/resets serialize on the user row and require the version returned by the last read; stale tabs receive HTTP 409 without overwriting the winning layout. | 13 backend tests / 49 assertions; SPA typecheck and 2 picker tests passed. |
| F-025 | **Remediated:** inventory movement, GRN, and payroll GL writers now post through the canonical journal service; a source contract rejects future direct application mutations and enumerates automated writers. | Contract: 2 tests / 2,677 assertions; affected GL suite: 26 tests / 119 assertions. |
| F-026 | **Remediated:** single and bulk role assignments carry expected-current role hashes, lock users in deterministic order, preserve stale users, and return explicit conflicts while auditing only committed changes. | 3 focused tests / 15 assertions; related role-management suite 10 tests / 25 assertions; SPA typecheck passed. |
| F-027 | **Remediated:** final-pay components, totals, live deductions, recovery allocation, and journal lines now use decimal-string `Money`/BCMath arithmetic with one cent-rounding policy. | 4 focused tests / 23 assertions, including an adversarial cent-level breakdown-to-GL reconciliation. |
| F-029 | **Remediated:** empty configuration fails closed, detail requires an exact header token, and query tokens are ignored. | 6 focused tests / 24 assertions. |
| F-031 | **Remediated:** rejected and skipped approval steps render as distinct terminal states with action context; later steps cannot appear active after rejection. | 2 focused SPA tests; typecheck and ESLint passed. |
| F-032 | **Remediated for the cited surfaces:** PO, GRN, bill/match, invoice, and work-order detail tables have narrow-screen overflow wrappers and explicit content widths without removing actions. | SPA typecheck and 1 focused static regression passed. |
| F-033 | **Partially remediated:** native PostgreSQL checks (and SQLite test guards) now close nine enum-backed money, stock, QC, delivery, and payroll lifecycle columns; migration preflight reports unsupported legacy values without rewriting rows, and enum drift fails a regression. Other module statuses remain inventoried follow-up. | 11 focused tests / 68 assertions plus rollback/reapply proof. |
| F-034 | **Remediated:** the route audit distinguishes 27 explicitly classified scope-cut/superseded clients from 814 matching SPA requests; new unmatched clients and stale manifest entries fail the audit. | Host `npm run audit:api-routes` passes against 1,326 Laravel method/routes. |
| F-036 | **Remediated:** all 38 findings are represented in a machine-validated lifecycle registry with status, owner, evidence date/scope, and explicit policy-decision metadata; CI rejects missing, duplicate, stale, or malformed records. | `node scripts/verify-audit-finding-lifecycle.mjs` passes for 38 findings. |
| F-037 | **Remediated:** the stale `Edge` registry entry was removed and registry/directory drift now fails a provider test. | 1 focused test / 3 assertions. |
| F-038 | **Remediated:** effective-dated BIR monthly withholding brackets now distinguish the official 2018–2022 and 2023+ schedules; migration preserves custom effective dates and restores the exact legacy rows on rollback. | 17 focused tests / 34 assertions. |

## Findings register

### F-001 — Arbitrary role-scoped approval delegation grants privilege escalation

- **Module / feature:** Admin / approval delegation.
- **Related modules:** Common ApprovalDelegation and ApprovalService; Purchasing, Leave, Payroll, and other approval consumers.
- **Category:** Authorization / segregation of duties.
- **Affected roles:** Every authenticated ordinary user; any delegate; all approval-role owners.
- **Current Behavior:** `POST /approval-delegations` is available to any authenticated user (`api/app/Modules/Admin/routes.php:206-219`; `StoreApprovalDelegationRequest.php:17-34`). The request accepts any existing `roles.slug` (`:31`). The service pins the delegator to the actor, but stores the submitted role without verifying that the actor holds it (`ApprovalDelegationService.php:42-74`). Explicit role rows are returned as active delegates without checking the delegator's role (`Common/Models/ApprovalDelegation.php:82-103`); `ApprovalService::userMayActFor()` trusts that result (`:146-159`).
- **Problem:** A normal employee can submit `role_slug=department_head` (or another approval role), nominate another user, and cause that user to pass the approval role check. The existing blanket-delegation path is correctly constrained, but explicit role delegation is not.
- **Real-world scenario:** An ordinary user creates a time-window delegation for a friend as `finance_officer` (or `system_admin`); the friend then approves a payroll or purchase action without ever holding that role.
- **Root Cause:** Input validates role existence, not actor authority; the service only protects `delegator_user_id`, not `role_slug`.
- **Recommended Improvement:** Permit same-role delegation only when the delegator currently holds that exact role; revalidate the delegator's role and the delegate's authority at approval time (not only when the row is created); audit role and effective window; deny self-approval and cross-domain escalation.
- **Ideal Process:** A user may cover only the exact authority they currently possess, and every approval revalidates that authority against the current role assignment.
- **New Feature/Module Required:** No new module; add delegation-authority policy and negative authorization tests.
- **Cross-Module Impact:** Approval chains in Purchasing, HR/Leave, Payroll, Loans, Returns, and Accounting.
- **Evidence:** `api/app/Modules/Admin/routes.php:206-219`; `api/app/Modules/Admin/Requests/StoreApprovalDelegationRequest.php:17-34`; `api/app/Modules/Admin/Services/ApprovalDelegationService.php:42-74`; `api/app/Common/Models/ApprovalDelegation.php:82-103`; `api/app/Common/Services/ApprovalService.php:146-159`. Existing `api/tests/Feature/Approvals/ApprovalDelegationTest.php:69-183` proves valid delegation and self-approval protection, but does not attempt an unauthorized explicit role.
- **Priority:** P0.
- **Impact:** Critical unauthorized approvals and audit/SOD failure.
- **Complexity:** S-M.

### F-002 — Loan payment and payroll deduction aggregates are unlocked read-modify-write

- **Module / feature:** Loans / record payment and Payroll / loan deduction.
- **Related modules:** Payroll, HR final pay, Accounting.
- **Category:** Financial concurrency / ledger integrity.
- **Affected roles:** Payroll operators, loan officers, finance, employees receiving deductions.
- **Current Behavior:** At the audited baseline, `LoanService::recordPayment()` checked the passed model and wrote `total_paid`, balance, remaining periods, and status without reloading/locking the loan. Payroll's loan deduction path similarly read loans without a lock and saved aggregate fields; the original source inventory identified `PayrollCalculatorService.php:822-870`.
- **Problem:** Two payments or a payment plus payroll deduction can both calculate from the same balance. One payment row may exist while the aggregate loses the other amount; overpayment/status decisions can be wrong.
- **Real-world scenario:** A payroll run posts a deduction while an HR operator records a cash payment; the employee's loan remains overstated or understated and a later final-pay deduction is incorrect.
- **Root Cause:** Payment rows and aggregate balance are not serialized by the same authoritative loan-row lock or conditional version update.
- **Recommended Improvement:** Lock the loan row inside every money mutation, re-read status/balance, enforce an idempotency key for externally retried payments, and reconcile aggregate totals from immutable payment/deduction rows.
- **Ideal Process:** One authoritative loan ledger serializes all payment sources and exposes a reconciliation exception if aggregate and detail diverge.
- **New Feature/Module Required:** Loan ledger/reconciliation service (small extension of Loans).
- **Cross-Module Impact:** Payroll, final pay, employee self-service, Accounting statements.
- **Evidence:** The baseline source locations were `LoanService.php:288-327` and `PayrollCalculatorService.php:822-870`. Current closure evidence is recorded in the lifecycle registry and `LoanPaymentSerializationTest`.
- **Priority:** P1.
- **Impact:** High financial misstatement and employee deduction disputes.
- **Complexity:** M.

### F-003 — Password-reset token is not single-use under concurrent requests

- **Module / feature:** Auth / password reset.
- **Related modules:** User sessions, audit log, password history.
- **Category:** Authentication race / account takeover control.
- **Affected roles:** All users; support/security operators.
- **Current Behavior:** A valid unused token is selected before the transaction; the transaction updates the password and marks `used_at`, but the token row is not locked/re-read (`api/app/Modules/Auth/Services/PasswordResetService.php:60-118`).
- **Problem:** Two requests can validate the same token before either commits and both change the password.
- **Real-world scenario:** A leaked reset link is submitted simultaneously by the legitimate user and an attacker; both requests pass validation and the final password is timing-dependent.
- **Root Cause:** Check-then-use without `lockForUpdate()` or an atomic `UPDATE ... WHERE used_at IS NULL` winner condition.
- **Recommended Improvement:** Lock/re-read token inside the transaction or atomically consume it; return one success and deterministic failure for all replays; record one password-history mutation.
- **Ideal Process:** A reset credential has exactly one consuming transition.
- **New Feature/Module Required:** No new module; token-consumption primitive and race test.
- **Cross-Module Impact:** Auth sessions, password history, audit events.
- **Evidence:** `api/app/Modules/Auth/Services/PasswordResetService.php:60-118`; `api/tests/Feature/Auth/PasswordResetTest.php:84-230` proves sequential reuse rejection only.
- **Priority:** P1.
- **Impact:** High account compromise risk.
- **Complexity:** S.

### F-004 — Login lockout counter can lose increments under concurrency

- **Module / feature:** Auth / failed-login tracking and lockout.
- **Related modules:** Rate limiting, audit events, user sessions.
- **Category:** Authentication concurrency.
- **Affected roles:** All users; security operators.
- **Current Behavior:** Failed login increments the in-memory model and saves without a row lock or atomic increment (`api/app/Modules/Auth/Services/AuthService.php:66-81`).
- **Problem:** Concurrent failures can overwrite one another, delaying or avoiding threshold lockout; a concurrent successful login can reset a counter after a failure.
- **Real-world scenario:** A credential-stuffing burst arrives across workers; the account remains usable beyond the configured five failures.
- **Root Cause:** Unserialized read-modify-write on `failed_login_attempts` and lock fields.
- **Recommended Improvement:** Use an atomic increment/threshold update or locked authoritative row; define ordering for failure versus success and emit one threshold audit event.
- **Ideal Process:** The lockout threshold is invariant regardless of worker interleaving.
- **New Feature/Module Required:** No new module; auth counter primitive and parallel test.
- **Cross-Module Impact:** Audit/alerting and session issuance.
- **Evidence:** `api/app/Modules/Auth/Services/AuthService.php:66-81`; `api/tests/Feature/Auth/AuthSecurityTest.php:102-165` and `AuthEventsAuditTest.php:107-128` cover sequential behavior, not parallel failures.
- **Priority:** P1.
- **Impact:** High weakening of brute-force protection.
- **Complexity:** S-M.

### F-005 — 13th-month computation omits withholding-tax exemption/excess handling

- **Module / feature:** Payroll / 13th-month run.
- **Related modules:** BIR statutory exports, payroll calculator, final pay.
- **Category:** Statutory payroll / tax.
- **Affected roles:** Payroll, Finance, HR, employees, statutory filing owners.
- **Current Behavior:** The service explicitly records that the ₱90,000 BIR exemption is not implemented (`api/app/Modules/Payroll/Services/ThirteenthMonthService.php:132-145`) and creates 13th-month rows with zero withholding/deductions (`:212-229`). Tests assert net equals gross and zero deductions (`api/tests/Feature/Payroll/ThirteenthMonthTest.php:114-149`).
- **Problem:** If taxable 13th-month excess must be withheld, the payroll and filing outputs under-withhold.
- **Real-world scenario:** A high-paid employee receives statutory 13th-month pay above the exemption; the employer remits too little and must true-up later.
- **Root Cause:** Intentional product simplification; no threshold/excess tax computation or year-end true-up.
- **Recommended Improvement:** Implement effective-dated BIR threshold/table logic, threshold boundary tests, annualization/true-up, and reconciliation to statutory exports. If policy deliberately excludes this, surface an explicit compliance limitation.
- **Ideal Process:** Taxable excess is computed once, reflected in payslip/GL, and reconciles to annual filing.
- **New Feature/Module Required:** Statutory tax calculation extension; official BIR authority must be confirmed by Sol.
- **Cross-Module Impact:** Payroll, Accounting, BIR exports, final pay.
- **Evidence:** `api/app/Modules/Payroll/Services/ThirteenthMonthService.php:132-145,212-229`; `api/tests/Feature/Payroll/ThirteenthMonthTest.php:114-149`.
- **Priority:** P1 if statutory policy is in scope; otherwise P2 product gap.
- **Impact:** High compliance and employee-net-pay risk.
- **Complexity:** L.

### F-006 — Outgoing QC is not bound to delivery batch/work order or inspected quantity

- **Module / feature:** Quality / outgoing inspection and SupplyChain / delivery.
- **Related modules:** Production outputs, CRM sales order, Inventory lots.
- **Category:** Quality traceability / quantity control.
- **Affected roles:** Quality inspector, warehouse, shipping, production, customer service.
- **Current Behavior:** Delivery accepts an explicitly supplied inspection based on product/stage/passed, or selects the latest passed outgoing inspection by product (`api/app/Modules/SupplyChain/Services/DeliveryService.php:206-236`). `DeliveryItem` has no batch/WO field (`api/app/Modules/SupplyChain/Models/DeliveryItem.php:18-40`), and `Inspection` has no batch field (`api/app/Modules/Quality/Models/Inspection.php:32-39`).
- **Problem:** A passed inspection from another batch/order can satisfy delivery; the system does not consume an inspected quantity ledger.
- **Real-world scenario:** Batch A fails and Batch B passes; a delivery of Batch A selects Batch B's latest product inspection and ships unverified stock.
- **Root Cause:** Product-level/latest-inspection lookup and missing batch/quantity allocation model.
- **Recommended Improvement:** Require inspection → work-order-output/batch lineage, enforce passed available quantity, consume it on partial delivery, and reject cross-SO/product-only inspection matches.
- **Ideal Process:** Every shipped quantity is traceable to one production batch and one passed QC quantity.
- **New Feature/Module Required:** QC allocation/lot traceability ledger.
- **Cross-Module Impact:** Production, Inventory, Quality, SupplyChain, customer traceability.
- **Evidence:** `api/app/Modules/SupplyChain/Services/DeliveryService.php:206-236`; `api/app/Modules/SupplyChain/Models/DeliveryItem.php:18-40`; `api/app/Modules/Quality/Models/Inspection.php:32-39`; existing `api/tests/Feature/Quality/OutgoingQcIdempotencyTest.php:103-240` tests idempotency, not batch allocation.
- **Priority:** P1.
- **Impact:** High recall, customer, and IATF traceability risk.
- **Complexity:** L.

### F-007 — Direct invoice finalization can precede delivery (policy-dependent)

- **Module / feature:** Accounting / invoice finalization; CRM/SupplyChain O2C.
- **Related modules:** SalesOrder, Delivery, collections, credit control.
- **Category:** Lifecycle gate / revenue recognition.
- **Affected roles:** Sales, AR, Finance, warehouse.
- **Current Behavior:** Sales orders can transition to invoiced from confirmed/in-production (`api/app/Modules/CRM/Services/SalesOrderService.php:38-45`). Invoice creation/finalization accepts a linked SO without requiring a delivered delivery (`api/app/Modules/Accounting/Services/InvoiceService.php:98-128,180-190,252-258`), and direct invoice routes are exposed (`api/app/Modules/Accounting/routes.php:103-113`).
- **Problem:** If policy is “invoice only after delivery,” the direct path bypasses the delivery gate. Prebilling may be legitimate, but the code does not make it a distinct document/policy path.
- **Real-world scenario:** AR finalizes an invoice for a confirmed order that is still in production; revenue and receivable exist before shipment.
- **Root Cause:** Delivery auto-invoice is hardened, but manual invoice service lacks an explicit prebill/delivery policy guard.
- **Recommended Improvement:** Enforce delivered quantity for standard invoices, or add explicit prebill/pro-forma type with approval, accounting treatment, and later conversion/reversal rules.
- **Ideal Process:** Standard invoice state is downstream of delivery; permitted prebilling is separately classified and approved.
- **New Feature/Module Required:** Invoice provenance/policy guard (or prebill document type).
- **Cross-Module Impact:** CRM status, delivery, AR, GL, collections.
- **Evidence:** Source above; `api/tests/Feature/SupplyChain/AutoInvoiceOnDeliveryConfirmTest.php:55-221` proves delivery-triggered invoice/retry but no negative direct pre-delivery test.
- **Priority:** P1 if delivery-gated invoicing is policy; otherwise P2 control clarity.
- **Impact:** High revenue, tax, and operational reporting risk.
- **Complexity:** M.

### F-008 — Product-only RMA can create financial credit without stock lineage

- **Module / feature:** ReturnManagement / customer return and credit note.
- **Related modules:** Inventory, Quality, CRM invoice/SO, Accounting.
- **Category:** Financial/inventory traceability.
- **Affected roles:** Customer service, returns, warehouse, Finance, Quality.
- **Current Behavior:** Return lines may contain a product without an inventory item (`api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:89-110`). Credit notes are calculated from returned quantity/product (`:683-736`), while movement resolution can return null for product-only lines (`:940-964,1022-1036`). A test intentionally creates product-only credit and checks only subtotal (`api/tests/Feature/ReturnManagement/ReturnRequestScenarioTest.php:245-265`).
- **Problem:** A customer may receive credit without a stock movement, source invoice/SO line, batch, or explicit non-stock classification.
- **Real-world scenario:** Support enters a product-only RMA; Finance credits the customer while warehouse inventory never records receipt or disposition.
- **Root Cause:** Product identity is sufficient for credit calculation, but not for inventory lineage; policy is not explicit.
- **Recommended Improvement:** Require invoice/SO item and batch for stockable products; otherwise route through an explicitly non-stock finance-only disposition with mandatory reason and audit.
- **Ideal Process:** Every stockable return either re-enters/quarantines inventory with lineage or is explicitly written off; credit never silently substitutes for receipt.
- **New Feature/Module Required:** RMA line provenance/disposition policy.
- **Cross-Module Impact:** Inventory valuation, Quality inspection, CRM credit, Accounting AR.
- **Evidence:** Source/test refs above; item-backed restock is separately covered by `api/tests/Feature/ReturnManagement/CustomerReturnRestockOnDisposeTest.php:117-220`.
- **Priority:** P1 if stockable products must be traceable; otherwise P2 policy gap.
- **Impact:** High inventory shrinkage and credit abuse risk.
- **Complexity:** M.

### F-009 — Production-output idempotency is cache-only and can duplicate outputs

- **Module / feature:** Production / record work-order output.
- **Related modules:** Inventory finished-goods receipt, Quality, OEE, dashboards.
- **Category:** Idempotency / concurrency.
- **Affected roles:** Shop-floor operator, production supervisor, warehouse, Quality.
- **Current Behavior:** The idempotency key is read from cache and the output ID is written to cache with a 24-hour TTL (`api/app/Modules/Production/Services/WorkOrderOutputService.php:34-39,73-81,211-215`). The database transaction creates a new output after the cache read (`:115-140`).
- **Problem:** Cache eviction, expiry, backend split-brain, or two simultaneous misses can create duplicate output rows and duplicate downstream facts; there is no database uniqueness on `(work_order_id, idempotency_key)` because the key is not persisted.
- **Real-world scenario:** A shop-floor retry crosses a cache restart; both requests create production output and finished-goods receipt before operators notice excess quantity.
- **Root Cause:** Cache is the sole replay key and check/put are not atomic with output insertion.
- **Recommended Improvement:** Persist request key and response/output ID with a unique constraint, or use an atomic cache `add` plus durable DB backstop; make receipt listener idempotent by output ID.
- **Ideal Process:** Retries are safe across cache loss and worker concurrency.
- **New Feature/Module Required:** Production command/idempotency ledger.
- **Cross-Module Impact:** Inventory quantity, Quality counts, OEE, CRM chain status.
- **Evidence:** `api/app/Modules/Production/Services/WorkOrderOutputService.php:34-39,73-81,115-140,211-215`; tests `api/tests/Feature/Production/WorkOrderOutputIdempotencyKeyTest.php:80-102` prove cache-key namespacing/replay only.
- **Priority:** P1.
- **Impact:** High production and inventory overstatement risk.
- **Complexity:** M.

### F-010 — Payroll can be disbursed while GL handoff is still pending (policy-dependent)

- **Module / feature:** Payroll / finalize, GL handoff, disbursement.
- **Related modules:** Accounting, bank-file/payslip generation, Finance controls.
- **Category:** Money control / cross-module ordering.
- **Affected roles:** Payroll operator, Finance reviewer, Treasury, employees.
- **Current Behavior:** `markDisbursed` requires finalized status and proof, but does not require `gl_handoff_status=Posted` (`api/app/Modules/Payroll/Services/PayrollPeriodService.php:910-940`). Finalization stages GL as Pending (`:1049-1135`), and GL posting accepts finalized or disbursed (`api/app/Modules/Payroll/Services/PayrollGlPostingService.php:99-121`).
- **Problem:** Under a strict accounting-before-payment policy, bank disbursement can occur before the liability/expense journal exists. The asynchronous/manual-required design may be intentional; this must be decided rather than inferred.
- **Real-world scenario:** Payroll pays employees while Accounting configuration is broken; the bank file succeeds and GL remains manual-required.
- **Root Cause:** No explicit pre-disbursement GL gate.
- **Recommended Improvement:** Either require Posted/NotRequired before disbursement, or document pending/manual disbursement as allowed and make the warning, owner, SLA, and reconciliation visible.
- **Ideal Process:** Treasury and Finance share an explicit state contract, not an implicit queue assumption.
- **New Feature/Module Required:** Payroll disbursement policy gate/reconciliation report.
- **Cross-Module Impact:** Payroll, Accounting, bank files, cash reconciliation.
- **Evidence:** Source refs above; `api/tests/Feature/Payroll/PayrollPeriodEventsTest.php:66-87,154-200` proves disbursement without GL; `PayrollGlHandoffTest.php:89-264` proves pending/retry/manual states.
- **Priority:** P1 if policy forbids it; otherwise P2.
- **Impact:** High financial close and audit risk.
- **Complexity:** M.

## Strong P2 controls and lifecycle findings

The following findings are material but lower than the confirmed P1 issues, or
have a current mitigation that is not yet complete proof under race, legacy, or
deployment conditions.

### F-011 — Payroll claim ownership has no worker fencing token

- **Module / feature:** Payroll / compute claim and stale recovery.
- **Related modules:** Queue/outbox, Payroll, Accounting GL.
- **Category:** Concurrency/recovery.
- **Affected roles:** Payroll operators and queue workers.
- **Current Behavior:** Claim uses a conditional period update and stale takeover (`api/app/Modules/Payroll/Services/PayrollPeriodService.php:703-810`), with durable outbox staging.
- **Problem:** A stale worker that was paused after claiming may resume after takeover; current evidence does not prove it cannot continue writing payroll rows/status. Claim is period-wide, not worker-token fenced.
- **Real-world Scenario:** Worker A stalls, reaper permits worker B to take over, then A resumes and commits late results.
- **Root Cause:** No per-run owner/lease token checked by every downstream write.
- **Recommended Improvement:** Persist claim token/version, require it on computation writes and outbox completion, and test stale-worker fencing.
- **Ideal Process:** Only the current lease owner can commit; stale workers fail harmlessly.
- **New Feature/Module Required:** Payroll compute lease/fencing metadata.
- **Cross-Module Impact:** Payroll rows, payslips, GL handoff.
- **Evidence:** `PayrollComputeRecoveryTest.php:89-253` covers stale recovery and outbox; no true parallel worker/fencing test found.
- **Priority:** P2. **Impact:** Duplicate or lost payroll results in rare failure windows. **Complexity:** M.

### F-012 — Leave overlap check locks existing rows but has no database exclusion backstop

- **Module / feature:** Leave / submit.
- **Related modules:** Attendance, Payroll.
- **Category:** Concurrency/lifecycle.
- **Affected roles:** Employees, HR, department/HR approvers.
- **Current Behavior:** Service queries overlapping pending/approved rows and uses `lockForUpdate` before insert (`api/app/Modules/Leave/Services/LeaveRequestService.php:132-176`).
- **Problem:** When no overlapping row exists, two transactions can both pass because there is no database exclusion/employee-date claim. Existing tests prove ordinary overlap and duplicate POST behavior but not two empty-gap concurrent inserts.
- **Real-world Scenario:** Two browser tabs submit the same leave dates at once; both become pending and later both are approved.
- **Root Cause:** Application check locks rows that may not yet exist; no serializing employee/date resource.
- **Recommended Improvement:** Add a database-compatible overlap claim/employee advisory lock or serialize on employee row; retain service check for user feedback.
- **Ideal Process:** At most one active overlapping leave interval per employee.
- **New Feature/Module Required:** Leave overlap reservation/constraint strategy.
- **Cross-Module Impact:** Attendance and payroll leave-without-pay.
- **Evidence:** Source refs above; `api/tests/Feature/Leave/HalfDayLeaveOverlapTest.php` and chaos evidence prove sequential behavior only.
- **Priority:** P2. **Impact:** Medium payroll/attendance conflict. **Complexity:** M.

### F-013 — Auto-detected overtime duplicate protection is check-then-insert

- **Module / feature:** Attendance / auto overtime detection.
- **Related modules:** HR self-service, Payroll, Attendance.
- **Category:** Idempotency/concurrency.
- **Affected roles:** Employees, HR, payroll, biometric workers.
- **Current Behavior:** `autoDetectFromAttendance` checks any OT row for employee/date, then creates a row (`api/app/Modules/Attendance/Services/OvertimeService.php:40-45,93-113`).
- **Problem:** Two biometric imports/workers can both observe no row and create duplicate OT requests; no unique employee/date backstop is shown in the service.
- **Real-world scenario:** Punch import replay creates two pending overtime rows and two approval/payroll effects.
- **Root Cause:** Non-atomic existence check and insert.
- **Recommended Improvement:** Unique key for the intended OT grain or durable idempotency/import event key; catch duplicate as replay.
- **Ideal Process:** One source attendance event/day produces one auto-detected OT row.
- **New Feature/Module Required:** Attendance import idempotency key.
- **Cross-Module Impact:** Approval, DTR, Payroll.
- **Evidence:** `api/app/Modules/Attendance/Services/OvertimeService.php:93-113`; lifecycle tests cover cancellation/restore, not concurrent auto-detection.
- **Priority:** P2. **Impact:** Medium duplicate pay/approval risk. **Complexity:** S-M.

### F-014 — PO status becomes received/partially received before incoming QC completes

- **Module / feature:** Inventory GRN creation / Purchasing PO status.
- **Related modules:** Quality incoming QC, supplier performance, AP/3-way match.
- **Category:** Status handoff.
- **Affected roles:** Warehouse, Quality, Purchasing, Finance, supplier portal.
- **Current Behavior:** GRN creation sets `pending_qc`, then updates PO running received total/status before/while QC is pending (`api/app/Modules/Inventory/Services/GrnService.php:97-133,220-243`); `refreshPoStatus` is called at `:225`.
- **Problem:** Consumers reading PO status can treat goods as received even though no quantity is accepted; UI/analytics may show a transient or misleading received state.
- **Real-world scenario:** Purchasing closes a PO or supplier score counts delivery while QC later fails the entire receipt.
- **Root Cause:** “Physically received” and “QC-accepted” share a PO status without a distinct received-pending-QC aggregate.
- **Recommended Improvement:** Separate `received_pending_qc` from accepted quantity; ensure close, bill, supplier KPI, and MRP consumers use the appropriate quantity.
- **Ideal Process:** PO tracks ordered, physically received, QC-accepted, rejected, and open quantities independently.
- **New Feature/Module Required:** PO quantity/status projection or explicit state enum.
- **Cross-Module Impact:** Quality, Purchasing, AP, supplier analytics, inventory.
- **Evidence:** `api/app/Modules/Inventory/Services/GrnService.php:97-133,220-243,1099-1119`; incoming QC acceptance gate at `:680-703`.
- **Priority:** P2. **Impact:** Medium operational/reporting error. **Complexity:** M.

### F-015 — Legacy stock-adjustment methods bypass approval and freeze policy

- **Module / feature:** Inventory / stock adjustments.
- **Related modules:** Accounting GL, stock count, audit.
- **Category:** Authorization/control bypass.
- **Affected roles:** Warehouse, inventory manager, Finance.
- **Current Behavior:** Legacy `adjustIn`/`adjustOut` apply movements immediately and explicitly do not apply the value-threshold approval gate (`api/app/Modules/Inventory/Services/StockAdjustmentService.php:24-65`). The gated `create` path is separate (`:69-130`).
- **Problem:** Any controller/service retaining the legacy path can bypass approval; callers can also pass a count-freeze bypass flag.
- **Real-world scenario:** A legacy import or old UI posts a high-value adjustment directly while a stock count is frozen; audit sees a movement but no approval chain.
- **Root Cause:** Compatibility methods remain public mutation boundaries instead of delegating to one policy-enforcing command.
- **Recommended Improvement:** Deprecate/remove public legacy methods, route all writes through `create/approve`, or require an explicit privileged system context with audit reason.
- **Ideal Process:** One stock mutation boundary enforces value gate, freeze, GL handoff, and audit.
- **New Feature/Module Required:** None; API deprecation and static caller audit.
- **Cross-Module Impact:** Inventory valuation, GL, counts, audit.
- **Evidence:** `api/app/Modules/Inventory/Services/StockAdjustmentService.php:24-65,69-130`; existing approval race tests cover only the gated path.
- **Priority:** P2. **Impact:** High control bypass if legacy caller is reachable. **Complexity:** M.

### F-016 — Work orders can be created and started without a BOM/material plan

- **Module / feature:** Production / work-order creation and start.
- **Related modules:** MRP, Inventory reservations/issues, Quality traceability.
- **Category:** Completeness / material consumption.
- **Affected roles:** Production planner, supervisor, warehouse, PPC.
- **Current Behavior:** `createDraft` deliberately saves a WO when no active BOM exists (`api/app/Modules/Production/Services/WorkOrderService.php:139-187`). `start` issues reserved materials best-effort and explicitly permits legacy WOs with no reservations (`:299-349`).
- **Problem:** A production run can start and produce output with no material requirements or issue trace.
- **Real-world scenario:** A product master lacks a BOM; the operator starts production and finished goods are recorded without consumed resin/components.
- **Root Cause:** BOM is optional and start treats absent reservations as a legacy-compatible condition.
- **Recommended Improvement:** Require BOM/material approval for standard products; allow an explicit exception WO type with supervisor reason and manual material capture.
- **Ideal Process:** Every good output has either exploded BOM issue lineage or documented exception.
- **New Feature/Module Required:** Production exception/material-plan state.
- **Cross-Module Impact:** MRP, Inventory, costing, traceability, OEE.
- **Evidence:** `api/app/Modules/Production/Services/WorkOrderService.php:139-187,299-349`; `api/tests/Feature/Production/WorkOrderMachineConflictTest.php:29` explicitly creates no-BOM WOs.
- **Priority:** P2. **Impact:** High costing/traceability risk. **Complexity:** M.

### F-017 — Invalid CRM status transitions intentionally silently no-op

- **Module / feature:** CRM / SalesOrder derived transitions.
- **Related modules:** Production, SupplyChain, Accounting.
- **Category:** Lifecycle observability.
- **Affected roles:** Sales, production, warehouse, Finance.
- **Current Behavior:** `transitionTo` logs and returns when target is not allowed (`api/app/Modules/CRM/Services/SalesOrderService.php:563-618`); the service comment says invalid/backwards transitions silently no-op. Tests codify the behavior (`api/tests/Feature/CRM/SalesOrderStatusTransitionsTest.php:110-160`).
- **Problem:** A stale event or invalid caller receives apparent success while the SO remains in an earlier state; downstream chain and operator UI can diverge.
- **Real-world scenario:** A delivery event tries to mark an already-cancelled SO delivered; queue marks business work done although the status update was skipped.
- **Root Cause:** Defensive no-op chosen to avoid upstream exceptions, without an explicit skipped outcome propagated to callers/chain ledger.
- **Recommended Improvement:** Return a typed `skipped` outcome with reason, record it in chain/audit, and make direct command callers receive 409/422 where appropriate.
- **Ideal Process:** Idempotent same-state is success; invalid/backwards is visible and actionable.
- **New Feature/Module Required:** Transition result/outcome contract.
- **Cross-Module Impact:** O2C chain, dashboards, notifications, audit.
- **Evidence:** `api/app/Modules/CRM/Services/SalesOrderService.php:31-45,563-618`; tests above.
- **Priority:** P2. **Impact:** Medium stuck/misleading workflow risk. **Complexity:** S-M.

### F-018 — Supplier bill creation can be detached from an accepted GRN (policy-dependent)

- **Module / feature:** Accounting / manual supplier bill; Purchasing/Inventory P2P.
- **Related modules:** Three-way match, PO, GRN, GL.
- **Category:** AP provenance/control.
- **Affected roles:** AP clerk, Finance reviewer, Purchasing.
- **Current Behavior:** `BillService::create` accepts optional PO/3-way data and supports a manual bill path (`api/app/Modules/Accounting/Services/BillService.php:93-112,152-227`). GRN-linked draft creation is a separate path (`:262-367`).
- **Problem:** A manual bill can be created/posted without an accepted GRN unless policy/3-way configuration blocks it; source audit also recorded the historical absence of a PO status gate. Prebilling may be legitimate, but the distinction is not consistently explicit.
- **Real-world scenario:** AP posts a supplier invoice after receiving a paper invoice, while warehouse has no accepted receipt; liability and stock receipt diverge.
- **Root Cause:** Optional provenance fields and separate manual/draft paths.
- **Recommended Improvement:** Require accepted GRN for stock invoices, or explicit non-receipt/service invoice type with approval and variance reason; ensure cancelled PO is denied or override-audited.
- **Ideal Process:** AP posting has authoritative PO/GRN/service provenance and a visible exception route.
- **New Feature/Module Required:** AP bill provenance policy.
- **Cross-Module Impact:** Inventory, Purchasing, Finance, supplier portal.
- **Evidence:** `api/app/Modules/Accounting/Services/BillService.php:93-112,152-227,262-367`; 3-way blocking at `:195-201`; uncertainty: current configuration may intentionally permit service/non-stock bills.
- **Priority:** P2. **Impact:** Medium/high AP and inventory mismatch. **Complexity:** M.

### F-019 — Annual 13th-month period uniqueness is application-checked, not database-backed

- **Module / feature:** Payroll / create and compute 13th-month period.
- **Related modules:** Payroll computation, GL, statutory exports.
- **Category:** Concurrency/idempotency.
- **Affected roles:** Payroll operators, scheduler, Finance.
- **Current Behavior:** `computeAndPay` queries one 13th-month period by year and creates one when absent (`api/app/Modules/Payroll/Services/ThirteenthMonthService.php:145-179`). The normal auto period service similarly checks `period_start` before creation (`AutoPayrollPeriodService.php:67-109`).
- **Problem:** Two workers can both observe no row before inserting unless an effective unique constraint/serialization exists; current tests do not prove concurrent annual creation.
- **Real-world scenario:** Payroll operator and scheduler start the annual run simultaneously; one run resets/recomputes the other or creates duplicate periods.
- **Root Cause:** Check-then-create and no cited unique `(year, is_thirteenth_month)` constraint.
- **Recommended Improvement:** Add a partial unique index or lock a year-level key; make duplicate insert a safe winner/loser outcome and test concurrent compute.
- **Ideal Process:** Exactly one non-void 13th-month period per year.
- **New Feature/Module Required:** Database constraint/migration and concurrency test.
- **Cross-Module Impact:** Payroll, GL, statutory filing, employee payslips.
- **Evidence:** Source refs above; `api/tests/Feature/Payroll/ThirteenthMonthTest.php` covers sequential reruns/void, not parallel period creation.
- **Priority:** P2. **Impact:** High duplicate-pay risk in rare race. **Complexity:** S-M.

### F-020 — Accounting source/reference IDs are polymorphic integers without foreign-key integrity

- **Module / feature:** Accounting / journal and stock movement references.
- **Related modules:** All modules posting automated journals.
- **Category:** Referential integrity/auditability.
- **Affected roles:** Finance, auditors, system operators.
- **Current Behavior:** Journal and stock movement schemas store `reference_type` plus nullable integer `reference_id` with ordinary indexes, not FKs (`api/database/migrations/0039_create_journal_entries_table.php:24-29`; `0057_create_stock_movements_table.php:22-29`). Journal resources accept arbitrary strings/integers (`api/app/Modules/Accounting/Requests/StoreJournalEntryRequest.php:21-22`).
- **Problem:** A deleted/mistyped source can leave an orphaned journal or movement reference that cannot be followed or mechanically reconciled.
- **Real-world scenario:** An auditor clicks a payroll/bill reference after source cleanup and finds only “#123”; automated reconciliation cannot prove ownership.
- **Root Cause:** Generic polymorphic fields with no typed source registry or FK strategy.
- **Recommended Improvement:** Use typed nullable FKs for core sources, immutable source registry/UUID, or enforce allow-listed `(type, id)` validation and no hard delete of referenced sources.
- **Ideal Process:** Every automated journal has a resolvable immutable source and cross-module reconciliation key.
- **New Feature/Module Required:** Source-reference registry/reconciliation checker.
- **Cross-Module Impact:** GL, Inventory, Payroll, AP/AR, audit exports.
- **Evidence:** Migration and request refs above; `JournalEntry.php:84-93` maps only known display labels and falls back to arbitrary text.
- **Priority:** P2. **Impact:** Medium audit/reconciliation weakness. **Complexity:** L.

### F-021 — Forecast uniqueness with nullable customer_id is not proven by a database constraint

- **Module / feature:** Forecasting / demand forecast upsert.
- **Related modules:** MRP advisory projection, stock-out analytics.
- **Category:** Data integrity/concurrency.
- **Affected roles:** PPC, Purchasing, Plant Manager, Forecasting admin.
- **Current Behavior:** Forecast service queries and `updateOrCreate`s on `(product_id, customer_id, year, month)` (`api/app/Modules/Forecasting/Services/ForecastingService.php:104-132`), while stock-out logic treats `customer_id IS NULL` as total forecast (`:77-87`).
- **Problem:** In PostgreSQL, a normal unique index permits multiple NULL customer IDs; concurrent recomputes can create duplicate total forecasts, which then sum in analytics.
- **Real-world scenario:** Two forecast jobs write the same product/month total forecast; stock-out projection doubles demand.
- **Root Cause:** Application upsert with nullable key and no evidenced `NULLS NOT DISTINCT`/partial unique constraint.
- **Recommended Improvement:** Add a partial unique index for `customer_id IS NULL` plus a customer-scoped unique index; test concurrent recompute and duplicate cleanup.
- **Ideal Process:** One total and one customer-specific forecast per product/month/method grain.
- **New Feature/Module Required:** Forecast uniqueness migration/reconciliation.
- **Cross-Module Impact:** MRP projections, stock-out warnings, dashboards.
- **Evidence:** `api/app/Modules/Forecasting/Services/ForecastingService.php:104-132`; `StockOutProjectionService.php:77-87`; `api/tests/Feature/Forecasting/ForecastAccuracyTest.php` and `ForecastMrpToggleTest.php` do not prove NULL-key concurrency.
- **Priority:** P2. **Impact:** Medium planning distortion. **Complexity:** S-M.

### F-022 — Several material models have no audit trail or explicit detail audit

- **Module / feature:** Inventory, HR, Production, and Quality entity history.
- **Related modules:** Audit log viewer, Payroll, Accounting, approval/recovery workflows.
- **Category:** Auditability / change attribution.
- **Affected roles:** Warehouse, HR, Payroll, Production, Quality, Finance, auditors.
- **Current Behavior:** `StockAdjustment`, `ProfileUpdateRequest`, `EmployeeSalaryHistory`, `WorkOrderOutput`, and `InspectionMeasurement` use `HasHashId`/Eloquent models but do not use `HasAuditLog` or persist an equivalent explicit detail audit (`api/app/Modules/Inventory/Models/StockAdjustment.php:25-44`; `api/app/Modules/HR/Models/ProfileUpdateRequest.php:12-36`; `api/app/Modules/HR/Models/EmployeeSalaryHistory.php:18-36`; `api/app/Modules/Production/Models/WorkOrderOutput.php:16-36`; `api/app/Modules/Quality/Models/InspectionMeasurement.php:20-41`).
- **Problem:** Material adjustments, salary-history edits, profile/bank changes, production output corrections, and measurement changes may be visible in current-state tables but lack immutable before/after actor/reason history.
- **Real-world scenario:** An auditor sees a changed salary history or QC measurement but cannot establish who changed the value, what it replaced, or whether a production/stock fact was corrected after the event.
- **Root Cause:** Audit coverage is inconsistent by model; service comments and related parent audit rows are not equivalent to row-level detail history.
- **Recommended Improvement:** Add `HasAuditLog` where row-level history is required, or write explicit append-only detail events with actor, reason, source command, and correlation ID; cover create/update/delete and sensitive-field redaction.
- **Ideal Process:** Every material fact has immutable actor/time/before/after provenance, with system-worker attribution where applicable.
- **New Feature/Module Required:** Shared material-detail audit policy; no new business module.
- **Cross-Module Impact:** Inventory valuation, HR/payroll, production traceability, Quality evidence, audit exports.
- **Evidence:** Exact current model paths/lines above; `api/app/Common/Traits/HasAuditLog.php:20-22` documents model-event audit behavior. Parent entities such as Employee, WorkOrder, Inspection, and Payroll do not replace the missing detail-row history.
- **Priority:** P2. **Impact:** High audit/dispute weakness across money, quality, and traceability facts. **Complexity:** M.

### F-023 — Supplier portal exposes raw GRN model-shaped delivery data

- **Module / feature:** B2B supplier portal / deliveries.
- **Related modules:** Inventory GRN, Purchasing PO, Quality status.
- **Category:** Data minimization/API contract.
- **Affected roles:** Suppliers, purchasing, warehouse.
- **Current Behavior:** Supplier delivery query returns `GoodsReceiptNote` models with only a vendor filter and eager-loaded PO (`api/app/Modules/B2B/Services/SupplierPortalService.php:448-460`); PO detail separately exposes GRN number/date/status (`:110-129`).
- **Problem:** The portal contract is model-shaped rather than an explicit supplier-safe resource, making future fillable/appended fields easy to expose unintentionally. Current vendor scoping is a mitigation; no cross-vendor leak is claimed.
- **Real-world scenario:** A future GRN attribute (warehouse location, QC notes, internal remarks, cost) becomes serializable through the portal without a contract review.
- **Root Cause:** Direct model collection/serialization for external boundary.
- **Recommended Improvement:** Introduce a dedicated supplier delivery resource with allow-listed fields and negative cross-vendor tests; keep internal QC/lot/accounting fields out.
- **Ideal Process:** External APIs expose stable, least-privilege DTOs.
- **New Feature/Module Required:** SupplierDeliveryResource/schema.
- **Cross-Module Impact:** Inventory, Quality, supplier portal security.
- **Evidence:** `api/app/Modules/B2B/Services/SupplierPortalService.php:110-129,448-460`; existing supplier auth/tenant tests are a mitigation.
- **Priority:** P2. **Impact:** Medium confidentiality/contract drift risk. **Complexity:** S-M.

### F-024 — Dashboard layout cloning/save has last-writer behavior without explicit conflict handling

- **Module / feature:** Dashboard / personal and role layouts.
- **Related modules:** RBAC/widget analytics, SPA dashboards.
- **Category:** UX/concurrency/authorization.
- **Affected roles:** All dashboard users; system administrators managing role defaults.
- **Current Behavior:** Effective layout strips forbidden widgets and falls back to role defaults (`api/app/Modules/Dashboard/Services/DashboardLayoutService.php:17-57`). Cloning checks for user rows then bulk inserts (`:94-135`); saving deletes all user rows and inserts the submitted set (`:141-170`).
- **Problem:** Concurrent tabs can overwrite layouts; role-default changes do not version/migrate personal layouts. Permission stripping can also make a user's intentional layout appear empty before fallback.
- **Real-world scenario:** A user edits desktop and mobile tabs concurrently; the last save deletes the other layout. Admin revokes a widget and users lose placement without explanation.
- **Root Cause:** Whole-layout delete/insert with no version/ETag or conflict response.
- **Recommended Improvement:** Add layout version/optimistic concurrency, explicit reset/migration semantics, and UI feedback for stripped widgets.
- **Ideal Process:** Layout edits are conflict-safe and permission changes are explainable.
- **New Feature/Module Required:** Layout versioning/migration contract.
- **Cross-Module Impact:** RBAC, analytics, all role dashboards.
- **Evidence:** `api/app/Modules/Dashboard/Services/DashboardLayoutService.php:94-170`; `api/tests/Feature/Admin/DashboardLayoutTest.php` covers functional layout behavior, not concurrent saves.
- **Priority:** P2. **Impact:** Medium usability loss. **Complexity:** M.

### F-025 — GL writer enumeration should remain a regression guard

- **Module / feature:** Accounting / journal post/reverse and automated GL.
- **Related modules:** Payroll, Inventory, GRN, Bills.
- **Category:** Concurrency/control consistency.
- **Affected roles:** Finance maker/checker, payroll/inventory workers.
- **Current Behavior:** Current `JournalEntryService::post` and `reverse` re-lock authoritative rows (`api/app/Modules/Accounting/Services/JournalEntryService.php:180-225,275-305`); the baseline writer inventory also identified separate automated writers and historical pre-transaction guards in other GL paths.
- **Problem:** A future or missed writer could resurrect/repost a stale journal or bypass period/maker-checker gates even though the canonical service is currently safe; no current unsafe writer is established in this pass.
- **Real-world scenario:** A worker posts a journal while an operator reverses/voids it; the stale worker later writes a second posted state.
- **Root Cause:** Controls are not mechanically centralized for every writer.
- **Recommended Improvement:** Route all posting/reversal through one lock-then-guard service, enumerate raw writers in CI, and test stale models for each writer.
- **Ideal Process:** No journal status writer exists outside the authoritative state machine.
- **New Feature/Module Required:** Static writer audit/contract test.
- **Cross-Module Impact:** All GL integrations.
- **Evidence:** `api/app/Modules/Accounting/Services/JournalEntryService.php:180-225,275-305` currently locks/rechecks the canonical paths; the historical writer inventory is context, not proof of a current unsafe writer.
- **Priority:** P3. **Impact:** High only if a future raw writer bypasses the canonical service; current production behavior is not confirmed defective. **Complexity:** M.

### F-026 — User role updates are last-write-wins without optimistic versioning

- **Module / feature:** Admin / user role assignment.
- **Related modules:** RBAC cache, approvals, dashboard scope.
- **Category:** Authorization concurrency.
- **Affected roles:** System administrators; all users whose role changes.
- **Current Behavior:** Single-user role assignment updates the user directly (`api/app/Modules/Admin/Services/UserAdminService.php:90-150`); bulk assignment reads users then updates role IDs (`:157-197`).
- **Problem:** Two administrators can overwrite each other's role decisions; permission caches may reflect an unexpected final role.
- **Real-world scenario:** Admin A removes Finance access while Admin B restores the old role from a stale screen; the final role silently re-grants access.
- **Root Cause:** No row lock/version/expected-role condition on role update.
- **Recommended Improvement:** Add optimistic version/expected role checks, audit old/new role and reason, invalidate cache transactionally, and expose conflict to the second admin.
- **Ideal Process:** Sensitive RBAC changes are intentional, serialized, and attributable.
- **New Feature/Module Required:** RBAC change conflict/audit contract.
- **Cross-Module Impact:** Approval delegation, dashboards, route authorization, audit.
- **Evidence:** `api/app/Modules/Admin/Services/UserAdminService.php:90-150,157-197`; `api/tests/Feature/Admin/RoleManagementTest.php` covers permissions, not concurrent role edits.
- **Priority:** P2. **Impact:** High authorization drift in rare admin race. **Complexity:** S-M.

### F-027 — Final-pay calculations use binary floating-point arithmetic

- **Module / feature:** HR / final pay computation.
- **Related modules:** Payroll, Loans, Accounting.
- **Category:** Money precision.
- **Affected roles:** HR, Payroll, Finance, separated employees.
- **Current Behavior:** Final-pay components are added/subtracted as PHP floats and net is clamped with `max(0.0, ...)` (`api/app/Modules/HR/Services/FinalPayService.php:68-88`).
- **Problem:** Decimal salary/leave/loan values can accumulate binary rounding differences before formatting to two decimals; the journal may require a different exact cent result.
- **Real-world scenario:** A cent-level rounding difference appears between employee breakdown, final-pay row, and GL lines.
- **Root Cause:** Float arithmetic instead of decimal/money value object through the whole calculation.
- **Recommended Improvement:** Use decimal strings/Money for all arithmetic, define per-component rounding, and reconcile breakdown sum to journal exactly.
- **Ideal Process:** Every displayed and posted amount is derived from the same cent-precise calculation.
- **New Feature/Module Required:** Shared Money arithmetic policy.
- **Cross-Module Impact:** Payroll, Accounting, loan settlement.
- **Evidence:** `api/app/Modules/HR/Services/FinalPayService.php:68-88,233-245`; existing `FinalPayMoneyFindingsRegressionTest.php` covers balancing edge cases but not decimal adversarial values.
- **Priority:** P2. **Impact:** Medium financial discrepancy. **Complexity:** M.

### F-028 — 13th-month run concurrency is only partially proven despite accrual hardening

- **Module / feature:** Payroll / annual accrual and compute.
- **Related modules:** Normal payroll compute, GL.
- **Category:** Concurrency/recovery.
- **Affected roles:** Payroll operators and workers.
- **Current Behavior:** At the audited baseline, accrual/reversal locked employee rows while period creation and run reset still used the existing-period check/create path (`ThirteenthMonthService.php:145-180`).
- **Problem:** Accrual RMW is mitigated, but concurrent period creation/reset and compute-vs-reversal interleavings are not fully proven.
- **Real-world scenario:** A retry begins while an operator voids/restarts the annual run; one run resets payroll rows while the other is still computing.
- **Root Cause:** Different locks protect accrual employees versus period/run ownership.
- **Recommended Improvement:** Add a year/period run lock and owner token; test compute/void/retry interleavings.
- **Ideal Process:** One annual run owner controls all accrual, payroll-row reset, finalization, and reversal transitions.
- **New Feature/Module Required:** Annual run lease/state machine.
- **Cross-Module Impact:** Payroll, GL, statutory exports.
- **Evidence:** Baseline location: `ThirteenthMonthService.php:145-180`. Current annual-run ownership and concurrency evidence is listed in the lifecycle registry.
- **Priority:** P2. **Impact:** High but rare duplicate/lost annual pay. **Complexity:** M.

## P2/P3 and evidence-qualified findings

These findings retain explicit evidence boundaries. F-029–F-033 are confirmed
current P2/P1 issues; F-034–F-037 are structural or audit-governance follow-ups.

### F-029 — Public health detail is exposed when the detail token is empty

- **Module / feature:** Health/Edge operational endpoints.
- **Related modules:** Auth middleware, deployment monitoring.
- **Category:** Security / unauthenticated operational disclosure.
- **Affected roles:** Operations, device integrators, platform administrators.
- **Current Behavior:** `GET /health` accepts the detail request when `HEALTH_DETAIL_TOKEN` is empty (`api/routes/api.php:43-58`), then returns DB/Redis/queue checks and timestamps (`:60-94`). The token is also accepted in the query string (`:50-58`).
- **Problem:** A public endpoint can disclose internal component topology, queue size, and timing whenever configuration leaves the token empty; query-token support also creates URL/log/referrer leakage risk.
- **Real-world Scenario:** An internet scanner calls `/api/v1/health?token=...` or simply reaches an environment with an empty token and learns DB/Redis/queue health, deployment timing, and operational state.
- **Root Cause:** Empty token means “grant,” and detail authentication is optional rather than fail-closed; sensitive token transport is permitted in the query string.
- **Recommended Improvement:** Require a non-empty production secret for detail, return only minimal liveness publicly, require header-only token authentication for detail, reject query tokens, and add configuration/startup and negative endpoint tests.
- **Ideal Process:** Liveness is public/minimal; detail is authenticated, scoped, and auditable.
- **New Feature/Module Required:** Operational endpoint contract suite.
- **Cross-Module Impact:** Auth, Edge, deployment monitoring.
- **Evidence:** `api/routes/api.php:43-94`; `api/config/health.php:8-14`; `api/tests/Feature/Health/HealthCheckTest.php` does not establish production non-empty-token enforcement.
- **Priority:** P2. **Impact:** Medium confidentiality and reconnaissance risk; potentially higher if deployment topology is sensitive. **Complexity:** S.

### F-030 — Restore and deployment proof is incomplete for production readiness

- **Module / feature:** Infrastructure / restore, deploy, scheduled workers.
- **Related modules:** Outbox, scheduler ledger, file storage, migrations.
- **Category:** Operational resilience/evidence.
- **Affected roles:** DevOps, system administrator, auditors.
- **Current Behavior:** The restore drill provides a local contract and runbook (`docs/RESTORE-DRILL.md`) but no retained target-environment evidence. Local deployment checks do not prove authenticated API, queue, scheduler, rollback, or restored-upload behavior in production-like infrastructure.
- **Problem:** Green CI can coexist with an untested production recovery path.
- **Real-world Scenario:** A database/storage restore loses outbox or uploaded proof files even though application tests pass.
- **Root Cause:** External infrastructure state is outside PHPUnit/SPA assertions.
- **Recommended Improvement:** Run and retain a real backup restore, authenticated API smoke, queue/scheduler worker smoke, migration upgrade/rollback rehearsal, durable-upload check, and failure/rollback drill; attach timestamped evidence to the release gate.
- **Ideal Process:** Every release has a reproducible restore and deployment proof, not only code tests.
- **New Feature/Module Required:** CI/CD operational smoke stage and evidence retention.
- **Cross-Module Impact:** Entire ERP.
- **Evidence:** `docs/RESTORE-DRILL.md` and `scripts/release-evidence.sh`; F-030 remains external-evidence-only in the acceptance manifest.
- **Priority:** P1. **Impact:** Potentially critical recovery and release failure. **Complexity:** M-L.

### F-031 — Purchase-request rejected approval steps render as pending

- **Module / feature:** RBAC route visibility; Purchasing rejected-request UI.
- **Related modules:** SPA navigation, permissions, API routes.
- **Category:** UX/authorization contract.
- **Affected roles:** Department heads, Purchasing, approvers, all role dashboards.
- **Current Behavior:** The PR detail compact chain maps any approval action other than `approved` or `pending` to `pending` (`spa/src/pages/purchasing/purchase-requests/detail.tsx:369-375`), while the approval timeline distinguishes rejected/skipped states elsewhere in the same page.
- **Problem:** A rejected approval step appears active/pending in the compact chain, misleading approvers and making a terminal PR look actionable.
- **Real-world Scenario:** A department head sees a rejected step as pending and retries/escalates a request that should be closed or resubmitted.
- **Root Cause:** Compact-chain state mapping omits `rejected`/`skipped` terminal states even though the underlying approval record contains the action.
- **Recommended Improvement:** Map rejected/skipped to terminal danger/neutral states with reason/date and make resubmission the only explicit next action; add route/permission negative tests separately rather than conflating them with this UI defect.
- **Ideal Process:** Every visible action is authorized and every terminal status has an actionable explanation.
- **New Feature/Module Required:** None; regression suite.
- **Cross-Module Impact:** All role dashboards and approval pages.
- **Evidence:** `spa/src/pages/purchasing/purchase-requests/detail.tsx:361-385`, especially `:369-375`; status/timeline tests do not assert rejected compact-chain rendering.
- **Priority:** P2. **Impact:** Medium approval dead-end and operator error. **Complexity:** S.

### F-032 — Key operational detail tables are not horizontally wrapped on mobile

- **Module / feature:** SPA / mobile operational tables.
- **Related modules:** Inventory, Accounting, Loans, Returns, HR self-service.
- **Category:** UX/accessibility.
- **Affected roles:** Drivers, warehouse, employees, mobile approvers.
- **Current Behavior:** Key detail tables render bare `<table className={tableCls}>` without an `overflow-x-auto` wrapper: purchase-order lines (`spa/src/pages/purchasing/purchase-orders/detail.tsx:225-249`), GRN lines (`spa/src/pages/inventory/grn/detail.tsx:284-296`), supplier bills (`spa/src/pages/accounting/bills/detail.tsx:230-259`), customer invoices (`spa/src/pages/accounting/invoices/detail.tsx:183-210`), and production material lots (`spa/src/pages/production/work-orders/detail.tsx:227-254`).
- **Problem:** Dense tables can clip actions or require horizontal scrolling without pinned identifiers/action affordances.
- **Real-world Scenario:** A driver or warehouse operator cannot see delivery status/action on a phone and abandons the workflow.
- **Root Cause:** Responsive behavior is component/page-specific rather than an acceptance contract.
- **Recommended Improvement:** Define mobile table patterns, test key widths/device matrix, prioritize active work queues and approvals.
- **Ideal Process:** Every mobile role can complete its primary action without inaccessible columns.
- **New Feature/Module Required:** Shared responsive data-grid pattern.
- **Cross-Module Impact:** All mobile surfaces.
- **Evidence:** Exact baseline table locations above; current static/type evidence is recorded in the acceptance manifest, while authenticated narrow-width visual evidence remains absent.
- **Priority:** P2. **Impact:** Medium usability and task-completion risk for mobile operators. **Complexity:** M.

### F-033 — Status/check constraints are incomplete at the database boundary

- **Module / feature:** Cross-module status fields.
- **Related modules:** All lifecycle models and analytics.
- **Category:** Data integrity/maintainability.
- **Affected roles:** Developers, operators, auditors.
- **Current Behavior:** Domain enums and service guards define statuses, while many historical migrations use strings and nullable columns; the baseline writer inventory found multiple writers and raw string statuses.
- **Problem:** A typo or legacy writer can persist an unrecognized status that the UI/service silently treats as pending/unknown.
- **Real-world Scenario:** A queue writes `partially_received` while a consumer expects `partial_received`; the item disappears from a work queue.
- **Root Cause:** Application-only enum validation and heterogeneous raw writers.
- **Recommended Improvement:** Add DB checks where supported, centralized transition tables, status-writer static audit, and unknown-status monitoring.
- **Ideal Process:** Invalid state cannot be persisted; every valid state has consumer/action coverage.
- **New Feature/Module Required:** Schema/status contract tooling.
- **Cross-Module Impact:** Entire ERP.
- **Evidence:** Representative writer: `api/app/Modules/CRM/Services/SalesOrderService.php:31-45`; current constraint evidence is mapped under F-033.
- **Priority:** P2. **Impact:** Medium latent data-integrity and reporting risk. **Complexity:** M-L.

### F-034 — Hidden clients and scope-cut surfaces create audit false positives

- **Module / feature:** Cross-module hidden clients and unused surfaces.
- **Related modules:** CRM, Loans, ReturnManagement, Accounting, CI/static audits.
- **Category:** Maintainability/scope governance.
- **Affected roles:** Developers, auditors, product owners.
- **Current Behavior:** The current audit inventory identifies about 30 hidden clients/unused surfaces that can create audit false positives.
- **Problem:** Reviewers and static audits can mistake hidden/dead clients for reachable production capability, creating noise and maintenance drift.
- **Real-world Scenario:** A reviewer signs off a client as a supported workflow although no route mounts it, or a future developer implements against a hidden surface that lacks current controls.
- **Root Cause:** Reachability and scope status are not maintained as one source of truth.
- **Recommended Improvement:** Inventory/remove hidden clients, make scope-cut status explicit, and require route/client reachability evidence before treating a surface as supported.
- **Ideal Process:** Every code path is reachable and governed, or clearly quarantined from production.
- **New Feature/Module Required:** Scope manifest/dead-code CI check.
- **Cross-Module Impact:** Entire architecture.
- **Evidence:** Current audit inventory/findings on about 30 hidden clients and false positives.
- **Priority:** P3. **Impact:** Medium audit/maintenance drift. **Complexity:** S-M.

### F-035 — CI should preserve adversarial concurrency and cross-module acceptance tests

- **Module / feature:** Engineering verification pipeline.
- **Related modules:** All modules listed in this audit.
- **Category:** Test coverage/quality gate.
- **Affected roles:** Developers, QA, release managers, auditors.
- **Current Behavior:** Existing tests are broad and several race/idempotency regressions are strong, but the candidate matrix still lacks parallel token reset, login lockout, loan payment, QC allocation, production cache-loss, annual 13th period, and leave empty-gap races.
- **Problem:** Sequential tests can pass while the identified check-then-write races remain exploitable.
- **Real-world Scenario:** A release passes the full suite, then a second queue worker creates duplicate financial or inventory facts.
- **Root Cause:** Tests mostly invoke services sequentially and do not exercise multiple DB connections/processes or cache loss.
- **Recommended Improvement:** Add a prioritized adversarial suite using parallel DB connections, forced interleavings, cache flush, stale models, and replayed outbox events; fail CI on missing acceptance IDs.
- **Ideal Process:** Every P0/P1 mutation has a deterministic sequential, stale, retry, and concurrent proof.
- **New Feature/Module Required:** Concurrency harness and acceptance-test manifest.
- **Cross-Module Impact:** Entire ERP.
- **Evidence:** Candidate-specific gaps are documented in this register; the current acceptance manifest preserves the required concurrency and cross-module gates.
- **Priority:** P3 as a tooling finding; individual missing tests inherit their finding priority.
- **Impact:** High assurance gap. **Complexity:** M.

### F-036 — Findings require an explicit audit lifecycle and status

- **Module / feature:** Audit governance / finding lifecycle.
- **Related modules:** All module audits, CI evidence, release governance.
- **Category:** Audit metadata / evidence quality.
- **Affected roles:** Architectural reviewers, auditors, product owners, release managers.
- **Current Behavior:** At the audited baseline, findings were spread across dated documents with different evidence scopes and no single durable disposition.
- **Problem:** Without status, owner, first-seen/fixed dates, and verification scope, historical defects can be mistaken for current defects or current operational gaps can be treated as fixed.
- **Real-world Scenario:** A repaired RBAC issue remains on a P1 backlog while an unexecuted restore drill is treated as complete because a source audit passed.
- **Root Cause:** Audit findings lack a durable lifecycle/meta record tied to current code and acceptance evidence.
- **Recommended Improvement:** Add finding status (`open`, `mitigated`, `verified`, `deferred`, `accepted-risk`), owner, evidence date/scope, regression test, and explicit policy-decision field.
- **Ideal Process:** Severity and disposition always reflect the current reachable behavior and the exact verification boundary.
- **New Feature/Module Required:** Audit finding registry or structured metadata; no business module.
- **Cross-Module Impact:** Audit planning and release decisions.
- **Evidence:** The current lifecycle registry now records evidence scope and preserves the distinction between source-level closure and external runtime verification.
- **Priority:** P3. **Impact:** Medium governance and prioritization risk. **Complexity:** S.

### F-037 — Stale Edge module registration is structural cleanup

- **Module / feature:** Providers / module route registration.
- **Related modules:** Edge device integrations, deployment configuration, health monitoring.
- **Category:** Structural cleanup / reachability.
- **Affected roles:** Developers, platform administrators, auditors.
- **Current Behavior:** `ModuleServiceProvider` lists `Edge` among module names (`api/app/Providers/ModuleServiceProvider.php:35-47`), but the current worktree has no `api/app/Modules/Edge` directory.
- **Problem:** Boot-time module discovery and static audits carry a stale provider entry, obscuring whether Edge is supported or intentionally removed.
- **Real-world Scenario:** A deployment assumes Edge routes are mounted when they are not, or a future module restore collides with undocumented provider expectations.
- **Root Cause:** Provider registration was not removed or reconciled when the module surface changed.
- **Recommended Improvement:** Confirm ownership; remove the stale entry if Edge is out of scope, or restore a tested module package and route contract if it remains supported.
- **Ideal Process:** Every registered module exists, is tested, and has an explicit deployment owner.
- **New Feature/Module Required:** None unless Edge is intentionally restored.
- **Cross-Module Impact:** Module boot, route audits, Edge deployment.
- **Evidence:** `api/app/Providers/ModuleServiceProvider.php:35-47`; current tree has no `api/app/Modules/Edge`.
- **Priority:** P3. **Impact:** Low runtime today, medium structural confusion. **Complexity:** S.

### F-038 — Seeded BIR withholding brackets misstate effective dates and boundaries

- **Module / feature:** Payroll / Philippine withholding-tax computation and statutory configuration.
- **Related modules:** Payroll calculator, 13th-month annualization, final pay, BIR exports, Accounting payroll handoff.
- **Category:** Statutory payroll / effective-dated reference data.
- **Affected roles:** Payroll, Finance, HR, employees, statutory filing owners.
- **Current Behavior:** The audited seed labeled one schedule as effective in 2018 while combining boundaries and fixed/rate values that do not match the official 2018–2022 monthly table or the separate 2023-and-later table. Computation selected rows by effective date, so internally consistent code could still calculate from incorrect statutory reference data.
- **Problem:** Employees can be under- or over-withheld at bracket boundaries, and historical payroll recomputation can apply the wrong schedule.
- **Real-world scenario:** A 2023 payroll run selects a row seeded as 2018 with a later-law marginal rate but incorrect threshold/fixed amount, producing a value that cannot reconcile to the BIR monthly table.
- **Root Cause:** Statutory schedules were collapsed into a single seed set without a source-pinned, effective-dated fixture matrix.
- **Recommended Improvement:** Store the official 2018–2022 and 2023+ monthly brackets as separate effective dates; pin every lower/upper boundary, fixed amount, and marginal rate in tests; preserve tenant-specific effective dates during repair; require source review for later statutory changes.
- **Ideal Process:** Payroll selects the most recent schedule effective on the pay date, boundary tests reconcile to the official table, and statutory updates are additive and reversible.
- **New Feature/Module Required:** No new module; governed statutory-reference-data migrations and a source-pinned fixture suite.
- **Cross-Module Impact:** Payroll withholding, 13th-month/year-end true-up, final pay, payslips, GL, and BIR exports.
- **Evidence:** `api/database/seeders/GovernmentTableSeeder.php`; `api/database/migrations/0467_correct_bir_effective_dated_brackets.php`; `api/tests/Feature/Payroll/GovComputationServicesTest.php`. Official BIR RR No. 11-2018 Annex D and Annex E define the separate monthly schedules.
- **Priority:** P1. **Impact:** High statutory, employee-net-pay, and reconciliation risk. **Complexity:** M.

## Audited-baseline acceptance-test evidence matrix (historical)

This matrix preserves the gap analysis at the time each baseline entry was
written. Current acceptance commands and dispositions live in
`AUDIT-ACCEPTANCE-MANIFEST-2026-08-13.json` and
`SYSTEM-AUDIT-FINDING-LIFECYCLE.json`; those artifacts supersede the historical
gap verdicts below.

| ID | Required acceptance test | Existing evidence | Gap verdict |
|---|---|---|---|
| F-001 | Ordinary employee submits explicit privileged `role_slug`; delegate must receive 403/422 and no row. Valid same-role delegation still works. | Added ordinary/admin foreign-role rejection, valid same-role, and use-time role-revocation regressions. | **Verified remediated; P0 closed in worktree** |
| F-002 | Concurrent cash payments and payroll deduction; exact payment rows, aggregate, balance, status, and replay behavior. | Added authoritative loan-lock stale-model and recompute regressions; external idempotency/reconciliation remains. | **Partially covered; P1** |
| F-003 | Same reset token in two concurrent requests; exactly one success and one mutation/history/used timestamp. | Token/user are now locked and consumed atomically; stale preloaded-token regression added. | **Service fixed; real two-connection test remains** |
| F-004 | N concurrent failures at threshold plus success/failure interleave; no lost increments and one lockout audit. | Authoritative user lock plus stale-snapshot/expired-lock regressions added. | **Service fixed; real two-connection test remains** |
| F-005 | 13th-month below/at/above exemption, partial year, taxable excess, BIR reconciliation. | `ThirteenthMonthTest.php:114-149` asserts zero tax. | **Uncovered; P1/P2 policy** |
| F-006 | Two same-product batches; wrong inspection rejected; passed quantity consumed exactly on partial deliveries. | Output-bound QC/delivery regressions cover two-output replay, legacy rejection, wrong product/WO/SO lineage, partial reservations, and cancellation reuse. | **Verified remediated; P1 closed in worktree** |
| F-007 | Direct linked invoice before delivery rejected, or explicit prebill type/approval path; SO state remains correct. | `AutoInvoiceOnDeliveryConfirmTest.php:55-221` happy/retry delivery path only. | **Uncovered; P1/P2 policy** |
| F-008 | Product-only stockable RMA rejected or explicitly non-stock; credit has source/disposition audit and no silent stock omission. | `ReturnRequestScenarioTest.php:245-265` checks credit amount only; item-backed restock tests exist. | **Uncovered; P1/P2 policy** |
| F-009 | Same idempotency key under simultaneous requests, cache flush, expiry, and worker restart; one output/receipt. | Durable scoped key/fingerprint, unique constraint, cache-flush replay, payload-conflict, cross-WO, and stale-WO tests added. | **Verified remediated; P1 closed in worktree** |
| F-010 | Disbursement while GL Pending: enforce block, or assert allowed warning/manual owner/reconciliation. | `PayrollPeriodEventsTest.php:66-87,154-200`; `PayrollGlHandoffTest.php:89-264`. | **Policy decision required; P1/P2** |
| F-011 | Stale worker after claim takeover cannot write payroll rows or complete outbox. | Durable claim token is checked before employee/failure/terminal writes; stale-worker and old-reaper-snapshot regressions preserve the current owner. | **Service mitigated; real paused-worker/two-connection proof remains** |
| F-012 | Two empty-gap leave submissions on separate connections; one active overlap result. | Submission now locks the guaranteed employee row before the overlap query; focused suite pins the lock and half-day/full-day semantics. | **Service remediated; real two-connection harness remains** |
| F-013 | Concurrent biometric replay creates one OT row and one payroll effect. | Partial source-key uniqueness, non-destructive duplicate preflight, replay-safe writer, and distinct-employee regressions added. | **Verified remediated; P2 closed in worktree** |
| F-014 | PO reporting/close/billing while GRN pending QC uses physical versus accepted quantities correctly. | GRN/QC acceptance tests cover gate, not consumer semantics. | **Partially covered; P2** |
| F-015 | Legacy adjustment callers cannot bypass approval/freeze; all writes use canonical gate. | Legacy generic methods removed; ordinary changes use create/approve; stock-count-only reconciliation derives locked values and rejects replay. | **Verified remediated; P2 closed in worktree** |
| F-016 | No-BOM standard WO cannot start/record stockless output; explicit exception path is audited. | No-BOM production tests prove current permissive behavior. | **Policy/acceptance gap; P2** |
| F-017 | Invalid transition returns typed skipped/409 and chain ledger records reason; same-state replay remains idempotent. | `SalesOrderStatusTransitionsTest.php:110-160` codifies silent no-op. | **Design decision; P2** |
| F-018 | Manual supplier bill without accepted GRN/PO is blocked or explicit service/non-stock override is audited. | Bill/3-way tests cover configured match paths. | **Policy/coverage gap; P2** |
| F-019 | Concurrent annual 13th period creation yields one non-void row. | PostgreSQL partial-expression uniqueness, migration duplicate preflight, and per-year advisory-lock service regressions added. | **Verified remediated; P2 closed in worktree** |
| F-020 | Delete/mistype source reference is rejected or reconciliation reports an orphan. | Existing FK/integrity sweeps do not cover polymorphic source semantics. | **Uncovered; P2** |
| F-021 | Concurrent total forecast upsert with NULL customer yields one row and correct stock-out sum. | PostgreSQL null-aware unique index, duplicate preflight, stable advisory lock, NULL replay, and customer-separation regressions added. | **Verified remediated; P2 closed in worktree** |
| F-022 | Create/update each material detail (stock adjustment, profile request, salary history, WO output, inspection measurement); assert immutable actor/before/after audit evidence. | Models lack `HasAuditLog`/equivalent detail audit at the cited paths. | **Uncovered; P2** |
| F-023 | Supplier sees only allow-listed GRN fields and never another vendor's data. | Dedicated supplier resource plus exact-field and cross-vendor HTTP regression added. | **Verified remediated; P2 closed in worktree** |
| F-024 | Concurrent dashboard saves return conflict instead of deleting another tab's layout; permission removal is explained. | User-row serialization, deterministic versions, stale HTTP conflict regression, and SPA version propagation added. | **Verified remediated; P2 closed in worktree** |
| F-025 | Enumerate every GL writer and retain stale-model/reversal/post regression tests. | Three direct writers were rerouted through `JournalEntryService::postSystem`; mutation-contract and affected GL suites pass. | **Verified remediated; P3 guard active** |
| F-026 | Concurrent admin role edits detect stale version and preserve audit/cache correctness. | Expected-role contracts, authoritative locks, typed single-edit 409, partial bulk conflict reporting, and audit assertions are covered. | **Verified remediated; P2 closed in worktree** |
| F-027 | Decimal adversarial final pay reconciles exactly to cents and GL. | Decimal-string component contracts and an adversarial cent fixture prove exact breakdown totals and balanced posted journal totals. | **Verified remediated; P2 closed in worktree** |
| F-028 | Compute/void/retry annual run interleaving has one owner and no reset corruption. | Accrual invariant evidence only. | **Uncovered; P2** |
| F-029 | Without a configured token, public health must return minimal liveness only; query token rejected; header token required for DB/Redis/queue/timestamps. | Empty/wrong/query/correct-token endpoint regressions added; six health tests pass. | **Verified remediated; P2 closed in worktree** |
| F-030 | Restore real backup, authenticated API, queue/scheduler, durable files, migration rollback, and deployment rollback; retain artifacts. | `RESTORE-DRILL.md:109` is partial/placeholder; current defense matrix does not prove these paths. | **Uncovered; P1** |
| F-031 | Rejected/skipped PR approval step renders terminal with reason/date, not pending; resubmission is explicit. | Terminal-state renderer and pure PR-chain regressions added; later steps are skipped after rejection. | **Verified remediated; P2 closed in worktree** |
| F-032 | PO, GRN, bill, invoice, and WO detail tables remain usable at mobile widths with wrapping/pinned actions. | All cited tables now have overflow/min-width guards; typecheck and focused source regression pass. | **Cited surfaces remediated; live narrow-browser visual proof remains** |
| F-033 | Invalid lifecycle status cannot be persisted, or is surfaced as an explicit unknown-state error. | Nine highest-risk enum-backed columns have database rejection and enum-drift coverage; lower-risk module lifecycle columns remain outside this bounded tranche. | **Mitigated; P2 remainder inventoried** |
| F-034 | Every hidden client/scope-cut surface is marked, removed, or route-reachability verified. | Checked scope manifest classifies all 27 unmatched clients; audit fails on unclassified or stale entries. | **Verified remediated; P3 closed in worktree** |
| F-035 | CI runs the prioritized concurrency, stale, retry, and cache-loss acceptance suite. | Broad CI and new stale/replay/uniqueness contracts mitigate the gap; selected real two-connection and external failure cases remain. | **Mitigated; P3 tooling / inherited test priorities** |
| F-036 | Every finding has owner, status, evidence date/scope, policy decision, and regression proof. | Structured registry covers all 38 finding IDs and is checked by a dedicated CI workflow against the findings document. | **Verified remediated; P3 governance gate active** |
| F-037 | Provider module list matches actual module tree and deployment ownership. | Bidirectional module-registry/route-directory regression added after removing stale `Edge`. | **Verified remediated; P3 closed in worktree** |
| F-038 | Boundary dates and amounts for 2018–2022 and 2023+ reconcile to official BIR monthly tables; historical/custom effective dates remain safe. | Separate source-pinned schedules, corrective migration, boundary tests, and rollback fixture restoration added. | **Verified remediated; P1 closed in worktree** |

## Module breadth and role participation

The principal roles participating in the audited chains are: system admin and
RBAC administrator; Sales/CRM; Purchasing/Procurement; Warehouse/Inventory and
Shipping; Quality inspectors/NCR owners; PPC/MRP and Production supervisors;
Maintenance; HR/Payroll/Finance/Accounting/Treasury; department and HR
approvers; employees using self-service; customer and supplier portal users;
drivers; and queue/scheduler/system actors. The module test inventory shows
strong sequential lifecycle coverage in CRM, Purchasing, Inventory, MRP,
Production, Quality, Maintenance, SupplyChain, Returns, B2B, Forecasting,
Landing, HR, and Payroll, but the acceptance matrix above identifies where
cross-module state, concurrency, provenance, or abnormal recovery still lacks
proof.
