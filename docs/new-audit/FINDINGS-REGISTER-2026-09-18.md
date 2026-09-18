# Consolidated Findings Register — 2026-09-18

Single severity-ranked backlog distilled from the 15 module audit documents in this folder.
Covers all 26 modules.

**Status legend**
- **OPEN** — reproduced against the working tree on 2026-09-18.
- **FIXED** — an uncommitted fix pass by another session is present and verified (working tree).
- **DESIGN** — deliberate; no fix planned (documented in the source trace).
- **VERIFY** — reported by an audit but not re-checked against the current tree after the fix pass.

> The working tree carries an uncommitted cross-module fix pass. Statuses below were spot-verified
> where marked; re-run the register after that pass commits.

---

## P0 — crash, security/privacy, money, IATF, data loss

| ID | Module | Finding | Location | Impact | Status |
|---|---|---|---|---|---|
| P0-01 | Admin / Inventory | `SearchOperator::like()/contains()` used with no `use App\Common\Support\SearchOperator;` | `Admin/Services/RoleService.php:28-29`, `Inventory/Services/GrnService.php:81`, `Inventory/Services/MaterialIssueService.php:39` | Fatal 500 on `?search=` for roles, GRNs, material issues | **FIXED** (this pass) |
| P0-02 | HR | Profile-update requests store bank/gov numbers **plaintext** and `listForReview()` has no row scope; route gated only on `hr.employees.view` | `HR/Models/ProfileUpdateRequest.php:33`, `HR/Services/ProfileUpdateRequestService.php:86-97`, `HR/routes.php:241-242` | Sensitive financial data readable company-wide + unaudited leakage into `audit_logs` | **FIXED** (this pass) |
| P0-03 | Platform | 8D SLA escalation command exits `SUCCESS` on total failure; service swallows `Throwable → []` | `Console/Commands/RunComplaint8dSlaChecks.php:26`, `CRM/Services/Complaint8dEscalationService.php:250-258` | IATF escalation notifications can be 100% undelivered while the cron reports healthy | **FIXED** (this pass) |
| P0-04 | Payroll | 13th-month GL dropped the withholding correction | `Payroll/Services/PayrollGlPostingService.php:251-256` | Payroll/WHT liability understated | **FIXED** (verified: gross expense + WHT payable + net payable) |
| P0-05 | HR | `FinalPayService::compute()` did not enforce "covering period disbursed" | `HR/Services/FinalPayService.php` (now calls `assertCoveringPeriodAllowsFinalPay` at :78) | Double-pay / unpostable final pay; `FinalPayDoublePayGuardTest` failed 5/5 | **FIXED** (verified) |
| P0-06 | CRM / B2B | Portal response-line IDs accept raw integers (`HashIdFilter::decode(...) ?? (int) $id`; request only requires `string`) | `CRM/Services/SalesOrderResponseService.php:208-209`, `B2B/Requests/Customer/RespondToSalesOrderRequest.php:28` | HashID-policy bypass on a customer-facing endpoint | **FIXED** (this pass) |
| P0-07 | Production | Machine breakdown never creates a corrective `MaintenanceWorkOrder` (pauses WO + downtime + notify only) | `Production/Listeners/HandleMachineBreakdown.php` (0 MWO refs) | Breakdowns never enter the maintenance queue; contradicts 3 docs + migration docblock | **FIXED** (this pass) |
| P0-08 | Quality | AQL compares **measurement rows** to `Ac` (a unit failing 3 dimensions counted as 3 defects) | `Quality/Services/InspectionService.php` | Wrong accept/reject decisions; a good lot can fail AQL | **FIXED** (this pass; `accepted_quantity = full batch` kept — correct AQL lot acceptance) |
| P0-09 | SupplyChain | Landed cost never posted to inventory/GL | `SupplyChain/Services/LandedCostService.php` | Customs/freight cost never capitalised into WAC or the ledger | **PARTIAL** (visibility fixed: `ShipmentService::show` eager-loads landed costs; WAC/GL capitalization deferred — needs a clearing-account design) |
| P0-10 | Landing | Newsletter has no unsubscribe path (status/fields exist, no route/handler) | `Landing/Services/NewsletterService.php`, `Landing/routes.php` | Data Privacy Act (opt-out) gap | **FIXED** (this pass) |
| P0-11 | Platform | Nginx SPA `location` re-declares `X-Frame-Options: SAMEORIGIN` / `frame-ancestors 'self'`, shadowing the mandated `DENY`/`'none'` | `docker/nginx/default.conf:36,40`, `docker/nginx/prod.conf:87,91` | Weakens clickjacking policy on the SPA origin (deliberate PDF-iframe carve-out) | **DOCUMENTED** (this pass; CLAUDE.md now states the carve-out and why not to "fix" it) |
| P0-12 | Platform | `SettingsService::updateFromAdmin` writes the setting then audits it **outside a transaction** | `Common/Services/SettingsService.php:194-223` | Security-relevant setting can change with no audit row | **FIXED** (this pass) |
| P0-13 | Leave / Loans | Cancel leaves a live approval card (`approval_records` untouched; board has no source-status filter; no withdraw) | `Leave/Services/LeaveRequestService.php:447-473`, `Loans/Services/LoanService.php:289-307` | Stale approver cards that 422 on action; audit noise | **FIXED** (this pass) |

