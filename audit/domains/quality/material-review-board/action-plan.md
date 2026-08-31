# M054 — material-review-board action plan

Date: 2026-08-25  
Status: 🔁 Needs Re-audit  
Overall recommendation: partial implementation completed; re-audit after the deferred policy and cross-module items are resolved.

This existing plan was resumed in a dedicated session. Contained fixes were implemented and regression-tested; the highest-risk work that changes ownership of held stock, Quality disposition policy, permissions, or supplier/accounting handoff remains deferred.

## Ordered fixes

### 1. M054-F001 — Enforce location and warehouse invariants at the service boundary

- Classification/severity: Broken, P1
- Scope: medium; location policy, request/service validation, active-zone filtering, tests
- Recommendation: separate session
- Require active locations, a quarantine zone for hold destinations, a good non-quarantine/non-scrap zone for rework/use-as-is targets, and the same warehouse for every movement. Validate source location activity and zone as well; do not rely on the SPA tree.
- Acceptance: forged API payloads for inactive, wrong-zone, missing-zone, or cross-warehouse locations fail before movement; the SPA lists only valid active choices; same-warehouse behavior remains covered.

### 2. M054-F002 — Make held stock exclusive to the MRB release path

- Classification/severity: Broken, P1
- Scope: large; StockMovement/TransferOrder/StockAdjustment coordination, ownership checks, migration/backfill if needed, concurrency tests
- Recommendation: separate session
- Decide whether every movement from a quarantine location must reference the owning held MRB. Enforce that generic transfer/adjustment paths cannot consume or move held stock, or add an explicit authorized exception with an MRB reconciliation event. Reconcile already-held rows before enabling the guard.
- Acceptance: generic transfer/adjustment from a held quarantine location is denied; only MRB release can change the held quantity; a concurrent release versus exception has deterministic locking and a visible audit trail.

### 3. M054-F003 — Add rework verification and use-as-is concession evidence

- Classification/severity: Broken, P1
- Scope: large; Quality state/inspection handoff, concession approval, evidence model, release policy, tests
- Recommendation: separate session
- Define the required gate for each disposition. Rework should link to a rework action/order and a passed reinspection before good-stock release; use-as-is should require an authorized concession/sign-off and any customer evidence required by policy. Do not collapse both into one transfer branch.
- Acceptance: an unverified rework or unsigned use-as-is release cannot move stock to a good zone; the MRB resource shows the evidence, actor, and result; repeat/retry behavior is idempotent.

### 4. M054-F004 — Complete supplier-return provenance and handoff

- Classification/severity: Incomplete, P1
- Scope: large; vendor/PO/GRN/bill lineage, purchasing notification/dispatch, accounting reconciliation, portal/UI contract
- Recommendation: separate session
- Decide whether M054 owns the supplier-return document or hands off to Purchasing. Persist the supplier/source receipt identity and authoritative quantity; create or link the return/credit process; keep the MRB in an explicit `pending_supplier_return`/manual state until the external handoff is acknowledged, if that is the policy.
- Acceptance: a returned MRB identifies supplier, source receipt, quantity, movement, purchasing owner, and accounting outcome; a failed notification/handoff is retryable and does not present `returned` as complete.

### 5. M054-F005 — Validate and expose authoritative Quality linkage

- Classification/severity: Broken, P1
- Scope: medium/large; item/product/entity/status/quantity validation, lookup endpoints, inspection UI/resource, tests
- Recommendation: separate session
- Require linked NCR/inspection records to match the held material and permitted lifecycle state, or make the manual/no-link exception explicit with a reason. Add an item/inspection-scoped lookup and show inspection number, stage, status, and NCR state on the MRB detail page.
- Acceptance: unrelated, passed/closed/cancelled, or quantity-inconsistent links fail with a clear 4xx; a valid failed inspection/NCR is selectable and visible end-to-end; no first-100/all-record lookup is required for correctness.

### 6. M054-F006 — Add idempotency to hold commands

- Classification/severity: Incomplete, P1
- Scope: medium; command key/fingerprint persistence, API contract, SPA retry behavior, concurrency tests
- Recommendation: separate session
- Accept a bounded idempotency key, fingerprint the normalized item/quantity/source/destination/evidence payload and actor, and return the original MRB for an identical replay. Reject reuse with a different payload while preserving legitimate separate holds under different keys.
- Acceptance: timeout/retry creates one MRB and one hold movement; concurrent identical requests converge; changed-payload key reuse is rejected; the audit record names the original command.

### 7. M054-F009 — Define the disposition segregation-of-duties matrix

