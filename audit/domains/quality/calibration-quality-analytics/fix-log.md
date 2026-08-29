# M059 — Fix Log

## 2026-08-30 — re-audit session (own DB `ogami_test_cqa`)

### M059-B11 — fixed. Every calibration PATCH answered HTTP 500.

`CalibrationService::update()` rebuilt its patch as
`$record->fill($this->withDerived(array_merge($record->toArray(), $data)))`
(`api/app/Modules/Quality/Services/CalibrationService.php:39`, pre-edit).
`toArray()` carries `id`, `created_at` and `updated_at`, and
`AppServiceProvider` turns on `Model::preventSilentlyDiscardingAttributes(! isProduction())`
at `api/app/Providers/AppServiceProvider.php:238` — so `fill()` **refused**
those keys instead of dropping them, and the plainest possible edit raised
`MassAssignmentException: Add fillable property [updated_at, created_at, id]`.

Reproduced end-to-end before the fix: `PATCH /api/v1/quality/calibration/{id}`
with `{equipment_code, name, frequency_days}` → **500**. The whole PATCH path had
**zero test coverage anywhere in `api/tests`** (`grep -rn 'patchJson|putJson' api/tests/Feature/Quality/Calibration*.php`
returned nothing), which is why a 100%-dead write path stayed green. The SPA edit
form at `spa/src/pages/quality/calibration/form.tsx` therefore could not save in
any non-production environment, i.e. in every environment this project is
demonstrated in. In production the guard is off and the keys are silently
dropped, so the defect is invisible there — the failure mode is inverted.

After: `update()` re-reads the row under `lockForUpdate()`, applies only
`array_intersect_key($data, array_flip($locked->getFillable()))`, and derives
status explicitly — `api/app/Modules/Quality/Services/CalibrationService.php:34-90`.
Covered by `test_patch_updates_a_calibration_record` at
`api/tests/Feature/Quality/CalibrationBoundaryTest.php:76-108`.

### M059-I03 — fixed by the same rewrite (stale-snapshot overwrite).

Before: `update()` started from the representation the request arrived with, so
of two concurrent PATCHes the later silently reverted the earlier one's fields.
After: the locked re-read is the authority and the request is a patch on top of
it. Covered by `test_patch_does_not_resurrect_a_stale_snapshot_of_untouched_fields`
at `api/tests/Feature/Quality/CalibrationBoundaryTest.php:110-143` — a competing
writer's `responsible` survives a PATCH that only sets `location`.

### M059-B10 — fixed. The register accepted an impossible history.

Reproduced before the fix on `ogami_test_cqa`:
`create(['last_calibration_date' => '2030-01-01', 'next_calibration_date' => '2020-01-01', 'frequency_days' => 365])`
passed `StoreCalibrationRecordRequest::rules()` and persisted as
`last=2030-01-01 next=2020-01-01 status=overdue` — an instrument calibrated in
2030 whose next due date is 2020.

After, in two places because neither alone is sufficient:
- `api/app/Modules/Quality/Requests/StoreCalibrationRecordRequest.php:26-31` —
  `last_calibration_date` gains `before_or_equal:today`.
- `api/app/Modules/Quality/Services/CalibrationService.php:151-179`
  (`assertDateOrder()`) — the ordering invariant is checked against the **merged**
  pair, because a PATCH may supply only the next date and the FormRequest cannot
  see the stored last date. Both violations map to one correctable input, so they
  raise `ValidationException::withMessages()` (field-level 422), not
  `BusinessRuleException`.
  On update the check is gated on the patch actually touching a date, so a row
  that predates this guard stays repairable field by field rather than becoming
  permanently unsaveable.

`frequency_days` was deliberately **not** made to re-derive `next_calibration_date`
— that is M059-I01 and remains an undecided policy question.

Covered by `test_future_last_calibration_date_is_rejected_on_create`,
`test_next_calibration_date_before_last_is_rejected` and
`test_patch_supplying_only_the_next_date_is_checked_against_the_stored_last_date`
at `api/tests/Feature/Quality/CalibrationBoundaryTest.php:145-193`.

Refactor carried along: `withDerived()`'s inline status/date coercion moved into
`requestedStatus()` and `asDateString()` so `create()` and `update()` read the
same value the same way.

