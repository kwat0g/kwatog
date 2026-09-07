# Audit: accounting-budgeting — 2026-09-06

## Summary

Overall health is strong for a thesis-grade ERP: JE posting/reversal is lock-disciplined
(header→lines→accounts order, lockForUpdate re-reads everywhere), maker-checker + closed-period
guards are real and tested, money math is bcmath throughout, TINs are encrypted and masked, and
the 34-test Feature suite covers the risky races (post race, double-finalize, period concurrency,
ledger invariants). The worst problems: (1) **reversed journal entries are excluded from every GL
statement and account-balance query**, so cancelling/reversing a document retroactively rewrites
historical periods and the GL disagrees with the AR/AP aging subledgers as of past dates; (2) the
**Statement of Account ledger omits credit-note applications**, overstating the customer's closing
balance while the aging block on the same report nets them; (3) **bill-side budget enforcement is
both bypassable (department_id optional, SPA never sends it) and inconsistent with the documented
enforcement modes (hard-blocks at CRITICAL even when mode=off)**. No test run was performed (code-level
evidence only).

## Findings

| ID | Category | Severity | Effort | Title | Location | Evidence/Repro |
|---|---|---|---|---|---|---|
| AB-01 | Broken process | Critical | M | Reversed JEs vanish from all GL statements for periods before the reversal date | api/app/Modules/Accounting/Services/Statements/TrialBalanceService.php:34 (also IncomeStatementService.php:33, BalanceSheetService.php:42, AccountService.php:82, BudgetConsumptionService.php:283) | Every statement query filters `je.status = 'posted'`; the reversed original is dropped and only the dated reversal remains — June revenue disappears from a July IS after an August cancel, while AR aging (cancelled_at > cutoff) still counts it |
| AB-02 | Broken process | High | S | Statement of Account ledger omits credit-note applications | api/app/Modules/Accounting/Services/StatementOfAccountService.php:97-181 vs 217-222 | buildTransactions() emits invoices/cancellations/collections only; CreditNoteApplication rows feed computeAging() but never the running balance, so closing_balance > total_outstanding on the same report whenever a credit was applied |
| AB-03 | Risk | Medium | S | Bill payment + bill line inputs miss the centavo contract | api/app/Modules/Accounting/Requests/StoreBillPaymentRequest.php:23, StoreBillRequest.php:35-37 | `amount`/`quantity`/`unit_price` are bare `numeric`: `1.999` silently Money::round2→2.00 on a real payment, `1e3` → BCMath ValueError 500, unbounded → decimal(15,2) overflow 500; the identical bug class is documented as FIXED in StoreCollectionRequest/StoreJournalEntryRequest comments |
| AB-04 | Broken process | Medium | S | Bill budget check is bypassable and ignores enforcement mode | api/app/Modules/Accounting/Services/BillService.php:154-166, BudgetEnforcementService.php:93-95 | department_id is nullable in StoreBillRequest and the SPA bill form never sends it → check silently skipped; when sent, checkAvailability() returns canProceed=false at CRITICAL, blocking bills even with `budgeting.enforcement_mode=off` (PR/PO assess()/enforce() honor the mode) |
| AB-05 | Risk | Medium | M | Budget availability check is TOCTOU — no reservation | api/app/Modules/Accounting/Services/BudgetEnforcementService.php:28-99 | checkAvailability() reads derived consumption with no lock/reservation; concurrent PR/PO creations each see the same remaining budget and both pass; consumption rows only materialize after commit |
| AB-06 | Risk | Medium | M | Credit note header can link another party's source document (known, deferred M028-F19) | api/app/Modules/Accounting/Services/CreditNoteService.php:364-393, Resources/CreditNoteResource.php | create() decodes customer_id and invoice_id independently; customer A's note can carry customer B's invoice_number (metadata disclosure + false audit link); money cannot cross — apply() re-checks party at :243/:277; fix reverted because ReturnManagement fixtures depend on the gap |
| AB-07 | Bad practice | Low | S | Budget sync endpoints return raw primary keys | api/app/Modules/Accounting/Controllers/BudgetController.php:404-427 | `outbox_id`, `run_id`, `id` are `(string) $model->getKey()` — raw keys in API responses, against the hash-id-only rule |
| AB-08 | Gap | Low | S | Budget revisions/transfers cut in code but still documented as features | api/database/migrations/0456_drop_budget_revisions_table.php, 0459_drop_budget_transfers_table.php vs docs/DEFENSE-TRACEABILITY.md:84, CLAUDE.md module 17 | Tables dropped 2026-08-07 (scope cut); docs still assert `BudgetTransfer`, `BudgetRevision` exist — defense-traceability exposure |
| AB-09 | Missing | Low | M | Balance sheet never rolls prior-year net income into retained earnings | api/app/Modules/Accounting/Services/Statements/BalanceSheetService.php:75-90 | Equity gets only current-FY net income; with any prior-year revenue/expense the equation fails and `balanced:false` is returned (consequence of the no-close-wizard scope cut, but silently unbalanced output) |
| AB-10 | Bad practice | Low | S | SPA types declare numeric ids where API returns hash strings | spa/src/types/accounting.ts:117,259 | `BillItem.id`/`InvoiceItem.id: number` but BillItemResource/InvoiceItemResource return `$this->hash_id` (string) |
| AB-11 | Bad practice | Low | S | Collection form skips the client-side centavo refinement | spa/src/pages/accounting/invoices/detail.tsx:38 | `z.coerce.number().positive()` round-trips the amount through a JS float; backend decimal:0,2 catches it as a mapped 422, but the form does not mirror the validated shape (tracked in StoreCollectionRequest comment) |
| AB-12 | Risk | Low | S | Reversal date may precede the original entry date | api/app/Modules/Accounting/Requests/ReverseJournalEntryRequest.php:18-23, JournalEntryService.php:454 | `reverse_date` validated only as `date`; combined with AB-01 semantics a back-dated reversal moves money across periods (period-close guard still applies) |

