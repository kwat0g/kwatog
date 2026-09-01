# M058 — Traceability & PPAP action plan

**Plan date:** 2026-08-25  
**Status:** `📋 Plan Ready`  
**Basis:** Fresh audit after uncommitted source changes invalidated the prior report. The plan is ordered by dependency and risk. No production code was changed while building this plan.

The plan is intentionally conservative: the core items affect lot quantities, lifecycle transitions, permissions/evidence, or cross-module contracts. They are all `separate-recommended` except the final polish item. A first-pass session therefore hands off at `📋 Plan Ready`.

## 1. Make material issue lineage authoritative

**Findings:** M058-F001  
**Scope:** large  
**Session recommendation:** separate-recommended

- Define the authoritative relationship between a reservation/material issue, stock movement, GRN/material lot, and work-order material line.
- Change the normal work-order issue transaction to stamp the actual issued lot on the stock movement and material-issue record, then derive the work-order trace snapshot from those persisted facts.
- Validate manual lot input against available/reserved stock and the relevant item/warehouse; reject arbitrary lot stamping.
- Add a safe migration/backfill policy for existing JSON-only snapshots. Do not silently reinterpret historical data.
- Add transaction-level tests for single-lot, multi-lot, partial issue, and insufficient/invalid lot cases.

**Dependency note:** This requires Inventory and Production ownership decisions. M058 should not patch those modules opportunistically.

## 2. Bind shipment lots to validated delivery/QC/output allocations

**Findings:** M058-F002  
**Scope:** large  
**Session recommendation:** separate-recommended

- Replace free-form JSON work-order membership with a validated allocation contract, preserving per-work-order quantities and product identity.
- Make creation lock the delivery/Sales Order context and invoke the existing passed-inspection/output validation before recording a lot.
- Validate each work order against the delivery line, output product, accepted quantity, batch state, and remaining allocatable quantity.
- Reject duplicate work-order IDs, over-allocation, mismatched products, and repeated submissions; define idempotency behavior.
- Keep the shipment-lot record and allocation writes in one transaction and add concurrency tests.

**Dependency note:** This requires Supply Chain, Production, and Quality contract ownership. The existing DeliveryService helper is evidence to reuse, not permission to bypass module boundaries.

## 3. Define and enforce the PPAP level/evidence matrix

**Findings:** M058-F003, M058-F006  
**Scope:** large  
**Session recommendation:** separate-recommended

- Resolve the policy question whether PSW is included in the documented 18 elements or is an additional element.
- Define a versioned required-element matrix for each PPAP level, including when an element may be Not Applicable and what evidence is required.
- Create the complete element set at submission creation or through an explicit draft-generation action; do not make “at least one row” sufficient.
- Validate evidence ownership, type, size, storage location, and replacement/version semantics.
- Replace raw path input/output with private upload and authorized download/preview contracts; record access and changes.
- Add tests for every level, missing evidence, allowed N/A, rejected evidence, and supplier tenancy.

## 4. Make PPAP transitions immutable, auditable, and maker-checker safe

**Findings:** M058-F004  
**Scope:** large  
**Session recommendation:** separate-recommended

- Specify the legal transition graph: Draft → Submitted → UnderReview → Approved/Rejected, with explicit rework/revision behavior and Expired handling.
- Treat Approved as immutable/terminal except through a documented revision flow; prevent approved-child mutation and post-approval rejection.
- Require review before approval, prevent self-approval, and enforce distinct submitter/reviewer/approver identities.
- Put transition reads/writes under row locks and transactions; use the project’s `DomainException(message, 'CODE', httpStatus)` convention and an explicit state-machine transition contract.
- Add child-element audit logging or an equivalent immutable evidence history.
- Split permissions if the resolved SoD policy requires separate submit/review/approve capabilities; update the RolePermissionSeeder and route checks in the owning session.
- Add transition, race, permission, and audit-log tests.

## 5. Ship internal QC and supplier PPAP workflows

**Findings:** M058-F005, M058-F006  
**Scope:** large  
**Session recommendation:** separate-recommended

- Add internal SPA list/detail/create/edit/review/approve/reject screens for QC users, with role-appropriate actions and explicit terminal-state messaging.
- Add supplier portal list/detail/submit/evidence/revision surfaces constrained to the supplier tenant.
- Add typed API clients, route permission checks, loading/error/empty states, and protected evidence actions.
- Add the recall and shipment-lot actions to the appropriate operator surfaces only after their backend contracts are authoritative.
- Cover the role matrix: system administrator, QC inspector, supplier user, and unauthorized/readonly users.

## 6. Correct recall semantics and expose the operator workflow

**Findings:** M058-F007, M058-F005  
**Scope:** medium/large  
**Session recommendation:** separate-recommended

