# M038 — Supplier Performance Action Plan

Status: `📋 Plan Ready`  
Prepared: 2026-08-25  
No implementation fixes were applied in this session.

The plan is intentionally split at business-policy and cross-module boundaries.
Items tagged `separate-recommended` should be completed with their fixture and
verification work in the same follow-up session as the code change.

| Priority | Finding(s) | Ordered work | Scope | Session recommendation |
|---|---|---|---|---|
| P0 | F-001 | Replace the quantity-shortfall placeholder with the approved price-variance definition. Join authoritative PO unit prices to GRN unit costs, define receipt/quantity weighting and currency, and keep all financial arithmetic decimal-string/`Money` based. Add cent-level and zero-price fixtures. | large | separate-recommended |
| P0 | F-002, F-004 | Decide supplier attribution for work-order/delivery inspections, define mixed inspection/GRN coverage and partial-acceptance semantics, then implement quality aggregation and stage breakdown. Add incoming, in-process, outgoing, mixed, and partial-accepted fixtures. | large | separate-recommended |
| P0 | F-009 | Establish a stable vendor-period deterioration identity and make notification delivery idempotent across outbox replay, queue retry, and partial notification failure. Verify no duplicate inbox rows are created. | medium | separate-recommended |
| P1 | F-003 | Choose PO-level, receipt-level, or quantity-weighted delivery semantics; align on-time and lead-time calculations and comments; add multiple/partial GRN cases. | medium | separate-recommended |
| P1 | F-005, F-006 | Make ranking ordering explicit (`NULLS LAST`, vendor name tie-break), validate period/tier/limit inputs, and return the complete documented row/meta contract including count. Add null, tie, invalid-input, and shape assertions. | medium | same-session-ok |
| P1 | F-007 | Validate CLI, service, and controller periods at the boundary; reject month values outside 1–12 and agree a year range. Add a compatible database constraint after checking existing data. | medium | same-session-ok |
| P1 | F-008 | Change the deterioration link to `/purchasing/suppliers/{hash}/performance` and assert the payload in a listener test. | small | same-session-ok |
| P1 | F-010 | Confirm whether recompute is purchasing-officer self-service or admin-only. Then align the permission seeder, route permission, controller documentation, and UI affordance. | medium | separate-recommended |
| P1 | F-012 | Build the ranking SPA surface: API client/types, route, permission guard, filters, table, pagination/limit behavior, empty/error/loading states, and detail links. | large | separate-recommended |
| P2 | F-011 | Define and centrally validate domains for neutral score, penalty factors, targets, weights, and tier thresholds. Add configuration-boundary tests. | medium | separate-recommended |
| P2 | F-013 | Use the configured trend window in the heading and add a text/table alternative for the score bars. Recheck palette contrast and reduced-motion behavior. | small | same-session-ok |
| P2 | F-014 | Extend regression coverage for every corrected metric and all API/notification contracts; run it with the test database available. | medium | separate-recommended |
| P2 | F-015 | Add a concurrent first-computation test and implement the selected upsert/lock/retry behavior around the unique snapshot key. | medium | separate-recommended |

## Release decision

The plan contains multiple financial, quality-policy, queued side-effect, RBAC,
and large frontend items. The majority of the meaningful work is therefore
`separate-recommended`; this session stops at Plan Ready rather than applying
isolated fixes that would leave the scorecard internally inconsistent.

## Suggested follow-up order

1. Resolve price, quality, and delivery metric definitions with purchasing,
   inventory, and quality owners.
2. Implement and test the backend metric corrections and notification
   idempotency together.
3. Harden ranking/period contracts and correct the alert link.
4. Resolve recompute RBAC, then build the ranking UI and dynamic trend polish.
5. Run the focused Laravel suite and the SPA checks with PostgreSQL available.
