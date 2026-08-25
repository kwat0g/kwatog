# M059 — Calibration / Quality Analytics Audit Report

Audit session: 2026-08-24 UTC / 2026-08-25 Asia/Manila  
Final session status: 🔁 Needs Re-audit  
Scope: calibration register and due-status automation, defect Pareto / inspection-summary analytics, Cp/Cpk capability analytics, and the directly corresponding quality UI. The inspection, NCR, maintenance, dashboard-platform, and specification modules were read only for boundary evidence; they were not audited or changed.

## 2026-08-25 Revalidation and execution

The existing report and plan were re-read before implementation. The shared working tree contained pre-existing Quality changes that materially altered `SpcService`, so the affected findings were revalidated against the current code rather than assumed unchanged. The prior finding labels below remain the audit classification; this section records the current disposition after this session's focused fixes.

- **M059-B01 — resolved/verified.** `DefectParetoService::run()` counts from the ungrouped base query at `api/app/Modules/Quality/Services/DefectParetoService.php:104-112`; the new multi-parameter regression at `api/tests/Feature/Quality/QualityAnalyticsBoundaryTest.php:25-60` verifies one denominator and excludes the cancelled row.
- **M059-B02 — resolved.** Pareto and drill-down now restrict measurements to `InspectionStatus::Passed`/`Failed` at `api/app/Modules/Quality/Services/DefectParetoService.php:85-89,151-157`.
- **M059-B03/B04 — resolved at the API boundary.** The current `SpcService` worktree changes filter capability reads to terminal inspections and reject a foreign product/spec pairing at `api/app/Modules/Quality/Services/SpcService.php:139-180`; `CapabilityController` now enforces active ownership and returns typed 422 errors at `api/app/Modules/Quality/Controllers/CapabilityController.php:31-40,57-107`. Route tests cover mismatch, insufficient data, terminal-only sampling, and permission denial.
- **M059-B05/B06 — resolved.** Recording uses `before_or_equal:today` in `api/app/Modules/Quality/Requests/RecordCalibrationRequest.php:16-20`, the service rejects a future date at `api/app/Modules/Quality/Services/CalibrationService.php:50-60`, and explicit null frequency is rejected by `StoreCalibrationRecordRequest:22-31` and the service guard at `:121-126`.
- **M059-B07/P02 — resolved.** Insufficient capability samples now produce `quality_capability_insufficient_samples` rather than a successful null response at `api/app/Modules/Quality/Controllers/CapabilityController.php:93-98`; the SPA renders an actionable no-data state at `spa/src/pages/quality/capability/index.tsx:131-140,234-241`.
- **M059-I01 — still Incomplete / decision required.** Frequency edits still do not define whether `next_calibration_date` is rescheduled from the last completed event or explicitly edited; this is intentionally deferred rather than guessed.
- **M059-I02 — still Incomplete / decision required.** Capability options and the capability POST path now exclude archived/inactive specs at `api/app/Modules/Quality/Controllers/CapabilityController.php:31-40,71-83`, but the existing `/inspection-specs/{id}/spc` path remains available for archived records via `withTrashed()` at `api/app/Modules/Quality/routes.php:52-54`. Historical-spec analytics policy needs an owner decision.
- **M059-I03 — resolved.** Analytics product filters now fail with a field-level 422 when a hash is malformed or expired at `api/app/Modules/Quality/Controllers/AnalyticsController.php:24-34,65-77`.
- **M059-M01/P03 — resolved in the SPA surface.** Calibration list/create/edit/record flows, API client, permission-gated routes, and navigation are present at `spa/src/pages/quality/calibration/index.tsx`, `spa/src/pages/quality/calibration/form.tsx`, `spa/src/api/quality/calibration.ts`, `spa/src/routes/qualityRoutes.tsx:23-48`, and `spa/src/components/layout/Sidebar.tsx:422-466`. Capability Study is now also discoverable from Quality navigation.
- **M059-M02 — materially improved but still Incomplete.** Focused route/service coverage was added in `api/tests/Feature/Quality/QualityAnalyticsBoundaryTest.php` and `CalibrationBoundaryTest.php`. A full Quality-suite result is not evidence in this session because the shared `ogami_test` database was concurrently refreshed by another process; the isolated targeted runs passed.
- **M059-P01 — resolved.** Pass-rate and open-NCR KPI failures now display explicit retry states at `spa/src/pages/quality/dashboard.tsx:64-85`.
- **M059-P04 — resolved for the post-scope-cut baseline.** The stale COPQ route, cron, event, role-matrix, and E2E permission references were removed or replaced in `docs/PROCESS-FLOWS.md:1264-1269,1678-1688`, `docs/AUTO-BROWSER-TESTS.md:114-124`, and `spa/e2e/helpers-extended.ts:94-95,110-111,192-193`; an `rg` check found no remaining `COPQ`, `copq`, or `quality.copq` occurrence in those files or `CLAUDE.md`.

