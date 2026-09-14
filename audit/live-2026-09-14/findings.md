# LIVE Audit Findings

**Run:** Batch 1, 2026-09-14
**Stack:** `http://localhost`
**Browser:** Playwright Firefox, one context, one serial worker
**Database:** seeded `ogami_overnight_20260914`; not reset

## Findings

### LIVE-B1-F01: Department Head Sees Department-Scoped Employee List

**Disposition:** FALSE POSITIVE / PASS AFTER DATABASE RECONCILIATION

**Actor:** `depthead` / `depthead@ogami.test`, seeded `department_head`, PROD department

**Expected boundary:** The role matrix allows department-scoped approval work and only the employee/item lookups needed for those tasks. It does not grant ordinary employee-master browsing or cross-department employee visibility.

**Observed evidence:**

- SPA path `/hr/employees` rendered the Employees page with `86 employees` and the active employee table.
- Direct request from the same authenticated Firefox page: `GET /api/v1/hr/employees` returned `200`.
- Returned target hash IDs included `ajzw1q1w3e`, `DzAwdKb5ld`, `BnoNyGw56v`, and `0ldwDXMwVa`.
- The nearest mutation boundary is correctly denied: `POST /api/v1/hr/employees` with an empty validation payload returned `403`.
- A direct database reconciliation showed 200 total employees, 86 employees in the department-head's PROD department, `hr.employees.view = true`, and `hr.employees.view_sensitive = false`. The returned 86 rows therefore match the intended department scope; no cross-department rows were exposed.

**Screenshot:** [`artifacts/19-depthead-boundary-depthead-negative--hr-employees.png`](artifacts/19-depthead-boundary-depthead-negative--hr-employees.png)

**Related coverage:** S07 aggregator/row-scope evidence and S13 employee/department scope evidence. The underlying module is S09, which remains a planning row and was not marked passed.

**Disposition rationale:** The original failure classification was caused by treating the department-sized result as the company-wide result. The policy and API are behaving correctly. No application fix is required.

## Blocked Evidence

### LIVE-B1-B01: No Second Supplier Or Customer Tenant Fixture

**Disposition:** BLOCKED, not a product failure

The seeded run has one supplier portal identity (`portal@supp.test`) linked to vendor hash `dGypLxpvAg` and one customer portal identity (`portal@cust.test`) linked to customer hash `dGypLxpvAg`. The opposite-guard checks passed: supplier credentials received `401` from `/api/v1/b2b/customer/me`, customer credentials received `401` from `/api/v1/b2b/supplier/me`, and both opposite SPA routes redirected to the corresponding portal login. A tenant A versus tenant B read/action comparison could not be executed without inventing a fixture.

## Process Improvements

### LIVE-B1-PI01: Make The Role Matrix Read/Mutate Explicit

The production and QC boundary checks initially looked like failures because their list APIs intentionally return `200` while their operational mutations return `403`. Each boundary row should declare separate `view`, `create`, and `mutate` expectations, with the exact method and path for each.

### LIVE-B1-PI02: Encode SPA Redirect Expectations

`/dashboard` is a role router and legitimately redirects to `/dashboard/admin`, `/dashboard/hr`, `/dashboard/finance`, `/dashboard/plant-manager`, or another permission-derived destination. The live runner should treat the resolved destination as the assertion target instead of requiring the initial path to remain unchanged.

### LIVE-B1-PI03: Keep SPA And API Paths As Separate Fields

The first pass accidentally navigated to `/approvals/board`, `/chain/bottlenecks`, and `/dashboards/action-center` as SPA paths while those are API paths. The fixture schema should require `spaPath` and `apiPath` as different fields and reject a path beginning with `/api` or an API-only route in the UI slot.

### LIVE-B1-PI04: Provide Disposable Cross-Tenant Fixtures

Portal isolation requires at least two supplier tenants and two customer tenants, with one clean record per tenant. The overnight seed should provision those identities or expose a documented read-only fixture set. Without that, the audit can prove guard separation but cannot prove tenant A cannot read tenant B's records.

### LIVE-B1-PI05: Keep The Current SPA Route In The Coverage Register

The planned department-head leave route `/leaves/requests` was unavailable in the SPA. The live route is `/hr/leaves`, while the API remains `/api/v1/leaves/requests`. The register should preserve this intentional SPA/API naming difference to avoid a false BLOCKED result.

## Batch 2 Findings — S08-S14

### LIVE-B2-F01: Position Duplicate Is Accepted

**Disposition:** FIXED and live-regression-tested

**Actor:** `hr` / `hr@ogami.test`, `hr_officer`

**SPA path:** `/hr/positions` rendered successfully before the direct mutation.

**Exact API evidence:** `POST /api/v1/hr/positions` returned `201` with created hash ID `DzAwdgKb5l` when the payload reused the existing position title `Accounting Officer`, department hash `R8epjLbOaD`, and the existing position's salary grade. The expected duplicate/invalid result was `422`. The disposable duplicate `DzAwdgKb5l` was immediately deleted with `DELETE /api/v1/hr/positions/DzAwdgKb5l` `204`; no seeded record was altered.

**Reproduction:** As HR, open `/hr/positions`; submit a new position whose `title` and `department_id` match an existing position. The API creates a second active row instead of returning a validation error. Repeated final-run evidence produced hashes `29awRBwjWx`, `q0zw8Gb7no`, `A3ap5y3p70`, and `DzAwdgKb5l`, each cleaned immediately.

**Severity:** Medium. **Category:** Data integrity / master-data validation. **Effort:** S.

**Concrete fix:** Add a database-backed unique constraint on the active position identity `(department_id, normalized title)` or an equivalent soft-delete-aware uniqueness strategy, mirror it in `StorePositionRequest` and `UpdatePositionRequest`, and return a field-keyed `422` for duplicate active titles. Add a feature test that creates the same title twice in one department and verifies no second active row.

**Evidence:** [`artifacts/failure-S08-invalid-duplicate-position-POST--api-v1-hr-positions.png`](artifacts/failure-S08-invalid-duplicate-position-POST--api-v1-hr-positions.png)

**Fix evidence:** Migration `api/database/migrations/0490_guard_active_position_titles.php`
adds a soft-delete-aware PostgreSQL unique index on normalized department/title;
the request and service layers now return a field-keyed 422 before insert. The
focused `PositionIntegrityTest` passes, and a live Firefox request using the same
case/whitespace variation returned 422 with no duplicate row.

### LIVE-B2-F02: Permission Denials Expose Laravel Trace Details

**Disposition:** FIXED and live-regression-tested

**Actor:** `employee` / `employee@ogami.test`, `employee`; nearest wrong-role check.

**SPA path:** `/hr/departments` boundary check. The UI is not authorization evidence; the direct API assertion is the finding evidence.

**Exact API evidence:** `POST /api/v1/hr/departments` with `{}` returned `403`, but the JSON body included `exception: Symfony\\Component\\HttpKernel\\Exception\\HttpException`, `file: /var/www/vendor/laravel/framework/src/Illuminate/Foundation/Application.php`, a source `line`, and a full `trace` containing `/var/www/app/Common/Middleware/CheckPermission.php`. The same response shape was observed on the other employee wrong-role `403` checks.

**Reproduction:** Log in as `employee`; send `POST /api/v1/hr/departments` with an empty JSON body. Authorization is correctly denied, but implementation paths and stack frames are returned to the browser.

**Severity:** High. **Category:** Security / information disclosure. **Effort:** S.

**Concrete fix:** Ensure production exception rendering returns only the stable generic authorization envelope for `403` and validation/business errors, with exception details and traces restricted to server logs. Verify the live environment has `APP_DEBUG=false`, add an API contract test asserting `exception`, `file`, `line`, and `trace` are absent from all `401/403/404/422/5xx` responses, and include this in the live smoke gate.

**Fix evidence:** `api/bootstrap/app.php` now renders standard API HTTP errors and
unexpected API 5xx errors through redacted JSON envelopes, even when local debug
mode is enabled. `PositionIntegrityTest::test_api_permission_denials_do_not_expose_debug_details`
passes, and live `POST /api/v1/hr/departments` as `employee` returned only
`{"message":"You do not have permission to perform this action."}` with HTTP 403.

### LIVE-B2-B01: Employee Self-Service Cross-Record Fixture Blocked

**Disposition:** BLOCKED — fixture/process gap, not a product failure

**Actor:** `employee` / `employee@ogami.test`, `employee`

**SPA paths exercised:** `/self-service`, `/self-service/me`, `/self-service/profile`, `/self-service/dtr`, `/self-service/documents`, `/self-service/payslips`, `/self-service/notification-preferences`.

**Exact API evidence:** Own reads returned `200` from `GET /api/v1/hr/self-service/home`, `/profile`, `/attendance?from=2026-09-01&to=2026-09-30`, `/leave-requests`, `/payslips`, `/documents`, and `GET /api/v1/notification-preferences`; the own payslip hash `A3JpYn5NYX` download returned `200`. The employee list route needed to discover a second target returned `403`, and `/api/v1/hr/self-service/profile/{otherEmployee}` is not an owner-comparison route. No cross-employee mutation was attempted.

**Why blocked:** The seeded employee session has no supported second self-service employee fixture and the self-service read API intentionally resolves the employee from the session rather than accepting an employee ID. This run therefore proves own-record reads, not employee A versus employee B comparison.

### LIVE-B2-B02: Leave Request Lifecycle Blocked By Missing Balance

**Disposition:** BLOCKED — seeded fixture gap, not a product failure

**Actor:** `hr` / `hr@ogami.test` for type management; leave maker lifecycle could not start.

**SPA path:** `/hr/leaves`.

**Exact API evidence:** Leave type `E1mbe9bGrz` passed `POST /api/v1/leaves/types` `201`, `PUT` `200`, `DELETE` `204`, restore `PATCH` `200`, and final archive `DELETE` `204`. `GET /api/v1/leaves/balances/me` returned `200`, but no row had `remaining >= 1`.

**Why blocked:** The requested disposable leave create/cancel/reject fixture requires a usable seeded balance. The existing `LeaveBalanceService` was not called, no artificial balance was inserted, and no leave-request product pass is claimed.

## Batch 2 Process Improvements

### LIVE-B2-PI01: Make Live Fixture Cleanup Terminal And Idempotent

Disposable audit records should be created with a run suffix, have an explicit cleanup state, and be archived or rejected in a `finally` phase. Soft-delete-aware unique fields mean a restored row can still block the next run, so the harness must retain each created hash ID and perform cleanup by resource type rather than relying on a static name/code. The final run applied this manually for departments, positions, employees, trainings, shifts, holidays, attendance, leave types, profile requests, and recruitment applications.

### LIVE-B2-PI02: Add A Fixture Manifest Before CRUD Execution

The runner should resolve and record one clean reference hash for every resource before attempting invalid duplicate assertions, and should generate unique disposable values for any column with historical soft-deleted uniqueness. The manifest should include department, position, employee, training, skill, shift, holiday, leave type, public posting, and application hashes so a failed harness step cannot leave a row or cause a false product failure on the next replay.

### LIVE-B2-PI03: Treat Multipart And Method-Spoofed Requests As First-Class API Checks

The live harness initially sent JSON or raw multipart `PATCH` requests where the product requires uploaded files and Laravel method spoofing. The supported helper should always set the XSRF header, use `FormData`, send `POST` plus `_method` for multipart PATCH routes, and retain the exact response status/body. This prevents harness failures from being recorded as completion or certificate defects.

### LIVE-B2-PI04: Provision Explicit Self-Service And Leave Fixtures

The overnight seed should provide two employee identities with distinct owner-scoped payslip/document/attendance records and one employee with a documented unused leave balance. The run manifest should label these as disposable fixtures. If the balance is intentionally absent, the runner must emit `BLOCKED` before attempting a leave request and must not invoke a domain service to manufacture a pass.

### LIVE-B2-PI05: Assert Error-Envelope Redaction Separately From RBAC Status

The role matrix should keep `403` authorization status as one assertion and response-body redaction as another. A correct `403` must still fail the security check if it contains exception class names, application paths, line numbers, or traces. This should be part of every nearest-wrong-role API check, not an incidental observation.

## Batch 3 Findings — S15-S23

### LIVE-B3-F01: Payroll Adjustment Accepts Three-Decimal Money And Rounds It

**Disposition:** FIXED and live-regression-tested

**Severity:** High. **Category:** Financial data integrity / payroll.

**Actor:** `hr` / `hr@ogami.test`, payroll maker.

**Exact evidence:** `POST /api/v1/payroll-adjustments` with `original_payroll_id=29awRYnbjW`, `type=underpayment`, `amount="1.999"`, and a valid reason returned HTTP `201`. The response created adjustment resource `dGypLxpvAg` with `amount: "2.00"` instead of rejecting the over-precision input with `422`.

**Impact:** The operator can submit a value the UI/API contract should reject and receive a different financial amount than entered. This violates the repository decimal-string contract and makes replay/audit evidence disagree with the submitted amount.

**Artifact:** [`artifacts/failure-LIVE-B3-S15-adjustment-overprecision-invalid-hr-201.png`](artifacts/failure-LIVE-B3-S15-adjustment-overprecision-invalid-hr-201.png)

**Recommended action:** Add the same `decimal:0,2` and bounded decimal rule used by the hardened journal/invoice/collection requests to `CreatePayrollAdjustmentRequest`, mirror it in the SPA Zod schema, and add a feature test asserting `1.999`, scientific notation, and overflow return field-keyed `422` with no row created.

