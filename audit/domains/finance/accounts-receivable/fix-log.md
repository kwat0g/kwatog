# M028 — Accounts Receivable fix log

Audit date: 2026-08-24  
Module status: 🔁 Needs Re-audit  
Implementation status: Implementation work completed for the safe portions; residual policy, correction, and cross-module controls remain deferred.

## Audit actions

- Refreshed the module registry before selecting work.
- Documented the dependency-cycle exception in `inventory.md`; M028 was claimed because no remaining module satisfied a strict topological dependency order.
- Inspected the API, services, models, migrations, permissions, resources, internal SPA, customer portal, background schedule, recent changes, and release surface.
- Ran the focused Docker suite: 65 passed, 280 assertions.

## Code changes

The original audit session authored only audit artifacts. The resumed Plan Ready
session below executed the existing ordered plan as required by the module-audit
workflow.

## Original audit deferral rationale

The findings were dominated by invoice state-machine integrity, account
classification, credit-note target safety, prebill separation of duties,
source-party binding, reversal periods, historical reporting, receipt
integration, and customer-facing data boundaries. The resumed session was
dedicated to this module, so the existing `separate-recommended` items were
implemented where the required rule was unambiguous. Items requiring a policy
or cross-module decision remain explicitly deferred below.

## Deferred contained candidates

Customer restore binding, customer-list credit aggregation, and collection-resource eager loading were individually small. They are recorded as completed/verified in the implementation session below; the remaining deferrals are the policy, correction, and cross-module items in that session's deferred list.

## Release note

This log is complete for the implementation session. The module must be
released through the audit release script after artifact verification so the
lock is removed, the registry is regenerated, and the next candidate is
recommended.

## Implementation session — 2026-08-25

### Fixed and verified

- M028-F01 (Broken/P0): `api/app/Modules/Accounting/Services/InvoiceService.php:163-203` now locks and re-reads the invoice inside `DB::transaction()`, re-checks `Draft`, writes through the locked model, and replaces items only after that guard. The new stale-update regression is in `api/tests/Feature/Accounting/InvoiceDraftNumberingTest.php:222-253`.
- M028-F02 (Broken/P0): `api/app/Modules/Accounting/Services/PostingAccountResolver.php:40-63,99-106` centralizes active/type checks. Invoice lines use Revenue at `InvoiceService.php:582-601`, collection cash uses Asset at `InvoiceService.php:375`, configured AR/VAT/discount accounts are type-checked at `InvoiceService.php:647-655`, and credit-note lines/GL lines are checked at `api/app/Modules/Accounting/Services/CreditNoteService.php:117,336-351`. Direct wrong-type regressions are in `InvoiceCollectionTest.php:196-212` and `CreditNoteTest.php:234-252`.
- M028-F03 (Missing/P1): `api/database/migrations/2026_08_25_120000_harden_accounts_receivable_controls.php:13-15` adds a unique nullable collection idempotency key; the request accepts it at `api/app/Modules/Accounting/Requests/StoreCollectionRequest.php:26`, and replay/payload mismatch behavior is implemented at `InvoiceService.php:377-417`. `InvoiceCollectionTest.php:170-194` verifies one collection, one journal, and one receipt on replay.
- M028-F05 (Broken/P1): `InvoiceService.php:116-119,657-720` validates and locks the customer/SO/delivery chain, then re-locks and revalidates it at finalization. The same migration adds source foreign keys at `2026_08_25_120000_harden_accounts_receivable_controls.php:21-26`.
- M028-F06 (Broken/P1): invoice cancellation now records `cancelled_at`/`cancelled_by` and passes an explicit date/reason to reversal at `InvoiceService.php:322-356`. The shared journal reversal guard already checks the effective period; the remaining cross-period correction policy is deferred below.
- M028-F08 (Broken/P1): `InvoiceService::aging()` at `InvoiceService.php:457-544` filters invoice dates to the cutoff, excludes post-cutoff cancellations, reconstructs settlement from collections and credit applications through an end-of-day cutoff, preserves legacy snapshots only when no event rows exist, and uses date-based bucket boundaries. `HistoricalReceivablesTest.php:34-91` verifies future invoices, post-cutoff payment, post-cutoff cancellation, and the later zero balance.
- M028-F09 (Broken/P1, partial): `api/app/Modules/Accounting/Services/StatementOfAccountService.php:38-90,97-180,186-251` now validates strict dates, emits cancellation events, filters collection history, reconstructs aging from dated events, and uses date semantics for due-day boundaries. The same historical fixture verifies a 1,500.00 cutoff balance. Full effective-posting/event-model parity remains deferred.
- M028-F10 (Broken/P0): `CreditNoteService.php:238-320` now permits only finalized/partial invoices or unpaid/partial bills with a Posted target journal, under source/target locks, before changing balances. The focused credit-note suite passed all target-state regressions.
- M028-F12 (Missing/P1): collections issue an OR after the posted collection journal at `InvoiceService.php:421-453`; `OfficialReceiptService.php:30-68` locks and reuses an existing receipt, and `OfficialReceiptIssued.php:10-14` dispatches after commit. The migration’s unique collection link is at `2026_08_25_120000_harden_accounts_receivable_controls.php:17-19`.
- M028-F13 (Broken/P1): the portal filters dashboard/list/detail invoices to Finalized/Partial/Paid at `api/app/Modules/B2B/Services/CustomerPortalService.php:72-74,146-165`, uses the allowlisted `CustomerPortalInvoiceResource.php:15-53`, and sends the safe PDF through `CustomerPortalController.php:101-146` and `api/app/Modules/Accounting/Services/PdfService.php:59-68`/`api/resources/views/pdf/customer-invoice.blade.php`. Portal status and BIR/remarks-redaction tests passed.
- M028-F14 (Incomplete/P1): strict `Y-m-d` input is defined in `api/app/Modules/Accounting/Requests/StatementAsOfRequest.php:17-31`; the helper is named `asOfDate()` so it does not override Laravel Request's incompatible `date()` signature. AR aging uses the export check at `FinancialStatementController.php:110-128,173-178`; customer and portal SOA endpoints validate their `as_of` input at `api/app/Modules/Accounting/Controllers/CustomerController.php:69-76` and `api/app/Modules/B2B/Controllers/CustomerPortalController.php:234-246`. Permission/date boundary tests passed except the pre-existing CSV-header expectation mismatch documented below.
- M028-F15/F16/F17 (contained): restore binding is present in `api/app/Modules/Accounting/routes.php:87-95`; customer list credit exposure is aggregated at `api/app/Modules/Accounting/Services/CustomerService.php:22-28` and shown in `spa/src/pages/accounting/customers/index.tsx:26-39`; `api/app/Modules/Accounting/Resources/CollectionResource.php:21-30` no longer performs a per-row journal lookup. These were verified as existing dirty-worktree changes; no unrelated module was edited.

