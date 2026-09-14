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

## Code-reading re-audit — 2026-09-14 (56e0d431)

### Recheck and Status

This is a read-only source audit of commit `56e0d431`. The required project
guidance, schema/design/seed references, roadmap, scope map, prior audit, the
Assets module, its SPA surfaces, and directly relevant tests were read. No
tests, Docker commands, artisan commands, browser checks, or application-code
changes were performed.

| Prior finding | Current status | Current evidence |
|---|---|---|
| AS-01 | Resolved in the normal disposal path | `AssetService::dispose()` catches up through the disposal month before derecognition (`api/app/Modules/Assets/Services/AssetService.php:397-405`); the monthly selector excludes assets disposed on or before the period end (`api/app/Modules/Assets/Services/DepreciationService.php:198-217`). `AssetDisposalMonthDepreciationTest.php:139-200,202-241` contains the intended seam coverage, but its direct disposal calls are currently blocked by AS-12. |
| AS-02 | Resolved in the intended open-period backfill path | Posted periods now accept supplemental rows for assets missing from the original run (`api/app/Modules/Assets/Services/DepreciationService.php:76-89,496-570`); the backfill regression covers repaired posted months and later plain runs (`AssetDepreciationBackfillRepairTest.php:96-151`). Closed-period refusal remains explicit (`:235-255`). |
| AS-03 | Resolved in application code; verification/documentation drift remains | Disposal request, approval, rejection, cancellation, and final execution are wired (`api/app/Modules/Assets/Services/AssetService.php:188-343,365-472`). The current seeder uses `finance_officer -> vice_president` (`api/database/seeders/WorkflowSeeder.php:152-157`), but several tests and `docs/SEEDS.md` still encode `system_admin`; see AS-12 and AS-09 below. |
| AS-04 | Resolved for recurring monthly runs; a disposal-tail evidence edge remains | The ordinary monthly path creates zero-value rows for a declining-balance tail (`api/app/Modules/Assets/Services/DepreciationService.php:464-493`) and the regression covers later runs (`AssetDepreciationBackfillRepairTest.php:176-233`). Disposal catch-up uses a different path and does not create that zero row; see AS-15. |
| AS-05 | Unresolved | Disposal still does not reconcile linked machine/mold/vehicle records (`api/app/Modules/Assets/Services/AssetService.php:365-471`); see the detailed finding below. |
| AS-06 | Mitigated | The full-month convention is now stated in the depreciation service comments and documented in disposal-month test intent (`api/app/Modules/Assets/Services/DepreciationService.php:39-43`; `AssetDisposalMonthDepreciationTest.php:139-200`). It remains a policy convention rather than a user-facing accounting-policy reference. |
| AS-07 | Resolved | The SPA sends the backfill flag and arms it after the completeness error (`spa/src/pages/assets/DepreciationRunner.tsx:35-53,84-94`); the endpoint routes it (`api/app/Modules/Assets/Controllers/AssetDepreciationController.php:69-80`). |
| AS-08 | Partially mitigated | Queue lifecycle hooks now record failed listener runs through `ChainListenerRunService` (`api/app/Common/Services/ChainListenerRunService.php:44-61,139-263`). The asset request is still staged without `chain:` context (`api/app/Console/Commands/RequestMonthlyDepreciation.php:64-68`), so it has generic listener telemetry rather than a domain `chain_step_runs` record. This is a residual observability limitation, not the former log-only state. |
| AS-09 | Unresolved, changed shape | The current seeder and current project policy use `vice_president`, while `docs/SEEDS.md:250` still says `system_admin`. The stale test assumptions make this operationally significant; see AS-12. |
| AS-10 | Accepted scope cut, unchanged | Transfer routes remain deliberately hidden (`api/app/Modules/Assets/routes.php:41-54`), while transfer pages and client methods remain retained. This is consistent with the roadmap's hide/deprecate decision, not a newly exposed application route. |

### Findings

#### AS-05 — Asset disposal leaves operational reverse links active