**Fix verification (2026-09-14, Firefox live):** `CreatePayrollAdjustmentRequest` now rejects over-precision with the `decimal:0,2` rule. HR reached `/payroll/adjustments`; `POST /api/v1/payroll-adjustments` using finalized source payroll `29awRYnbjW`, `type=underpayment`, `amount="1.999"`, and reason `Batch 3 live precision rejection` returned HTTP `422` with `errors.amount[0] = "The amount field must have 0-2 decimal places."`. The adjustment list contained 2 rows before and after, so no adjustment row was created and no financial record was mutated. No residual observed.

### LIVE-B3-F02: Manual Loan Payment Replay Creates A Second Ledger Payment

**Disposition:** FIXED and live-regression-tested

**Severity:** Critical. **Category:** Financial idempotency / duplicate payment.

**Actor:** Finance payment/write-off path after the disposable company-loan chain completed.

**Exact evidence:** Company loan `DzAwdKb5ld` had principal and balance `1000.10`. The same `POST /api/v1/loans/DzAwdKb5ld/payments` payload (`amount="100.00"`, `payment_date=2026-09-14`, identical remarks) was sent twice. Both requests returned `201`; two distinct payment rows were created, each `100.00`. The first observed balance was `900.10`, but the replay reduced the balance again. Cleanup then paid `800.10`, producing total paid `1000.10` and final balance `0.00`.

**Impact:** A double-click or retried request can accelerate loan settlement and create a duplicate payroll/loan ledger effect. The endpoint has no idempotency key or duplicate natural-key guard.

**Artifact:** [`artifacts/failure-LIVE-B3-S16-payment-replay-accepted.png`](artifacts/failure-LIVE-B3-S16-payment-replay-accepted.png)

**Recommended action:** Require an idempotency key on manual loan payments, persist it under a unique `(loan_id, idempotency_key)` constraint, and return the original payment on replay. Keep the row lock and add a concurrent duplicate feature test that asserts one payment and one aggregate update.

**Fix verification (2026-09-14, Firefox live):** Migration `0491_add_manual_payment_idempotency.php` is applied. A newly disposable company loan `35Mbaeb49K` (`LN-202609-0002`, principal `100.10`) was created through `/self-service/loans` and approved through the UI by `depthead`, `production`, `finance`, and `vp`. Finance reached `/hr/loans/35Mbaeb49K`. The same payment payload (`amount="100.10"`, `payment_date=2026-09-14`, remarks `Batch 3 exact replay verification`) without `Idempotency-Key` returned field-keyed HTTP `422` at `idempotency_key`. With new key `live-b3-loan-1789350199736`, the first request returned `201` and the exact replay returned `201` with the same loan resource state (`id`, `total_paid`, and `balance`). Payment rows changed from 0 to 1; the loan ended `paid`, `total_paid="100.10"`, `balance="0.00"`. Final read shows one manual payment row `40awq4bKG2`. No residual observed beyond the retained disposable paid loan.

### LIVE-B3-F03: CRM Customer Restore Returns 404 After Successful Archive

**Disposition:** FIXED and live-regression-tested

**Severity:** Medium. **Category:** Master-data lifecycle / soft-delete restore.

**Actor:** `crm` / `crm@ogami.test`.

**Exact evidence:** Disposable customer `35Mbaeb49K` passed CRM create, show, partial update, and `DELETE /api/v1/crm/customers/35Mbaeb49K` returned `204`. The documented restore action `PATCH /api/v1/crm/customers/35Mbaeb49K/restore` then returned `404`, so the normal owner could not restore its own archived record. Admin recovery via `PATCH /api/v1/customers/35Mbaeb49K/restore` returned `200`.

**Impact:** CRM master-data operators can archive a customer but cannot complete the documented restore lifecycle. The failure is especially risky when a mistaken archive is the only reason downstream forms stop resolving a customer.

**Artifact:** [`artifacts/failure-LIVE-B3-S19-CRM-customer-restore-404.png`](artifacts/failure-LIVE-B3-S19-CRM-customer-restore-404.png)

**Recommended action:** Ensure the CRM restore route uses a trashed-aware binding and the CRM controller's restore action receives the same soft-deleted model as the Accounting alias. Add a CRM-owned archive/restore feature test, including a downstream customer lookup after restore.

**Fix verification (2026-09-14, Firefox live):** CRM created disposable customer `kABpVvpKRW`, `Live B3 Restore 1789350371194`, through `/crm/customers` (the live CRM UI currently has no customer archive control, so archive was the direct lifecycle request after UI creation). `DELETE /api/v1/crm/customers/kABpVvpKRW` returned `204`; the fixed CRM route `PATCH /api/v1/crm/customers/kABpVvpKRW/restore` returned `200`. Direct lookup returned `200` for the same hash, and the UI lookup `/crm/customers/kABpVvpKRW` rendered the customer name after restore. The disposable customer was finally archived with `DELETE` `204`. No residual observed; the UI archive-control gap is documented as a process/UI limitation, not a restore failure.

### LIVE-B3-F04: AP Bill Payment Replay Creates A Second Payment

**Disposition:** FIXED and live-regression-tested

**Severity:** Critical. **Category:** Financial idempotency / duplicate AP payment.

**Actor:** Finance AP payment path.

**Exact evidence:** Disposable bill `DzAwdKb5ld`, `BILL-C-1789347048794`, had total `321.09`. The same `POST /api/v1/bills/DzAwdKb5ld/payments` payload for `100.00` bank transfer was replayed. Both requests returned `201` and created payment rows `GqkbAVwxd1` and `R8epjLbOaD`, each `100.00`. The duplicate payment created a second AP journal effect before cleanup. Both payment rows were subsequently voided; the final bill read `amount_paid: "0.00"`, `balance: "321.09"`, `status: "unpaid"`.

**Impact:** A browser double-click or retried request can double-post AP cash movement and reduce the bill balance twice. The bill payment route has no durable idempotency key or unique replay guard.

**Recommended action:** Add an idempotency key to `StoreBillPaymentRequest`, enforce a unique `(bill_id, idempotency_key)` constraint, and return the original payment on replay. Add a concurrent replay test that checks one payment, one bill aggregate update, one AP journal, and one reversal when voided.

**Fix verification (2026-09-14, Firefox live):** Migration `0491_add_manual_payment_idempotency.php` is applied. Finance reached the existing disposable bill `DzAwdKb5ld` (`BILL-C-1789347048794`, total `321.09`) at `/accounting/bills/DzAwdKb5ld`. With new key `live-b3-bill-1789350490769`, the first payment returned `201` and the exact replay returned `201` with the same payment resource `MYAbJ3wBl7` and journal entry `8njw6dNk3K`. Exactly one keyed payment row existed; the bill aggregate moved from `amount_paid="0.00"`, `balance="321.09"` to `amount_paid="100.00"`, `balance="221.09"`, `status=partial`. The Finance UI voided payment `MYAbJ3wBl7` with reason `Batch 3 cleanup of disposable replay payment`, returned `200`, and the final bill read `amount_paid="0.00"`, `balance="321.09"`, `status=unpaid`; the void reversal journal was `DlVpZawqJ8`. No residual aggregate effect remains. Historical disposable payment rows from the prior Batch 3 run remain voided audit evidence on the same disposable bill.

## Batch 3 Blocked Evidence

### LIVE-B3-B01: Finance JE Self-Post Requires A Distinct Checker Fixture

**Disposition:** BLOCKED PROCESS EVIDENCE, not a product failure

Finance created and edited balanced JE `MYAbJ3wBl7` (`123.45/123.45`). Finance posting the same entry returned the intended maker/checker `403`. There is only one seeded Finance identity, so no normal distinct accounting checker could be exercised. Admin recovery posted it for the requested PDF/reverse evidence; the entry was then reversed by `kABpVvpKRW`. Do not interpret the admin recovery as a business approval pass.

### LIVE-B3-B02: Finance Customer Mutation Is Not Its Ownership Boundary

**Disposition:** BLOCKED ROLE-PATH EVIDENCE, not a product failure

Finance `POST /api/v1/customers` returned `403`. CRM is the current customer-master owner and its create/update/archive path was exercised separately. The shared Finance customer read surface remained available; no cross-module ownership leak was observed.

### LIVE-B3-B03: Standard Invoice Requires Delivered Quantity; Prebill Path Used

**Disposition:** BLOCKED FIXTURE/PATH EVIDENCE, not a product failure

Standard disposable invoice finalization returned the documented `422`: a standard sales-order invoice requires confirmed delivered quantity. The live run used the documented `lifecycle_type=prebill` and `prebill_reason` for disposable AR invoice, collection, PDF, credit-note, and report evidence. No seeded finalized invoice or delivery was changed.

## Batch 3 Process Improvements

### LIVE-B3-PI01: Group Role Sessions Before Auth Throttle

The live harness exceeded the five-login-per-minute auth limiter while switching roles for one pack. Later failures were `401`/navigation timeouts caused by the test session being throttled, not application behavior. The harness should group all actions for an actor into one session, surface `429` as `HARNESS_BLOCKED`, and never cascade unauthenticated responses into product findings.

### LIVE-B3-PI02: Register Idempotent Success Contracts Explicitly

Accounting period close replay returned `200` with the same period rather than `409/422`; this is safe idempotency, not duplicate creation. The coverage schema should declare `idempotent success` separately from `terminal denial` so a replay that has no duplicate effect is not misclassified.

### LIVE-B3-PI03: Record HTTP Status Variants Per Resource Contract

The de-minimis archive returned `200 {"message":"Deleted."}` rather than `204`, and credit-note creation returned a resource with `200` rather than `201`. The live register should specify accepted status variants and assert the state/effect independently from the transport code.

### LIVE-B3-PI04: Seed Full Financial Lifecycle Fixtures

The run needed a standard delivered SO/delivery to test ordinary AR finalization, a distinct Finance checker to test normal JE posting, and a stock draft bill to test AP draft/post. The overnight seed should provide one disposable fixture for each lifecycle, with a manifest documenting whether records are draft, terminal, or intentionally prebill/service.

### LIVE-B3-PI05: Make Payment Replay Keys Universal Across Money Modules

Collections accepted idempotency-key replay without creating a second collection, while loan payments and bill payments had no equivalent durable key contract. Payment APIs should share one documented idempotency strategy, with before/after ledger totals and reversal references emitted in the audit manifest.

## Batch 4 Findings — S24-S31

### LIVE-B4-F01: Inventory Category Restore Returns 404 After Successful Archive

**Disposition:** FIXED and live-regression-tested

**Severity:** Medium. **Category:** Inventory master-data lifecycle / soft-delete restore.

**Actor:** `warehouse` / `warehouse@ogami.test`, `warehouse_staff`.

**SPA path:** `/inventory/items` (the live category manager is folded into the Items page; `/inventory/categories` is not a mounted SPA route).

**Exact API evidence:** Disposable category `BnoNyGw56v` was created with `POST /api/v1/inventory/item-categories` `201`, edited with `PUT .../BnoNyGw56v` `200`, and archived with `DELETE .../BnoNyGw56v` `204`. The documented restore request `PATCH /api/v1/inventory/item-categories/BnoNyGw56v/restore` returned `404` with `{"message":"The requested resource was not found."}`.

**Impact:** An inventory operator can archive a category but cannot restore it through the documented owner route. A mistaken archive can leave dependent item forms without a recoverable catalog parent.

**Evidence:** [`artifacts/s24-s31-warehouse-inventory-items.png`](artifacts/s24-s31-warehouse-inventory-items.png)

**Recommended action:** Make the category restore binding trashed-aware (`withTrashed()`), or use an explicit trashed lookup in the restore action. Add a feature test for create -> archive -> restore through `/api/v1/inventory/item-categories/{hash}/restore`, including an item-category lookup after restore.

**Fix evidence:** The restore route now uses `withTrashed()`; the archived
category restore returned HTTP 200 live.

### LIVE-B4-F02: Inventory UOM Restore Contract Is Unreachable

**Disposition:** FIXED and live-regression-tested

**Severity:** Medium. **Category:** Inventory master-data lifecycle / missing route.

**Actor:** `warehouse` / `warehouse@ogami.test`, `warehouse_staff`.

**SPA path:** `/inventory/items` UOM selector and item form; no standalone UOM SPA page is mounted.

**Exact API evidence:** Disposable UOM `6r9wnvwOBW` was created with `POST /api/v1/inventory/uoms` `201`, fully edited with `PUT .../6r9wnvwOBW` `200`, and archived with `DELETE .../6r9wnvwOBW` `204`. The API client/documented restore request `PATCH /api/v1/inventory/uoms/6r9wnvwOBW/restore` returned `404`. The live Inventory route file exposes UOM index/create/update/delete but no mounted UOM restore route.

**Impact:** UOM archive is not a reversible lifecycle even though the frontend API surface advertises `restore`. A mistaken UOM archive can break item forms and configured conversions until manual database recovery.

**Evidence:** [`artifacts/s24-s31-warehouse-inventory-items.png`](artifacts/s24-s31-warehouse-inventory-items.png)

**Recommended action:** Register `PATCH /inventory/uoms/{uom}/restore` with a trashed-aware binding and permission consistent with UOM management, or remove the advertised restore contract and provide an explicit irreversible/deactivate UX. Add an HTTP feature test for archive/restore and a conversion-reference guard.

**Fix evidence:** `PATCH /api/v1/inventory/uoms/{hash}/restore` is now mounted
with `withTrashed()` and returned HTTP 200 live.

### LIVE-B4-F03: Approved Supplier Restore Returns 404 After Archive

**Disposition:** FIXED and live-regression-tested

**Severity:** Medium. **Category:** Purchasing master-data lifecycle / soft-delete restore.

**Actor:** `purchasing` / `purchasing@ogami.test`, `purchasing_officer`.

**SPA path:** `/purchasing/approved-suppliers`.