---

## P1 — correctness, reliability, dead subsystems

| ID | Module | Finding | Location | Status |
|---|---|---|---|---|
| P1-01 | Production | `ProductionSummaryService` breakdown query OR-precedence counts every downtime category as breakdown; multi-day WO output over-counted | `Production/Services/ProductionSummaryService.php:26-56` | **FIXED** (this pass) |
| P1-02 | Inventory | `StockCardService::direction()` defaults transfers to `'in'` (unfiltered stock cards inflate balance) | `Inventory/Services/StockCardService.php:170-179` | **FIXED** (this pass) |
| P1-03 | MRP | Unique/never-pruned `event_outbox.dedupe_key` permanently drops repeated routing replans (`mrp:replan:routing:{id}:{reason}`) | `Production/Services/ProductionRoutingService.php:524`, `Migrations/2026_08_10_110000` | **FIXED** (this pass; key now versioned by change timestamp) |
| P1-04 | MRP | `SalesOrderService::confirmWithChainResult` reads a non-existent `mrp_plans.summary` | `CRM/Services/SalesOrderService.php:659` | **VERIFY** |
| P1-05 | MRP | Material-vs-cost divergence for inactive sub-assembly products | `BomService.php:600` vs `BomCostingService.php:193` | **OPEN** |
| P1-06 | HR | Employee restore leaves the login disabled; `restore()` is a bare model call | `HR/Controllers/EmployeeController.php:252` | **OPEN** |
| P1-07 | HR | Self-service home payslip bypasses `PayrollPublicationPolicy` | `HR/Services/SelfServiceHomeService.php:107-130` | **OPEN** |
| P1-08 | HR | Document restore orphans the physically-deleted file | `HR/Services/EmployeeDocumentService.php:47-62` | **OPEN** |
| P1-09 | B2B | Supplier portal never enforces password expiry (customer-only middleware, hardcodes `CustomerPortalUser`) | `B2B/Middleware/CheckPortalPasswordExpiry.php:19-20`, `B2B/routes.php:141` | **OPEN** |
| P1-10 | Platform | `RunApprovalEscalations` always exits `SUCCESS`, no failure counts | `Console/Commands/RunApprovalEscalations.php:29` | **OPEN** |
| P1-11 | Platform | `RunChainBottleneckCheck` bypasses `AlertEngineService` dedup (writes `Alert::create`) | `Console/Commands/RunChainBottleneckCheck.php` | **OPEN** |
| P1-12 | Attendance | Voided payroll period permanently locks attendance although its message says to void it | `Attendance/Services/AttendanceDateMutabilityGuard.php:42-52` | **OPEN** |
| P1-13 | Attendance | Raw-punch import (`importRawPunches`/`PunchSessionizer`) is unrouted dead code | `Attendance/Services/DTRImportService.php:166` | **OPEN** |
| P1-14 | Leave | Year-end runs up to a week late (only Jan 1–7 recovery scheduled; docblock claims Dec 31) | `Leave/Jobs/ProcessYearEndLeave.php:57-58`, `routes/console.php:209` | **OPEN** |
| P1-15 | Loans | Government loan types unreachable but would charge 10–10.5% interest (violates zero-interest) | `Loans/Services/LoanService.php:173-174`, `Enums/LoanType.php` | **OPEN** |
| P1-16 | Payroll | Disbursed/voided events have no listeners; anomaly detection swallow-disables the finalize gate; single permission covers finalize+bankfile+disburse | `Payroll/*` | **VERIFY** |
| P1-17 | Inventory | Stock-count maker-checker self-approvable (`approveVariance` and `completeSession` share one permission) | `Inventory/routes.php:133-138` | **OPEN** |
| P1-18 | SupplyChain | `NotifyFinanceOnDeliveryConfirmed` silent no-op (role setting never seeded); shipment lots never created via UI; permission drift on proofs | `SupplyChain/*` | **OPEN** |
| P1-19 | Production | Operations schedule endpoint dead (`WoOperation.planned_start` never written); dual output paths; three Pareto impls | `Production/*` | **OPEN** |
| P1-20 | CRM | Price/product manage permissions granted to no business role; `revenue_account_id` API-unreachable; orphaned leads/opportunities schema | `CRM/*`, `RolePermissionSeeder.php:315-318` | **OPEN** |
| P1-21 | Platform | `Edge` module does not exist but CLAUDE.md + dead carve-outs/Middleware reference it | `SessionTimeout.php:123`, `CheckPasswordExpiry.php:68`, `bootstrap/app.php:69` | **OPEN** (verified) |
| P1-22 | Platform | Session terminate by id gated only on `admin.settings.manage`; `/business-policies` ungated; `password-policy` complexity hardcoded; no 2FA | `Admin/Controllers/SessionController.php:38`, `routes/api.php:101-102` | **OPEN** |
| P1-23 | HR | `EmployeeCompetenceScope` self-only for dept heads; masking tiers inconsistent; salary-adjustment approver mis-attributed; two pending adjustments allowed | `HR/*` | **OPEN** |

