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