- Classification/severity: Incomplete, P1
- Scope: medium; permission/RBAC policy, approval records, thresholds, UI action gates, negative tests
- Recommendation: separate session
- Confirm whether warehouse and QC may both release every disposition. If not, split hold/release permissions and require an independent approver for scrap, return-to-supplier, high-value rework, and use-as-is concessions. Keep the policy server-side and actor-specific.
- Acceptance: each disposition has an explicit actor/approval matrix; unauthorized and self-approval attempts fail; the MRB timeline records decision actor, approver, evidence, and final movement.

### 8. M054-F007 — Make MRB search and filtering real

- Classification/severity: Broken, P2
- Scope: small; controller validation, service query, search tests
- Recommendation: same-session-ok
- Validate `search`, status, item, and page size at the controller; implement ticket/item/NCR/location matching in the service and preserve URL filter state.
- Acceptance: searching an MRB number returns only matching records, empty results are explicit, malformed page sizes return 422 rather than an internal error, and the API/UI contract is covered.

### 9. M054-F008 — Align quantity precision and lookup recovery

- Classification/severity: Polish, P2
- Scope: small; shared decimal rule, form validation, paged/searchable item/NCR lookups, loading/error states
- Recommendation: same-session-ok
- Set one three-decimal rule in the form and API. Replace bounded raw selects with searchable/scoped lookups, or provide a clear recovery path when item/NCR/warehouse queries fail or exceed the page limit.
- Acceptance: the form rejects a fourth decimal before submission, every valid active item and relevant quality record is reachable, and lookup failures tell the operator what to retry.

## Execution status for the resumed plan

Implemented and targeted-verified in this session:

- F001: service-boundary active/zone/warehouse validation for source, quarantine, and release locations; active same-warehouse SPA choices; regression coverage for inactive and cross-warehouse payloads.
- F005: item-scoped failed-inspection and open/in-progress NCR lookup; item/status/quantity/event consistency checks; inspection/NCR status detail in the resource and SPA.
- F006: bounded `Idempotency-Key`, actor-scoped payload fingerprint, durable migration fields, replay-safe hold command, and SPA retry key reuse.
- F007: validated search/status/page filters and MRB/item/NCR/location search, with URL filter state passed to the filter bar.
- F008: three-decimal form validation, searchable item/quality lookups, active-location filtering, and lookup retry states.

Deferred for a real in-session constraint, not because of the original risk label:

- F002: the ownership guard crosses shared StockMovement, transfer-order, adjustment, and Return Management paths. RMA quarantine movements are a valid existing exception, but there is no authoritative policy for the exception/reconciliation boundary; changing those dependencies would violate this module-scoped session.
- F003: the repository has no authoritative MRB rework evidence, reinspection gate, or use-as-is concession approval schema. A human decision is required before adding a release gate or inventing evidence fields.
- F004: supplier/PO/GRN/bill provenance and Purchasing/Accounting handoff ownership are unspecified. A terminal returned state cannot be safely replaced or connected without that cross-module contract.
- F009: segregation-of-duties thresholds and approval ownership are unspecified, and the required RBAC/seeder changes are outside this module’s scope.

The module is released as 🔁 Needs Re-audit. Re-audit the implemented findings after the deferred policy decisions are made; do not mark the module verified while F002/F003/F004/F009 remain open.

## Definition of done for the next implementation tranche

- Held stock has one authoritative owner and cannot bypass MRB release through generic inventory mutations.
- Every release disposition has the required Quality, concession, supplier, and approval evidence before stock/GL changes.
- Locations, source quantities, and quality links are validated server-side and reflected in the UI.
- Replayed holds are idempotent, MRB search works, and the operator can recover from lookup/API failures.

---

# RE-AUDIT ACTION PLAN — 2026-09-01

Status: **📋 Plan Ready** · Overall recommendation: **hand off — no code fixed
this session, for a structural reason stated below.**

## Why nothing was fixed (the split, and its justification)

**100% of M054's runtime surface lives in `api/app/Modules/Inventory/`, which is
LIVE under another agent (`warehouse-stock-control`) this session and read-only
for me.** That covers `QuarantineService`, `MrbController`, all four MRB
FormRequests, `MaterialReviewRecordResource`, `MaterialReviewRecord`, `MrbStatus`,
`Inventory/routes.php`, and — for the most severe finding — `StockMovementService`.
`api/app/Modules/SupplyChain/` is live too. So the usual Step 6 arithmetic
(majority `same-session-ok` + small scope → fix) does not decide anything here:
there is no file in my lane to change.