The production-manager calibration permission question remains open from the prior report. The SPA typecheck remains blocked by a pre-existing parser error outside M059 at `spa/src/pages/production/work-orders/detail.tsx:624`; targeted ESLint for the changed M059 SPA files passes.

## Discovery

- Backend calibration surface: `api/app/Modules/Quality/routes.php:23-35`, `CalibrationController`, `CalibrationService`, `CalibrationRecordResource`, and migration `api/database/migrations/0215_create_calibration_register.php:20-35`.
- Calibration automation: `api/app/Console/Commands/CheckCalibrationDue.php:10-24` and `api/routes/console.php:274-278` run the due/overdue recomputation daily.
- Backend analytics surface: defect Pareto, inspection summary, and drill-down at `api/app/Modules/Quality/routes.php:126-132`; Cp/Cpk options and study endpoints at `:161-164`.
- Frontend analytics surface: `spa/src/pages/quality/dashboard.tsx`, `spa/src/pages/quality/capability/index.tsx`, `spa/src/api/quality/analytics.ts`, and `spa/src/api/quality/capability.ts`.
- Frontend calibration discovery: no `spa/src/pages/quality/calibration*`, calibration API client, calibration route, or calibration navigation item exists. The backend route inventory does contain the five calibration endpoints.
- Existing focused verification: `docker compose run --rm --no-deps api php artisan test tests/Feature/Quality/CalibrationRegisterTest.php tests/Feature/Quality/CalibrationBackdatedRecordRaceTest.php tests/Feature/Quality/QualityInspectionSummaryTest.php` — **8 passed, 17 assertions**.
- A broader `tests/Feature/Quality` run was not usable as module evidence: 60 tests failed against a stale/incomplete shared test schema (`roles.deleted_at`, `audit_logs.actor_type`, missing tables, and a deadlock). Those failures were not attributed to M059.

## Verified strengths

- Calibration create, update, and record writes use `DB::transaction()` at `api/app/Modules/Quality/Services/CalibrationService.php:20-38,45-66`.
- Calibration recording re-reads and locks the authoritative row, and rejects an older/equal event from regressing the register at `api/app/Modules/Quality/Services/CalibrationService.php:47-65`; the dedicated backdated-race test passes.
- Due/overdue status derivation is centralized and the scheduled command is idempotent in shape at `api/app/Modules/Quality/Services/CalibrationService.php:69-135`.
- API resources expose hash IDs rather than integer primary keys at `api/app/Modules/Quality/Resources/CalibrationRecordResource.php:18-31`.
- Analytics and calibration routes are behind the quality feature and Sanctum authentication, with separate view/manage permission middleware at `api/app/Modules/Quality/routes.php:23-35,126-132,161-164`.
- The quality dashboard has a loading/error/retry path for the Pareto query and uses semantic design tokens in `spa/src/pages/quality/dashboard.tsx:80-117`. The capability page has a product-list retry state and mutation error toast at `spa/src/pages/quality/capability/index.tsx:96-130,154-164`.

## Findings

### Broken

#### M059-B01 — Pareto total and percentages are calculated from a grouped count

Evidence: `api/app/Modules/Quality/Services/DefectParetoService.php:104-111` builds the result rows with `groupBy('m.parameter_name')`, then calls `count()` on a clone that retains that grouping. Laravel's aggregate implementation returns the first aggregate row (`api/vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php:3999-4008`), not the count of all defect measurements. With more than one defect parameter, `total_defects`, each percentage, and the cumulative percentage are therefore wrong.

Impact: the quality dashboard can report a partial defect total and misleading Pareto percentages. No test currently exercises `DefectParetoService::run()` with multiple parameter groups.

#### M059-B02 — Pareto and drill-down include cancelled inspections while the KPI excludes them

Evidence: `DefectParetoService::run()` and `inspectionsWithDefect()` filter only on `m.is_pass = false` and `i.completed_at` at `api/app/Modules/Quality/Services/DefectParetoService.php:85-95,150-168`; neither restricts inspection status. Cancellation sets `status = cancelled` and still stamps `completed_at` at `api/app/Modules/Quality/Services/InspectionService.php:573-588`. In contrast, `inspectionSummary()` explicitly restricts status to `passed`/`failed` at `DefectParetoService.php:34-36`.

Impact: the dashboard's total-defect chart, drill-down list, and pass-rate KPI can describe different populations for the same date window. A cancelled inspection's failed measurements can appear as production defects.

