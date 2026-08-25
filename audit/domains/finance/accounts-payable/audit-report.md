# M027 — Accounts Payable audit report

Audit date: 2026-08-25  
Status: ✅ Verified  
Release recommendation: release after the completed targeted verification

## 2026-08-25 re-audit and implementation result

The source surface changed after the 2026-08-24 report: the working tree already
contained an active posting-account resolver and journal reversal period guard.
Those changes were preserved. The AP boundary was then re-checked and the
original findings were implemented as follows:

- F01/F02/F03: fixed in `BillService`, requests, permissions, and tests. Draft
  bills now require a posted bill journal before payment; new bill/payment
  accounts are active and correctly typed; blocking 3-way overrides require a
  dedicated permission, a reason, and a different checker from the bill maker.
- F04/F05: fixed with a locked one-accepted-GRN/one-bill invariant, vendor
  equality checks across bill/PO/GRN, and a migration-backed unique constraint.
  The existing workflow is explicitly one bill per accepted GRN; if the
  business later needs partial allocation across multiple bills, that remains
  a design change rather than an implicit relaxation of this invariant.
- F06/F07/F08: fixed with explicit bill cancellation reversal inputs, persisted
  cancellation timing, historical payment reconstruction for AP aging, strict
  as-of validation, and a dedicated AP-aging export route/permission boundary.
- F09/F10: fixed by exposing internal provenance/checker evidence only through
  the internal bill resource and using a separate supplier-safe bill resource.
- F11: fixed with a posted/voided payment lifecycle, journal reversal linkage,
  replacement reference, dedicated void permission, balance rebuild, and UI
  action. The reversal uses the existing journal service period guard.
- F12–F15: fixed with trashed vendor binding/restore, vendor-list open-balance
  aggregation, eager-loaded payment journal resources, and corrected supplier
  invoice empty-state copy.

Focused verification: `AccountsPayableHardeningTest` 6 passed, 18 assertions; the existing AP
and 3-way-match suites 23 passed. PHPStan passed for the changed backend files
and targeted SPA ESLint passed. A combined accounting run still reports an
unrelated pre-existing AR CSV expectation mismatch in `AgingReportTest`; the
AP aging assertions pass. Repository-wide SPA typecheck remains blocked by an
unrelated malformed `spa/src/pages/crm/sales-orders/create.tsx` already present
in the shared working tree.

Original production audit: 42/100, blocked, because AP could accept payments against unposted drafts, post through arbitrary or inactive ledger accounts, and approve three-way-match overrides without a distinct authorization boundary; source duplication, historical aging, and journal reversal controls also required separate financial work. The 2026-08-25 execution update above supersedes that pre-fix assessment.

At the audit baseline, the focused Docker test suite was green, but its coverage
was concentrated on happy-path workflow and current-state behavior. The
execution update above records the added negative and historical-control tests.

## Executive assessment

The module has a coherent service boundary, transactional bill creation, row locking around several state transitions, normal posting-period guards, accepted-GRN draft generation, supplier scoping, and a usable internal UI. The main risk is that the server-side financial authority is weaker than the UI suggests. Several restrictions are implemented only by front-end filtering or by the intended action path, while direct API calls can reach the weaker service branches.

The audit score is capped below release-ready quality by three P0 control failures: payment can be recorded for an unposted draft; account classification and active-state checks are not enforced server-side; and three-way-match overrides lack a dedicated permission and maker-checker separation. The remaining P1 findings affect duplicate liability, party identity, period integrity, historical reporting, data disclosure, and correction workflows.

## Blockers and high-value findings

### M027-F01 — P0, Broken: draft bills can receive payments

Evidence: api/app/Modules/Accounting/Services/BillService.php:520-541 rejects only Cancelled and Paid before creating a payment. The payment journal is then posted at :558-569. The UI hides payment for drafts in spa/src/pages/accounting/bills/detail.tsx:129-130, but the API is the authority. The bill-payment migration, api/database/migrations/0046_create_bill_payments_table.php:13-27, has no lifecycle state.

Impact: a draft with no original AP credit can be paid, producing an inconsistent liability history and an apparently valid cash journal.

Action: require the bill to be Unpaid or Partial, require a posted source journal, and make the payment transition atomic. Add direct API tests for draft, cancelled, paid, and concurrent payment cases. Separate implementation recommended because it changes financial state-machine behavior.

### M027-F02 — P0, Broken: server-side account type and active-state controls are missing

Evidence: api/app/Modules/Accounting/Http/Requests/StoreBillRequest.php:33-39 and StoreBillPaymentRequest.php:20-26 validate IDs as strings but do not validate account classification or active state. BillService.php:706-742 and :543-546 only decode the IDs. The UI's expense-account and cash-account filters are advisory.

Impact: a caller can route expense, VAT, AP, or payment credit lines through an inappropriate or inactive account, compromising ledger meaning and auditability.

Action: enforce active expense accounts for bill lines and active asset/cash/bank accounts for payments in the service/request boundary, with tests for wrong type, inactive account, missing account, and direct API calls. Separate implementation recommended.

