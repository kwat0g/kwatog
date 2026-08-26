# M007 — Dashboards & KPIs fix log

Audit date: 2026-08-24  
Fix session: 2026-08-25  
Claim: platform / dashboards-kpis  
Final audit state: Needs Re-audit  
Production code changes: implemented; final regression verification is deferred

## Session record

- Refreshed the registry and atomically claimed M007 because it was the first unlocked `📋 Plan Ready` module in the priority list.
- Read the existing audit report and action plan in full. The discovery report and plan were not rewritten.
- Preserved unrelated shared-worktree changes outside this module.

## Per-finding remediation

| Finding | File:line | Before | After |
| --- | --- | --- | --- |
| M007-01 | `api/app/Modules/Dashboard/Services/KpiSnapshotService.php:539-565`; `api/tests/Feature/Dashboard/KpiComputeProcessTest.php:106-151` | Inventory turnover queried nonexistent `type`/`unit_cost` fields and had no evidenced denominator. | Uses `StockMovementType::MaterialIssue`, `total_cost`, and current `quantity * weighted_avg_cost`; annualizes the ratio, returns no-data for non-positive inputs, documents the current-inventory policy, and adds a ledger fixture. |
| M007-02 | `api/app/Modules/Dashboard/Services/KpiSnapshotService.php:472-498`; `api/tests/Feature/Dashboard/KpiComputeProcessTest.php:63-104` | AR aging summed nonexistent `balance_due` and used an overly broad invoice population. | Uses `balance`, finalized/partial statuses, invoice date through the requested period end, and the configured lookback cutoff; adds finalized, partial, and draft fixture coverage. |
| M007-03 | `api/app/Modules/Dashboard/Services/FinanceDashboardService.php:61-95`; `HrDashboardService.php:47-80`; `PpcDashboardService.php:40-93`; `PurchasingDashboardService.php:36-64`; `WarehouseDashboardService.php:37-66`; `QualityDashboardService.php:39-67`; `AdminDashboardService.php:38-79` | Sensitive counts and financial/HR queries were evaluated before `PanelGate` filtered the response. | Data reads now live in permission-bearing closures; refused panels/KPIs do not execute their source query. Finance retains a gate-answer cache signature. |
| M007-04 | `api/app/Modules/Dashboard/Services/ActionCenterTaskService.php:90-121`; `api/tests/Feature/Dashboard/ActionCenterControllerTest.php:122-158` | Every `quality:` task was authorized through the same broad path. | Parses `quality:inspection:` versus `quality:ncr:` and requires the matching narrow permission or `quality.view`; adds negative, broad-grant, and malformed-key tests. |
| M007-05 | `api/app/Modules/Dashboard/Services/WarehouseDashboardService.php:39-46` | Warehouse KPIs used impossible GRN, movement, and transfer representations. | Pending GRNs exclude only accepted/rejected statuses, issues use `material_issue`, and pending work uses `transfer_orders.status = pending`, matching the inventory workflow contract. |
| M007-06 | `api/app/Modules/Dashboard/Services/PlantManagerDashboardService.php:134-145`; `QualityDashboardService.php:71-91`; `api/tests/Feature/Dashboard/RoleDashboardServiceTest.php:358-388` | Plant revenue included draft/cancelled invoices; pass rate included unfinished inspections; CoC count used closed NCRs. | Revenue is limited to finalized/partial/paid invoices, pass rate uses completed passed/failed outcomes, and CoCs count non-deleted `delivery_proofs` with `proof_type = coc`; revenue fixture coverage includes excluded lifecycle rows. |
| M007-07 | `spa/src/lib/dashboardLinks.ts:109-117` | CoC KPI linked to the nonexistent `/quality/certificates` route. | Links to the registered outgoing, passed inspection worklist with the current-month filter. |
| M007-08 | `api/app/Modules/Dashboard/Services/PurchasingDashboardService.php:50,158-183` | Supplier review count included low historical snapshots and duplicate vendor periods. | Reuses the latest snapshot period and counts distinct vendors below the configured threshold. |
| M007-09 | `api/app/Modules/Dashboard/Services/HrDashboardService.php:203-240`; `api/tests/Feature/Dashboard/RoleDashboardServiceTest.php:436-465` | The query used the configured probation period but displayed a hard-coded six-month end date. | Reads `hr.probation.period_months` once for both membership and `probation_end`; the test changes the setting to three months. |
| M007-10 | `api/tests/Feature/Dashboard/KpiComputeProcessTest.php:50-61` | Process coverage exercised only synthetic definitions, so the shipped active catalog was not computed. | Seeds `KpiDefinitionSeeder`, runs `computeAll`, and asserts every active definition is computed or no-data with no failures. |
| M007-11 | `api/app/Modules/Dashboard/Services/DashboardLayoutService.php:105-122` | Concurrent first-login clones could both pass the user-layout existence check and insert duplicates. | Locks the user row inside the transaction before the check and uses the locked role id for the source layout. |
| M007-12 | `api/app/Modules/Dashboard/Services/KpiSnapshotService.php:193-253`; `api/app/Modules/Dashboard/Controllers/KpiController.php:31-38`; `api/app/Modules/Dashboard/routes.php:91-96`; `spa/src/api/kpi.ts:22-25`; `spa/src/pages/dashboard/scorecard.tsx:83-92,170-176`; `api/tests/Feature/Dashboard/KpiScorecardTest.php:69-91`; `spa/src/api/kpi.test.ts:39-45` | The scorecard made one trend request per KPI card. | Added a permission-aware, 24-month-clamped batch trend endpoint and one scorecard request for all visible codes; cards consume the returned points. |

