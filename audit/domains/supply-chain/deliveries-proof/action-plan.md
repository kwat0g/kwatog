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

---

# Action plan — re-audit 2026-09-01

Status after this session: **🔁 Needs Re-audit** (unchanged label, materially advanced).

Of 17 findings now on the board, **3 were closed in-session** as containment
(F011 cancelled-order gate, F004's delete race, F010's header) and **14 are gated**
behind a decision that is explicitly not an auditor's: whether a shipment may leave
uncertified, what quantity is invoiced, who may confirm, whether archive is
recoverable, and how money is allocated. The split is stated rather than smuggled:
the contained items refuse impossible input or close a race without changing correct
behaviour; nothing else does.

## Closed this session

| # | Finding | Why it was containment |
|---|---|---|
| ✅ | **F011** cancelled sales order still shipped | A missing guard refusing impossible input. Placed at `assertDeliveryQuantitiesAvailable()`, the single seam the manual and auto-draft paths share. No valid order changes behaviour. |
| ✅ | **F004a** last-proof delete race | A lock closing a race without changing correct behaviour. Reuses the row `confirm()` already serializes on. |
| ✅ | **F010** client filename forged `Content-Disposition` | Small, no behaviour change for well-formed names; the portal stream's existing RFC 6266 shape was copied. |
| ✅ | Inherited `CocAutoAttachOnConfirmTest` fixture | A fixture correction. The guard was not weakened. |

## Ordered remaining work

### 1. M044-F014 — Make a missing Certificate of Conformance visible

- Broken, **P0**. Scope: **medium**. Session: **separate-recommended**.
- Two options, and the choice is a human's:
  **(a) surface-only** — add `coc_handoff_status` / `_message` / `_at` columns
  mirroring `invoice_handoff_*`, notify QC, record a replayable outbox event, and add
  a `delivery_confirmed_without_coc` bottleneck. Confirm still succeeds. This is
  containment-shaped and could be same-session in a follow-up.
  **(b) fail closed** — refuse confirmation when a linked passed outgoing inspection
  cannot produce a certificate. Correct for IATF, but it changes whether a shipment
  may leave, so not an auditor's call.
- Do **not** simply widen the `catch`. The failure path must not swallow its own
  errors — CLAUDE.md's own rule, and the reason this was invisible.
- Acceptance: a delivery whose CoC cannot be issued is distinguishable from one whose
  CoC was issued, without reading a log; the state is durable and replayable; the
  process document matches the code.

### 2. M044-F013 — Gate the AR side of the proof-of-delivery contract

- Broken, **P0**. Owner: **accounts-receivable**. Scope: **small** in AR.
- `InvoiceService::resolveSourceChain()` must read `deliveries.status` and refuse
  anything but `confirmed`, and should bound invoice line quantities by the linked
  `delivery_items`. Hand off with the measured 201s.
- Acceptance: no invoice can be raised against a scheduled, in-transit or cancelled
  delivery from any path; the delivery→AR precondition is enforced on both sides.

### 3. M044-F016 — Reconcile the CoC evidence guard with the AQL acceptance number

- Broken, **P1**. Owner: **quality / inspections-certificates**. Scope: **small**.
- `failing > 0` should become `failing > accept_count || any critical failure`. Keep
  the other three rules. Compounds with F014, so sequence after or with it.
- Acceptance: a lot of 400 with one accepted non-critical defect gets its
  certificate; a lot with a critical failure or defects above `Ac` still cannot.

### 4. M044-F002 — Decide and seed the last-mile actors

- Missing, **P0 operational**. Scope: **large**. Session: **separate-recommended**.
- Name the operator for create/assign/status/proof and the actor for confirmation,
  then grant the minimum to seeded roles. `warehouse_staff` is the natural candidate
  for dispatch; the confirmer has no seeded home today. Decide whether the customer
  portal confirms (build it) or not (fix `docs/PROCESS-FLOWS.md:332`).
- Acceptance: every registry role completes its documented part with no throwaway
  test role; negative authorization tests per role; `CreateDeliveryDriverGateTest.php:119`
  can drop its workaround comment.

### 5. M044-F004b — Make confirmed evidence append-only

