# M059 — Calibration / Quality Analytics Audit Report

Audit session: 2026-08-27 Asia/Manila
Claimed card: M059 / `quality/calibration-quality-analytics`
Final session status: 📋 Plan Ready
Scope: calibration register and due-status automation, defect Pareto / inspection-summary analytics, Cp/Cpk capability analytics, and their directly corresponding Quality UI. The registry, inherited module artifacts, dependencies, implementation, tests, git diff, and mtimes were read before review. Dependency modules were read only.

The coordinator registry was not regenerated or edited. M059 was claimed atomically with `audit/scripts/claim-module.sh quality calibration-quality-analytics`; the preferred card was available, so the M046 fallback was not used.

## Verification snapshot

- Backend focused suite passed: **36 tests, 147 assertions** using only `DB_DATABASE=ogami_test_m059_roll_b`. Covered analytics boundaries, calibration boundaries/register/race behavior, inspection summary, inspection-spec boundaries, and `SpcService` math.
- PHP syntax lint passed for the M059 Quality services/controllers/requests/routes/models and the focused tests.
- Targeted SPA ESLint passed with `--max-warnings 0` for the M059 API clients, pages, routes, sidebar, and types.
- `spa` `npm run typecheck` passed.
- No browser walk was run in this session; role smoke coverage remains a deferred acceptance gate.
- Before this session, the M059 audit files had no target diff against `HEAD`; unrelated worktree changes were preserved.

## Inherited finding disposition

The prior report was revalidated against the current source rather than copied forward.

| Prior finding | Current disposition |
|---|---|
| M059-B01, B02 | Resolved and covered by the focused analytics tests: Pareto uses an ungrouped denominator and terminal `passed`/`failed` populations in `api/app/Modules/Quality/Services/DefectParetoService.php:85-112,151-185`. |
| M059-B03, B04 | Resolved at the capability API boundary: product/spec ownership, active-spec checks, current-revision readings, and terminal inspection filtering are enforced in `api/app/Modules/Quality/Controllers/CapabilityController.php:31-40,66-99` and `api/app/Modules/Quality/Services/SpcService.php:130-212`. |
| M059-B05, B06 | Resolved for calibration event dates and explicit null frequency: `api/app/Modules/Quality/Requests/RecordCalibrationRequest.php:11-20`, `api/app/Modules/Quality/Services/CalibrationService.php:50-72,121-126`, and `api/app/Modules/Quality/Requests/StoreCalibrationRecordRequest.php:22-31`. |
| M059-B07 / P02 | The insufficient-sample API contract is now typed 422 and the capability page has an actionable no-data state at `api/app/Modules/Quality/Controllers/CapabilityController.php:94-99` and `spa/src/pages/quality/capability/index.tsx:131-140,234-241`. Zero variance remains intentionally guarded, with the distinction deferred as wording/product work. |
| M059-I01, I02 | Still open below: frequency-edit semantics and historical analytics for archived/inactive specifications remain policy decisions. |
| M059-I03 | Resolved: malformed/expired analytics product hashes fail closed with a field-level validation error at `api/app/Modules/Quality/Controllers/AnalyticsController.php:65-76`. |
| M059-M01 / P03 | Resolved in the SPA surface: calibration list/create/edit/record routes and navigation exist at `spa/src/routes/qualityRoutes.tsx:23-49`, `spa/src/components/layout/Sidebar.tsx:422-466`, and `spa/src/pages/quality/calibration/`. |
| M059-M02 | Partially resolved: focused backend coverage exists, but the M059 SPA/browser coverage remains missing below. |
| M059-P01 / P04 | Resolved: dashboard KPI retry states and the post-scope-cut COPQ documentation/fixture cleanup were revalidated in the inherited artifacts. |

## Discovery

- Calibration API and permissions: `api/app/Modules/Quality/routes.php:23-35`; scheduled status refresh: `api/app/Console/Commands/CheckCalibrationDue.php:10-25` and `api/routes/console.php:318-322`.
- Analytics and capability API: `api/app/Modules/Quality/routes.php:140-177`, `api/app/Modules/Quality/Controllers/AnalyticsController.php`, `CapabilityController.php`, `DefectParetoService.php`, and `SpcService.php`.
- Calibration SPA: `spa/src/api/quality/calibration.ts`, `spa/src/pages/quality/calibration/index.tsx`, `form.tsx`, `spa/src/routes/qualityRoutes.tsx:42-49`, and `spa/src/components/layout/Sidebar.tsx:432-452`.
- Capability SPA: `spa/src/pages/quality/capability/index.tsx`; dashboard analytics presentation: `spa/src/pages/quality/dashboard.tsx:89-179`.
- Registry metadata lists `system_admin`, `qc_inspector`, and `production_manager` for M059 at `audit/00-MODULE-REGISTRY.md:56`.

