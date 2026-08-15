# OGAMI system/module audit

**Date:** 2026-08-13  
**Artifact:** authoritative current-state map for AUD-010, based on AUD-001, AUD-004, and AUD-007 evidence  
**Repository:** `/home/kwat0g/Desktop/kwatog`  
**Read point:** audited baseline captured before the remediation overlay, from the dirty worktree and local PostgreSQL state rather than prior audit conclusions

> **Baseline note:** counts, gaps, and migration state in this document describe
> the system at the audit read point. They are intentionally preserved as
> evidence and are not the current disposition of every finding. Current
> statuses are maintained in `SYSTEM-AUDIT-FINDING-LIFECYCLE.json`; the
> consolidated current posture is in
> `SYSTEM-AUDIT-EXECUTIVE-SUMMARY-2026-08-13.md`.

## Scope, method, and architecture

This document maps the 22 first-party modules under `api/app/Modules`. It records what is implemented, who uses it, the data moving through it, current lifecycle/state behavior, strengths, ideal state, and evidence-backed gaps. It is a system map, not a replacement for the detailed finding templates.

The application is a Laravel modular monolith API: `api/composer.json:2-18` identifies the Laravel project and its PHP dependencies; `api/routes/api.php:35-41` says module routes are mounted from each module's `routes.php`; `api/app/Providers/ModuleServiceProvider.php:11-60` performs that loading. The principal persistence boundary is PostgreSQL. Events/listeners, queued jobs, outbox/chain tables, Redis-backed cache, Sanctum sessions/tokens, and the SPA consume the same module models and services. Cross-module effects are visible in `api/app/Providers/AppServiceProvider.php:14-90`.

At the baseline read point, read-only database verification found 196 public
tables, 480 applied migrations, 743 public PK/UNIQUE/FK/CHECK constraints,
only two table-level CHECK constraints (plus two domain checks), and no
exclusion constraints. The local database was PostgreSQL 16.13. Migration
source and applied migration sets matched and no migration was pending at that
time. Subsequent audit remediation added migrations and constraints; use the
current lifecycle registry rather than these baseline counts for release
status.

“Ideal state” below means the invariant implied by the current names, comments, service behavior, and downstream usage—not a new product requirement. “Gap” means a current-state limitation or a defensible integrity risk; an empty/uncertain statement is intentionally not converted into a defect.

## Concise inventory counts

| Inventory | Current observation |
|---|---|
| First-party modules | 22 directories under `api/app/Modules` |
| Public DB tables | 196 |
| Applied migrations | 480, max batch 6 |
| Public PK/UQ/FK/CHECK constraints | 743 total |
| Table CHECK constraints | `journal_entry_lines` debit/credit XOR; `items` ABC class |
| Exclusion constraints | 0 |
| Representative live rows | `payrolls` 1,200; `payroll_cycle_claims` 1,000; `thirteenth_month_accruals` 200; `dashboard_layouts` 165; `leave_requests` 33; `loan_payments` 11; `payroll_periods` 7; `overtime_requests` 3 |
| Empty high-risk ledgers in local DB | `stock_adjustments` 0; `stock_movements` 0 |
| Current candidate violations checked | Invoice source orphans, credit-note orphans, loan-payment payroll orphans, dashboard duplicates, OT duplicates, leave overlaps, annual 13th duplicates, and stock-adjustment duplicate movements: all 0 |

## Module/state map

### 1. Accounting

