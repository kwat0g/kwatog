# OGAMI ERP — Dashboards/DSS Phase 0 Findings Log

Bug/gap log. Every item extracted from the six section files in
`docs/dashboards/phase0/` — no new research. Format: ID · severity
(`blocks-dashboard` / `high` / `medium` / `low`) · evidence (`file:line`) · status.
All statuses `open` at synthesis time. Source section noted per item (A–F).

---

## Approval ledger / chains (C)

| ID | Severity | Finding | Evidence | Status |
|---|---|---|---|---|
| F-001 | blocks-dashboard | Board `payroll` kind is dead: workflow unwired, zero `approval_records` rows ever written, column can only ever be empty | `api/database/seeders/WorkflowSeeder.php:188-199`; `api/app/Common/Support/ApprovalTypeRegistry.php:62-69` (C §1a/§5.1) | open |
| F-002 | blocks-dashboard | All 14 custom single-step types board-blind (no ledger rows) + sweep-blind (no reminder/escalation/auto-resolve) — systemic silent-stall class | C §2.10; `api/app/Common/Services/ApprovalEscalationService.php:59-152` | open |
| F-003 | high | VP escalation gap: escalation map ends at `vice_president` (no superior entry) — VP-step stalls notify only the possibly-empty VP role, else nobody | `api/database/migrations/0416_seed_approval_escalation_role_map.php` + `0482_…`; `api/app/Common/Services/ApprovalEscalationService.php:385-398` (C §2 head) | open |
| F-004 | high | Asset transfers undecidable via API: checker service exists but routes intentionally unrouted (scope cut) — pending transfers strand | `api/app/Modules/Assets/routes.php:44-48` (C §1b/§5.7) | open |
| F-005 | medium | `ApprovalRecord` has no audit trait: intermediate step decisions leave no `audit_logs` row (no IP/user-agent); ledger is the only trail | C §3 (verified grep: trait absent) | open |
| F-006 | high | Bill-payment submit/approve/reject/post notifications all missing (`BillService` has zero notify sends) | `api/app/Modules/Accounting/Services/BillService.php` (C §3) | open |
| F-007 | high | Salary-adjustment request/approve/reject notifications all missing (zero notify/outbox calls) | `api/app/Modules/HR/Services/SalaryAdjustmentService.php` (C §3) | open |
| F-008 | high | Asset-disposal request/approve/reject notifications all missing (zero notify/outbox calls) | `api/app/Modules/Assets/Services/AssetService.php` (C §3) | open |
| F-009 | high | Stock adjustment, payroll adjustment, budget, budget transfer, invoice prebill, cost review: all submit/decide notifications missing | `BudgetService`, `BudgetTransferService`, `StockAdjustmentService`, `InvoiceService`, `PayrollAdjustmentService`, `ItemCostReviewService` (C §3) | open |
| F-010 | medium | Mid-chain step handoffs un-notified: loan dept→HR→finance→VP, PR/PO Finance→VP (fully-approved broadcast exists, handoff does not), return step handoffs, payroll waiting-approval | `api/app/Modules/Loans/Listeners/NotifyOnLoanSubmitted.php:35-48`; `api/app/Modules/Purchasing/Services/PurchaseRequestService.php:436-440` (C §3) | open |
| F-011 | high | Leave sole-head trap: step 1 is head-only; department with no active head stalls at `pending_dept`, escalation notifies `production_manager` who cannot act; sole head's own leave stalls unconditionally | `api/app/Modules/Leave/Services/LeaveRequestService.php:730-749` (C §2.1) | open |
| F-012 | high | Loans headless-department stall at step 1 (both chains ladder on `canDecide` same-department scope) | `api/app/Modules/Loans/Policies/LoanAccessPolicy.php:106-141` (C §2.5) | open |
| F-013 | high | Salary-adjustment PM self-request self-blocks step 1 (single-holder by default) | `api/app/Modules/HR/Models/SalaryAdjustment.php:50-53` (C §2.6) | open |
| F-014 | high | Payroll maker-checker silent stall: no second `approve` holder + no override grant = stuck `computed` with no notification, no delegation, no sweep | `api/app/Modules/Payroll/Services/PayrollPeriodService.php:1205-1220` (C §2.9) | open |
| F-015 | high | Custom approvals never consult `ApprovalDelegation` (Attendance: zero references; others use raw identity checks) — delegates cannot cover sole-checker absence | `api/app/Modules/Attendance/Services/OvertimeService.php:269-312`; `StockAdjustmentService.php:267-268`; `PayrollAdjustmentService.php:114-116`; `BudgetService.php:139-151` (C §2.10) | open |
| F-016 | low | Bill-payment chain doc drift: CLAUDE.md still describes Finance→VP; code is single VP step ≥₱50k with below-threshold auto-post | `api/database/migrations/2026_10_09_000300_bill_payment_vp_threshold.php`; `BillService.php:841-845` (C §5.3) | open |
| F-017 | low | Company-loan step 2 now `hr_officer` (was `production_manager`) + ₱50k VP threshold added — undescribed in CLAUDE.md | `api/database/migrations/2026_10_10_000100…` (C §5.4) | open |
| F-018 | low | Reserved workflows (`department_transfer`, `separation_clearance`, `maintenance_request`, `8d_report`, `work_order`, `ncr`) seeded inactive with no `submit()` path — must never surface as approvable | `api/database/seeders/WorkflowSeeder.php:14-23` (C §5.5; A §5.9) | open |
| F-019 | low | `overtime_request` workflow row deleted as dead config — any doc referencing OT workflow approval is stale | migration `0502_…` (C §5.6) | open |

