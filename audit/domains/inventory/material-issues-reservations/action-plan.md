# M042 — Material Issues & Reservations Action Plan

Session: 2026-08-25 re-audit  
Status: `📋 Plan Ready`  
Overall recommendation: `separate-recommended`; no production-code fixes were
applied while the physical-process decision remains unresolved.

## Ordered fixes

| Priority | Finding(s) | Work | Scope | Session recommendation | Acceptance evidence |
|---|---|---|---|---|---|
| P0 | M042-F01 | Obtain the process-owner decision: make create the final physical issue and remove “ready to pick” semantics, or add an explicit draft/pick/issue lifecycle with stock/GL posting at the physical transition. | large | separate-recommended | One authoritative lifecycle; no slip is both ledger-issued and waiting for an unrecorded pick; replay/cancel behavior is explicit. |
| P0 | M042-F02, F03 | In one transaction lock the work order, reservation, material line, item, location, and stock level; require an open work-order status, `reserved` reservation status, matching identities, and quantity coverage; release reservation quantity before movement and consume full/partial reservations deliberately. | large | separate-recommended | Full/partial reservation tests pass; unrelated, terminal, wrong-WO/item/location, over-quantity, and unavailable cases return 422 with no stock mutation. |
| P0 | M042-F04 | Define the ownership boundary between manual issue slips and production-start issues, then update work-order material quantity/cost/variance for every accepted manual issue and reversal without double counting. | large | separate-recommended | Stock, slip, reservation, work-order actuals, and GL reconcile for manual and start-driven paths. |
| P0 | M042-F05 | Keep the locked cancellation transition; restore/release linked reservations consistently, require a cancellation reason, persist actor/reason/timestamp provenance, and cover two-connection cancellation. | medium | separate-recommended | Exactly one reversal under a race; issued reservation state is reconciled; strict lazy loading and route cancellation pass; reason/actor are auditable. |
| P0 | M042-F06 | Add a durable unique idempotency key and payload fingerprint to issue creation; return the original slip for same-key replay and reject same-key/different-payload reuse; send the key from the SPA. | medium | separate-recommended | Same-key replay creates one slip/movement/GL effect; different key creates a new issue; altered payload returns a business 422. |
| P1 | M042-F07 | Use `Money`/movement totals for line and slip aggregation with canonical half-up rounding. | medium | separate-recommended | Four-decimal WAC boundaries and multi-line totals match movement and GL values exactly. |
| P1 | M042-F08 | Add preflighted foreign keys and positive/nonnegative database checks for work-order, reservation, quantity, and cost fields. | medium | separate-recommended | Existing-data inventory is clean before migration; orphan/zero/negative writes are rejected on supported DBs. |
| P1 | M042-F09 | Centralize the usable-source invariant for active items, active/unblocked locations, and non-quarantine/non-scrap zones; apply it to issue service, movement boundary, request/options, and UI choices. | medium | separate-recommended | Inactive, soft-deleted, blocked, held, scrapped, and inactive-item cases are rejected consistently. |
| P1 | M042-F10 | Return hashed work-order/item/location/line identifiers plus work-order number/summary; align API resources, picking payloads, and TypeScript types/rendering. | small | separate-recommended | No raw internal IDs in issue/picking responses; list/detail/picking links render the business identities. |
| P1 | M042-F11 | Add reservation options and detail linkage, hash-decode reservation IDs, validate lot/UOM against master data, and align client/server three-decimal quantity rules. | large | separate-recommended | Warehouse can select an open reservation, submit lot/UOM through HTTP, and see traceability on detail; precision errors agree client/server. |
| P1 | M042-F12 | Protect the picking API and SPA route with `inventory.picking.view`, and add allowed/denied role tests. | small | separate-recommended | Warehouse/system-admin allowed; inventory-view-only users receive 403 and cannot fetch picking data. |
| P1 | M042-F13 | Resolve the lot policy. If lot accuracy is required, implement lot-level quantity/expiry accounting; otherwise label FEFO as a location heuristic and prevent exact lot claims after untagged issues. | medium | separate-recommended | Suggestions never direct operators to a depleted/expired lot as authoritative, or the UI clearly communicates the heuristic. |
| P1 | M042-F14 | Add permission-aware cancellation UI with required reason, confirmation, mutation, toast, query invalidation, and visible stock/status result. | medium | separate-recommended | Authorized warehouse user can reverse safely; unauthorized users cannot; browser coverage proves the result. |
| P2 | M042-F15 | Add status/date filters and a permission-gated header create action while preserving loading/error/empty/stale states and design-system density. | small | same-session-ok after semantic fixes | Filters round-trip through URL/API and the non-empty list exposes the primary issue action. |

## Session decision