**Exact API evidence:** Disposable approved-supplier link `OX5b9zmwBr` was created with `POST /api/v1/purchasing/approved-suppliers` `201`, updated with `PUT .../OX5b9zmwBr` `200`, and archived with `DELETE .../OX5b9zmwBr` `204`. The documented restore request `PATCH /api/v1/purchasing/approved-suppliers/OX5b9zmwBr/restore` returned `404`.

**Impact:** Purchasing can remove an approved item/vendor relationship but cannot recover it through the normal lifecycle. This can silently remove a sourcing candidate and force a new qualification record instead of restoring the audited relationship.

**Evidence:** [`artifacts/s24-s31-purchasing-approved-suppliers.png`](artifacts/s24-s31-purchasing-approved-suppliers.png)

**Recommended action:** Add `withTrashed()` to the approved-supplier restore route binding or resolve the archived model explicitly. Add a feature test that restores the link and confirms it returns in item sourcing candidates without duplicating the active relationship.

**Fix evidence:** The route now binds archived rows. A live restore with a
replacement active item/vendor link returns a clear HTTP 422 business response
instead of 404/500; the service prevents duplicate active relationships. A
clean pair restores through the same route when no active duplicate exists.

### LIVE-B4-F04: GRN Stock Movement Exposes Raw Integer Reference ID

**Disposition:** FIXED and live-regression-tested

**Severity:** High. **Category:** API data-protection / HashID contract violation.

**Actor:** `warehouse` / `warehouse@ogami.test`, after QC handoff; the movement was produced by the accepted GRN path.

**SPA paths:** `/inventory/grn`, `/inventory/stock-levels`, and the item stock card route.

**Exact API evidence:** `GET /api/v1/inventory/stock-movements?item_id=E1mbe9bGrz&per_page=100` returned movement hash `OX5b9mbBrk` for `reference_type: goods_receipt_note`, but the resource contained `reference_id: 2` rather than a hash ID. The GRN itself was `GqkbAVwxd1`, the PO was `DzAwdKb5ld`, and the incoming inspection was `DzAwdKb5ld`.

**Impact:** A raw database identifier is exposed in an API response and can be correlated with other records. This violates the project-wide rule that integer IDs never appear in API responses or URLs and creates inconsistent client behavior: the movement `id` is obfuscated while its reference is not.

**Before/after evidence:** At location `9eQbB6qb7G`, stock was `1.400`, WAC `10.1250`, value `14.18` before receipt. The accepted GRN movement was `1.500` at unit cost `10.1000`, total `15.15`; after acceptance stock was `2.900`, WAC `10.1121`, value `29.33`.

**Evidence:** [`artifacts/s24-s31-warehouse-inventory-stock-levels.png`](artifacts/s24-s31-warehouse-inventory-stock-levels.png), [`artifacts/s24-s31-warehouse-grn-continuation.png`](artifacts/s24-s31-warehouse-grn-continuation.png)

**Recommended action:** Serialize typed reference hashes or a typed reference object in `StockMovementResource`; never return the raw polymorphic `reference_id`. Add a resource contract test covering GRN, adjustment, transfer, issue, and MRB movement references.

**Fix evidence:** `StockMovementResource` now serializes polymorphic references
through HashIDs. A live stock-movement response returned 19 rows with zero raw
numeric `reference_id` values.

## Batch 4 Blocked Evidence

### LIVE-B4-B01: High-Value Adjustment Checker Fixture Not Available In Live Configuration

**Disposition:** BLOCKED - fixture/configuration evidence, not a product failure

The disposable adjustment `dGypLxpvAg` was `2.000 x 10.1250 = 20.25` and posted immediately as `approved`; Finance replay returned the terminal `422` `Adjustment is already approved.` The live configuration did not expose an enabled threshold through the options surface, and no large disposable stock balance was manufactured solely to force a checker transition.

### LIVE-B4-B02: VP Threshold PO Fixture Not Used

**Disposition:** BLOCKED - disposable high-value fixture absent, not a product failure

The exercised disposable PO `DzAwdKb5ld` totaled `22.62` and returned `requires_vp_approval=false`. Finance approved and Purchasing sent it normally. VP wrong-role inventory denial passed, but a VP money approval was not claimed because creating a high-value PO would require an artificial high-value fixture and downstream stock/receipt effects.

### LIVE-B4-B03: Supplier Listing Review Fixture Absent

**Disposition:** BLOCKED - seeded fixture gap, not a product failure

`purchasing` reached `/purchasing/supplier-listings`; `GET /api/v1/purchasing/supplier-listings?per_page=100` returned `200` with `data: []`. No supplier portal identity or pending listing was manufactured through a service, so approve/reject/bulk replay was not claimed.

### LIVE-B4-B04: Material Issue To Work Order Fixture Preserved

**Disposition:** BLOCKED - disposable production fixture intentionally absent, not a product failure

`warehouse` created disposable material issue `GqkbAVwxd1` with `work_order_id=null`, then cancelled it and observed the reversal. Seeded active work orders were preserved; no disposable work order was created through a domain service. Work-order-linked issuance remains unclaimed.

## Batch 4 Process Improvements

### LIVE-B4-PI01: Resolve Cross-Module Hash References Under The Owning Role

The first run tried to derive the PO-line hash while logged in as `warehouse`; `GET /api/v1/purchasing/purchase-orders/DzAwdKb5ld` correctly returned `403`, so the GRN branch was initially marked harness-blocked. The harness must resolve source-document line hashes under `purchasing` and pass only the captured hash to `warehouse`, with role and API path recorded separately. The continuation then passed this contract without recreating the PO.

### LIVE-B4-PI02: Declare Resource-Returning Delete Status Variants

Material issue cancellation returned `200` with the cancelled resource and the exact replay returned `422`, while the initial register expected `204`. The live schema should distinguish `void response`, `resource-returning lifecycle action`, and `idempotent terminal denial`; otherwise a safe state transition is misclassified as a transport failure.

### LIVE-B4-PI03: Seed Explicit Threshold, Listing, And Work-Order Fixtures

S24-S31 needs a disposable manifest with one high-value pending adjustment, one PR/PO above the VP threshold, one pending supplier listing, and one disposable production work order with reservable material. If any is absent, the runner must emit `BLOCKED` before mutating a seeded record, as this batch did.

### LIVE-B4-PI04: Separate Dispatch State From Notification Delivery Evidence

The sent PO showed `supplier_dispatch.status=confirmed`, `channel=manual_confirmation`, `recipient_count=0`, and `attempts=0`; the acting user's notification feed returned `200` with `total=0` and `unread_count=0`. The live ledger should record dispatch confirmation, recipient resolution, outbox/listener state, and notification delivery as independent assertions instead of treating one confirmed/manual state as proof that a notification was delivered.

## Batch 5 Findings — S32-S40

### LIVE-B5-F01: Calibration PATCH Rejects Its Documented Partial-Edit Shape

**Disposition:** FIXED and live-regression-tested

**Severity:** Medium. **Category:** API contract / master-data lifecycle. **Effort:** S.

**Actor:** `qc` / `qc@ogami.test`, `qc_inspector`.

**SPA path:** `/quality/calibration`, then the calibration form surface.

**Screenshot:** [`artifacts/s32-s40-qc-calibration.png`](artifacts/s32-s40-qc-calibration.png)

**Exact API evidence:** Disposable calibration `GqkbAVwxd1`, equipment code `B5-CAL-3253907`, was created with `POST /api/v1/quality/calibration` → `201`. The direct partial edit `PATCH /api/v1/quality/calibration/GqkbAVwxd1` with body `{ "remarks": "Edited calibration record" }` returned `422` with field errors for required `equipment_code` and `name`. The request class comment says omitted fields mean “keep the current value” on PATCH, but the live rules require both fields.

**Before/after:** Before the PATCH, status was `active`, last calibration date `2026-09-14`, next date `2027-09-14`, and remarks `Disposable calibration fixture`. After the rejected PATCH, the resource remained unchanged. The full create and `POST /api/v1/quality/calibration/GqkbAVwxd1/record` → `200` path passed.

**Cleanup:** The disposable calibration remains as active audit evidence because the live calibration route has no archive/delete action. No seeded calibration record was changed.

**Recommendation:** Make PATCH fields `sometimes` while retaining required fields on POST, or make the frontend always send a complete resource and remove the partial-update contract comment. Add a feature test for a remarks-only PATCH and a live API contract test.

**Fix evidence:** PATCH fields are now conditional on the request method; a live
remarks-only PATCH returned HTTP 200 and preserved the other calibration fields.

### LIVE-B5-F02: Archived Machine Cannot Be Restored Through Its Owner Route

**Disposition:** FIXED and live-regression-tested

**Severity:** Medium. **Category:** Soft-delete lifecycle / MRP master data. **Effort:** S.

**Actor:** `production` / `production@ogami.test`, `production_manager`.

**SPA path:** `/mrp/machines` and the production machine fixture path.

**Screenshot:** [`artifacts/s32-s40-ppc-machines.png`](artifacts/s32-s40-ppc-machines.png)

**Exact API evidence:** Disposable machine `E1mbe9bGrz`, code `B5M-192532`, was created with `POST /api/v1/mrp/machines` → `201`, transitioned `idle → running → idle` with `PATCH /api/v1/mrp/machines/E1mbe9bGrz/transition-status` → `200`, and archived with `DELETE /api/v1/mrp/machines/E1mbe9bGrz` → `204`. The documented restore `PATCH /api/v1/mrp/machines/E1mbe9bGrz/restore` returned `404 {"message":"The requested resource was not found."}`. A final delete replay also returned `404` because the normal binding could not resolve the archived row.

**Before/after:** Before archive, machine status was `idle`, `is_available_now=true`, `deleted_at=null`. After archive, the row was soft-deleted; the restore action could not reach it, so the owner could not recover the master record.

**Cleanup:** The disposable machine remains archived as evidence. The first-run disposable machine `zW1p05N0BA` was separately archived during final cleanup. No seeded machine was altered.

**Recommendation:** Add `->withTrashed()` to the MRP machine restore route or resolve with `Machine::withTrashed()` inside the restore action. Add create → archive → restore coverage and verify the machine returns to the options list.

**Fix evidence:** The machine restore route now uses trashed-aware binding and
the archived live fixture restored with HTTP 200.

### LIVE-B5-F03: Archived Mold Cannot Be Restored Through Its Owner Route

**Disposition:** FIXED and live-regression-tested

**Severity:** Medium. **Category:** Soft-delete lifecycle / tooling master data. **Effort:** S.

**Actor:** `production` / `production@ogami.test`, `production_manager`.

**SPA path:** `/mrp/molds`, mold detail/edit surfaces.

**Screenshot:** [`artifacts/s32-s40-ppc-molds.png`](artifacts/s32-s40-ppc-molds.png)

**Exact API evidence:** Disposable mold `XKEbGkbgWk`, code `B5MD-192799`, was created with `POST /api/v1/mrp/molds` → `201`, commissioned and decommissioned with `POST /commission` and `POST /decommission` → `200`, then archived with `DELETE /api/v1/mrp/molds/XKEbGkbgWk` → `204`. The documented restore `PATCH /api/v1/mrp/molds/XKEbGkbgWk/restore` returned `404 {"message":"The requested resource was not found."}`; the final delete replay also returned `404`.

**Before/after:** Before archive, mold status was `retired` after the decommission lifecycle, with shot count `0`. After archive, the row was soft-deleted and unrecoverable through the owner restore route. The separate execution mold `GkXwO7wP52` reached shot count `2` during the successful WO and was archived after use.

**Cleanup:** Both disposable molds remain archived audit evidence. No seeded mold was mutated.

**Recommendation:** Add trashed-aware binding to the mold restore route and test restore for both available and retired archived molds, including compatibility history after restore.

**Fix evidence:** The mold restore route now uses trashed-aware binding and the
archived retired mold restored with HTTP 200.

### LIVE-B5-F04: Finalized 8D Report PDF Returns HTTP 500

**Disposition:** FIXED and live-regression-tested

**Severity:** High. **Category:** IATF evidence / document generation. **Effort:** M.

**Actor:** `crm` / `crm@ogami.test`, `sales_officer`.

**SPA path:** `/crm/complaints/GqkbAVwxd1`.

**Screenshot:** [`artifacts/s32-s40-crm-complaint-detail.png`](artifacts/s32-s40-crm-complaint-detail.png)

**Exact API evidence:** Disposable complaint `GqkbAVwxd1` (`CMP-202609-0002`) was created with `POST /api/v1/crm/complaints` → `201`. Full D1-D8 data was saved and `POST /api/v1/crm/complaints/GqkbAVwxd1/8d/finalize` returned `200`, with a finalized 8D report. The immediately following `GET /api/v1/crm/complaints/GqkbAVwxd1/8d/pdf` returned `500`, `content-type: application/json`, body `{"message":"An unexpected error occurred."}` instead of a PDF.

**Before/after:** Before finalization, the complaint was `open` and the 8D report had no finalized timestamp. After finalization, the 8D report was finalized and the linked NCR `MYAbJg3wBl` was subsequently closed with `rework`; only the PDF stream failed.

**Cleanup:** Complaint `GqkbAVwxd1` was resolved and closed; NCR `MYAbJg3wBl` was closed with rework disposition, one corrective action, and one preventive action. The closed complaint/NCR remain disposable IATF audit evidence. No seeded complaint or NCR was changed.

**Recommendation:** Capture the server-side PDF exception and fix the `pdf.complaint-8d` render/vault path. Add a feature test that finalizes a complete 8D and asserts `200 application/pdf`, private disposition, stored document linkage, and replay behavior.

**Fix evidence:** The PDF view now renders the backed severity enum value. The
same finalized complaint returned HTTP 200 with `application/pdf` live.