- **Purpose/users/features:** finance users manage chart of accounts, accounting periods, double-entry journals, AR invoices/collections/official receipts, AP bills/payments, budgets, and customer/supplier credit notes.
- **Inputs/outputs:** customer/vendor/order/delivery references, invoice/bill lines, payment and credit applications; outputs are posted journal entries, balances, GL reports, documents, and downstream status changes.
- **Dependencies/consumers:** Auth/Admin permissions and users; CRM sales orders/customers; Purchasing vendors/POs/GRNs; SupplyChain deliveries; Inventory stock/GL events; Payroll/Assets/Production postings. The event wiring is evidenced by `api/app/Providers/AppServiceProvider.php:14-34,49-60,83-90`.
- **Lifecycle/current strengths:** invoices and bills progress draft → finalized/open → partial/paid/cancelled; credit notes draft → finalized → applied/void; journal entries are posted through a service and journal lines enforce exactly one positive debit or credit (`api/database/migrations/0040_create_journal_entry_lines_table.php:15-30`). Invoice numbers, bill vendor/number pairs, journal entry numbers, and credit-note numbers are unique.
- **Ideal state/gaps:** every persisted source link and posted instrument should remain traceable and financially immutable. `invoices.sales_order_id` and `delivery_id` are raw integers (`api/database/migrations/0048_create_invoices_table.php:15-31`) despite relations and writes (`api/app/Modules/Accounting/Models/Invoice.php:58-66`, `api/app/Modules/Accounting/Services/InvoiceService.php:98-109`). Credit notes similarly leave `return_request_id` and `journal_entry_id` without FKs (`api/database/migrations/0268_create_credit_notes_tables.php:26-40`); local orphan checks are clean. Status and amount domains are mostly application-enforced.

### 2. Admin

- **Purpose/users/features:** administrators define roles, permissions, overrides, settings, approvals/delegations, audit logs, notifications, documents, exports, and operational configuration.
- **Inputs/outputs:** user/role/permission assignments, approval requests, policy/settings values, audit events, notification preferences; outputs are authorization decisions, audit records, notifications, and configuration consumed by every module.
- **Dependencies/consumers:** Auth users/sessions; all module permission middleware and approval services; Dashboard and portal surfaces. Polymorphic audit/approval targets are consumed by the originating module.
- **Lifecycle/current strengths:** approval records preserve historical decisions while pending steps are advanced; `api/database/migrations/0010_create_approval_records_table.php:13-27` provides approvable type/id, step, role, approver, action, and indexes. `api/app/Common/Services/ApprovalService.php:23-113` locks the pending step before decisions. Role/permission and override keys are constrained.
- **Ideal state/gaps:** approval history should be authoritative, replay-safe, and explainable across all target types. Polymorphic targets intentionally have no FK; status/action strings are not DB checks. The current evidence supports a latent raw-write/status-drift risk, not an observed orphan.

### 3. Assets

- **Purpose/users/features:** asset custodians and finance track assets, vehicles, transfers, depreciation, and custody/department changes.
- **Inputs/outputs:** asset acquisition/identity, department/employee assignments, transfer requests, depreciation periods; outputs are asset book values, depreciation journals, transfer history, and reports.
- **Dependencies/consumers:** Admin users/permissions, HR employees/departments, Accounting journals, Inventory/purchasing sources. `api/database/migrations/0104_create_assets_table.php:23-53` and `0105_create_asset_depreciations_table.php:17-35` establish the persistence boundary.
- **Lifecycle/current strengths:** asset code is unique; depreciation is unique by asset/year/month, making monthly runs naturally idempotent; transfers have their own document identity and FK-backed actors.
- **Ideal state/gaps:** asset identity, custody, and book value should have one auditable timeline and no duplicate period posting. Current DB uniqueness supports this core invariant. Status and transfer business rules remain primarily service-level; no additional high-confidence database defect was established.

### 4. Attendance

- **Purpose/users/features:** employees, supervisors, and HR manage shifts, assignments, holidays, attendance, biometric-derived overtime, and attendance summaries.
- **Inputs/outputs:** employee punches/attendance dates, shift and holiday calendars, OT requests; outputs are attendance facts, approved OT, payroll inputs, notifications, and leave conflict signals.
- **Dependencies/consumers:** HR employees/shifts, Leave requests, Payroll calculator, Admin users. Attendance uniqueness is defined in `api/database/migrations/0024_create_attendances_table.php:13-32`; OT is defined in `0025_create_overtime_requests_table.php:13-27`.
- **Lifecycle/current strengths:** attendance rows are unique by employee/date; OT has pending/approved/rejected states, approver metadata, and indexes. Auto-detection records an outbox event (`api/app/Modules/Attendance/Services/OvertimeService.php:93-116`).
- **Ideal state/gaps:** one authoritative OT claim should feed payroll once. The OT table has only an employee/date index, while auto-detection checks then inserts outside a serialized invariant (`OvertimeService.php:93-116`), so concurrent detection can duplicate a day. Current duplicate query returned 0.