## Findings

### Broken

#### M059-B08 — Capability Study is reachable under a Quality permission but its required data routes reject intended Quality roles

Evidence: the SPA route and sidebar expose `/quality/capability` with only `quality.inspections.view` at `spa/src/routes/qualityRoutes.tsx:74-76` and `spa/src/components/layout/Sidebar.tsx:448-452`. The page then calls CRM products and Quality product-spec endpoints at `spa/src/pages/quality/capability/index.tsx:98-110`; those endpoints require `crm.products.view` and `quality.specs.view` at `api/app/Modules/CRM/routes.php:27-29` and `api/app/Modules/Quality/routes.php:62-63`. The seeded role definitions grant `production_manager` only `quality.view`, `quality.inspections.view`, and `quality.ncr.view` at `api/database/seeders/RolePermissionSeeder.php:576-585`, while `qc_inspector` receives the Quality module permissions but no CRM product-view permission at `:682-697`; the catalog defines these as separate permission groups at `:302-350`. The same mismatch was confirmed against the seeded role rows on `ogami_test_m059_roll_b`.

Impact: the route is advertised as a Quality capability screen, but a QC inspector cannot load the product picker and a production manager cannot load the dependent product/spec data. A role can reach a page that is functionally 403-blocked by its own dependencies. Decide whether to provide a Quality-scoped read endpoint or explicitly grant the cross-module reads; do not silently widen role permissions.

#### M059-B09 — A successful capability study can be hidden when the thresholds/options query fails

Evidence: `CapabilityStudyPage` destructures only `data` from the options query at `spa/src/pages/quality/capability/index.tsx:95-96`, sets a successful mutation result and success toast at `:123-130`, but renders the entire result only when both `result` and `thresholds` are truthy at `:243-245`. There is no options-query loading, error, or retry state between those points.

Impact: a transient failure of `/quality/spc/charts/options` can produce a “Capability study completed” toast while suppressing the returned Cp/Cpk result and giving the user no recovery path. The page needs an independent options state and a defined rendering contract for a study result whose interpretation thresholds are unavailable.

#### M059-B10 — Calibration create/update accepts an impossible future history and inconsistent date pair

Evidence: the create/update request accepts both date fields as unconstrained nullable dates at `api/app/Modules/Quality/Requests/StoreCalibrationRecordRequest.php:23-27`. `CalibrationService::create()` persists the supplied dates at `api/app/Modules/Quality/Services/CalibrationService.php:21-31`, while `update()` carries them through without a domain date invariant at `:34-43`. The separate record-event path rejects future dates at `:56-60`, proving the invariant is enforced inconsistently. Nothing prevents a future `last_calibration_date`, a `next_calibration_date` before the last date, or an arbitrary next date unrelated to the selected frequency.

Impact: the register can claim an instrument was calibrated in the future or display a schedule that contradicts its own history. Add request and service-level date-order/history guards and focused 422 tests before relying on the register as an IATF control.

### Missing

#### M059-M02 — M059 SPA and browser regression coverage is still missing

Evidence: the isolated backend suite now covers the service/API boundary, for example `api/tests/Feature/Quality/QualityAnalyticsBoundaryTest.php:21-30`, but no dedicated test file exists for `spa/src/pages/quality/calibration/`, `spa/src/pages/quality/capability/`, or `spa/src/pages/quality/dashboard.tsx`. The existing Quality SPA test only exercises the inspection-spec editor and mocks the capability API at `spa/src/pages/quality/inspection-specs/editor.test.tsx:5-34`; the existing Quality E2E file is for the separate forecast dashboard at `spa/e2e/dashboard-forecast-quality.spec.ts:1-13`. No calibration/capability M059 role smoke path was run during this audit.

Impact: the role/permission dependency defect, silent options failure, calibration state transitions, and defect-label mismatch can regress while backend tests remain green. Add page tests for loading/error/empty/data states and browser checks for QC, production-manager view-only behavior, and calibration manage actions.