- Validate and normalize the search/recall identifier, define identifier namespace/collision behavior, and cap result size.
- Return an explicit distinction between “identifier exists but has no downstream consumption” and “identifier does not exist”.
- Calculate affected quantities from authoritative allocations rather than whole shipment-lot totals; preserve batch/material/lot provenance in each result.
- Define duplicate-material-lot and multiple-consumer behavior, then add API tests for each search leg and no-result/partial-result cases.
- Add the typed recall client, UI action, result summary, retry/error state, and audit-safe export/detail behavior only after the contract is stable.

## 7. Close PPAP source, gate, expiry, and reporting gaps

**Findings:** M058-F008  
**Scope:** medium  
**Session recommendation:** separate-recommended

- Decode and validate all hash IDs consistently on create and update; enforce vendor/item/product/PO relationships and reject invalid optional references instead of silently nulling them.
- Resolve and document fail-open versus fail-closed behavior for an absent PPAP when the purchasing gate is enabled; align the setting default, service behavior, and UI.
- Add a scheduled expiry command/job with observable execution, per-record audit behavior, and tests around timezone/boundary dates.
- Define reporting for pending, rejected, expiring, expired, and missing PPAPs by vendor/item/product.
- Add a route/API test matrix for gate state, source relationship mismatch, expiry, and permission failures.

## 8. Apply the small frontend polish fix and run the verification gate

**Findings:** M058-F009  
**Scope:** small  
**Session recommendation:** same-session-ok

- Add a visible label linked to the traceability search input while preserving the concise operator layout.
- Re-check keyboard focus, status text/color pairing, loading/error/empty states, and the documented density tokens after the workflow screens land.
- Run the focused M058 tests plus SPA typecheck. Record unrelated dependency failures separately rather than weakening the gate.

## Recommended execution order

1. Inventory/Production authoritative lineage.
2. Supply Chain shipment-lot allocation contract.
3. Quality PPAP level/evidence and lifecycle contract.
4. Evidence storage/access and internal/supplier UI.
5. Recall semantics and UI.
6. Gate, expiry, and reporting.
7. Frontend polish and complete verification.

Items 1–7 require a dedicated implementation session or coordinated module claims. The current audit session must not modify dependency modules, resolve the open PPAP policy questions by assumption, or mark the module Verified.

---

# M058 action plan — 2026-09-01 re-audit

**Basis:** 19 measured findings. 5 contained items were implemented this session
(commit `1b5dc582`); the rest are ordered below. The split is deliberate and justified:
everything fixed was a *missing guard refusing impossible input*, a *validation rule*, or a
*broken query returning rows it always intended to*. Everything deferred either changes what
a trace means, changes PPAP approval authority or supersession, or lives in a directory
another agent owns.

## DONE this session (contained)

| # | Finding | Scope | What |
|---|---|---|---|
| ✅ | R1 | small | `whereJsonContains` object→array at both forward-hop call sites |
| ✅ | R10 (evidence) | small | approved PPAP evidence frozen |
| ✅ | R3 | small | array query params 422 instead of 500 |
| ✅ | R14 (update) | small | update route speaks HashIDs; `ppap_level` validated |
| ✅ | R14 (create) | small | `Rule::enum`; optional refs refused not nulled |

## 1. Repair shipment-lot creation — it has never worked

**Findings:** R7 · **Scope:** small (the bug) / large (the contract) ·
**Session recommendation:** `separate-recommended` — **SupplyChain owner only**

- `ShipmentLotService.php:41` calls `WorkOrder::decodeHashId()`, which does not exist. Use
  `WorkOrder::tryDecodeHash()` and 422 on an unresolvable id.
- Then the contract work the 2026-08-25 plan already specified: per-work-order allocation
  rather than a bare JSON id list, invoke `DeliveryService`'s locked
  inspection/output/Sales-Order validation, reject duplicate ids and over-allocation, and
  define idempotency (the table has `unique(delivery_id)`, so a re-post is currently a
  unique violation waiting behind the 500).
- Until this lands, **no shipment lot can be created by any caller**, so the whole
  batch→customer leg is unreachable in production.

## 2. Make trace links refuse instead of silently erasing

**Findings:** R8, R5 · **Scope:** large · **Session recommendation:** `separate-recommended`
— **IATF-auditable; needs a human decision first**

Measured: 9 of 11 links silently erase. Ordered by blast radius:

1. `shipment_lots.customer_id` and `.product_id` are `nullOnDelete` — a deleted customer or
   archived product silently removes it from the trace. Decide: restrict, or soft-delete-aware.
2. `Delivery` uses `SoftDeletes`, so archiving one drops it from both `search` and
   `simulateRecall` while they still report success. The trace must either resolve trashed
   parents (`withTrashed()`) or refuse to answer.
3. `work_order_ids` / `material_lot_references` are unconstrained JSON with no FK, so a
   dangling id yields a partial answer with no flag (R5). Either add a real allocation table
   (folds into item 1) or have the service compare the resolved count against the stored
   list and mark the result partial.
4. `Product` / `Item` archive nulls the part and material identity out of an otherwise
   successful trace.
