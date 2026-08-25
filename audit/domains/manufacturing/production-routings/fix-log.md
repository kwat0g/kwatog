# M052 — Production Routings Fix Log

Audit date: 2026-08-25 (`📋 Plan Ready`)
Implementation session: 2026-08-26
Final status: `✅ Verified`

## Session context

This session resumed the `📋 Plan Ready` plan and worked all ten items, including
the `separate-recommended` ones — this was the dedicated session those were
waiting for.

A **prior session crashed mid-implementation** and left an orphaned `.lock` plus
uncommitted work in the tree. Claim was `RECLAIMED`. Plan items 1–3 were already
largely implemented by that session; they were reviewed, corrected, hardened, and
are now covered by tests. Items 4–10 were implemented in this session. Nothing in
`audit-report.md` was invalidated by intervening commits — the last commit
touching routing source is `2192116c` (Task 12), well before the audit.

Discovery was not re-run beyond confirming the above.

---

## Item 1 — one active routing per product; concurrency-safe version allocation

Evidence: H-01, H-05.

**Pre-existing from the crashed session (reviewed, kept):**

- `api/database/migrations/2026_08_25_143500_enforce_one_active_routing_per_product.php:41-43`
  — partial unique index `product_routings_one_active_per_product_unique ON
  product_routings (product_id) WHERE is_active = TRUE`, with a data-repair pass
  (`:17-37`) that keeps the newest version per product before the constraint is
  added. Verified present in a freshly migrated database via `\d product_routings`.
- `api/app/Modules/Production/Services/ProductionRoutingService.php:341-358`
  (`lockActiveProduct`) — `SELECT … FOR UPDATE` on the product row serialises every
  routing writer for that product, so `max(version) + 1` can no longer race.
- `api/app/Modules/Production/Exceptions/RoutingVersionConflictException.php:26-33`
  — `render()` returns **409** with `code: ROUTING_VERSION_CONFLICT` instead of a
  raw `QueryException`, mapped from SQLSTATE 23505/23000 in
  `ProductionRoutingService.php:isRoutingVersionConflict()`.

**Changed this session:**

- `ProductionRoutingService.php:267-299` (`createVersion`) — before: `ProductRouting::create()`
  with `total_cycle_time => '0.00'` followed by `$routing->update([...])` once the
  operations were summed. After: the operations are totalled *first* and the version
  is inserted once. Two audit rows for one logical publish became one, per the
  audit-row hygiene rule in `CLAUDE.md`.
- `ProductionRoutingService.php:301-320` (new `supersedeActiveVersions`) — before: a
  mass `->update(['is_active' => false])`. After: the (at most one, by the index)
  active row is loaded and saved individually. A mass Eloquent update fires no model
  events, so `HasAuditLog` recorded the version that took over but **not** the one it
  displaced — half the handover was invisible.
- `ProductionRoutingService.php:120-156` (`update`) — before: the guard read
  `$routing->is_active` from the caller's in-memory copy, *before* taking the product
  lock. After: the row is re-read `lockForUpdate()` **inside** the lock and re-checked.
  Two editors who both loaded the same active version could each publish; the loser
  silently overwrote a change it never saw. Now the second gets a 422 telling it to
  reload.
- `ProductionRoutingService.php:164-190` (`duplicate`) — same re-read under the lock
  for the source version, so a duplicate cannot copy a definition that was superseded
  between load and submit.

Downstream consumers (`WoOperationService::generateFromRouting` at
`api/app/Modules/Production/Services/WoOperationService.php:46-50`, and
`BomCostingService::routingCosts` at `api/app/Modules/MRP/Services/BomCostingService.php:209-213`)
select `is_active = true` then `->first()`. Both are now deterministic **because of
the index**, not because of a query hint — deliberately left untouched, they belong
to other modules.

Tests: `test_create_publishes_the_only_active_version`,
`test_the_database_refuses_a_second_active_version`,
`test_a_stale_in_memory_version_cannot_publish_over_a_newer_one`,
`test_both_sides_of_a_version_handover_are_audited`.

## Item 2 — operation provenance for already-generated work orders

Evidence: H-02.

