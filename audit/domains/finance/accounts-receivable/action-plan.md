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