#### M059-B03 — Capability study does not enforce that the spec item belongs to the selected product

Evidence: `api/app/Modules/Quality/Controllers/CapabilityController.php:60-66` independently resolves a product and a spec item, then passes both to `SpcService`. `SpcService::computeCapabilityStudy()` never uses `$productId`; its query is only keyed by `inspection_spec_item_id` at `api/app/Modules/Quality/Services/SpcService.php:153-169`.

Impact: a caller can submit a valid product hash together with a spec-item hash belonging to another product and receive that other product's measurements. The UI's dependent dropdowns reduce accidental misuse but do not protect the API boundary.

#### M059-B04 — Cp/Cpk analytics read measurements from non-terminal inspections

Evidence: `computeForSpec()` and `computeCapabilityStudy()` query `inspection_measurements` without joining `inspections` or filtering `status` at `api/app/Modules/Quality/Services/SpcService.php:123-140,153-169`. The class documentation says the data is from completed inspections at `SpcService.php:114-119`.

Impact: draft or in-progress readings can change reported process capability, and cancelled readings can remain in the study. The result is not a stable quality metric based on released inspection evidence.

#### M059-B05 — A calibration event can be recorded with a future date

Evidence: `CalibrationController::recordCalibration()` validates only `required|date` at `api/app/Modules/Quality/Controllers/CalibrationController.php:44-48`; `CalibrationService::recordCalibration()` parses and persists any supplied date and derives the next date from it at `api/app/Modules/Quality/Services/CalibrationService.php:45-63`.

Impact: a technician or client can backdate or future-date a completed calibration event. A future date can move the next due date forward and suppress an otherwise overdue instrument.

#### M059-B06 — Explicit `frequency_days: null` passes request validation but violates the database invariant

Evidence: `StoreCalibrationRecordRequest` declares `frequency_days` as nullable at `api/app/Modules/Quality/Requests/StoreCalibrationRecordRequest.php:22-31`, while `calibration_records.frequency_days` is an unsigned, non-null column with a default at `api/database/migrations/0215_create_calibration_register.php:26-29`. `CalibrationService::create()` and `update()` pass the validated payload through to `fill()` at `api/app/Modules/Quality/Services/CalibrationService.php:20-38`.

Impact: a syntactically valid JSON request containing `frequency_days: null` can reach a database exception instead of a field-level 422 response.

#### M059-B07 — “Insufficient samples” is returned as a successful null result and the UI reports success

Evidence: `SpcService::compute()` returns `null` below the configured minimum at `api/app/Modules/Quality/Services/SpcService.php:81-86`; the controller wraps that null in a 200 response at `api/app/Modules/Quality/Controllers/CapabilityController.php:63-76`. The SPA types the mutation as a non-null capability result and always shows “Capability study completed” in `spa/src/pages/quality/capability/index.tsx:120-133`.

Impact: users receive a success toast but no result or explanation when there is not enough data. The page cannot distinguish “no study possible yet” from a completed study with no visible output.

### Missing

#### M059-M01 — Calibration register has no usable frontend surface

Evidence: backend endpoints exist at `api/app/Modules/Quality/routes.php:25-35`, but `spa/src/routes/qualityRoutes.tsx:25-60` registers no calibration route, `spa/src/components/layout/Sidebar.tsx:422-454` has no calibration item, and the SPA has no calibration API client or page file.

Impact: QC users can be granted `quality.calibration.view/manage` but cannot list equipment, register an instrument, update it, or record calibration through the product UI. This is a complete role-facing feature gap, not just navigation polish.

#### M059-M02 — Analytics and capability boundary tests are missing

Evidence: the existing Quality tests cover calibration service status/race behavior and one inspection-summary service path, but `rg` finds no test for `DefectParetoService::run()`, `inspectionsWithDefect()`, `CapabilityController`, `SpcService::computeForSpec()`, capability product/spec ownership, future-date rejection, or calibration HTTP permission/validation behavior.

Impact: the broken aggregate, lifecycle-scope, and API-contract cases above can regress without a focused failure. Add feature tests at the route boundary and service tests for the calculation invariants before marking the module verified.

### Incomplete

#### M059-I01 — Changing calibration frequency leaves the next due date stale

Evidence: `CalibrationService::update()` merges the existing row and calls `withDerived()` at `api/app/Modules/Quality/Services/CalibrationService.php:31-38`. `withDerived()` derives only `status`; it does not recompute `next_calibration_date` when `frequency_days` changes at `:96-109`.

Impact: an operator can change a 365-day interval to 30 days while the register still displays the old next date. Decide whether frequency changes take effect from the last completed calibration or require an explicit next-date edit, then enforce and test that policy.

#### M059-I02 — Capability options and spec analytics are not scoped to active specs