### M027-F03 — P0, Broken: three-way-match override authorization lacks separation of duties

Evidence: BillService.php:198-201 and :421-431 accept allow_override based on the request path; the bill routes and controller use accounting.bills.create for posting. No dedicated match-override permission exists in RolePermissionSeeder.php:165-171. StoreBillRequest.php:27 has no override_reason rule. Manual provenance records the same actor as exception owner and approver at BillService.php:214-217 and :655-664, while the fallback reason at :228-234 can be generated automatically. JournalEntryService.php:288-301 exempts non-empty reference types from its self-posting check.

Impact: the user who creates or posts a mismatched bill can authorize the exception without an independent finance review, and the audit trail can contain a default rather than a meaningful reason.

Action: add a dedicated permission and explicit reason validation, require a distinct authorized checker for override approval, persist the actor/time/evidence, and ensure normal journal maker-checker rules cannot be bypassed by reference metadata. Separate implementation recommended.

### M027-F04 — P0, Broken: one accepted source can support multiple AP bills

Evidence: api/database/migrations/2026_08_08_100001_add_grn_id_to_bills.php:24-28 adds an indexed but non-unique goods_receipt_note_id. BillService.php:272-287 performs an idempotency lookup only in the accepted-GRN listener path. SupplierPortalService.php:324-339 deduplicates vendor plus bill number, not source allocation. ThreeWayMatchService.php:43-53 aggregates accepted receipt quantities without subtracting quantities already billed. The database unique key in 0044_create_bills_table.php:33-35 is only vendor plus bill number.

Impact: repeated manual, portal, or concurrent submissions can create duplicate liabilities against the same accepted receipt or purchase-order quantity.

Action: model source consumption/allocation, enforce it under lock, define whether one receipt may create multiple bills, and add unique or allocation constraints plus concurrency tests for manual, portal, listener, and retry paths. Large/separate implementation recommended.

### M027-F05 — P1, Broken: bill vendor is not bound to PO and GRN vendor

Evidence: BillService.php:118-123 selects the bill vendor from request data. Its provenance checks at :655-690 verify PO/GRN relationships and accepted status but do not require the selected vendor to match both documents. The UI explicitly allows changing the PO-prefilled vendor in spa/src/pages/accounting/bills/create.tsx:123-125. ThreeWayMatchService.php:201-257 does not add a party-identity check.

Impact: a bill can be attributed to the wrong supplier while still passing the source and quantity checks.

Action: assert PO.vendor_id = GRN.vendor_id = bill.vendor_id under lock for stock provenance; define a documented exception path for legitimate agency/triangulation cases. Separate implementation recommended.

### M027-F06 — P1, Broken: bill cancellation can post a reversal in a closed period

Evidence: BillService.php:500-506 calls JournalEntryService::reverse. JournalEntryService.php:321-371 defaults the reversal date to today at :339-350, marks the reversal posted, and does not call AccountingPeriodService::assertPostingAllowed. Normal bill posting does use a period guard.

Impact: cancelling a historical AP bill can mutate a closed current or historical accounting period without the period approval path.

Action: require an explicit reversal date and period check, or route the operation through the same controlled posting service as other journal entries. Add closed-period and cross-period cancellation tests. Separate implementation recommended.

### M027-F07 — P1, Broken: AP aging as-of reporting is not historical

Evidence: FinancialStatementController.php:111-128 accepts and passes as_of. BillService.php:605-641 queries current Unpaid/Partial bills and current balances, does not constrain bill date to the as-of date, and does not reconstruct balances from payment dates. The result therefore includes future bills and removes or includes payments according to current balance rather than the selected date.

Impact: period-end AP reporting can disagree with the ledger and cannot support reliable historical close or audit queries. The query also loads the open-bill set without a bounded aggregation at :609-613.

Action: define the historical semantics, filter bills by bill date, reconstruct payment balance through the selected date, and use bounded/aggregated queries. Add fixtures for future bills, payments after as-of, partial payments, and cancelled/posting transitions. Large/separate implementation recommended.

### M027-F08 — P1, Incomplete: AP aging export permission and date validation are not enforced at the route boundary

Evidence: routes.php:117-120 protects the AP aging endpoint with accounting.statements.view only, despite accounting.statements.export being defined in RolePermissionSeeder.php:184-185. FinancialStatementController.php:113-115 parses raw input with Carbon::parse without request validation or a documented invalid-date response contract.

Impact: a viewer may export data without the intended export permission, and malformed or ambiguous dates can produce inconsistent report requests.

Action: split view and export authorization, validate as_of with a request object and explicit timezone/format, and test both JSON and CSV paths. Separate implementation recommended because it changes authorization policy.

### M027-F09 — P1, Incomplete: internal reviewers cannot see persisted provenance and exception evidence