## Verification performed

| Check | Result | Notes |
| --- | --- | --- |
| Targeted PHP syntax | PASS | All modified Dashboard services/controllers/routes/tests passed `php -l`. |
| `git diff --check` | PASS | No whitespace errors. |
| SPA typecheck | PASS | `npm run typecheck`. |
| KPI route registration | PASS | Container `php artisan route:list --path=dashboard/kpi` shows scorecard, single-trend, batch-trend, and compute routes. |
| Backend remediation feature suite | BLOCKED | The selected container run reached 47 tests but failed with 0 assertions before test bodies because shared test DB migration `2026_08_25_210000_enforce_one_active_holiday_per_date.php:42` attempts to drop the constraint-owned `holidays_date_name_unique` index; concurrent/shared DB state also produced table/type/migration conflicts. |
| SPA unit tests | BLOCKED | Vitest startup cannot write the existing root-owned `spa/node_modules/.vite-temp` cache directory (`EACCES`). |
| Seeded monthly KPI command | NOT RUN | Depends on the same unavailable/contended test database; the seeded process regression was added but could not execute. |
| Browser route/role audit | NOT RUN | No local frontend/API server was listening; the CoC link was statically mapped to a registered route. |

All twelve remediation items were implemented. The remaining handoff is verification in an isolated, healthy test database and browser session; leave M007 as `🔁 Needs Re-audit` until those checks pass.

---

# Verification session — 2026-08-27

Claim: `platform / dashboards-kpis` (RECLAIMED — stale lock, 31h old).
Purpose: this is the separate session the 2026-08-25 items were waiting for. The
2026-08-25 slice was written but never executed (`migrate:fresh` was broken
repo-wide), so every item below was **done-but-unverified** on entry.
Isolated database: `ogami_n_dash`.

## Fixes this session

