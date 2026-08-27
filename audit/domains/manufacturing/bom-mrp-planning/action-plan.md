# M049 — Action Plan

Final audit status: 📋 Plan Ready. No implementation changes were made in this session. The gate does not permit fixes because every current action is `separate-recommended` and the total scope is not small.

| # | Finding | Ordered action and acceptance evidence | Scope | Session recommendation |
|---:|---|---|---|---|
| 1 | M049-B06 | Define the cancellation race policy (skip, typed partial failure, or synchronous rejection). Lock and re-read the sales order inside each MRP transaction, enforce plannable status in the direct entry point, and ensure no active plan, draft auto-PR, or planned WO can be committed after cancellation. Add an interleaving/concurrency test covering candidate snapshot, cancellation, and MRP commit. | large | separate-recommended |
| 2 | M049-I05 | Decide whether BOM cost snapshots are mutable historical repair data or immutable audit records. If immutable, restrict recalc to the active version or create a new revision; if mutable, document the audit consequence and version the repair event. Add tests for inactive-version recalc and planning-time freshness. | medium | separate-recommended |
| 3 | M049-M02 | Publish a standard-cost-change event from the inventory update boundary, scope affected active BOMs and parent SOs, and enqueue an idempotent recost/replan transaction—or explicitly mark dependent snapshots stale and block/use a visible recovery state. Add end-to-end event and replan tests. | large | separate-recommended |
| 4 | M049-I04 | Define the maker-field contract for queued system work. Preserve system/run provenance without attributing automatic plan, PR, and WO creation to the SO creator; choose a valid system actor or nullable maker fields with required generation context. Add automatic and manual attribution tests. | medium | separate-recommended |
| 5 | M049-M03 | Add focused regression coverage for the nine historical hardening gaps and M049-B06: no-BOM WO policy, component lifecycle, concurrent active BOMs, outbox rollback, actor/run context, resource HashIDs, partial/redacted recovery, production-manager access, item-cost replan, and cancellation interleaving. Keep all tests on the module’s isolated database. | medium | separate-recommended |

## Gate result

Same-session-ok actions: 0/5. Small actions: 0/5. The majority/small-scope condition is not met; status remains **📋 Plan Ready** and no code changes are authorized in this session.

## Handoff

After these actions land, run the focused MRP suite, role alignment suite, SPA checks, and the new concurrency/contract tests on the unique module database, then request a fresh M049 re-audit. Do not edit the generated registry during parallel audit work.