This is a fresh plan because current M042 files changed after the prior report.
The first item is a real process-owner decision, not an implementation detail,
and the rest of the plan is predominantly high-risk inventory, financial,
idempotency, permission, and cross-module work. The module is therefore handed
off as `📋 Plan Ready`; no downstream fix is applied until the lifecycle choice
and work-order issue ownership are confirmed.

## Verification gate

Before marking M042 `✅ Verified`:

- Run the focused inventory, production-reservation, cancellation, GL, and
  HTTP contract suites against PostgreSQL.
- Add two-connection tests for issue replay and cancel-versus-cancel.
- Add reservation matching, work-order status/actuals, raw-ID absence,
  lot/UOM, inactive/blocked-source, and role-denial assertions.
- Add SPA/browser coverage for list/create/detail/picking, including warehouse
  versus view-only roles and the mobile/floor layout.
- Run `npm run typecheck`, `npm run test:run`, `npm run audit:api-routes` with
  the API service running, and `npm run audit:tokens`.



---

# Action plan — 2026-09-01 re-audit (measured)

Status: `📋 Plan Ready`
Overall recommendation: `separate-recommended`. No production-code fix applied
this session — justification in "Session decision" below, which is a *measured*
argument this time, not a deferral.

## Ordered fixes

| # | Priority | Finding(s) | Work | Scope | Session rec. | Acceptance evidence |
|---|---|---|---|---|---|---|
| 1 | **P0** | N01 + N02a/b/c | **One unit of work: per-reservation accounting.** Either (a) release exactly the reservation's own outstanding quantity under the same lock that changes its status, tracking `quantity_issued` per reservation, or (b) derive `stock_levels.reserved_quantity` from `Σ(active reservations)` instead of maintaining it independently. Must also validate, inside the lock: status is `reserved`, item/location match the line, work order matches the slip, and the quantity is covered. Release **before** the movement so a reservation can be drawn against. | large | separate-recommended | `reserved_quantity == Σ(reserved rows)` after every operation; the I5/I6/I7/I8 probes invert; **Race 2 yields exactly one winner**. |
| 2 | **P0** | N10, N11, N12 | Decide and implement the work-order contract: which statuses accept an issue, whether an issue may exceed `bom_quantity` (refuse vs. flag), and **which of the two issue paths is canonical**. Then have the surviving path maintain `work_order_materials` actuals/variance/cost for both manual and production-start issues, without double counting. | large | separate-recommended | Issues against cancelled/closed refused; over-BOM refused or visibly flagged; actuals reconcile across both paths. |
| 3 | **P0** | F01 (prior) | **Process-owner decision, still unresolved:** is create the authoritative physical issue (making picking advisory), or is a draft→picked→issued lifecycle required so stock and GL post at the physical transition? Note the measured consequence: because `create()` hard-codes `Issued`, the `Draft` branch of `cancel()` — the only code that correctly releases a reservation — is unreachable from any route. | large | separate-recommended | One authoritative lifecycle; no unreachable branch; reservation release reachable. |
| 4 | **P0** | F06 / Race 2 | Durable idempotency key + payload fingerprint on create; same-key replay returns the original slip. | medium | separate-recommended | Same-key replay produces one slip, one movement, one GL effect. |
| 5 | **P1** | N09 | Route line and slip totals through `Money`/`round2()` half-up so the document, the movement and the GL agree. | medium | separate-recommended (**changes a money figure**) | `qty 1 @ 1.0050` gives `1.01` on slip, line and movement alike. |
| 6 | **P1** | N14 | Refuse an issue whose item or location is soft-deleted, and make archived item/location still render on historic slips (`withTrashed()` on the resource relations) so traceability survives. | small | **same-session-ok** (missing guard refusing impossible input; read-path fix restores lost data) | Trashed item/location → 422 on create; `show` renders code/name for archived rows instead of `null`. |
| 7 | **P1** | N15 | Hash `work_order_id` on the issue resource, hash all ids in the picking payload, and strip raw item/location PKs from the insufficient-stock message. Coordinated with `spa/src/types/inventory.ts` and the `WO#<id>` render. | small–medium | separate-recommended for the resource/SPA pair; the **error-message** part is `same-session-ok` | No raw PK in any 200 or 422 body; list/detail/picking still render business identities. |
| 8 | **P1** | N07 | Add `items.*.material_reservation_id` to `hashIdFields()` and relax the rule so a hash id is accepted (raw ints still decode via `HashIdFilter`, so this is additive). | small | **same-session-ok** | Hash id → 201; raw PK continues to work; no client is broken. |
| 9 | **P1** | N06 | Preflight, then add FKs on `material_issue_slips.work_order_id`, `material_reservations.work_order_id`, `material_issue_slip_items.material_reservation_id`, plus positive-quantity / non-negative-cost checks. **Numbering:** these tables come from `0065`–`0067`, but any migration depending on later `2026_*` work must use a `2026_*` name — `'0' < '2'`, so every `0NNN_` runs first. Confirm the prefix is unused before writing. | medium | separate-recommended | Existing-data inventory clean first; orphan/zero/negative writes rejected. |
| 10 | **P1** | N13 | Column-scoped immutability on `material_issue_slips` / `_items` after a slip has moved stock. **Coordinate with the deferred `stock_movements` N4** from `warehouse-stock-control` — same exposure, and `stampLot()`/the GL handoff legitimately update rows, so a blanket trigger would break them. | medium | separate-recommended | Post-issue `UPDATE`/`DELETE` on identity and quantity columns refused in SQL; legitimate lot/GL updates still pass. |
| 11 | **P1** | N03 | Give reservations an HTTP surface: list, show, and an explicit release, gated on a reservation permission. Without it warehouse staff cannot see or free what is reserved, and the create form cannot offer a reservation to pick. | medium | separate-recommended | Warehouse can list open reservations, release one, and select one on the issue form. |
| 12 | **P1** | Reservation orphaning | Release reservations when their work order is cancelled/closed, and reconcile on cancel of an issued slip (N-cancel/I15). Depends on #1's accounting and #2's WO contract. | medium | separate-recommended | No `reserved` row survives its work order; cancelling an issued slip restores or explicitly releases the reservation. |
| 13 | **P2** | N08 | Gate the picking route (API + SPA) on the `inventory.picking.view` permission that already exists and is already seeded to `warehouse_staff`. | small | separate-recommended — **it 403s three roles that get 200 today**, so confirm the broad read was not deliberate | `warehouse_staff`/`system_admin` 200; `qc_inspector`/`production_manager`/`purchasing_officer` 403. |
| 14 | **P2** | F13 | Resolve the lot policy: real lot-quantity accounting, or label FEFO as a location-level heuristic so the UI stops presenting a lot as exact. | medium | separate-recommended | Operators are never directed to a depleted lot as authoritative. |
| 15 | **P2** | F14, F15 | Cancel UI with required reason + confirmation; status/date filters and a header create action on the list. | medium | same-session-ok **after** the semantic fixes | Authorized user can reverse safely; filters round-trip. |