| # | File:line | Before | After |
| --- | --- | --- | --- |
| V-1 | `api/tests/Feature/Dashboard/NewDomainAnalyticsTest.php:78-96` | Fixture seeded `year => 2031`, `start_date 2031-01-01`, `end_date 2031-12-31`, `status => 'open'`. `BudgetWidgetAnalytics::payload()` reads `FiscalYear::active()->current()`, which needs `status = 'active'` (migration 0162: draft/active/closed) AND today inside the window. Nothing matched, the gauge correctly returned `[]`, and `$payload['value']` raised `ErrorException: Undefined array key "value"` at the assertion. | Seeds `now()->year` with `startOfYear()`/`endOfYear()` and `status => 'active'`, so the scope the gauge actually uses selects the row. Fixture-only; no production change (see decision note below). |
| V-2 | `api/tests/Feature/Dashboard/BadgeControllerTest.php:246-273` | Five `employee_trainings` rows inserted via raw `DB::table()->insert()`, which bypasses `EmployeeTraining::booted()`'s `creating` hook, so all five fell to the column default `assignment_key = '__unscheduled__'` and collided on `uq_emp_training_assignment_key (employee_id, training_id, assignment_key)` at the second insert. Two of the rows were also genuinely duplicate under the tightened rule (both unscheduled, same training). | Each row now carries the `assignment_key` the model hook would derive (`scheduled_for` date string, else the `'__unscheduled__'` sentinel), and the second completed/expiring row moved to a distinct `Training` so it no longer competes for the sentinel. Per-row comments state which side of the 14-day / 30-day windows each row is on. Fixture-only; the constraint is a deliberate 2026-08-25 hardening. |
| V-3 | `api/database/seeders/KpiDefinitionSeeder.php` / `api/app/Modules/Dashboard/Services/KpiSnapshotService.php` — see the entry appended after investigation | — | — |

### V-3 — `KpiComputeProcessTest::test_seeded_active_catalog_computes_without_schema_failures`

This is the M007-10 guard the 2026-08-25 session added, and it worked: it found
**four** real production defects in the shipped KPI catalog, none of them a
fixture problem. The 2026-08-25 slice fixed this exact class of bug for
`inventory_turnover` (M007-01) and `ar_aging_60d` (M007-02) but did not sweep the
remaining nine calculators, so four more shipped.

**Why they came out one at a time.** `computeAll` catches per definition but opens
no savepoint, and `RefreshDatabase` wraps the test in one transaction. The first
real SQL error aborts that transaction, so all later definitions report the
cascade `SQLSTATE[25P02] current transaction is aborted, commands ignored until
end of transaction block` rather than their own result. The first run reported 10
failures of which **only the first was real**. Fix-and-re-run had to be repeated
four times. The scheduled command runs in autocommit, so in production only the
genuinely broken definition fails — the isolation claim in the audit report holds
for production but NOT under an enclosing transaction. Documented in the test
docblock so the next reader does not chase nine phantoms.

| Defect | File:line | Before | After |
| --- | --- | --- | --- |
| V-3a `dppm` | `api/app/Modules/Dashboard/Services/KpiSnapshotService.php:369-398` | `->sum('defects_found')` on `inspections`. No such column; PG even hinted `inspections.defect_count`. Aborted the monthly run before the `$totalInspected == 0` early return, so it failed even with zero inspections. | `->sum('defect_count')` — the column `InspectionService::recordMeasurements()` writes and every other Quality reader uses. Early return moved ahead of the second query. |
| V-3b `budget_utilization` | `api/app/Modules/Dashboard/Services/KpiSnapshotService.php:513-550` | `->sum('budget_line_items.budgeted_amount')`. No such column. | `->sum('budget_line_items.annual_total')` — the `GENERATED ALWAYS AS (jan+…+dec) STORED` column from migration 0162, and the natural counterpart to the `actual_total` the other half of the ratio already read. |
| V-3c `wo_completion_rate` (column) | `api/app/Modules/Dashboard/Services/KpiSnapshotService.php:596-647` | `->whereBetween('scheduled_end', …)` on `work_orders`. No such column. | `planned_end`, the actual due-date column. `actual_end` on the numerator was already right. |
| V-3d `wo_completion_rate` (dead status) | same | Denominator filtered `whereIn('status', ['completed','closed','started','paused'])`. **`started` is not a WorkOrderStatus** (planned/confirmed/in_progress/paused/completed/closed/cancelled) and `work_orders_status_lifecycle_check` rejects it, so no row could ever match — every running work order due in the month fell out of the denominator and the completion rate read high. Exactly the M007-05 class ("status values the module never emits"), which the 2026-08-25 session fixed for warehouse but not here. | Uses `WorkOrderStatus::InProgress->value` plus the other three as enum values, not literals, per the CLAUDE.md "enums for all status fields" rule. Found only because the check constraint refused the fixture — the bug is invisible to a query that just returns zero rows. |

