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

## Deferred

- **M02 automatic trigger remains pending:** the repository has no Inventory
  item-standard-cost update event. Wiring that trigger requires modifying the
  Inventory write path, which is outside this claimed module’s scope. The
  planning-time recost is implemented; the next session should add/validate the
  cross-module event once that boundary is approved.