Verification: `CalibrationBoundaryTest` + `CalibrationRegisterTest` +
`CalibrationBackdatedRecordRaceTest` — **16 passed / 43 assertions** on
`ogami_test_cqa`. `php -l` clean on all three changed files.

### M059-P05 — fixed. The defect Pareto reported defect counts as downtime minutes.

Before: the shared chart hard-coded `name="Downtime"` on the bar and its tooltip
formatter matched on that literal, so it ran `formatMinutes()` over whatever the
`minutes` field held. The Quality dashboard feeds `defect_count` into that field
(`spa/src/pages/quality/dashboard.tsx:51-57`), so hovering a bar with 1 defect
read **"Downtime: 1m"**. The `valueLabel` prop existed but only reached the left
Y-axis tick formatter.

After: `spa/src/components/charts/DowntimeParetoChart.tsx:18-45,62-81` derives
`seriesName` and `formatValue` from `valueLabel` once and uses them in the axis,
the series name and the tooltip. The Quality dashboard now reads
"Defects: 1".

Shared-component blast radius checked, not assumed: the only other consumer is
`spa/src/pages/maintenance/downtime/index.tsx:327`, which passes **no**
`valueLabel`, so `seriesName` falls back to `'Downtime'` and `formatValue` to
`formatMinutes` — bit-identical behaviour. `npx vitest run src/components`
(15 files / 73 tests) and the full SPA typecheck both pass.

### M059-P06 — fixed. Calibration edit loading/error used raw text divs.

Before: `spa/src/pages/quality/calibration/form.tsx:96-101` returned bare
`<div className="px-5 py-8 text-sm …">` strings with no page header, no
skeleton, and **no retry affordance** — the user's only recovery was to navigate
away, which is exactly what the copy told them to do.

After: both states keep the `PageHeader` (so the back link survives a failure)
and use the shared primitives — `SkeletonBlock` for loading and
`QueryErrorState` with `onRetry={detail.refetch}` for the error. Covered by
`spa/src/pages/quality/calibration/form.test.tsx:44-56`, which clicks the retry
button and asserts a second fetch.

### M059-P07 (new) — fixed. Two defects in the "Total defects" KPI tile.

`spa/src/pages/quality/dashboard.tsx:74-78`:
1. On a failed Pareto query the tile rendered `'—'`, which is exactly what it
   renders for "no defects" — a failure read as a clean quality window. This is
   the same defect M059-P01 fixed for the pass-rate and open-NCR tiles and
   missed here. Now `'Unavailable'` + `'Retry below'`, matching its two
   neighbours; the retry itself already exists in the panel beneath.
2. The helper said **"across top 10 parameters"**, but `total_defects` is the
   *ungrouped* denominator over every failed measurement in the window — that
   was the whole point of the M059-B01 fix at
   `api/app/Modules/Quality/Services/DefectParetoService.php:112`. With more than
   ten defective parameters the number legitimately exceeds the sum of the ten
   rows shown, so the label made a correct figure look like an arithmetic bug.
   Now "failed measurements in the window".

### Calibration form now mirrors the new backend rules

`spa/src/pages/quality/calibration/form.tsx:20-45,140` adds `max={today()}` to
the "Last calibrated" picker and two Zod refinements mirroring
`StoreCalibrationRecordRequest` + `CalibrationService::assertDateOrder()`.

Measured, not assumed: `max` makes the input `rangeOverflow`, so native form
validation blocks submission before React Hook Form runs — verified in jsdom
(`validity.rangeOverflow === true`, `form.checkValidity() === false`). The Zod
refine is therefore defence-in-depth for a value that arrives without a change
event (a draft restored by `useFormSafety`), and the server remains
authoritative. The test asserts what actually happens — the out-of-range value
never reaches `calibrationApi.create` — rather than asserting a Zod message that
the native layer prevents from ever rendering.

Verification (SPA): `form.test.tsx` + `capability/index.test.tsx` +
`inspection-specs/editor.test.tsx` — **7 passed**; `src/components` —
**73 passed**; `npx tsc --noEmit` — **exit 0, zero diagnostics** (this clears the
prior session's outstanding typecheck gate, which had failed for an OOM reason,
not a code reason); `npx eslint --max-warnings 0` clean over all six changed SPA
files plus the maintenance chart consumer.