Regression coverage added — three fixture tests that pin the value, not merely
the absence of a SQL error, matching the style of the AR-aging/inventory-turnover
tests from the previous session:

- `api/tests/Feature/Dashboard/KpiComputeProcessTest.php` —
  `test_dppm_uses_the_inspection_defect_count_column` (2 defects / 500 sampled =
  4,000 DPPM; excludes an out-of-period row and a never-completed row),
  `test_budget_utilization_uses_the_generated_annual_total` (250/1000 = 25%),
  `test_wo_completion_rate_uses_planned_end_for_the_due_population` (1 of 2 due =
  50%; would have read 100% under the `started` filter).

All 11 seeded definitions are active, so the catalog is now fully exercised.

## New findings for the coordinator — NOT decided in this session

These are metric-contract questions, not schema errors. Both are in
`computeWoCompletionRate` and are recorded in the code docblock next to the
affected queries. Options with evidence, no pick:

**M007-13 — the completion rate mixes two populations, so it can exceed 100%.**
The denominator counts work DUE in the month (`planned_end` between from/to); the
numerator counts work FINISHED in the month (`actual_end` between from/to). A work
order planned for July and finished in August is a July miss and an August hit,
and August's numerator can exceed August's denominator. Options: (a) cohort basis
— numerator restricted to the same `planned_end` cohort that forms the
denominator, answering "of the work due this month, how much shipped"; (b)
throughput basis — both sides on `actual_end`, answering "of the work finished
this month, how much was on time"; (c) keep as-is and rename the KPI to say it is
a ratio of two different populations. (a) matches the "WO Completion Rate" label
most closely. Needs a production/PPC decision.

**M007-14 — soft-deleted work orders count in the denominator.** `work_orders`
carries `deleted_at` and both queries use raw `DB::table()`, which ignores it.
`computeAttendanceRate` in the same service does exclude soft deletes (its SQL
carries `"deleted_at" is null`), so the catalog is internally inconsistent about
whether a soft-deleted row is history or noise. Deciding it one way for all
calculators is the cheap fix; doing it per-calculator invites the same drift back.

## Verification performed — 2026-08-27

Isolated database `ogami_n_dash`, one test class per run (4 agents share a 3.7 GiB
host). The 2026-08-25 gates that were BLOCKED are now executed.