### 5. Auth

- **Purpose/users/features:** users authenticate through sessions/tokens, password-reset and history controls, login history, role membership, and employee account linkage.
- **Inputs/outputs:** credentials, sessions, tokens, reset requests, employee provisioning events; outputs are authenticated principals used by every API and portal permission boundary.
- **Dependencies/consumers:** Admin roles/permissions; HR employee lifecycle; Sanctum and Laravel session infrastructure. User identity and password/session tables originate in migrations `0004_create_users_table.php:13-58`, `0006_create_sessions_table.php:13-57`, and `0178_create_personal_access_tokens_table.php:27-43`.
- **Lifecycle/current strengths:** email uniqueness, employee uniqueness, role FK, session/token persistence, login history, and reset state are present. HR listeners provision/deactivate accounts (`api/app/Providers/AppServiceProvider.php:38-48`).
- **Ideal state/gaps:** account status, employee status, role, and session revocation should transition atomically with separation/clearance. Current evidence shows event-driven coupling and normal keys; cross-system failure/retry behavior is not fully proven by schema inspection.

### 6. B2B

- **Purpose/users/features:** customer and supplier portal users exchange shipping documents, delivery schedules, and supplier order dispatches.
- **Inputs/outputs:** portal credentials, PO/document uploads, delivery commitments, dispatch requests and idempotency keys; outputs are portal-visible documents, schedule updates, supplier notifications, and purchasing handoffs.
- **Dependencies/consumers:** Purchasing POs, SupplyChain deliveries/shipments, Accounting bills, Auth portal users. Evidence: `api/database/migrations/0161_create_supplier_portal_users_table.php:13-38`, `0163_create_portal_shipping_documents_table.php:18-45`, `0164_create_delivery_schedules_table.php:19-45`, and `2026_08_10_130000_create_supplier_order_dispatches.php:13-31`.
- **Lifecycle/current strengths:** portal emails are unique; shipping documents have PO/type/file deduplication; dispatches have unique idempotency key and unique PO. FKs cascade or set null according to ownership.
- **Ideal state/gaps:** every external submission should be retry-safe, visible to the correct party, and reconcile to the PO/delivery. The current schema is comparatively strong; status strings and portal-user audit actor fields remain partly unconstrained.

### 7. CRM

- **Purpose/users/features:** sales and service users manage customers, products, price agreements, sales orders/items, complaints, and complaint 8D/NCR escalation.
- **Inputs/outputs:** customer/product/order lines, price agreements, complaint evidence; outputs are confirmed sales orders, delivery/invoice requests, complaint/NCR workflows, and dashboard metrics.
- **Dependencies/consumers:** Accounting customers/invoices; SupplyChain deliveries; Inventory/products; Quality NCR; Dashboard. Current sales-order identity is defined in `api/database/migrations/0071_create_sales_orders_table.php:25-62` and products in `0069_create_products_table.php:22-55`.
- **Lifecycle/current strengths:** sales-order numbers and product part numbers are unique; customer/product FKs anchor the commercial master data; complaint and 8D tables support escalation (`0098_create_customer_complaints_table.php:19-45`, `0099_create_complaint_8d_reports_table.php:20-42`).
- **Ideal state/gaps:** order confirmation should atomically drive fulfillment and invoice readiness. The historical leads/opportunities/quotes funnel is deliberately dropped by `0454_drop_crm_sales_funnel_tables.php:39-49`; that is a scope cut, not an accidental missing table. Source traceability to invoices is currently not FK-enforced.

### 8. Dashboard

- **Purpose/users/features:** users and role owners view KPIs, widgets, layouts, action-center tasks, badges, and snapshots.
- **Inputs/outputs:** permission-filtered widget definitions, role defaults, user layout changes, KPI snapshots and task events; outputs are dashboard pages, badge counts, and operational drill-down links.
- **Dependencies/consumers:** every module's metrics, Admin permissions, Auth users/roles, event observers. Evidence: `api/database/migrations/0128_create_dashboard_widgets_table.php:21-44`, `0129_create_dashboard_layouts_table.php:24-37`, `0259_create_kpi_definitions_table.php:13-34`, `0260_create_kpi_snapshots_table.php:13-37`.
- **Lifecycle/current strengths:** widget keys, KPI codes/scope periods, and action-center item keys are unique; `DashboardLayoutService.php:153-185` filters unauthorized/unknown widgets and deduplicates a single request.
- **Ideal state/gaps:** layout cloning and saving should be atomic, versioned, and unique per owner/widget. `DashboardLayoutService.php:98-135` check-then-inserts role defaults; `:141-187` deletes/reinserts without optimistic concurrency. Current 165 rows contain no duplicate owner/widget groups. The intentional lack of widget FK is documented in `0129:16-18`.