**Pre-existing from the crashed session (reviewed, kept):** `update()` no longer
force-deletes and recreates the operation rows; it publishes a new version and
leaves the edited one intact. `wo_operations.routing_operation_id` is
`nullOnDelete()`, so the old behaviour nulled the provenance of work already on the
floor.

**Changed this session:** the docblock at `ProductionRoutingService.php:111-119` now
states *why* (work orders hold an FK to the operation rows) rather than only *what*,
and `RoutingOperation` carries a class-level comment to the same effect
(`api/app/Modules/Production/Models/RoutingOperation.php:9-16`).

Test: `test_an_edit_publishes_a_new_version_and_keeps_work_order_provenance` —
generates WO operations from v1, edits, then asserts the WO still cites the original
`routing_operation_id` **and** that the row still holds the original values
(`operation_name = 'Injection'`, `cycle_time_minutes = 1.50`).

## Item 3 — routing-definition validation at both request and service level

Evidence: H-03, H-04.

**Pre-existing from the crashed session (reviewed, kept):**

- `api/app/Modules/Production/Requests/StoreRoutingRequest.php:39-74` — `distinct` on
  sequence; `decimal:0,2` / `decimal:0,4` with maxima matching the columns;
  `Rule::exists` scoped to `deleted_at IS NULL` plus usable `status` for machines and
  molds, and `is_active` for the product.
- `ProductionRoutingService.php:assertRoutingDefinitionValid()` — the same rules for
  direct service callers (imports, jobs, seeders): unique positive sequences, decimal
  scale and maxima, positive cycle time, total-cycle-time ceiling, machine/mold
  existence and usable status, **mold-to-product ownership**, and machine/mold
  compatibility via `Mold::compatibleMachines()`.

**Verified this session** by test rather than by reading — every branch above now has
a case: `test_duplicate_operation_sequences_are_rejected`,
`test_a_mold_belonging_to_another_product_is_rejected`,
`test_an_unusable_machine_status_is_rejected`, `test_an_archived_machine_is_rejected`,
`test_an_incompatible_machine_and_mold_pair_is_rejected`,
`test_a_compatible_machine_and_mold_pair_is_accepted`,
`test_an_inactive_product_cannot_receive_a_routing`,
`test_a_rate_beyond_the_column_scale_is_rejected`, `test_a_zero_cycle_time_is_rejected`,
`test_total_cycle_time_is_summed_with_decimal_arithmetic`, plus the HTTP-layer
`test_the_api_rejects_duplicate_sequences_with_a_field_error` and
`test_the_api_rejects_a_mold_that_is_not_available`.

## Item 4 — routing-change propagation to MRP

Evidence: H-06. **Implemented this session.**

`ProductionRoutingService.php:500-526` — new `propagateRoutingChange()` /
`requestAutomaticReplan()`.

- Before: create/update/duplicate called `recalculateActiveBom()` only. A routing
  carries cycle/setup time and the labor, machine and overhead rates, so a publish
  silently re-cost the BOM while leaving pending MRP plans and capacity built from
  the old figures.
- After: the BOM recalculation stays inline (costing reads the routing directly), and
  the replan is **durable** — `OutboxService::record(new MrpReplanRequested($salesOrderIds,
  $reason, auth()->id()), 'mrp:replan:routing:{id}:{reason}')` inside the same
  transaction as the routing rows. Reuses the existing, wired path
  (`OutboxEventCodec:128`, `AppServiceProvider:299` → `QueueMrpOnReplanRequested`),
  matching `BomService::requestAutomaticReplan()` rather than inventing a second
  mechanism. Scope comes from `MrpScopeResolver::salesOrderIdsForProduct()`, so a
  product with no active demand records nothing.
- The dedupe key is per **version** (a new id every publish) and per reason, so a
  crashed queue worker cannot lose the replan and a retry cannot double-plan.

Tests: `test_a_published_routing_records_a_durable_mrp_replan`,
`test_no_replan_is_recorded_when_no_active_sales_order_needs_the_product`.

## Item 5 — auditable routing history and lifecycle

Evidence: H-07. **Implemented this session.** The plan required an explicit product
decision; the decision taken is **immutable versions + audit log + explicit
reactivation, and no delete/archive surface at all**, recorded in the service
docblock at `ProductionRoutingService.php:22-45`.

