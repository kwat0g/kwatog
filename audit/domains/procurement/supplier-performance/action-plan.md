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

---

# Re-audit action plan — 2026-09-01

Status: `🔁 Needs Re-audit` → see release status below.
Baseline before any change: **29 passed / 0 failed / 69 assertions**
(`SupplierRankingTest`, `SupplierTierTest`, `SupplierQualityMetricsTest`,
`SupplierDeteriorationTest` on `ogami_test_supperf`).

Prior plan items F-005, F-006, F-007, F-008, F-012, F-013 are **closed and now
runtime-verified**; F-015's code is closed. The rows below are the newly measured
items plus the carried commercial/cross-module ones.

| Priority | Finding(s) | Ordered work | Scope | Session recommendation |
|---|---|---|---|---|
| P0 | NEW-01 | **Decide first (QUESTION-1), then implement.** Make the composite's treatment of an unknown `on_time_delivery_rate` / `quality_pass_rate` consistent with the seeded `neutral_missing_metric` policy, or renormalise weights, or ratify the current 0. Whichever is chosen, persist a marker distinguishing "not measured" from "measured zero" so the API can too. | medium | **separate-recommended** — changes every tier boundary; commercial |
| P0 | NEW-02 | Extend `test_only_non_terminal_inspections_does_not_divide_by_zero` to assert `overall_score` and `tier`, and correct its two factually wrong comments. Must be done *with* NEW-01 so the assertion pins the ratified number, not the current one. | small | separate-recommended (coupled to NEW-01) |
| P0 | F-001 | Replace the quantity-shortfall placeholder with the approved price-variance definition. Carried unchanged. | large | separate-recommended |
| P0 | F-002, F-004 | Supplier attribution for work-order/delivery inspections; mixed coverage and `partial_accepted` semantics. Carried unchanged. | large | separate-recommended |
| P0 | F-009 | Stable vendor-period deterioration identity + notification idempotency. Carried unchanged. | medium | separate-recommended |
| P1 | NEW-03 | Exclude soft-deleted `purchase_orders` / `purchase_order_items` from `priceVariancePct()`, `po_count`, and the on-time and lead-time joins (`whereNull('deleted_at')` on each `DB::table()` reach, or route through the Eloquent model). Archived rows must not feed a live score. | small | **same-session-ok** — "archived rows do not count" is a correctness rule, not a commercial choice |
| P1 | NEW-04 | Exclude soft-deleted vendors from `ranking()` — the raw `leftJoin('vendors')` bypasses the scope the eager load applies, so an archived vendor ranks with a null identity and consumes a `limit` slot. No score changes. | small | **same-session-ok** |
| P1 | NEW-06 | Clamp or guard `lead_time_variance_days` against the `numeric(5,2)` ±999.99 domain so a mis-keyed expected date cannot 500 `compute()`. Prefer a service-side clamp with a logged warning over widening the column. | small | **same-session-ok** |
| P1 | NEW-05 | **QUESTION-2 first.** Constrain which `purchase_orders.status` values enter `price_variance_pct` / `po_count`. Today `draft`, `pending_approval` and `cancelled` all count and a cancelled PO reads as 100% variance. | medium | separate-recommended — commercial |
| P1 | F-010 | **QUESTION-3.** Resolve recompute RBAC: seeder grants it to `purchasing_officer`, controller docblock says Admin-only. Align seeder, route, docblock and UI. | medium | separate-recommended |
| P2 | F-011 | Bound each supplier policy setting to its real domain (neutral/targets 0–100, thresholds inside the score domain, penalty factors sane). | medium | separate-recommended |
| P2 | F-015 | Two-connection concurrent first-computation probe. Subject to the `RefreshDatabase` visibility artifact; abandon and say so if it deadlocks. | medium | separate-recommended |
| P2 | — | Ranking tie determinism: insert two equal-score vendors in **both** insertion orders and assert identical output. Ordering is deterministic by construction (score → `vendors.name` → unique `vendor_id`); this is a **regression lock**, expected to pass either way. | small | same-session-ok |

## Release decision

The three P0 items and both `NEW-05`/`F-010` are commercial or cross-module
decisions that this session is explicitly forbidden to make: NEW-01 alone moves
every supplier's tier. The majority of the *meaningful* work is therefore
`separate-recommended`, and the module stays short of `✅ Verified`.

The genuinely contained items — NEW-03, NEW-04, NEW-06 — are corrections of the
"which rows count" and "unhandled domain overflow" kind, none of which requires a
policy choice: excluding archived rows and refusing to overflow a column are
unambiguously right. They are the only candidates for same-session work.

## Suggested follow-up order

1. Get QUESTION-1 answered — it blocks the largest single scoring defect and
   NEW-02's assertion depends on the answer.
2. Land the contained soft-delete and overflow guards (NEW-03/04/06) with probes.
3. Then QUESTION-2/QUESTION-3, then the carried F-001/F-002/F-003/F-004 metric
   redefinitions with their cross-module fixtures.

## Outcome of the 2026-09-01 session

**Landed** (commit `6f357553`): NEW-03, NEW-04, NEW-06 — the three items tagged
`same-session-ok`. Each was measured failing before and passing after, and the
five new regression cases were confirmed red against unmodified HEAD. Baseline
29/69 → 40/96, no regressions.

**Not landed, by design:** NEW-01, NEW-02 (coupled to NEW-01), NEW-05, and the
carried F-001/F-002/F-003/F-004/F-009/F-010/F-011.

Justification for the split: the landed three are corrections to *which rows
count* and to an *unhandled column domain* — excluding archived rows and refusing
to overflow `numeric(5,2)` require no policy choice, and the clamp was proven
score-neutral by assertion. Everything deferred either changes what a score
*means* (NEW-01 moves every tier boundary; NEW-05 redefines the price base) or
crosses into quality/inventory/RBAC ownership. Fixing only the contained items
from a mostly-gated plan is the explicitly sanctioned split.

**Blocking on humans:** QUESTION-1 (0-vs-neutral for a missing metric — the
single largest scoring defect), QUESTION-2 (which PO statuses count),
QUESTION-3 (recompute RBAC).

**Also referred out:** NEW-08 — the archived-vendor leak fixed here in `ranking()`
still exists in three Dashboard services. Needs the Dashboard module's owner.
