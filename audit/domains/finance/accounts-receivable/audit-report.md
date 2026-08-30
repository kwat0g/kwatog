# M028 — Accounts Receivable audit report

Audit date: 2026-08-24  
Status: 📋 Plan Ready  
Release recommendation: separate implementation work required

Production audit: 38/100, blocked, because a stale draft update can mutate a finalized invoice after its journal is posted, AR can post through arbitrary or inactive accounts, credit-note application can mutate an unposted invoice, and the remaining period, historical-reporting, receipt, party-binding, and portal-boundary controls require separate work.

The focused Docker test suite is green, but its coverage is concentrated on happy-path workflow, current-state behavior, and tenant scoping. Passing tests do not offset the missing negative, historical, and correction controls below.

## Executive assessment

The module has a coherent service boundary, transactional invoice and collection paths, authoritative row locking around finalization and collections, delivery-line matching for standard invoices, posting-period checks on normal invoice and credit-note finalization, daily dunning with lock-based tier deduplication, durable delivery handoff behavior, and customer-ID scoping in the portal.

The server-side authority is nevertheless weaker than the UI and comments imply. Draft update does not re-read or lock the persisted lifecycle before replacing totals and items. Account selectors are advisory. Historical reports use current balances, correction paths are incomplete, and the same internal invoice resource is returned to customers. The score is capped below release-ready quality by the three P0 financial-integrity findings and the number of independent P1 controls needed before production confidence is reasonable.

## Blockers and high-value findings

### M028-F01 — P0, Broken: a stale draft update can mutate a finalized invoice

Evidence: `api/app/Modules/Accounting/Services/InvoiceService.php:154-158` checks the status on the caller's route-bound model. The transaction at `:160-191` does not lock or re-read the invoice and then updates totals and recreates all items. Finalization, by contrast, locks and re-reads the authoritative row at `:198-207` before posting the journal.

Impact: an update request that loaded a draft before another request finalized it can commit afterward, changing date, totals, BIR fields, remarks, and items while the posted invoice journal and source-chain state still reflect the previous values. This breaks the invoice/subledger/GL invariant.

Action: lock and re-read the invoice inside the update transaction, re-check `Draft`, and make the item replacement conditional on that authoritative state. Add stale-update-versus-finalize concurrency tests and verify that no finalized invoice or posted source journal is changed.

### M028-F02 — P0, Broken: server-side account classification and active-state checks are missing

Evidence: `StoreInvoiceRequest.php:48-54` and `StoreCollectionRequest.php:20-25` validate only opaque IDs and scalar fields. `InvoiceService.php:476-498` decodes any revenue account without checking `type` or `is_active`; `:360-364` decodes any cash account without checking that it is an active asset/cash/bank account. `CreditNoteService.php:111-124` likewise accepts any decoded account for credit-note lines. The SPA filters options, but those filters are not an API control.

Impact: a direct caller can route revenue, discount, VAT, cash, or credit-note lines through an inappropriate or inactive ledger account. The journal can remain mathematically balanced while its accounting meaning and auditability are wrong.

Action: enforce account existence, active state, and allowed classification in the request/service boundary for each posting path. Add direct API tests for wrong type, inactive, missing, soft-deleted, and valid account IDs, including credit-note lines.

### M028-F03 — P1, Missing: collection recording has no idempotency or duplicate-payment key

Evidence: `InvoiceService.php:339-373` creates a collection and journal entry after a balance check. The collection migration, `api/database/migrations/0050_create_collections_table.php:13-27`, has only a nullable `reference_number`; it has no request idempotency key and no unique constraint tying a retry to one collection. A retry for an amount smaller than the remaining balance can therefore create a second valid collection.

Impact: network retries, client re-submits, or worker replay can double-record a real payment, send duplicate downstream notifications, and make the payment trail difficult to reconcile even though the overpayment guard remains intact.

Action: define an idempotency key or controlled external reference contract, enforce it under the invoice lock and database uniqueness, and make replay return the original collection. Add repeated-request and concurrent-request tests.

### M028-F04 — P1, Incomplete: prebill approval is not separated from prebill creation

Evidence: `InvoiceService.php:95-100` requires the `accounting.invoices.prebill_approve` permission, then `:110-125` records the same `$by` as both creator and `prebill_approved_by` at creation time. `RolePermissionSeeder.php:172-179` defines the permission, and the finance officer receives the full accounting module at `:494-504`; there is no distinct approval state, checker, or different-actor requirement in this path.

Impact: if “approve prebill” is intended to be an exception approval, the maker can self-approve a receivable before delivery and can also bypass the sales-order credit-limit checkpoint. If one-step prebilling is intentional, the policy and compensating review must be explicit rather than implied by a permission named “approve.”

Action: decide and document the policy. Prefer a separate submit/approve transition with a distinct authorized checker, immutable reason/evidence, and an explicit credit-exposure rule. Add same-actor rejection and authorized-checker tests; if one-step approval is retained, add an auditable compensating-control test and documentation.

### M028-F05 — P1, Broken: invoice customer is not bound to the sales order and delivery customer

Evidence: `InvoiceService.php:101-103` resolves the customer independently from the request, while `:116-121` stores independently supplied sales-order and delivery IDs. Standard finalization verifies the delivery belongs to the selected sales order at `:504-510` and matches every delivery line at `:512-529`, but it never verifies that the sales order/delivery customer equals the invoice customer. The source columns are also raw nullable IDs without foreign keys in `api/database/migrations/0048_create_invoices_table.php:17-20`.

Impact: a caller with invoice-create access can combine Customer A with Customer B's delivered order, posting AR to the wrong party while passing the quantity and price checks.