### LIVE-B5-F05: Manual MRP Run Response Leaks Raw Machine Integer IDs

**Disposition:** FIXED and live-regression-tested

**Severity:** High. **Category:** API data protection / HashID contract. **Effort:** S.

**Actor:** `ppc` / `ppc@ogami.test`, `ppc_head`.

**SPA paths:** `/mrp/plans` and `/production/schedule`.

**Screenshots:** [`artifacts/s32-s40-ppc-plans.png`](artifacts/s32-s40-ppc-plans.png), [`artifacts/s32-s40-ppc-schedule.png`](artifacts/s32-s40-ppc-schedule.png)

**Exact API evidence:** Before the run, `GET /api/v1/mrp/runs?per_page=100` returned an empty list. `POST /api/v1/mrp/runs` returned `202` for run hash `dGypLxpvAg`; the response’s `summary.scheduling.scheduled` entries contained `machine_id: 1` as a raw integer while the same response used hashed `work_order_id` values and the normal machine list exposed hashed IDs. This violates the project-wide no-integer-ID response contract.

**Before/after:** Before: no run response existed. After: completed run reported `sales_orders_evaluated=3`, `plans_generated=3`, `shortages_found=0`, and the raw integer machine reference in the scheduling summary. The corresponding machine target is an internal database identifier, not a hash ID.

**Cleanup:** The manual run, its three generated plans, and generated WO chain records remain explicit live-run evidence because there is no safe run rollback route. The run did not alter pre-existing active WO rows; it generated new planning records from three seeded SOs. This residual is called out in coverage and `LIVE-B5-PI02`.

**Recommendation:** Serialize `machine_id` through the machine model’s `hash_id` accessor in `MrpRunResource`/the scheduler summary, and add a recursive API contract test that rejects numeric `id`, `*_id`, and reference fields in all MRP run responses.

**Fix evidence:** New scheduler summaries encode machine/mold IDs directly, and
`MrpPlanningResponseSerializer` now normalizes historical nested summaries. A
live read of all stored MRP runs found zero numeric `machine_id`/`mold_id` values.

## Batch 5 Blocked Evidence

### LIVE-B5-B01: Delivery Proof And Customer Confirmation Write Fixture Blocked

**Disposition:** BLOCKED — role/fixture evidence, not a product failure.

**Actor/UI:** `impex` / `/supply-chain/deliveries/GqkbAVwxd1`; the driver UI `/driver` was also exercised for assigned-delivery scope.

**Exact API evidence:** `GET /api/v1/supply-chain/deliveries/GqkbAVwxd1/proofs` returned `200` and showed one seeded signed-DR proof. `POST /api/v1/supply-chain/deliveries/GqkbAVwxd1/proofs` as `impex` returned `403`; `POST /api/v1/supply-chain/deliveries/GqkbAVwxd1/confirm` as `impex` returned `403`. `GET /api/v1/driver/deliveries` as `driver` returned only the assigned delivery `GqkbAVwxd1` (`200`), while broad delivery/fleet reads returned `403`.

**Why blocked:** ImpEx has delivery view and fleet/shipment management, but not `supply_chain.deliveries.create` or customer-confirm permission. The disposable SO `zW1p05N0BA` had no passed outgoing inspection, so no disposable delivery was manufactured and no seeded delivery was confirmed or altered.

### LIVE-B5-B02: Outgoing CoC And Delivery-Fail Block Fixture Absent

**Disposition:** BLOCKED — seeded chain fixture gap, not a product failure.

**Actor/UI:** `qc` / `/quality/inspections`, plus `crm` SO `zW1p05N0BA` and `production` WO `MYAbJ3wBl7`.

**Evidence:** In-process inspection `MYAbJ3wBl7` completed `passed` after actual measurements, but no output-backed outgoing inspection target was available. The delivery inspection path requires a passed outgoing inspection and remaining accepted quantity; creating a delivery without that source would have bypassed the chain and was not attempted. `GET /api/v1/quality/inspections/{hash}/coc` was therefore not claimed as supported.

### LIVE-B5-B03: Shortage And Consolidated-PR Fixture Not Present

**Disposition:** BLOCKED — live data outcome, not a product failure.

**Actor/UI:** `ppc` / `/mrp/plans` and `/production/schedule`.

**Exact API evidence:** Before the run, `GET /api/v1/mrp/plans?per_page=100` and `GET /api/v1/mrp/runs?per_page=100` returned empty lists. Manual `POST /api/v1/mrp/runs` returned `202` with `sales_orders_evaluated=3`, `shortages_found=0`, `prs_created=0`, `plans_generated=3`.

**Why blocked:** The live fixture had no material shortage and no consolidated PR candidate. The run was executed once as requested; no artificial shortage, stock depletion, or duplicate PR was manufactured.

### LIVE-B5-B04: Routing Authoring Fixture Has Zero Operations

**Disposition:** BLOCKED — fixture shape gap, not a product failure.

**Actor/UI:** `ppc` / `/production/routings`.

**Exact API evidence:** Seeded routing `dGypLxpvAg` detail returned `200` with `operations: []`. `POST /api/v1/production/routings/dGypLxpvAg/duplicate` as PPC returned `422` with `A routing requires at least one operation.` Production’s same mutation probe correctly returned `403`.

**Why blocked:** No valid seeded routing with an operation existed to duplicate/activate, and the execution machine/mold fixtures were archived after the disposable WO. No seeded routing was changed.

## Batch 5 Process Improvements

### LIVE-B5-PI01: Declare Read, Create, Mutate, And Confirm Permissions Separately

CRM product/price-agreement writes and ImpEx proof/customer-confirm actions returned the expected `403` even though their list/detail UIs rendered. The live role matrix should declare each operation independently before execution so authorization boundaries are not counted as runner failures.

### LIVE-B5-PI02: Scope Manual MRP Runs To Disposable Audit Fixtures

The requested manual run returned `202`, evaluated three seeded SOs, generated three plans/WOs, and found no shortage. An overnight fixture should provide a disposable SO selector or dry-run mode, otherwise a legitimate run test creates durable planning records from seeded orders and cannot be rolled back through the live API.

### LIVE-B5-PI03: Seed Complete Cross-Module Fixtures Before S32-S40

The manifest needs one routing with an operation, one output-backed passed outgoing inspection, one delivery with a proof-write actor, and one customer-confirmation target. Without these, routing duplication, CoC generation, delivery proof write, and outgoing-fail delivery blocking must remain `BLOCKED`.

### LIVE-B5-PI04: Model Numeric And Visual Inspection Rows Separately

Numeric inspection rows require `measured_value` so the service derives pass/fail; visual rows require explicit `is_pass`. The final live inspection passed once the harness respected that contract. The fixture schema and UI automation helper should encode this distinction rather than treating every row as numeric.

### LIVE-B5-PI05: Separate Dispatch State From Notification Delivery

The complaint/NCR and SO handoffs completed while `GET /api/v1/notifications?per_page=50` returned `200`, `total=0`, `unread_count=0`. The ledger must capture recipient resolution, outbox/listener state, notification IDs, and delivery separately.

### LIVE-B5-PI06: Emit A Residual Manifest For Every Live Batch

This batch archived disposable shipments/documents/containers/vehicles/customers/machines/molds, cancelled the failed disposable WO, and retained closed SO/WO/inspection/complaint/NCR/calibration/plan/run evidence where terminal or no archive route existed. The runner should write this manifest automatically before the next batch.

### LIVE-B5-PI07: Declare Idempotent Terminal Success In The Coverage Schema

The final complaint close replay returned `200` with the same closed resource and no duplicate effect. The schema should distinguish safe idempotent success from a terminal denial (`409/422`) so replay behavior is recorded without being misclassified.

## Batch 6 Findings — S41-S45

### LIVE-B6-F01: QC Can Approve Its Own PPAP Submission

**Disposition:** FIXED — maker-checker control is now enforced; live rerun requires a distinct checker fixture.

**Severity:** High. **Category:** IATF supplier-quality evidence / segregation of duties. **Effort:** M.

**Actor:** `qc` / `qc@ogami.test`, `qc_inspector`.

**SPA/API paths:** `/quality/inspections` rendered the quality review page; PPAP has no employee SPA route. Direct API paths were `POST /api/v1/quality/ppap`, `PATCH /api/v1/quality/ppap/dGypLxpvAg/submit`, `PATCH /api/v1/quality/ppap/dGypLxpvAg/review`, and `PATCH /api/v1/quality/ppap/dGypLxpvAg/approve`.

**Exact evidence:** QC created disposable PPAP `dGypLxpvAg` (`PPAP-202609-0001`) for vendor `GqkbAVwxd1` and item `E1mbe9bGrz`; create returned `201`. The same authenticated QC user updated the PSW element to `accepted`, submitted (`200`), reviewed (`200`), and approved (`200`). The approved response contained the same QC identity as `submitter` and `approver` (`WDQwPGbYPx`). No distinct QC manager or checker fixture exists, and all four actions use `quality.ppap.manage`.

**Before/after:** `draft -> submitted -> under_review -> approved`; `expires_at` was set to `2029-09-14`. Approved parent edit and PSW evidence mutation were correctly rejected with `422`, so the evidence-freeze guard works after approval. The defect is the approval actor boundary before approval.

**Impact:** A single QC operator can create, review, and approve the supplier PPAP package that is later used as supplier/item quality evidence. The package is auditable but not independently checked.

**Cleanup:** PPAP `dGypLxpvAg` remains explicit disposable approved audit evidence. A second exact source/vendor/level create was observed as draft `GqkbAVwxd1`; it was not treated as a duplicate defect because PPAP revision semantics are undocumented. No seeded PPAP existed before this batch.

**Recommendation:** Add a distinct review/approve permission or enforce a maker-checker actor distinctness rule on PPAP review/approval. Seed a QC reviewer identity only if the business actually has one; do not grant a missing role during recovery. Add a feature test asserting that the PPAP creator cannot approve and that the second actor’s approval is persisted.

**Fix evidence:** PPAP review and approval now reject the submitting user with
HTTP 403 through `ForbiddenActionException`. A live QC submission replay moved
`draft -> submitted` and same-user review returned 403. The seeded environment
still lacks a distinct QC checker, so independent approval remains blocked rather
than being falsely claimed as passed.

### LIVE-B6-F02: RMA PATCH Uses The Full Create Contract For A Partial Edit

**Disposition:** FIXED and regression-tested

**Severity:** Medium. **Category:** API ergonomics / draft editing. **Effort:** S.

**Actor:** `crm` / `crm@ogami.test`, `sales_officer`.

**SPA path:** `/return-management/MYAbJ3wBl7` was reachable for the disposable finance-only RMA.

**Exact API evidence:** `PATCH /api/v1/return-management/return-requests/MYAbJ3wBl7` with the valid draft-only partial payload `{"customer_notes":"S45 partial edit continuation"}` returned `422` with field errors for required `type` and `items` (`A return needs at least one line item.`). The endpoint uses `UpdateReturnRequestRequest extends StoreReturnRequestRequest`, so a partial draft edit is validated as a complete create payload.

**Impact:** Autosave, a narrow prefilled-field edit, or a client retry that sends only changed fields cannot update a draft. The UI must re-submit every source/provenance line and party field even when the operator changes only notes. This is not a data mutation or security leak; it is a contract mismatch requiring an explicit product decision.

**Cleanup:** The RMA remained a draft at the time of the failed partial edit and was then submitted through the intended full lifecycle. No seeded source line was reserved.

**Recommendation:** Either document and enforce `PATCH` as full replacement with a distinct `PUT` route, or make `UpdateReturnRequestRequest` genuinely partial while revalidating persisted provenance and source reservations in the service. Add tests for a partial notes edit, a partial line edit, and stale-source rejection.

**Fix evidence:** `UpdateReturnRequestRequest` now makes top-level fields partial;
the service merges persisted draft fields and only releases/rebuilds source
allocations when `items` is supplied. The focused HTTP regression test passes for
a notes-only PATCH.

## Batch 6 Blocked Evidence

### LIVE-B6-B01: PPAP Element Set And Distinct Checker Fixture Absent

**Disposition:** BLOCKED — fixture/process gap, not a second product failure.

PPAP `POST /api/v1/quality/ppap` auto-created exactly one PSW element. There is no add-element route, no seeded PPAP with the 18-element set, and no distinct QC checker identity. Purchasing only read existing vendor/item master data to let QC exercise the real PPAP mutation path. No DB service or synthetic role was used.

### LIVE-B6-B02: Maintenance Assignment And Schedule Management Permission Absent

**Disposition:** BLOCKED — seeded role boundary, not a product failure.

`maintenance` rendered the maintenance pages and completed disposable WOs `MYAbJ3wBl7` and `35Mbaeb49K`, but `GET /api/v1/maintenance/work-orders/assignees` and `POST /api/v1/maintenance/schedules` returned generic `403` because `maintenance_tech` lacks `maintenance.wo.assign` and `maintenance.schedules.manage`. No maintenance-head account or permission was invented. Invalid spare references returned the expected `422`.

### LIVE-B6-B03: Completed-Period Depreciation And Disposal Checker Fixture

**Disposition:** BLOCKED — period/SoD evidence, not a product failure.

Finance created disposable asset `VW1wmxbLZ0` for `1200.00`; monthly expected depreciation was `50.00`. `POST /api/v1/asset-depreciations/run` for `2026-09` returned the expected `422` because the period is not complete. A prior-period run would have posted against seeded assets, so it was not manufactured. Finance requested disposal for `600.00`, self-approval correctly returned `403`, and Finance cancelled the request; VP read access passed but no pending request remained for a legitimate VP approval.