## Open questions for a human (do not guess)

1. **Reservation expiry** — `material_reservations` has no `expires_at` and no
   scheduled command references it. Is expiry in scope at all, or are reservations
   meant to live until their work order consumes or releases them? Nothing in the
   schema suggests expiry was ever designed, so this is reported, not assumed.
2. **Which issue path is canonical** (N12) — production consuming material with no
   issue document may be deliberate, or may be the bug.
3. **Does reversing a posted stock movement need a checker?** (I25) — one
   `warehouse_staff` user created and cancelled its own issue, with no reason
   recorded. Journal entries require maker-checker; whether an issue reversal
   should is a policy decision.
4. **Was the broad picking read gate deliberate?** (N08) — the narrow permission
   exists and is seeded, which suggests not, but tightening it removes access
   three roles have today.
5. **May an issue exceed the BOM requirement?** (N11) — over-issue is real in
   plastics (purge, start-up scrap). Refuse, or record a variance?

## Session decision — why nothing was fixed, argued from measurement

Item 1 is the module's reason to exist, and it is the one thing that must not be
fixed piecemeal. The measured interaction is decisive: **N01's spurious refusal is
today accidentally containing N02b.** Correct the ordering so a reservation can be
drawn against, without simultaneously fixing per-reservation accounting, and
issues that currently fail safely would begin succeeding *and* silently releasing
other work orders' reservations — the ledger would diverge from the reservation
table more often, not less. A partial fix here is worse than no fix.

Items 2 and 3 are process-owner decisions (what an issue may exceed; which path is
authoritative; whether the lifecycle is one-shot or staged). Item 5 changes a money
figure. Items 9, 10 and 13 change database contracts, a shared immutability
exposure, and who may read. All are explicitly outside containment.

Items 6 and 8 **are** contained (a missing guard refusing impossible input; an
additive hash-id decode). They were left unapplied only because they are small
relative to the risk of touching `MaterialIssueService` while the reservation
cluster is unresolved — a fix session taking item 1 will be editing the same
method and can land them in the same pass with one set of test runs. Whoever takes
item 1 should take 6 and 8 with it.

## Verification gate before `✅ Verified`

- Invert every probe in `audit-report.md`'s measured section, including **Race 2
  yielding exactly one winner**.
- Assert `reserved_quantity == Σ(active reservations)` as a standing invariant
  after each operation.
- HTTP contract tests for all five routes — **four of five have never had one**.
- Run the out-of-module callers of `reserve()`/`release()`: Production + MRP.
- SPA: `npm run typecheck`, `npm run test:run`, `npm run audit:tokens`.