Independently, 6 of 9 findings fall squarely in the briefing's *not-contained*
category — wiring the disposition→movement bridge, changing who may disposition,
and changing what a decision means are IATF-auditable design decisions. Those
would be `separate-recommended` even if I owned the files.

The three findings that *are* containment-shaped (R1 guard extension, R5
immutability trigger, R8 FK tightening) each harden an Inventory-owned artefact
while that agent is live; landing them now risks either a merge collision or a
migration-ordering collision with work that agent may be doing on the same
tables. They are sequenced first below so whoever owns Inventory next can take
them immediately.

One item (R9.4, the `USER-MANUAL.md` gap) is genuinely outside any live module.
It is still deferred: `docs/USER-MANUAL.md` is edited by many audit sessions on
this branch, and a new section is a poor trade against that collision risk for a
documentation-only gap.

## Ordered items

### 1. M054-R1 — Make quarantine stock exclusive to the MRB release path
- Broken, **P0**. Scope: **medium**. Session: **separate-recommended** (Inventory-owned).
- `StockMovementService::assertConsumableSource()` `:307-329` only guards
  `MaterialIssue` and `Delivery`. Extend it so **any** movement whose
  `fromLocationId` is in a Quarantine zone must carry an MRB release context;
  `Transfer`, `AdjustmentOut`, `Scrap` and `ReturnToVendor` currently pass.
- Must also handle the **stranded-MRB** half: an MRB left `held` over an empty
  quarantine location can never terminate (measured: `release()` throws
  `InsufficientStockException` forever). Needs either a reconciliation path or a
  `void`/`cancel` transition on `MrbStatus`, plus a backfill for existing stranded
  rows.
- Note the legitimate exception the prior session flagged: ReturnManagement's RMA
  flow also writes Quarantine/Scrap zones
  (`ReturnRequestService.php:687,1379,1649`), so the guard must allow an RMA
  context as well as an MRB one — this is why it is medium, not small.
- Acceptance: each of the four escaping movement types is denied from a
  quarantine location without an MRB/RMA context; every MRB can reach a terminal
  state; `mrb_holds` badge count cannot include an unterminable row.

### 2. M054-R5 — Make a terminal MRB immutable (observer + PostgreSQL trigger)
- Broken, P1. Scope: **small**. Session: **separate-recommended** (Inventory table).
- Follow the `journal-ledger` precedent: an Eloquent observer **plus** a `P0001`
  trigger. MRB is an easier target than NCR/CAPA because it has **no legitimate
  post-terminal writer** — unlike `NcrService::close()` (double write) and CAPA
  (writes to closed rows), which is the hazard `ncr-capa` documented. So a trigger
  keyed on `OLD.status IN ('released','scrapped','returned')` rejecting any UPDATE
  or DELETE is safe here. Verify against `hold()`'s two writes (`:266`, `:281`)
  and `release()`'s one (`:402`) — all occur while `OLD.status = 'held'`.
- **Migration naming:** this touches `material_review_records`, whose most recent
  dependency is the timestamp-named
  `2026_08_25_190000_add_mrb_hold_idempotency.php`. Per CLAUDE.md's dependency
  rule, use a `2026_MM_DD_HHMMSS_*` name dated after it, **not** `0479_` — every
  `0NNN_` runs before every `2026_*`.
- Acceptance: Eloquent update, property-set status downgrade, raw SQL rewrite and
  `delete()` on a terminal MRB all fail; `hold()` and `release()` still pass.

### 3. M054-R8 — `ncr_id` / `inspection_id` must be `restrictOnDelete`
- Broken, P2. Scope: **small**. Session: **separate-recommended** (Inventory table).
- `0262_…:24-27` uses `nullOnDelete()`, and neither `NonConformanceReport` nor
  `Inspection` uses `SoftDeletes`, so a hard delete silently erases an MRB's
  quality trace (measured: `ncr_id` → NULL, `GET /mrb/{id}` still 200). Match
  `item_id` / `source_location_id` / `held_by` on the same table, which are all
  `restrictOnDelete`. Same migration-naming rule as item 2.
- Acceptance: deleting a referenced NCR or inspection is refused; existing MRB
  rows already orphaned are enumerated for a human.

### 4. M054-R2 — Decide and enforce the disposition→movement bridge
- Broken, **P0**. Scope: **large**. Session: **separate-recommended** —
  **needs a human decision first** (Options A–D in `audit-report.md`).
