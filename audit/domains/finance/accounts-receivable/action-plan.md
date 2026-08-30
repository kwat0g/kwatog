# M028 — Accounts Receivable action plan

Date: 2026-08-24  
Status: 📋 Plan Ready  
Overall recommendation: separate implementation work; no production-code fixes in this audit session

The focused test suite passed (65 tests, 280 assertions), but the highest-risk findings are financial-integrity, authorization, period, historical-reporting, receipt, and customer-boundary changes. Work should be split into reviewable changes with migrations, concurrency tests, authorization tests, and ledger reconciliation.

## Ordered implementation plan

### 1. M028-F01 — Close the stale invoice-update race

- Classification/severity: Broken, P0
- Scope: medium; invoice service and tests
- Session recommendation: separate-recommended
- Lock and reload the invoice inside update, re-check Draft, and prevent item/totals replacement after finalization.
- Tests: stale update versus finalize, stale update versus cancel, item/totals invariants, and posted-JE immutability.

### 2. M028-F02 — Enforce account type and active state server-side

- Classification/severity: Broken, P0
- Scope: medium; request/service validation and tests
- Session recommendation: separate-recommended
- Require active revenue/contra-revenue accounts for invoice lines, active asset/cash/bank accounts for collections, and the correct revenue/expense classification for credit-note lines.
- Tests: wrong type, inactive, missing, soft-deleted, direct API IDs, and valid account paths.

### 3. M028-F10 — Protect credit-note application state

- Classification/severity: Broken, P0
- Scope: medium; credit-note service, target-document state machine, tests
- Session recommendation: separate-recommended
- Permit application only to posted eligible invoices/bills, lock source and target in a consistent order, and verify target journal/balance invariants before changing subledger state.
- Tests: draft, finalized, partial, paid, cancelled, missing-JE, over-application, and concurrent application cases.

### 4. M028-F03 — Add collection idempotency and replay semantics

- Classification/severity: Missing, P1
- Scope: medium; collection schema/service/API contract
- Session recommendation: separate-recommended
- Add a unique idempotency key or controlled external reference, return the original collection on replay, and keep uniqueness under the invoice lock.
- Tests: same request retry, changed payload with same key, concurrent requests, and remaining-balance invariants.

### 5. M028-F04 — Define prebill approval and credit-exposure separation of duties

- Classification/severity: Incomplete, P1
- Scope: medium/large; permissions, lifecycle state, audit evidence, UI, tests
- Session recommendation: separate-recommended
- Decide whether prebill is one-step or maker/checker. Prefer separate submit and approve transitions, a distinct checker, immutable reason/evidence, and an explicit credit-limit policy.
- Tests: same actor rejection, authorized checker success, missing reason, credit-limit behavior, and audit serialization.

### 6. M028-F05 — Bind the invoice party to the source chain

- Classification/severity: Broken, P1
- Scope: medium; invoice/provenance validation and schema review
- Session recommendation: separate-recommended
- Assert invoice customer = sales-order customer = delivery sales-order customer under lock. Add foreign keys for source IDs where safe and define any approved exception path.
- Tests: each pairwise mismatch through manual creation, delivery handoff, retry, and finalization.

### 7. M028-F06 — Guard reversal dates and periods

- Classification/severity: Broken, P1
- Scope: medium; journal/accounting-period boundary
- Session recommendation: separate-recommended (coordinate with M026 journal-ledger)
- Require a controlled reversal date and `assertPostingAllowed` before posting. Define the approved cross-period correction path.
- Tests: same-period cancel, closed-period rejection, authorized cross-period correction, and reversal audit trail.

### 8. M028-F07 — Reconcile cancellation with the order-to-cash chain

- Classification/severity: Broken, P1
- Scope: large; invoice, sales-order, delivery allocation, and UI workflow
- Session recommendation: separate-recommended (coordinate with CRM/supply-chain owners)
- Model replacement/reissue or an authorized chain reconciliation that does not leave an invoiced terminal SO without a valid invoice.
- Tests: cancel-before-collection, reissue, repeated cancel, delivery reuse/allocation, and portal chain state.

### 9. M028-F08 — Rebuild AR aging as-of semantics

- Classification/severity: Broken, P1
- Scope: large; query/service/report contract
- Session recommendation: separate-recommended
- Define effective posting dates, exclude future postings, reconstruct invoice balances from collections through the cutoff, represent reversals, and bound/aggregate the report query.
- Tests: future invoice, after-as-of payment, partial payment, cancellation/reversal, timezone, JSON/CSV parity, and ledger reconciliation.

### 10. M028-F09 — Make statements historically correct