## Chain links / data integrity (B §2.6)

| ID | Severity | Finding | Evidence | Status |
|---|---|---|---|---|
| F-020 | medium | F1: `work_orders.sales_order_item_id` has zero FK rows — orphan-able; dashboards must LEFT JOIN | `information_schema` (B §2.6-F1) | open |
| F-021 | high | F2: delivery→invoice lossy by design — confirm valid with `invoice_id NULL` + `manual_required`; revenue dashboards must count handoff_status | `api/app/Modules/SupplyChain/Services/DeliveryService.php:1640-1667` (B §2.6-F2) | open |
| F-022 | medium | F3: `pending_qc` GRNs with handoff ≠ generated have no inspection rows (sweeper exists because gap is real) | `grn:retry-pending-incoming-qc`, `api/routes/console.php` (B §2.6-F3) | open |
| F-023 | medium | F4: completed WOs without outgoing inspection existed as a class — funnels must handle NULL-inspection batches | `qc:sweep-missing-outgoing`, hourly (B §2.6-F4) | open |
| F-024 | medium | F5/F8: GL + stock-movement references polymorphic by convention (no FK) — drill-downs join on type+id strings | `Accounting/Services/SourceReferenceRegistry.php` (B §2.6-F5) | open |
| F-025 | medium | F6: MRP run→order trace only via summary json + plans bridge; `mrp_plans.mrp_run_id` unindexed | `api/app/Modules/MRP/Services/MrpEngineService.php` (B §2.6-F6) | open |
| F-026 | medium | F7: forecast actuals late-filled — only `actual_quantity IS NOT NULL` rows comparable | `ForecastingService.php:251-302` (B §2.6-F7) | open |
| F-027 | low | F9: inspections dual addressing (legacy `entity_type/entity_id` + typed FKs) — dashboards must prefer typed FKs | `inspections` table (B §2.6-F9) | open |
| F-028 | medium | F10: NCR-close WO creation can fail logged-only — reconcile `disposition=rework` + NULL WO ids | `Quality/Services/NcrService.php:1124` (B §2.6-F10) | open |
| F-029 | medium | F11: stock tables (`StockLevel`, `StockMovement`) have no audit rows — movement ledger is the only history | verified grep=0 vs 136 models with trait (B §2.6-F11) | open |
| F-030 | blocks-dashboard | F12: returns fact tables nearly unindexed — no status/type/date/counterparty index on `return_requests`; all six provenance FKs + quarantine cols on items unindexed | `return_requests`, `return_request_items` (B §1.15/§4 rows 10-15) | open |

## Index debt blocking aggregations (B §4, F §2)

| ID | Severity | Finding | Evidence | Status |
|---|---|---|---|---|
| F-031 | high | `wo_operations.work_order_id` unindexed — table has NO non-PK index at all (operation→WO join scans) | B §4a rows 2,5,6 | open |
| F-032 | high | `production_logs.wo_operation_id` + `operator_id` unindexed — only-pkey table (OEE/downtime joins scan) | B §4a rows 3,4 | open |
| F-033 | high | `grn_items.goods_receipt_note_id` unindexed (line→header join) | B §4a row 1 | open |
| F-034 | high | `budgets` has ONLY pkey (fiscal_year/department/status unfiltered); `budget_transfers` line-item FKs unindexed; `credit_note_lines` pkey-only | B §4a rows 39-41 | open |
| F-035 | high | `purchase_requests.date` + `purchase_orders.date` unindexed (aging/cycle-time trends scan) | B §4a rows 25,26 | open |
| F-036 | high | `deliveries.delivered_at/confirmed_at/received_at` unindexed (OTD/lead-time); `work_orders.actual_start/end` unindexed (WO lead-time); MWO `started_at` unindexed (MTTR) | B §4a rows 23,44,45 | open |
| F-037 | medium | `invoices.sales_order_id` / `delivery_id`, `deliveries.invoice_id`, `invoice_items.source_delivery_item_id`, `official_receipts.invoice_id` unindexed (AR chain joins) | B §4a rows 17-21 | open |
| F-038 | medium | Line-item date gap: `sales_order_items`, `purchase_order_items`, `invoice_items`, `bill_items`, `work_order_defects`, `payroll_deduction_details` carry NO timestamps — time-series must join parents | B §5.3-G1 | open |

