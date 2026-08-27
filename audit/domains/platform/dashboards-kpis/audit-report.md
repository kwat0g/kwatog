# M007 — Dashboards & KPIs audit

Audit date: 2026-08-27
Branch: `audit/2026-08-26-five-modules`
Claim: `platform / dashboards-kpis` (M007, preferred target; atomically claimed)
Status: 📋 Plan Ready

## Executive outcome

The previously reported schema, authorization, workflow, metric-source, layout, and
scorecard-loading defects are implemented in the current branch and the backend
dashboard gate is green. The re-audit still finds open KPI lifecycle, period-input,
metric-contract, financial precision, and dashboard consistency issues. No production
code was changed in this session because the ordered plan is not predominantly small
and same-session-safe.

Do not promote M007 to `✅ Verified`. Release it as `📋 Plan Ready` and schedule a
separate hardening slice for the open findings below.

## Discovery, hardening, and polish scope

- Registry row and inherited `status.md`, `audit-report.md`, `action-plan.md`, and
  `fix-log.md`; the coordinator registry was not regenerated or edited.
- Dashboard routes/middleware, dispatch, catalog, role services, PanelGate, layout
  persistence, widgets, rich analytics, badges, action center, KPI seed/catalog,
  scheduler, calculators, migrations, and permissions.
- SPA dashboard routes/pages, scorecard, KPI API client, dashboard links, and the
  quality inspection worklist.
- Current worktree diff and target mtimes. M007 had no uncommitted production or test
  changes on entry; unrelated leave-management edits were preserved.
- Inherited fix log and prior verification claims were treated as historical evidence,
  then rechecked on this claim using the unique database below.

## Finding status

| ID | Classification | Priority | Current status | Scope | Session recommendation |
| --- | --- | --- | --- | --- | --- |
| M007-01 | Broken | P0 | Resolved schema defect; formula sign-off deferred | Large | separate-recommended |
| M007-02 | Broken | P0 | Resolved and verified | Medium | separate-recommended |
| M007-03 | Incomplete | P1 | Resolved by closure-based gates; focused tests pass | Large | separate-recommended |
| M007-04 | Broken | P1 | Resolved and verified | Medium | separate-recommended |
| M007-05 | Broken | P1 | Resolved and verified | Medium | separate-recommended |
| M007-06 | Incomplete | P1 | Resolved and verified | Medium | separate-recommended |
| M007-07 | Broken | P1 | Resolved statically; browser check deferred | Small | same-session-ok |
| M007-08 | Incomplete | P2 | Resolved and verified | Medium | separate-recommended |
| M007-09 | Incomplete | P2 | Resolved and verified | Small | same-session-ok |
| M007-10 | Missing | P1 | Resolved; seeded catalog guard passes | Medium | separate-recommended |
| M007-11 | Incomplete | P2 | Resolved and verified | Medium | separate-recommended |
| M007-12 | Polish | P2 | Resolved in source; SPA tests/browser deferred | Medium | separate-recommended |
| M007-13 | Incomplete | P1 | Open: completion-rate populations can diverge | Medium | separate-recommended |
| M007-14 | Incomplete | P1 | Open: soft-deleted work orders are counted | Medium | separate-recommended |
| M007-15 | Incomplete | P1 | Open: inactive KPIs remain readable/renderable | Medium | separate-recommended |
| M007-16 | Missing | P1 | Open: KPI year/month inputs are not validated | Medium | separate-recommended |
| M007-17 | Incomplete | P1 | Open: draft inspections are omitted from the QC queue | Small | same-session-ok |
| M007-18 | Incomplete | P2 | Open: “My Action Items” is a global HR queue | Medium | separate-recommended |
| M007-19 | Incomplete | P1 | Open: dashboard monetary paths cast decimals to float | Large | separate-recommended |
| M007-20 | Incomplete | P2 | Open: no-data recompute leaves a stale snapshot | Medium | separate-recommended |
| M007-21 | Incomplete | P2 | Open: generic upcoming payables includes draft bills | Small | same-session-ok |
| M007-22 | Polish | P3 | Open: scorecard loading skeleton is hard-coded to eight cards | Small | same-session-ok |

## Resolved findings and evidence

### M007-01 — Inventory turnover schema references

Classification: Broken; original priority P0. Current status: resolved schema defect;
formula policy still needs inventory-owner sign-off.

