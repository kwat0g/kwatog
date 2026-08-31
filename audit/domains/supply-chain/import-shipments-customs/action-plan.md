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

---

# M043 — action plan, 2026-09-01 re-audit

The 2026-08-25 plan stands: the dominant work is policy, not code. All 8 of its findings
reproduce. This re-audit adds 8 more, and **four of them are genuinely contained** — they
turn 500s and dropped input into correct refusals without deciding any business question.

## Split, and why

Containment here means: a guard refusing impossible input, a validation rule, a
`withTrashed()`, a Content-Disposition fix, an eager-load that stops an endpoint 500ing.
It does **not** mean changing a landed-cost apportionment, a duty figure, whether goods may
clear without documents, or who may act. The seven items in tranche A change no successful
money outcome and no lifecycle policy; every item in tranche B does one or both.

## Tranche A — same-session-ok, small (DONE this session)

### A1. F101 — eager-load `shipment` so `calculate-landed-cost` stops 500ing
- Broken, P0. Scope: small. **same-session-ok**.
- `LandedCostService::calculate()` returns `load('landedCosts.purchaseOrderItem')` while
  `ShipmentLandedCostResource` reads `->shipment`. Add the relation.
- Acceptance: the endpoint returns 200 for 1/2/3-line shipments; `impex_officer` completes
  all 15 documented steps; no N+1 in production either.

### A2. F100 — `by_weight` refuses explicitly instead of `TypeError`-ing
- Broken, P0. Scope: small. **same-session-ok**.
- Correct the `getItemWeights()` return type, and because the weight basis column does not
  exist, refuse `by_weight` with a `BusinessRuleException` naming the reason rather than
  silently equal-splitting under a name that promises weight apportionment.
- This changes no successful outcome: there are none today. Choosing to *capture* item
  weights and apportion by them is tranche B.
- Acceptance: `by_weight` → a 422 that says why; the other three methods unchanged.

### A3. F103 — RFC 6266 Content-Disposition on document download
- Broken, P1. Scope: small. **same-session-ok**.
- Copy the shape `DeliveryProofController::contentDisposition()` established at `38663a81`,
  including its hex-escaped character class.
- Acceptance: a filename containing `"` yields exactly one quoted parameter.

### A4. F104 — validate the client filename length
- Broken, P1. Scope: small. **same-session-ok**.
- Acceptance: a 304-character filename → 422, not 500.

### A5. F105 — bound the container weight/volume rules
- Broken, P1. Scope: small. **same-session-ok**.
- `decimal:0,2` / `decimal:0,3` plus a `max:` inside the column precision, so `1e17`/`1e20`
  are refused instead of 500ing and `1.999` is refused instead of silently becoming `2.00`.
- Acceptance: the eleven probed values give 422 or an exactly-stored 201; no 500.

### A6. F106 — carry the ETA ≥ ETD rule onto `updateMeta`
- Incomplete, P1. Scope: small. **same-session-ok**.
- Acceptance: ETA before ETD → 422 on create *and* update.

### A7. F/prior-F006 — `withTrashed()` on the three import restore routes
- Broken, P1. Scope: small. **same-session-ok**.
- **Open question inherited from `deliveries-proof` (M044): is archive recoverable or
  permanent?** Adding `withTrashed()` makes an already-advertised route reach its only valid
  target; it does not decide that question. If the answer is "permanent", the correct fix is
  to delete these three routes instead — which is why the file-retention half (F/prior-F006,
  below) stays in tranche B.
- Acceptance: archive → restore succeeds for shipment, document and container.

## Tranche B — separate-recommended (NOT done; needs a human decision)

### B1. F102 — landed-cost apportionment: exact-sum, basis, and money type
- Broken, P0. Scope: large. **separate-recommended** (Accounting + Inventory owners).
- Replace float arithmetic with BCMath; apportion so `Σ` line allocations equals the charged
  total exactly, per component, with a stated residual rule (largest-remainder on the
  largest line is the usual choice); make `manual` accept real per-line amounts or remove it.
- Gated because it changes a money figure and needs the residual policy agreed.
- Acceptance: `Σ total_allocated == landed_cost_total` and `Σ allocated_<component> ==
  <component>` for 1..12 lines and for the five components, asserted with `bccomp`.

### B2. F102 family — a landed-cost input path, and whether it reaches inventory
- Broken, P0. Scope: large. **separate-recommended**.
- Nothing anywhere writes `freight_cost`/`insurance_cost`/`duties_amount`/`brokerage_fee`/
  `other_charges`; measured `0.00` after create, patch and calculate. So the feature computes
  zero for every real shipment and the commercial-invoice PDF's freight/duty rows can never
  render. Decide who enters these from clearance evidence, and whether the result adjusts GRN
  unit cost and weighted-average cost or is analysis-only. **Do B1 first** — wiring an
  unreconciled figure into WAC would corrupt a calculation `goods-receiving` verified exact.
- Also add `HasAuditLog` to `ShipmentLandedCost` as part of this: it is the only model in the
  module without it, and it is the money one.

### B3. Prior-F001 — which PO states may create a shipment
- Broken, P1. Scope: medium. **separate-recommended**.
- All 8 states are accepted today. `GrnService:103-121` allows exactly approved / sent /
  partially_received. Mirroring that set is one line, but *which* set is correct depends on
  the unanswered multi-leg question the prior session raised (may one PO have several
  shipments? may a `partially_received` PO open another leg?). Deferring for the same reason
  the prior session did, rather than guessing and silently blocking a real workflow.

### B4. Prior-F002 — the customs evidence gate and the shipment→GRN handoff
- Broken, P1. Scope: large. **separate-recommended**.
- Which of the nine document types are mandatory before `customs→cleared` and
  `cleared→received`; and a durable handoff with the shape `deliveries` already has
  (status column + event + notification + bottleneck), since today `received` emits nothing
  at all. Explicitly *not* a `catch (Throwable) → Log::warning`.

### B5. Prior-F006 (retention half) + F/prior-F008 — terminal-state immutability and file retention
- Broken, P1. Scope: medium. **separate-recommended**.
- Archiving a shipment destroys document files while leaving the rows active; a `cleared`
  customs record can be archived; a received shipment's metadata, containers and documents
  are all still mutable, and raw SQL/delete have no `pg_trigger` behind them. The
  `journal-ledger` precedent is an observer **plus** a PostgreSQL `P0001` trigger. Deciding
  what is frozen at `cleared` vs `received`, and whether archive is recoverable, is the
  business question A7 deliberately does not answer.

### B6. Prior-F005 + F107 — container and landed-cost UI, and the dead surfaces
- Missing, P1/P2. Scope: medium. **separate-recommended**.
- Six container routes, the calculate endpoint, shipment archive/restore and the
  `allocation_methods` option all have no client. Depends on B2 for what the cost panel
  should contain.

### B7. Prior-F003 (PDF half) — Incoterm source of truth
- Broken, P1. Scope: small-medium. **separate-recommended**.
- A1..A7 include persisting the submitted Incoterm (it is validated, exposed and dropped —
  that half is containment). Whether the PDFs should render the shipment value or inherit
  the PO's, and whether legacy nulls are backfilled, is the policy half.

### B8. Prior-F007 — supplier-portal convergence
- Missing, P1. Scope: medium-large. **separate-recommended** (with the supplier-portal owner).
- `supplier_shipments` is read only by `B2B` resources; the internal record never sees it.