### Incomplete

#### M059-I01 — Changing calibration frequency leaves rescheduling semantics undefined

Evidence: `CalibrationService::update()` merges the stored row with the patch at `api/app/Modules/Quality/Services/CalibrationService.php:34-43`; `withDerived()` recalculates status from the existing/provided next date but never derives a new next date from a changed frequency at `:105-118`. The form exposes both frequency and next date for editing at `spa/src/pages/quality/calibration/form.tsx:118-129`.

Impact: changing a 365-day interval to 30 days may leave the old next due date in place. Decide whether a frequency change reschedules from the last completed calibration or requires an explicit next-date edit, then encode and test that policy.

#### M059-I02 — Historical analytics policy for archived/inactive specifications is not consistent across capability surfaces

Evidence: capability options intentionally restrict parent specs to active, non-deleted records at `api/app/Modules/Quality/Controllers/CapabilityController.php:33-40`, but direct SPC reads are routed with `withTrashed()` at `api/app/Modules/Quality/routes.php:58-60`; `InspectionSpecController::spcData()` delegates to `SpcService::computeForSpec()` at `api/app/Modules/Quality/Controllers/InspectionSpecController.php:101-114`, which loads the spec with `withTrashed()` at `api/app/Modules/Quality/Services/SpcService.php:130-141`.

Impact: a user can receive different answers depending on whether the same historical spec is reached through Capability Study or the spec detail SPC path. Choose whether archived data is a supported historical read, then align options, direct reads, permissions, and tests.

#### M059-I03 — Calibration update is transaction-wrapped but not protected against stale-snapshot overwrites

Evidence: `CalibrationService::update()` fills from `$record->toArray()` and saves without re-reading or locking the authoritative row at `api/app/Modules/Quality/Services/CalibrationService.php:34-43`. The adjacent `recordCalibration()` path explicitly locks and re-reads the row at `:50-72` to prevent stale regression.

Impact: two concurrent PATCH requests can each start from an old representation and the later save can overwrite fields changed by the first request. Use a lock or an optimistic-concurrency contract, and add a concurrent-update regression test.

#### M059-I04 — Retired calibration records still expose a Record action with contradictory service semantics

Evidence: the list renders Edit and Record actions for every manageable row, including `status=retired`, at `spa/src/pages/quality/calibration/index.tsx:71-91`. The service docblock says recording “resets status to active” at `api/app/Modules/Quality/Services/CalibrationService.php:46-48`, but `statusFor()` explicitly preserves `Retired` at `:128-134`; the record path still updates the last/next dates at `:69-72`.

Impact: an operator can record a calibration on retired equipment, changing its history while it remains retired, with no clear reactivation decision. Reject recording retired instruments or provide an explicit reactivation flow, then align the UI, service comment, and tests.

### Polish

#### M059-P05 — Quality defect Pareto tooltip reports defect counts as downtime minutes

Evidence: the Quality dashboard maps `defect_count` into the generic chart’s `minutes` field while passing `valueLabel="Defects"` at `spa/src/pages/quality/dashboard.tsx:101-107`. The shared chart still names that bar “Downtime” and formats it with `formatMinutes()` at `spa/src/components/charts/DowntimeParetoChart.tsx:62-78`.

Impact: hovering a Quality defect bar can show values such as `1m` / “Downtime” instead of a defect count. Give the chart a semantic value formatter/label or use a Quality-specific chart presentation; do not change the shared component without checking its other consumers.

#### M059-P06 — Calibration edit loading/error states do not use the standard query-state components

Evidence: the edit form returns raw text-only `div` states at `spa/src/pages/quality/calibration/form.tsx:96-100`, while the list and capability pages provide structured skeleton/error/empty states at `spa/src/pages/quality/calibration/index.tsx:128-145` and `spa/src/pages/quality/capability/index.tsx:168-241`.

Impact: the edit flow has weaker visual hierarchy, retry affordance, and accessibility consistency than the rest of the M059 surface. Use the shared skeleton/query-error primitives in the normal UI polish pass.

## Handoff recommendation

Do not mark M059 verified and do not modify implementation in this session. The ordered plan is dominated by separate-recommended work: a cross-module RBAC/data-contract decision, calibration domain policy/concurrency, historical SPC policy, and missing browser/page coverage. The two small same-session candidates (options-query state and Pareto tooltip semantics) are intentionally deferred because the total plan is not small.

