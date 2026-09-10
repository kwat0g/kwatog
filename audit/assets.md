# Audit: assets — 2026-09-06

## Summary

The module's core machinery is unusually hardened: depreciation runs are
serialized by a locked asset set plus an `asset_depreciation_runs` ledger
(double-run same month is a verified no-op), the UNIQUE (asset_id, year, month)
is respected, salvage≥cost is clamped at request AND service level, disposal
JEs handle zero-proceeds/never-depreciated/zero-cost cases with explicit
refusals, and race conditions (double dispose, update-vs-dispose) are
lock-guarded and regression-tested. 38 Asset-filtered tests pass (41s). Two
subsystems disagree at the disposal seam, which produces wrong ledger amounts:
**a mid-month disposal is depreciated again for the disposal month (and for any
unrun prior months) after the disposal JE already reversed all accumulated
depreciation and derecognised the cost** — over-stated expense and a phantom
credit in the accumulated-depreciation account for every partially-depreciated
disposal. Second structural break: **a backdated asset entered after its
acquisition month was already posted can never catch up** — normal runs refuse
("run the explicit backfill workflow first"), but backfill early-returns on
posted periods, so the monthly cron dies for ALL assets until manual DB repair.
Disposal also bypasses the seeded 4-step `asset_disposal` workflow (documented
RESERVED), posting a ledger JE on a single `assets.dispose` permission.

## Findings