- `api/app/Modules/Dashboard/Services/KpiSnapshotService.php:568-595` now uses
  `movement_type`, `StockMovementType::MaterialIssue`, `total_cost`, and
  `quantity * weighted_avg_cost`, with an explicit current-inventory policy.
- `api/database/seeders/KpiDefinitionSeeder.php:24` keeps the KPI active.
- `api/tests/Feature/Dashboard/KpiComputeProcessTest.php:306-317` exercises the
  ledger fixture; the seeded active catalog passes without a SQL failure.

The runtime schema failure is closed. The annualized COGS/current-ending-inventory
definition should be accepted by the inventory owner before final verification.

### M007-02 — AR aging invoice column and population

Classification: Broken; original priority P0. Current status: resolved and verified.

- `api/app/Modules/Dashboard/Services/KpiSnapshotService.php:485-511` sums the
  authoritative `balance`, limits rows to finalized/partial invoices through the
  requested period end, and applies the configured lookback cutoff.
- `api/tests/Feature/Dashboard/KpiComputeProcessTest.php:250-278` covers finalized,
  partial, draft, paid, cancelled, and period-boundary rows.

### M007-03 — Gate-before-query behavior

Classification: Incomplete; original priority P1. Current status: resolved by the
current closure-based implementation; focused permission tests pass.

- `api/app/Modules/Dashboard/Support/PanelGate.php:39-49` evaluates permission before
  invoking the query closure.
- `api/app/Modules/Dashboard/Services/FinanceDashboardService.php:61-95`,
  `HrDashboardService.php:47-69`, and `PpcDashboardService.php:40-73` place data
  reads inside permission-bearing closures; the same pattern is present in the
  purchasing, warehouse, quality, and admin services.
- `api/tests/Feature/Dashboard/DashboardPanelGateTest.php` passes its denied-panel
  and cache-isolation cases.

### M007-04 — Quality action-center source authorization

Classification: Broken; original priority P1. Current status: resolved and verified.

- `api/app/Modules/Dashboard/Services/ActionCenterTaskService.php:90-121` parses
  `quality:inspection:` and `quality:ncr:` separately, requiring the matching narrow
  permission or `quality.view`.
- `api/tests/Feature/Dashboard/ActionCenterControllerTest.php` passes the inspection,
  NCR-only, broad-grant, malformed-key, and unknown-key cases.

### M007-05 — Warehouse state/type drift

Classification: Broken; original priority P1. Current status: resolved and verified.

- `api/app/Modules/Dashboard/Services/WarehouseDashboardService.php:39-46` uses
  inventory enums, material-issue movements, and `transfer_orders.status = pending`.
- `api/tests/Feature/Dashboard/RoleDashboardServiceTest.php` and the full dashboard
  suite pass the warehouse fixture coverage.

### M007-06 — Role-dashboard metric source drift

Classification: Incomplete; original priority P1. Current status: resolved and verified.

- Plant revenue now filters finalized/partial/paid invoices at
  `api/app/Modules/Dashboard/Services/PlantManagerDashboardService.php:134-145`.
- Quality pass rate now uses completed passed/failed outcomes at
  `api/app/Modules/Dashboard/Services/QualityDashboardService.php:71-81`.
- CoC MTD now counts non-deleted `delivery_proofs` with `proof_type = coc` at
  `api/app/Modules/Dashboard/Services/QualityDashboardService.php:83-91`.
- `api/tests/Feature/Dashboard/RoleDashboardServiceTest.php` passes the metric
  regression cases.

### M007-07 — Dead CoC drill-down

Classification: Broken; original priority P1. Current status: resolved statically;
authenticated browser verification is deferred.

- `spa/src/lib/dashboardLinks.ts:109-117` now targets the registered inspection
  worklist with outgoing/passed/current-month filters.
- `spa/src/routes/qualityRoutes.tsx:50-55` registers `/quality/inspections` and its
  permission guard.

### M007-08 — Historical supplier-review duplicates

Classification: Incomplete; original priority P2. Current status: resolved and verified.

- `api/app/Modules/Dashboard/Services/PurchasingDashboardService.php:158-183` uses
  the latest period and distinct `vendor_id` values below the configured threshold.
- The supplier panel at `:137-155` uses the same latest-period boundary.

### M007-09 — Hard-coded probation period

Classification: Incomplete; original priority P2. Current status: resolved and verified.