Action: load and lock the source chain, assert one customer identity across invoice, sales order, and delivery, add database foreign keys where feasible, and test every mismatch through manual and delivery-handoff paths.

### M028-F06 — P1, Broken: invoice cancellation can reverse a journal without a posting-period guard

Evidence: `InvoiceService.php:308-335` calls `JournalEntryService::reverse` for a posted invoice. `JournalEntryService.php:321-350` locks the original but defaults the reversal date to `now()` and creates the reversal as Posted without calling `AccountingPeriodService::assertPostingAllowed`; normal invoice finalization does call the period guard at `InvoiceService.php:209-210`.

Impact: cancelling a historical invoice can post a reversal into today or into a closed period, bypassing the normal accounting-period control and obscuring the period in which the commercial correction occurred.

Action: require an explicit controlled reversal date, assert the target period is open, and define the approved cross-period correction path. Add same-period, closed-period, and cross-period cancellation tests with journal/date assertions.

### M028-F07 — P1, Broken: cancellation leaves the order-to-cash chain at terminal `invoiced`

Evidence: finalization promotes the parent SO at `InvoiceService.php:281-287`. The sales-order transition table makes `invoiced` terminal at `api/app/Modules/CRM/Services/SalesOrderService.php:40-48`, and `InvoiceService::cancel` at `:324-335` reverses the invoice and marks it cancelled without reconciling or demoting the parent SO/delivery handoff.

Impact: the commercial chain can say “invoiced” after the only invoice is cancelled, while the delivery remains consumed. Reviewers and re-invoicing logic can see an apparently completed chain with no valid receivable.

Action: define cancellation semantics for the chain: either create a replacement/reissue workflow with explicit provenance or add an authorized reconciliation transition and delivery allocation release. Test cancel-before-collection, reissue, replay, and portal chain states.

### M028-F08 — P1, Broken: AR aging is not historically correct

Evidence: `InvoiceService.php:410-417` selects current `Finalized`/`Partial` invoices without constraining invoice date to `$asOf` and loads the entire result set. `:422-427` buckets each current `balance`. A payment after the requested date has already reduced that balance, and future-dated invoices are included. The controller accepts raw `as_of` at `FinancialStatementController.php:88-105`, and both JSON and CSV use the same current-state service.

Impact: period-end AR aging can include future invoices and exclude receivables that were open at the selected date. It cannot reliably reconcile to the historical ledger or support audit/close reporting.

Action: define as-of semantics, filter postings by effective invoice/JE date, reconstruct balance from collections through the cutoff, represent cancellation/reversal events, and use bounded/aggregated queries. Add fixtures for future invoices, after-as-of collections, partial payments, cancellation, and timezone boundaries.

### M028-F09 — P1, Broken: customer statements and portal SOA inherit current-balance history errors

Evidence: `StatementOfAccountService.php:37-40` parses raw `as_of` with `Carbon::parse`. Invoice transactions at `:101-120` exclude any invoice that is currently Draft or Cancelled rather than reconstructing its state at the cutoff; collections at `:124-142` are date-filtered, but `computeAging` at `:165-171` uses each invoice's current balance. The customer and portal controllers pass the same unvalidated date through at `CustomerController.php:69-75` and `CustomerPortalController.php:234-246`.

Impact: a payment after the as-of date changes historical aging, and an invoice cancelled after the as-of date disappears instead of being represented by a dated reversal. Malformed dates can also become an exception path rather than a stable validation response.

Action: share a defined historical AR event model with aging, validate a strict date format/timezone, include posting and reversal events through the cutoff, and add hand-calculated internal/portal statement fixtures.

### M028-F10 — P0, Broken: credit-note application can settle a draft or otherwise unposted invoice

Evidence: `CreditNoteService.php:238-256` locks the target invoice and checks only customer identity and current balance. It does not require `Finalized` or `Partial`, and it then changes `amount_paid`, `balance`, and status without creating an invoice journal entry. The service comment at `:207-211` says the target is an open invoice, but the server-side state guard is absent.

Impact: a finalized credit note can turn a draft invoice into `Partial` or `Paid`, creating subledger payment state without the original AR posting. The same missing target-state guard exists in the supplier branch at `:264-280`.

Action: require a posted, eligible target document under lock, reject Draft/Cancelled/Paid targets as appropriate, and assert source/target journal invariants before changing balances. Add direct API tests for every invoice/bill lifecycle and concurrent applications.

### M028-F11 — P1, Missing: credit-note void/correction lifecycle is absent

Evidence: the credit-note migration defines `draft|finalized|applied|void` at `api/database/migrations/0268_create_credit_notes_tables.php:20-41`, but routes expose only list/show/store/finalize/apply at `api/app/Modules/Accounting/routes.php:55-62`. There is no void service or reversal route for a posted credit note.

Impact: an erroneous or fraudulent finalized credit note cannot be corrected through a first-class, period-controlled, auditable workflow. Staff are pushed toward manual ledger work or leaving an incorrect open credit.

Action: design an authorized void/reversal lifecycle with closed-period handling, application constraints, replacement linkage, and customer notification behavior. Coordinate the GL reversal contract with M026 journal-ledger.

### M028-F12 — P1, Missing: official receipts are not integrated with collections and are not unique per collection

Evidence: `OfficialReceiptService.php:21-48` implements `issueForCollection`, but `InvoiceService.php:365-406` records and posts a collection without invoking it; repository search found only the service definition and a direct `issueForInvoice` test use. The receipt migration indexes but does not uniquely constrain `collection_id` at `api/database/migrations/0208_create_official_receipts_table.php:19-32`; `issueForInvoice` at `:53-66` accepts an arbitrary amount and has no collection link or replay guard.