### 9. Forecasting

- **Purpose/users/features:** planners calculate moving/weighted/manual forecasts, compare actuals, expose accuracy, and feed MRP/stock-out projections.
- **Inputs/outputs:** product/customer/month history and manual quantities; outputs are forecast rows, confidence/variance, accuracy reports, and MRP/stock projections.
- **Dependencies/consumers:** CRM sales history, Inventory products/stock, MRP, Dashboard. `api/database/migrations/0157_create_demand_forecasts_table.php:21-40` defines product/customer/month scope.
- **Lifecycle/current strengths:** product and optional customer FKs, period indexing, and a composite scope key support normal forecast replacement. `ForecastingService.php:104-132,198-226` computes and upserts both algorithmic and manual forecasts.
- **Ideal state/gaps:** one forecast per product/customer scope/month must be guaranteed under concurrency. Because nullable `customer_id` participates in the unique key, PostgreSQL permits multiple global rows; the service assumes one row. Current duplicate-global query returned 0.

### 10. HR

- **Purpose/users/features:** HR maintains employee master data, employment/salary history, onboarding, documents, training, skills, reviews, clearances, job postings/applications, and separation.
- **Inputs/outputs:** employee identity and employment changes, salary/property/documents, training/review outcomes, clearance actions; outputs are payroll inputs, leave balances, account-provisioning/deactivation events, and workforce reports.
- **Dependencies/consumers:** Auth user accounts, Attendance/Leave/Payroll, Assets custody, Admin approvals, CRM/job workflows. Evidence: `api/database/migrations/0016_create_employees_table.php:13-55`, `0019_create_employment_history_table.php:13-38`, `0120_create_employee_onboarding_table.php:16-42`.
- **Lifecycle/current strengths:** employee number is unique; department/position relationships are FK-backed; onboarding is one-per-employee; history tables preserve changes rather than overwriting facts. HR event listeners are registered in `api/app/Providers/AppServiceProvider.php:38-48`.
- **Ideal state/gaps:** hire, transfer, salary, clearance, and separation should be one auditable state machine with payroll/account effects committed or recoverably retried. Employee status and several history/business domains are unconstrained strings; event failure/replay evidence is outside this schema map.

### 11. Inventory

- **Purpose/users/features:** warehouse users manage items, UOMs, locations, stock levels, movements, reservations, counts, GRNs, scans, adjustments, and material issues.
- **Inputs/outputs:** purchase/production/return receipts, issues, transfers, counts, adjustments; outputs are quantity/WAC ledger changes, GL postings, reorder signals, QC handoffs, and production/purchasing availability.
- **Dependencies/consumers:** Purchasing/Quality/SupplyChain/Production/ReturnManagement, Accounting GL, Dashboard. Evidence: `api/database/migrations/0056_create_stock_levels_table.php:13-28`, `0057_create_stock_movements_table.php:13-31`, `0206_add_reason_code_to_stock_adjustments.php:30-52`.
- **Lifecycle/current strengths:** stock level `(item,location)` is unique; movement service uses transactions, row locks, lock versions, outbox, and GL events (`api/app/Modules/Inventory/Services/StockMovementService.php:52-173`); adjustment approval re-reads under lock (`StockAdjustmentService.php:138-168`).
- **Ideal state/gaps:** the DB ledger should reject negative/impossible rows and make every adjustment-to-movement relationship idempotent. Movement/adjustment direction, quantity, endpoints, status, value relation, and movement uniqueness are not DB-enforced. Local stock movement/adjustment tables are empty, so deployed-volume behavior is not proven.

### 12. Landing