Evidence: `CapabilityController::options()` selects every bilateral `InspectionSpecItem` and eager-loads the parent without an `is_active` constraint at `api/app/Modules/Quality/Controllers/CapabilityController.php:29-44`. `InspectionSpec` has both soft deletes and an `is_active` flag at `api/app/Modules/Quality/Models/InspectionSpec.php:25-36`, while `SpcService::computeForSpec()` only filters by parent ID at `api/app/Modules/Quality/Services/SpcService.php:123-130`.

Impact: archived/deactivated specification items can be offered to capability clients or queried directly, even though the normal product lookup uses only active specs. The intended historical-analytics policy needs to be made explicit and consistently enforced.

#### M059-I03 — Invalid product filters silently become unfiltered analytics

Evidence: `AnalyticsController` accepts an unconstrained `product_id` and replaces an invalid hash with `null` at `api/app/Modules/Quality/Controllers/AnalyticsController.php:22-33,40-49,54-63`. The service applies the product predicate only when the value is non-empty at `api/app/Modules/Quality/Services/DefectParetoService.php:37-39,90-92,167-168`.

Impact: a malformed or expired product filter can return company-wide results instead of a 422/404 or an empty result. Use the shared hash-filter contract or fail closed at the request boundary.

### Polish

#### M059-P01 — Quality dashboard silently hides pass-rate and open-NCR query failures

Evidence: `spa/src/pages/quality/dashboard.tsx:31-46` creates the pass-rate and open-NCR queries, but the cards at `:62-77` render `—` on any failure and provide no error or retry state. The Pareto query has an explicit retry state at `:80-89`, so the page is inconsistent.

Impact: a QC user cannot tell “zero/no data” from an unavailable KPI and has no recovery action for two of the three headline metrics.

#### M059-P02 — Capability and SPC panels do not communicate missing-data states

Evidence: `SpcService` explicitly says the UI should convey “not enough data” where results are absent at `api/app/Modules/Quality/Services/SpcService.php:114-120`. The inspection detail page renders the SPC panel only when data is non-empty at `spa/src/pages/quality/inspections/detail.tsx:470-505`, and the capability page renders results only when `result` is truthy at `spa/src/pages/quality/capability/index.tsx:218-353`.

Impact: users see an empty area rather than sample-count requirements, a no-data explanation, or a next action. This compounds M059-B07's successful-null contract problem.

#### M059-P03 — The capability study is not discoverable from quality navigation

Evidence: `/quality/capability` is registered at `spa/src/routes/qualityRoutes.tsx:58-60`, but the Quality sidebar section contains only inspection specs, inspections, NCRs, and traceability at `spa/src/components/layout/Sidebar.tsx:422-454`; there is no capability or calibration link.

Impact: the capability screen is effectively a direct-URL feature, and the missing calibration screen has no navigational placeholder. Add discoverability when the calibration UI is implemented, using permission-derived visibility.

#### M059-P04 — Documentation and E2E permission fixtures still advertise the removed COPQ surface

Evidence: the COPQ snapshot table is explicitly removed by `api/database/migrations/0452_drop_copq_snapshots_table.php:8-18`, but `docs/PROCESS-FLOWS.md:1264-1269,1688`, `CLAUDE.md:646-661`, and `spa/e2e/helpers-extended.ts:94,110,192` still reference COPQ endpoints, cron, or `quality.copq.view`.

Impact: operators and test authors can expect a route and permission that do not exist. This is a documentation/fixture drift finding; it is not treated as a request to reintroduce COPQ without a product decision.

## Open questions

- M059 metadata lists `production_manager` as a role, but the seeded role receives `quality.view`, `quality.inspections.view`, and `quality.ncr.view` rather than `quality.calibration.view` at `api/database/seeders/RolePermissionSeeder.php:530-541`. The browser role matrix also lists calibration for `qc_inspector` but not production manager at `docs/AUTO-BROWSER-TESTS.md:119-123`. Confirm whether production managers should see calibration or only analytics/capability.
- The current code explicitly removed COPQ. Confirm whether the intended M059 end state is the post-scope-cut set (calibration, Pareto, summary, drill-down, Cp/Cpk) or whether a new COPQ product decision supersedes the removal.
- The remaining `Not Started` dependency graph was cyclic during selection, and the first three Tier-3 candidates became locked by other sessions. M059 was claimed as the first subsequently available Tier-3 candidate; its dependency modules were read only.

## Handoff recommendation

Do not mark M059 verified yet. Fix the calculation/data-scope defects and define the calibration/capability API contracts first, then add the missing calibration UI and route-level tests. A fresh focused API suite plus SPA typecheck/build and role smoke coverage should be required for re-audit.
