# M007 — Dashboards & KPIs audit

Audit date: 2026-08-24  
Branch / baseline: main at b269eafd (origin/main)  
Claim: platform / dashboards-kpis  
Status: Plan Ready

## Executive outcome

Production audit score: 56/100. The dashboard surfaces are substantially implemented and the dedicated backend suite is green, but this module is not release-ready: the scheduled KPI process has two active calculators that reference columns absent from the live schema, several role services read gated financial/HR data before applying PanelGate, and warehouse/action-center permissions and metric contracts still have production-impacting gaps.

No production code was changed in this session. The finding mix is not predominantly small/same-session work, so the workflow decision is to release this module as Plan Ready and schedule a separate hardening session.

## Scope and evidence checked

- API routes and middleware: api/app/Modules/Dashboard/routes.php:20-95.
- Role dispatch and dashboard catalog: DashboardDispatchService, DashboardCatalog, DashboardController, and the seven role dashboard services.
- Widget catalog, layout persistence, server-owned links, scalar data, rich analytics, badge/action-center/rollout-health services.
- KPI seed definitions, monthly scheduler, command failure behavior, calculators, migrations, and permissions.
- SPA dashboard routes/pages, dashboardLinks.ts, widget registry, scorecard, and dashboard API clients.
- Database schema was inspected read-only in the PostgreSQL test database. Invoice columns include balance but not balance_due; stock_levels includes weighted_avg_cost but not unit_cost; stock_movements includes movement_type but not type.
- Automated backend gate: php artisan test tests/Feature/Dashboard --no-coverage — 128 passed, 609 assertions.
- PHP syntax gate: 123 Dashboard/related PHP files passed php -l.
- SPA gate: npm run typecheck passed.
- Browser route/role audits were attempted but could not run: no local frontend/API server was listening on localhost. Vite/Vitest startup was also blocked by EACCES on the root-owned spa/node_modules/.vite-temp directory.

## Release blockers and high-value findings

| ID | Classification | Priority | Finding | Scope | Session |
| --- | --- | --- | --- | --- | --- |
| M007-01 | Broken | P0 | Active inventory_turnover KPI SQL uses nonexistent schema columns and causes the monthly KPI process to fail. | Large | Separate recommended |
| M007-02 | Broken | P0 | Active ar_aging_60d KPI sums nonexistent balance_due instead of the invoice balance column. | Medium | Separate recommended |
| M007-03 | Incomplete | P1 | Multiple bespoke dashboards perform sensitive queries before PanelGate evaluates the viewer permission. | Large | Separate recommended |
| M007-04 | Broken | P1 | Action-center task authorization treats every quality item as interchangeable, allowing an NCR-only grant to mutate an inspection task if its key is known. | Medium | Separate recommended |
| M007-05 | Broken | P1 | Warehouse headline counts use status/type values that the inventory module never emits; valid activity renders as zero. | Medium | Separate recommended |
| M007-06 | Incomplete | P1 | Several KPI labels do not match their source data: plant revenue includes draft/cancelled invoices, quality pass rate includes unfinished inspections, and CoC count is actually closed NCR count. | Medium | Separate recommended |
| M007-07 | Broken | P1 | The CoCs Gen. MTD card points to /quality/certificates, a SPA route that does not exist. | Small | Same-session possible, deferred with metric fix |
| M007-08 | Incomplete | P2 | Suppliers Due Review counts every low historical snapshot rather than the latest distinct vendor period. | Medium | Separate recommended |
| M007-09 | Incomplete | P2 | Probation alerts query using the configured probation period but display a hard-coded six-month end date. | Small | Same-session possible |
| M007-10 | Missing | P1 | Tests prove generic failure isolation but do not execute the seeded active KPI catalog, so the two schema failures escaped the green process test. | Medium | Separate recommended |
| M007-11 | Incomplete | P2 | First-login role-layout cloning has a check-then-insert race and no uniqueness constraint for user/widget rows. | Medium | Separate recommended |
| M007-12 | Polish | P2 | The scorecard makes one trend request per KPI card, producing an avoidable client-side N+1 request pattern. | Medium | Separate recommended |