- Blocking question: which mechanism owns the movement? Until that is answered,
  no code should be written. Whatever is chosen must include a **per-NCR quantity
  ledger** (measured: 3 MRBs summing 120.000 against `affected_quantity = 40`) and
  a rule that resolves `ncr.disposition` vs `mrb.disposition` disagreement
  (measured: `return_to_supplier` vs `use_as_is`, material into finished goods).
- Also needs a decision on the `product_id` (NCR) vs `item_id` (MRB) identity
  mismatch — they join only via `inspections.item_id`.
- Acceptance: one nonconformance cannot be over-dispositioned; the two
  disposition columns cannot disagree; a scrap and a return-to-supplier cannot
  both be executed against the same nonconformance.

### 5. M054-R3 — Decide whether MRB is a board, then enforce it
- Broken, P1. Scope: **medium**. Session: **separate-recommended** —
  **needs a human decision first.**
- Measured: one `qc_inspector` inspected, raised the NCR, held, and released
  `use_as_is` into good stock. Requires splitting `inventory.mrb.manage` into a
  hold permission and a disposition permission, and/or a second-signature row.
  Touches `RolePermissionSeeder` (shared RBAC) — do not change unilaterally.
- Acceptance: the actor who detected or caused the nonconformance cannot be the
  sole approver of its disposition; all three registry roles can still complete
  their part of the split.

### 6. M054-R4 — Rework reinspection + use-as-is concession evidence
- Broken, P1. Scope: **large**. Session: **separate-recommended** —
  **needs a human decision first** (question 4 in `audit-report.md`).
- Split the shared `Rework`/`UseAsIs` arm at `QuarantineService.php:331-356`.
  Rework should require a **passed** reinspection linked on release; use-as-is
  should require a recorded concession approval and customer sign-off, which the
  enum already claims (`NcrDisposition.php:12`) and the schema has no room for.

### 7. M054-R7 — Supplier-return provenance
- Incomplete, P1. Scope: **large**. Session: **separate-recommended**.
- Unchanged from prior F004. Add vendor / PO / GRN lineage or make the handoff
  boundary explicit so `returned` stops implying more than it proves. Note the
  adjacent fact found this re-audit: the RMA path is the only other
  `ReturnToVendor` producer and it is hard-capped at `quantity_accepted`
  (`ReturnRequestService.php:360,494`), so it cannot cover the MRB case either.

### 8. M054-R6 — A quarantine aging / escalation command
- Missing, P1. Scope: **medium**. Session: **separate-recommended**.
- There are **zero** MRB scheduled commands (measured). Add an aging report or
  escalation for holds exceeding a configured age, wired in
  `api/routes/console.php`. **Build it so it distinguishes "nothing to do" from
  "everything threw"** — non-zero exit and a distinct counter when work was
  attempted and failed. Do not wrap the failure-recorder in a swallowing
  `catch (Throwable)`; that is exactly how the 8D SLA ledger stayed dead for its
  whole life.

### 9. M054-R9 — Polish
- Polish, P2/P3. Scope: **small** each. Session: **separate-recommended**
  (items 1–3 are Inventory-owned).
1. Guard `MaterialReviewRecordResource:30-35` so a soft-deleted item still shows
   its code/name (via `withTrashed()` on the eager load), rather than `"item": null`
   on a lot that is physically in quarantine.
2. Distinguish "invalid hash id" from "missing field" in `ResolvesHashIds` so
   `?item_id=garbage` stops reporting *"The item id field is required."*
3. Decide whether accepting raw integer ids alongside hash ids
   (`HashIdFilter::decode`, measured to work in the test environment) is intended.
4. Add an MRB / quarantine section to `docs/USER-MANUAL.md` — currently **zero**
   mentions of "material review", "MRB" or "quarantine".

### 10. Test debt to land alongside any of the above
- Broken (coverage), P1. Scope: **medium**.
- Only **2 HTTP calls** exist to any MRB endpoint in the whole repo
  (`QuarantineMrbTest.php:418,424`); 5 of 6 endpoints are service-only. Add
  HTTP-level tests for `options`, `index` (with each filter), `quality-options`,
  `show` and `release`. This session probed all of them and found them healthy —
  the point is to keep them that way, since `goods-receiving` found a route that
  had 500'd for six days behind 52 green service-level tests.
- Also add regression tests for the 20-cell transition matrix and the 20-value
  quantity validation family, both of which pass today and are unguarded.

## Deferred with reason (this session)

Everything above. The reason is uniform and structural: **the module's code is
owned by a live agent and read-only for me**, and the majority of the work is
IATF-auditable design that the briefing explicitly excludes from same-session
fixing. Four questions for a human are recorded at the end of `audit-report.md`;
items 4, 5 and 6 should not be started before they are answered.