---

# Re-audit — 2026-08-30 Asia/Manila

Claimed card: M059 / `quality/calibration-quality-analytics` (`claim-module.sh` → `CLAIMED`).
Final session status: 🔁 Needs Re-audit.
Prior history was read before anything else (`audit-report.md`, `action-plan.md`,
`fix-log.md`, then `git log -30` and the working tree over the module's paths).
The coordinator registry was **not** regenerated. Dependency modules were read
only. Working tree was clean at claim time, so everything the prior sessions
wrote is committed.

## What the prior sessions had actually landed

Verified against source and by re-running, not inferred from the log:

| Prior finding | Disposition now | Evidence |
|---|---|---|
| B01, B02 (Pareto denominator, terminal population) | **Confirmed fixed** | ungrouped `count()` at `api/app/Modules/Quality/Services/DefectParetoService.php:112`; `QualityAnalyticsBoundaryTest` green |
| B03, B04 (capability ownership, terminal readings) | **Confirmed fixed** | `api/app/Modules/Quality/Controllers/CapabilityController.php:66-99`, `api/app/Modules/Quality/Services/SpcService.php:173-213` |
| B05, B06 (future record date, null frequency) | **Confirmed fixed** | `RecordCalibrationRequest.php:19`, `CalibrationService::recordCalibration()`, `assertFrequencyIsPresentWhenSupplied()` |
| B07 / P02 (typed insufficient-samples 422) | **Confirmed fixed** | `CapabilityController.php:94-99` + SPA empty state |
| **B09 (study hidden when options query fails)** | **Confirmed fixed and committed** since the last report — commit `89382380`, plus a new page test at `spa/src/pages/quality/capability/index.test.tsx`. The prior `audit-report.md` still listed it open; it is not. |
| I03-old (fail-closed product hash) | **Confirmed fixed** | `AnalyticsController::decodeProductFilter()` |
| M01 / P03 (calibration SPA surface) | **Confirmed present** | routes/sidebar/pages all exist |
| P01 (dashboard KPI retry) | **Fixed for two of three tiles** — see M059-P07 below |
| B08, B10, I01, I02, I04 (renumbered I03-old→I03), M02 | **Still open, all reproduced this session** | below |

## What was actually executed this session (not just read)

Per the module protocol, the analytics queries and the scheduled command were
**run**, not reviewed.

- `php artisan calibration:check-due` against the dev database → `Calibration check: 0 due, 0 overdue.` exit **0**.
- `php artisan calibration:check-due` against `ogami_test_cqa` seeded with a real overdue row → `0 due, 1 overdue`, statuses flipped in the table. The command works.
- `DefectParetoService::run()`, `::inspectionSummary()` and `::inspectionsWithDefect()` executed against real PostgreSQL rows on `ogami_test_cqa`:
  - `run()` → `total_defects: 3`, two rows, `percentage` 66.67/33.33, `cumulative_percentage` 66.67/100, `is_critical: true` — the `BOOL_OR(...)::int` PG branch works.
  - `inspectionSummary()` → `passed:0 failed:1 total:1 pass_rate:0`.
  - `inspectionsWithDefect()` → one row with **HashIDs** for both inspection and product (`"8njw6dNk3K"`, `"0ldwDmpVa9"`), no raw integer ids. The `GROUP BY` path compiles and returns rows; **no `SQLSTATE[42803]`**. The prior test only asserted the empty case, so the row-mapping branch had never executed anywhere before now.
- `SpcService::capabilityThresholds()` → `{launch:1.67, ongoing:1.33, action:1, minimum_samples:5}`.
- Backend focused suite on **`ogami_test_cqa`** (own database, never shared `ogami_test`): **33 passed / 100 assertions**.

**Environment note for the coordinator (NOT a module defect, nothing changed):**
the dev database `ogami` is stale — `SpcService::computeForSpec()` throws
`SQLSTATE[42P01] relation "inspection_spec_revisions" does not exist` there,
because migration `2026_08_25_170000_create_inspection_spec_revisions.php` has
never been applied to it. Its three `inspections` rows also predate the current
seeder and carry `completed_at = NULL` and `inspection_spec_id = NULL`, so every
Quality analytics window is empty in the dev environment. The current
`ComprehensiveDemoSeeder` (`:860-880`) does set `completed_at`, so this is drift,
not a code defect. It needs `migrate` + re-seed, which is outside this module.

## Findings

### Broken

#### M059-B11 (new, severe) — every calibration PATCH answered HTTP 500

`CalibrationService::update()` rebuilt the model as
`$record->fill($this->withDerived(array_merge($record->toArray(), $data)))`
(`api/app/Modules/Quality/Services/CalibrationService.php:39`, pre-fix).
`toArray()` carries `id`, `created_at` and `updated_at`, and
`api/app/Providers/AppServiceProvider.php:238` enables
`Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction())` — so
`fill()` **refused** those keys rather than dropping them.

Reproduced end to end before the fix: `PATCH /api/v1/quality/calibration/{id}`
with `{equipment_code, name, frequency_days}` → **500**
(`MassAssignmentException: Add fillable property [updated_at, created_at, id]`).
The edit form at `spa/src/pages/quality/calibration/form.tsx` therefore could not
save in **any non-production environment** — which is every environment this
project is developed, demonstrated and graded in. In production the guard is off
and the same code silently discards the three keys and works, so the failure mode
is inverted: it breaks exactly where it is used and passes where nobody looks.

Why five prior sessions missed it: the PATCH path had **zero test coverage
anywhere in `api/tests`**. `grep -rn 'patchJson|putJson|->update(' api/tests/Feature/Quality/Calibration*.php`
returned nothing. A completely dead write path was indistinguishable from a
healthy one — the same shape as the 8D SLA ledger defect recorded in `CLAUDE.md`,
minus the swallowed exception.

**Fixed** this session, with M059-I03. See `fix-log.md`.

#### M059-B10 — calibration create/update accepted an impossible history

Reproduced on `ogami_test_cqa`: `StoreCalibrationRecordRequest::rules()` passed
`{last_calibration_date: '2030-01-01', next_calibration_date: '2020-01-01', frequency_days: 365}`
and `CalibrationService::create()` persisted it as
`last=2030-01-01 next=2020-01-01 status=overdue` — an instrument calibrated in
2030 whose next due date is 2020. The sibling `recordCalibration()` path already
rejected future dates (`RecordCalibrationRequest.php:19`,
`CalibrationService.php:58-60`), proving the invariant was enforced
inconsistently across two writers on the same column.

**Fixed** this session. See `fix-log.md`.

#### M059-B08 — Capability Study is reachable under a Quality permission, but its data routes reject the intended Quality roles

Re-confirmed against seeder source, line by line:

- Route + sidebar gate the page on `quality.inspections.view` only —
  `spa/src/routes/qualityRoutes.tsx:74-76`, `spa/src/components/layout/Sidebar.tsx:447-452`.
- The page then needs two other modules' permissions:
  `productsApi.list()` → `GET /crm/products` → `crm.products.view`
  (`api/app/Modules/CRM/routes.php:28`), and `inspectionSpecsApi.forProduct()` →
  `quality.specs.view` (`api/app/Modules/Quality/routes.php:62-63`) —
  `spa/src/pages/quality/capability/index.tsx:105-115`.
- `qc_inspector` gets `$this->module('quality')` (so `quality.specs.view` ✓) but
  **no** `crm.products.view` — `api/database/seeders/RolePermissionSeeder.php:681-697`.
- `production_manager` gets only `quality.view`, `quality.inspections.view`,
  `quality.ncr.view` — **neither** `quality.specs.view` nor `crm.products.view`
  — `api/database/seeders/RolePermissionSeeder.php:577`.

Impact: of the three roles the registry assigns to M059, only `system_admin` can
actually run a capability study. A QC inspector — the role whose job this screen
is — reaches the page and gets a permanent "could not load the product list"
error with a retry button that can never succeed. A production manager gets the
same, one layer earlier. Note the page's own product/spec queries do have proper
error states, so the failure is *reported* honestly; it is simply unrecoverable.

**Not fixed.** The choice between (a) a Quality-owned product/spec read endpoint,
(b) granting `crm.products.view` to `qc_inspector`, and (c) narrowing the route
guard so the page stops advertising itself to roles that cannot use it, is an
RBAC/product decision, and quietly widening a role's permissions during an audit
is exactly what should not happen. Deferred with a question below.

### Missing

#### M059-M03 (new) — the IATF calibration register has no seed data or factory anywhere

`grep -rln 'CalibrationRecord|calibration_records' api/database/` returns only
three **migrations** — `0215_create_calibration_register.php` and two lifecycle
constraint migrations. There is no seeder entry and no
`api/database/factories/CalibrationRecordFactory.php`.

Measured: `select count(*) from calibration_records` on the dev database `ogami`
→ **0**. So `calibration:check-due` (scheduled daily at 06:50,
`api/routes/console.php:318-322`) has printed `0 due, 0 overdue` and exited
SUCCESS on every run of its life, and an IATF 16949 gauge-control register that
has never held a row is indistinguishable from one that is simply all-current.
The register is not dead code — this session drove it end to end with real rows
and it works — but nothing has ever exercised it outside tests.

#### M059-M04 (new) — an overdue gauge notifies nobody

`grep -rn 'calibration' api/app -il` returns exactly ten files: the command, the
enum, the model, two requests, the resource, the controller, the service, the
routes, and one unrelated `UpdateSettingRequest`. **None** is in
`Common/Services/NotificationService`, an alert provider, or
`DashboardWidgetSeeder`. `CheckCalibrationDue` flips `active → due → overdue`
and tells no one; there is no calibration dashboard widget
(`DashboardWidgetSeeder.php:186-189` has `qc.pareto`, `qc.pending_inspections`,
`qc.open_ncrs`, `qc.pass_rate` and no calibration key), and no alert row.

Impact: measuring equipment silently goes out of calibration, and the only way to
find out is for someone to open `/quality/calibration` and read the register. For
an IATF 16949 control that is a real gap — the same "the status changed and no
required notification was delivered" shape as the 8D escalation defect, reached
by omission rather than by a swallowed exception. `NotificationService::send()`
already exists as the single entry point (`CLAUDE.md`, shared helpers), so this
is wiring, not new infrastructure — but choosing recipients (QC? maintenance? the
`responsible` column?) and cadence is a product decision.

#### M059-M02 — SPA and browser regression coverage (partially closed)

Now present: `spa/src/pages/quality/capability/index.test.tsx` (added by the
prior session) and `spa/src/pages/quality/calibration/form.test.tsx` (added this
session, 4 tests). Still absent: any test for
`spa/src/pages/quality/calibration/index.tsx` (list, filters, record modal) or
`spa/src/pages/quality/dashboard.tsx`, and **no browser walk was run in this
session** — so per-role behaviour for the B08 defect above and the Pareto tooltip
label are still unverified in a real renderer.

The tooltip in particular cannot be honestly asserted in jsdom: it only exists on
hover inside a Recharts `ResponsiveContainer`, which has no layout without a real
layout engine. Per `CLAUDE.md` that means Chromium, not Lightpanda — a passing
geometry assertion under Lightpanda would be a fabricated number.

### Incomplete

#### M059-I01 — changing calibration frequency still does not reschedule

`CalibrationService` derives status from the *existing* next date and never
recomputes a next date from a changed `frequency_days`; the form offers both
fields side by side (`spa/src/pages/quality/calibration/form.tsx:120-124`).
Changing 365 → 30 days leaves the old next-due date in place. Unchanged by this
session's fix, deliberately: whether a frequency change reschedules from the last
completed calibration or requires an explicit next-date edit is a policy choice
that changes what the register *means*.

#### M059-I03 — stale-snapshot overwrite on concurrent PATCH — **fixed**

Was: `update()` started from the representation the request arrived with, so the
later of two concurrent PATCHes silently reverted the earlier one's fields.
Now the row is re-read under `lockForUpdate()` and the request is a patch on top
of it, matching the pattern `recordCalibration()` already used. Covered by
`test_patch_does_not_resurrect_a_stale_snapshot_of_untouched_fields`.

#### M059-I04 — recording a calibration on retired equipment is still permitted, and the service contradicts itself

The list renders Edit and Record for every manageable row including
`status=retired` (`spa/src/pages/quality/calibration/index.tsx:71-93`). The
service docblock says recording "resets status to active"
(`api/app/Modules/Quality/Services/CalibrationService.php:46-49`) while
`statusFor()` explicitly preserves `Retired` (`:158-162`) — yet the record path
still advances `last_calibration_date` and `next_calibration_date`. So an
operator can rewrite the calibration history of an instrument that is out of
service, and the register keeps calling it retired.

Not fixed: refusing the transition versus making reactivation explicit is a
lifecycle decision, and the half-fix (hide the button, leave the API permissive)
would be worse than either. Note the fix landed this session *does* now give the
edit form a working reactivation path (`status: retired → active` on PATCH), so
an explicit reactivation flow is available if that is the policy chosen.

#### M059-I02 — archived/inactive specs are a supported historical read on one path and refused on the other

`CapabilityController::options()` restricts to active, non-deleted specs
(`:33-40`) and `capability()` refuses an archived spec with a typed
`quality_capability_spec_unavailable` (`:73-78`), but the spec-detail SPC route is
declared `->withTrashed()` (`api/app/Modules/Quality/routes.php:58-60`) and
`SpcService::computeForSpec()` loads `InspectionSpec::withTrashed()` (`:132`).
The same historical spec answers differently depending on which screen asks.
Unresolved policy, unchanged.

#### M059-I05 (new) — `options()` ships every product's spec items to a consumer that does not read them

`CapabilityController::options()` returns a `spec_items` array built from **all**
bilateral `InspectionSpecItem` rows on every active spec, across every product,
unpaginated (`api/app/Modules/Quality/Controllers/CapabilityController.php:33-48`).
It eager-loads `spec:id,product_id` and then never emits the product — so the
payload carries no way to tell which product an item belongs to.

The SPA does not use it: `CapabilityStudyPage` populates its dimension dropdown
from `inspectionSpecsApi.forProduct(selectedProductId)`
(`spa/src/pages/quality/capability/index.tsx:111-125`) and reads only
`capability_thresholds` off this response (`:100`). So the array is dead weight
that grows linearly with the spec catalogue and exposes every product's parameter
names to anyone holding `quality.inspections.view`. Either scope it by product
and use it, or drop it and rename the endpoint to what it actually serves.

#### M059-I06 (new) — `calibration:check-due` cannot distinguish "nothing to do" from "nothing there", and is not atomic

`CheckCalibrationDue::handle()` prints `%d due, %d overdue` and returns
`self::SUCCESS`. With an empty register (M059-M03: the real state of the dev
environment) that is `0 due, 0 overdue` — identical to a register of a hundred
current instruments. The command does not report how many rows it scanned, which
is the one number that separates the two.

Separately, `recomputeStatuses()` saves row by row outside any transaction
(`api/app/Modules/Quality/Services/CalibrationService.php:118-137`) and
`statusFor()` throws `BusinessRuleException` if the
`quality.calibration.due_window_days` setting is missing or negative (`:173-177`).
That throw is *not* swallowed — good, and deliberately unlike the 8D ledger — but
it aborts mid-chunk, leaving the register partially recomputed with no record of
how far it got. Add a scanned count to the summary and either wrap the sweep or
validate the setting once before the loop.

### Polish

#### M059-P05 — Pareto tooltip reported defect counts as downtime minutes — **fixed**
#### M059-P06 — calibration edit loading/error used raw text divs with no retry — **fixed**
#### M059-P07 (new) — "Total defects" KPI conflated failure with zero, and its helper mislabelled the figure — **fixed**

All three are described with before/after in `fix-log.md`.

## Open questions needing a human decision

1. **B08 — who may run a capability study?** Options: a Quality-owned
   product/spec read endpoint; grant `crm.products.view` to `qc_inspector`; or
   narrow the route guard to `quality.specs.view` so the page stops advertising
   itself to `production_manager`. This decides whether M059's registry role list
   (`system_admin`, `qc_inspector`, `production_manager`) is accurate or
   aspirational.
2. **I01 — does changing `frequency_days` reschedule?** From the last completed
   calibration, or never (explicit next-date edit required)?
3. **I04 — may a retired instrument be calibrated?** Refuse, or require explicit
   reactivation first? The PATCH reactivation path now works either way.
4. **I02 — are archived specs valid historical SPC inputs?** The two paths
   currently disagree; either answer is defensible, but not both.
5. **M04 — who is told a gauge went overdue, and how?** The `responsible` column
   on the record, the QC role, maintenance, or a dashboard widget only?
6. **M03 — should the register be seeded?** A demo/thesis environment with an
   empty IATF calibration register cannot demonstrate the control at all.