- **Purpose/users/features:** public visitors submit contact/newsletter interest; staff receive inquiry/subscriber data.
- **Inputs/outputs:** public form fields and email; outputs are persisted inquiries/subscribers and notification/marketing inputs.
- **Dependencies/consumers:** Admin notifications/settings and CRM/customer follow-up. Newsletter identity is defined in `api/database/migrations/0220_create_newsletter_subscribers_table.php:16-35`; `api/app/Providers/AppServiceProvider.php:62-63` registers the contact-inquiry model.
- **Lifecycle/current strengths:** newsletter email uniqueness prevents the basic duplicate subscription. Public intake is isolated from authenticated ERP flows.
- **Ideal state/gaps:** public submissions should be validated, rate-limited, consent-aware, and replay-safe. The available evidence establishes persistence and identity but not complete anti-abuse or operational SLA behavior; this remains uncertain rather than a ranked defect here.

### 13. Leave

- **Purpose/users/features:** employees submit leave; department/HR approvers decide; balances and year-end processing update payroll/attendance.
- **Inputs/outputs:** leave type, date range/half-day, reason/document; outputs are approval records, balance decrements, attendance/payroll effects, notifications, and year-end dispositions.
- **Dependencies/consumers:** HR employees and balances, Admin approval service, Attendance, Payroll, Dashboard. Evidence: `api/database/migrations/0026_create_leave_types_table.php:13-31`, `0027_create_employee_leave_balances_table.php:13-32`, `0028_create_leave_requests_table.php:13-35`, and `api/app/Modules/Leave/Services/LeaveRequestService.php:93-185`.
- **Lifecycle/current strengths:** request number and balance scope are unique; balance row is locked before checking; half-day semantics and pending-department/pending-HR/approved/rejected/cancelled states are represented; approval events feed notifications.
- **Ideal state/gaps:** overlapping approved/pending requests should be impossible under concurrent submission and approval, with a single balance/attendance effect. The date query's `lockForUpdate()->exists()` cannot lock absent ranges (`LeaveRequestService.php:132-159`), and no DB exclusion constraint exists. Current overlap query returned 0.

### 14. Loans

- **Purpose/users/features:** employees request/approve loans or cash advances; payroll deducts installments; finance/HR review balances and payment history.
- **Inputs/outputs:** employee/type/principal/rate/term, approval, payroll deduction/manual payment; outputs are amortization schedules, loan balances, payment history, payroll deduction details, and final-paid state.
- **Dependencies/consumers:** HR employees, Admin approvals/settings, Payroll, Accounting. Evidence: `api/database/migrations/0029_create_employee_loans_table.php:13-35`, `0030_create_loan_payments_table.php:13-24`.
- **Lifecycle/current strengths:** loan number is unique; employee FK cascades; money uses decimal(15,2); service checks active status before payment and Payroll has cycle claims.
- **Ideal state/gaps:** a payment should be applied once against a locked authoritative loan, trace to an existing payroll, and never overrun the balance. `LoanService.php:288-327` and `PayrollCalculatorService.php:824-870` do not provide one shared lock/idempotency invariant; `loan_payments.payroll_id` has no FK. This is the strongest current financial race in the map.

### 15. MRP

- **Purpose/users/features:** planners create MRP plans/runs from demand, stock, BOM, lead times, and open purchasing/production supply.
- **Inputs/outputs:** forecast demand, BOM/routing, stock/reservations, purchase/work-order state; outputs are planned shortages, purchase requests, and production recommendations.
- **Dependencies/consumers:** Forecasting, Inventory, Purchasing, Production, CRM demand. Evidence: `api/database/migrations/0086_create_mrp_plans_table.php:28-55`, `0110_create_mrp_runs_table.php:17-40`.
- **Lifecycle/current strengths:** plan numbers are unique and runs are separately recorded; planned links can be retained while source plans are removed/set null according to migration definitions.
- **Ideal state/gaps:** a run should be reproducible from a frozen input snapshot and idempotently publish recommendations. The schema map confirms plan/run persistence but does not establish full snapshot/replay guarantees; uncertainty is retained.

### 16. Maintenance