Impact: a recorded cash collection can exist without its expected BIR receipt, while manual/retry issuance can produce duplicate or amount-mismatched receipts. The invoice/collection/OR audit chain is incomplete.

Action: define whether OR issuance is synchronous or outbox-backed, issue exactly once per collection under a unique constraint/idempotency key, validate amount and period, and provide a controlled correction path. Add collection-to-OR integration, retry, and duplicate tests.

### M028-F13 — P1, Broken: customer portal exposes draft/cancelled invoices and reuses an internal resource/PDF boundary

Evidence: `CustomerPortalService.php:72-73` returns recent invoices without a lifecycle filter; `:145-162` lists and details every invoice owned by the customer without excluding Draft or Cancelled. `CustomerPortalController.php:101-120` returns the shared `InvoiceResource`, and `:140-147` sends the same invoice PDF after only an ownership check. `InvoiceResource.php:15-64` includes status, BIR buyer TIN/ATP/serial, remarks, journal metadata, collections, and internal relationship fields.

Impact: customers can see draft documents, cancelled documents, blank draft invoice numbers, internal remarks, and accounting/BIR fields that have not been deliberately defined as portal-visible. Tenant scoping is good, but status and serialization boundaries are not.

Action: expose only finalized/partial/paid documents (or an explicit customer-safe review state), split internal and portal resources/PDF templates, define field-level redaction, and add portal tests for draft/cancelled exclusion and response redaction.

### M028-F14 — P1, Incomplete: AR aging CSV is gated by view permission and raw date parsing

Evidence: the AR aging route at `api/app/Modules/Accounting/routes.php:117-120` applies only `accounting.statements.view` even for `?format=csv`, while `RolePermissionSeeder.php:183-185` defines `accounting.statements.export`. `FinancialStatementController.php:88-105` uses `Carbon::parse` on raw query input instead of a strict request contract.

Impact: a viewer may export a finance report without the separately defined export capability, and invalid/ambiguous dates do not have a stable 422 contract.

Action: authorize JSON view and CSV export separately, validate `as_of` as `Y-m-d` with an explicit timezone, and test both permission paths and malformed input.

### M028-F15 — P2, Broken: customer restore cannot reach a soft-deleted route-bound model

Evidence: `Customer.php:15-17` uses `SoftDeletes`; `routes.php:87-95` declares the restore route without `withTrashed`; `CustomerController.php:59-62` calls `restore`. The service and `CustomerResource` expose trash-aware behavior, but default route binding excludes the deleted customer before the controller runs.

Impact: the advertised restore API returns not-found for the normal soft-deleted target, leaving administrators with a lifecycle action they cannot complete.

Action: enable explicit trashed binding for restore, retain authorization, and test deleted, active, and missing targets. This is a contained same-session candidate but is deferred because the overall plan is dominated by separate financial work.

### M028-F16 — P2, Incomplete: customer list balances are absent while detail computes them

Evidence: `CustomerService.php:22-40` paginates customers without a credit-used aggregate; `:48-54` computes open balance only when explicitly called. `CustomerController.php:30-34` adds `credit_used` only for show, and `CustomerResource.php:19-43` returns null credit-used/available/warning fields when the attribute is absent.

Impact: the customer list and detail surfaces disagree about credit exposure, reducing triage value and potentially making a customer appear unutilized until opened individually.

Action: add a bounded aggregate or an explicit list contract, update the table UI to show the intended exposure, and cover pagination/filter performance. Keep the calculation consistent with finalized/partial invoice and credit-note semantics.

### M028-F17 — P2, Polish: collection serialization performs a journal lookup per row

Evidence: `api/app/Modules/Accounting/Resources/CollectionResource.php:25-27` calls `JournalEntry::find($this->journal_entry_id)` for every collection instead of using the relationship already available from the invoice/detail query.

Impact: invoice detail and portal payloads can issue one extra query per collection, degrading a common AR review surface as payment history grows.

Action: add the relationship/eager-load contract and a query-count regression test. This is a contained candidate but was deferred with the rest of the small polish work.

## Strengths observed

- Accounting routes use authentication, feature gating, and per-action permissions.
- Invoice finalization, collections, dunning claims, and credit-note finalization re-read authoritative rows under locks in their main transitions.
- Standard invoice finalization requires a confirmed delivery and exact source-line quantity/price coverage.
- Normal invoice and credit-note posting use accounting-period guards; the reversal gap is isolated to the generic reversal path.
- Delivery invoice handoff has durable outbox/recovery behavior and idempotent replay tests.
- Dunning is scheduled daily with `withoutOverlapping`/`onOneServer`, locks each invoice before selecting a tier, and queues email after the transaction boundary.
- Customer portal authentication and customer-ID ownership checks passed the focused auth/cross-guard tests.
- Internal AR screens generally follow the Atelier design system for dense tables, monetary formatting, loading/error/empty states, and report download affordances.

## Evidence checked

- Working tree, branch, recent commits, and release surface were inspected. HEAD was `b269eafd`; pre-existing dirty changes were in backup, notification, landing, Docker, and database-script areas. No M028 production source changes were made.
- Accounting/B2B routes, requests, controllers, services, models, resources, migrations, permission seeders, background schedule, internal SPA pages, portal pages, and the design-system guidance were read.
- Focused Docker test run: 65 passed, 280 assertions.
- The dependency cycle was documented in `inventory.md`; dependencies were not modified.

## Evidence missing / follow-up validation