### LIVE-B6-B04: Source-Backed RMA And Downstream Disposal Fixture Absent

**Disposition:** BLOCKED — seeded source/role fixture gap, not a product failure.

`crm` source options for Honda customer `R8epjLbOaD` returned `200` but no invoice/delivery line with positive remaining quantity, product provenance, and stockable item hash. The finance-only fallback RMA `MYAbJ3wBl7` received `1.000` successfully and QC staging returned `manual_required` because product `BB-001` had no active inspection spec. Warehouse dispose returned `403` because the seeded role only has `return_management.receive`; no requested-chain business actor had `return_management.dispose` or `return_management.complete`. Inventory, replacement-PO, credit-note, and auto-NCR effects were therefore not claimed, and admin was not used as a business-process substitute.

### LIVE-B6-B05: Terminal Replay Probe Used Wrong RMA Actor

**Disposition:** BLOCKED — harness actor-order evidence, not a product failure.

Rejected RMA `35Mbaeb49K` reached `rejected` under `depthead`. The immediate submit replay was sent before switching back to CRM and returned the expected authorization `403`, not the intended terminal-state `422`. The corrected cancel path used CRM: draft RMA `kABpVvpKRW` reached `cancelled`, and CRM submit replay returned `422`. No state was changed by the wrong-actor probe.

## Batch 6 Process Improvements

### LIVE-B6-PI01: Seed PPAP Revision And Checker Fixtures

The live manifest needs one disposable PPAP with all 18 element rows, a second QC checker identity, and an explicit duplicate/revision policy. Without those fixtures, element completeness and independent approval cannot be separated from the current one-permission route behavior.

### LIVE-B6-PI02: Declare Permission Granularity For Maintenance And RMA

The role matrix should declare `view`, `create`, `assign`, `complete`, `receive`, `inspect`, `dispose`, and `complete` separately. Maintenance UI pages rendered while assignee and schedule writes were denied; RMA approval and receipt succeeded while disposal/completion were unavailable to the requested business actors. This must be classified before execution rather than reported as generic runner failure.

### LIVE-B6-PI03: Provide A Complete Disposable RMA Chain

The overnight fixture should include a customer with a delivered/invoiced, still-returnable source line, an inventory item with stock, a product with an active inspection spec, a quarantine and good-stock location, and a seeded actor allowed to dispose/complete. This enables real inventory, credit, replacement, NCR, and terminal replay evidence without touching seeded documents.

### LIVE-B6-PI04: Make Depreciation Fixtures Period-Safe

Exact-money/idempotency checks need an already completed disposable period or an asset acquired in a closed prior month whose run does not include seeded assets. The runner must not call a broad prior-period depreciation run merely to satisfy an exact amount assertion.

### LIVE-B6-PI05: Define The RMA Draft Update Contract

The SPA/API fixture schema should state whether RMA `PATCH` is partial or full replacement. If partial edits are supported, the request must accept changed fields while the service revalidates the persisted source allocation; if full replacement is intended, expose that clearly as `PUT` and make the SPA request shape explicit.

### LIVE-B6-PI06: Treat Duplicate PPAP Submission As An Explicit Policy

An exact same vendor/item/level PPAP create returned `201` and produced a second draft. That may be a legitimate revision workflow, but the API should either return the existing revision/require a revision marker or document why parallel submissions are valid. The live harness currently records this as observed, not as a failure.

## Batch 7 Findings — S46-S49

### LIVE-B7-F01: Customer Delivery Confirmation Returns 500 After Durable Commit

**Disposition:** FIXED and live-regression-tested

**Severity:** High. **Category:** Customer portal / delivery confirmation / post-commit response integrity. **Effort:** M.

**Actor and tenant:** `customer-portal` / `portal@cust.test`, customer hash `dGypLxpvAg` (`Toyota Motor Philippines, Inc.`).

**SPA/API paths:** `/portal/customer/deliveries`; `POST /api/v1/b2b/customer/deliveries/dGypLxpvAg/confirm`.

**Exact evidence:** The customer portal read own delivery `dGypLxpvAg` (`DEL-20260914-0001`) as `delivered`, with proof hash `dGypLxpvAg` and `confirmed_at: null`. The customer submitted receiver fields through the portal confirm route. The response was HTTP `500` with the redacted body `{"message":"An unexpected error occurred."}` and request ID `636d4e85-8cdc-49a2-9450-783e5c2f2716`.

**Server evidence:** `auth-2026-09-14.log` records the request as `LazyLoadingViolationException: Attempted to lazy load [product] on model [App\Modules\CRM\Models\SalesOrderItem] but lazy loading is disabled` at the response path. The exception occurred after `CustomerPortalService::confirmDelivery()` delegated to the shared delivery confirmation flow.

**State/effect evidence:** A read-only PostgreSQL reconciliation after the response showed the delivery had committed as `confirmed`, `confirmed_at` was populated, and the invoice handoff status was `generated`. The customer therefore sees a failed confirmation even though the business state and invoice handoff were durably changed. No replay was sent after the 500. This is not a safe idempotent-success contract because the client cannot know whether retrying will duplicate downstream effects.

**Impact:** A customer can retry a request that already confirmed the delivery and generated its invoice handoff. The portal exposes no successful response or correlation-safe recovery instruction, and the same serialization defect can prevent the idempotent replay response from being delivered.

**Artifact:** [`artifacts/s46-s49-b7-customer-deliveries.png`](artifacts/s46-s49-b7-customer-deliveries.png)

**Recommended action:** Eager-load `salesOrderItem.product` anywhere the confirmed delivery resource serializes delivery items, or make the resource use only relationships guaranteed by the delivery service. Add a feature test using the customer guard that asserts `POST .../confirm` returns HTTP `200` with `status=confirmed`, invoice-handoff state, and no lazy-loading exception; add an exact replay test asserting one confirmation and one invoice handoff. For the already-confirmed live fixture, use the existing delivery-invoice recovery/read path rather than a database reset.

**Fix evidence:** `DeliveryService::show()` now eager-loads
`items.salesOrderItem.product`. The focused customer-portal confirmation suite
passes all four tests, and replaying the confirmed live delivery returned HTTP
200 with `status=confirmed` and four serialized items.

## Batch 7 Blocked Evidence

### LIVE-B7-B01: Second Supplier And Customer Tenant Fixtures Absent

**Disposition:** BLOCKED — fixture gap, not a product failure

Only supplier `portal@supp.test` linked to vendor `dGypLxpvAg` and customer `portal@cust.test` linked to customer `dGypLxpvAg` were present. Supplier credentials against `/api/v1/b2b/customer/me` and customer credentials against `/api/v1/b2b/supplier/me` returned `401`; no tenant A versus tenant B read/action comparison could be performed without inventing a tenant or granting access.

### LIVE-B7-B02: Internal Schedule Review Fixture Absent

**Disposition:** BLOCKED — live fixture gap, not a product failure

`admin` reached `/accounting/portal-access`; `GET /api/v1/b2b/portal-access/delivery-schedules?per_page=100` returned `200` with `data: []`. Acknowledge/reject and replay were not attempted against a seeded schedule.

### LIVE-B7-B03: Supplier Action Fixtures Absent

**Disposition:** BLOCKED — tenant fixture/state gap, not a product failure

Supplier `GET /api/v1/b2b/supplier/purchase-orders?per_page=100`, delivery schedules, item listings, and PPAP submissions all returned `200` with empty collections. The supplier PO action set, shipping document upload/download, invoice draft/PDF, schedule creation, listing mutation, and PPAP read/action chain could not be exercised safely. No other vendor PO or terminal seeded record was targeted.

### LIVE-B7-B04: Customer Negotiation And Reset Tokens Not Available

**Disposition:** BLOCKED — workflow/mail fixture gap, not a product failure

Customer catalog and order placement passed with disposable draft `E1mbe9bGrz` / `SO-202609-0014`, but the order was `draft`; both response attempts returned the expected `422` because it was not open to customer response. A successful negotiation requires an internal CRM release actor and a suitable disposable state, which was not manufactured during this batch. Forgot-password known/unknown parity passed, but no reset token was exposed through the live mail channel, so successful token completion was not claimed.

### LIVE-B7-B05: Factory And Maintenance Mutation Fixtures Preserved

**Disposition:** BLOCKED — fixture preservation, not a product failure

`production` saw an empty active factory list from `GET /api/v1/production/work-orders?...`; `maintenance` had only existing disposable completed/cancelled records in its mobile list. No production output/QC result or maintenance status was changed merely to create a write target. Driver assigned delivery read scope passed, but no status/photo write was sent to the seeded delivery.

## Batch 7 Process Improvements

### LIVE-B7-PI01: Make Portal Confirmation Serialization-Safe

Portal actions that delegate to internal services need a response-resource contract test under the custom guard. The delivery confirmation transaction completed, but the response attempted to lazy-load `SalesOrderItem.product` after lazy loading was disabled. Eager-load all relationships required by the resource before commit/return, and assert the final serialized shape rather than only the database transition.

### LIVE-B7-PI02: Record Commit State Separately From HTTP Outcome

The batch observed HTTP `500` alongside a committed delivery confirmation and generated invoice handoff. The live audit ledger should capture transaction state, outbox/handoff state, and response status independently, then classify “committed but response failed” as a distinct critical outcome requiring recovery/replay guidance.

### LIVE-B7-PI03: Seed A Disposable Portal Scenario Manifest

S46-S49 needs one disposable sent supplier PO with accepted GRN, one pending supplier schedule/listing, one customer order released for response, one delivered disposable customer delivery with proof, one driver-assigned disposable delivery, and one active factory WO. Each fixture needs a documented cleanup route so the audit does not mutate seeded chain evidence or falsely mark unavailable actions as passed.

### LIVE-B7-PI04: Provision Explicit Cross-Tenant And Password-Test Channels

Tenant A/B isolation cannot be demonstrated with one portal identity per portal type. The overnight seed should provide two supplier and two customer identities with separate records. Password-flow verification also needs a test mail capture or documented reset-token handoff that does not require reading deployment secrets or inventing a token.

### LIVE-B7-PI05: Encode UI Geometry Assertions At The Required Touch Threshold

The batch observed no horizontal overflow at `390x844` and `768x1024`, and sampled controls were not below 40px. The audit should assert the design-system touch threshold explicitly, include the high-contrast theme attribute, and sample the actionable control set rather than using a 40px-only heuristic.

### LIVE-B7-PI06: Make Notification And Action-Link Evidence First-Class

Portal mutation responses did not expose notification IDs or action links, and the supplier/customer guard route families have no portal notification feed. The live ledger should either expose a tenant-scoped notification/action-link read contract or explicitly join the internal notification/outbox evidence under the nearest business actor. Without that contract, portal handoff success cannot prove recipient delivery or link ownership.

## Batch 8 Findings - A01-A09

### LIVE-B8-F01: Asset Disposal Workflow Deadlocks Its Finance Maker

**Disposition:** FIXED with distinct seeded checker fixture and live-regression-tested

**Actor sequence:** `finance` maker/reviewer -> `vp` expected final approver. No `system_admin` substitution was used.

**Exact evidence:** Finance created disposable asset `7yZw4BNA0o` / `AST-2026-0002` through `POST /api/v1/assets` `201`, acquisition cost `1200.00`, book value `1200.00`, monthly depreciation `50.00`. Finance submitted `POST /api/v1/assets/7yZw4BNA0o/dispose` `200`, disposal amount `600.00`; the returned approval chain had step 1 `finance_officer` pending and step 2 `vice_president` pending. Finance attempting `POST /api/v1/assets/7yZw4BNA0o/dispose/approve` returned `403` with `You cannot act on a record you submitted.` VP attempting the same endpoint returned `403` with `Only users with role 'finance_officer' can approve this step.` Production's wrong-role probe returned `403`. The asset remained `active`, the disposal request remained pending, and no disposal journal or terminal asset state was produced. Screenshot: [`failure-live-b8-11-a09-vp-final-disposal-approval.png`](artifacts/failure-live-b8-11-a09-vp-final-disposal-approval.png).

**Impact:** The live current WorkflowSeeder chain cannot complete the requested Finance-maker -> VP disposal path because the maker's role is also the first approval role. The maker-checker guard correctly prevents Finance self-approval, but no distinct Finance reviewer fixture or alternate first-step authority is available. The clean cancel fixture `DAMpXKw6VJ` / `AST-2026-0003` did cancel successfully and remained active, proving the cancel arm but not resolving the happy-path deadlock.

**Recommended action:** Make the disposal maker distinct from the Finance review step, or define the live chain as VP-only when Finance is the requester. Add a seeded disposable actor/fixture test that asserts the exact current chain can reach `disposed` and creates exactly one disposal journal on the final VP action. Do not use `system_admin` as a business approver.

**Fix evidence:** `finance2@ogami.test` is now a distinct same-role Finance
checker in `DemoAccountSeeder` and `docs/SEEDS.md`. It approved the Finance-made
request, then `vp@ogami.test` approved the terminal step live; asset
`7yZw4BNA0o` reached `disposed` with HTTP 200. No system-admin business bypass
was used.

### LIVE-B8-F02: Approval Notification Delivery Was Not Observable In Queried Recipient Feeds

**Disposition:** OBSERVED LIVE GAP — recipient/queue boundary unresolved, not counted as a confirmed product failure

After completed A02-A09 actions, the relevant checker sessions queried `GET /api/v1/notifications?per_page=100` and received HTTP `200` with no run-specific notification matching the target; the queried feeds were empty for A02-A07 and had only unrelated seeded notifications for A08. The exception queue `GET /api/v1/dashboards/action-center` also returned HTTP `200` without the approval target. This does not prove every recipient missed a notification because the run did not inspect database/outbox/queue state and the exception queue is not the approval board. A01 had no record and therefore no notification expectation.