| ID | Category | Sev | Effort | Title | Location | Evidence/Repro |
|---|---|---|---|---|---|---|
| AS-01 | Broken process | Critical | M | Disposal JE and monthly run double-count the disposal month (and any lagging prior months) | api/app/Modules/Assets/Services/DepreciationService.php:184-200; api/app/Modules/Assets/Services/AssetService.php:178-271 | `assetsInServiceFor()` INCLUDES disposed assets with `disposed_date >= periodStart`, and `dispose()` performs no depreciation catch-up. Repro: asset cost 12,000, salvage 0, 5y SL (200/mo), accumulated 3,000 through Aug; dispose 2026-09-15 for 5,000 → JE reverses 3,000 accum + removes 12,000 cost, books 4,000 loss. Oct-1 run for September still includes the asset (`disposed_date 09-15 >= 09-01`) → posts another 200 DR expense / CR accum and sets `asset.accumulated_depreciation` = 3,200. Ledger: total expense for asset = 3,200 dep + 4,000 loss = 7,200 vs economic 7,000; accum-dep account carries a 200 credit belonging to no asset; asset detail's accumulated (3,200) no longer reconciles with the ledger (200 net). Same defect for any month left unrun before disposal (cron outage, new asset): those runs still include the disposed asset and post after the reversal. Normal use, every mid-month disposal. No test covers run-after-dispose (AssetDepreciationCommandTest has zero disposal cases). |
| AS-02 | Stuck process | High | M | Backdated asset entered after a posted period permanently blocks company-wide depreciation; backfill cannot repair it | api/app/Modules/Assets/Services/DepreciationService.php:48-50,59-67,149-179,205-240 | `StoreAssetRequest` allows `acquisition_date` arbitrarily in the past. If January is already posted and a January-acquired asset is entered in March: March run (`assertPriorPeriodsComplete`) throws "Depreciation period 2026-01 is missing … run the explicit backfill workflow first". `runBackfillTo()` walks months but each posted month early-returns at `$run->journal_entry_id !== null` (lines 59-67) WITHOUT adding the missing row — backfill reports success having done nothing for January, and every subsequent non-backfill run throws again forever. The monthly cron (`RunMonthlyDepreciationJob`, no backfill) fails 3× and dies; one retro row bricks depreciation for ALL assets. Only recovery is manual DB repair + hand-made JE. `DepreciationRunner.tsx` cannot even trigger the backfill arm (controller accepts `backfill`, SPA never sends it). |
| AS-03 | Missing | High | M | Disposal bypasses the seeded 4-step `asset_disposal` approval; single permission posts the JE | api/app/Modules/Assets/Services/AssetService.php:178-271; api/app/Modules/Assets/routes.php:24; api/database/seeders/WorkflowSeeder.php:129-137 | Spec/SEEDS.md: asset_disposal = dept_head → manager → finance_officer → vice_president. WorkflowSeeder seeds the definition but explicitly marks it RESERVED ("Wire or drop before pilot"). `dispose()` is gated only by `assets.dispose` (finance_officer + system_admin, RolePermissionSeeder.php:544) and posts a ledger JE immediately — no ApprovalService::submit, no approval_requests row, no second pair of eyes on gain/loss recognition. The PS-01 defect class is moot here only because the chain was never wired. Seeded step roles also differ from docs/SEEDS.md (production_manager/system_admin vs manager/vice_president). |
| AS-04 | Risk | Medium | S | declining_balance assets asymptote below one centavo and then brick all runs | api/app/Modules/Assets/Models/Asset.php:87-99; api/app/Modules/Assets/Services/DepreciationService.php:245-266,205-240 | 200% DB monthly = round2((book−salvage)×2/life/12) shrinks geometrically and never terminates at useful-life end. Once book−salvage < ~0.06–0.15 (method selectable in the create form), monthly rounds to 0.00 while remaining > 0: `calculateRow` returns null forever (no row posted), but `assertPriorPeriodsComplete` still sees accumulated < depreciable and demands a row for every month → every later run (incl. cron, all assets) throws. Same company-wide blast radius as AS-02 with no repair path. Standard fix: switch to straight-line at the crossover or force-book the residual when monthly rounds to zero. |
| AS-05 | Gap | Medium | S | Disposal ignores reverse links: machines/molds/vehicles keep pointing at a disposed asset | api/app/Modules/Assets/Services/AssetService.php:178-289 | `dispose()` never checks `machines.asset_id` / `molds.asset_id` / `vehicles.asset_id`; nothing nulls or blocks them. `machines.asset_id`/`vehicles.asset_id` are `nullOnDelete()` FKs, but Assets only ever SOFT-deletes, so the FK action never fires and links dangle. `molds.asset_id` has NO FK at all (0076_create_molds_table.php:34; the FK 0104's comment promises was never added). An active mold/machine can sit on a derecognised asset; the Maintenance MWO polymorphic seam is equally unaware. Cross-module: MRP, Maintenance. |
| AS-06 | Gap | Low | S | Full-month convention undocumented and untested | api/app/Modules/Assets/Services/DepreciationService.php:184-200 | Any asset in service for any day of a month takes a FULL month (acquired Jan 31 → full January; acquired+disposed within one month → one full month booked). This is a defensible policy but nowhere stated (migration comment implies simple (cost−salvage)/(life×12)), untested, and it interacts badly with AS-01. |
| AS-07 | Gap | Low | S | SPA cannot run the backfill workflow its own error message points to | spa/src/pages/assets/DepreciationRunner.tsx:26-34; api/app/Modules/Assets/Controllers/AssetDepreciationController.php:69-81 | Controller validates a `backfill` boolean; the modal never sends it. When AS-02's guard fires, the operator's only tool is artisan on the server. |
| AS-08 | Risk | Low | S | Cron permanent failure surface is log-only | api/app/Modules/Assets/Jobs/RunMonthlyDepreciationJob.php:55-62; api/app/Modules/Assets/Listeners/RunMonthlyDepreciationOnRequested.php:49-57; api/app/Console/Commands/RequestMonthlyDepreciation.php:64-72 | No exit-0-while-throwing (command returns FAILURE properly, job throws honestly — good), but outbox staging records no chain ledger (`record(..., dedupeKey:)` without `chain:`), so a permanently failed period (AS-02/AS-04) is visible only via Log::error after 3 retries. Given those stuck states brick every later month, this needs an alert or a chain-step row. |
| AS-09 | Bad practice | Low | S | docs/SEEDS.md asset_disposal roles mismatch the seeder | docs/SEEDS.md:250; api/database/seeders/WorkflowSeeder.php:131-136 | Docs say manager + vice_president; seeder uses production_manager + system_admin (no vice_president role exists). Fix when wiring AS-03. |
| AS-10 | Other | Low | — | `under_maintenance` status is orphaned; scope-cut transfer code kept | api/app/Modules/Assets/Enums/AssetStatus.php:10; api/app/Modules/Assets/routes.php:33-47 | No code anywhere writes `under_maintenance` (grep: enum + dashboard readers only), so the SPA chip variant is unreachable. AssetTransfer routes are deliberately commented out (2026-08-08 scope cut) with controller/service/SPA pages/API client retained per hide-access policy — documented, but the SPA `assetTransfersApi` targets 404s. Also `DELETE /assets` + `PATCH /assets/{id}/restore` have no UI surface. |

### Detail — AS-01 (disposal month depreciated after derecognition)

The two halves of the module encode contradictory accounting. `dispose()` (on the
disposal date, mid-month) journals: DR Accumulated depreciation for the FULL
stored balance, CR PPE at cost, DR/CR loss/gain against proceeds. It assumes
depreciation STOPS at `disposed_date`. `assetsInServiceFor()` says the opposite:
a disposed asset with `disposed_date >= periodStart` is still "in service" for
the whole month, so the month-end run books one more full monthly charge and
increments `asset.accumulated_depreciation` — after the balance was already
reversed out of the ledger. Net effect per mid-month disposal of a
partially-depreciated asset: depreciation expense overstated by one monthly
charge, accumulated-depreciation GL account left with a credit that belongs to
no asset, and the asset register's accumulated field diverges from the ledger.
Worse ordering variant: if any prior month was unrun at disposal time (cron
outage, backdated asset), those months ALSO post after the reversal, since the
run only excludes disposals dated before the period start. Fix must make both
sides agree — either exclude the disposal month (and force catch-up through the
prior month inside `dispose()`'s transaction) or compute the disposal JE from
accumulated INCLUDING the disposal month. Needs a regression test pairing
`AssetService::dispose` with a subsequent `runForMonth` — currently zero
coverage of this seam.

### Detail — AS-02 (retroactive asset = permanent pipeline brick)

The guard exists for the right reason (don't post month N on a stale accumulated
balance), but its remedy is fictional. `runForMonth` on an already-posted period
early-returns on the run ledger's `journal_entry_id` regardless of `allowBackfill`
(DepreciationService.php:59-67), so `runBackfillTo` can never insert the missing
row for the retro asset; meanwhile `assertPriorPeriodsComplete` demands a row for
every in-service month from acquisition. State is absorbing: once entered, every
plain run — including the scheduled job for ALL assets — throws the same
BusinessRuleException monthly, and neither the SPA runner (no backfill toggle),
the artisan backfill, nor any endpoint can clear it. Suggested direction: a
catch-up path that posts a supplemental JE + row for assets missing from a
posted period (or refuses backdated entry before the last unposted month), plus
a test shaped exactly like this repro.

## Cross-module flags

- **SupplyChain (fleet):** `ZzM045FleetProbeTest` prints `create-with-asset_id http=201 resulting asset_id=NULL` — vehicle creation silently drops the asset link. Seam broken on their side (api/tests/Feature/SupplyChain/ZzM045FleetProbeTest.php).
- **MRP:** `molds.asset_id` has no FK (0076_create_molds_table.php:34) — orphaned links possible even without soft deletes; MRP never reacts to asset disposal (AS-05).
- **Maintenance:** MWOs/machines are unaware of asset disposal; `under_maintenance` is never set by anyone, so the asset status and machine maintenance states are disconnected (AS-05/AS-10).
- **Accounting:** disposal JE uses `reference_type = Asset::class` while depreciation uses `'asset_depreciation'` — free-form today, but JE reference filtering/display has two shapes for one module.

## What was NOT checked

- No dynamic repro of AS-01/AS-02 (code-path analysis only; existing 38 tests pass and none exercise these seams).
- JournalEntryService internals, accounting-period close logic, and the outbox dispatcher recovery loop were read only at the seam.
- AssetTransferService logic beyond confirming routes are dead (scope-cut 2026-08-08); its tests still run and pass.
- `HasAuditLog` coverage on asset mutations; HashIDs config specifics.
- Dashboard AssetWidgetAnalytics internals (read-only dependency).