- Full browser/e2e validation of internal AR and customer-portal workflows.
- Historical AR aging and statement fixtures reconciled to journal/collection event dates.
- Adversarial authorization and concurrency tests for each P0/P1 finding.
- Deployed permission matrix, production database constraints, queue worker behavior, and rollback/restore drill evidence.

## Release decision

No production-code fixes were applied. The majority of the plan is financial state-machine integrity, authorization/separation-of-duties, period control, historical reporting, receipt integration, or cross-module/customer-boundary work. Applying the small restore, balance-list, and N+1 fixes alone would leave the primary risks unresolved. M028 is released as 📋 Plan Ready with the ordered action plan below. The refreshed registry currently shows M034 customer-complaints-8d as the first visible Not Started entry, but it is locked; the next session must rerun the registry, skip any locks, and claim the highest eligible dependency-safe module atomically.

---

# Re-audit — 2026-08-30

Status: 🔁 Needs Re-audit
Prior lock: orphaned 2026-08-25 (107h), RECLAIMED.

## What the crashed session left

**Case B — the work was committed, not lost.** The 2026-08-25 fix-log describes a
large "dirty worktree" implementation. That worktree was swept into
`167de85e chore: remaining uncommitted work from ~50 crashed audit sessions`
(2026-08-26 02:32). Every file that log claims exists and is tracked:
`PostingAccountResolver.php`, `StatementAsOfRequest.php`,
`CustomerPortalInvoiceResource.php`,
`2026_08_25_120000_harden_accounts_receivable_controls.php`,
`HistoricalReceivablesTest.php`. The tree is clean of AR changes at claim time.
So this pass is verification, not recovery.

**Prior fixes verified by probe, not by reading the log.** Of the 13 findings the
2026-08-25 session claimed fixed, these were re-measured as holding: F01 (stale
draft update refused), F02 (account classification), F03 (collection replay with
an idempotency key), F05 (source-chain party binding), F06 (cancellation period
guard — now fires), F08 (aging as-of), F10 (credit-note target state), F12
(OR issued per collection, unique), F13 (portal status + resource boundary), F14
(strict `as_of`, export gate), F15/F16/F17. Two previously-reported problems have
also gone away: `AgingReportTest` and `FinancialStatementBoundaryTest` — logged
across four sessions as a "known CSV-header expectation mismatch" — now pass.

**Open findings that still reproduce: 7 of the re-audit's 22, plus 4 new P0/P1
defects the prior passes did not reach.** F04, F07, F11 and the F08/F09
residuals remain open as previously described and are not restated below except
where new measurement changes them.

## Method

Every figure below was measured against real PostgreSQL rows on a dedicated
database (`ogami_test_ar`), never the shared `ogami_test`. GL control-account
balances are read from `journal_entry_lines` joined to posted, non-archived
`journal_entries`, resolving the AR code from settings
(`accounting.accounts.ar_code` = `1100`) rather than hardcoding it.

---

## BROKEN

### M028-F18 — P0: `credit_limit = 0.00` is a hard 500 on the customer list and detail — FIXED

`api/app/Modules/Accounting/Resources/CustomerResource.php:23-28`.
`$creditLimit = (string) ($this->credit_limit ?? '0')` then guards the ratio with
`$creditLimit !== '0'`. The model's `decimal:2` cast
(`api/app/Modules/Accounting/Models/Customer.php:32`) renders a stored `0.00` as
the string `'0.00'`, which is not `'0'` — so the guard passes and `:28` evaluates
`(float) $creditUsed / (float) '0.00'`.

Measured: `DivisionByZeroError: Division by zero`, both from the resource
directly and from `GET /api/v1/customers?per_page=5`.

`credit_limit = 0` is reachable and meaningful: `StoreCustomerRequest.php:26`
allows `min:0`, `CustomerImporter.php:48-58` stores a CSV `"0"`, and zero is the
documented *"no limit is enforced"* convention
(`api/app/Modules/CRM/Services/SalesOrderService.php:99`). `credit_used` is
attached on every list (`CustomerService.php:24-26`) and every show
(`CustomerController.php:33`), so one such customer with at least one invoice
took down the entire AR customer list.

### M028-F19 — P1: a credit note's header links another customer's invoice — DEFERRED

`api/app/Modules/Accounting/Services/CreditNoteService.php:132-135` decodes
`customer_id` and `invoice_id` independently; `assertParty()` checks only that a
party is present.

Measured: a credit note created with `customer_id = A` and `invoice_id` belonging
to `B` was **accepted**. Money cannot cross — `apply():243-245` re-checks the
party — but `CreditNoteResource.php:41` then serialises B's `invoice_number` onto
A's credit note, which is a cross-tenant metadata disclosure and a false audit
link.

The guard was written and **reverted** in this session. `ReturnRequestService::
creditNoteFor()` (`api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:1362-1364`)
forwards `$rma->customer_id` and `$rma->invoice_id` with no cross-check of its
own, and `tests/Feature/ReturnManagement/CustomerReturnRestockOnDisposeTest.php`
at `:135,:155,:205,:238` calls `$this->customer()` twice — and `customer()` at
`:57` is `Customer::create(...)`, minting a **new** row per call — so the fixture
builds an RMA whose invoice belongs to a different customer, and 3 of its tests
went red. Landing this needs a coordinated return-management change. The revert
is proven comment-only: stripping comment lines from
`git diff HEAD -- CreditNoteService.php` yields an empty diff.

### M028-F20 — P0: the statement of account reports three different receivable figures for the same rows — DEFERRED

One customer, one ₱1,000.00 invoice, one ₱200.00 cash collection, one ₱300.00
applied credit note. Ledger truth: `invoices.balance = ₱500.00`.