Verification (backend, final combined run on `ogami_test_cqa`):
`CalibrationBoundaryTest`, `CalibrationRegisterTest`,
`CalibrationBackdatedRecordRaceTest`, `QualityAnalyticsBoundaryTest`,
`QualityInspectionSummaryTest`, `SpcServiceTest` —
**33 passed / 100 assertions**. PHPStan clean on the three changed backend
files. Pint fails on `CalibrationService.php` and
`StoreCalibrationRecordRequest.php` — **proven inherited**: the
`git show HEAD:` copies of both files fail with the identical fixer lists, so
no new violation was introduced and pre-existing style was deliberately not
reformatted into this diff.

### Deliberately NOT fixed here

B08 (capability cross-module RBAC), I01 (frequency rescheduling policy),
I02 (archived-spec SPC policy), I04 (recording on retired equipment),
M03 (no calibration seed data), M04 (no overdue-calibration notification),
I05 (unscoped `spec_items` payload) and I06 (ambiguous command summary) are all
recorded in `audit-report.md` with reproduction evidence and left to a separate
session — see `action-plan.md` for why each is gated.


## 2026-08-27 — M059-B09 fixed

- **M059-B09 — fixed.** The capability page now tracks the options query as a
  full query state with independent loading, error, and retry feedback. A
  completed study renders independently of that query. When thresholds are
  unavailable, the page keeps Cp, Cpk, sample count, mean, standard deviation,
  histogram, and detailed indices visible, explicitly labels thresholds as
  unavailable, and omits the Cpk rating and threshold guidance rather than
  inventing an interpretation.
- Added `spa/src/pages/quality/capability/index.test.tsx`, covering an options
  failure, retry affordance, and successful study result with no threshold-based
  rating.
- B08, B10, I01-I04, archived-SPC I02, and unrelated UI polish were not touched.

Verification: focused Vitest **1 test passed** using an equivalent temporary
runner config because the repository config's root-owned `spa/node_modules/.vite-temp`
directory rejected its generated cache file; targeted ESLint passed with
`--max-warnings 0`; page/test-only strict TypeScript checking passed.

## 2026-08-25 — focused plan execution

The module was claimed with an existing `📋 Plan Ready` action plan, so the plan was executed in order. The working tree already contained unrelated/pre-existing Quality changes, including the terminal-inspection and capability query changes in `SpcService`; those changes were inspected and preserved. The controller boundary, UI, tests, and documentation changes below were made in this session.

