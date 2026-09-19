# MRP / BOM Audit

Date: 2026-09-18
 
## Re-audit 2026-09-19

Current verdict: **historical FINISHED label is not release closure**.

- Inactive-component consistency, duplicate BOM constraints, MRP summary wiring, stale-run metadata, and retirement audit behavior are current.
- Remaining risks: soft-deleted highest-version collision, PO in-transit UOM, manual/daily overlap, plan-history loss, missing-BOM alert/status, MOQ precision, and untested Redis/two-worker behavior.
- Full current classification: `RE-AUDIT-REGISTER-2026-09-19.md`.
Scope: `api/app/Modules/MRP/` BOM/planning core. The MRP engine was traced in
`FINISHED-SALES-ORDER-CHAIN-TRACE-2026-09-18.md`; machines, molds, and `CapacityPlanningService` in
`PRODUCTION-AUDIT-2026-09-18-FINISHED.md`. This covers the remaining BOM/costing/run surface.
Claims are **[confirmed]** (file:line/grep) or **[assumption/unverified]**.

---

## 1. Walkthrough

- **BOM structure/versioning**: one-active-per-product via partial unique index + `(product_id,
  version)` unique; `update()` creates a new version and archives the old; `delete` refuses
  active BOMs. Cycle/depth detection happens only at explode/cost time; `validateDefinition`
  only blocks a product's own part number. Depth limit default 10.
- **Explosion**: `BomService::productionPlan` recursively explodes, validating and running two
  queries at **every** level (O(tree) redundant work); `MrpEngineService` also double-asserts.
- **Costing**: `BomCostingService` rolls up material/labor/machine/overhead; nested manufactured
  items recurse to `total_cost`; routing adds cycle-time and setup spread. `ensureFreshBom`
  keys staleness on `costed_at` vs item/BOM `updated_at`; routing changes only refresh because
  `ProductionRoutingService` eagerly recalculates.
- **Run/scope**: `runForActiveSalesOrders` isolates per-SO failures (`Partial`) and marks
  `Failed` without rethrowing; `MrpScopeResolver` walks parent products for stock-movement
  replans; `RunAutomaticMrpJob` is unique per unsorted id list and plant-overlapped.
- **Scheduling**: daily `mrp:run-daily` 06:00 and hourly `mrp:reap-stale-runs`.

## 2. Findings

1. **Material-vs-cost divergence on inactive sub-assembly products.** `BomService:600` resolves
   the sub-assembly with no `active()` filter while `BomCostingService:193` uses `active()`, so
   an inactive product is exploded as manufactured (raw materials demanded) but costed as a
   bought leaf. **[confirmed]**
2. **Outbox dedupe permanently drops repeated routing replans.** This was true of the earlier
   routing key, but the current implementation versions the key with the routing change
   timestamp and has a regression test for repeated same-reason edits. **[resolved before this
   pass]**
3. **`SalesOrderService::confirmWithChainResult` reads a non-existent `mrp_plans.summary`.** The
   scheduling summary lives on `MrpRun`, so `chain_result.scheduling_conflicts` is always `[]`
   and `scheduled_start` always falls back to `planned_start`. **[confirmed]**
4. **BOM graph validation is not explicit at the authoring boundary.** The costing call currently
   rejects active transitive cycles indirectly, but direct imports or legacy writes can still
   create a graph that fails only when it is later exploded.
5. **No DB unique `(bom_id,item_id)`** on `bom_items`; service/runtime guards exist, but direct
   writes can still persist invalid duplicates.
6. **`RunAutomaticMrpJob::uniqueId` hashes an unsorted id list**, so `[21,22]` and `[22,21]` are
   distinct unique jobs; coalescing can lose a change that arrives while a same-scope job runs.
7. **`RunDailyMrp` treats every `Partial` run as a command failure**, so one missing BOM can make
   the daily cron report failure even when other sales orders planned successfully. The current
   comparison uses the backed enum value correctly; the remaining issue is exit-status policy.
8. **`ReapStaleMrpRuns` only flips status/heartbeat** (no `error_code`/`duration_ms`) and its
   `$description` claims it cancels orphan draft auto-PRs, which the body deliberately does not.
9. **`prs_updated` metric is wrong** for retired PRs (the no-shortage branch cancels the draft
   but the counter calls it "updated").
10. **Audit hygiene**: cancelled PRs/WOs use query-builder `->update()`, bypassing `HasAuditLog`,
    while the reuse paths use audited `forceFill()->save()`.
11. **Dead code and contract drift**: `BomService::productionTree`/`productionTreeInto` have zero
   callers; the SO-confirm documentation must describe queued execution. `MrpPlanGenerated` is
   intentionally broadcast-only and is consumed by the SPA production dashboard.
12. **`BomItem` has no audit log/timestamps**, leaving direct line edits outside the BOM-version
   audit trail and outside cost-staleness detection.

## 3. Resolution Status

- Findings 1, 3, 4, 5, 6, 7, 8, 9, 10, and 12 were addressed in the follow-up implementation.
- Finding 2 was already resolved in the current tree; the follow-up preserves the versioned
  dedupe behavior and changes automatic MRP uniqueness to release when processing starts, so a
  change arriving during a running job can queue a follow-up run.
- Finding 11 removed the unused BOM production-tree path and corrected the broadcast/queue
  interpretation in this report. The broadcast event remains because the SPA consumes it.
- Regression coverage was added for inactive subassemblies, transitive cycles, duplicate BOM
  components, BOM-item audit timestamps, scheduling summaries, queue scope identity, daily-run
  exit semantics, stale-run metadata, audited lifecycle retirement, and PR metrics.

## 4. Assumptions

- A1. The original audit used source/grep only. The follow-up added focused tests and passed
  PHP linting, Pint, PHPStan, and PHPUnit test discovery; database-backed execution was blocked
  because Docker is unavailable and the configured PostgreSQL host `db` is unreachable.
- A2. Production data (cycles, inactive products, duplicate BOM lines) remains unquantified.
- A3. Seed settings (`mrp.bom.max_explode_depth`, `mrp.default_lead_time_days`,
  `mrp.safety_buffer_days`) presence was not verified per environment.
