# M059 — Action Plan

Updated 2026-08-30 (re-audit). Released status: 🔁 Needs Re-audit.

The 2026-08-27 plan is superseded. Its items 6 (B09) and 8 (P05, P06) are done;
its remaining items are re-ordered below against what actually reproduces, plus
one new severe finding (B11) that was fixed this session and five new findings
that are not.

## Done this session — no further action

| Finding | What landed |
|---|---|
| M059-B11 | `CalibrationService::update()` no longer re-fills from `$record->toArray()`; PATCH returns 200 instead of 500 in every non-production environment. First test coverage the PATCH path has ever had. |
| M059-I03 | Same rewrite: `lockForUpdate()` re-read makes the stored row authoritative, so concurrent PATCHes no longer overwrite each other. |
| M059-B10 | `before_or_equal:today` on `last_calibration_date` at the request; merged-pair date-order invariant in the service as a field-level 422. Zod + `max` mirror it in the form. |
| M059-P05 | Shared Pareto chart takes its series name and value formatter from `valueLabel`; the maintenance consumer is provably unchanged. |
| M059-P06 | Calibration edit form uses `SkeletonBlock` / `QueryErrorState` with a working retry, keeping the `PageHeader` on both states. |
| M059-P07 | "Total defects" tile reports `Unavailable` + `Retry below` on error; helper corrected to "failed measurements in the window". |
| M059-B09 | Confirmed already fixed and committed (`89382380`) before this session; the prior report listed it open in error. |

## Remaining, in order

| Order | Finding(s) | Ordered action and acceptance evidence | Scope | Session recommendation |
|---:|---|---|---|---|
| 1 | M059-B08 | Decide the capability role contract, then implement one of: a Quality-owned product/spec read endpoint; an explicit `crm.products.view` grant to `qc_inspector`; or narrowing the route/sidebar guard to `quality.specs.view`. Acceptance: route-level 200/403 tests for `qc_inspector`, `production_manager` and `system_admin`, plus a Chromium walk proving the product dropdown populates for whichever roles the decision admits. Do not widen a role without recording the decision. | large | **separate-recommended** — permissions/RBAC, cross-module |
| 2 | M059-M04 | Wire an overdue/due-calibration notification through the existing `NotificationService::send()` entry point, and decide recipients (the record's `responsible`, the QC role, maintenance). Add a `calibration.overdue` alert or dashboard widget row if that is the chosen surface. Acceptance: a test asserting a notification is dispatched exactly once per instrument per transition, and that a second sweep the same day does not re-notify. | medium | **separate-recommended** — new cross-module notification wiring; needs a recipient decision |
| 3 | M059-M03, M059-I06 | Seed the calibration register (a `CalibrationRecordFactory` plus a handful of demo gauges spanning active/due/overdue/retired), and make `calibration:check-due` report the number of rows scanned so "nothing to do" is distinguishable from "nothing there". Validate `quality.calibration.due_window_days` once before the sweep instead of throwing mid-chunk. Acceptance: `migrate:fresh --seed` yields a non-empty register in each status, and the command prints a scanned count. | medium | **separate-recommended** — touches shared seeders that other modules' fixtures read |
| 4 | M059-I04 | Choose the retired-instrument lifecycle: refuse `recordCalibration()` on a retired record with a `BusinessRuleException`, or require explicit reactivation first. Align the service docblock (which currently contradicts `statusFor()`), the list row actions, and add tests both ways. | medium | **separate-recommended** — changes a state-machine transition |
| 5 | M059-I01 | Define frequency-change semantics and encode them: either recompute `next_calibration_date` from `last_calibration_date + frequency_days` on a frequency change, or refuse a frequency change that would leave a next date inconsistent with it. Acceptance: a test pinning 365→30 behaviour. | medium | **separate-recommended** — changes what a register row means; pairs naturally with item 4 |
| 6 | M059-I02 | Decide whether archived/inactive specs are valid historical SPC inputs, then align `CapabilityController::options()`, `capability()`, the `->withTrashed()` spec-detail SPC route and `SpcService::computeForSpec()` to one answer. Acceptance: both paths give the same verdict for the same archived spec, asserted in a test. | medium | **separate-recommended** — changes metric meaning across two screens |
| 7 | M059-I05 | Either scope `options()`'s `spec_items` by product and have the SPA use it, or delete it and let the endpoint serve only `capability_thresholds` (renaming it accordingly). Acceptance: the response no longer carries other products' parameter names, and the capability page still populates its dimension dropdown. | small | same-session-ok |
| 8 | M059-M02 | Add the remaining SPA coverage — `spa/src/pages/quality/calibration/index.tsx` (5 list states, status filter, record modal incl. the future-date server error) and `spa/src/pages/quality/dashboard.tsx` — plus a **Chromium** browser walk for the Pareto tooltip label and per-role capability access. Lightpanda is not a fit here: the tooltip needs hover inside a `ResponsiveContainer`, i.e. real layout. | medium | **separate-recommended** — depends on item 1's decision for the role paths |

## Re-audit gate

M059 must not go ✅ Verified until items 1–6 are decided and implemented. Items 7
and 8 are the cheapest remaining work and can go in the same session as any of
the above.

When re-verifying, use a private database (`RefreshDatabase` runs `migrate:fresh`
and will tear the schema out from under a concurrent suite) and run:

- `docker compose run --rm -e DB_DATABASE=<own_db> api php artisan test tests/Feature/Quality/CalibrationBoundaryTest.php tests/Feature/Quality/CalibrationRegisterTest.php tests/Feature/Quality/CalibrationBackdatedRecordRaceTest.php tests/Feature/Quality/QualityAnalyticsBoundaryTest.php tests/Feature/Quality/QualityInspectionSummaryTest.php tests/Unit/SpcServiceTest.php` — baseline **33 passed / 100 assertions**
- `cd spa && npx vitest run src/pages/quality src/components` and `npx tsc --noEmit` (passes clean at this commit)
- Pint on `CalibrationService.php` / `StoreCalibrationRecordRequest.php` fails at
  HEAD and failed identically before this session's diff — do not "fix" it inside
  a feature change.

## Session evidence

- Backend, own DB `ogami_test_cqa`: **33 passed / 100 assertions**. Dropped after use.
- SPA: quality page tests **7 passed**, `src/components` **73 passed**, `tsc --noEmit` exit 0, ESLint `--max-warnings 0` clean over all changed files plus the shared-chart consumer.
- PHPStan clean on the three changed backend files.
- `calibration:check-due` executed against both an empty and a populated register; all three analytics queries executed against real PostgreSQL rows. Results in `audit-report.md`.