- `api/app/Modules/Dashboard/Services/HrDashboardService.php:205-240` uses the
  configured `hr.probation.period_months` for both membership and `probation_end`.
- The three-month configuration regression passes in
  `api/tests/Feature/Dashboard/RoleDashboardServiceTest.php`.

### M007-10 — Seeded KPI process coverage

Classification: Missing; original priority P1. Current status: resolved; the seeded
catalog guard now passes.

- `api/tests/Feature/Dashboard/KpiComputeProcessTest.php:67-77` seeds the real
  `KpiDefinitionSeeder`, runs `computeAll`, and asserts no active definition fails.
- The same guard exposed and the current branch fixed the remaining `dppm`, budget,
  and work-order schema/status defects.

### M007-11 — Concurrent role-layout cloning

Classification: Incomplete; original priority P2. Current status: resolved and verified.

- `api/app/Modules/Dashboard/Services/DashboardLayoutService.php:105-141` locks the
  user row before checking and inserting user layout rows.
- `api/tests/Feature/Admin/DashboardLayoutTest.php` passes idempotency, save/reset,
  visibility, and version-conflict cases.

### M007-12 — Scorecard trend request fan-out

Classification: Polish; original priority P2. Current status: resolved in source;
SPA unit/browser verification is deferred.

- `api/app/Modules/Dashboard/Services/KpiSnapshotService.php:202-253` implements the
  permission-aware batch trend query with a 24-month clamp.
- `spa/src/pages/dashboard/scorecard.tsx:83-92` makes one batch request for visible
  codes, and `spa/src/api/kpi.ts:22-25` calls the batch endpoint.
- `api/tests/Feature/Dashboard/KpiScorecardTest.php:69-91` passes the batch response
  case.

## Open findings

### M007-13 — Work-order completion rate mixes populations

Classification: Incomplete
Priority: P1
Scope: Medium
Session recommendation: separate-recommended

Evidence:

- `api/app/Modules/Dashboard/Services/KpiSnapshotService.php:627-647` counts the
  denominator by `planned_end` in the requested month but counts the numerator by
  `actual_end` in that month.
- `api/app/Modules/Dashboard/Services/KpiSnapshotService.php:611-620` documents the
  mismatch and the possibility of a value above 100%.
- `api/database/migrations/0080_create_work_orders_table.php:45-48` confirms the two
  independent date columns; `KpiDefinitionSeeder.php:25` labels the result “WO
  Completion Rate”.

Impact: a work order due in July but completed in August is a July miss and an
August numerator hit. Production/PPC must choose a due-cohort rate, a completion-
throughput rate, or a renamed metric before the KPI is decision-safe.

### M007-14 — Soft-deleted work orders enter the rate

Classification: Incomplete
Priority: P1
Scope: Medium
Session recommendation: separate-recommended

Evidence:

- `api/app/Modules/Production/Models/WorkOrder.php:24-26` uses `SoftDeletes`, and
  migration `api/database/migrations/0444_add_soft_deletes_to_all_tables.php:68-81`
  adds `deleted_at`.
- Both raw queries in `api/app/Modules/Dashboard/Services/KpiSnapshotService.php:627-647`
  omit `whereNull('deleted_at')`.
- The same service's attendance calculator at `:459-483` explicitly excludes soft
  deletes, so dashboard KPI history has inconsistent deletion policy.

Impact: deleted/cancelled historical work can inflate the denominator and depress the
rate. Confirm whether deleted operational history is excluded, then apply and test a
single policy across the related calculators.

### M007-15 — Inactive KPI definitions remain exposed

Classification: Incomplete
Priority: P1
Scope: Medium
Session recommendation: separate-recommended

Evidence:

- `api/app/Modules/Dashboard/Models/KpiDefinition.php:49-52` defines the active
  scope, and `KpiSnapshotService.php:133` uses it for the scorecard.
- The individual and batch trend reads at
  `api/app/Modules/Dashboard/Services/KpiSnapshotService.php:169-181` and `:213-250`
  query definitions by code without `is_active`.
- Rich widget analytics at
  `api/app/Modules/Dashboard/Services/Analytics/KpiWidgetAnalytics.php:51-59` and
  scalar widget reads at `DashboardWidgetDataService.php:297-315` also omit the
  active predicate, despite the rich provider comment saying a deactivated
  definition should degrade.

