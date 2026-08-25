# M043 — import-shipments-customs action plan

Date: 2026-08-25  
Status: 📋 Plan Ready  
Overall recommendation: separate implementation work; no production-code fixes in this audit session.

The focused shipment, document, PDF, concurrency, and typecheck suites are green, but the core customs and costing gaps are policy/integration work. Keep the fixes independently reviewable and preserve the module's private-file and hash-ID conventions.

## Ordered fixes

### 1. M043-F001/F002 — Define and enforce the PO → shipment → customs → GRN lifecycle

- Classification/severity: Broken, P1
- Scope: large; shipment creation gate, authoritative locked status policy, required customs evidence, transition tests, GRN linkage/handoff state, permissions and audit events
- Recommendation: separate session
- Define which PO states may create a shipment and whether a PO may have multiple shipment legs. Add the same locked PO-state rule to the API that the UI currently approximates with a `sent` filter. Before `customs → cleared` and `cleared → received`, enforce the approved document/evidence policy. Add a durable shipment-to-GRN handoff or an explicit multi-shipment reconciliation model; do not rely on an unqualified PO-only lookup.
- Acceptance: invalid/terminal POs are rejected server-side; the transition API refuses missing customs evidence; `received` creates or links the intended GRN handoff exactly once; partial/multiple shipments have an explicit receiving policy; stale transitions remain safe and auditable.

### 2. M043-F007 — Converge supplier-portal shipment updates and documents

- Classification/severity: Missing, P1
- Scope: medium/large; source-of-truth decision, PO/shipment projection, document import/linking, tenancy/security policy, duplicate handling, audit trail, supplier/internal tests
- Recommendation: separate session with the supplier-portal owner
- Choose whether the internal `Shipment` is the canonical operational row, whether supplier updates create a draft shipment, or whether the PO remains canonical until an ImpEx Officer claims/links a shipment. Project carrier/ETA/tracking and supplier document metadata into the internal record with provenance and vendor scoping, or make the manual reconciliation step explicit and visible. Keep supplier portal files private and idempotent.
- Acceptance: a supplier update has one visible internal destination; supplier B/L/CI/PL evidence is discoverable by the authorized ImpEx Officer without SQL/file copying; vendor isolation remains enforced; retries do not duplicate rows or files; the source and last actor/time are visible.

### 3. M043-F004 — Redesign landed-cost input, allocation, and accounting handoff

- Classification/severity: Broken, P1
- Scope: large; cost-entry API/UI, money arithmetic, manual line allocations, cent residual policy, persistence/read contract, GRN/unit-cost application, AP/bill evidence, recalculation and audit tests
- Recommendation: separate session with Accounting, Purchasing, and Inventory owners
- Define which costs are entered from invoices/clearance evidence, who may edit them, and whether landed cost affects GRN unit cost, weighted-average stock cost, AP accruals, or only an analysis report. Replace float arithmetic with the repository money convention, make manual allocation accept actual line amounts, reconcile every component to the source total, and load/serialize the allocations consistently. Add a safe recalculation policy after receipt or AP posting.
- Acceptance: an authorized user can enter all five cost components and choose a method; manual allocations are not silently equal-split; every allocation reconciles to source cents; refresh returns the same breakdown; receiving/valuation/AP show the agreed landed-cost treatment; duplicate/retry calculations are safe and audited.

### 4. M043-F003 — Repair the Incoterm and trade-document contract

- Classification/severity: Broken, P1
- Scope: medium; create/update request, service persistence, resource/type parity, PDF source-of-truth, migration/backfill review, contract tests
- Recommendation: separate session, can follow lifecycle policy
- Decide whether shipment Incoterm overrides the PO value or must be inherited/locked from the PO. Persist and expose that decision consistently, validate updates with the same date invariants as create, and render PDFs from the authoritative value. Backfill or flag existing shipments whose selected value was dropped.
- Acceptance: create/update/read/PDF all agree on Incoterm; an invalid ETA/ETD pair is rejected on every mutation path; legacy null/mismatch rows are reportable; the SPA type and form reflect the API contract.

### 5. M043-F006 — Make archive/restore and document-file retention recoverable

- Classification/severity: Broken, P1
- Scope: medium; active-state deletion guard, `withTrashed()` route binding, archived-list policy, parent/child retention, file cleanup/recovery, negative tests
- Recommendation: separate session
- Deny shipment archive after the agreed customs/receipt point, or require an audited cancellation/void workflow. Add `withTrashed()` to intended restore routes, make archived documents discoverable to the restore flow, and never delete a file while a restorable document row still depends on it. Define container/document behavior when a shipment is archived and test commit/rollback behavior.
- Acceptance: archive → archived list → restore succeeds for shipment, container, and document; active customs history cannot be silently destroyed; restored downloads work; failed transactions leave the correct file/row pair; terminal-state mutation policy is enforced server-side.

### 6. M043-F005 — Complete multi-container management in the shipment journey

- Classification/severity: Missing, P1
- Scope: medium; response relation/resource, SPA API client, detail/list management UI, validation/uniqueness policy, PDF refresh, permission tests
- Recommendation: separate session after the lifecycle/source-of-truth decision
- Add a typed container collection to shipment detail, expose list/create/update/archive/restore methods, and provide an authorized multi-container editor. Decide whether the legacy shipment-level `container_number` remains a summary or is retired; validate gross/net/volume and the container-number reuse policy consistently.
- Acceptance: an ImpEx Officer can maintain 2–5 containers from the SPA; shipment detail and generated PDFs show the same current records; read-only users cannot mutate; archived rows are intentional and recoverable; validation covers physical constraints.

### 7. M043-F008 — Enforce terminal-state document/version/date policy

- Classification/severity: Incomplete, P2
- Scope: small/medium after lifecycle policy; backend status guards, document replacement/version rules, ETA/ETD cross-field validation, duplicate/retry tests
- Recommendation: same-session-ok only after the lifecycle contract is approved
- Mirror terminal-state guards on the API, decide whether each document type is single-current, versioned, or append-only, and implement the chosen idempotency/version metadata. Reuse the create request's ETA-after-ETD rule on updates. Keep the UI behavior derived from the server policy.
- Acceptance: UI and API reject the same terminal mutations; duplicate uploads follow the documented version/idempotency rule; current/archived document state is visible; ETA cannot precede ETD on create or update.

## Session decision

No production-code implementation is authorized in this audit session. Six findings are separate-recommended and the only smaller validation work depends on the unresolved lifecycle/evidence policy. Do not combine customs gates, supplier convergence, landed-cost accounting, and container UI into a cosmetic patch.

## Definition of done for the next implementation tranche

- Invalid PO states cannot produce operational shipments, and the shipment-to-GRN handoff is durable and unambiguous.
- Customs clearance/receipt requires the agreed evidence and records the actor, time, and source documents.
- Supplier portal updates/documents converge on the internal record with provenance and tenant isolation.
- Landed costs are enterable, cent-reconciled, visible after refresh, and applied or explicitly excluded from GRN/AP valuation by policy.
- Incoterm, containers, documents, and PDFs share one authoritative contract.
- Archive/restore preserves recoverability and terminal records are not silently destroyed.