- **Purpose/users/features:** maintenance users manage equipment schedules, work orders, logs, downtime, condition readings, spare-part usage, and calibration.
- **Inputs/outputs:** machine/vehicle/asset condition, preventive schedule, work-order actions, spare parts; outputs are maintenance history, downtime/OEE inputs, inventory issues, and asset state.
- **Dependencies/consumers:** Assets, Inventory, Production machines/molds, Admin users/settings, Dashboard. Evidence: migrations `0100_create_maintenance_schedules_table.php:24-55`, `0101_create_maintenance_work_orders_table.php:20-54`, `0102_create_maintenance_logs_table.php:14-45`, `0103_create_spare_part_usage_table.php:19-44`.
- **Lifecycle/current strengths:** maintenance work-order identity and actor/item FKs are present; scheduled generation is event/listener-backed (`api/app/Providers/AppServiceProvider.php:78-80`).
- **Ideal state/gaps:** preventive generation, execution, and spare-part consumption should be retry-safe and linked to the same equipment state. Polymorphic schedule targets are not FK-backed; no additional current violation was demonstrated.

### 17. Payroll

- **Purpose/users/features:** payroll users calculate, review, approve/finalize, disburse, email payslips, generate bank files, deduct loans, and run 13th-month accruals.
- **Inputs/outputs:** periods, employees, attendance/leave/OT, salary/contributions, loans/adjustments; outputs are payroll rows, payslips, bank/disbursement records, GL postings, and loan/13th-month state.
- **Dependencies/consumers:** HR, Attendance, Leave, Loans, Accounting, Admin settings/approval, B2B/employee delivery. Evidence: migrations `0032_create_payroll_periods_table.php:13-28`, `0033_create_payrolls_table.php:13-51`, `0036_create_thirteenth_month_accruals_table.php:13-25`, `0439_create_payroll_cycle_claims_table.php:45-58`.
- **Lifecycle/current strengths:** payroll `(period,employee)`, accrual `(employee,year)`, cycle claims `(employee,cycle_key)`, and claim-to-payroll identity are unique. Partial auto-payroll idempotency was added by `2026_08_11_140000_add_auto_payroll_idempotency_key.php:23-61`.
- **Ideal state/gaps:** a period must be unique by business window, finalization immutable, statutory calculations complete, and every downstream money movement retry-safe. 13th-month period creation is unlocked and lacks annual uniqueness (`api/app/Modules/Payroll/Services/ThirteenthMonthService.php:146-177`); the service explicitly records the ₱90,000 BIR exemption as not yet applied (`:141-144`). Status strings are not DB checks.

### 18. Production

- **Purpose/users/features:** production users manage work orders, BOM/material issues, operations, outputs, defects, schedules, logs, molds/machines, and finished-goods receipt.
- **Inputs/outputs:** confirmed work order, materials, operator output/reject counts, defects, shifts; outputs are production totals, quality defects, stock receipts, OEE/dashboard events, and downstream shipment availability.
- **Dependencies/consumers:** CRM products/orders, Inventory stock, Quality defects/inspections, Maintenance machines/molds, Dashboard. Evidence: migrations `0080_create_work_orders_table.php:27-62`, `0081_create_work_order_materials_table.php:18-43`, `0082_create_work_order_outputs_table.php:17-29`, `0083_create_work_order_defects_table.php:18-34`.
- **Lifecycle/current strengths:** work-order number/batch identity is unique; output validates positive total and records defects; output-to-stock handoff has recovery state and outbox chain. `WorkOrderOutputService.php:37-48,67-95,195-215` documents the intended idempotency behavior.
- **Ideal state/gaps:** one client command should create one output and one downstream receipt even under retries/concurrency. Output idempotency is only a 24-hour cache read/write; the output table has no idempotency key/unique constraint. This is a latent double-production/double-receipt risk.

### 19. Purchasing