Impact: an authenticated user with the module grant can still retrieve snapshots for
a retired definition, and an existing layout can render its stale scalar/trend. Make
all read surfaces honor the same lifecycle boundary and add inactive-definition
negative tests.

### M007-16 — KPI period inputs are not validated

Classification: Missing
Priority: P1
Scope: Medium
Session recommendation: separate-recommended

Evidence:

- `api/app/Modules/Dashboard/Controllers/KpiController.php:16-21` and `:40-51`
  cast raw query/body values to integers without validating a supported year or
  month range.
- `api/app/Modules/Dashboard/routes.php:91-96` exposes these endpoints directly to
  authenticated users, with only the compute route restricted to admins.
- `api/database/migrations/0260_create_kpi_snapshots_table.php:16-27` stores integer
  periods and has uniqueness but no month 1..12 constraint. The command signature
  claims 1..12 at `api/app/Console/Commands/ComputeMonthlyKpis.php:26`, while the
  controller path has no equivalent guard.

Impact: invalid scorecard periods silently return empty snapshots, and an admin
backfill can pass a month outside 1..12 to calculators that normalize dates while
the service persists the raw period key. Validate at the request/command boundary
and add invalid-period tests.

### M007-17 — Dedicated QC queue omits draft inspections

Classification: Incomplete
Priority: P1
Scope: Small
Session recommendation: same-session-ok

Evidence:

- `api/app/Modules/Quality/Enums/InspectionStatus.php:8-14` defines `draft` as an
  inspection with a measurement scaffold and no readings yet.
- `api/app/Modules/Dashboard/Services/QualityDashboardService.php:42` and `:103-106`
  count/list only `in_progress` inspections.
- New inspections are created in `draft` at
  `api/app/Modules/Quality/Services/InspectionService.php:351-369`.
- The generic widget and badge use both draft and in-progress at
  `api/app/Modules/Dashboard/Services/DashboardWidgetDataService.php:109` and
  `api/app/Modules/Dashboard/Services/BadgeService.php:408-415`.

Impact: the dedicated QC dashboard says the queue is empty while newly created,
unmeasured inspections still await completion. Align the KPI, queue, badge, and
drill-down semantics and add a draft fixture.

### M007-18 — “My Action Items” is not self-scoped

Classification: Incomplete
Priority: P2
Scope: Medium
Session recommendation: separate-recommended

Evidence:

- `api/app/Modules/Dashboard/Services/HrDashboardService.php:62-63` describes the
  panel as self-scoped and ungated, passing `$user` to the helper.
- `HrDashboardService.php:332-349` never uses `$user`; it counts every `pending_hr`
  leave, every pending profile update, and every pending clearance.
- `spa/src/pages/dashboard/hr.tsx:286-318` labels the result “My Action Items” and
  tells the viewer that the items require their attention.

Impact: the HR officer sees a company-wide queue presented as a personal queue. Either
scope each source to the current actor/approval stage or rename and permission the
panel as an HR-wide action queue.

### M007-19 — Monetary dashboard paths cast decimal values to float

Classification: Incomplete
Priority: P1
Scope: Large
Session recommendation: separate-recommended

Evidence:

- `api/app/Common/Support/Money.php:8-10` states that currency operations must use
  decimal strings and never floats.
- Dashboard currency paths cast sums through float at
  `api/app/Modules/Dashboard/Services/Concerns/DashboardQueries.php:44-66`,
  `api/app/Modules/Dashboard/Services/DashboardWidgetDataService.php:324-341`,
  `api/app/Modules/Dashboard/Services/PlantManagerDashboardService.php:134-145`,
  and `api/app/Modules/Dashboard/Services/PurchasingDashboardService.php:100-107`.
- KPI monetary inputs also cast through float at
  `api/app/Modules/Dashboard/Services/KpiSnapshotService.php:497-505` and
  `:534-548`; the scorecard parses decimal strings as JavaScript floats at
  `spa/src/pages/dashboard/scorecard.tsx:54-66` and `:174-176`.

Impact: dashboard financial values do not follow the repository's money contract and
can lose precision or disagree with the accounting service. Centralize decimal-string
formatting with `Money`/BCMath and keep currency values as strings through the API and
SPA.

### M007-20 — No-data recompute preserves a stale snapshot

Classification: Incomplete
Priority: P2
Scope: Medium
Session recommendation: separate-recommended

Evidence:

- `api/app/Modules/Dashboard/Services/KpiSnapshotService.php:95-100` returns `null`
  before deleting or replacing an existing snapshot for the same definition/period.