- Broken, **P1**. Scope: **medium**. Session: **separate-recommended**.
- Refuse post-confirmation proof `store()` and receipt replacement by default. If
  corrections are allowed, require a distinct permission, a reason, an actor and a
  replacement link. An auto-generated `coc` proof should never be operator-deletable
  or operator-replaceable at all. Add an observer **plus** a PostgreSQL `P0001`
  trigger — the `journal-ledger` shape — since `deliveries` has zero triggers today.
- Acceptance: no unapproved post-confirmation add/replace/delete; the certificate on
  file is provably the generated one.

### 6. M044-F003 — Choose a retention policy, then make archive/restore honest

- Broken, **P1**. Scope: **medium**. Session: **separate-recommended**.
- Decide true archive vs permanent delete **first**. For archive: retain the private
  files, cascade `deleted_at` to `delivery_proofs`, add `->withTrashed()` to the five
  restore routes that lack it, and expose archived rows. For permanent delete: remove
  the restore routes and the SPA's "can be restored later" copy.
- Adding `->withTrashed()` alone is a half-fix that resurrects metadata pointing at
  deleted bytes. Do not ship it on its own.
- Acceptance: archive → list archived → restore → download succeeds, or the API and
  UI both say deletion is permanent; missing-file and parent-deleted cases return
  explicit errors.

### 7. M044-F012 — Decrement finished goods when goods leave

- Missing, **P1**. Owner spans **warehouse-stock-control**. Scope: **large**.
- Emit a `StockMovementType::Delivery` movement on the delivered transition, inside
  the existing transaction, from the finished-goods location. The enum, GL
  classification, zone-consumability check and ABC bucket are already built for it.
- Acceptance: FG on-hand falls by the delivered quantity; the movement cannot drive
  stock negative; it is idempotent per delivery; voiding restores it exactly. All
  four are currently vacuous and become live with this change.

### 8. M044-F015 — Record what the customer actually accepted

- Missing, **P2**. Scope: **medium**. Session: **separate-recommended** (changes what
  is invoiced).
- Capture an accepted quantity per delivery line at confirmation, invoice that, and
  reconcile the SO ledger to it. Define the shortfall route (return, re-delivery, or
  order amendment).

### 9. M044-F007 — Durable idempotency on manual delivery creation

- Incomplete, **P1**. Scope: **medium**. Session: **separate-recommended**.
- Bounded `X-Idempotency-Key`, payload+actor fingerprint, persisted command/result,
  replay returns the original delivery, key reuse with a different payload is
  refused. Legitimate partial deliveries with distinct keys must remain possible.

### 10. M044-F005 — Reconcile narrow delivery reads with the proof contract

- Incomplete, **P2**. Scope: **medium**. Session: **separate-recommended after F002**.
- Either accept `supply_chain.deliveries.view` on the proof/receipt read routes, or
  hide those controls from the narrow role in the SPA. Sequence after the actor
  decision so the same matrix answers both.

### 11. M044-F006 — Complete the landed-cost money controls

- Broken, **P1**. Scope: **large**. Session: **separate-recommended**.
- Unchanged from the 2026-08-25 plan: authorized cost entry, decimal-string
  arithmetic instead of floats, real manual line allocation, residual-cent
  reconciliation, and a reachable UI.

### 12. M044-F008b — Lock shipment deletion against a terminal transition

- Incomplete, **P2**. Scope: **small**. Session: **separate-recommended**.
- Re-read and lock the shipment inside the transaction before the terminal-status
  check. The status-domain half of the old finding is void: `deliveries_status_check`
  already exists.

### 13. M044-F009 — Container surface parity

- Missing, **P2**. Scope: **medium**.
- Either build the container client and shipment-detail section, or remove the six
  routes and the process-flow expectation. The fleet half is now partly closed.

### 14. M044-F017 — Mass-assignment and enum hardening

- Polish, **P2**. Scope: **small but cross-module**.
- Remove `status` from `Delivery::$fillable` and route writes through
  `forceFill()`; add a `proof_type` domain check. Deferred here only because several
  tests in other modules mass-assign `Delivery` status, so it is not contained.

## Definition of done for the next tranche

- A confirmed delivery without a certificate is impossible, or loudly visible.
- The delivery→AR precondition is enforced on both sides of the contract.
- Every registry role completes its documented part of the last mile.
- Confirmed evidence is append-only, backed by a database trigger.
- Archive is demonstrably recoverable, or the product says it is permanent.
- Finished-goods stock falls when goods leave.