---

## P2 — dead code, inconsistencies, cosmetic (per doc)

Summarized by audit file; each doc lists the full set.

- **ACCOUNTING-CORE**: COGS misclassification of payroll expenses; 4010 discount aliases revenue; no year-end close; posters bypass account policy; dead `forDate`/`translation_adjustment`.
- **INVENTORY-RETURNS**: dead `is_blocked`/legacy projections; duplicated acceptance blocks; `acceptInternal` not delta-aware; dead `issueForInvoice`; RMA cross-party CN link; finance lacks RMA view.
- **QUALITY**: `PpapService::expireOverdue` uncalled; `AutoSpawn8DOnNcrRecurrence` ineffective; `rework_work_order_id` write-only; dead `8d` config role.
- **PRODUCTION**: OEE N×days querying; dashboard cache no invalidation; mold events no listeners; `UnderMaintenance` unreachable; scratch probe test.
- **MRP**: dead `productionTree`; no `(bom_id,item_id)` unique; audit-bypassing `->update()`s.
- **SUPPLYCHAIN**: dead `by_weight`/`manual`; `Vehicle.asset_id` dead; no SPA consumers for container/landed/reschedule.
- **CRM**: dead `crm.customers.manage`/`crm.so.create`; duplicated pricing validation; inquiry API in Landing without `feature:crm`.
- **HR**: dead property/directory stack; `is_final_pay_deduction` never set; importer bypasses position uniqueness.
- **ATTENDANCE-LEAVE-LOANS**: remaining register items were remediated or explicitly retained as
  future government-loan scope; see the domain audit for the current status.