| as_of | `closing_balance` | `total_outstanding` | AR aging total |
|---|---|---|---|
| 2026-04-30 | **₱800.00** | **₱800.00** | **₱800.00** |
| 2026-08-30 (today) | **₱800.00** | ₱500.00 | ₱500.00 |

Two independent defects produce this:

**(a) `closing_balance` never subtracts a credit note — at any as-of.**
`StatementOfAccountService::buildTransactions()` (`:97-181`) emits only
`invoice`, `cancellation` and `payment` events. Measured
`txn_types=invoice,payment`. So the running ledger a customer reads is overstated
by every applied credit, permanently. Note this also means `closing_balance`
(₱800.00) and `total_outstanding` (₱500.00) contradict each other **inside one
response payload** — the header/lines divergence class.

**(b) every historical aging excludes credit applications entirely.**
`credit_note_applications` has **no business-date column** — only
`created_at` (`api/database/migrations/0268_create_credit_notes_tables.php:58-68`,
never altered). Both `StatementOfAccountService::computeAging():219` and
`InvoiceService::aging():491` filter it with `->where('created_at', '<=', $cutoff)`.
`created_at` is the wall-clock insert time, so **any as-of earlier than the moment
the row was written silently reverts to the pre-credit figure.** Measured
`credit_note_applications.created_at = 2026-08-30 07:56:14` against an as-of of
2026-04-30 — ₱300.00 of credit vanished. A month-end AR aging is correct when run
that month and wrong when re-run later for the same cutoff.

### M028-F21 — P0: two credit notes drive the GL AR control account negative — DEFERRED

`CreditNoteService::create()` validates line amounts `> 0` (`:119`) but never
compares the credit-note total to the invoice named in `invoice_id`, and nothing
prevents a second credit note for the same invoice.

Measured:
- a credit note of **₱99,999.00** was accepted against a **₱1,000.00** invoice;
- **two** ₱1,000.00 credit notes were finalized against one ₱1,000.00 invoice,
  leaving **GL AR control balance = −₱1,000.00**.

`apply()` caps each application at the invoice balance (`:255-257`), so the
*subledger* cannot go negative — but the GL moved at `finalize()`
(`buildGlLines():345` credits AR for the full total), which `apply()` never
revisits. A negative receivable in the general ledger, created entirely through
AR endpoints, with no guard and no void path to undo it (F11).

### M028-F22 — P0: a credit note charges 12% VAT against a VAT-exempt invoice — DEFERRED

`CreditNoteService.php:111`:
`$isVatable = (bool) ($data['is_vatable'] ?? $this->taxPolicy->isVatRegistered());`
The credit note never inherits the invoice's `vat_classification`, and
`StoreCreditNoteRequest.php:24` makes `is_vatable` optional.

Measured: invoice `vat_classification=vat_exempt`, `vat_amount=0.00`,
`total=1000.00` → credit note `is_vatable=true`, `vat_amount=120.00`,
`total=1120.00`. `buildGlLines():342-344` then debits VAT Output ₱120.00 that was
never collected, and the credit total exceeds the invoice total. BIR exposure.

### M028-F23 — P1: AR aging 500s once any customer with invoices is archived — FIXED

`InvoiceService::aging()` read `$inv->customer->hash_id`. `customers` soft-deletes
(`Customer.php:17`) while `invoices` does not, and `invoices.customer_id` is NOT
NULL behind a RESTRICT FK — so under the default scope the relation resolves to
`null`.

Measured: `ErrorException: Attempt to read property "hash_id" on null`, and
`GET /api/v1/accounting/statements/ar-aging` → 500. This is the CFO monthly
deliverable (`routes.php:130`) **and** the finance dashboard, which calls
`aging()` on every load. One archived customer took both down.

### M028-F24 — P1: `1e3`/`1e17` on any AR money field is a 500, and `1.999` was silently rounded up — FIXED

`StoreInvoiceRequest.php:52,54` (`items.*.quantity`, `items.*.unit_price`), `:41`
(`senior_pwd_discount`), `StoreCollectionRequest.php:23` (`amount`),
`StoreCreditNoteRequest.php:37` and `ApplyCreditNoteRequest.php:19` (`amount`)
all used bare `numeric` with no `max` and no `decimal:0,2`.

Measured through `POST /api/v1/invoices`:

| input | before | cause |
|---|---|---|
| `1.999` | **201**, stored `subtotal 2.00` | `Money::round2()` applied silently |
| `1e3` | **500** | `ValueError: bccomp(): Argument #1 ($num1) is not well-formed` |
| `1e17` | **500** | same |
| `99999999999999999.99` | **500** | `SQLSTATE[22003]` numeric field overflow |

This is the exact class `journal-ledger` closed on `StoreJournalEntryRequest`
hours earlier — but AR never reaches that FormRequest: `InvoiceService` and
`CreditNoteService` build their own GL lines and call
`JournalEntryService::create()` directly, so the hardening did not cover AR.

---

## MISSING

### M028-F25 — P1: no AR collection void/reversal path exists at all, while AP has one

Measured route inventory: the only AR collection route is
`POST api/v1/invoices/{invoice}/collections`. AP has
`POST /bills/{bill}/payments/{payment}/void`
(`api/app/Modules/Accounting/routes.php:90-91`, permission
`accounting.bills.void_payment`).

A mis-keyed or mis-applied customer payment cannot be reversed, and
`InvoiceService::cancel():331-333` refuses to cancel an invoice with any
`amount_paid` — so the invoice is permanently frozen carrying the wrong money.
**The brief's "payment reversed → balance restores exactly" invariant is not
merely failing; the path does not exist**, so it cannot be tested.