- **Category:** Gap
- **Severity:** Medium
- **Effort:** M
- **Location:** `api/app/Modules/Assets/Services/AssetService.php:365-471`; `api/app/Modules/MRP/Models/Mold.php:23-45`; `api/app/Modules/SupplyChain/Models/Vehicle.php:19-31`
- **Evidence/reproduction:** `dispose()` posts the derecognition journal and changes only the `assets` row. It neither blocks disposal of an asset referenced by a machine, mold, or vehicle nor clears/retires the operational reference. `molds.asset_id` is fillable but has no relationship or FK in the model; `Vehicle` does not expose `asset_id` in its fillable fields even though the migration adds the column. Dispose an asset linked to an active mold or machine, then read the operational record: it still points to the disposed asset and can continue to be treated as active equipment. This remains a cross-module MRP/SupplyChain finding and should be resolved at consolidation rather than patched only in Assets.

#### AS-09 — Seed/reference/test contract disagrees on the final disposal approver

- **Category:** Bad practice
- **Severity:** Low
- **Effort:** S
- **Location:** `docs/SEEDS.md:250`; `api/database/seeders/WorkflowSeeder.php:152-157`; `api/database/seeders/RolePermissionSeeder.php:368-375`
- **Evidence/reproduction:** The active workflow definition is `finance_officer -> vice_president`, matching the current policy that `system_admin` is IT-only. The seed reference still documents `finance_officer -> system_admin`, and the RolePermissionSeeder comment still describes the final step as `system_admin`. A seed/reference comparison or operator following `docs/SEEDS.md` will provision or expect the wrong approval owner. Update the documentation and stale comments together with the tests; do not revert the application workflow to the IT role.

#### AS-11 — Pre-2020 acquisition dates can brick every later depreciation run

- **Category:** Stuck process
- **Severity:** High
- **Effort:** S/M
- **Location:** `api/app/Modules/Assets/Requests/StoreAssetRequest.php:31-40`; `api/app/Modules/Assets/Services/DepreciationService.php:143-169,223-265,599-608`
- **Evidence/reproduction:** Asset creation accepts any historical `acquisition_date` with only `before_or_equal:today`; the depreciation engine supports periods only from 2020 through 2100. Create an asset acquired in `2019-12`, then run a normal current-month depreciation: `assertPriorPeriodsComplete()` starts at the acquisition month and raises that `2019-12` is missing. The explicit backfill starts at the earliest acquisition month and calls `runForMonth()` for 2019, which fails the supported-period guard. The asset therefore prevents the company-wide monthly run from progressing, while the provided backfill cannot repair it. Either constrain acquisition dates to the supported accounting horizon or define and implement a legacy-opening-balance path before accepting such rows.

#### AS-12 — Checked-in disposal regression tests still exercise the retired execution contract

- **Category:** Broken process
- **Severity:** Medium
- **Effort:** S
- **Location:** `api/tests/Feature/Assets/AssetDisposalApprovalWorkflowTest.php:86-92,149-152,263-270,350-354`; `api/tests/Feature/Assets/AssetDisposeDoublePostingRaceTest.php:80-85,114-121,149-151`; `api/tests/Feature/Assets/AssetUpdateVsDisposeRaceTest.php:75-84`; `api/tests/Feature/Assets/AssetDisposalMonthDepreciationTest.php:48-51,149-153,211-215,255-259,285-289`; `api/tests/Feature/Assets/AssetDisposalJournalLinesTest.php:287-291,317-321`
- **Evidence/reproduction:** The active `WorkflowSeeder` makes `vice_president` the second step, but the approval helpers still pass `system_admin`; `ApprovalService::userMayActFor()` rejects that role (`api/app/Common/Services/ApprovalService.php:222-235`). Separately, `AssetService::dispose()` now requires a fully approved request (`api/app/Modules/Assets/Services/AssetService.php:365-376`), while `AssetDisposalMonthDepreciationTest` does not seed `WorkflowSeeder` and calls `dispose()` directly, and the two final `AssetDisposalJournalLinesTest` cases do the same. These cases are deterministic contract mismatches, not concurrency flakiness: they either fail at the wrong-role check or fail before journal assertions with `requires an approved disposal request`. The test suite is therefore not a reliable verification gate at this commit, and the prior audit's passing-test statement was not re-established.

#### AS-13 — Depreciation run endpoint exposes a raw journal integer ID

- **Category:** Bad practice
- **Severity:** Medium
- **Effort:** S
- **Location:** `api/app/Modules/Assets/Services/DepreciationService.php:76-85,127-131,515-519`; `api/app/Modules/Assets/Controllers/AssetDepreciationController.php:69-80`
- **Evidence/reproduction:** Asset resources and depreciation-history rows publish HashIDs, but the run service returns `journal_entry_id` as an integer and the controller returns the result directly. A successful `POST /api/v1/asset-depreciations/run` can therefore return `data.journal_entry_id: 123`, exposing the internal primary key and contradicting the project-wide no-raw-ID API rule. Return the journal hash or omit this internal field from the run command response; keep integer IDs internal to accounting writes.