- Classification/severity: Broken, P1
- Scope: large; shared SOA service, portal contract, date validation, tests
- Session recommendation: separate-recommended
- Use the same historical event model as aging, validate `as_of`, represent posting/reversal events, and ensure internal and portal statements agree at each cutoff.
- Tests: pre/post payment, post-cutoff cancellation, malformed date, opening/closing balance, and portal parity.

### 11. M028-F11 — Add credit-note void/reversal correction

- Classification/severity: Missing, P1
- Scope: large; credit-note schema, journal, permissions, UI, notifications
- Session recommendation: separate-recommended (coordinate with M026 journal-ledger)
- Implement authorized void/reversal, application restrictions, period handling, replacement linkage, and customer communication.
- Tests: unused/finalized/applied notes, closed-period correction, duplicate request, and GL/subledger reconciliation.

### 12. M028-F12 — Integrate official receipts with collections

- Classification/severity: Missing, P1
- Scope: medium/large; collection transaction, receipt schema, event/outbox, correction path
- Session recommendation: separate-recommended
- Decide synchronous versus outbox issuance, enforce one receipt per collection, validate amount/date/customer, and make retries idempotent.
- Tests: collection creates OR, retry returns same OR, duplicate rejection, amount mismatch, failed delivery, and correction/void behavior.

### 13. M028-F13 — Split customer-facing invoice resources and status policy

- Classification/severity: Broken, P1
- Scope: medium; portal service/controller/resource/PDF/UI/tests
- Session recommendation: separate-recommended
- Exclude drafts/cancelled invoices from customer views unless a documented customer-safe state exists. Use a dedicated portal resource and PDF field allowlist.
- Tests: draft/cancelled exclusion, own-customer scope, other-customer denial, BIR/remarks redaction, PDF behavior, and portal UI states.

### 14. M028-F14 — Separate aging view and export permissions

- Classification/severity: Incomplete, P1
- Scope: small/medium; route/request/controller/tests
- Session recommendation: separate-recommended
- Require statements.view for JSON and statements.export for CSV, with a strict `Y-m-d` request contract and explicit timezone/error response.
- Tests: view-only JSON, view-only CSV rejection, export success, malformed dates, and permission regression.

### 15. M028-F15 — Make customer restore reachable

- Classification/severity: Broken, P2
- Scope: small; route binding and tests
- Session recommendation: same-session-ok (deferred because the overall plan is separate-recommended)
- Enable trashed binding only for restore and test deleted, active, and missing targets.

### 16. M028-F16 — Populate customer-list credit exposure

- Classification/severity: Incomplete, P2
- Scope: small/medium; bounded aggregate, resource, SPA contract
- Session recommendation: same-session-ok (deferred because the overall plan is separate-recommended)
- Add a consistent credit-used/available contract to list responses and cover filters, pagination, and performance.

### 17. M028-F17 — Remove collection-resource N+1

- Classification/severity: Polish, P2
- Scope: small; relationship eager-load and query-count test
- Session recommendation: same-session-ok (deferred because the overall plan is separate-recommended)

## Session decision

No production-code implementation is authorized by this audit session because the majority of findings are separate-recommended and financial-control sensitive. A future implementation session may bundle F15–F17 only if it remains isolated from the P0/P1 accounting changes; the P0/P1 items should not be mixed into a cosmetic patch.

## Definition of done for the next implementation session

- Every P0 has a server-side negative test and an authorization/concurrency test where applicable.
- Invoice, collection, credit-note, receipt, and journal states reconcile under retry and correction paths.
- Historical aging and statements are verified against hand-calculated fixtures at multiple as-of dates.
- Portal resources/PDFs are explicitly allowlisted and tested for status and field redaction.
- Migration/backfill, rollback, deployment order, and cross-module coordination are documented before release.

---

# Ordered plan — re-audit 2026-08-30

Four items were fixed in-session (items 1–4 below, marked **DONE**). The rest are
ordered by money risk. `separate-recommended` dominates, and the reason is
consistent: almost every remaining item **changes a peso figure a customer is
shown or owed**, or needs a schema column plus a backfill decision, or cannot land
without a coordinated change in another module.

## DONE this session — `same-session-ok`

### 1. F18 — `credit_limit = 0.00` is a 500 on the customer list · Scope: small · DONE
Deterministic crash; the fix refuses nothing and changes no peso figure — it only
stops dividing by zero. `credit_available` and `credit_warning` now correctly read
"no limit enforced", matching `SalesOrderService::checkCreditLimit`'s convention.