- **Item 1 / M059-B01 — verified.** Before: the audit identified a grouped query being reused for the Pareto denominator. After: the current implementation counts from the ungrouped base query at `api/app/Modules/Quality/Services/DefectParetoService.php:105-112`; added the multi-parameter denominator and percentage regression at `api/tests/Feature/Quality/QualityAnalyticsBoundaryTest.php:25-60`.
- **Item 2 / M059-B02 — fixed.** Before: Pareto and drill-down accepted any completed inspection, including cancelled records. After: both queries use the shared Passed/Failed status list at `api/app/Modules/Quality/Services/DefectParetoService.php:85-89,151-157,188-195`; the cancelled-row regression is covered at `api/tests/Feature/Quality/QualityAnalyticsBoundaryTest.php:25-60`.
- **Item 3 / M059-B03/B04 — fixed at the HTTP boundary and verified with the existing service changes.** Before: the controller accepted a product/spec-item mismatch and capability reads could include non-terminal measurements. After: active spec ownership and typed mismatch/unavailable/insufficient-data errors are enforced at `api/app/Modules/Quality/Controllers/CapabilityController.php:31-40,57-107`; the pre-existing terminal-reading query changes in `api/app/Modules/Quality/Services/SpcService.php:139-180` were preserved. Coverage includes mismatch and terminal-only sampling in `api/tests/Feature/Quality/QualityAnalyticsBoundaryTest.php:72-165`.
- **Item 4 / M059-B05/B06 — fixed.** Before: future calibration dates and explicit null frequency could pass the request boundary. After: date validation is at `api/app/Modules/Quality/Requests/RecordCalibrationRequest.php:16-20`, service defense-in-depth is at `api/app/Modules/Quality/Services/CalibrationService.php:50-60`, and frequency validation/guard is at `api/app/Modules/Quality/Requests/StoreCalibrationRecordRequest.php:22-35` and `api/app/Modules/Quality/Services/CalibrationService.php:121-126`. HTTP and service regressions are covered in `api/tests/Feature/Quality/CalibrationBoundaryTest.php:18-53` and `api/tests/Feature/Quality/CalibrationRegisterTest.php:113-125`.
- **Item 5 / M059-I01/I02 — deferred.** No code change was made. A product decision is required for whether editing frequency reschedules from the last completed calibration, and whether archived specs remain valid for historical analytics. The remaining archived direct-SPC path is recorded in `audit-report.md`; guessing here could change metric meaning.
- **Item 6 / M059-B07/P02 — fixed.** Before: insufficient capability data could return a successful null result and the SPA showed no actionable state. After: the controller returns `quality_capability_insufficient_samples` at `api/app/Modules/Quality/Controllers/CapabilityController.php:93-98`, and the SPA renders an actionable empty state at `spa/src/pages/quality/capability/index.tsx:131-140,234-241`.
- **Item 7 / M059-I03 — fixed.** Before: malformed product filters silently became unfiltered analytics. After: all analytics endpoints fail closed through `decodeProductFilter()` at `api/app/Modules/Quality/Controllers/AnalyticsController.php:22-62,65-77`; the invalid-filter regression is at `api/tests/Feature/Quality/QualityAnalyticsBoundaryTest.php:62-70`.
- **Item 8 / M059-M01/P03 — fixed.** Before: calibration had no usable SPA surface and capability was absent from Quality navigation. After: the calibration API client and list/form/record flow are at `spa/src/api/quality/calibration.ts:1-20`, `spa/src/pages/quality/calibration/index.tsx:29-189` (including manage-only row navigation at `:149`), and `spa/src/pages/quality/calibration/form.tsx:46-147`; permission-gated routes are at `spa/src/routes/qualityRoutes.tsx:23-48,71-73`, and navigation is at `spa/src/components/layout/Sidebar.tsx:422-467`.
- **Item 9 / M059-P01 — fixed.** Before: pass-rate and open-NCR failures looked like missing values with no retry path. After: KPI error values and compact retry states are at `spa/src/pages/quality/dashboard.tsx:64-85`.
- **Item 10 / M059-M02 — improved and verified by isolated runs.** Added route/service boundary coverage in `api/tests/Feature/Quality/QualityAnalyticsBoundaryTest.php:25-254` and `api/tests/Feature/Quality/CalibrationBoundaryTest.php:18-78`, including permission denial, validation, ownership, lifecycle, and no-data contracts. The shared Quality suite was not used as a gate because another process was concurrently refreshing `ogami_test`.
- **Item 11 / M059-P04 — fixed for the post-COPQ scope cut.** Removed stale COPQ route/cron/event/role-matrix/permission references from `docs/PROCESS-FLOWS.md:1264-1269,1678-1688`, `docs/AUTO-BROWSER-TESTS.md:114-124`, `spa/e2e/helpers-extended.ts:94-95,110-111,192-193`, and `CLAUDE.md`; no COPQ occurrence remains in the checked files.
- **Item 12 — deferred.** Production-manager calibration access remains an open RBAC/product question documented in `audit-report.md`; no permission was broadened without confirmation.

Verification: isolated analytics tests passed **6 tests / 20 assertions**; isolated calibration tests passed **11 tests / 23 assertions**. PHP lint passed for changed backend files and targeted ESLint passed for changed M059 SPA files. Full SPA typecheck remains blocked by the unrelated pre-existing parser error at `spa/src/pages/production/work-orders/detail.tsx:624`. The module is released as `🔁 Needs Re-audit` pending the policy decisions, a quiet shared-schema run, and SPA typecheck/build/browser verification.

## 2026-08-27 — separate-session verification run (own DB `ogami_w2_qc`)

This session is the "separate session" the 2026-08-25 items were released against. It re-ran the module's tests against a freshly migrated schema and repaired the one genuine defect found.