#### AS-14 — Disposal approve/reject buttons are permission-gated, not step-gated

- **Category:** Gap
- **Severity:** Medium
- **Effort:** S/M
- **Location:** `spa/src/pages/assets/detail.tsx:211-243`; `api/app/Modules/Assets/Resources/AssetResource.php:58-68`
- **Evidence/reproduction:** The detail page renders Approve and Reject for every user with `assets.dispose.approve`, regardless of whether that user's role is the current workflow step. After a finance user approves step 1, the same user can reload the asset and is still offered the buttons; clicking produces a backend 403 because the next step belongs to `vice_president`. The API resource returns approval records but no current-step/`is_pending_my_approval` signal. Follow the documented approval-action pattern: expose the actionable user/step state from the backend or reuse the approval-board contract, and hide or explain unavailable actions instead of presenting a guaranteed refusal.

#### AS-15 — Disposal catch-up omits zero-value declining-balance month evidence

- **Category:** Gap
- **Severity:** Low
- **Effort:** S
- **Location:** `api/app/Modules/Assets/Services/DepreciationService.php:184-193,293-325,464-493`
- **Evidence/reproduction:** The ordinary monthly path calls `pendingRow()` and records a `0.00` row when a declining-balance charge rounds to zero while residual value remains. Disposal catch-up instead calls `calculateRow()` directly; when that returns `null`, `postMissingMonthForAsset()` returns without inserting any row. Dispose a declining-balance asset with a small residual after its last non-zero month and inspect depreciation history: the disposal-month/prior missing month has no ledger-evidence row, even though the disposal path claims to catch up every month through disposal. The disposal JE remains balanced, so this is an audit-history completeness gap rather than the former company-wide run brick.

### Clean Areas

- AS-01's disposal-month double-counting path is addressed by catch-up plus monthly exclusion; the test intent covers both already-posted and unrun months, subject to the stale approval contract in AS-12.
- AS-02's posted-period repair now uses supplemental journals without editing the original consolidated journal, and the SPA can invoke the repair arm.
- AS-03's application path has a locked request/approval/execute transaction, requester self-approval protection, rejection/cancellation handling, and a fully-approved execution guard.
- Salvage bounds are enforced in both request and service paths with exact decimal-string comparison (`api/app/Modules/Assets/Requests/StoreAssetRequest.php:51-78`; `api/app/Modules/Assets/Services/AssetService.php:159-176`).
- Asset detail depreciation journals are eager-loaded rather than resolved per history row (`api/app/Modules/Assets/Services/AssetService.php:58-71`; `AssetDetailEagerLoadTest.php:38-91`).
- HashID handling is present in published asset/depreciation resources and in department/asset transfer request decoding; the raw run-summary ID in AS-13 is the remaining identified exception.
- Disposal and depreciation financial mutations use transaction boundaries and row/unique-key fences, including the asset update/dispose and duplicate-dispose guards.
- SPA routing keeps assets behind the main `AuthGuard`, `ModuleGuard`, and per-route `PermissionGuard` (`spa/src/App.tsx:47-71`; `spa/src/routes/assetsRoutes.tsx:14-28`), and the active list/detail/form pages have loading, error, empty/data, retry, mutation feedback, and draft-safety handling.

### Verification Limits

- No tests, Docker, artisan, queue worker, database, migration, or browser execution was performed, per scope. Findings are source-reading and deterministic call-path analysis only.
- The stale test contracts in AS-12 mean the prior audit's reported test pass count cannot be treated as current verification without first reconciling the tests to the current `vice_president` workflow and approval-gated execution contract.
- Accounting-period behavior, queue failure visibility in deployed operator surfaces, HashID configuration, and MRP/SupplyChain disposal reactions were inspected only at the Assets seam; cross-module remediation belongs to the consolidation pass.
- Existing unrelated worktree modifications in other audit files and `spa/playwright.config.ts` were not changed or used as evidence for this unit.

No application code, migration, test, registry, or roadmap file was modified. Only this re-audit section was appended to `audit/assets.md`.
