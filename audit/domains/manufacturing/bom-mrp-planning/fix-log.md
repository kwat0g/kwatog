# M049 — Fix Log

## Same-session fixes

- **B01 — no-BOM work-order policy:** Before, `MrpEngineService` skipped only
  demand explosion and then created a standard root work order. After,
  `api/app/Modules/MRP/Services/MrpEngineService.php:148` records the missing
  BOM as a blocked diagnostic, `:164` validates usable BOMs, `:400` skips the
  standard-work-order path, and `:734` cancels only stale planned MRP roots.
  Existing progressed/manual work orders remain untouched.

- **B02 — component lifecycle integrity:** Before, costing rejected some bad
  components while explosion/netting could treat missing or inactive rows as
  leaves. After, the shared guard at
  `api/app/Modules/MRP/Services/BomComponentIntegrityService.php:16` rejects
  empty, duplicate, missing, archived, and inactive components with the stable
  `bom_component_integrity` code; costing and every BOM traversal invoke it at
  `api/app/Modules/MRP/Services/BomCostingService.php:29` and
  `api/app/Modules/MRP/Services/BomService.php:260`.

- **B03 — BOM/outbox atomicity:** Before, `bom_changed` was recorded after the
  BOM transaction committed. After,
  `api/app/Modules/MRP/Services/BomService.php:84-114` records the replan event
  inside the same transaction, so the outbox row rolls back with a failed BOM
  write.

- **M01 — one active BOM invariant:** Added deterministic duplicate cleanup and
  a partial unique index in
  `api/database/migrations/2026_08_26_000100_guard_one_active_bom_per_product.php:14-53`.
  The newest active version is retained and older duplicate active flags are
  archived without deleting history.

- **B04 — public planning IDs:** Before, nested diagnostics, cost-summary
  products, work-order products, and run-summary IDs could be raw integers.
  After, `api/app/Modules/MRP/Resources/MrpPlanningResponseSerializer.php:10`
  hashes known nested ID keys, and both planning resources apply it at
  `api/app/Modules/MRP/Resources/MrpPlanResource.php:34` and
  `api/app/Modules/MRP/Resources/MrpRunResource.php:39`.

- **I01 — money precision:** Before, MRP converted item standard cost to float
  for diagnostics, shortage pricing, and PR persistence. After,
  `api/app/Modules/MRP/Services/MrpEngineService.php:272-293` keeps costs as
  decimal strings and `:366` rounds only at the purchase-request money boundary.

- **B05 — production-manager read access:** Added `mrp.plans.view` and
  `mrp.runs.view` to the production-manager catalog at
  `api/database/seeders/RolePermissionSeeder.php:566`; the companion migration
  at `api/database/migrations/2026_08_26_000300_align_mrp_production_manager_read_permissions.php:11`
  repairs already-seeded installations.

- **I02 — partial run outcomes:** Added the `partial` state at
  `api/app/Modules/MRP/Enums/MrpRunStatus.php:11`, persisted failed-SO counts
  and safe recovery fields in
  `api/database/migrations/2026_08_26_000200_add_mrp_run_outcomes_and_context.php:25-39`,
  and emit partial results from
  `api/app/Modules/MRP/Services/MrpEngineService.php:656-687`. The frontend
  displays partial status and recovery guidance at
  `spa/src/components/mrp/MrpRunStatusPanel.tsx:11-15` and `:53-77`.

- **I03/I04 — actor and run context:** Added nullable `mrp_run_id` plus a
  generation-context snapshot to plans and safe error code/recovery fields to
  runs in `api/database/migrations/2026_08_26_000200_add_mrp_run_outcomes_and_context.php:12-39`.
  The engine carries actor/run/reason context through plans, PRs, WOs, and the
  durable event at `api/app/Modules/MRP/Services/MrpEngineService.php:80-101`
  and `:518`; the event/job path preserves it at
  `api/app/Modules/MRP/Events/MrpPlanGenerated.php:21` and
  `api/app/Modules/MRP/Jobs/RunAutomaticMrpJob.php:43-52`.

- **M02 — planning-time cost freshness:** Added nested component/BOM stale-cost
  detection and recosting before an MRP plan consumes a snapshot at
  `api/app/Modules/MRP/Services/BomCostingService.php:34-88` and
  `api/app/Modules/MRP/Services/MrpEngineService.php:164`.

- **Polish P01–P04:** BOM actions are permission-gated at
  `spa/src/pages/mrp/boms/detail.tsx:15-23` and `:92`; plan rerun errors are
  surfaced at `spa/src/pages/mrp/plans/detail.tsx:39`; semantic cards use
  opaque tokens and the diagnostics table is responsive with `colSpan={9}` at
  `spa/src/pages/mrp/plans/detail.tsx:96-149`; the stale costing comment was
  corrected at `api/app/Modules/MRP/Services/BomCostingService.php:20-26`.

## Verification

- PHP syntax checks passed for all changed MRP services, resources, models,
  events, jobs, and migrations.