## Detailed findings

### M007-01 — Inventory turnover calculator is broken

Classification: Broken  
Priority: P0 / release blocker  
Scope: Large  
Session recommendation: separate recommended

Evidence:

- api/app/Modules/Dashboard/Services/KpiSnapshotService.php:469-491 queries stock_movements.type and stock_levels.unit_cost.
- api/database/migrations/0057_create_stock_movements_table.php:18-20 defines movement_type, quantity, and unit_cost.
- api/database/migrations/0056_create_stock_levels_table.php:17-20 defines quantity, reserved_quantity, and weighted_avg_cost.
- api/database/seeders/KpiDefinitionSeeder.php:24 activates inventory_turnover with computeInventoryTurnover.
- api/routes/console.php:316-323 schedules the all-active KPI computation monthly.
- api/app/Console/Commands/ComputeMonthlyKpis.php:46-61 returns failure when any active definition fails.

Impact: the inventory turnover calculator cannot execute against the current schema. The per-definition isolation records the failure, but the scheduled command still exits non-zero, leaving this KPI without a snapshot and making the monthly process operationally red. The correction also needs a domain decision for what “average inventory” means; simply renaming columns would not prove the formula is authoritative.

### M007-02 — AR aging calculator references a nonexistent invoice column

Classification: Broken  
Priority: P0 / release blocker  
Scope: Medium  
Session recommendation: separate recommended

Evidence:

- api/app/Modules/Dashboard/Services/KpiSnapshotService.php:408-428 sums invoices.balance_due.
- api/database/migrations/0048_create_invoices_table.php:20-29 defines date, due_date, total_amount, amount_paid, balance, and status; there is no balance_due.
- api/app/Modules/Dashboard/Services/DashboardWidgetDataService.php:110-115 and api/app/Modules/Dashboard/Services/FinanceDashboardService.php:76-81 use balance for the same AR boundary.

Impact: any non-empty active AR-aging calculation fails at runtime. After the column correction, the implementation still needs an explicit finalized/partial/paid status policy instead of the current generic “not paid and not cancelled” filter, which can include drafts.

### M007-03 — Permission gates protect the response, not all data reads

Classification: Incomplete  
Priority: P1 / security and least-privilege risk  
Scope: Large  
Session recommendation: separate recommended

Evidence:

- PanelGate explicitly requires closures so refused queries do not run at api/app/Modules/Dashboard/Support/PanelGate.php:26-31 and evaluates them at :39-49.
- FinanceDashboardService reads cash, AR, AP, revenue, aging, journal entries, and customer aging before calling the gate at api/app/Modules/Dashboard/Services/FinanceDashboardService.php:62-116; the panel map is gated only at :118-142.
- HrDashboardService precomputes company headcount, leave, and separation counts before panel/KPI gates at api/app/Modules/Dashboard/Services/HrDashboardService.php:49-56.
- PpcDashboardService precomputes production, shortage, machine, MRP, and accounting values before gates at api/app/Modules/Dashboard/Services/PpcDashboardService.php:40-53 and :89-93.
- PurchasingDashboardService, WarehouseDashboardService, and QualityDashboardService use the same value-first pattern at their respective methods’ opening blocks.

Impact: output omission tests pass, but an account without accounting.invoices.view, accounting.bills.view, accounting.journal.view, payroll.periods.view, or the equivalent domain grant can still cause those tables to be queried. This violates the module’s stated least-privilege contract, exposes data to query logging/observability, and makes future query-side effects possible even though the JSON payload is filtered.

### M007-04 — Quality action-center task authorization is too broad

Classification: Broken  
Priority: P1 / authorization boundary  
Scope: Medium  
Session recommendation: separate recommended

Evidence:

- ActionCenterService emits distinct inspection keys at api/app/Modules/Dashboard/Services/ActionCenterService.php:195-218 and NCR keys at :222-251.
- ActionCenterTaskService collapses every key beginning with quality: into quality.view or quality.ncr.view at api/app/Modules/Dashboard/Services/ActionCenterTaskService.php:92-109.
- The controller tests cover alert authorization but do not exercise separate inspection and NCR grants at api/tests/Feature/Dashboard/ActionCenterControllerTest.php:104-120.

Impact: a user with only quality.ncr.view can claim, snooze, resolve, or reopen a quality:inspection task when the item key is obtained from a stale client, log, or another channel. Authorization must parse the source and item kind and require quality.inspections.view for inspections and quality.ncr.view for NCRs.

### M007-05 — Warehouse dashboard headline counts use impossible business states

Classification: Broken  
Priority: P1  
Scope: Medium  
Session recommendation: separate recommended

Evidence:

- WarehouseDashboardService queries goods_receipt_notes.status = pending, stock_movements.movement_type = issue, and stock_movements.movement_type = transfer with a null destination at api/app/Modules/Dashboard/Services/WarehouseDashboardService.php:34-45.
- GrnStatus has draft, pending_qc, accepted, partial_accepted, and rejected only at api/app/Modules/Inventory/Enums/GrnStatus.php:7-13.
- StockMovementType defines material_issue, not issue, at api/app/Modules/Inventory/Enums/StockMovementType.php:7-20.
- StockTransferService requires both source and destination for a transfer; api/app/Modules/Inventory/Services/StockMovementService.php:248-254 rejects a transfer without both locations.
- Pending transfer work is stored in transfer_orders with status pending at api/app/Modules/Inventory/Services/TransferOrderService.php:41-52, not as a stock movement with a null destination.

Impact: Pending GRNs, Issues Today, and Pending Transfers can silently show zero while valid operational work exists. The generic widget path already uses more accurate inventory states, so the dedicated warehouse page is inconsistent with its own module contract.

### M007-06 — Several role-dashboard metrics do not match their labels

Classification: Incomplete  
Priority: P1  
Scope: Medium  
Session recommendation: separate recommended

Evidence:

- Plant Manager “Revenue” sums every invoice in the date range without excluding draft/cancelled records at api/app/Modules/Dashboard/Services/PlantManagerDashboardService.php:134-140. The finance widget path explicitly excludes those statuses at api/app/Modules/Dashboard/Services/DashboardWidgetDataService.php:113-115.
- Quality “Pass Rate Today” uses all inspections created today as the denominator, including draft/in-progress rows, at api/app/Modules/Dashboard/Services/QualityDashboardService.php:76-82. The scalar widget path uses passed plus failed observations at api/app/Modules/Dashboard/Services/DashboardWidgetDataService.php:361-365.
- Quality “CoCs Gen. MTD” counts closed non-conformance reports at api/app/Modules/Dashboard/Services/QualityDashboardService.php:39-50. The actual CoC flow is based on passed outgoing inspections at api/app/Modules/Quality/Services/CoCService.php:145-155 and persists delivery_proofs with proof_type coc at api/database/migrations/0156_add_delivery_proofs.php:27-40.

Impact: operators can be shown inflated revenue, understated pass rate, and a count unrelated to certificates of conformance. These are decision-support correctness failures even when the endpoint and tests remain green.

### M007-07 — CoC KPI drill-down route is dead

Classification: Broken  
Priority: P1  
Scope: Small  
Session recommendation: same-session possible, but coordinate with M007-06

Evidence:

- spa/src/lib/dashboardLinks.ts:116-117 maps CoCs Gen. MTD to /quality/certificates.
- spa/src/routes/qualityRoutes.tsx:29-60 defines dashboard, inspection, NCR, traceability, and capability routes but no /quality/certificates route.

Impact: clicking the card sends the user to an unrouted SPA path. The metric source and the destination should be corrected together so a repaired count has a valid worklist.

### M007-08 — Supplier review count includes historical duplicates

Classification: Incomplete  
Priority: P2  
Scope: Medium  
Session recommendation: separate recommended

Evidence:

- PurchasingDashboardService counts every snapshot below the threshold at api/app/Modules/Dashboard/Services/PurchasingDashboardService.php:46-49.
- supplier_performance_snapshots is unique per vendor/year/month at api/database/migrations/0130_create_supplier_performance_snapshots_table.php:42-76.
- The adjacent supplier panel correctly selects the latest period first at api/app/Modules/Dashboard/Services/PurchasingDashboardService.php:147-156.

Impact: a supplier with three historical low scores contributes three “Suppliers Due Review” units. The KPI should use the latest computed period and count distinct vendors, matching the adjacent panel.

### M007-09 — Probation alert output hard-codes six months

Classification: Incomplete  
Priority: P2  
Scope: Small  
Session recommendation: same-session possible

Evidence:

- HrDashboardService reads hr.probation.period_months for the query window at api/app/Modules/Dashboard/Services/HrDashboardService.php:219-231.
- The returned probation_end is nevertheless calculated with addMonths(6) at :244-250.

Impact: changing the configured probation period changes which employees are listed but leaves the displayed end date wrong.

### M007-10 — Seeded KPI computation is not covered by the process test

Classification: Missing  
Priority: P1  
Scope: Medium  
Session recommendation: separate recommended

Evidence:

- KpiComputeProcessTest creates only synthetic valid_no_data and broken_calculator definitions at api/tests/Feature/Dashboard/KpiComputeProcessTest.php:16-46.
- The production catalog is a separate active seed at api/database/seeders/KpiDefinitionSeeder.php:14-25, including the two failing calculators above.

Impact: the test proves exception aggregation but not that the shipped catalog can compute. Add a seeded-catalog command/service test that asserts each active definition returns computed, no_data, or an intentionally documented failure.

### M007-11 — First-login layout cloning can duplicate rows under concurrency

Classification: Incomplete  
Priority: P2  
Scope: Medium  
Session recommendation: separate recommended

Evidence:

- AuthService calls cloneRoleDefaultToUser after successful login at api/app/Modules/Auth/Services/AuthService.php:150-163.
- cloneRoleDefaultToUser checks for user rows and then inserts the role rows without locking the user or enforcing uniqueness at api/app/Modules/Dashboard/Services/DashboardLayoutService.php:105-135.
- The dashboard_layouts migration has only an owner index and no owner/widget unique constraint at api/database/migrations/0129_create_dashboard_layouts_table.php:24-37.

Impact: parallel first logins or duplicated login requests can create duplicate widget placements, which then affect layout ordering and user edits. The current idempotency test covers sequential calls only.

### M007-12 — Scorecard trend loading is client-side N+1

Classification: Polish  
Priority: P2  
Scope: Medium  
Session recommendation: separate recommended

Evidence:

- ScorecardPage fetches the scorecard at spa/src/pages/dashboard/scorecard.tsx:77-81.
- Each KpiCard then issues an independent trend request at :159-168.

Impact: an 11-KPI scorecard causes one scorecard request plus up to 11 trend requests, increasing latency and failure surface. A batch trend endpoint or embedded trend points would make the page more resilient.

## What is working

- Permission-derived dashboard dispatch is server-side and tested across roles.
- Layout/widget visibility is filtered server-side, with version conflicts and reset behavior covered.
- Rich widget providers degrade to scalar data instead of blanking the dashboard.
- Finance shared-cache permission signatures prevent response-level cross-user payload leakage.
- Action-center SQL errors are not returned as SQL-bearing 422 messages; the existing regression suite covers this.
- Monthly KPI failures are aggregated and surfaced as a failed process rather than reported as success.
- The dashboard feature suite is green: 128 tests and 609 assertions.

## Required next action

Do not promote M007 as complete. Execute the action plan, beginning with the two active KPI schema failures and the gate-before-query refactor, then add the missing source-specific authorization and metric-contract regressions. Re-run the backend dashboard suite, SPA typecheck/unit tests, seeded KPI command, and authenticated browser route audit before moving the module to Verified.