### Deferred items and exact reasons

- M028-F04 (Incomplete/P1): prebill maker/checker separation is not implemented. A business decision is required on whether prebilling is intentionally one-step or must introduce submit/approve states, a distinct checker, credit-limit behavior, and immutable evidence. The current path remains in `InvoiceService.php:95-114`.
- M028-F07 (Broken/P1): cancellation/reissue reconciliation with terminal SalesOrder `invoiced` state is not implemented. It requires CRM/Supply Chain transition and delivery-allocation decisions outside this module’s scope; the current cancellation change is limited to AR reversal/audit fields.
- M028-F08/F09 residuals (P1): the implementation reconstructs the available invoice/collection/credit-application/cancellation history, but the schema still lacks a first-class effective-finalization/reversal event model. Backdated posting semantics, timezone policy, and full internal/portal parity need a follow-up design and audit.
- M028-F11 (Missing/P1): credit-note void/reversal/correction lifecycle is not implemented. It needs an authorized state transition, closed-period handling, replacement linkage, application restrictions, and journal-ledger coordination.
- M028-F12 residual (P1): receipt correction/void behavior and failure/outbox recovery policy remain undefined; collection-to-OR creation and replay uniqueness are implemented and tested.

### Verification record

- PHP syntax checks passed for the changed AR source, migration, resource, view, and regression tests; `git diff --check` passed for the touched tracked paths.
- On the dedicated `ogami_test_ar_20260825` database, the implementation-focused run passed 24 tests/119 assertions across invoice draft/update, collections, and credit notes. The historical AR fixture passed 1/5 assertions; the portal status/redaction filter passed 2/10 assertions; Invoice BIR and statement-service checks passed 6/25 assertions.
- The full `CustomerPortalServiceTest` passed 15/49 assertions before the added targeted regressions. The full collection suite passed 7/53 assertions before the added replay/type regressions.
- `AgingReportTest` passed the bucket/AP/permission cases (3/4); its CSV case fails because the existing dirty `FinancialStatementController::csv()` emits valid quoted fields such as `"Row Type"`, while the dirty test asserts an unquoted header. `FinancialStatementBoundaryTest` has the same isolated header expectation failure (2/3 pass). This was not changed because it belongs to another in-progress financial-statement change.
- SPA typecheck is blocked by pre-existing syntax errors in `spa/src/pages/crm/sales-orders/create.tsx:169,238,308,321,331,416`, outside Accounts Receivable. The changed customer-list file introduced no reported type error.