- MRP service container resolution passed in the API container.
- Durable event codec checks passed for the extended `MrpReplanRequested`
  payload and `MrpPlanGenerated` payload keys.
- Nested response serializer check passed: raw `so_id`, `plan_id`, and
  `work_order_id` values became hash IDs.
- Targeted frontend ESLint passed for the changed MRP files.
- `npm run typecheck` still reports only pre-existing unrelated errors in
  `spa/src/pages/assets/detail.tsx` and
  `spa/src/pages/return-management/detail.tsx`.
- The focused MRP PHPUnit suite could not complete because the shared test
  database fails earlier in the unrelated concurrent migration
  `api/database/migrations/2026_08_25_210000_enforce_one_active_holiday_per_date.php:42`
  while dropping a constraint-backed index (`holidays_date_name_unique`).

## Session 2026-08-27 — execution/verification session

The 2026-08-25 session could not run PHPUnit (a concurrent holiday migration
broke `migrate:fresh` repo-wide). Everything above was therefore committed
source-only and unexecuted. This session executed it.

### Fix — V01: `MrpNettingTest` cost fixture built an unreachable BOM state

`api/tests/Feature/MRP/MrpNettingTest.php:502-523`
(`test_mrp_plan_persists_bom_cost_summary_and_material_cost_diagnostics`)
failed with `-'275.00' / +'100.00'` on
`$plan->cost_summary['planned_production_cost']`.

Cause: the M02 fix above added planning-time recosting
(`MrpEngineService.php:164` → `BomService::ensureFreshForPlanning()` →
`BomCostingService::ensureFreshBom()`, `BomCostingService.php:52-87`). That
guard treats `costed_at === null` as stale (`:61`) and recosts. The fixture
force-filled the five cost columns but left `costed_at` null, so planning
discarded the snapshot and recomputed from the components: material
2 pcs x P5.00 = P10.00/unit, no routing so labor/machine/overhead = P0.00,
total P10.00/unit; x10 units = P100.00. The actual output was arithmetically
correct **for what was in the database**.

`costed_at` is written in exactly one place — `BomCostingService.php:177` —
inside the same `forceFill()` as the five cost columns. A BOM carrying costs
with a null `costed_at` is therefore a state production code cannot reach; the
fixture was asserting against an impossible row.

Before: `forceFill([... 'total_cost' => '27.50'])`.
After: the same five values plus `'costed_at' => now()`, and a comment
recording why. **No expected cost value was changed.** All five original
expectations (275.00 / 100.00 / 50.00 / 100.00 / 25.00) pass unchanged once the
fixture is a complete frozen snapshot, which independently confirms the MRP
cost-summary multiplication (frozen bucket x remaining line quantity) is
correct. Costing itself stays pinned by `BomCostingTest`.

### Verified by execution this session (on `ogami_w2_mrp`)

- `MrpNettingTest` 11/11, `MrpDemandIntegrityTest` 6/6, `MrpRerunSafetyTest`
  2/2, `MrpAutomationTest` 6/6, `MultiLevelBomTest` 5/5,
  `SubassemblyWorkOrderTest` 5/5, `BomCostingTest`, `MrpReaperTest`,
  `RoleResponsibilityAlignmentTest` 15/15.
- M01 partial unique index present and correct:
  `bill_of_materials_one_active_per_product UNIQUE (product_id) WHERE
  is_active = true AND deleted_at IS NULL`.
- I02/I03/I04 columns present: `mrp_runs.failed_sales_orders`, `.error_code`,
  `.recovery_action`; `mrp_plans.mrp_run_id`, `.generation_context`.
- B05 verified positively after seeding `RolePermissionSeeder`:
  production_manager holds `mrp.plans.view` + `mrp.runs.view` and still holds
  no `mrp.plans.run` / `mrp.boms.manage`. `RoleResponsibilityAlignmentTest`
  confirms the added reads leaked no manage/run slug.
- I02 end-to-end: `MrpRunStatus::Partial` exists AND the DB CHECK constraint was
  correctly widened — `mrp_runs_status_lifecycle_check` accepts
  `running|completed|failed|partial`. An un-widened constraint would have thrown
  on the first partial run; it does not.
- B04 serializer exercised directly: `item_id`/`product_id`/`so_id`/`plan_id`/
  `work_order_id` come back as hash IDs, and the money strings (`'100.00'`,
  `'275.00'`, `'27.50'`) pass through byte-identical — the serializer does not
  coerce decimal strings. SPA types agree (`spa/src/types/mrp.ts:114,121,133,145`
  are all `string`).
- P01–P04 confirmed by inspection: `usePermission` + `can('mrp.boms.manage')` at
  `spa/src/pages/mrp/boms/detail.tsx:15,22,92`; rerun `onError` toast at
  `spa/src/pages/mrp/plans/detail.tsx:39`; opaque tokens (the `/5` alpha
  suffixes are gone), `overflow-x-auto` and `colSpan={9}` at `:96-148`; no
  hardcoded colour in any changed MRP frontend file, and every token used is
  defined in all three palettes in `spa/src/styles/tokens.css`.