**Recommended action:** Make the live audit contract record notification IDs and recipient aliases immediately after each submit/approve/reject event, and separately record queue/outbox delivery state. Expose or document the approval-board link for each workflow type; do not use the unrelated action-center endpoint as an approval-card assertion.

### LIVE-B8-F03: Approval Board PO Filter Returns HTTP 500

**Disposition:** FIXED and live-regression-tested

**Actor:** `depthead`, `finance`, and `vp`.

**Exact evidence:** Supplemental serial Firefox reads of `GET /api/v1/approvals/board?type=po&pending_limit=100&history_limit=100` returned HTTP `500` and `{"message":"An unexpected error occurred."}` for all three actors. The same endpoint with `type=loan` returned `200` and included `BnoNyGw56v` / `CA-202609-0003` and `6r9wnvwOBW` / `LN-202609-0004`; `type=pr` returned `200` and included `ajzw1lb3eW` / `PR-202609-0006`. PO-board failure screenshots: [`failure-live-b8-board-1-depthead-po.png`](artifacts/failure-live-b8-board-1-depthead-po.png), [`failure-live-b8-board-2-finance-po.png`](artifacts/failure-live-b8-board-2-finance-po.png), and [`failure-live-b8-board-3-vp-po.png`](artifacts/failure-live-b8-board-3-vp-po.png).

**Impact:** The normal approval-card surface cannot load purchase-order approvals for any of the tested seeded business actors, even though direct PO approval succeeds and PR/loan board filters work. No PO mutation was sent by the supplemental probe.

**Recommended action:** Trace the PO board query/resource serialization against the live approved PO rows, add a feature test for `type=po` under department head, Finance, and VP scopes, and assert a redacted JSON error rather than a generic 500 if a row is malformed.

**Fix evidence:** `ApprovalBoardService` now qualifies the source table
projection before vendor joins. The focused ApprovalBoard and ApprovalBoardScope
suites pass (15 tests/56 assertions), and live PO-board reads return HTTP 200 for
department head and Finance with no source-ID warning; the VP route is covered by
the same permission-derived board path.

## Batch 8 Blocked Evidence

### LIVE-B8-B01: Leave Maker Fixture Has No Usable Balance

**Disposition:** BLOCKED — seeded fixture gap, not a product failure

`employee` (`0ldwDmpVa9`) opened `/self-service/leaves`; `GET /api/v1/leaves/balances/me` returned HTTP `200` with `data=[]`. Active leave types were available, but no balance row had usable days. No request, approval, rejection, cancellation, calendar, or balance mutation was manufactured.

### LIVE-B8-B02: Automatic PR Conversion Was Still Pending

**Disposition:** BLOCKED — asynchronous handoff evidence, not a product failure claim

Approved threshold PRs `BnoNyGw56v` / `PR-202609-0004` and `ajzw1lb3eW` / `PR-202609-0006` returned `po_conversion_status=pending` after VP approval. The A06 PO was created manually by `purchasing` from the approved PR to exercise the requested PO maker chain; no queued conversion replay or duplicate-conversion result was claimed. No queue/scheduler reset or admin recovery was used.

## Batch 8 Process Improvements

### LIVE-B8-PI01: Seed A01 Leave Balance And Disposable Approval Manifest

The overnight seed should provide one documented employee leave balance with at least two days and a per-pack manifest containing one happy target and one independent reject/cancel target. The manifest should persist target hashes across serial continuation workers so a corrected harness does not create duplicate PR/PO/loan residuals.

### LIVE-B8-PI02: Separate Approval Board, Exception Queue, And Notification Assertions

The live runner initially treated `/api/v1/dashboards/action-center` as an approval-card source. The fixture contract should require a resource-specific `approvalBoardType` and reject unsupported types; approval board, exception queue, notification feed, and outbox delivery must be recorded in separate fields.

### LIVE-B8-PI03: Add A Distinct Disposal Reviewer Fixture

The A09 chain needs a supported Finance requester/reviewer separation or a documented VP-only route when Finance creates the request. The fixture should assert maker identity, first pending role, final approver, terminal asset state, exactly one disposal journal, and a clean cancellation target before execution.

### LIVE-B8-PI04: Pin Approval Endpoint Response Contracts

The salary-adjustment create endpoint returned HTTP `200` with a created pending resource, while the harness assumed `201`; the endpoint contract should be explicit in the route/resource test and generated audit manifest. Similarly, direct API assertions should distinguish permission-context `403` from a product transition `422` before counting failures.

## Batch 9 Findings - A10-A18

### LIVE-B9-F01: Customer SO Response Replay Creates A Second Accepted Response

**Disposition:** FIXED and regression/live-tested

**Severity:** High. **Category:** Customer portal negotiation / idempotency / order-history integrity.

**Actor and tenant:** `customer-portal` / `portal@cust.test`, customer `Toyota Motor Philippines, Inc.`

**Target:** Disposable SO `XKEbGkbgWk` / `SO-202609-0016`, total `39.20`.

**Exact evidence:** The valid draft-to-confirmation path was followed: customer portal create returned `201` with `status=draft`; CRM `POST /api/v1/crm/sales-orders/XKEbGkbgWk/request-customer-confirmation` returned `200` and set `customer_confirmation_requested_at`; the customer portal `POST /api/v1/b2b/customer/orders/XKEbGkbgWk/respond` returned `201` with response ID `dGypLxpvAg`, `type=accept`, and `status=accepted`. An exact replay of the same response returned `201` again with a different response ID `GqkbAVwxd1` and `status=accepted`.

**Impact:** A browser double-click, network retry, or client timeout can append multiple accepted negotiation responses to one sales order. The response history is not idempotent and does not expose a durable idempotency key or reject an already-responded order.

**Artifact:** [`artifacts/a10-a18-cont-a14-corrected-crm-so.png`](artifacts/a10-a18-cont-a14-corrected-crm-so.png)

**Recommended action:** Add a durable idempotency key or a unique pending/accepted response guard keyed by sales order and customer-response cycle. On an exact replay, return the original response or a documented `409/422` without inserting a second row. Add a feature test that asserts one response row, one notification/handoff, and stable SO state under exact replay.

**Fix evidence:** Customer `accept` replay now returns the latest existing
accepted response without inserting another row. The focused portal response
suite passes 9 tests/48 assertions, and the live replay did not increase the
historical row count. Two rows from the pre-fix audit remain as immutable audit
residuals.

### LIVE-B9-F02: CRM Cannot Resolve A Customer Response Already Marked Accepted

**Disposition:** FIXED and regression/live-tested

**Severity:** High. **Category:** Customer portal negotiation / lifecycle handoff / approval queue integrity.

**Actor sequence:** Customer portal response followed by internal `crm` Sales resolution; no system-admin recovery was used.

**Target:** Disposable SO `XKEbGkbgWk` / `SO-202609-0016`; first response `dGypLxpvAg`.

**Exact evidence:** The customer portal response endpoint returned `201` with `status=accepted` and `resolved_at=null`. The subsequent internal response queue read exposed the response, but `PATCH /api/v1/crm/sales-order-responses/dGypLxpvAg/accept` returned `422` with `Only a pending customer response can be resolved.` The customer portal has therefore completed the response state before the internal Sales action that the workflow register requires.

**Impact:** The internal Sales checker cannot complete the customer-response handoff through its direct route. A customer response can appear accepted but remain unresolved, preventing the intended maker/customer -> Sales confirmation lifecycle and leaving the order/response chain in an ambiguous terminal-looking state.

**Artifact:** [`artifacts/a10-a18-cont-a14-corrected-crm-so.png`](artifacts/a10-a18-cont-a14-corrected-crm-so.png)

**Recommended action:** Choose one explicit lifecycle contract. Either portal submission creates `pending` and CRM `accept` transitions it to `accepted/resolved`, or portal acceptance is terminal and the CRM route must be an idempotent read/acknowledgement rather than rejecting an already accepted response. Add a customer-guard feature test covering submit, internal resolve, replay, SO state, and `resolved_at`.

**Fix evidence:** The existing contract remains portal `accept -> accepted`; CRM
`accept` now idempotently acknowledges an already accepted response and stamps
`resolved_at` once. The live CRM request returned HTTP 200 with `accepted` and a
resolved timestamp; the focused suite covers the same lifecycle.

## Batch 9 Blocked Evidence

### LIVE-B9-B01: Payroll Compute Handoff Remained Processing

**Disposition:** BLOCKED - live queue/worker evidence, not a product finding

`hr` created period `0ldwDmpVa9` and received `202` from compute. Repeated authenticated reads during the serial Firefox run remained `status=processing`, with no payroll rows or anomalies. Finance approve correctly returned `422 Only computed periods can be approved.` The disposable period was force-unlocked by the admin recovery route to `draft`; no seeded payroll, GL, bank, payslip, or disbursement record was touched. A queued worker/scheduler reset was not performed, and no downstream pass is claimed.

### LIVE-B9-B02: Disposable Supplier PO Response Fixture Absent

**Disposition:** BLOCKED - seeded fixture gap, not a product finding

`portal@supp.test` returned `200` and an empty own-tenant PO collection from `GET /api/v1/b2b/supplier/purchase-orders?per_page=100`. No explicitly disposable sent/approved PO was available. The run did not mutate the seeded internal PO rows or invent a vendor/PO. Supplier acknowledgement/response, internal accept/reject, stale cancellation, dispatch, and notification assertions remain unclaimed.

### LIVE-B9-B03: High-Value Stock Adjustment Checker Was Not Armed

**Disposition:** BLOCKED - live threshold/configuration evidence, not a product finding

Warehouse created `GqkbAVwxd1` for `100000.00`; the response was immediately `approved`, with movement `DlVpZawqJ8` and GL handoff `generated`, and both requester and approver were the warehouse user. No pending state was exposed for Finance, so no Finance checker action or replay was attempted. This is the same fixture/configuration class as the prior threshold block; the live audit preserved the exact disposable stock/GL residual rather than manufacturing another threshold setting.

### LIVE-B9-B04: Independent PPAP Checker And Complete Element Set Absent

**Disposition:** BLOCKED - fixture/process gap, not a second product finding

The disposable PPAP `R8epjLbOaD` auto-created one PSW element rather than an 18-element package. Same-user QC review returned the fixed `403`, and only `qc_inspector` was seeded with `quality.ppap.manage`; no QC manager/checker was invented. Supplier PPAP visibility was also empty because the chosen existing vendor was not the supplier portal tenant. Independent distinct-checker approval and complete-element coverage remain unclaimed.

## Batch 9 Process Improvements

### LIVE-B9-PI01: Make Live Queue Handoffs Observable Before Approval Assertions

The payroll compute endpoint returned `202` and remained `processing` through the serial poll, leaving Finance's approval board empty and downstream actions correctly unavailable. The live manifest needs a durable queue-health/result probe and a documented timeout classification before attempting checker actions; an audit runner must not turn an unavailable worker into a product failure or repeatedly enqueue the same period.

### LIVE-B9-PI02: Define One Customer Response State Machine And Replay Contract

The customer portal currently returned `status=accepted` before CRM resolution, while CRM accepted only `pending`; an exact portal replay inserted a second accepted response. The API contract should define the state transition owner, `resolved_at` semantics, accepted replay behavior, and a shared idempotency key. The SPA/API fixture should assert one response, one handoff, one notification, and the final SO state.

### LIVE-B9-PI03: Seed A Disposable Sent Supplier PO

A13 could not safely run because the supplier tenant had no own PO. The overnight fixture should provide one clearly disposable sent PO with a line, acknowledgement-capable status, supplier response, internal response-review target, and a cancellation/rejection cleanup path. The manifest must distinguish it from preserved seeded POs.

### LIVE-B9-PI04: Seed Threshold, PPAP, And Portal Review Fixtures Together

A16 needs a high-value pending adjustment whose threshold is demonstrably enabled; A17 needs a complete 18-element PPAP and a distinct real QC checker; A12 needs an approved-supplier response that exposes the listing-to-sourcing linkage; A15 needs notification IDs or an explicit recipient/outbox read contract. Without these, the run proves the surrounding routes but cannot prove the requested cross-module effects end to end.

## Batch 10 Findings - L01-L05

### LIVE-B10-F01: Payroll Compute Loses The Scoped Disposable Employee And Fails The Queue Handoff

**Disposition:** FIXED and live-regression-tested

**Severity:** Critical. **Category:** Payroll correctness / queue failure / financial workflow.

**Actors and target:** `hr` created disposable employee `ajzw1qdw3e` / `OGM-2026-0010` in Production with `employment_type=probationary`, `pay_type=monthly`, and ₱24,000.00 salary. The October payroll period was `BnoNyGw56v` / `LIVE-B10 disposable October payroll 61815681`.

**Exact evidence:** `POST /api/v1/payroll-periods/scope-preview` returned `200` with `employee_count=1`, `already_paid_count=0`, and `estimated_gross="12000.00"`. The matching period create returned `201` and persisted the expected scope plus derived `is_first_half=true`. `POST /api/v1/payroll-periods/BnoNyGw56v/compute` returned `202` and `status=processing`. After the clear 45-second serial evidence timeout, `GET /api/v1/payroll-periods/BnoNyGw56v` still returned `status=processing`, but its summary was `employee_count=0`, `failed_count=0`, `total_gross="0.00"`, `total_deductions="0.00"`, and `total_net="0.00"`. No payroll row, anomaly result, GL entry, bank file, payslip, disbursement proof, or Finance approval target was available.

The employee create response exposed `status=null`, while the scope preview counted the same employee. This is a strong live correlation for a status/filter mismatch, but the queue exception body was not exposed by the API, so the exact internal exception remains to be confirmed from server logs. The queue worker was running; `queue:failed` and queue logs showed `RunPayrollComputationOnRequested` failures for the disposable compute attempts.