- **`QualityAnalyticsBoundaryTest::test_capability_uses_only_passed_and_failed_inspection_measurements` — fixed (test fixture, not production code).**
  Before: all eight fixture readings were `'10.0000'` at `api/tests/Feature/Quality/QualityAnalyticsBoundaryTest.php:184` (pre-edit), so the five terminal samples had zero sigma. `SpcService::compute()` correctly returns `null` for σ < 1e-10 (`api/app/Modules/Quality/Services/SpcService.php:93-95`) and `CapabilityController::capability()` correctly turned that into the typed 422 `quality_capability_insufficient_samples` (`api/app/Modules/Quality/Controllers/CapabilityController.php:94-99`). The assertion of 200 was the wrong expectation, not the guard.
  After: the fixture now carries real spread — five terminal readings averaging 10.0200 with sample σ 0.1581, and three non-terminal readings parked at 10.9000 so a population leak moves the mean, not only the count — at `api/tests/Feature/Quality/QualityAnalyticsBoundaryTest.php:156-192`, with `data.mean` and `data.std_dev` now asserted alongside `data.sample_count` at `:201-203`. The zero-variance guard was **not** weakened.
  Also added: `test_capability_refuses_zero_variance_readings_instead_of_reporting_infinite_capability` at `api/tests/Feature/Quality/QualityAnalyticsBoundaryTest.php:206-259` — six terminal readings all `'10.0000'` (well past the 5-sample minimum) must still 422, pinning the guard so a later session cannot "fix" it by deleting the σ check.
  Evidence: `QualityAnalyticsBoundaryTest` — **7 passed / 24 assertions**.
- **Diagnosis correction.** The inherited diagnosis also claimed the fixture's spec/item/inspections lacked `inspection_spec_revision_id` and were therefore rejected by `computeCapabilityStudy()`. That half is **wrong**: `InspectionSpecItem::booted()` (`api/app/Modules/Quality/Models/InspectionSpecItem.php:27-39`) and `Inspection::booted()` (`api/app/Modules/Quality/Models/Inspection.php:34-46`) auto-fill the revision through `InspectionSpec::ensureCurrentRevision()` (`api/app/Modules/Quality/Models/InspectionSpec.php:68-78`) precisely for direct model writers like this fixture. Zero variance was the sole cause — proven by the test going green on a fixture change that touched only `measured_value`.

### ⚠️ CROSS-MODULE ENTRY — belongs to `inventory/goods-receiving` (M0xx), fixed here under coordinator authorisation

Read this if you own **inventory/goods-receiving**. A Quality-scoped session was authorised to repair one test in your module because the guard it tripped over is Quality-owned. Nothing else in your module was touched.

- **`Tests\Feature\Inventory\LotTraceabilityTest::test_incoming_resin_qc_attributes_persist_on_grn_line` — fixed (test was wrong; the guard is correct).**
  Before: the test received a GRN line with `'coa_verified' => true` and asserted `assertTrue($line->coa_verified)` at `api/tests/Feature/Inventory/LotTraceabilityTest.php:193,199` (pre-edit). That is precisely the behaviour your own audit finding **GRN-07** classified as broken — a warehouse receiver self-certifying a supplier Certificate of Analysis. The test predates the guard your GRN-07 fix added at `api/app/Modules/Inventory/Services/GrnService.php:140-144` (`create()`) and `:378-382` (`finalizeDraft()`), reinforced by `['prohibited']` rules at `api/app/Modules/Inventory/Requests/StoreGrnRequest.php:60`, `api/app/Modules/Inventory/Requests/FinalizeGrnRequest.php:30`, and `api/app/Modules/Inventory/Controllers/GoodsReceiptNoteController.php:170`.
  After: the receiving payload no longer carries `coa_verified`; the test asserts the receiver-owned fields still persist (`moisture_percentage`, `coa_document_path`, `material_lot_number`) and that the line lands **unverified** — `api/tests/Feature/Inventory/LotTraceabilityTest.php:183-204`. The guard was **not** weakened, and no production code was changed.
  Added alongside it: `test_receiving_cannot_self_certify_the_supplier_coa` at `api/tests/Feature/Inventory/LotTraceabilityTest.php:207-266`, which pins the service-level refusal. Before this session the `['prohibited']` request rules and both service guards had **zero test coverage anywhere in `api/tests`** (`grep -rn 'coa_verified' api/tests` returned only the now-fixed assertions), so a later session could have deleted the guard and the suite would still have gone green.
  Evidence: `LotTraceabilityTest` — **5 passed / 18 assertions**.