- All three 2026_08_26_* migrations apply cleanly under `migrate:fresh`.

### Not verified this session

- **SPA `npm run typecheck` / `audit:tokens` were NOT run.** The host had 449 MiB
  available with three other audit agents live; `tsc` over this SPA would risk
  OOM-killing them. The frontend checks above are static inspection only.

## Deferred

- **MRP cancellation race — CONFIRMED, deliberately not fixed.** Not covered by
  any of this module's 14 action-plan items, and the remedy embeds a policy
  choice, so it was documented rather than patched.

  Evidence. Every path into `runForSalesOrder()` funnels through
  `runForActiveSalesOrders()`, which reads its candidate set once, outside any
  transaction, at `api/app/Modules/MRP/Services/MrpEngineService.php:607-612`
  (`SalesOrder::whereIn('status', ['confirmed','in_production',
  'partially_delivered'])->get()`), then loops at `:622` and calls
  `runForSalesOrder()` at `:632`. That method opens its own transaction at `:84`
  and locks the prior *MrpPlan* at `:100-104` (`lockForUpdate()`), but never
  re-reads or re-validates `$so->status`. The window is between the `:612`
  snapshot and each per-SO transaction — it widens with batch size, since an SO
  late in the loop is planned long after it was read. (`rerun()` at `:536-544`
  and `RunAutomaticMrpJob` both enter through the same filtered query, so the
  narrow filtered case — SO already cancelled at read time — is handled: the run
  generates no plan and `:546` throws.)

  Blast radius is *orphaned* commitments, not merely a stale plan.
  `SalesOrderService::cancel()` at `api/app/Modules/CRM/Services/SalesOrderService.php:645-654`
  correctly re-reads and locks the SO, and at `:665-691` cleans up downstream MRP
  artifacts (supersedes the active plan, cancels cancellable WOs). If the MRP run
  commits *after* that cleanup, it writes a brand-new Active plan plus draft
  auto-PRs and planned WOs against a cancelled order, and the cleanup has already
  run, so nothing collects them.

  The codebase already has the established remedy pattern for exactly this bug
  class — see `MachineStatusTransitionTest::test_transition_revalidates_stale_route_model_inside_locked_transaction`
  and `SalesOrderLifecycleConcurrencyTest` (both green) — so the fix is
  mechanically small: re-read and lock the SO inside `runForSalesOrder()`'s
  transaction and re-assert the plannable status set already declared at `:607`.

  What makes it a decision, not a patch: **what the run should then do.**
  - Option A — skip the SO silently: no plan, no counter movement. Batch runs
    stay `completed`. Risk: a cancelled-mid-run SO leaves no operator trace.
  - Option B — count it as a failed SO: reuses the new I02 machinery, so the run
    reports `partial` with an `error_code`/`recovery_action`. Risk: a *routine*
    cancellation now renders as a partial failure and will alarm planners, and
    `rerun()` at `:546` would raise `BusinessRuleException` for what is arguably
    correct behaviour.
  - Option C — new non-failure "skipped" counter, distinct from both. Costs
    another column plus UI, but is the only one that reports the truth.

  Recommend deciding A/B/C before implementing; the acceptance test is a
  concurrency test in the shape of the two named above.

- **No new regression tests were added for ANY of the 14 findings.** Verified
  mechanically: `git show --stat 167de85e -- api/tests/Feature/MRP
  api/tests/Unit/MRP` is empty, and this session's run of all 11 MRP classes
  totals exactly 67 tests / 184 assertions — identical to the pre-fix baseline
  recorded at `audit-report.md:14`. The nine test gaps listed at
  `audit-report.md:127-129` are therefore all still open. The fixes are
  implemented and (where checkable) behaviourally verified, but they are
  unpinned: nothing will catch a regression in B01–B05, M01, M02 or I01–I04.

- **M02 planning-time recost has no test coverage.** `ensureFresh` /
  `ensureFreshBom` / `costed_at` staleness appear nowhere in `api/tests`
  (grepped). The V01 fixture fix deliberately makes the netting test bypass the
  recost path rather than depend on it, so the recost is still unproven. It
  needs its own test: a costed BOM whose component `standard_cost` changes
  afterwards must be recosted before the plan consumes it.
- **M02 recost mutates frozen cost snapshots during a planning run** —
  unresolved policy, not a defect to patch blind. `BomCostingService.php:85`
  rewrites the BOM's five money columns, `cost_basis`, `costed_at` and every
  `BomItem.unit_cost`/`extended_cost` from inside an MRP run. That directly
  contradicts the still-open policy question recorded at
  `audit-report.md:123-125` (are historical BOM cost snapshots mutable for
  repair, or immutable for audit?). Business decision; not taken here.
- **M02 automatic trigger remains pending:** the repository has no Inventory
  item-standard-cost update event. Wiring that trigger requires modifying the
  Inventory write path, which is outside this claimed module’s scope. The
  planning-time recost is implemented; the next session should add/validate the
  cross-module event once that boundary is approved.