### AB-01 (Critical) — reversed entries are erased from history
When any JE is reversed (manual reversal, invoice cancel, bill cancel, payment void), the original
header flips to `reversed` and every GL aggregate — trial balance, income statement, balance sheet,
COA current balances, and budget posted-actuals — filters `status = 'posted'`, dropping it entirely.
The reversing entry is included only from its own date. Any report window that contains the original
date but not the reversal date therefore shows the entry as if it never happened: revenue, AR, AP and
expense are retroactively restated, and two consecutive monthly income statements no longer sum to the
YTD figure. The subledgers disagree in the opposite direction: InvoiceService::aging / BillService::aging
deliberately keep documents cancelled *after* the as-of date, so for the same historical date the AR
aging total and the balance-sheet AR balance differ. Reversed entries must remain in period aggregates
(include `posted`+`reversed` by date, or store an effective-end date), with the cancellation/reversal
event reflected only from the reversal date forward.

### AB-02 (High) — Statement of Account double-counts applied credits
buildTransactions() constructs the ledger from invoices, cancellations and collections only.
CreditNoteApplication rows are read solely inside computeAging(). A customer with a ₱10k invoice and a
₱4k applied credit note gets a statement closing balance of ₱10k next to an aging total of ₱6k on the
same page — the document sent to the customer overstates what they owe. Adding credit applications as
negative ledger rows (mirroring the collections block, keyed by `created_at`/applied date) closes it.

### AB-04 (Medium) — bill budget enforcement semantics drift
Two independent defects in one call site: (a) the check runs only `if (!empty($data['department_id']))`
and the SPA bill form never sends the field, so manual bills skip enforcement entirely (PR/PO-side
enforcement is the only real gate); (b) when a department is supplied, the raw `checkAvailability()`
result is used, and that method returns `canProceed=false` at CRITICAL with "Finance acknowledgment
required" — so a bill is hard-rejected at 90% consumption even when `budgeting.enforcement_mode=off`,
contradicting the documented off/warn/block contract that the PR/PO path follows via assess()/enforce().

## Cross-module flags

- **return-management** — blocks AB-06: `ReturnRequestService::creditNoteFor()` forwards `$rma->customer_id`/`$rma->invoice_id` without a party cross-check, and its restock tests mint two customers per fixture; the CreditNote party-binding fix was implemented and reverted for this reason (documented in CreditNoteService).
- **purchasing** — AB-05 spans Purchasing: enforcement call sites are PurchaseRequestService:281 / PurchaseOrderService:197,345,402; any reservation-based fix must land there too. ThreeWayMatchService is owned by Purchasing; only its contract at the Accounting boundary was verified here.
- **dashboard** — FinanceDashboardController delegates entirely to `App\Modules\Dashboard\Services\FinanceDashboardService`; not part of this unit, its panel gating/caching was not audited.
- **docs** — AB-08: docs/DEFENSE-TRACEABILITY.md:84 and CLAUDE.md module 17 still name BudgetTransfer/BudgetRevision; needs a doc update to match the 2026-08-07 scope cut.
- **crm / supply-chain** — InvoiceService::finalize calls SalesOrderService::markInvoiced and consumes Delivery status; only the Accounting side of those contracts was verified.

## What was NOT checked

- No tests were executed; all evidence is code reading.
- PdfService/PdfController rendering content (invoices, bills, JEs, statement PDFs).
- SyncBudgetActualsJob internals and the outbox dispatcher beyond the service/handoff surface (durable-handoff test exists).
- FinanceDashboardService (Dashboard module), ChainBroadcaster internals.
- Imports (AccountImporter/CustomerImporter/VendorImporter) and the three email listeners/Mail classes.
- VendorService/CustomerService CRUD internals beyond resource masking and TIN encryption (both verified).
- RolePermissionSeeder full grant matrix — only presence of the SoD/override/void/export permissions was confirmed.
- Supplier-portal and delivery→invoice handoff call sites (cross-module entry points into BillService/InvoiceService).
- SPA pages beyond spot checks: bills/index+create, invoices/detail, credit-notes/index, budgeting/index, journal-entries money util, periods helper; forms for vendors/customers/CoA were not opened.