- `api/app/Modules/Production/Models/ProductRouting.php:1-31` — added `HasAuditLog`
  and a class docblock naming the invariant and its index.
- `api/app/Modules/Production/Models/RoutingOperation.php:1-26` — added `HasAuditLog`.
- `ProductionRoutingService.php:192-241` — new `activate()`: puts a superseded version
  back into service under the same product lock as a publish, **without renumbering**
  (the version number is history). It is idempotent for the version already active,
  and it **re-validates the stored definition first** — a version whose mold has since
  been retired must not silently become the definition work orders and costing read.
  It also propagates downstream (item 4).
- `api/app/Modules/Production/Controllers/ProductionRoutingController.php:54-59` and
  `api/app/Modules/Production/routes.php:84` — `POST /production/routings/{routing}/activate`,
  gated on `production.routings.manage`.
- Soft deletes are deliberately **not** enabled even though migration 0444 added
  `deleted_at` to both tables: a soft-deleted active row still occupies the partial
  unique index, so "archiving" the active version would block the next publish.
  Documented at `ProductionRoutingService.php:31-45`.

Tests: `test_both_sides_of_a_version_handover_are_audited`,
`test_activate_puts_a_superseded_version_back_into_service`,
`test_activate_is_idempotent_for_the_version_already_in_service`,
`test_activate_refuses_a_version_whose_mold_is_no_longer_usable` (and asserts a
refused rollback leaves the active version alone).

## Item 6 — role matrix

Evidence: H-08, F-02. **Implemented this session.** Decision: **`production_manager`
is view-only on routings; `ppc_head` authors them**, matching the documented boundary.
Publishing a routing re-costs the product's BOM and re-generates work instructions —
that is a write, not an oversight action.

- `api/database/seeders/RolePermissionSeeder.php:558-571` — before:
  `$this->module('production')`, which swept in `production.routings.manage`. After:
  `$this->module('production', except: ['production.routings.manage'])`.
  `production.routings.view` is retained: the role must be able to read the plan it is
  running.
- `api/database/migrations/2026_08_26_020000_restrict_production_manager_routing_manage.php`
  — revokes the already-granted `role_permissions` row from deployed databases
  (`up()`), reversible (`down()`). Follows the pattern of
  `2026_08_26_000300_align_mrp_production_manager_read_permissions.php`, since the
  running database drifts from the seeders.
- `docs/AUTO-BROWSER-TESTS.md:119` — the §1.3 boundary row now names routings
  explicitly ("routings **view-only**" / "no `production.routings.manage`") so the doc
  and the seeder cannot silently disagree again.

Tests: `test_production_manager_may_read_routings_but_not_author_them`,
`test_ppc_head_owns_the_routing_write_surface`,
`test_view_permission_reads_routings_but_cannot_write_them` (index/show OK;
store/update/duplicate/activate all 403).
`DashboardDispatchTest` (19) and `WidgetSeedIntegrityTest` (10) were re-run to confirm
the seeder change causes no dashboard or role-default drift — all pass.

## Item 7 — real view/detail experience, manage actions gated

Evidence: F-02. **Implemented this session.**

- `spa/src/routes/productionRoutes.tsx:55-56` — `/production/routings/:id` before:
  `PermissionGuard permission="production.routings.manage"`. After:
  `production.routings.view`, matching the backend `GET /routings/{id}`. There was no
  read-only detail path at all: a view-only user could open the list but not any row.
- `spa/src/pages/production/routings/editor.tsx` — rewritten to serve three states
  from one component: create, edit-active (publishes a new version), and read-only.
  `readOnly = !canManage || isSuperseded` (`editor.tsx:126-129`). Read-only disables both
  fieldsets, omits Save / Add operation / Remove row entirely rather than rendering
  them disabled, and renders machine/mold as text instead of empty selects.
- `spa/src/pages/production/routings/index.tsx:93-114,118-128,159-172` — New routing,
  Duplicate and the empty-state call to action are now behind
  `can('production.routings.manage')`. Before, a view-only user saw all three and
  every click ended in a 403.
- The status chip now reads **Superseded** rather than "Inactive"
  (`index.tsx:78-84`, `editor.tsx`), which is what an immutable-version model means.