## Backend↔frontend gating bugs (A §2)

| ID | Severity | Finding | Evidence | Status |
|---|---|---|---|---|
| F-039 | high | M1: finance page guard (`dashboard.accounting.view`) vs data endpoint (`accounting.dashboard.view`) vs widget permission — three authorities; one-slug-off users get page-without-data or data-without-page; page doc contradicts its own guard | `spa/src/routes/dashboardRoutes.tsx:64-65`; `spa/src/api/accounting/dashboard.ts:5-8`; `spa/src/pages/dashboard/finance.tsx:54-60` | open |
| F-040 | high | M10: `/chains` gate vs bottleneck-API gate diverge BOTH ways for seeded roles (`sales_officer` 403s widget; `ppc_head` 404'd off page) | `spa/src/routes/dashboardRoutes.tsx:102-105`; `api/routes/api.php:118-121` | open |
| F-041 | medium | M5: `/maintenance` home guard-free — `driver`/`sales_officer` open an empty shell | `spa/src/routes/maintenanceRoutes.tsx:22` | open |
| F-042 | medium | M6: WO detail guard broader than backend read gate — every `employee` opens arbitrary order URLs the API 404s; `maintenance_tech` lacks the `work.assigned` arm entirely | `spa/src/routes/maintenanceRoutes.tsx:30-31`; `CheckMaintenanceWorkAccess.php:20-36`; `RolePermissionSeeder.php:888-906` | open |
| F-043 | medium | M4: OT create page vs store endpoint check different slugs (`attendance.edit` vs `attendance.ot.create`) | `spa/src/routes/hrRoutes.tsx:124-128`; `api/app/Modules/Attendance/routes.php:55` | open |
| F-044 | medium | M7: guard-free self-service pages vs permission-gated API (revoked users keep pages, eat 403s) | `spa/src/routes/selfServiceRoutes.tsx:29-75` | open |
| F-045 | low | M8: `/approvals/delegations` unguarded; backend service-scoped | `spa/src/routes/dashboardRoutes.tsx:92-95` | open |
| F-046 | low | M2/M3: leave/attendance lists need approver perms in SPA, `*.view` in API (by design; divergence real at guard layer) | `spa/src/routes/hrRoutes.tsx:101-104,136-153` | open |
| F-047 | low | M9: warehouse structure pages narrower than APIs (`inventory.view` holders callable but 404'd) | `spa/src/routes/inventoryRoutes.tsx:125-132` | open |
| F-048 | low | M11: `is_superuser` SPA-only bypass; API never sends/checks it (inert, authorities disagree) | `spa/src/hooks/usePermission.ts:6`; `UserResource.php:13-45` | open |
| F-049 | low | M12: sidebar shows `/notifications` to all; route needs `notifications.view` (diverges only on revoke) | `spa/src/components/layout/Sidebar.tsx:190-196` | open |

## Roles / dispatch / docs (A §4–§5, D §6, E §5)

| ID | Severity | Finding | Evidence | Status |
|---|---|---|---|---|
| F-050 | high | `maintenance_supervisor` migration grants lag seeder grants (missing `wo.verify`, `mrp.machines.view`) — migrated-not-reseeded DBs have a thinner supervisor | `api/database/migrations/2026_10_09_230100_add_maintenance_supervisor_role.php:39-46` vs `RolePermissionSeeder.php:910-913` | open |
| F-051 | medium | 3 roles have no role-default layout rows (`vice_president`, `customer_service_officer`, `sales_officer`); seeder comment "five roles with no dashboard grant" is stale (actual: eight land on default) | `DashboardRoleLayoutSeeder.php:31-129` (A §5.2) | open |
| F-052 | medium | `vice_president` has no dashboard of its own by design — executive vs operations readings not separated (shares plant-manager page) | `RolePermissionSeeder.php:624-628` (A §5.3) | open |
| F-053 | medium | Factory PWA held only by `production_manager` (+admin) — no operator-rank role can open `/factory` | `RolePermissionSeeder.php:300,732`; `spa/src/routes/factoryRoutes.tsx:12-25` (A §5.6) | open |
| F-054 | low | Stale `crm_commissions` module grant in `finance_officer` set grants nothing (no such catalog key) | `RolePermissionSeeder.php:584` vs `:20-494` (A §4) | open |
| F-055 | low | Scorecard leftovers: `GET /dashboard/kpi/scorecard` + `getScorecard` live/tested, SPA page deleted; `docs/e2e-audit.md:20,65` still cites scorecard sparklines as live | D §6.2 | open |
| F-056 | low | KPI strip-vs-tile window duplication undocumented (trends endpoint vs 12-mo `KpiWidgetAnalytics::WINDOW` — no canonical stated) | D §6.3 | open |
| F-057 | medium | Forecast-gate rule (dataset gate vs `forecasting.view`) lives only in code comments — headcount/attendance/defect use dataset gates, cash/capacity use `forecasting.view` | D §1.2/§6.4 | open |
| F-058 | medium | Capacity/attendance forecast panels have no history (`historical: []`) — "trend" is flat arithmetic; risks reading as analytical | `CapacityForecastService.php:23-46`; `AttendanceForecastService.php:23-53` (D §3.4/§6.5) | open |
| F-059 | low | Hardcoded DSS thresholds in new forecast code: capacity breach 90%, attendance risk 8%/15% — consts, not settings (noted in-code as ponytail) | `CapacityForecastService.php:18`; `AttendanceForecastService.php:17-18` (D §6.6) | open |
| F-060 | medium | KPI calculator gaps: OEE ignores shifts/holidays/downtime calendars; WO completion can exceed 100%; turnover uses current (not period-average) stock; budget ignores month arg; AR-aging is a current-balance snapshot | `KpiSnapshotService.php:318-374,612-642,558-585,527-541,491-518` (D §2) | open |
| F-061 | low | Widget scalar count conflict: D reports 65 keys (54 + 11 KPI), F reports 55 scalar keys in `LINK_BY_KEY` — recount before citing | `DashboardWidgetSeeder.php:48-105,128-143` (D §1.3 vs F §3) | open |
| F-062 | low | Dead WS surface: `inventory.stock` channel authorised, zero SPA subscribers | `api/routes/channels.php:36-37` (F §1.2) | open |
| F-063 | high | Hottest composable paths uncached (R15): widget-data + rich payloads recompute live per 30–60s poll; DSS tiles multiply R1–R14 load with users × keys | `DashboardWidgetDataService.php:50-74`; `WidgetAnalyticsService.php:24-30` (F §2-R15) | open |
| F-064 | medium | Query-risk cluster, PHP-side aggregation: outputs-today into PHP (R1), active-SO hydration (R2), OEE 3×N fan-out (R3/R4), full inventory tables into PHP (R5), whole-stock low-stock subquery (R7) | `ProductionDashboardService.php:78-80,122-156`; `OeeService.php:119-145`; `InventoryDashboardService.php:38-51` (F §2) | open |
| F-065 | medium | Query-risk cluster, index-defeating predicates: cash-position `LOWER()`+OR over biggest ledger (R6), `DATE()` group-by (R8), 4× invoice scans with date arithmetic (R9) | `DashboardWidgetDataService.php:385,463`; `CoreWidgetAnalytics.php:96-100,196-202` (F §2) | open |
| F-066 | low | SCHEMA.md stale ≥16 places (88-table/15-module/0169-migration world; enum drifts D3–D10; products misfiled D12; missing depth D13) — code wins; doc misleads estimators | `docs/SCHEMA.md` vs code (B §5.1) | open |
| F-067 | low | CLAUDE.md stale counts: 43 cron entries (now 63), max migration 0481 (now 0499+timestamps), budget "revisions" table (none exists), 1900-test figure unverified this pass | `api/routes/console.php`; migrations dir (B §5.2; D §6.1) | open |
| F-068 | low | DESIGN-SYSTEM.md dashboard pattern promises header "(title + range selector + export)": range on one page, export on none; real pattern (`DashboardShell` + `KpiStrip` slot) unnamed; two `--text-subtle` hexes differ from shipped tokens | `docs/DESIGN-SYSTEM.md:70,117,528-532` vs `tokens.css:30,150` (E §5-D1/D2) | open |

---

## Severity tally

- blocks-dashboard: F-001, F-002, F-030 (3)
- high: F-003, F-004, F-006, F-007, F-008, F-009, F-011, F-012, F-013, F-014, F-015, F-021, F-031, F-032, F-033, F-034, F-035, F-036, F-039, F-040, F-050, F-063 (22)
- medium: F-005, F-010, F-020, F-022, F-023, F-024, F-025, F-026, F-028, F-029, F-037, F-038, F-041, F-042, F-043, F-044, F-051, F-052, F-053, F-057, F-058, F-060, F-064, F-065 (24)
- low: F-016, F-017, F-018, F-019, F-027, F-045, F-046, F-047, F-048, F-049, F-054, F-055, F-056, F-059, F-061, F-062, F-066, F-067, F-068 (19)