Evidence: api/database/migrations/2026_08_13_120000_add_commercial_provenance_controls.php:31-43 persists provenance_type, exception evidence, owner, approver, and timestamp. BillResource.php:15-70 omits those fields, and spa/src/pages/accounting/bills/detail.tsx:204-224 does not render them.

Impact: the evidence needed to review a manual or overridden bill exists in storage but is not available in the normal AP review surface.

Action: expose the fields in a dedicated internal resource/detail section with role-aware redaction and attachment/error states. Add resource and UI contract tests. Separate implementation recommended because the resource is shared across internal and portal consumers.

### M027-F10 — P1, Broken: the shared bill resource leaks internal exception data to the supplier portal

Evidence: BillResource.php:33-37 exposes variance, override, reason, review status, and match URL fields. SupplierPortalController.php:303-308 returns BillResource for supplier invoices, and SupplierPortalService.php:446-468 loads those bill details for supplier scope.

Impact: internal AP exception data and review metadata can cross the supplier boundary even though the portal is not the internal control surface.

Action: split internal and supplier-facing resources, explicitly define which statuses/evidence suppliers may see, and add a supplier-role serialization test. Separate implementation recommended.

### M027-F11 — P1, Missing: payment correction/void lifecycle is absent

Evidence: accounting routes expose post, cancel, and pay at api/app/Modules/Accounting/routes.php:74-83. BillPayment migration 0046_create_bill_payments_table.php:13-27 has no void/reversal status or replacement linkage. BillService.php:520-595 only records and posts payments.

Impact: an erroneous or fraudulent payment cannot be corrected through a first-class AP workflow with period controls, authorization, and an auditable reversal chain.

Action: design a payment void/reversal lifecycle, including closed-period handling, permission separation, replacement references, and balance recalculation. Coordinate with the journal-ledger module. Large/separate implementation recommended.

## Lifecycle and UI findings

### M027-F12 — P2, Broken: vendor restore endpoint cannot bind soft-deleted vendors

Evidence: Vendor.php:15-17 uses SoftDeletes. VendorController.php:59-62 calls restore, but Accounting/routes.php:66-73 does not enable withTrashed route binding. VendorService.php:21-25 supports trashed filtering, so the API and list imply a lifecycle that the restore route cannot complete.

Action: enable explicit trashed binding and add a restore authorization, route, and response test. This is a contained same-session candidate, but it was not applied because the overall plan is dominated by separate financial work.

### M027-F13 — P2, Incomplete: vendor list open balances are not populated

Evidence: VendorService.php:21-39 lists vendors without an open-balance aggregate. VendorResource.php:17-32 reads the raw open_balance attribute, while VendorController.php:30-35 computes it only for show. The list UI renders the field at spa/src/pages/accounting/vendors/index.tsx:28-35.

Impact: the vendor table presents blanks or nulls where users expect payable exposure, reducing triage value and making list/detail totals inconsistent.

Action: add a scoped open-balance aggregate or explicit zero, keep the query bounded, and cover active/trashed and pagination cases. Contained same-session candidate; deferred with the broader plan.

### M027-F14 — P2, Polish: payment resource performs a journal lookup per payment

Evidence: BillPaymentResource.php:25-27 calls JournalEntry::find for each payment instead of using an eager-loaded relationship.

Action: add the relationship/resource load and a query-count regression test. Contained same-session candidate; deferred.

### M027-F15 — P2, Polish: supplier invoice empty state uses the wrong perspective

Evidence: spa/src/pages/portal/supplier/invoices/index.tsx:102-106 says “Invoices from your customers will appear here” although this screen lists invoices submitted by the supplier.

Action: correct the copy and, if product policy allows, add explicit submission/review status rather than exposing internal exception fields. Contained UI work; deferred.

## Strengths observed

- Accounting routes have authentication, feature gating, and per-action permissions.
- Normal bill creation/posting and journal posting use database transactions, row locking in key transitions, and posting-period checks.
- Accepted-GRN draft creation has listener idempotency and focused tests for partial acceptance and retry behavior.
- Supplier portal invoice queries are scoped to the authenticated vendor, and portal submission has vendor-plus-bill-number idempotency.
- The accounting screens generally follow the Atelier design system for dense tables, IDs, amounts, focus states, loading, error, and empty states.

## Evidence checked

- Working tree, branch, recent commits, and release surface were inspected; no M027 production source changes were present before this audit.
- API routes, requests, controllers, services, models, resources, migrations, permission seeders, and relevant SPA pages were read.
- Focused Docker test run: 44 passed, 171 assertions.
- Dependency cycle was documented in inventory rather than silently treating unready dependencies as complete.

## Evidence missing / follow-up validation

- Full browser/e2e validation of internal and portal flows.
- A historical AP aging fixture and ledger reconciliation at multiple as-of dates.
- Adversarial authorization and concurrency tests for every P0/P1 finding.
- Deployed permission matrix and production database constraint verification.

## Release decision

All 15 planned findings were implemented and rechecked. The module is released
as `✅ Verified`; remaining repository-wide typecheck/Pint limitations are
documented in the execution update and are outside this module's scope.