- A superseded version offers **Make active** (with a `ConfirmDialog` spelling out
  that work orders and BOM cost follow) and **Duplicate to edit** — the two supported
  ways to act on history.
- The edit toast now says a *new version* was published; before it said "Routing
  updated", which made the version jump on the next screen look like a bug.

## Item 8 — list filtering and historical-version discovery

Evidence: F-01, F-06. **Completed this session** (backend half was pre-existing).

- `ProductionRoutingService::list()` (pre-existing from the crashed session) reads
  `search` and matches product `part_number` / `name` via `ilike`. The list page was
  already emitting `search`; nothing read it, so the search box did nothing.
- `spa/src/api/production/routings.ts:7-15` (`RoutingListParams`) — before: `product_search?: string`, a
  parameter the backend never had. After: removed; `search` comes from `ListParams`,
  and `is_active` / `product_id` are declared. A comment records the trap.
- `spa/src/pages/production/routings/index.tsx:26-38,132-137` — added the
  Status filter (All / Active / Superseded) bound to `is_active` through the shared
  `FilterBar` contract, so superseded versions are intentionally reachable.
- `index.tsx:146-173` — the empty state is now filter-aware; a first-run "No routings
  yet — create one" message on a filtered list is actively wrong.

Test: `test_the_list_filters_by_product_text_and_active_state` (part number, product
name, `is_active=true`, `is_active=false`).

## Item 9 — editor surface and large-dataset behaviour

Evidence: F-03, F-04, F-05. **Implemented this session** in `editor.tsx`.

- **F-03** — the operation `description` is now editable: each operation renders a
  second full-width row with a `Textarea` bound to `operations.${i}.description`
  (max 500, counter). Before, the field was in the schema and the payload but had no
  control, so it could never be entered. It gets its own row rather than a thirteenth
  column because 500 characters do not fit a table cell.
- **F-04** — before: products, machines and molds each fetched `per_page: 100` with no
  search, no pagination and no visible loading/error state, so beyond 100 records
  valid selections were unreachable and a failed lookup looked like an empty control.
  After: the product lookup has a debounced server-side search
  (`useDebounce`, 300 ms, the pattern already used by
  `spa/src/pages/crm/complaints/create.tsx`), a "showing N of M — narrow the search"
  helper, a loading helper, and an error message. Molds are scoped by the selected
  product (`moldsApi.list({ product_id })`) — which the backend validation requires
  anyway — with "Select a product first" and "No usable mold for this product" states.
  Machines expose a retry on failure and a truncation count.
- **F-05** — the operations table wrapper was `overflow-hidden`, which clipped twelve
  columns at narrow widths. Now `overflow-x-auto` with `min-w-[62rem]`, matching the
  shared `DataTable` (`spa/src/components/ui/DataTable.tsx:424-425`).
- Also added: a duplicate-sequence `superRefine` on the Zod schema so the offending
  row is named client-side instead of the whole array, and in edit mode the fixed
  product renders as text with a hidden field rather than a disabled select whose
  value matched no option.

## Item 10 — dedicated routing test matrix

Evidence: D-02. **Implemented this session.**

`api/tests/Feature/Production/ProductionRoutingTest.php` — 28 tests / 89 assertions,
all passing. Covers CRUD authorization by permission *and* by seeded role, the active
/ version invariant at both service and database level, stale-edit and duplicate
races, operation sequence and decimal validation, resource status / archival /
ownership / compatibility, work-order provenance across an edit, the durable MRP
replan, the audit rows on both sides of a handover, the rollback lifecycle, and list
filtering.

---

## Verification run

Own database (never shared): `ogami_test_m052`.

```
docker compose exec -T -e DB_DATABASE=ogami_test_m052 api php artisan test --filter='ProductionRoutingTest'
  Tests: 28 passed (89 assertions)   Duration: 41.97s

docker compose exec -T -e DB_DATABASE=ogami_test_m052 api php artisan test \
  --filter='BomCosting|WoOperation|WidgetSeedIntegrity|DashboardDispatch'
  Tests: 6 failed, 36 passed (139 assertions)
```