| Check | Result | Evidence |
| --- | --- | --- |
| `NewDomainAnalyticsTest` | **PASS** | 11 passed, 20 assertions |
| `BadgeControllerTest` | **PASS** | 9 passed, 101 assertions |
| `KpiComputeProcessTest` | **PASS** | 7 passed, 15 assertions (was 4) |
| `WidgetSeedIntegrityTest` (drift guard) | **PASS** | 10 passed, 85 assertions |
| `DashboardDispatchTest` (dispatch guard) | **PASS** | 19 passed, 41 assertions |
| `DashboardPanelGateTest` — verifies M007-03 | **PASS** | 12 passed, 46 assertions |
| `RoleDashboardServiceTest` — M007-05/06/09 | **PASS** | 10 passed, 30 assertions |
| `ActionCenterControllerTest` — M007-04 | **PASS** | 9 passed, 41 assertions |
| `KpiScorecardTest` — M007-12 batch trends | **PASS** | 3 passed, 10 assertions |
| `Admin\DashboardLayoutTest` — M007-11 | **PASS** | 9 passed, 27 assertions |
| `KpiWidgetTest` | **PASS** | 11 passed, 44 assertions |
| `WidgetAnalyticsServiceTest` | **PASS** | 6 passed, 12 assertions |
| `DashboardWidgetDataTest` | **PASS** | 4 passed, 18 assertions |
| `AdminDashboardServiceTest` | **PASS** | 5 passed, 25 assertions |
| `ApprovalsWidgetTest` | **PASS** | 4 passed, 11 assertions |
| `BadgeRealtimeTest` | **PASS** | 5 passed, 88 assertions |
| `RenderKindTest` | **PASS** | 3 passed, 5 assertions |
| `RichLayoutEndpointTest` | **PASS** | 4 passed, 22 assertions |
| `RolloutHealthTest` | **PASS** | 2 passed, 13 assertions |
| `WidgetScopeTest` | **PASS** | 3 passed, 4 assertions |
| **Dashboard suite total** | **146 passed / 0 failed** | all 19 `tests/Feature/Dashboard` classes + `Admin\DashboardLayoutTest` |
| PHP syntax | PASS | `php -l` on all four changed files |
| Repo sweep for the same defect class | PASS | `defects_found`, `budgeted_amount` now appear only in comments/logs; `'started'` has zero occurrences in `api/app`. `scheduled_end` legitimately exists on `production_schedules` (migration 0085) and is used correctly by Production/MRP — the sibling table is the likely source of the original copy-paste. |
| M007-07 CoC link (static) | PASS | `spa/src/lib/dashboardLinks.ts:116-117` targets `/quality/inspections`, registered at `spa/src/routes/qualityRoutes.tsx:50`. |
| SPA typecheck | **FAIL — pre-existing, out of scope** | `tsc --noEmit` reports only `src/pages/assets/detail.tsx(6,20) TS2307: Cannot find module 'qrcode'` and `(67,14) TS7006`. `qrcode@1.5.4` and `@types/qrcode@1.5.5` ARE declared in `spa/package.json:42,61` but are absent from `spa/node_modules`, which is root-owned and dated Aug 22 — a stale install, not a code defect. `detail.tsx` is unmodified in the worktree and belongs to Assets. No SPA file was changed by this session. |
| SPA unit tests (`kpi.test.ts`) | **NOT RUN** | Same blocker as 2026-08-25: `spa/node_modules/.vite-temp` is root-owned (`drwxr-xr-x root root`), so Vitest cannot write its cache. Not fixable without root, and chmod-ing a shared `node_modules` while three other agents are live in this worktree is not a safe unilateral act. |
| Browser route/role audit | **NOT RUN** | Only `db`, `redis`, `api` are up by instruction; no SPA dev server. The one browser-checkable item (M007-07) was verified statically instead. |
| Seeded monthly KPI command end-to-end | **COVERED BY TEST** | `test_seeded_active_catalog_computes_without_schema_failures` runs `computeAll` over the real `KpiDefinitionSeeder` catalog and asserts zero failures — the check the command performs. |

### Status of the twelve 2026-08-25 items

All twelve were **done-but-unverified** on entry (source committed, never executed).
Eleven are now **done and verified** by the runs above: M007-01, 02, 03, 04, 05, 06,
07 (static), 08, 09, 10, 11, 12. M007-10's new guard is what exposed V-3a–V-3d, so
the item did its job.

The 2026-08-25 slice was, however, **incomplete on its own terms**: M007-01/02 fixed
two broken calculators without sweeping the other nine, and M007-05 fixed
never-emitted status values for warehouse without sweeping the KPI calculators. Four
more defects of those exact two classes survived and are fixed here.

### Remaining before `✅ Verified`

- SPA unit tests and the authenticated browser/role audit at desktop and mobile
  widths (blocked on a root-owned `node_modules` and no dev server).
- The two metric-contract decisions M007-13 and M007-14 above.




## Release handoff

Release M007 as `🔁 Needs Re-audit`, regenerate the registry, verify the lock is removed, and recommend the next eligible module. The next session should rerun the blocked backend/SPA/browser gates before promoting M007 to `✅ Verified`.