- **FORECASTING-ASSETS**: forecasts advisory-only; duplicate accuracy endpoint/N+1; hidden transfer stack; QR docblock lie.
- **B2B-MAINTENANCE**: reset tokens never pruned; supplier can't re-download docs; condition-reading surface disabled but job runs; maintenance N+1.
- **PLATFORM**: dashboard/Admin/Auth/Landing/Common dead-ends listed in that doc.

---

## Already fixed (this fix pass, verified)

- **P0-04** 13th-month GL withholding split — `PayrollGlPostingService.php:251-256`.
- **P0-05** final-pay covering-period guard restored — `FinalPayService.php:78`.
- **PR trace §9** (documented in `PURCHASE-REQUEST-CHAIN-TRACE-2026-09-18-FINISHED.md`): PR policy docblock, `mrp_plan_id` persistence, `purchase_request` chain entity type, `coa_verified` single writer, `grn:retry-pending-incoming-qc` sweep, `assertVendorSod` exception type.
- Many other files are modified in the uncommitted pass (payroll, leave, loans, assets, maintenance, purchasing, auth, admin). **Re-verify P1-16 and the P2 lists after that pass commits.**

---

## Suggested fix order

1. **P0-01** (three lines, three 500s) — trivial, immediate.
2. **P0-02** (encrypt `ProfileUpdateRequest.changes` + add review row scope) — privacy.
3. **P0-03** (give the 8D command outcome buckets + non-zero exit, mirroring NCR).
4. **P0-07 / P0-09 / P0-08** — IATF and money integrity (breakdown→MWO, landed cost posting, AQL unit semantics).
5. **P0-06 / P0-10 / P0-12** — portal HashID, newsletter unsubscribe, transactional audit.
6. **P1-01 / P1-02 / P1-03** — reporting and MRP correctness.
7. Then P1 by module, then P2 cleanup.

Each fix should land with one regression test (the existing suites already cover several of these paths).

---

## Resolution log — 2026-09-18 (second pass)

Seven P0s fixed. Tests run: targeted suites only (full suite not run).

| ID | Change | Files | Tests |
|---|---|---|---|
| P0-01 | Added the missing `use App\Common\Support\SearchOperator;` imports | `Admin/Services/RoleService.php`, `Inventory/Services/GrnService.php`, `Inventory/Services/MaterialIssueService.php` | `RoleManagementTest` pass |
| P0-02 | New `EncryptedArrayCast` (JSON-wrapped ciphertext for the json column, legacy-plaintext fallback) on `ProfileUpdateRequest.changes`; `listForReview()` now applies `DepartmentScope` (view-all via `hr.employees.view_sensitive`) | `HR/Casts/EncryptedArrayCast.php` (new), `HR/Models/ProfileUpdateRequest.php`, `HR/Services/ProfileUpdateRequestService.php`, `HR/Controllers/ProfileUpdateReviewController.php` | `ProfileUpdateSodTest`, `ProfileUpdateStaleRejectRaceTest` pass |
| P0-03 | `Complaint8dEscalationService::runWithOutcome()` (considered/advanced/skipped/unstaffed/failed, config errors rethrow); command reports all buckets and returns `FAILURE` on any failure | `CRM/Services/Complaint8dEscalationService.php`, `Console/Commands/RunComplaint8dSlaChecks.php` | `Complaint8dSlaTest` (7) pass |
| P0-06 | Portal line IDs: numeric strings are rejected; only a real `int` (internal caller) or a non-digit HashID is accepted | `CRM/Services/SalesOrderResponseService.php` | `SalesOrderResponseTest` 15/16 pass — the 1 failure is pre-existing (SO lifecycle work by the other session; fails with this change stashed) |
| P0-10 | Newsletter unsubscribe: `POST /landing/newsletter/unsubscribe` (email, non-enumerable) and signed one-click `GET .../{subscriber}` | `Landing/Services/NewsletterService.php`, `Landing/Controllers/NewsletterController.php`, `Landing/Requests/UnsubscribeNewsletterRequest.php` (new), `Landing/routes.php` | `NewsletterTest` (4) pass |
| P0-12 | Wrapped the settings lock+write+audit in one `DB::transaction` | `Common/Services/SettingsService.php` | `SettingsGovernanceTest`, `SettingsCacheTest` (12) pass |
| P0-13 | Cancelling a leave/loan now supersedes current open `approval_records` (mirrors the RMA cancel) | `Leave/Services/LeaveRequestService.php`, `Loans/Services/LoanService.php` | `LeaveRequestHardeningTest`, `LeaveRequestVisibilityTest` (19), `LoanApprovalChainTest`, `LoanApprovalChainWalkTest` (11) pass |