### 2. F23 — AR aging 500s on an archived customer · Scope: small · DONE
Eager-load the customer `withTrashed()` and null-coalesce the label. Containment:
the report previously **threw**, so there was no figure to change; the receivable
was already inside the bucket totals. Restores a report and the finance dashboard.

### 3. F24 — money fields accept `1e3`/`1e17` (500) and silently round `1.999` · Scope: small · DONE
Missing guards that refuse impossible input, judged on containment. Follows the
precedent `journal-ledger` set on `StoreJournalEntryRequest` hours earlier, for
the same column type. Turns three 500s into mapped 422s.

**Caveat to carry forward:** unlike the journal-entry form, the AR SPA forms use
`z.coerce.number()` with no precision refinement
(`invoices/create.tsx:31,33`, `invoices/detail.tsx:38`,
`credit-notes/index.tsx:42`, `credit-notes/detail.tsx:40`), so a three-decimal
entry now surfaces as a server 422 rather than being caught client-side. See
item 12.

### 4. F37 (backend half) — float division on money · Scope: small · DONE
Folded into item 1: `used/limit >= ratio` restated as `used >= limit × ratio` in
exact peso arithmetic, so there is no division at all.

---

## Written and reverted — needs a coordinated change

### 5. F19 — credit-note header links another customer's invoice · Scope: small (AR) + small (returns) · `separate-recommended`
The AR guard is written, correct, and **reverted**; the revert is proven
comment-only. It cannot land alone because
`ReturnRequestService::creditNoteFor()` forwards an RMA's `customer_id` and
`invoice_id` with no cross-check, and
`tests/Feature/ReturnManagement/CustomerReturnRestockOnDisposeTest.php`
`:135,:155,:205,:238` calls `$this->customer()` twice — `customer()` mints a new
row per call — so 3 of its tests go red on a genuinely inconsistent fixture.

Land as one change with return-management: fix the 4 fixture call sites to reuse
one customer, decide whether `ReturnRequestService::store()` should bind
`invoice_id` to `customer_id` itself, then restore the AR guard (the docblock at
`CreditNoteService::assertParty()` carries the exact code intent).

---

## P0 money correctness — `separate-recommended`

### 6. F20 — the statement of account reports three different receivable figures · Scope: large · `separate-recommended`
Two sub-items, both of which change a figure a customer reads:

**6a. `closing_balance` never subtracts a credit note.** Add credit-note and
credit-note-application events to `buildTransactions()`. This restates a
customer-facing running balance (measured ₱800.00 → ₱500.00), so it needs sign-off
before it ships, not a unilateral edit.

**6b. `credit_note_applications` has no business date.** Requires a migration
adding an application/effective date, a backfill policy for existing rows
(`created_at`? the credit note's `date`? the invoice's?), and then switching
`StatementOfAccountService::computeAging():219` and `InvoiceService::aging():491`
off `created_at`. Must be **timestamp-named and dated after
`2026_08_30_100000_guard_archived_journal_entry_posting.php`** if it touches
anything that migration created; otherwise the numeric prefix is fine — but
confirm with
`ls api/database/migrations | grep -E '^04' | sort | tail -3` first.

### 7. F21 — two credit notes drive GL AR negative · Scope: medium · `separate-recommended` + **QUESTION**
Measured **−₱1,000.00** in the AR control account. The guard is easy; the *rule* is
not, and the rule is a business decision:
- Is `credit_notes.invoice_id` a **binding** cap or a **reference**? It is
  nullable, and a volume rebate legitimately exceeds one invoice.
- Should the cap be "Σ non-void credit notes naming this invoice ≤ its total", and
  what makes a credit note "non-void" before F11 (void lifecycle) exists?

Sequence after F11: without a void path, a wrongly-capped or wrongly-issued credit
note cannot be corrected, so adding the cap first can strand real money.

### 8. F22 — credit note charges 12% VAT against a VAT-exempt invoice · Scope: small · `separate-recommended` + **QUESTION**
The narrow fix is contained: when `invoice_id` is present and `is_vatable` was not
explicitly supplied, inherit the invoice's `vat_classification` instead of the
company default. But it **changes the credited amount** (measured ₱1,120.00 →
₱1,000.00) and needs a decision on the fallback for credit notes with no
`invoice_id`, plus whether `zero_rated` behaves like `vat_exempt` here. Highest
value-per-line of everything deferred, and BIR-relevant.

### 9. F25 — no AR collection void/reversal path · Scope: medium · `separate-recommended`
Mirror `BillController::voidPayment` (`routes.php:90-91`) for collections: void
under the invoice lock, reverse the cash JE with an explicit controlled date,
restore `amount_paid`/`balance`/`status`, void the linked OR, and assert the
period. This creates money movement, so it is not a containment-only change.