- The service otherwise uses `updateOrCreate` at `:118-128`, and the command describes
  the job as an idempotent re-computation at
  `api/app/Console/Commands/ComputeMonthlyKpis.php:14-17`.
- The comment at `KpiSnapshotService.php:95-98` says the scorecard should expose the
  definition without a snapshot, which is false if a prior run already persisted it.

Impact: a corrected or deleted source dataset can leave an old value visible as the
authoritative current-period KPI. Define whether snapshots are immutable or
recomputed; if recomputed, clear/mark stale rows when the calculator returns no data.

### M007-21 — Generic upcoming payables includes draft bills

Classification: Incomplete
Priority: P2
Scope: Small
Session recommendation: same-session-ok

Evidence:

- `api/app/Modules/Dashboard/Services/DashboardWidgetDataService.php:120` filters
  only due date and positive balance for `finance.upcoming_payables`.
- `api/app/Modules/Accounting/Enums/BillStatus.php:9-17` defines `draft` separately
  from payable `unpaid` and `partial` states.
- The authoritative finance panel applies the narrower status set at
  `api/app/Modules/Dashboard/Services/FinanceDashboardService.php:224-229`, while
  the generic widget is seeded for the bills-read permission at
  `api/database/seeders/DashboardWidgetSeeder.php:195-201`.

Impact: a draft supplier bill with a balance and future due date inflates the generic
payables tile while the finance dashboard excludes it. Align the widget status filter
and add draft/paid/cancelled fixtures.

### M007-22 — Scorecard loading count is stale

Classification: Polish
Priority: P3
Scope: Small
Session recommendation: same-session-ok

Evidence:

- The seeded catalog contains 11 definitions at
  `api/database/seeders/KpiDefinitionSeeder.php:14-25`.
- `spa/src/pages/dashboard/scorecard.tsx:117` passes `kpiCount={8}` to the loading
  shell, while the rendered grid maps all returned definitions at `:159-163`.

Impact: the loading state renders fewer placeholders than the configured scorecard.
Derive the placeholder count from the catalog or use a neutral loading state.

## Verification

All backend commands below used only `DB_DATABASE=ogami_test_m007_roll_a`.

| Check | Result | Evidence |
| --- | --- | --- |
| `tests/Feature/Dashboard` | PASS | 137 tests, 631 assertions |
| `tests/Feature/Admin/DashboardLayoutTest.php` | PASS | 9 tests, 27 assertions |
| Combined backend dashboard gate | PASS | 146 tests, 658 assertions |
| Seeded KPI compute process | PASS | `KpiComputeProcessTest`: 7 tests, 15 assertions; active catalog has zero failures |
| Seeded monthly command | PASS | After `KpiDefinitionSeeder`, `kpi:compute-monthly --year=2026 --month=7` returned `computed=0 no_data=11 failed=0` |
| KPI route registration | PASS | `php artisan route:list --path=dashboard/kpi` shows 4 expected routes |
| PHP syntax | PASS | Dashboard PHP syntax check required before commit |
| `git diff --check` | PASS | Required before commit |
| SPA typecheck | BLOCKED externally | `qrcode` is declared but absent from the existing root-owned `spa/node_modules`; errors are in unmodified `src/pages/assets/detail.tsx` |
| SPA KPI unit test | BLOCKED externally | Vitest cannot write `spa/node_modules/.vite-temp` (`EACCES`, root-owned) |
| Browser role/dynamic-route audits | BLOCKED externally | Playwright receives `ERR_CONNECTION_REFUSED` at `http://localhost`; no SPA server is running |
| M007-07 route check | PASS statically | Link targets registered `/quality/inspections` route with permission guard |

## Gate decision

The plan is not predominantly `same-session-ok`, includes large/medium financial and
metric-contract work, and has multiple P1 findings. The gate therefore forbids
production fixes in this session. Only the audit report, action plan, verification log,
and release metadata are changed.

## Required next action

Run the separate action plan beginning with M007-15/M007-16 authorization and period
boundaries, then resolve M007-13/M007-14 metric policy and M007-19 decimal handling.
Add focused regressions for M007-17/M007-21, clarify M007-18, and rerun the SPA unit,
browser, and route-role gates once the environment blocker is removed. Keep M007 at
`📋 Plan Ready` until the open findings and deferred checks are closed.
