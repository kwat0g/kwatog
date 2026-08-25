# M044 — deliveries-proof action plan

Date: 2026-08-25  
Status at original audit: 📋 Plan Ready  
Current session outcome: 🔁 Needs Re-audit  
Overall recommendation from original audit: separate implementation work; no production-code fixes were applied in that audit pass.

Progress: M044-F001 was implemented and verified during the resumed session. M044-F002 is the current human-decision gate; M044-F002–F010 remain pending.

The focused suite is green, but the highest-risk work changes actor policy, private-document retention, legal proof semantics, money allocation, or database invariants. Keep those changes reviewable and independently tested.

## Ordered fixes

### 1. M044-F001 — Align manual delivery creation with QC provenance

- Classification/severity: Broken, P1
- Scope: medium; API contract, passed-inspection lookup, SPA form, tests
- Recommendation: separate session
- Decide whether each line selects one passed outgoing inspection or whether the server may resolve one. Expose only inspections linked to the selected sales-order line, show remaining accepted quantity, and align request validation, UI decimal precision, and service errors.
- Acceptance: a manual delivery can be created from the SPA with a valid inspection; a mismatched/legacy/over-capacity inspection returns a clear 4xx; UI and API reject the same precision; auto-draft behavior remains idempotent.

### 2. M044-F002 — Define assignment and confirmation ownership

- Classification/severity: Missing, P1
- Scope: large; delivery assignment API/UI, seeded role grants, driver handoff, portal/internal confirmation policy
- Recommendation: separate session
- Choose the operational owner for assignment, status/proof upload, and confirmation. Add a guarded assignment/update path or an explicit workflow event; grant the minimum permissions to named seeded roles; decide whether customer portal confirmation is required and implement it or correct the process contract.
- Acceptance: every auto-drafted delivery can be assigned to a vehicle/driver by an authorized operator; assigned drivers see it in the PWA; warehouse/ImpEx can perform their intended actions; confirmation has one documented actor boundary and negative authorization tests.

### 3. M044-F003 — Make archive/restore genuinely recoverable

- Classification/severity: Broken, P1
- Scope: medium; route binding, file-retention policy, restore services, API/UI tests
- Recommendation: separate session
- Decide between true archive and permanent delete. For archive, bind restores with trashed models, retain private files, expose archived rows intentionally, and restore only compatible parent/child records. For permanent delete, remove the restore routes and UI language. Apply the same policy to shipment documents, proofs, deliveries, and their files.
- Acceptance: archive→list archived→restore→download succeeds, or the API/UI clearly says deletion is permanent; missing-file and parent-deleted cases return explicit errors; permissions are tested.

### 4. M044-F004 — Enforce append-only confirmed proof and serialize deletion

- Classification/severity: Broken, P1
- Scope: medium/large; proof policy, service/controller transaction boundary, audit/correction model, tests
- Recommendation: separate session
- Make confirmed evidence immutable by default. If corrections are allowed, require a distinct permission, reason, actor, and replacement linkage. Lock the delivery row before counting/deleting proofs, and add a database or service invariant that a confirmed delivery retains at least one active proof.
- Acceptance: no unapproved post-confirmation add/replace/delete; two concurrent deletes cannot remove the final proof; confirmation and proof mutation have deterministic 4xx responses and audit evidence.

### 5. M044-F006 — Complete landed-cost money controls

- Classification/severity: Broken, P1
- Scope: large; cost-entry API/UI, decimal-string money arithmetic, allocation model, accounting/GRN consumers
- Recommendation: separate session
- Add an authorized cost-entry contract with validation for non-negative amounts, currency/period semantics, and recalculation after edits. Replace float arithmetic with decimal-string/BCMath or the shared Money policy. Define real manual line allocation input, validate that allocations reconcile per component, and allocate residual cents deterministically. Surface the result on shipment detail and downstream receiving/accounting contracts.
- Acceptance: every entered charge is traceable to an actor and source; line allocations sum exactly to each charge and the shipment total; `manual` never silently equal-splits; invalid/negative/incomplete inputs fail closed; accounting/GRN consumers use the authoritative allocation.

### 6. M044-F007 — Add durable idempotency to manual delivery commands

- Classification/severity: Incomplete, P1
- Scope: medium; command key/fingerprint persistence, controller/service, SPA, concurrency tests
- Recommendation: separate session
- Accept a bounded `X-Idempotency-Key` for manual creation, fingerprint the normalized payload and actor, persist the command/result, and return the original delivery for an identical replay while rejecting key reuse with a different payload. Keep quantity/inspection locks as a separate invariant.
- Acceptance: timeout/retry produces one delivery, same key with a changed payload is rejected, concurrent identical requests converge, and legitimate partial deliveries with different command keys remain possible.

### 7. M044-F008 — Add delivery status database guards and lock shipment deletion

- Classification/severity: Hardening, P1
- Scope: medium; migration/backfill, service transaction, tests
- Recommendation: separate session
- Preflight existing delivery values, add a migration-backed status domain check, and retain the service state-machine guard. Re-read and lock shipments before terminal-status checks/deletion; define behavior for a concurrent received transition and document/update metadata policy for terminal shipments.
- Acceptance: invalid delivery status writes fail at the database boundary; stale delete cannot archive a received shipment; migration preflight is fail-closed and rollback/reapply are documented.

### 8. M044-F005 — Reconcile narrow delivery reads with proof/receipt access

- Classification/severity: Incomplete, P2
- Scope: medium; authorization policy, route/UI gates, negative tests
- Recommendation: separate session after actor policy
- Decide whether `supply_chain.deliveries.view` includes proof metadata/files and receipt photos. Either accept the narrow permission on the intended read routes with redaction, or make the detail page omit those controls and add a dedicated proof-view permission. Preserve the warehouse restriction from shipments/fleet/customs.
- Acceptance: each role has a documented response matrix; no reachable SPA action returns an avoidable 403; proof URLs cannot be used outside the chosen boundary.

### 9. M044-F009 — Complete container and fleet surface parity

- Classification/severity: Missing, P2
- Scope: medium; resource eager-loading, SPA/API methods, fleet-driver coordination
- Recommendation: separate session
- Add container data to shipment detail and implement guarded container CRUD. Add fleet create/update/archive/restore controls or explicitly move fleet administration to M045 and remove misleading management routes/UI expectations from M044.
- Acceptance: the documented process flow can be completed from an authorized UI, or the ownership boundary is explicit and linked; list/detail/resource contracts include active and archived states.

### 10. M044-F010 — Make private download filenames standards-safe

- Classification/severity: Polish, P2
- Scope: small; shared response helper and stream tests
- Recommendation: same-session-ok after the lifecycle decisions
- Use a standards-compliant `Content-Disposition` builder with a sanitized fallback and UTF-8 filename parameter for internal and portal document/proof streams.
- Acceptance: quotes, control characters, long Unicode names, and missing names return a valid download response without header errors.

## Session decision

No production-code implementation is authorized in this audit session. The majority of findings are separate-recommended and several require policy, migration, or financial review. The module is released as 📋 Plan Ready; do not mix F001–F008 into a cosmetic patch.

## Definition of done for the next implementation tranche

- Manual creation, assignment, driver visibility, and confirmation are proven end-to-end for each seeded role.
- Archive/restore behavior is either demonstrably recoverable or explicitly permanent, including private files.
- Confirmed proof has a tested append-only/correction policy and concurrent deletion coverage.
- Landed-cost lines reconcile exactly to entered charges and downstream accounting/receiving consumers.
- Delivery replay, lifecycle status, permission, and restore negative tests run in CI.