**Impact:** A payroll run can be accepted and claimed, then remain processing while producing no payroll rows or money. Finance cannot safely approve or finalize it, and downstream bank/GL/payslip evidence cannot be generated. The zero-row summary also risks making a failed run appear empty rather than failed.

**Artifacts:** [`artifacts/l03-l05-continuation-results.json`](artifacts/l03-l05-continuation-results.json), screenshots [`artifacts/l03-l05-l04-hr-payroll.png`](artifacts/l03-l05-l04-hr-payroll.png), and the queue evidence captured after the timeout.

**Recommended action:** Make the employee create path persist the canonical active status explicitly and make payroll's scoped candidate query use the same active-status policy as `scope-preview`. Add a transaction-level invariant that a non-empty preview cannot compute to a zero-employee summary without a named failure/anomaly. Ensure the queued compute listener records a redacted durable failure reason, transitions the period out of `processing` through its `finally` path, and causes the command/monitoring surface to distinguish zero work from all-worker-failed. Add a feature test covering a newly created probationary monthly employee, scoped preview count one, compute row count one, and a failing listener retry/terminal state.

**Fix evidence:** The queued listener now resolves its services from the
container when Laravel invokes it with only the event; direct test invocation
remains supported. `PayrollComputeHandoffTest` passes, and a live disposable
probationary monthly employee produced scope preview count one, compute HTTP 202,
then `computed` with one payroll row and `total_gross="12000.00"`.

### LIVE-B10-F02: Welcome And Password-Reset Mail Jobs Fail In The Live Queue

**Disposition:** BLOCKED ENVIRONMENT EVIDENCE, NOT CLASSIFIED AS A PRODUCT FAILURE

The disposable account provisioning response correctly returned `Account created. Welcome notification queued.` and account status showed the employee role and `must_change_password=true`. The single queue worker subsequently logged `EmployeeWelcomeNotification` and `EmployeePasswordResetNotification` failures; the HR notification feeds contained `email.delivery_failed` rows naming both delivery failures. The disposable account could still be recovered through the technical admin reset route, changed on first login, and used for the employee self-service checks.

No email transport/mailbox fixture was available at `http://localhost`, so external delivery, message content, and recipient inbox evidence are blocked. No seeded account was reset or changed.

### LIVE-B10-B01: Sep Payroll Cutoff Already Covered By Preserved Company-Wide Period

**Disposition:** BLOCKED SAFE-GUARD EVIDENCE, NOT A PRODUCT FAILURE

The first disposable period attempt used Sep 1-15 and returned `422` because a preserved company-wide payroll period already covered those dates. The run did not mutate or void the seeded period. The chain continued with an October scoped period so the seeded payroll remained untouched.

### LIVE-B10-B02: Disposable Holiday Date Already Occupied

**Disposition:** BLOCKED FIXTURE EVIDENCE, NOT A PRODUCT FAILURE

HR's disposable holiday create for Sep 11 returned `422` because one active holiday already existed for that date. The audit did not archive the seeded holiday. The holiday DTR used the existing preserved holiday and recorded `holiday_type=regular`, `day_type_rate="2.00"`, and 2.00 auto-OT hours.

## Batch 10 Process Improvements

### LIVE-B10-PI01: Persist A Reusable Disposable H2R Fixture Manifest

The initial runner had to discover an unused employment/pay-type scope, recover a one-time employee password through a technical path, and continue from a second serial runner after harness failures. Seed a documented disposable employee with a known active status, account credential handoff, unique position, leave balances, shift assignment, and a payroll scope manifest. Persist all target hashes before mutation so a continuation does not create another employee or duplicate terminal records.

### LIVE-B10-PI02: Make Queue Failure Evidence First-Class

The compute route returned `202` and a processing period, while the worker failed and `queue:failed` was the only durable failure signal. The audit runner should capture the period status, summary, failed-job identity, worker liveness, and a redacted failure reason in one evidence object. A processing period whose worker has failed must not be treated as healthy idle work, and the runner must never enqueue a second compute after the timeout.

### LIVE-B10-PI03: Align Payroll Scope Preview And Compute Candidate Queries

The same scope preview counted one employee and ₱12,000.00 estimated gross, but the created period later summarized zero employees and zero money. The preview and compute paths should share one candidate-set service and expose the candidate count/hash sample in the period claim response. Add a live-safe invariant test that a newly created employee cannot disappear between preview, period creation, and compute.

### LIVE-B10-PI04: Separate Technical Credential Recovery From Business-Actor Fixtures

HR provisioning intentionally hides temporary passwords, but the audit needed a disposable employee session for self-service. The fixture should provide a documented one-time credential handoff or a disposable employee login fixture so technical admin recovery is not needed in a business-chain audit. Any fallback recovery must be explicitly tagged technical and must never be accepted as a business approval actor.

## Batch 11 Findings — L06-L09

No product failure was confirmed in the executed portion of L06-L09. The batch stopped at the live shortage/source safety gate and did not claim downstream P2P behavior without a legitimate source.

### LIVE-B11-B01: No Disposable MRP Shortage Or Consolidated Auto-PR Fixture

**Disposition:** BLOCKED — live data outcome and fixture gap, not a product failure.

**Actors / SPA paths:** `crm` / `/crm/sales-orders`; `ppc` / `/mrp/boms` and `/mrp/plans`.

**Exact evidence:** Active customer/product/price-agreement reads returned `200`. CRM created disposable SO `GkXwO7wP52` / `SO-202609-0017` for Toyota / WB-001, total `25.20`; confirmation returned `200`, exact replay returned `422`. The queue-created MRP plan read returned `200` for `40awq4bKG2` / `MRP-202609-0015`, with `shortages_found=0`, `auto_pr_count=0`, and `draft_wo_count=1`. The three diagnostics were all `action=sufficient`: RM-001 had gross `0.016`, on-hand `236.968`, in-transit `20000`, safety stock `200`; RM-010 had gross `0.002`, on-hand `784.996`, safety stock `20`; PKG-001 had gross `1`, on-hand `1605`, safety stock `500`.

**Why blocked:** The requested bridge requires a real shortage. The run did not deplete seeded stock, alter safety stock, create an artificially large SO, or call the all-active-sales-orders manual MRP trigger. The plan correctly generated one disposable planned WO but no PR. L07 PR approval/conversion, L08 source PO/GRN, and L09 stock/AP assertions therefore have no legitimate source.

**Evidence:** [`artifacts/l06-l09-results.json`](artifacts/l06-l09-results.json), [`artifacts/l06-l09-crm-sales-orders.png`](artifacts/l06-l09-crm-sales-orders.png), [`artifacts/l06-l09-ppc-boms.png`](artifacts/l06-l09-ppc-boms.png), and [`artifacts/l06-l09-l06-no-shortage-mrp-plans.png`](artifacts/l06-l09-l06-no-shortage-mrp-plans.png).

### LIVE-B11-B02: Downstream P2P Source, Import, Receipt, And AP Branches Unavailable

**Disposition:** BLOCKED — dependency absence, not a product failure.

No auto-generated PR, sent PO, shipment, customs/landed-cost row, GRN, incoming inspection, stock movement, auto-created draft bill, three-way match, AP journal, or payment was created by Batch 11. The requested failure branches were not forced against seeded rows: rejected incoming inspection/no-stock/no-bill, approval reject/cancel, duplicate conversion/receipt, and partial/full payment replay remain unclaimed. The VP threshold branch was also not manufactured solely to obtain an approval target.

**Safety disposition:** No seeded active stock, PO, GRN, bill, payment, or financial terminal row was mutated. No domain service was invoked to manufacture a pass.

### LIVE-B11-B03: Initial Source Pair Had No Active Price Agreement

**Disposition:** BLOCKED — source-fixture resolution, not a product failure.

The first harness source selection used customer `R8epjLbOaD` and product `35Mbaeb49K`. `crm` `POST /api/v1/crm/sales-orders` returned the expected field-keyed `422`: `No active price agreement for this customer and product.` The harness correction resolved an active pair before creating SO `GkXwO7wP52`; no row was created by the failed attempt and no downstream action was sent with a null SO hash.

## Batch 11 Process Improvements

### LIVE-B11-PI01: Require A Shortage-Capable Disposable MRP Manifest

The overnight manifest needs one confirmed disposable SO whose active BOM has a documented material source below net available quantity, with the exact item/location/safety-stock calculation recorded before the run. The current WB-001 plan was safely sufficient because RM-001 had `20000` units in transit and substantial on-hand above safety stock. A fixture-scoped MRP run or dry-run selector would allow the shortage bridge without depleting seeded stock or evaluating every active SO.

### LIVE-B11-PI02: Preflight Cross-Module Source Relationships And Stop On Missing IDs

The first harmless probe selected a valid active customer and product but no active price agreement, returned `422`, and exposed a harness continuation bug that polled a null SO route. The runner should resolve and persist the complete customer/product/price-agreement/BOM manifest before the first write, stop immediately on a missing target hash, and classify all dependent packs as `BLOCKED` rather than emitting follow-on failures.

### LIVE-B11-PI03: Seed A Full Disposable P2P Resume Manifest

L07-L09 require a disposable shortage PR with supplier and price, a PO with a known threshold state, a sent-PO receipt location, an import/no-import flag, a QC-fail GRN target, a QC-pass GRN target, and an AP cash account. Each record needs a cleanup route and a persisted hash manifest so the batch can resume after a queue or actor-order interruption without creating duplicate documents.

### LIVE-B11-PI04: Make Threshold And Branch Coverage Explicitly Fixture-Gated

The live run could not safely assess VP threshold approval, PO approval rejection/cancel, incoming-QC rejection/no-stock/no-bill, duplicate receipt, or AP payment idempotency without a legitimate shortage source. The coverage contract should expose `requiresShortage`, `requiresVpThreshold`, `requiresImport`, and `requiresStandardBill` preconditions and mark each downstream assertion `BLOCKED` before mutation when its source is absent.

## Batch 12 Findings - L10-L14

### LIVE-B12-F01: Legitimate In-Process And Outgoing Inspection Creation Returns HTTP 500

**Disposition:** FIXED and live-regression-tested

**Severity:** High. **Category:** IATF quality gate / O2C chain blockage. **Effort:** M.

**Actors:** `qc` / `qc@ogami.test`, `qc_inspector`; source production actor `production` / `production@ogami.test`.

**SPA/API paths:** `/quality/inspections`; `POST /api/v1/quality/inspections`.

**Exact evidence:** The legitimate planned/confirmed/closed WO `0ldwDmpVa9` / `WO-202609-0008` produced output `GqkbAVwxd1` with `2` good and `1` reject. QC then submitted a valid in-process inspection payload for product `dGypLxpvAg` / WB-001, batch `3`, `entity_type=work_order`, `entity_id=0ldwDmpVa9`; the response was HTTP `500` with only `{"message":"An unexpected error occurred."}`. The same QC session submitted two valid output-bound outgoing payloads for `work_order_output_id=GqkbAVwxd1`, one intended to pass and one intended to fail; both returned HTTP `500` with the same generic body. The request ledger is in [`artifacts/l10-l14-continuation-results.json`](artifacts/l10-l14-continuation-results.json), including the redacted response bodies and `LIVE-B12-C-*` correlation IDs.

**Impact:** No actual-measurement in-process inspection, auto-NCR from inspection failure, outgoing AQL result, CoC, or delivery-authorizing outgoing inspection could be created. L12 stopped before delivery rather than bypassing the Quality gate. This prevents proving failed-QC delivery blocking, partial delivery, portal delivery confirmation, invoice finalization, collection, and GL on this legitimate output.

**Safety disposition:** No direct inspection/NCR service record was manufactured to force a pass. The completed WO/output, material issue, mold shot, and complaint-origin NCR evidence remain preserved. The live response exposed no server exception details; correlate the request IDs with the API log before changing code.

**Fix evidence:** `InspectionService::create()` now returns an existing
inspection for the same non-outgoing entity or output-backed outgoing batch,
matching the database uniqueness contract. Live replays for the in-process and
outgoing payloads returned HTTP 200 instead of 500 and did not create duplicate
inspection rows.

### LIVE-B12-F02: Valid CRM Complaint Source Creation Fails After Two Successful Complaint-to-NCR Handoffs

**Disposition:** FIXED and live-regression-tested

**Severity:** High. **Category:** Quality feedback loop / complaint-to-NCR handoff. **Effort:** M.

**Actor:** `crm` / `crm@ogami.test`, `sales_officer`.

**SPA/API path:** `/crm/complaints`; `POST /api/v1/crm/complaints`.

**Exact evidence:** Valid complaint creates for Toyota/customer `dGypLxpvAg`, product `dGypLxpvAg`, medium severity, affected quantity `1`, and confirmed SO `OX5b9mbBrk` succeeded twice: complaints `DzAwdKb5ld` / CMP-202609-0004 and `MYAbJ3wBl7` / CMP-202609-0005 each returned `201` and generated NCRs. The next valid complaint request for a `use_as_is` source returned HTTP `500` with the generic envelope; the following valid `return_to_supplier` request also returned HTTP `500`. A continuation retried both with the same active customer/product but no SO link, and both again returned HTTP `500`. No complaint or NCR hash was returned by any failed request, and no partial row was assumed.

**Impact:** The complaint-origin NCR path cannot be completed reliably for later valid records, so `use_as_is` and `return_to_supplier` dispositions were not reached. The successful `scrap` NCR `kABpV2vpKR` and `rework` NCR `0ldwD5mpVa` branches passed corrective/preventive actions, closure, 8D closure, and CAPA effectiveness; this finding is isolated to the subsequent valid complaint-source writes, not the disposition endpoints themselves.