- **Purpose/users/features:** requesters and buyers manage purchase requests, approvals, templates, POs, supplier qualification, GRNs, and purchasing automation.
- **Inputs/outputs:** item/vendor demand, approvals, supplier prices, PO lines, receipts; outputs are supplier commitments, GRNs, inventory receipts, bills, and MRP fulfillment.
- **Dependencies/consumers:** MRP/Forecasting recommendations, Inventory/Quality GRNs, Accounting AP, B2B supplier portal, SupplyChain shipments. Evidence: migrations `0058_create_purchase_requests_table.php:13-47`, `0060_create_purchase_orders_table.php:13-49`, `0061_create_purchase_order_items_table.php:13-37`, `0063_create_goods_receipt_notes_table.php:13-44`.
- **Lifecycle/current strengths:** request/order/GRN document numbers are unique; supplier/item and source FKs are present; supplier dispatches separately enforce PO and idempotency uniqueness.
- **Ideal state/gaps:** approval, dispatch, receipt, and invoice should be one recoverable chain with no duplicate external commitment. Current DB keys are comparatively strong; status transitions and external delivery evidence remain service/event concerns.

### 20. Quality

- **Purpose/users/features:** quality users define inspection plans/specifications, inspect GRNs/production, record measurements, manage NCR/8D/MRB/PPAP, and track calibration.
- **Inputs/outputs:** product/item specs, GRN/production samples, measurement results, defects, corrective actions; outputs are accept/reject/hold decisions, NCR/MRB actions, supplier/customer feedback, and inventory release/hold.
- **Dependencies/consumers:** Purchasing GRNs, Production outputs, Inventory holds/movements, CRM complaints, Maintenance calibration, Dashboard. Evidence: migrations `0087_create_inspection_specs_table.php:25-50`, `0089_create_inspections_table.php:22-49`, `0090_create_inspection_measurements_table.php:21-43`, `0091_create_non_conformance_reports_table.php:26-52`, `0240_create_ppap_tables.php:16-69`.
- **Lifecycle/current strengths:** inspection number and `(GRN item,stage)`, NCR number, PPAP number/elements, and calibration codes have uniqueness; measurement/action children are FK-backed.
- **Ideal state/gaps:** an accepted/rejected/held item must have a single authoritative quality decision and recoverable downstream release. Current schema supports the main identities; status/check semantics and cross-module handoff completeness require service-level verification.

### 21. ReturnManagement

- **Purpose/users/features:** customer/supplier returns manage RMA intake, approval, receipt, inspection, disposition, replacement/refund/credit, and stock movement.
- **Inputs/outputs:** sales/purchase/invoice/bill source, reason, returned items, inspection/NCR outcomes; outputs are stock receipt/return movements, credit/debit notes, replacement orders, and closure history.
- **Dependencies/consumers:** CRM/SupplyChain source documents, Accounting credit notes/bills, Inventory movements, Quality inspections/NCR, Purchasing replacement POs. Evidence: `api/database/migrations/0158_create_return_requests_table.php:26-82`, `:85-119`; service handoffs are in `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:516-537,626-651`.
- **Lifecycle/current strengths:** RMA number is unique; source/customer/vendor/credit/stock/inspection/NCR FKs are mostly present with null-on-delete semantics; current credit-note relation is correctly repointed by `0269_add_credit_note_id_to_return_requests.php`.
- **Ideal state/gaps:** return inspection, disposition, stock, and financial resolution should be one state machine with no double completion. No current orphan was found; status and line-level uniqueness remain primarily application-level.

### 22. SupplyChain

- **Purpose/users/features:** logistics users manage shipments, lots, deliveries, documents, proof of delivery, schedules, containers, and landed costs.
- **Inputs/outputs:** POs, shipment/lot data, delivery schedules, warehouse/customer receipt, documents/proofs; outputs are delivery confirmation, invoice triggers, customer portal visibility, and landed-cost allocation.
- **Dependencies/consumers:** Purchasing POs, CRM sales orders/customers, Inventory stock, Accounting invoices/landed costs, B2B portal. Evidence: `api/database/migrations/0093_create_shipments_table.php:22-55`, `0096_create_deliveries_table.php:21-57`, `0150_add_batch_lot_traceability.php:36-58`, `0156_add_delivery_proofs.php:27-54`.
- **Lifecycle/current strengths:** shipment and delivery numbers are unique; shipments/deliveries and lots/proofs are FK-backed; shipment-lot and landed-cost scope keys constrain common duplicates.
- **Ideal state/gaps:** dispatch, delivery, proof, invoice, and stock posting should be an idempotent chain with clear recovery. Current source links are mostly constrained; invoice source columns remain the accounting-side gap noted above.

