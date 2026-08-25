# M049 — Action Plan

Final audit status: 📋 Plan Ready. No implementation changes were made in this session because the core findings span financial precision, database invariants, outbox atomicity, actor/audit context, production-work-order policy, and RBAC. Those concerns are separate-recommended under the audit protocol; the small frontend polish items are safe candidates for a later focused session.

| # | Finding | Action and acceptance evidence | Scope | Session recommendation |
|---:|---|---|---|---|
| 1 | M049-B01 | Define/enforce WO class policy at the MRP boundary. Standard stock output must require an effective BOM/material plan; explicit service/non-stock/prototype exceptions must carry class, authorized actor, reason, costing rule, and visible plan/WO diagnostics. Add API and integration tests for both paths. | large | separate-recommended |
| 2 | M049-B02 | Add one shared BOM/component integrity guard used by costing, explosion, and MRP netting. Reject inactive/deleted components with a typed domain error and operator recovery message; add lifecycle-drift tests. | medium | separate-recommended |
| 3 | M049-B03 | Move BOM-change outbox recording inside the same transaction as version creation, or use a transaction-aware domain event/outbox boundary that cannot commit the BOM without the replan intent. Test rollback and dispatcher recovery. | medium | separate-recommended |
| 4 | M049-M01 | Add a PostgreSQL partial unique index for one active BOM per product, first handling any existing duplicate data in a controlled migration. Retain service validation for readable errors. Add a concurrency test. | medium | separate-recommended |
| 5 | M049-B04 | Introduce a planning response serializer that hashes every nested item/product/SO/work-order identifier. Update TypeScript types and add resource contract tests for diagnostics, cost summary, linked WOs, and run history. | medium | separate-recommended |
| 6 | M049-I01 | Remove float round-trips from monetary diagnostics and auto-PR prices. Keep decimal strings/BCMath through Money rounding and assert boundary values such as 1.005, four-decimal item costs, and two-decimal PR persistence. | medium | separate-recommended |
| 7 | M049-B05 | Align the role matrix, seeded permissions, backend routes, dashboard widgets, and frontend guards. Recommended default: production-manager receives read-only `mrp.plans.view` and `mrp.runs.view`, never run/manage permissions. Add role endpoint tests. | small | separate-recommended |
| 8 | M049-I03, M049-I04 | Carry authenticated/system actor, originating MRP run ID/correlation, source entity, and reason into generated plans, PRs, WOs, outbox payloads, and operator-facing errors. Preserve the human actor separately from the queue worker. | medium | separate-recommended |
| 9 | M049-I02 | Add a `partial`/equivalent run outcome or explicit failed-order counters. Completion must distinguish all-success, partial, and catastrophic failure; alerts and UI recovery copy must use the same state. | medium | separate-recommended |
| 10 | M049-M02 | Define standard-cost freshness policy. On item standard-cost change, recost affected active BOMs and enqueue scoped parent-SO replans atomically, or explicitly mark cost snapshots stale and block dependent plans until recosted. Add event and replan tests. | large | separate-recommended |
| 11 | M049-P01 | Gate BOM detail actions with `usePermission('mrp.boms.manage')`; retain backend enforcement and add a view-only browser/component check. | small | same-session-ok |
| 12 | M049-P02 | Add `onError` feedback and appropriate query invalidation to plan re-run; verify failed rerun leaves the current plan visible and gives an actionable toast. | small | same-session-ok |
| 13 | M049-P03 | Replace translucent semantic backgrounds with opaque design tokens, correct warning `colSpan`, and add a responsive overflow wrapper for the diagnostics table if required by the mobile smoke check. | small | same-session-ok |
| 14 | M049-P04 | Correct the stale setup-time comment and keep the BOM costing TDD contract aligned with the implementation. | small | same-session-ok |

## Recommended order

1. Decide no-BOM and historical-cost semantics.
2. Repair BOM/component integrity and the database active-version invariant.
3. Make BOM changes, outbox intents, and MRP actor/run context atomic and auditable.
4. Correct monetary serialization/rounding and partial-run/error semantics.
5. Align RBAC/API contracts.
6. Apply the contained frontend polish items, then run the focused MRP suite plus browser smoke coverage.

## Handoff state

All findings remain deferred and should be marked `Needs Re-audit` after implementation until the listed acceptance tests and a fresh registry audit confirm them.
