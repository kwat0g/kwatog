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