The 6 failures are **not** in this module and **not** caused by these changes — see
the blocker section below.

Other gates, all clean:
- `php -l` on every changed PHP file.
- `npx tsc --noEmit` — no errors in any routing / production-route / routing-API file
  (pre-existing errors elsewhere in the tree belong to other sessions:
  `CommandPalette.tsx`, `pages/assets/detail.tsx`, `pages/return-management/detail.tsx`).
- `npx eslint src/pages/production/routings src/api/production/routings.ts src/routes/productionRoutes.tsx`
  — clean.
- `npm run audit:tokens` — `✓ token discipline clean — 783 files checked`.

`api/tests/Unit/RolePermissionSeederTest.php` (another session's untracked test, which
exercises the seeder this session edited) was **not** run: it contains no `production`
assertions, the seeder's role↔permission consistency is already covered by the passing
`DashboardDispatchTest` / `WidgetSeedIntegrityTest` runs above, and a concurrent
session was observed moving the blocker migration aside for its own run at the same
time — a second mover on the same file risks breaking that session's suite.

## Cross-module blockers encountered (NOT fixed — outside module scope)

1. **`api/database/migrations/2026_08_25_210000_enforce_one_active_holiday_per_date.php:42`**
   runs `DROP INDEX IF EXISTS holidays_date_name_unique`, but `0023_create_holidays_table.php:21`
   creates that name with `$table->unique(['date','name'])` — a PostgreSQL UNIQUE
   *constraint*, not a bare index. Postgres refuses with
   `SQLSTATE[2BP01] … cannot drop index holidays_date_name_unique because constraint
   holidays_date_name_unique on table holidays requires it`, so **`migrate:fresh`
   fails for the whole repo** and every `RefreshDatabase` test dies before reaching an
   assertion. Untracked, in-flight work from a concurrent session; the coordinator is
   fixing it centrally. Per coordinator instruction the file was moved aside for the
   duration of each test run and restored in the same command with a `trap`, then
   confirmed byte-identical (`diff -q` + `sha256sum -c`) after every run. It is
   present and unmodified.
2. **`api/app/Modules/MRP/Services/BomService.php:85-115`** — an in-flight edit moved
   `requestAutomaticReplan` inside the transaction and dropped the method's
   `return $bom;`, so `create()` returns nothing:
   `TypeError: BomService::create(): Return value must be of type Bom, none returned`.
   All 6 `BomCostingTest` failures are this one error raised in test setup, including
   `routing changes recalculate the active bom snapshot`. Nothing routing-side is
   reached. Left for the MRP session / coordinator.

## Residual verification note

Item 9's acceptance clause "verify keyboard/focus behaviour at narrow widths" was
**not** confirmed in a browser. The fix itself is the shared-`DataTable` treatment
(`overflow-x-auto` + `min-w`), and `docs/DESIGN-SYSTEM.md` geometry checks require
Chromium (Lightpanda fabricates `getBoundingClientRect`, per `CLAUDE.md`) against a
running app; the stack is not served in this environment and no routings Playwright
spec exists to extend. Everything else in item 9 is implemented and type/lint clean.

## Files changed

Backend:
- `api/app/Modules/Production/Services/ProductionRoutingService.php`
- `api/app/Modules/Production/Models/ProductRouting.php`
- `api/app/Modules/Production/Models/RoutingOperation.php`
- `api/app/Modules/Production/Controllers/ProductionRoutingController.php`
- `api/app/Modules/Production/routes.php` (routings block only)
- `api/database/seeders/RolePermissionSeeder.php` (`production_manager` only)
- `api/database/migrations/2026_08_26_020000_restrict_production_manager_routing_manage.php` (new)
- `api/tests/Feature/Production/ProductionRoutingTest.php` (new)

Frontend:
- `spa/src/api/production/routings.ts` (`routingsApi` / `RoutingListParams` only)
- `spa/src/pages/production/routings/index.tsx`
- `spa/src/pages/production/routings/editor.tsx`
- `spa/src/routes/productionRoutes.tsx` (routings routes only)

Docs:
- `docs/AUTO-BROWSER-TESTS.md` (§1.3 `production_manager` row)

Left in the working tree uncommitted, as instructed — five sessions share this tree.