### M028-F26 — P1: no unapplied-credit and no multi-invoice payment concept; the GL and every AR report disagree

Measured with one finalized-but-unapplied ₱400.00 credit note:

| surface | figure |
|---|---|
| GL AR control (posted lines) | **₱600.00** |
| AR aging total | **₱1,000.00** |
| SOA `total_outstanding` | **₱1,000.00** |
| `credit_notes.balance` (unapplied) | ₱400.00 |

Nothing in AR surfaces `credit_notes.balance` in an aging or a statement, so the
control account and the subledger reports differ by the unapplied credit with no
reconciling line anywhere.

Separately, a collection is strictly per-invoice, so one customer cheque covering
three invoices must be entered as three unlinked collections, and there is no
customer-deposit / cash-on-account concept. The brief's "split payment where the
total does not match" invariant is therefore **N/A — the endpoint does not
exist.**

### M028-F27 — P1: `credit_note_applications` has no idempotency key, no unique constraint, no `amount > 0` CHECK and no `invoice_id` XOR `bill_id` CHECK

Created once at `0268_create_credit_notes_tables.php:58-68` and never altered;
the only index is a non-unique `credit_note_id` (`:67`). Double-apply prevention
is entirely the pessimistic lock at `CreditNoteService.php:224`. The sibling AR
hardening migration added exactly this class of guard for
`collections.idempotency_key` and `official_receipts.collection_id`
(`2026_08_25_120000:14,18`) but skipped this table.

Honest scope: **I could not produce a double-apply through the service — the lock
holds.** This is defence-in-depth against any other writer, not a reproduced
defect.

### M028-F28 — P1: collections have no dedupe unless the caller volunteers an idempotency key, and no caller does

Measured: two identical collections (same invoice, same ₱400.00, same date, same
method, same cash account) were both accepted → 2 rows, `amount_paid = 800.00`.
With `idempotency_key` supplied, the replay correctly returned the original → 1
row.

`StoreCollectionRequest.php:26` makes the key `nullable`, and no client sends one
(`spa/src/api/accounting/invoices.ts:28`,
`spa/src/pages/accounting/invoices/detail.tsx:90`). F03's fix is real but
**switched off in production use.**

### M028-F29 — P1: official receipts have no HTTP or serialization surface

An `OfficialReceipt` is created for every collection
(`InvoiceService.php:434`) with a unique `collection_id`
(`2026_08_25_120000:18`), and `InvoiceService.php:399,453` eager-load it — but
`CollectionResource.php:12-32` never serialises it, there is no route, and
`grep or_number spa/src` is empty. A statutory BIR document reaches the customer
only by email (`CustomerOfficialReceiptMail.php`), and the eager-load is wasted
work. `OfficialReceiptService::issueForInvoice():74-92` remains callable with an
arbitrary amount, no collection link, no replay guard and no period check — but
has no HTTP route, so it is reachable only from code.

### M028-F30 — P1: the internal statement of account is dead — no client, no UI, no test

`GET /api/v1/customers/{customer}/statement-of-account` (`routes.php:105-106`)
has no method on `customersApi` (`spa/src/api/accounting/customers.ts:9-22`) and
no entry point on `spa/src/pages/accounting/customers/detail.tsx` (which has
"New invoice" at `:124` and "Edit" at `:134`). Only the *portal* consumes an SOA
— so the F20 divergence above is currently visible to **customers but not to
finance.**

### M028-F31 — P2: dead surfaces in both directions

- `customersApi.delete` and `customersApi.restore`
  (`spa/src/api/accounting/customers.ts:18-21`) have zero call sites — the entire
  customer soft-delete lifecycle is unreachable from the UI, unlike
  journal-entries which wires both (`journal-entries/detail.tsx:60,69`).
- `invoicesApi.update` (`spa/src/api/accounting/invoices.ts:21-22`) has zero call
  sites and there is no invoice-edit route in `accountingRoutes.tsx:91-96`, so
  `PUT /invoices/{invoice}` and its `accounting.invoices.update` permission are
  exercised only by cancel.
- `spa/e2e/ux-hardening-visual.spec.ts:55` mocks `**/api/v1/accounting/invoices/*`,
  a path that does not exist (real: `/api/v1/invoices/{invoice}`) — a dead
  interceptor that never matches.
- No e2e coverage anywhere for collections recording, credit-note apply,
  `/customers` CRUD, `ar-aging`, or `b2b/customer/invoices*`.

---

## INCOMPLETE

### M028-F32 — P1: credit-note apply moves the AR subledger inside a closed period — QUESTION

Measured: with 2026-03 closed, `apply()` **succeeded**. It posts no GL entry by
design (`CreditNoteService.php:206-211`), so `assertPostingAllowed` is never
called — but it does change `invoices.amount_paid`, `balance` and `status`, i.e.
the AR subledger for a closed month, which then reconciles against a frozen GL.
Whether that is acceptable depends on whether the "no GL entry" design is meant
to exempt application from period control. **Needs a human decision.**

### M028-F33 — P1: invoice cancellation reverses at `now()`, not in the invoice's period

`InvoiceService::cancel():342-347` passes `now()` as the reversal date. Measured:
with the current month closed, cancelling an April invoice was refused —
`Accounting period 2026-08 is closed ... dated 2026-08-30`. So the period guard
*does* fire (an improvement on the original F06 report, which found none), but
the consequences are that (a) a historical invoice cannot be cancelled at all
while the current month is closed, and (b) when it is open, the commercial
correction lands in today's period rather than the invoice's.

This is the same reversal-date policy question `journal-ledger` left open. **Not
this module's decision** — recorded here only as the AR-side consequence.