## Re-audit verification session — 2026-08-25

- Claimed M028 as the first unlocked `🔁 Needs Re-audit` module after registry refresh, re-read the existing report/plan/log, and did not repeat discovery or rewrite the action plan.
- No production source changes were made in this verification pass; the prior implementation changes remain present in the shared dirty worktree. The focused source checks covered the lock/re-check paths at `InvoiceService.php:163-203,360-454`, account classification at `PostingAccountResolver.php:40-63,99-120`, credit-note target guards at `CreditNoteService.php:220-318`, historical reports at `InvoiceService.php:457-544` and `StatementOfAccountService.php:97-272`, receipt integration at `OfficialReceiptService.php:30-68`, and the portal boundary at `CustomerPortalService.php:62-74,146-165` and `CustomerPortalInvoiceResource.php:15-53`.
- `php -l` passed for all changed AR controllers, services, models, requests, resources, routes, the portal resource, and `2026_08_25_120000_harden_accounts_receivable_controls.php`.
- Serial Docker verification passed: `InvoiceDraftNumberingTest` 7 tests/28 assertions; `InvoiceCollectionTest` 9/61; `HistoricalReceivablesTest` 1/5; `CustomerPortalServiceTest` 16/56; `StatementServicesTest` 1/9; `InvoiceBirFieldsTest` 5/16; isolated customer-credit application, stale-credit replay, and credit-note finalization cases passed.
- `AgingReportTest` passed 3/4 and `FinancialStatementBoundaryTest` passed 2/3. The isolated failures are the known CSV-header expectation mismatch: `fputcsv()` correctly emits quoted fields such as `"Row Type"`, while the dirty tests expect an unquoted header. The shared financial-statement CSV implementation/test pair was not changed because it is outside the AR-owned fix scope.
- The initial multi-file test invocation also hit shared `ogami_test` schema teardown races; after an explicit test-database refresh, serial class/method runs produced the results above. This is verification-environment contention, not a new AR assertion failure.
- M028 remains `🔁 Needs Re-audit`: F04 (prebill maker/checker policy), F07 (sales-order/delivery cancellation and reissue reconciliation), the first-class effective-posting/event-model residuals of F08/F09, F11 (credit-note void/reversal), and F12 receipt correction/void and failure-recovery policy remain pending because they require business decisions or cross-module journal/CRM/supply-chain coordination. They were not guessed or changed in this session.

## Current audit session — 2026-08-25

- Re-read the existing audit report and action plan after claiming M028. The existing implementation was retained; discovery and plan authoring were not repeated.
- Verified the previously fixed controls at `api/app/Modules/Accounting/Services/InvoiceService.php:163-203,360-454,457-544`, `api/app/Modules/Accounting/Services/PostingAccountResolver.php:40-123`, `api/app/Modules/Accounting/Services/CreditNoteService.php:220-361`, `api/app/Modules/Accounting/Services/StatementOfAccountService.php:97-272`, `api/app/Modules/Accounting/Services/OfficialReceiptService.php:30-68`, `api/app/Modules/B2B/Services/CustomerPortalService.php:62-165`, `api/app/Modules/B2B/Resources/CustomerPortalInvoiceResource.php:15-53`, `api/app/Modules/Accounting/routes.php:102-104`, `api/app/Modules/Accounting/Services/CustomerService.php:22-43`, and `api/app/Modules/Accounting/Resources/CollectionResource.php:21-30`.
- On disposable database `ogami_m028_audit_20260825`, the AR-focused serial run passed 47 tests and 205 assertions across invoice draft/finalization, collections, credit notes, historical receivables, customer portal, statement services, and BIR fields.
- `AgingReportTest` and `FinancialStatementBoundaryTest` passed 5/7 tests. The two failures are the known CSV-header expectation mismatch: the controller emits valid quoted fields such as `"Row Type"` through `fputcsv()`, while the shared financial-statement tests expect unquoted headers. No out-of-scope financial-statement change was made.
- PHP syntax checks passed for the changed AR services, requests, resources, routes, portal resource, journal boundary, and AR migration; `git diff --check` passed for the AR-owned tracked paths. An initial run against shared `ogami_test` also encountered migration-table/duplicate-table races from concurrent test database refreshes; the disposable rerun passed.
- No production-code fixes were added in this session. F04 remains blocked on the prebill maker/checker and credit-exposure policy; F07 on Sales Order/delivery cancellation and reissue semantics; F08/F09 residuals on a first-class effective-posting/event model and timezone/backdating policy; F11 on the credit-note void/reversal contract and journal-ledger coordination; and F12 on receipt correction/void and failure/outbox recovery policy. Status remains `🔁 Needs Re-audit`.