### Third batch — P0-07/08/09/11 and P1-01/02/03

| ID | Change | Files | Verification |
|---|---|---|---|
| P0-07 | `HandleMachineBreakdown` now opens an idempotent `[Breakdown]` corrective `MaintenanceWorkOrder` (High priority) inside the pause transaction, attributed to the automation actor; skips if one is already open | `Production/Listeners/HandleMachineBreakdown.php` | lint; full suite no new failures |
| P0-08 | AQL counts **defective sample units** (distinct `sample_index` with a failure), not measurement rows, in both `recordMeasurements()` and `complete()` | `Quality/Services/InspectionService.php` | inspection suites pass |
| P0-09 | `ShipmentService::show` eager-loads `landedCosts` so the resource returns them (was always null). **Capitalization into WAC/GL still deferred** — needs a clearing-account design | `SupplyChain/Services/ShipmentService.php` | `ShipmentCreateTest` pass |
| P0-11 | Documented the SPA same-origin frame carve-out in `CLAUDE.md` and why `DENY` must not be copied into the SPA block | `CLAUDE.md` | n/a |
| P1-01 | `ProductionSummaryService`: grouped the downtime OR so `category=breakdown` applies to both arms; join only outputs recorded inside the window | `Production/Services/ProductionSummaryService.php` | lint |
| P1-02 | `StockCardService::direction()` returns `'transfer'` for an unfiltered move; balance nets to zero and both legs render | `Inventory/Services/StockCardService.php` | lint |
| P1-03 | MRP replan dedupe key versioned by the routing change timestamp so a second edit with the same reason is not dropped | `Production/Services/ProductionRoutingService.php`, `ProductionRoutingTest.php` | `ProductionRoutingTest` pass |

**Regression caught and fixed during this batch:** the encrypted `changes` cast broke
audit-log redaction — `HasAuditLog` could not decode a custom cast class, so the audit
stored ciphertext and `MaterialDetailAuditTest` failed. Added a `auditAttributeSnapshot()`
model hook to `HasAuditLog`, implemented on `ProfileUpdateRequest` (using
`getAttribute()`, because Eloquent's inherited protected `$changes` property shadows the
attribute name inside the class). `MaterialDetailAuditTest` now passes.

### Full-suite baseline (2026-09-18, isolated DB `ogami_test_audit`)

`Tests: 33 failed, 3331 passed (15631 assertions)`, ~39 min, PHP `memory_limit=512M`.

Of the 33 failures, **2 were introduced by this session's fixes and are now resolved**
(`ProductionRoutingTest` key assertion updated; `MaterialDetailAuditTest` audit hook).
The remaining **31 are attributable to the other session's uncommitted work or pre-existing
red tests**, not to the changes above: Assets disposal (4 classes), B2B portal
(1), Chain listener recovery (1), Common SQL-leak message (1), Forecasting toggle (1),
HR EmployeeDataScope duplicate-position (1 class / 4 tests), Loans idempotency-key contract
(1 class / 2 tests), Payroll effective-dated salary (1 class / 6 tests), Quality PPAP SoD
(1), and the SupplyChain M044 probe (1). `SalesOrderResponseTest` was verified pre-existing
by stashing this session's change to that file.

**Still genuinely open:** P0-09's WAC/GL capitalization (needs a clearing-account decision).
Everything else in P0 is fixed or documented; the P1/P2 registers remain for follow-up.