### M028-F34 — P2: two different aging bucket contracts for the same receivables

`InvoiceService::aging()` yields five buckets
(`current/d1_30/d31_60/d61_90/d91_plus`); `StatementOfAccountService::
computeAging():247-253` yields four (`current/d30_days/d60_days/d90_plus`) where
the key `d90_plus` actually holds **61+ days**. The enum label is honest —
`StatementAgingBucket::Days90Plus => '61+ Days'` — but the key reads as 90+, and
a customer's portal statement cannot be tied bucket-by-bucket to the internal
report.

Measured: a 70-day-overdue ₱700.00 invoice sits in AR aging `d61_90` and in SOA
`d90_plus`. Totals reconcile (₱700.00 = ₱700.00).

### M028-F35 — P2: `opening_balance` and the transaction list describe different periods

`StatementOfAccountService.php:50` accumulates every transaction strictly before
the as-of **day** into `opening_balance`, while `transactions` (`:64`) lists every
transaction ever up to the as-of **date**. So
`opening_balance + Σ(listed transactions) ≠ closing_balance`: the statement shows
a one-day opening against a lifetime ledger.

### M028-F36 — P2: `invoice_items.quantity` is 2dp but `delivery_items.quantity` is 3dp, and the finalize check truncates while create rounds — QUESTION

`invoice_items.quantity` is `decimal(12,2)`
(`0049_create_invoice_items_table.php:19`); `delivery_items.quantity` is
`decimal(14,3)` (`0097_create_delivery_items_table.php:25`).
`InvoiceService::normalizeItems():593` applies `Money::round2()` (half-up), then
`assertInvoiceMatchesConfirmedDelivery():634` compares with
`bccomp(..., 2)` (which **truncates**).

Arithmetic proof (not an end-to-end chain run — labelled as such):

| delivered (3dp) | stored on invoice (2dp) | `bccomp` scale 2 | outcome |
|---|---|---|---|
| `1.005` | `1.01` | 1 | **MISMATCH — invoice cannot be finalized** |
| `1.015` | `1.02` | 1 | **MISMATCH — invoice cannot be finalized** |
| `1.004` | `1.00` | 0 | match, but the customer is billed for 1.00 |
| `2.500` | `2.50` | 0 | match |

So a `.xx5` delivered quantity produces a permanently un-finalizable standard
invoice, and a `.xx4` one silently under-bills. Needs a decision on whether
invoice lines should carry 3dp or deliveries should be constrained to 2dp.

---

## POLISH

### M028-F37 — float arithmetic on money

- `api/app/Modules/Accounting/Resources/CustomerResource.php:28` —
  `((float) $creditUsed / (float) $creditLimit)`. **FIXED** (this was also the
  F18 crash site); restated as `used >= limit × ratio` in exact peso arithmetic,
  so there is no division at all.
- `spa/src/pages/accounting/invoices/index.tsx:47` —
  `sum + Number(invoice.balance ?? 0)` for the displayed "Outstanding Balance"
  stat card. Scope is honestly disclosed (`helper="in current view"`), and an
  exact-cent helper already exists at
  `spa/src/pages/accounting/journal-entries/money.ts`. **Not fixed** — see the
  action plan for why the SPA was left untouched.
- `spa/src/pages/accounting/invoices/create.tsx:86-88` and
  `credit-notes/index.tsx:150-152` — float subtotal/VAT previews (the server
  recomputes; lower risk).
- `spa/src/pages/accounting/customers/form.tsx:53` —
  `Number(existing.credit_limit)` round-trips a money value through a JS float.

### M028-F38 — `/ar-aging` has no `/export` twin while `/ap-aging/export` exists

`routes.php:130-132`, and `spa/src/api/accounting/statements.ts:19-22` bypasses
the dedicated boundary for both by using `?format=csv`. **Not an access hole** —
`authorizeExport():178-183` gates `format=csv` on `accounting.statements.export`,
which is verified working (`FinancialStatementBoundaryTest` passes). Route
symmetry only.

### M028-F39 — `CustomerService::creditUsed():51-56` returns `(string) sum('balance')`

Yields `"0"` (not `"0.00"`) for a customer with no open invoices, while
`list()`'s `withSum` yields `null` — two different "no exposure" representations
feeding the same resource field.

### M028-F40 — stale docblock

`CustomerController.php:65-67` documents
`GET /api/v1/accounting/customers/{customer}/statement-of-account`; the real
route has no `accounting/` prefix (`routes.php:105`).

---

## Outside this module — reported, not touched

- **`api/app/Modules/CRM/routes.php:25`** —
  `PATCH /crm/customers/{customer}/restore` is missing `->withTrashed()`. Since
  `CustomerController@restore` (`CustomerController.php:59-63`) only ever acts on
  a soft-deleted row, the route **404s for every valid target**. Its Accounting
  twin (`routes.php:102-104`) and both CRM siblings (`:33-35`, `:43-45`) all have
  it. CRM's file.
- Customer CRUD is duplicated at `/api/v1/customers/*` (`feature:accounting`) and
  `/api/v1/crm/customers/*` (`feature:crm`) against the same controller, so
  disabling one feature flag does not close the write surface.
- `ReturnRequestService::store()` (`:92`, `:137`) accepts `invoice_id` and
  `customer_id` independently with no cross-check, and
  `CustomerReturnRestockOnDisposeTest` `:135,:155,:205,:238` builds an RMA whose
  invoice belongs to a different customer (see F19). Return-management's.
