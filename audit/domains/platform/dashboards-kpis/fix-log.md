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

## Release handoff

Release M007 as `🔁 Needs Re-audit`, regenerate the registry, verify the lock is removed, and recommend the next eligible module. The next session should rerun the blocked backend/SPA/browser gates before promoting M007 to `✅ Verified`.