5. Depends on the open question: **is archival recoverable or permanent?** No restore route
   exists in this module today.

## 3. PPAP element matrix, evidence boundary, and a way to add an element at all

**Findings:** R9.1, R9.2, R16 · **Scope:** large · **Session recommendation:** `separate-recommended`
— blocked on open questions 1 and 2

- **There is no create-element route**, so the 18 AIAG elements are unreachable through the
  API, not merely unenforced. This is the first thing to build.
- Then the versioned level→required-element matrix, and enforcement at `submit()`/`approve()`.
- Replace the free-text `document_path` with a real private upload/download contract:
  server-side MIME from real bytes, random filename, storage outside the web root,
  permission-checked serving, and access logging. `B2B` already models the read side
  correctly (`SupplierPpapElementResource` emits `has_document`, not the path).
- Add `HasAuditLog` to `PpapElement` — it is the one child table an auditor will demand a
  history for and the only one without one.

## 4. PPAP lifecycle: terminality, supersession, and segregation of duties

**Findings:** R10 (reject-after-approve), R11, R13 · **Scope:** large ·
**Session recommendation:** `separate-recommended` — **IATF-auditable**

- Decide whether `approved` is terminal and how an approval is withdrawn (revision vs.
  reject). `PpapStatus::isTerminal()` currently excludes `Approved`, which is what allows
  the post-approval reject measured in R13.
- Prevent two conflicting active approvals for one vendor+item; `revision` exists in the
  schema and **no code ever writes it**.
- Require review before approval (`reviewed_by` is NULL on the happy path today) and
  prevent self-approval. This means splitting `quality.ppap.manage` into
  submit/review/approve and updating `RolePermissionSeeder` — a role-matrix change, hence
  a human decision.
- Decide whether `draft` + `reject` is an intentional "abandon draft" affordance.
- Walk the matrix again afterwards; 27 of 30 cells are already sound.

## 5. Age the expiry, and stop presenting an expired PPAP as current

**Findings:** R12 · **Scope:** medium · **Session recommendation:** `same-session-ok` for a
session that owns `routes/console.php`

- `expireOverdue()` has **0 callers**. Add an artisan command + a `Schedule::command` entry
  that distinguishes "nothing to expire" from "everything threw" and does not exit 0 on the
  latter.
- Replace the mass `->update()` with a per-row loop (or an explicit audit write) so the
  status change is attributable — the builder update fires no model events, so `HasAuditLog`
  records nothing today.
- Until then, every list keyed on `status = 'approved'` shows expired approvals labelled
  "Approved" while the PO gate correctly refuses them. Consider having the list/resource
  derive an effective status from `expires_at` so the screen and the control agree.

## 6. Ship the missing operator surfaces

**Findings:** R15 · **Scope:** large · **Session recommendation:** `separate-recommended`
— after items 1–4

- PPAP has **zero SPA presence**: no type, no api module, no page, no route, no nav entry,
  against 9 internal routes and 1 supplier route.
- `recall-simulation` has no client either.
- `spa/src/api/supply-chain/shipmentLots.ts` exists and **nothing imports it**; its
  `createForDelivery` points at the route from item 1.
- `docs/USER-MANUAL.md` has **0 mentions** of ppap / traceability / recall.

## 7. Identifier namespace and result semantics

**Findings:** R2, R4 · **Scope:** medium · **Session recommendation:** `separate-recommended`

- `grn_items.material_lot_number` is indexed but not unique; duplicates resolve first-match
  and silently drop the rest.
- `batch_number` and `lot_number` share one namespace with no prefix guard — with both set
  to the same string, batch wins and the shipment-lot leg is unreachable.
- `simulateRecall` cannot distinguish "unknown identifier" from "received but not yet
  consumed" (R2); those demand opposite operator actions.
- Cap result size and preserve per-hop provenance.

## 8. Give the seeders a trace to seed

**Findings:** R19 · **Scope:** medium · **Session recommendation:** `separate-recommended`
— **`database/seeders/` owner**

- No seeder creates work orders, sales orders or deliveries, so every trace input is empty
  after a green `migrate:fresh --seed`.
- `seedBatchNumbers()` must not report "Batch numbers already present." when the table is
  empty; distinguish "all already stamped" from "nothing to stamp".
- `seedShipmentLots()` would write `work_order_ids = []` if it ran with no work orders —
  a lot pointing at nothing. Guard it.

## Recommended execution order

1. Item 1 (shipment-lot creation) — nothing downstream is testable until it works.
2. Item 8 (seed data) — cheap, and everything after it becomes demonstrable.
3. Item 5 (expiry ageing) — contained, high signal.
4. Item 3 (elements + evidence boundary).
5. Item 4 (lifecycle + SoD) — needs the human decisions.
6. Item 2 (trace erasure) — needs the archival decision.
7. Item 7, then item 6 (UI last, once the contracts are stable).