## Cross-module data invariants

| Invariant | Current enforcement | Current-state/ideal-state assessment |
|---|---|---|
| Employee identity flows into Auth, Attendance, Leave, Loans, Payroll, Assets | Employee/user/child FKs and employee-number uniqueness; event listeners for provisioning/separation | Strong identity base. Ideal requires atomic/retry-safe hire and separation effects. |
| Approved leave and OT affect attendance/payroll once | Application state machines; attendance date uniqueness; OT only indexed | Leave range overlap and OT duplicate races are not DB-safe. Ideal requires serialized claim plus immutable payroll input. |
| Loan deductions reconcile payroll, payment history, and balance | Payroll cycle claims and payroll uniqueness; loan payment payroll link has no FK; aggregate updates use stale objects | High-risk race and traceability gap. Ideal is locked loan + unique payment claim + FK. |
| Payroll period and 13th-month annual uniqueness | Payroll rows and accruals have unique scopes; period itself does not | Employee-level idempotency is stronger than period-level idempotency. Ideal is one business period per year/window. |
| Inventory quantities/WAC and GL stay aligned | Stock-level unique key, service transactions/locks/outbox/GL; weak DB domain constraints | Good service path, weak database backstop. Ideal rejects impossible ledger rows and makes every source command idempotent. |
| Production output creates one production fact and one stock receipt | Work-order identity and outbox recovery state; client idempotency cache only | Cache expiry/race can duplicate. Ideal uses durable unique command key. |
| Purchasing/GRN/Quality/Inventory/Accounting traceability | Most PO/GRN/inspection/source FKs and document uniques exist | Invoice source and credit-note journal/RMA references are raw integers. Ideal preserves every source link or explicit archival tombstone. |
| Dashboard role defaults and user overrides | Permission filtering and per-request deduplication | Concurrent clone/save is not versioned or uniquely constrained. Ideal is owner/widget unique and optimistic-lock protected. |
| Status/enum validity | PHP enums/request rules and service-only fields | DB has almost no status checks. Ideal has migration-backed status domains or controlled write boundary. |

## Deliberately hidden scope cuts (not defects)

- CRM leads/opportunities/quotes are historical tables removed by `api/database/migrations/0454_drop_crm_sales_funnel_tables.php:39-49`; they are not current missing implementations.
- Dashboard `widget_key` has no FK by design so a retired widget does not cascade-delete layout history (`api/database/migrations/0129_create_dashboard_layouts_table.php:16-18`).
- Dashboard `owner_type/owner_id` is polymorphic, so a direct owner FK is not expected; uniqueness and concurrency remain separate concerns.
- Approval records are polymorphic and intentionally retain historical rows; lack of a single unique row per approvable/step is not itself a defect (`api/app/Common/Services/ApprovalService.php:23-54`).
- Stock movement `reference_type/reference_id` and maintenance schedule targets are polymorphic integration links; they cannot receive ordinary relational FKs without a design change.
- Nullable customer scope in Forecasting intentionally represents “total demand across all customers” (`api/app/Modules/Forecasting/Models/DemandForecast.php:19-25`); the defect risk is PostgreSQL NULL uniqueness semantics, not the existence of global forecasts.
- `return_requests.credit_memo_id → invoices` is intentional credit-memo terminology; `credit_note_id → credit_notes` is the separate relation corrected by migration 0269.
- Empty local stock adjustment/movement tables are a data-state observation, not evidence that the inventory feature is absent.

## Evidence limits

- The worktree was dirty during inspection. This artifact describes the files currently present, including uncommitted code and migrations; it does not assert that every change is committed or deployed.
- PostgreSQL introspection was local Docker state (`ogami-db`), not production. Zero orphan/duplicate results therefore establish only the inspected local snapshot.
- Static module/state descriptions are based on routes, migrations, models, services, event registrations, and current rows. They do not prove every UI path, queue retry, external integration, or production configuration.
- No unsupported production-volume, legal, or business-policy assumptions are made. The 13th-month tax simplification is recorded because the current service explicitly documents it (`api/app/Modules/Payroll/Services/ThirteenthMonthService.php:141-144`); statutory acceptance remains a business/legal decision.