**Recommended action:** Correlate the two 500 pairs in the API error log and add a feature test that creates at least four sequential complaint-to-NCR records with the same active customer/product, with and without an SO link. Assert one complaint, one NCR, and one audit/outbox handoff per request, and return a field-keyed business error rather than a generic 500 if a source or downstream setup prerequisite is missing.

**Fix evidence:** `DocumentSequenceService` now reconciles persisted complaint and
NCR suffixes before allocating the next number, preventing seeded/imported
numbers from colliding with the sequence row. A live complaint without an SO
link returned HTTP 201 with a generated NCR after the previous sequence had
drifted; the focused sequence suite passed 4 tests/16 assertions.

### LIVE-B12-B01: Finished-Goods Receipt Requires Manual Inventory Setup

**Disposition:** BLOCKED CONFIGURATION/HANDOFF EVIDENCE, not counted as a confirmed product failure.

Production output `GqkbAVwxd1` returned `production_receipt_handoff.status=manual_required` with `Finished-goods inventory receipt could not be created automatically. Fix the item/location setup, then replay the handoff or create the receipt manually.` The narrow retry `POST /api/v1/production/work-orders/0ldwDmpVa9/outputs/GqkbAVwxd1/retry-receipt` returned `422` with `Finished-goods receipt still needs Inventory setup. Fix the prerequisite, then retry again.` No direct stock receipt or seeded inventory location was created to force the handoff.

### LIVE-B12-B02: Outgoing/Delivery/AR Fixture Is Downstream of the Inspection 500

**Disposition:** BLOCKED dependency evidence, not an independent product failure.

No outgoing inspection hash, CoC, delivery, proof, invoice, collection, or GL hash exists for this batch because the legitimate output-backed inspection could not be opened. No delivery was created with a failed inspection or a bypassed inspection. Consequently, failed-QC delivery blocking, delivered quantity x price invoice reconciliation, partial delivery/invoice idempotency, portal delivery confirmation, and AR GL reconciliation remain unclaimed.

### LIVE-B12-B03: Maintenance Assignment And Condition-Reading Threshold Fixtures Are Absent

**Disposition:** BLOCKED seeded role/scope evidence, not counted as a confirmed product failure.

`maintenance` can create, start, log, and complete a disposable corrective MWO but `GET /api/v1/maintenance/work-orders/assignees` and assignment returned `403` because the only seeded maintenance actor lacks `maintenance.wo.assign`. A disposable machine `XKEbGkbgWk` was created solely as a target, its MWO `kABpVvpKRW` completed with `22` downtime minutes, and the machine was archived. Condition-reading routes are explicitly scope-cut: `GET /api/v1/maintenance/condition-readings/health-snapshot` returned `404`. No hidden IoT/threshold record or missing maintenance checker was invented.

## Batch 12 Process Improvements

### LIVE-B12-PI01: Make Scheduler Response Shape And Confirm Ownership Explicit

`POST /api/v1/mrp/scheduler/run` returns schedule rows in `data.scheduled[]`, while the snapshot nests them under `data.rows[].bars[]`; the planned WO detail does not show the machine/mold until Production confirmation. The live harness initially searched only the snapshot top-level rows and classified the source as absent. The contract should expose one normalized schedule resource keyed by `work_order_id`, declare `mrp.schedule` versus `production.schedule.confirm` separately, and make the ownership handoff explicit.

### LIVE-B12-PI02: Add A Preflight Quality Fixture Gate Before O2C Mutation

The manifest had a valid active product/BOM and a real output, but `POST /quality/inspections` returned 500 before the outgoing AQL/CoC path. Before opening a long chain, preflight the product's active inspection spec, current revision, spec items, measurement scaffold, output-backed inspection route, and a known CoC response. If any preflight is unavailable, mark L11/L12 dependent assertions `BLOCKED` before creating further chain records.

### LIVE-B12-PI03: Surface Finished-Goods Receipt Prerequisites And Recovery State

Production correctly persisted `manual_required`, but the operator still needs a clear item/location prerequisite and a narrow retry outcome. The audit should record the receipt handoff status, movement hash, stock-location prerequisite, retry correlation ID, and final inventory effect independently. A disposable output fixture with a valid finished-goods location would allow stock movement and partial-delivery evidence without mutating seeded stock.

### LIVE-B12-PI04: Seed A Complete O2C Outgoing-to-AR Manifest

The live chain needs one disposable output with an active inspection spec, one pass and one fail outgoing target, a delivery-create/proof actor, an available vehicle/driver or documented optional assignment, a customer portal tenant, an AR cash account, and a delivery-linked invoice source. The manifest should include cleanup/terminal state for each hash and prevent the runner from falling back to seeded deliveries or prebill invoices.

### LIVE-B12-PI05: Preserve Server Correlation For Generic 500s

Both inspection creation and later complaint-source creation returned redacted generic 500 envelopes. The API contract is correctly redacted for the browser, but the live audit must persist request IDs and the server-side error category in a separate operator-readable ledger. Add a smoke assertion that all expected business prerequisites produce `4xx`, never a generic `500`, and that failed transactions leave no partial complaint, NCR, or inspection row.

### LIVE-B12-PI06: Encode Idempotent Success Separately From Terminal Denial

Customer SO response replay returned `201` with the same response ID and accepted state; WO close replay returned `409`; maintenance complete replay returned `422`; stale scheduler confirmation returned `422`. These are different safe outcomes. The coverage schema should declare `idempotent same-resource success`, `terminal denial`, and `stale ownership/state` separately rather than treating all non-2xx replay responses as failure.

## Batch 13 Findings - L15-L17

### LIVE-B13-F01: Supplier PO Response Replay Creates A Second Accepted Response

**Disposition:** FIXED and regression/live-tested

**Severity:** High. **Category:** B2B supplier negotiation / idempotency / duplicate notification. **Effort:** M.

**Actor and tenant:** `supplier-portal` / `portal@supp.test`, vendor `dGypLxpvAg` / Megaplast Industries Corp.; internal review actor `purchasing` / `purchasing@ogami.test`.

**SPA/API paths:** `/portal/supplier/purchase-orders`; `/api/v1/b2b/supplier/purchase-orders/35Mbaeb49K/respond`; internal `/api/v1/purchasing/purchase-orders/35Mbaeb49K/responses`.

**Exact evidence:** Safe disposable PO `35Mbaeb49K` / `PO-202609-0004` was sent by Purchasing with HTTP `200`, then acknowledged by the supplier with HTTP `200`. The supplier submitted `{type: "accept", notes: "LIVE-B13 supplier response 66483768"}` at request `LIVE-B13-15`; HTTP `201` returned response `dGypLxpvAg`, `status=accepted`. The exact same request was replayed at `LIVE-B13-16`; HTTP `201` returned a different accepted response `GqkbAVwxd1`. The subsequent internal response queue read returned two rows, both `accepted`, both unresolved, with the same timestamp and notes. No pending response remained for a legitimate internal review action.

**Notification/effect evidence:** Purchasing's feed showed two distinct `supplier.po_responded` notifications for the same PO: `936d5772-b3b2-4d47-af6d-2ef77a2e2a81` and `7bd8518c-2ce8-497b-952e-b685cef96586`. A read-only dedicated-DB reconciliation found `12` rows of this type, two for each of six recipients: `admin`, `finance`, `purchasing`, `buyer2`, `vp`, and `finance2`. It also found one dispatch row, one shipment row, one shipment-update row, one portal shipping-document row, one draft GRN, zero bills, and zero bill payments for the PO. The PO's durable event ledger had a second `sent` publication: outbox `bb917cae-15f4-4e4e-b522-afbe0f8af91b` and chain-step `bc56b2af-2040-470d-8995-8f223490deb7` for the send, followed by outbox `a82fd970-56be-4c34-808a-e9ff9044a5e0` and chain-step `dc9ef8d7-4883-4b0d-b328-041f87d9af0c` after the replay. No matching `chain_listener_runs` rows existed. The exact shipping-document upload replay returned the same document `dGypLxpvAg` with post-replay document count `1`. No stock, accepted GRN, AP bill, payment, or GL row was created by this finding; those downstream branches were fixture-blocked by the absence of an accepted GRN.

**Impact:** A supplier browser retry or network timeout can create multiple accepted responses for one PO negotiation cycle and notify Purchasing multiple times. The internal queue can no longer distinguish a single supplier decision from a replay, and any downstream response listener that assumes one response can run more than once.

**Screenshot:** [`artifacts/l15-l17-failure-LIVE-B13-F01-supplier-response-replay.png`](artifacts/l15-l17-failure-LIVE-B13-F01-supplier-response-replay.png)

**Recommended action:** Add a durable idempotency key or unique response-cycle guard scoped to `(purchase_order_id, vendor_id, negotiation_cycle)` and return the original response on an exact replay. Preserve legitimate counter-offer/revision semantics by making the cycle explicit rather than globally rejecting every later response. Add a feature test asserting one response row, one notification, stable PO status, and one downstream handoff under an exact supplier replay.

**Fix evidence:** Supplier `accept` replay now returns the latest existing
accepted response for the same PO/vendor instead of inserting another row or
sending another notification. The focused supplier response suite passes 12
tests/71 assertions, and live replay returned the existing response `GqkbAVwxd1`.

### LIVE-B13-B01: Portal A/B Tenant Comparison Has No Second Tenant Fixture

**Disposition:** BLOCKED - fixture/process gap, not a product failure

The live run confirmed supplier tenant `dGypLxpvAg` and customer tenant `dGypLxpvAg`. Supplier credentials received `401` from `/api/v1/b2b/customer/me` and `/api/v1/auth/user`; customer credentials received `401` from `/api/v1/b2b/supplier/me` and `/api/v1/auth/user`. Customer credentials attempting the supplier PO hash through `/api/v1/b2b/customer/orders/35Mbaeb49K` received `403`. These prove guard separation and an opposite-resource denial, but not supplier A versus supplier B or customer A versus customer B row isolation. No second portal identity or tenant was invented.

### LIVE-B13-B02: Supplier AP And Most Recovery Branches Have No Safe Manual Target

**Disposition:** BLOCKED - live fixture/state evidence, not a product failure

The selected supplier PO had draft GRN `R8epjLbOaD` / `GRN-202609-0002` with `incoming_qc_handoff=not_started`, not an accepted receipt. Supplier invoice list returned four tenant-visible bills, but none was linked to PO `35Mbaeb49K`; no invoice submit or duplicate vendor invoice-number replay was sent. Warehouse read 27 stock movements and found no explicit `gl_handoff.status=manual_required`. Nine payroll periods contained no manual-required GL handoff, no processing period, and no finalized bank-recovery target. The audit did not force stock depletion, accept a GRN, finalize payroll, generate a bank file, or use admin recovery to manufacture these branches.

### LIVE-B13-B03: Customer Complaint Has NCR Handoff But No Finalized 8D Report

**Disposition:** BLOCKED - terminal fixture state, not a product failure

Customer portal complaint `ajzw1lb3eW` / `CMP-202609-0006` was readable and open. Internal CRM read correlated it to generated NCR `E1mbed9NGr` / `NCR-202609-0036`; the narrow CRM retry `POST /api/v1/crm/complaints/ajzw1lb3eW/retry-ncr` returned HTTP `200` with the same NCR and no duplicate. The customer portal 8D endpoint returned `404 No 8D report available for this complaint yet.` because this retained complaint was not finalized through 8D. No portal complaint or 8D mutation was created solely to close the fixture gap.

## Batch 13 Process Improvements

### LIVE-B13-PI01: Use One Supplier And Customer Response Replay Contract

Customer `accept` replay reused response `R8epjLbOaD`, while supplier `accept` replay created `dGypLxpvAg` and `GqkbAVwxd1` plus two notifications. The portal negotiation manifest should require an explicit response-cycle idempotency key and test the same contract under both custom guards.

### LIVE-B13-PI02: Encode Nested Handoffs And Operation-Specific Permissions

The production receipt status is nested under `production_receipt_handoff.status`, and Finance has the retry permission without the Warehouse-owned stock movement list permission. The first L17 worker emitted a false stock-list failure and skipped the receipt retry until a correction worker used the correct field and actor. Harness schemas should declare read, retry, and recovery actors separately.

### LIVE-B13-PI03: Seed Manual-Recovery Targets Across All Requested Chains

The current live data allowed one narrow production receipt retry and one generated complaint-NCR replay. It had no manual incoming-QC, stock-GL, payroll-GL/bank, or supplier AP target. Seed one accepted-GRN supplier PO and one explicit manual-required target for each recovery branch, with cleanup and duplicate-effect expectations, rather than treating a `not_started` or `generated` state as retryable.

### LIVE-B13-PI04: Separate Dispatch, Notification, Outbox, And Email Failure Evidence

The PO dispatch ledger reported `recipient_count=1`, `attempts=1`, and `confirmed`; supplier response replay separately created two response rows, 12 recipient notification rows, and a second `sent` outbox/chain-step publication. CRM exposed durable `email.delivery_failed` notification IDs for complaint notifications. The audit contract must capture these as independent recipient/listener outcomes, and portal mutation responses must not be treated as delivery proof.

### LIVE-B13-PI05: Report Zero Recovery Candidates Separately From Failed Recovery

L17 correctly avoided retrying accepted/generated GRNs, generated stock GL, and non-manual payroll periods. Admin operations health reported `Failed Jobs=32`, providing non-zero failure visibility. Recovery dashboards and runners should preserve the distinction between zero eligible manual targets, a narrow retry that returned a documented prerequisite `422`, and a retry that failed after claiming a target.