- **Reaches beyond the one test (Quality-owned, NOT fixed here — needs a business decision).** `coa_verified` currently has **no writer that can ever set it true**. The only writes in the codebase are the two hard-coded `false` literals at `api/app/Modules/Inventory/Services/GrnService.php:240` and `:437`. The Quality incoming-QC path does not touch it either: `api/app/Modules/Quality/Listeners/TriggerIncomingQC.php` creates a per-line incoming inspection and `api/app/Modules/Quality/Services/InspectionService.php:203-265` records the verdict, but neither references `coa_verified`. Meanwhile `api/app/Modules/Inventory/Resources/GrnItemResource.php:38` publishes the flag and the SPA renders it, so the UI shows a permanently-unverified COA on every received resin lot. Your own fix-log already records this as deliberately deferred ("Quality's COA verification transition also remain undecided"), so this is a known gap, not new breakage — but it means the Chain 2 control "verify resin certs before accepting inventory" is currently only half-closed: the document is captured and the verification is refused to everyone. Options are written up in `audit-report.md` under *COA verification has no owner*; no option was chosen, because deciding who may certify a supplier certificate is a business/IATF decision, not an audit-session one.

### Re-verification of the 2026-08-25 items (previously "done but unexecuted")

The prior session shipped source it could not run: a broken migration made `migrate:fresh` fail repo-wide until 2026-08-26, so its "verified" claims were source-only. Every backend item was re-run here on a private, freshly migrated database (`ogami_w2_qc`), one test class per `--filter` invocation.

| Prior item | Status now | Evidence |
|---|---|---|
| 1, 2 (Pareto denominator + terminal population) | **Verified** | `QualityAnalyticsBoundaryTest` 7/24 |
| 3 (capability ownership + terminal readings) | **Verified** | same run; the failing member of this group was the fixture, now fixed |
| 4 (calibration future date + frequency default) | **Verified** | `CalibrationBoundaryTest` 3/8; `CalibrationRegisterTest` 7/12 |
| 6 (typed no-data contract) | **Verified** at the API; source-only in the SPA | 422 asserted in `QualityAnalyticsBoundaryTest`; the SPA arm exists at `spa/src/pages/quality/capability/index.tsx:133` and lints clean, but was not exercised in a browser |
| 7 (fail-closed product hash filter) | **Verified** | `QualityAnalyticsBoundaryTest` |
| 8 (calibration SPA + routes + sidebar) | **Source-only** | files present, routes permission-gated at `spa/src/routes/qualityRoutes.tsx:44-49,74-75`, sidebar at `spa/src/components/layout/Sidebar.tsx:433-448`, ESLint clean — no browser walk, no typecheck (see below) |
| 9 (dashboard KPI error/retry) | **Source-only** | `spa/src/pages/quality/dashboard.tsx:66-95` present and lints clean |
| 10 (route/boundary coverage) | **Verified** | all four calibration/analytics classes green |
| 11 (COPQ drift removal) | **Verified** | `grep -rn 'copq\|COPQ'` over `docs/PROCESS-FLOWS.md`, `docs/AUTO-BROWSER-TESTS.md`, `spa/e2e/helpers-extended.ts`, `CLAUDE.md`, `api/routes`, `api/app` returns nothing |
| 5, 12 (policy decisions) | **Still deferred** | no decision recorded; see *Open questions* in `audit-report.md` |

Adjacent classes run as regression cover, all green: `CalibrationBackdatedRecordRaceTest` 1/3, `QualityInspectionSummaryTest` 1/4, `SpcServiceTest` (unit) 9/29. `php -l` clean on both changed test files.

**SPA typecheck still not verified, and not for the previously reported reason.** The parse error at `spa/src/pages/production/work-orders/detail.tsx:624` that blocked the prior session has since been repaired by another session. `tsc --noEmit` now fails for an environment reason instead: it exceeds an 800 MB V8 heap on this host (`FATAL ERROR: Ineffective mark-compacts near heap limit`, exit 134). The cap was deliberate — three other audit sessions were live and the host was OOM-killed the previous day — so the run was contained rather than retried larger. Targeted ESLint over all six changed/claimed M059 SPA files passes with `--max-warnings 0`. The typecheck and the calibration browser walk remain the outstanding gates.

