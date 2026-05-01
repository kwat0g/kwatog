# OGAMI ERP — Task Queue

> Sequential build plan. ~85 tasks across 8 sprints (4 weeks each).
> **How to execute:** `claude "Execute task N from docs/TASKS.md. Follow CLAUDE.md conventions."`
> **References:** CLAUDE.md (conventions), DESIGN-SYSTEM.md (UI), SCHEMA.md (tables), SEEDS.md (data)

Every task is self-contained. Each follows the module pattern: Migration → Enum → Model → Service → Request → Resource → Controller → Routes → Frontend types → API → Pages → Components.

---

## SPRINT 1 — FOUNDATION (Tasks 1–12)

Build the bones. Nothing else works without this sprint being solid.

### Task 1: Docker Compose setup
Create `docker-compose.yml` with 9 services: api (PHP 8.3-FPM), spa (Node 20 dev), nginx (alpine), db (postgres:16-alpine), redis (redis:7-alpine), meilisearch, reverb (PHP), queue (PHP worker), mailpit (dev). Create `docker/php/Dockerfile` (PHP 8.3-FPM + pdo_pgsql, redis, gd, zip, bcmath, intl + Composer), `docker/nginx/default.conf` (proxy /api/* to api:9000, /ws to reverb:8080, serve SPA), `docker/node/Dockerfile` (Node 20 alpine). Create `.env.example` and `Makefile` (up, down, migrate, seed, fresh, test, shell, logs, tinker).

### Task 2: Laravel API scaffolding
Inside `api/`: `composer create-project laravel/laravel .` (Laravel 11). Install packages: `laravel/sanctum`, `vinkla/hashids`, `barryvdh/laravel-dompdf`, `maatwebsite/excel`, `laravel/scout`, `meilisearch/meilisearch-php`, `laravel/reverb`. Configure `.env` for PostgreSQL, Redis, Sanctum SPA mode (NOT API token mode). Set `session.driver=database`, `session.http_only=true`, `session.same_site=lax`, `cors.supports_credentials=true`. Create the `app/Modules/` directory structure with subdirectories for all 15 modules + `app/Common/{Traits,Services,Enums,Middleware}`. Create `ModuleServiceProvider` that auto-loads `routes.php` from each module directory.

### Task 3: React SPA scaffolding
Inside `spa/`: `npm create vite@latest . -- --template react-ts`. Install: `react-router-dom`, `@tanstack/react-query`, `@tanstack/react-table`, `zustand`, `axios`, `react-hook-form`, `@hookform/resolvers`, `zod`, `recharts`, `lucide-react`, `date-fns`, `clsx`. Install dev: `tailwindcss`, `postcss`, `autoprefixer`, `@types/react`, `vitest`. Initialize Tailwind. Set up path alias `@/` → `src/`. Create directory structure per CLAUDE.md. Create `src/api/client.ts` with Axios instance (`withCredentials: true`, base URL `/api/v1`, response interceptor for 401 redirect).

### Task 4: Design system foundation
Create `spa/src/styles/tokens.css` with ALL CSS variables from DESIGN-SYSTEM.md (light + dark mode). Load Geist + Geist Mono fonts via Google Fonts in `index.html`. Configure `tailwind.config.ts` to read from CSS variables (colors, fontFamily, fontSize). Create `spa/src/stores/themeStore.ts` (Zustand: mode light/dark/system, resolvedTheme, setMode, persists to DB via API). Apply theme via `document.documentElement.setAttribute('data-theme', mode)`.

### Task 5: Base UI primitives
Create in `spa/src/components/ui/`: Button (primary/secondary/danger, sm/md/lg), Input (with label, error, helper), Select (searchable), Textarea, Checkbox, Radio, Switch, Chip (success/warning/danger/info/neutral variants with bg+fg from DESIGN-SYSTEM), StatCard, Panel (with header), Modal (sm/md/lg/xl), Toast (integrate react-hot-toast), Skeleton, Spinner, EmptyState, Badge, Avatar, Tooltip. All components use tokens from tokens.css. Follow exact specs in DESIGN-SYSTEM.md.

### Task 6: DataTable component
Build `spa/src/components/ui/DataTable.tsx` using TanStack Table. Features: sortable columns (click header, show arrow), filterable, server-side pagination, row selection checkboxes, column visibility toggle, context menu (right-click row), bulk action toolbar (shows when rows selected), export buttons (CSV/PDF). Row height: 32px default, 28px compact, 40px spacious (user toggle). Numbers use `font-mono tabular-nums` right-aligned. Header: `text-2xs uppercase tracking-wider text-muted`. Props: `columns`, `data`, `meta`, `onPageChange`, `onSort`, `onFilter`, `bulkActions`, `contextMenu`, `density`.

### Task 7: Layout shell (Topbar + Sidebar + AppLayout)
Create `spa/src/components/layout/Topbar.tsx` (48px, logo + breadcrumbs + search trigger + theme toggle + notification bell + avatar). Create `spa/src/components/layout/Sidebar.tsx` (collapsible: 240px expanded / 56px rail, section labels uppercase, nav items, active state with 2px indigo indicator, badge counts). Create `spa/src/layouts/AppLayout.tsx` combining them. Create `spa/src/stores/sidebarStore.ts` (collapsed bool, persists to DB). Use Lucide icons throughout.

### Task 8: Chain visualization components
Create `spa/src/components/chain/ChainHeader.tsx` (horizontal process timeline, dots + lines, states: done/active/pending), `StageBreakdown.tsx` (vertical list with progress bars for dashboard), `LinkedRecords.tsx` (grouped record list for right panel), `ActivityStream.tsx` (chronological events). Follow exact specs in DESIGN-SYSTEM.md section "Chain Process Components".

### Task 9: Authentication (HTTP-only cookies)
**Database:** Migration `0001_create_roles_table.php`, `0002_create_permissions_table.php`, `0003_create_role_permissions_table.php`, `0004_create_users_table.php` (with role_id, employee_id nullable, is_active, last_activity, password_changed_at, failed_login_attempts, locked_until, must_change_password), `0005_create_password_history_table.php`, `0006_create_sessions_table.php` (Laravel default for database sessions).

**Backend:** Create `app/Common/Traits/HasHashId.php`. Create middleware: `CheckPermission`, `SessionTimeout` (15 min employee / 30 min others based on role), `CheckPasswordExpiry` (force change if > 90 days), `SanitizeInput`. Create `LoginController` (POST /auth/login — rate limit 5/min, increment failed_login_attempts on wrong password, lock account at 5 failures for 15 min, reset on success), `LogoutController`, `UserController` (GET /auth/user — returns user + role + permissions), `ChangePasswordController` (validates old password, checks against last 3 in password_history, stores new hash). Password policy: min 8 + uppercase + number + special. bcrypt cost 12. Rate limit auth routes to 5/min.

**Frontend:** Update `api/client.ts` to use `withCredentials: true`. Create `api/auth.ts`. Create `stores/authStore.ts` (user, permissions, isAuthenticated, isLoading, login, logout, checkSession). Create `components/guards/AuthGuard.tsx`, `PermissionGuard.tsx`, `ModuleGuard.tsx`. Create `pages/auth/login.tsx` (calls `/sanctum/csrf-cookie` first, then POST /auth/login). Create `pages/auth/change-password.tsx` for forced changes. **Never store tokens in localStorage.**

### Task 10: Dynamic RBAC
Seeder `RolePermissionSeeder`: create ~100 permissions following `{module}.{resource}.{action}` pattern (e.g., `hr.employees.view`, `hr.employees.create`, `payroll.periods.finalize`). Create 12 default roles: System Admin (all permissions), HR Officer, Finance Officer, Production Manager, PPC Head, Purchasing Officer, Warehouse Staff, QC Inspector, Maintenance Tech, ImpEx Officer, Department Head, Employee. Assign appropriate permissions. Create admin pages: `pages/admin/roles/index.tsx` (list), `pages/admin/roles/[id]/permissions.tsx` (matrix: rows = permissions grouped by module, columns = this role, checkboxes toggle). Create `hooks/usePermission.ts`.

### Task 11: Core shared services
Create `app/Common/Services/DocumentSequenceService.php` (atomic number generation using SELECT FOR UPDATE on `document_sequences` table, format `{PREFIX}-{YYYYMM}-{NNNN}`). Create `app/Common/Services/ApprovalService.php` (workflow engine: submit for approval, approve, reject, current step, next step, is fully approved). Create `app/Common/Traits/HasAuditLog.php` (logs create/update/delete to `audit_logs` with old/new values JSON). Create `app/Common/Traits/HasApprovalWorkflow.php`. Create `app/Common/Services/NotificationService.php` (wrapper around Laravel notifications, respects user preferences). Migration `0007_create_document_sequences_table.php`, `0008_create_audit_logs_table.php`, `0009_create_workflow_definitions_table.php`, `0010_create_approval_records_table.php`, `0011_create_notifications_table.php`, `0012_create_notification_preferences_table.php`. Seed all workflow definitions from SEEDS.md.

### Task 12: Settings & feature toggles
Migration `0013_create_settings_table.php` (key, value JSON, group). Create `app/Common/Services/SettingsService.php` (get, set, getGroup, cached in Redis). Seed settings: company.name, company.address, company.tin, fiscal.year_start_month=1, payroll.schedule=semi_monthly, and feature toggles `modules.hr=true`, `modules.attendance=true`, etc. (enable HR + Attendance + Leave + Payroll + Loans + Accounting for Semester 1, disable rest). Sidebar reads feature toggles — disabled modules hidden. API routes middleware checks feature toggle → return 403 if disabled. Create `pages/admin/settings.tsx` for admin to toggle.

---

## SPRINT 2 — HIRE TO RETIRE (Part 1: HR + Attendance + Leave) (Tasks 13–22)

### Task 13: Departments & Positions
Module: `api/app/Modules/HR/`. Migrations `0014_create_departments_table.php`, `0015_create_positions_table.php`. Models Department (self-referencing parent_id for hierarchy, head_employee_id), Position (belongs to Department). Service, Controller (CRUD), Requests, Resources, Routes. Seed 11 departments + ~35 positions from SEEDS.md. Frontend: `pages/hr/departments/index.tsx` (tree view), `pages/hr/positions/index.tsx` (table with department filter).

### Task 14: Employees — Backend
Migrations: `0016_create_employees_table.php` (~40 columns per SCHEMA.md including encrypted sss_no, philhealth_no, pagibig_no, tin, bank_account_no), `0017_create_employee_documents_table.php`, `0018_create_employment_history_table.php`, `0019_create_employee_property_table.php`. Enums: `EmployeeStatus`, `EmploymentType`, `PayType`, `CivilStatus`, `Gender`. Model Employee uses HasHashId, HasAuditLog, SoftDeletes, encrypted casts, full_name accessor. Service EmployeeService (create with auto-generated employee_no, update, list with filters, separation process). Controller with CRUD + separation endpoint. Form Requests. EmployeeResource with data masking for non-HR users. Routes.

### Task 15: Employees — Frontend
`pages/hr/employees/index.tsx` — DataTable with columns: employee_no (mono), full_name, department, position, status (chip), pay_type, date_hired (mono). Filters: department, status, employment_type, pay_type. Export CSV. `pages/hr/employees/create.tsx` — multi-section form (Personal, Contact, Government IDs, Employment, Banking) with React Hook Form + Zod. `pages/hr/employees/[id].tsx` — detail page with tabs: Overview, Employment History, Attendance, Payroll, Leaves, Loans, Documents, Property, Activity. Use LinkedRecords panel on right side showing related data. `pages/hr/employees/[id]/edit.tsx` — edit form. Create `api/employees.ts` with all CRUD functions.

### Task 16: Shifts
Migrations `0020_create_shifts_table.php`, `0021_create_employee_shift_assignments_table.php`. Model Shift (name, start_time, end_time, break_minutes, is_night_shift, is_extended, auto_ot_hours). Seed 4 shifts from SEEDS.md: Day, Extended Day, Night, Office. CRUD + bulk shift assignment (select department → assign shift to all employees in it, with effective date).

### Task 17: Holidays
Migration `0022_create_holidays_table.php`. Model Holiday (name, date, type regular/special_non_working, is_recurring). Seed 2026 PH holidays from SEEDS.md. CRUD. Frontend: `pages/hr/holidays/index.tsx` with calendar view + list view toggle.

### Task 18: Attendance + DTR Computation Engine
Migration `0023_create_attendances_table.php`, `0024_create_overtime_requests_table.php`. Model Attendance. Enum AttendanceStatus. **Service `DTRComputationService` — this is critical.** Method `computeForRecord(attendance)` takes time_in, time_out, shift, holiday, is_rest_day → computes regular_hours, overtime_hours, night_diff_hours, tardiness_minutes, undertime_minutes, day_type_rate. Must handle: all 14 holiday combinations, night diff 10PM–6AM ONLY, extended shift auto-OT (first 8 hrs regular + remaining up to 4 hrs as OT no approval needed), cross-midnight (attendance belongs to date shift started), monster edge case (regular holiday + rest day + night shift + OT = up to 487% of daily rate). Write unit tests for every combination. Service `DTRImportService` parses CSV from biometric, validates, creates attendance records, calls DTRComputationService. Controller with CRUD + POST /attendances/import.

### Task 19: Attendance — Frontend
`pages/attendance/index.tsx` — DataTable with filters (employee, department, date range, status). Shows computed hours. `pages/attendance/import.tsx` — CSV upload with drag-drop, simple mapping (system expects columns: employee_no, date, time_in, time_out), preview first 10 rows, submit. Show import progress. `pages/attendance/overtime/index.tsx` — OT requests with Kanban view toggle (pending/approved/rejected) and approve/reject buttons for dept heads.

### Task 20: Leave Management — Backend
Migrations `0025_create_leave_types_table.php`, `0026_create_employee_leave_balances_table.php`, `0027_create_leave_requests_table.php`. Models, services, controllers. Seed 8 leave types from SEEDS.md. Validation: sufficient balance, no overlap, no past dates (unless HR). 2-tier approval (Employee → Dept Head → HR Officer) via HasApprovalWorkflow trait. On final approval: deduct balance, mark attendance dates as on_leave.

### Task 21: Leave Management — Frontend
`pages/leaves/index.tsx` — list with filters + Kanban view. `pages/leaves/create.tsx` — form with leave type dropdown (shows remaining balance), date range, reason, document upload. `pages/leaves/calendar.tsx` — calendar view of department-wide leave schedule. `pages/leaves/approvals.tsx` — pending approvals for current user's role.

### Task 22: Loans & Cash Advance
Migrations `0028_create_employee_loans_table.php`, `0029_create_loan_payments_table.php`. Enums LoanType (company_loan, cash_advance), LoanStatus. Model with approval workflow. Validation: max 1 active company_loan + 1 active cash_advance per employee, company_loan max = 1 month basic_monthly_salary. On approval: generate amortization schedule (divide principal by N pay periods). Approval chains: cash advance 3-level (Staff → Dept Head → Accounting → VP), company loan 4-level (+ Manager before Accounting). Frontend list + create form (shows max amount, amortization preview) + detail page (schedule, payment history, remaining balance).

---

## SPRINT 3 — HIRE TO RETIRE (Part 2: Payroll) (Tasks 23–30)

### Task 23: Government contribution tables
Migration `0030_create_government_contribution_tables_table.php` (agency, bracket_min, bracket_max, ee_amount, er_amount, effective_date, is_active). Seed SSS table, PhilHealth, Pag-IBIG, BIR tables from SEEDS.md. Admin page `pages/admin/gov-tables.tsx` — editable table per agency.

### Task 24: Government deduction services
Create 4 computation services: `SssComputationService::compute(salary) → {ee, er}` (bracket lookup), `PhilhealthComputationService::compute(salary) → {ee, er}` (rate-based with floor ₱10K / ceiling ₱100K, split 50/50), `PagibigComputationService::compute(salary) → {ee, er}` (brackets with max EE ₱200), `BirTaxComputationService::compute(taxable, period_type) → tax` (semi-monthly graduated table from TRAIN Law). Write unit tests for each against official calculator values.

### Task 25: Payroll engine
Migrations `0031_create_payroll_periods_table.php`, `0032_create_payrolls_table.php`, `0033_create_payroll_deduction_details_table.php`, `0034_create_payroll_adjustments_table.php`, `0035_create_thirteenth_month_accruals_table.php`. Service `PayrollCalculatorService` — the orchestrator. For each employee: fetch attendance for period, compute basic pay (monthly: fixed half / daily: days × rate, pro-rated if mid-period hire/separation), add OT pay, add night diff pay, add holiday pay, subtract tardiness/undertime → gross. Compute gov deductions (ONLY on 1st period of month), subtract loan auto-deductions (query active loans), subtract cash advance auto-deductions. Store in payrolls + payroll_deduction_details.

### Task 26: Payroll processing job
Create `ProcessPayrollJob` (queue job). For payroll_period: wrap in `DB::transaction`, iterate all active employees, call PayrollCalculatorService for each, catch errors per employee without failing whole batch, update period status to 'draft' when done. Create `PayrollController` with: POST /payroll-periods (create), POST /payroll-periods/{id}/compute (dispatch job), PATCH /payroll-periods/{id}/approve (HR), PATCH /payroll-periods/{id}/finalize (Finance — after this, period is locked). Create `PayrollAdjustmentService` for corrections to finalized periods (creates adjustment applied to next period's payroll).

### Task 27: Payslip PDF + bank file
Create `resources/views/pdf/payslip.blade.php` using DomPDF. A5 format (2 per A4). CONFIDENTIAL watermark. "Generated by [name] on [date]" footer. Company header with Ogami logo. Service `PayslipPdfService::generate(payroll)` → PDF. Service `BankFileService::generate(payroll_period)` → CSV file with employee_no, full_name, bank, account, net_pay. Store in `bank_file_records` table. GET /payrolls/{id}/payslip returns PDF. GET /payroll-periods/{id}/bank-file returns CSV.

### Task 28: 13th month pay
Service `ThirteenthMonthService::accrue(employee, period)` — adds basic pay to running accrual. Service `computeAndPay(year)` — called December 1: for each active employee, final accrual amount = total basic earned ÷ 12, creates payroll entry in December 15 period. Auto-posts to GL.

### Task 29: Payroll → GL auto-posting
After payroll_period finalized, create journal entry: DR Salaries Expense, DR OT Expense, DR SSS Employer Expense, DR PhilHealth Employer Expense, DR Pag-IBIG Employer Expense, CR SSS Payable, CR PhilHealth Payable, CR Pag-IBIG Payable, CR Withholding Tax Payable, CR Loans Payable (for auto-deductions), CR Cash in Bank (net pay total). Must balance. Store journal_entry_id on payroll_period.

### Task 30: Payroll — Frontend
`pages/payroll/periods/index.tsx` — list of periods with status. `pages/payroll/periods/[id].tsx` — period detail: summary cards (total gross, total deductions, total net, employee count), employee payroll table, approve/finalize buttons. `pages/payroll/periods/[id]/employee/[eid].tsx` — individual payroll breakdown. `pages/payroll/adjustments/index.tsx` — corrections page. `pages/self-service/payslips.tsx` — employee views own payslips with download.

---

## SPRINT 4 — LEAN ACCOUNTING (Tasks 31–38)

### Task 31: Chart of Accounts
Migration `0036_create_accounts_table.php` (hierarchical: parent_id self-ref). Model Account. Seed ~45 accounts from SEEDS.md. CRUD (admin only). Frontend `pages/accounting/coa/index.tsx` — tree view.

### Task 32: Journal Entries
Migrations `0037_create_journal_entries_table.php` (entry_number, date, description, reference_type, reference_id, total_debit, total_credit, status), `0038_create_journal_entry_lines_table.php` (account_id, debit, credit, description). Service: create (validate debit=credit), post (status draft → posted, immutable after), reverse (create reversing entry, never delete posted). Controller. Frontend: `pages/accounting/journal-entries/index.tsx` (list), `[id].tsx` (detail), `create.tsx` (line-by-line builder with running balance check).

### Task 33: Vendors + AP Bills
Migrations `0039_create_vendors_table.php`, `0040_create_bills_table.php`, `0041_create_bill_items_table.php`, `0042_create_bill_payments_table.php`. Models. Service handles bill creation (auto JE: DR Expense accounts + DR VAT Input, CR AP), payment (auto JE: DR AP, CR Cash). AP aging report (current/30/60/90/120+). Frontend: vendor CRUD, bill list + create + detail, payment form.

### Task 34: Customers + AR Invoices
Migrations `0043_create_customers_table.php`, `0044_create_invoices_table.php`, `0045_create_invoice_items_table.php`, `0046_create_collections_table.php`. Models. Service handles invoice creation (auto JE: DR AR, CR Revenue + CR VAT Output), collection (auto JE: DR Cash, CR AR). AR aging report. Frontend: customer CRUD, invoice list + create + detail, collection form. Invoice PDF template (A4 portrait with Ogami letterhead).

### Task 35: Financial statements (live dashboards)
Service `TrialBalanceService::generate(from, to)` → array of accounts with debit/credit totals. Service `IncomeStatementService::generate(from, to)` → revenue accounts minus expense accounts → net income. Service `BalanceSheetService::generate(as_of)` → assets, liabilities, equity. These are NOT reports — they're live API endpoints that return JSON. Frontend: `pages/accounting/trial-balance.tsx`, `income-statement.tsx`, `balance-sheet.tsx` — dense tables with date range selector, export CSV, print PDF button.

### Task 36: Print templates
Create DomPDF Blade templates: `bill.blade.php`, `invoice.blade.php`, `journal-entry.blade.php`, `trial-balance.blade.php`, `income-statement.blade.php`, `balance-sheet.blade.php`. All with Ogami letterhead, "Generated by [user] on [date]" footer, page numbers, signature lines where needed. CONFIDENTIAL watermark on financial statements.

### Task 37: Dashboard — Finance section
`pages/dashboard/index.tsx` — main dashboard with KPI cards (Cash Balance, AR Outstanding, AP Outstanding, Revenue MTD). Panel: AP/AR aging summary. Panel: Recent journal entries. Panel: Top 5 overdue customers. Use StatCard, Panel, DataTable components. All numbers in mono. Drill-down: click AR card → AR aging page.

### Task 38: VPS deployment
Create `docker-compose.prod.yml` with production config (no dev services, Nginx with SSL). Set up DigitalOcean droplet. Install Docker + Docker Compose. Configure Certbot for Let's Encrypt SSL. Create `.env.production`. Deploy via git pull + docker compose up. Test auth flow end-to-end with HTTPS cookies. Configure daily pg_dump backup cron. **Semester 1 complete.**

---

## SPRINT 5 — PROCURE TO PAY (Part 1: Inventory + Purchasing) (Tasks 39–46)

### Task 39: Item master + categories
Migrations `0047_create_item_categories_table.php`, `0048_create_items_table.php`. Enum ItemType (raw_material, finished_good, packaging, spare_part). Model. Service. CRUD. Seed 12 raw materials + packaging from SEEDS.md. Frontend: `pages/inventory/items/index.tsx` — dense table with code, name, type, UOM, standard_cost (mono), stock level, reorder_point. Filters by type.

### Task 40: Warehouse structure
Migrations `0049_create_warehouses_table.php`, `0050_create_warehouse_zones_table.php`, `0051_create_warehouse_locations_table.php`. Seed 1 warehouse with 4 zones (A Raw Materials, B Staging, C Finished Goods, D Spare Parts) and sample bins. Frontend: `pages/inventory/warehouse.tsx` — tree view of zones and bins.

### Task 41: Stock movements + GRN + Material Issue
Migrations `0052_create_stock_levels_table.php` (item + location + quantity + reserved + weighted_avg_cost), `0053_create_stock_movements_table.php` (polymorphic: grn_receipt, material_issue, production_receipt, delivery, adjustment, scrap), `0054_create_goods_receipt_notes_table.php`, `0055_create_material_issue_slips_table.php`, `0056_create_material_reservations_table.php`. Service `StockMovementService` — handles all movement types, recalculates weighted average cost on receipt. Service `GrnService::create(po, items)` — creates GRN, increases stock, recalculates weighted avg. Service `MaterialIssueService::create(work_order, items)` — decreases stock from reservations. Frontend: GRN create page, material issue page, stock levels page.

### Task 42: Purchasing — Request + PO
Migrations `0057_create_purchase_requests_table.php`, `0058_create_purchase_request_items_table.php`, `0059_create_purchase_orders_table.php`, `0060_create_purchase_order_items_table.php`, `0061_create_approved_suppliers_table.php`. Approval chain 4-level (Staff → Head → Manager → Purchasing → VP). Service `PurchaseOrderService::createFromApprovedPR(pr)` — consolidates items by supplier. PO approval: VP required if total ≥ ₱50,000 (configurable). On approval: auto-email PO PDF to supplier. PO PDF template.

### Task 43: 3-way matching (PO vs GRN vs Bill)
When bill is created referencing a PO: compare quantities across PO, GRN, bill. Allow 5% tolerance. If variance > 5%: flag as discrepancy, block posting until Purchasing Officer resolves. Show 3-way match panel on bill detail page with all three columns side by side.

### Task 44: Purchasing — Frontend
`pages/purchasing/purchase-requests/index.tsx` (list with Kanban toggle), `create.tsx` (form with item picker), `[id].tsx` (detail with approval chain visualization). `pages/purchasing/purchase-orders/index.tsx`, `[id].tsx` (detail with ChainHeader showing PR → PO → GRN → Bill → Payment → Closed, LinkedRecords panel, line items table). `pages/purchasing/approved-suppliers.tsx`.

### Task 45: Low stock automation
Event listener on StockMovementService: after movement, check if any affected item's stock < reorder_point. If yes AND no pending PR for that item: auto-create draft Purchase Request with calculated order quantity. Notify Purchasing Officer + PPC Head. Mark PR as auto-generated (different icon/chip).

### Task 46: Inventory dashboard
`pages/inventory/dashboard.tsx` — KPI cards (Total Stock Value, Items Below Reorder, Critical Low, Pending GRNs), panels: Low Stock Alerts table, Recent Movements stream, Top Consumed Materials. Weighted avg cost visible. Chain visibility: show where each low-stock item is in the procure-to-pay chain.

---

## SPRINT 6 — ORDER TO CASH (Part 1: CRM + MRP + Production) (Tasks 47–58)

### Task 47: CRM Customers + price agreements
Migrations `0062_create_products_table.php`, `0063_create_product_price_agreements_table.php`. Model Product, PriceAgreement (customer + product + price + effective dates). Seed 5 customers + 8 products + 15 price agreements from SEEDS.md. Frontend: `pages/crm/customers/index.tsx` + detail page with price agreements tab.

### Task 48: Sales Orders + delivery schedules
Migrations `0064_create_sales_orders_table.php`, `0065_create_sales_order_items_table.php`. Service creates SO from entered delivery schedule (each line becomes a future work order). On SO confirmation: trigger MRP engine (Task 52). Frontend: `pages/crm/sales-orders/index.tsx` (dense table), `create.tsx` (customer → auto-pull price agreements → add line items with delivery dates), `[id].tsx` (detail with ChainHeader showing full Order-to-Cash chain, LinkedRecords showing MRP plan, work orders, QC inspections, deliveries, invoice).

### Task 49: Bill of Materials
Migrations `0066_create_bill_of_materials_table.php`, `0067_create_bom_items_table.php`. Model BOM (one per product, versioned). BomItem (item_id, quantity_per_unit, waste_factor). Seed BOMs for 8 demo products. Frontend: `pages/mrp/boms/index.tsx`, `[product_id].tsx` (edit BOM — list of raw materials with quantities).

### Task 50: Machines + Molds
Migrations `0068_create_machines_table.php`, `0069_create_molds_table.php`, `0070_create_mold_machine_compatibility_table.php`, `0071_create_mold_history_table.php`. Seed 12 machines + 15 molds from SEEDS.md. Mold tracking: current_shot_count, max_shots_before_maintenance, lifetime_total_shots, lifetime_max_shots, status, location. Frontend: `pages/mrp/machines/index.tsx` (list with status), `pages/mrp/molds/index.tsx` (list with shot count progress bar, alert at 80%).

### Task 51: Work Orders
Migrations `0072_create_work_orders_table.php`, `0073_create_work_order_materials_table.php`, `0074_create_work_order_outputs_table.php`, `0075_create_work_order_defects_table.php`, `0076_create_production_schedules_table.php`, `0077_create_machine_downtimes_table.php`. Seed 11 defect types from SEEDS.md. Work order lifecycle: planned → confirmed (reserves materials) → in_progress (starts, generates material requisition) → paused (breakdown, creates machine_downtime) → resumed → completed → closed.

### Task 52: MRP engine
Service `MrpEngineService::runForSalesOrder(so)` — called on SO confirmation. For each SO line: look up BOM, calculate gross material requirements (qty × bom items). Subtract on-hand inventory, subtract reserved inventory, subtract in-transit POs → net requirements. For each material with net requirement > 0: create draft Purchase Request with order date = need date - lead time. If order date ≤ today: flag URGENT. Also create draft Work Orders for SO lines (in planned state). Return MRP plan summary.

### Task 53: MRP II capacity planning
Service `CapacityPlanningService::schedule(work_orders)` — for each work order: calculate production time = (qty ÷ mold output_rate_per_hour) + setup_time. Find available machine-mold combination (mold compatible with machine, machine not already running, mold not at shot limit). Check labor: operators needed for the shift (based on machine operators_required ratio). Generate production_schedules entries. PPC Head reviews Gantt chart, can drag to reorder priority, change machine assignment, confirm.

### Task 54: Production Gantt chart
`pages/production/schedule.tsx` — Gantt chart showing machines on Y axis, time on X axis, work orders as bars. Color by status (indigo running, emerald completed, amber queued, red at risk). Drag to reorder. Click bar to open work order detail. Use `frappe-gantt` library. Dense, SAP-style.

### Task 55: Production output recording (WebSocket)
Service `WorkOrderOutputService::record(wo, {good, reject, defects})` — creates output entry, updates wo quantity_produced/good/rejected, increments mold shot_count. Broadcasts WebSocket event to `production.wo.{id}` channel. `pages/production/work-orders/[id]/record-output.tsx` — supervisor form (good count, reject count, defect counts per type). On submit: record + show live cumulative update. Dashboard subscribes to channel, updates in real-time.

### Task 56: Machine breakdown handling
When machine status → breakdown: pause all work orders on that machine, create machine_downtime entry, notify Maintenance Head (WebSocket), query compatible alternative machines not currently in use, send suggestion to PPC Head. PPC Head confirms reschedule or waits. When machine status → running: resume work orders, close machine_downtime entry.

### Task 57: OEE calculation
Service `OeeService::calculate(machine, period)` → {availability, performance, quality, oee}. Availability = (planned_time - downtime) / planned_time. Performance = (good_count + reject_count) * ideal_cycle_time / run_time. Quality = good_count / (good_count + reject_count). OEE = product of three. Runs nightly to update machine OEE metrics.

### Task 58: Production dashboard
`pages/production/dashboard.tsx` — KPI cards (Today Output, Active WOs, Machine Util, OEE). Panel: Active Orders by Chain Stage (StageBreakdown component). Panel: Machine Utilization table with OEE gauges. Panel: Alerts (breakdowns, mold limits, material shortages). Panel: QC Defect Pareto bar chart. Matches the mockup exactly.

---

## SPRINT 7 — QUALITY + DELIVERY (Tasks 59–68)

### Task 59: Inspection specs (per product)
Migrations `0078_create_inspection_specs_table.php`, `0079_create_inspection_spec_items_table.php`. Model: per product, list of parameters (dimensional/visual/functional) with nominal value, tolerance_min, tolerance_max, is_critical. Frontend: `pages/quality/inspection-specs/[product_id].tsx` — edit spec for a product.

### Task 60: Quality inspections — 3 stages
Migrations `0080_create_inspections_table.php`, `0081_create_inspection_measurements_table.php`. Enum InspectionStage (incoming, in_process, outgoing). Service `InspectionService::create(stage, entity, product, batch_quantity)` — for outgoing: calculates AQL 0.65 Level II sample size from batch_quantity. QC inspector records actual measurements → system auto-evaluates pass/fail per tolerance. Incoming: attached to GRN (blocks GRN acceptance until passed). In-process: attached to work order. Outgoing: attached to delivery (blocks shipment until passed).

### Task 61: Non-Conformance Report (NCR)
Migration `0082_create_non_conformance_reports_table.php`, `0083_create_ncr_actions_table.php`. NCR created when inspection fails OR customer complains. Fields: source, severity, defect description, affected quantity, disposition (scrap/rework/use_as_is/return_to_supplier), root cause, corrective action. On disposition=scrap for outgoing QC: auto-create replacement work order. On return_to_supplier: create return notification to Purchasing.

### Task 62: Certificate of Conformance
Service `CoCService::generate(delivery)` → PDF with Ogami letterhead, batch info, inspection data, quantity, "This certifies all parts conform to specifications" statement, QC manager signature line. Auto-generated when delivery is created from a batch that passed outgoing QC. Attached to delivery record.

### Task 63: Defect Pareto analytics
Dashboard panel showing top defects by frequency over selected period. Bar chart with indigo bars, counts on the right. Clickable to drill down to specific inspection records.

### Task 64: Quality — Frontend
`pages/quality/dashboard.tsx` (pass rate, open NCRs, defect Pareto), `pages/quality/inspections/index.tsx` (list filterable by stage), `pages/quality/inspections/[id].tsx` (detail with measurements table), `pages/quality/inspections/create.tsx` (measurement recording form, auto pass/fail from tolerances), `pages/quality/ncr/index.tsx` + detail + create. Use chain visualization: inspections appear in the ChainHeader of the related SO/PO.

### Task 65: Supply Chain — shipments + import docs
Migrations `0084_create_shipments_table.php`, `0085_create_shipment_documents_table.php`. For imported POs: track 9 document types (proforma invoice, commercial invoice, packing list, B/L, import entry, certificate of origin, MSDS, BOC release). Status: ordered → shipped → in_transit → customs → cleared → received. ImpEx Officer updates status and uploads documents. Shipment links to PO.

### Task 66: Fleet + Deliveries
Migrations `0086_create_vehicles_table.php`, `0087_create_deliveries_table.php`, `0088_create_delivery_items_table.php`. Seed 3 vehicles (Truck 1, Truck 2, L300 Van). Service `DeliveryService::create(sales_order_items)` — only allows items that passed outgoing QC, generates delivery_note_number. Status lifecycle: scheduled → loading → in_transit → delivered (driver uploads receipt photo) → confirmed (CRM officer). On confirmation: auto-create draft invoice.

### Task 67: Delivery — Frontend
`pages/supply-chain/shipments/index.tsx` (import tracking), `pages/supply-chain/deliveries/index.tsx` (outbound tracking), `pages/supply-chain/deliveries/[id].tsx` (detail with status progression, upload receipt photo button). `pages/supply-chain/fleet.tsx` (vehicle list + assignments).

### Task 68: Customer complaints + 8D report
Migrations `0089_create_customer_complaints_table.php`, `0090_create_complaint_8d_reports_table.php`. 8D structured form (D1 team, D2 problem, D3 containment, D4 root cause, D5 corrective action, D6 verification, D7 prevention, D8 recognition). On complaint → NCR auto-created. On disposition: replacement work order + credit memo if needed. PDF template for 8D report. Frontend: `pages/crm/complaints/index.tsx`, `[id].tsx` (tabbed: overview, 8D report editor, linked records).

---

## SPRINT 8 — POLISH + DSS + DEFENSE (Tasks 69–85)

### Task 69: Maintenance module
Migrations `0091_create_maintenance_schedules_table.php`, `0092_create_maintenance_work_orders_table.php`, `0093_create_maintenance_logs_table.php`, `0094_create_spare_part_usage_table.php`. Preventive (scheduled by hours/days/shots). Corrective (breakdown-triggered). Maintenance Head assigns technician. Spare parts consumed → deduct from inventory (spare_part item type). Mold maintenance: shot count trigger → auto-create work order → after maintenance, reset shot count. Frontend: maintenance calendar, work order list, logs.

### Task 70: Assets module
Migrations `0095_create_assets_table.php`, `0096_create_asset_depreciations_table.php`. Service: monthly straight-line depreciation job. Auto-post JE monthly. Link molds and machines to assets. Frontend: asset list with QR code generation for tagging.

### Task 71: Employee separation + clearance
Service `SeparationService::initiate(employee, date, reason)` → creates clearance record. Clearance has items for each department: Production (tools returned), Warehouse (materials returned), Maintenance (no outstanding work), Finance (no outstanding cash advances), HR (ID returned, 201 complete), IT (equipment returned). Each department signs off. On all cleared: compute final pay (last salary pro-rated by working days + unused convertible leave conversion + pro-rated 13th month − outstanding loan balance − unreturned property value). Generate clearance PDF with all signatures.

### Task 72: Plant Manager dashboard
`pages/dashboard/plant-manager.tsx` — matches the mockup exactly. KPI cards (Revenue Week, Production Output, OEE, OTD). StageBreakdown panel for active orders. Alerts panel. Machine Utilization table with status chips. QC Defect Pareto. All widgets live, WebSocket for real-time.

### Task 73: Role-based dashboards
`pages/dashboard/hr.tsx`, `pages/dashboard/ppc.tsx`, `pages/dashboard/accounting.tsx`, `pages/dashboard/employee.tsx` (self-service). Each role sees relevant KPIs and panels. Same design language across all.

### Task 74: Employee self-service portal
`pages/self-service/index.tsx` — mobile-responsive. Shows: latest payslip summary, leave balances, attendance this month, pending requests. Bottom navigation (Home, DTR, Leave, Payslip, Me). Large tap targets. Employee sees only own data (enforced server-side).

### Task 75: Global search
Configure Meilisearch indexes for: employees, products, customers, vendors, purchase_orders, sales_orders, work_orders, invoices. Service `SearchService::search(query)` returns grouped results. API: GET /search?q=. Frontend: command palette (Cmd+K) shows recent items + search results + quick actions.

### Task 76: Printable approved forms
For every financially approved document (PO, Bill Payment, Loan, Cash Advance, Purchase Request): print template with all 4 approval signatures (Prepared/Noted/Checked/Reviewed/Approved by names + dates + signature lines). Ogami letterhead. Bulk print multiple at once.

### Task 77: Notifications UI
`components/layout/NotificationBell.tsx` — dropdown showing recent notifications with unread count. `pages/notifications/index.tsx` — full list with filters. `pages/self-service/notification-preferences.tsx` — user toggles in-app/email per notification type.

### Task 78: WebSocket broadcasting
Configure Laravel Reverb. Broadcast events: WorkOrderOutputUpdated (channel: `production.wo.{id}`), MachineStatusChanged (channel: `machines.status`), PayrollProgress (channel: `payroll.period.{id}`). Frontend uses Laravel Echo client to subscribe on relevant pages.

### Task 79: Audit log viewer
`pages/admin/audit-logs.tsx` — dense table with filters (user, model type, action, date range). Click row to see old/new JSON diff. Admin only.

### Task 80: Comprehensive demo data seeder
`DemoDataSeeder` — creates 50 employees (mix monthly + daily-rated), 2 months of attendance, 20 leaves, 8 loans, 4 payroll periods, 10 vendors, 8 POs, 15 bills, 5 customers, 15 price agreements, 6 sales orders, 8 work orders, 18 inspections, 3 NCRs, 10 maintenance schedules, 18 assets, 4 quotations, 2 complaints with 8D, 60 notifications. Data should look realistic for screenshots and defense demo.

### Task 81: CI/CD (GitHub Actions)
`.github/workflows/ci.yml` — on push: run PHPUnit + Vitest. On main push: build Docker images. Optional: auto-deploy to VPS via SSH.

### Task 82: Performance optimization
Add Redis caching to dashboard endpoints (30–60s TTL). Add DB indexes on frequently filtered columns. Eager load relationships in list endpoints (prevent N+1). Enable PHP OPcache. Measure with Laravel Telescope (dev).

### Task 83: Cross-browser + device testing
Test on Chrome, Firefox, Safari, Edge, Samsung Internet. Test on budget Android phone (self-service portal). Fix any layout issues at 1280px, 1440px, 1920px widths. Test dark mode on all pages.

### Task 84: PDF user manual + thesis documentation
Write manual with screenshots of every major screen. Cover: login, each module, self-service, common workflows (create sales order, process payroll, generate PO, record production output, log inspection). Export as PDF. This is the thesis appendix.

### Task 85: Defense preparation
Create database backup (pg_dump). Record screen demo videos of the 3 chains end-to-end (order-to-cash, procure-to-pay, hire-to-retire). Practice demo 5+ times. Prepare answers for likely panel questions. Deploy to VPS. Test live demo on projector. Create defense presentation with architecture diagrams and screenshots.

---

## EXECUTION PROTOCOL

1. `claude "Execute task N from docs/TASKS.md"`
2. Review the generated code
3. Test manually (`make fresh && make seed` if migrations changed)
4. Commit: `git add . && git commit -m "feat: task N — description"`
5. Move to next task

**If stuck:** `claude "Task N failed with this error: [paste]. Reference CLAUDE.md and SCHEMA.md and fix."`

**Never skip tasks.** They have dependencies. The foundation sprint especially — tasks 1–12 unlock everything else.

**Commit after every task.** Small commits are easier to debug and revert.
