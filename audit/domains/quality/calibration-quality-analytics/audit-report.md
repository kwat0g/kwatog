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