### 10. F26 — no unapplied-credit visibility, no multi-invoice payment · Scope: large · `separate-recommended`
Measured GL AR ₱600.00 against AR aging ₱1,000.00. Needs a decision on whether
unapplied credits appear as a negative aging line, a separate "unapplied credits"
total, or a customer-deposit document. The multi-invoice allocation half is a new
document type.

---

## P1 defence-in-depth and reporting — `separate-recommended`

### 11. F27 — `credit_note_applications` has no constraints · Scope: small · `separate-recommended`
Unique/idempotency key, `amount > 0` CHECK, `invoice_id` XOR `bill_id` CHECK.
Judged on containment this is close to `same-session-ok` — it closes a race
without changing correct behaviour. It is deferred only because **no double-apply
could be reproduced** (the lock at `CreditNoteService.php:224` holds), so it is not
urgent, and because it is naturally one migration with item 6b.

### 12. F28 — collections dedupe is switched off in practice · Scope: small · `separate-recommended`
The server guard works; no client sends a key. Make the SPA mint one per submit
(`spa/src/pages/accounting/invoices/detail.tsx:90`) — and while in that file, add
the `.refine()` precision guard the F24 caveat calls for. SPA-only, but see the
note below on why no `.tsx` was touched this session.

### 13. F32 — credit-note apply inside a closed period · Scope: small · **QUESTION**
Measured accepted. Decide whether "no GL entry" exempts application from period
control. One line either way once decided.

### 14. F33 — cancellation reverses at `now()` · Scope: small · **BLOCKED, not ours**
The AR-side consequence of `journal-ledger`'s open reversal-date policy. Do not
decide here.

### 15. F36 — 3dp deliveries vs 2dp invoice lines · Scope: medium · **QUESTION**
A `.xx5` delivered quantity makes a standard invoice permanently un-finalizable;
a `.xx4` one silently under-bills. Decide whether `invoice_items.quantity` becomes
`decimal(12,3)` or deliveries are constrained to 2dp. Either way it is a migration
plus a change to the `bccomp` scale at
`InvoiceService::assertInvoiceMatchesConfirmedDelivery():634`.

### 16. F29 — official receipts have no HTTP or serialization surface · Scope: medium · `separate-recommended`
Serialise `or_number` on `CollectionResource`, add a read route and a PDF, and
either wire `issueForInvoice` to a controlled path or delete it. Includes the F12
residual (receipt void/correction policy).

### 17. F30 — the internal statement of account is dead · Scope: small · `separate-recommended`
Add `customersApi.statementOfAccount` and an entry point on the customer detail
page. **Sequence after item 6** — wiring finance up to a statement that overstates
by every applied credit would ship the F20 defect to a new audience.

### 18. F31 — dead client methods and a dead e2e interceptor · Scope: small · `separate-recommended`
Wire or remove `customersApi.delete`/`.restore` and `invoicesApi.update`; fix the
`**/api/v1/accounting/invoices/*` mock at
`spa/e2e/ux-hardening-visual.spec.ts:55`. Requires a product call on whether
customers should be archivable from the UI at all.

---

## Carried forward unchanged from the original plan

F04 (prebill maker/checker separation — **and note that a prebill invoice bypasses
the only credit-limit checkpoint entirely**), F07 (cancellation/reissue vs
terminal SalesOrder `invoiced`), F11 (credit-note void/reversal lifecycle — a
prerequisite for item 7), and the F08/F09 residual first-class posting/reversal
event model (now partly restated as item 6b, which is its concrete first step).

---

## Polish — `separate-recommended`, low value

19. F34 — reconcile the two aging bucket contracts (`d90_plus` holds 61+).
20. F35 — `opening_balance` describes one day against a lifetime ledger.
21. F37 (SPA half) — 4 float-money sites.
22. F38 — add `/ar-aging/export` for symmetry with `/ap-aging/export`.
23. F39 — `creditUsed()` returns `"0"` where `withSum` returns `null`.
24. F40 — stale docblock on `CustomerController::statementOfAccount`.

**Why no `.tsx` was touched this session.** The `PostToolUse` Prettier hook on
`Edit|Write` has been measured reformatting SPA files far beyond the edit (605
changed lines for a 5-line edit) and even files it was not pointed at. Every
remaining SPA item here is cosmetic or a client-side convenience, and none of the
measured money defects live in the SPA — so the reformat risk was not worth
taking. Items 12, 17, 18 and 21 should be done together in one deliberate SPA
pass with `git diff --stat` checked after each edit.