## Current audit session — 2026-08-25 (registry claim)

- Refreshed the registry and atomically claimed M028 as the first unlocked `🔁 Needs Re-audit` module. Read the existing audit report, action plan, and fix log in full; discovery and plan authoring were not repeated.
- Git history has no post-report commit for the AR source paths. The large dirty-worktree implementation is the same uncommitted work already documented above, so it does not invalidate the existing findings or plan.
- Focused source verification confirmed the fixed controls at `InvoiceService.php:168-224,328-454,457-544,657-708`, `PostingAccountResolver.php:20-123`, `CreditNoteService.php:220-318`, `StatementOfAccountService.php:97-255`, `OfficialReceiptService.php:30-68`, `CustomerPortalService.php:63-161`, `CustomerPortalInvoiceResource.php:15-53`, and the AR routes/migration. PHP syntax passed for 27 AR-owned files and `git diff --check` passed for the tracked AR paths.
- No Docker/Postgres service was running for a fresh integration run. The latest behavioral verification remains the prior serial AR run recorded above; no production source changed after that verification.
- No production-code fixes were added. F04 remains blocked on prebill maker/checker and credit-exposure policy; F07 on Sales Order/delivery cancellation and reissue semantics; F08/F09 residuals on a first-class effective-posting/event model and timezone/backdating policy; F11 on credit-note void/reversal and journal-ledger coordination; and F12 on receipt correction/void and failure/outbox recovery policy. Status remains `🔁 Needs Re-audit`.

## Current audit session — 2026-08-25 (verification and release)

- Refreshed the registry and claimed M028 as the first eligible unlocked `🔁 Needs Re-audit` module. The existing audit report, action plan, and fix log were read in full; discovery and plan authoring were not repeated.
- Git history has no post-report commit for the AR source paths. The current uncommitted implementation is the same work documented above, so the existing findings and ordered plan remain applicable. No production-code changes were made in this claim.
- PHP syntax checks passed for all 27 AR production, migration, resource, route, and regression-test files covered by the fixed controls. Scoped `git diff --check` passed for the AR source/test paths and audit artifacts.
- Focused serial verification on disposable database `ogami_m028_session_20260825` passed 52 tests and 239 assertions across invoice draft/update/finalization, collections and receipt replay, account classification, credit-note application, historical receivables, statement services, BIR fields, and customer-portal status/redaction boundaries.
- The only two failures were the known CSV-header expectation mismatch in `AgingReportTest` and `FinancialStatementBoundaryTest`: the shared controller emits valid quoted fields such as `"Row Type"` through `fputcsv()`, while the dirty tests assert unquoted headers. The financial-statement implementation/test pair was not changed because it is outside this module's owned fix scope.
- F04 remains deferred because prebill maker/checker separation, credit-limit treatment, and compensating evidence require a business decision. F07 remains deferred because cancellation/reissue must reconcile CRM sales-order state and delivery allocation outside this module. F08/F09 residuals remain deferred pending a first-class effective-posting/reversal event model and explicit backdating/timezone policy. F11 remains deferred pending an authorized credit-note void/reversal contract coordinated with journal-ledger. F12 residuals remain deferred pending receipt correction/void and failed-issuance/outbox-recovery policy.
- Because those plan items remain genuinely pending, M028 is released as `🔁 Needs Re-audit`; the plan is not complete and the next session should resume with the deferred decisions/designs rather than re-auditing fixed controls.