- Shared Accounting decision #12 (`created_by` discarded on source-linked
  entries, making maker-checker silently inert): the **AR consequence** is that
  *every* AR GL entry is source-linked (`reference_type` = `invoice` /
  `collection` / `credit_note`), so **no AR posting is ever maker-checker gated.**
  Reported; not decided here.
- `tests/Feature/Accounting/AccountsPayableHardeningTest` "supplier resource does
  not expose internal ap controls" fails at HEAD and is **not attributable to
  this session** — confirmed with `git diff --name-only HEAD` (this session
  touched no supplier resource). Third session to confirm it.
- `tests/Feature/Accounting/AccountingPeriodDuplicateRecoveryTest:39` still lacks
  the `RefreshDatabaseState::$migrated` reset. Accounting's; reported, not fixed.

---

## Invariants executed

| Invariant | Result | Probe |
|---|---|---|
| Overpayment refused | **HOLDS** | ₱1,500 on a ₱1,000 invoice → refused, balance unchanged at ₱1,000.00 |
| Payment on a settled invoice | **HOLDS** | ₱0.01 after full settlement → refused ("status is paid") |
| Split payment across invoices, total mismatched | **N/A — no such endpoint** | collections are strictly per-invoice; no on-account cash (F26) |
| Two concurrent payments, same invoice | **HOLDS** | two connections + `pcntl_fork`; loser blocked on the row lock, then refused with the recomputed balance. `sum(collections) == amount_paid`, balance never negative |
| Payment applied twice | **FAILS without a key** | two identical ₱400 collections both accepted → 2 rows (F28). With `idempotency_key`: 1 row |
| Payment reversed/voided restores exactly | **UNTESTABLE — path absent** | no AR collection void route exists (F25) |
| Balance == SQL-computed | **HOLDS** | 3 partial payments → stored ₱550.00 == `total − Σcollections − Σcredit_applications` |
| Soft-delete consistent across every aggregate | **N/A by schema — verified** | no AR document table has `deleted_at`; only `customers` does. Confirmed across all migrations |
| …but an archived customer breaks aging | **FAILED → FIXED** | `Attempt to read property "hash_id" on null` → 500 (F23) |
| Aging boundary exactness | **HOLDS** | 30→`d1_30`, 31→`d31_60`, 60→`d31_60`, 61→`d61_90`, 90→`d61_90`, 91→`d91_plus`; exactly one bucket at every edge |
| Aging buckets sum to total | **HOLDS** | asserted at all 8 boundary as-ofs |
| "as of" honoured | **HOLDS for collections, FAILS for credits** | a 2026-05-10 payment does not reduce a 2026-05-01 as-of. But credit applications are filtered on `created_at`, so any past as-of drops them (F20b) |
| VAT rounding | **HOLDS** | half-up; `total == subtotal + vat` at 0.04/0.05/1.04/33.33/1000.04 |
| VAT basis | on the total, not per line | 3×₱0.04 → VAT ₱0.01 (per-line would be ₱0.00). Defined and consistent |
| VAT-exempt / zero-rated handled | **FAILS on credit notes** | ₱120.00 VAT charged against a VAT-exempt invoice (F22) |
| Credit limit counts outstanding AR, inside the transaction | **partial** | `creditUsed` sums open-invoice balances (correct basis), but enforcement lives only in `SalesOrderService::checkCreditLimit` — a prebill invoice bypasses it entirely. Resource crashed at limit 0.00 (F18) |
| Posted-invoice immutability | **HOLDS** | update after finalize → "Only draft invoices can be edited"; total unchanged |
| Credit note balanced | **HOLDS** | finalize posts a balanced VAT-reversing entry |
| Credit note linked both ways | **FAILS** | header may name another customer's invoice (F19) |
| Credit note capped to the invoice | **FAILS** | ₱99,999 accepted against a ₱1,000 invoice (F21) |
| Credit note not issued twice | **FAILS** | two ₱1,000 credit notes on one ₱1,000 invoice → GL AR **−₱1,000.00** (F21) |
| Closed period — invoice finalize | **HOLDS** | `ClosedPeriodException` |
| Closed period — collection | **HOLDS** | refused on `collection_date`, not on today |
| Closed period — cancel/reversal | **HOLDS** (with F33 caveat) | refused; reversal date is `now()` |
| Closed period — credit-note finalize | **HOLDS** | `ClosedPeriodException` |
| Closed period — credit-note apply | **FAILS / question** | accepted; subledger moves in a closed month (F32) |
| Closed period — scheduled poster | **N/A** | `ArDunningService` writes no money; notification only |
| Portal cross-tenant — invoice detail | **HOLDS** | 403, no leak |
| Portal cross-tenant — invoice PDF | **HOLDS** | 404, no leak |
| Portal cross-tenant — invoice list | **HOLDS** | victim's invoice number absent |
| Portal cross-tenant — statement | **HOLDS** | victim's invoice number absent |
| Refusal bodies leak nothing | **HOLDS** | no invoice number, amount, or raw PK in any 403/404 body |
| HashIDs everywhere | **HOLDS** | every AR resource emits `hash_id`; no raw integer PK in any payload or error body |
| Money discipline | **1 backend float FIXED, 4 SPA floats reported** | F37 |
| `decimal(15,2)` on money columns | **HOLDS** | all 18 AR money columns compliant |

**Not tested, and why:** payment reversal (no path exists — F25); multi-invoice
payment allocation (no endpoint — F26); the end-to-end delivery→invoice chain for
F36 (proved arithmetically instead, and labelled as such); browser/e2e validation
of any AR screen (no Chromium run in this session — Lightpanda cannot measure
layout and the AR pages have no e2e coverage to run anyway); production
permission matrix and queue-worker behaviour.
