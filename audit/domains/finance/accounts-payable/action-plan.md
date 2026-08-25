# M027 — Accounts Payable action plan

Date: 2026-08-25  
Status: ✅ Verified  
Overall recommendation: all planned AP findings were implemented and verified; the module is ready for release

At the audit baseline, the focused suite passed (44 tests, 171 assertions), but
the highest-risk findings were financial-control and cross-module changes. The
ordered items below preserve that reviewable implementation sequence.

## 2026-08-25 execution update

This plan was resumed from `📋 Plan Ready` and executed in order. Items 1–15
are implemented in the current AP service/resource/request/UI surface and the
new AP hardening regression suite. The source-allocation decision for item 4
is one bill per accepted GRN, matching the existing accepted-GRN listener
workflow; changing to partial allocation would require a new allocation model.
Item 11 uses the existing journal reversal boundary and records the replacement
reference on the voided payment. The module is now `✅ Verified`.

## Ordered implementation plan

### 1. M027-F01 — Close the payment state machine

- Classification/severity: Broken, P0
- Scope: medium; accounting service and tests
- Recommendation: separate session
- Enforce Unpaid/Partial only, require a posted source journal, lock the bill and payment transition, and keep overpayment checks in the same transaction.
- Tests: direct API draft payment rejection, cancelled/paid rejection, missing source journal, concurrent payment, and balance/status invariants.

### 2. M027-F02 — Enforce account classification and active state

- Classification/severity: Broken, P0
- Scope: medium; request/service validation and tests
- Recommendation: separate session
- Require active expense accounts for bill lines and active asset/cash/bank accounts for payments. Do not rely on SPA filters.
- Tests: wrong type, inactive, missing, soft-deleted, and direct API IDs for both bill and payment paths.

### 3. M027-F03 — Add three-way override authorization and maker-checker separation

- Classification/severity: Broken, P0
- Scope: large; permissions, service, audit fields, journal boundary, UI, tests
- Recommendation: separate session
- Define a dedicated override permission, require a non-empty reason, require an independent checker, preserve evidence and timestamps, and remove the reference-type bypass for the relevant system entries.
- Tests: unauthorized create/post, same-actor rejection, authorized checker approval, missing reason/evidence, and audit serialization.

### 4. M027-F04 — Prevent duplicate source billing

- Classification/severity: Broken, P0
- Scope: large; schema, allocation model, matching, portal/listener paths, tests
- Recommendation: separate session
- Decide one-receipt/one-bill versus partial allocation semantics, persist consumed quantities or source allocations, enforce under lock, and cover all intake paths.
- Tests: repeated request, listener retry, portal retry, concurrent submissions, partial receipt allocation, and source migration/backfill.

### 5. M027-F05 — Bind bill, PO, and GRN to one vendor

- Classification/severity: Broken, P1
- Scope: medium; provenance service and UI validation
- Recommendation: separate session
- Assert PO.vendor_id = GRN.vendor_id = bill.vendor_id for stock provenance under lock. Document and separately authorize any legitimate exception.
- Tests: mismatch in each pair, manual API, portal, and accepted-GRN listener paths.

### 6. M027-F06 — Guard journal reversals by accounting period

- Classification/severity: Broken, P1
- Scope: medium; journal/accounting boundary
- Recommendation: separate session
- Require an explicit reversal date or approved system date and call AccountingPeriodService::assertPostingAllowed before posting the reversal.
- Tests: same-period cancellation, closed-period rejection, approved override, and cross-period audit trail.

### 7. M027-F07 — Make AP aging historically correct

- Classification/severity: Broken, P1
- Scope: large; query/service/report contract
- Recommendation: separate session
- Define as-of semantics, constrain bills by bill date, reconstruct payment balances through the as-of date, and bound/aggregate the query.
- Tests: future bill exclusion, after-as-of payment exclusion, partial balances, cancellation, timezone, CSV/JSON parity, and ledger reconciliation.

### 8. M027-F08 — Separate aging view/export authorization and validate dates

- Classification/severity: Incomplete, P1
- Scope: medium; request, route middleware, controller, tests
- Recommendation: separate session
- Require statements.view for JSON and statements.export for CSV; validate a strict as_of format and timezone with an explicit error contract.
- Tests: view-only JSON, view-only CSV rejection, export success, invalid dates, and permission regression.

### 9. M027-F09 — Surface internal provenance and exception evidence

- Classification/severity: Incomplete, P1
- Scope: medium; internal resource/UI/test contract
- Recommendation: separate session
- Add evidence type, owner, approver, timestamp, and provenance to an internal-only bill representation with appropriate redaction and empty/error states.
- Tests: authorized finance reviewer serialization, unauthorized serialization, missing evidence, and detail rendering.

### 10. M027-F10 — Split supplier and internal bill resources

- Classification/severity: Broken, P1
- Scope: medium; API resources, portal controller, tests, UI contract
- Recommendation: separate session
- Define supplier-visible status and review fields; do not reuse the internal resource for the portal. Confirm whether any match status or evidence is intentionally disclosed.
- Tests: supplier-role response redaction and internal-role response completeness.

### 11. M027-F11 — Add payment void/reversal/correction lifecycle

- Classification/severity: Missing, P1
- Scope: large; payment schema, journal, permission, UI, audit log
- Recommendation: separate session and coordinate with M026 journal-ledger
- Model void/reversal state and replacement linkage, enforce periods and separation of duties, and recalculate bill balances from the auditable payment chain.
- Tests: same-period void, closed-period correction, authorization, replacement payment, duplicate request, and ledger reconciliation.

### 12. M027-F12 — Make vendor restore reachable

- Classification/severity: Broken, P2
- Scope: small; route binding and tests
- Recommendation: eligible for a contained same-session fix, but deferred
- Enable trashed binding for restore, preserve authorization, and test deleted/not-deleted/not-found cases.

### 13. M027-F13 — Populate vendor list open balances

- Classification/severity: Incomplete, P2
- Scope: small/medium; query/resource/UI contract
- Recommendation: eligible for a contained same-session fix, but deferred
- Add a bounded open-balance aggregate or explicit zero and verify pagination and filters.

### 14. M027-F14 — Remove payment resource N+1

- Classification/severity: Polish, P2
- Scope: small; relationship eager-load and query-count test
- Recommendation: eligible for a contained same-session fix, but deferred

### 15. M027-F15 — Correct supplier invoice empty-state copy

- Classification/severity: Polish, P2
- Scope: small; SPA copy test/manual check
- Recommendation: eligible for a contained same-session fix, but deferred

## Session decision

No production-code implementation is authorized by this audit session because the majority of findings are separate-recommended and financial-control sensitive. A future implementation session may bundle F12–F15 only if it remains isolated from the P0/P1 accounting changes; the P0/P1 items should not be mixed into a cosmetic patch.

## Definition of done for the next implementation session

- Every P0 has a server-side negative test and an authorization/concurrency test where applicable.
- Source allocation, payment lifecycle, and reversal behavior reconcile to journal entries and bill balances.
- Export, internal-resource, and supplier-resource permissions are tested at the HTTP boundary.
- Historical aging is verified against hand-calculated fixtures at multiple as-of dates.
- Migration/backfill, rollback, and deployment order are documented before release.
