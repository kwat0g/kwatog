# Ogami ERP — Full System Audit Report

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete system discovery, module-by-module audit, gap analysis, and enhancement strategy for the Ogami ERP platform

**Architecture:** Laravel 11 modular monolith (23 modules) + React 18 SPA + PostgreSQL 16 + Redis 7 + Meilisearch + Laravel Reverb (WebSocket)

**Tech Stack:** PHP 8.3, TypeScript 5.6, React 18, Vite 5, TanStack Query/Table, Zustand, Zod, Recharts, Playwright, Vitest, Docker, GitHub Actions

---

## 1. System Discovery Map

```
OGAMI ERP
├── CORE INFRASTRUCTURE
│   ├── Authentication & Session Management (Sanctum SPA cookies)
│   ├── Role-Based Access Control (280+ permissions, 12 roles)
│   ├── Approval Workflow Engine (4-tier: Staff → Dept Head → Manager → Officer → VP)
│   ├── Document Sequence Generator (monthly reset, 10 entity types)
│   ├── Notification System (in-app + WebSocket push)
│   ├── Audit Logging (IP + user agent + before/after snapshots)
│   ├── Settings & Feature Toggles (Redis-cached, per-module on/off)
│   ├── Alert Engine (threshold-based, mold shots, stock, expiry)
│   ├── Global Search (Meilisearch-powered)
│   └── Document Vault (file uploads, permission-gated downloads)
│
├── CHAIN 3: HIRE TO RETIRE
│   ├── HR Module
│   │   ├── Departments (tree structure, head assignment)
│   │   ├── Positions (salary grade, headcount tracking)
│   │   ├── Employees (CRUD, docs, property, employment history)
│   │   ├── Employee Directory + Org Chart
│   │   ├── Onboarding Workflow (document checklist stepper)
│   │   ├── Separation & Clearance (multi-department sign-off)
│   │   ├── Profile Update Requests (employee-initiated, HR/finance review)
│   │   └── User Provisioning (bulk account creation from employee records)
│   ├── Attendance Module
│   │   ├── Shift Management (create, bulk assign)
│   │   ├── Holiday Calendar (regular + special non-working)
│   │   ├── DTR Computation Engine (biometric CSV import → computed hours)
│   │   ├── Overtime Requests (min 30m, max 4h, approval workflow)
│   │   └── Attendance Records (CRUD, manual correction)
│   ├── Leave Module
│   │   ├── Leave Types (configurable: VL, SL, EL, etc.)
│   │   ├── Leave Balances (annual allocation, carry-over rules)
│   │   └── Leave Requests (dept + HR approval, conflict detection)
│   ├── Loans Module
│   │   ├── Employee Loans (zero interest, max 1mo salary)
│   │   ├── Cash Advances (separate limit, concurrent with loan)
│   │   ├── Amortization Preview (before approval)
│   │   └── Auto-Deduction (payroll integration)
│   ├── Payroll Module
│   │   ├── Government Contribution Tables (SSS, PhilHealth, Pag-IBIG, BIR)
│   │   ├── Payroll Periods (semi-monthly, auto-creation)
│   │   ├── Payroll Calculator (daily-rated + monthly salaried)
│   │   ├── Deduction Details (itemized per employee)
│   │   ├── Payroll Adjustments (next-period corrections)
│   │   ├── Payroll Anomaly Detection (flags unusual computations)
│   │   ├── 13th Month Pay (accrual-based)
│   │   ├── Bank File Generation (payroll disbursement)
│   │   ├── Disbursement Proofs (receipt upload after bank release)
│   │   ├── Payslip PDF Generation
│   │   └── Payroll → GL Auto-Posting (journal entry per period)
│   └── Self-Service Portal
│       ├── My Profile (view/edit with approval)
│       ├── My DTR (view attendance records)
│       ├── My Leaves (apply, view balance)
│       ├── My Overtime (request, view status)
│       ├── My Loans (view balance, payments)
│       ├── My Documents (upload/download personal docs)
│       ├── My Payslips (view/download)
│       └── Notification Preferences
│
├── CHAIN 2: PROCURE TO PAY
│   ├── Inventory Module
│   │   ├── Item Categories (tree/hierarchical)
│   │   ├── Item Master (materials, consumables, finished goods)
│   │   ├── Warehouse Structure (warehouse → zone → location)
│   │   ├── Stock Levels (per-item per-location)
│   │   ├── Stock Movements (receive, issue, transfer, adjust)
│   │   ├── Stock Card (full movement history per item)
│   │   ├── Stock Adjustments (with reason tracking)
│   │   ├── Stock Transfers (inter-warehouse)
│   │   ├── Transfer Orders (formal multi-step transfers)
│   │   ├── Goods Receipt Notes (receiving with QC gate)
│   │   ├── Material Issue Slips (work order consumption)
│   │   ├── Material Reservations (soft-allocate for WO)
│   │   ├── Warehouse Map (visual location layout)
│   │   ├── Stock Count Sessions (cycle count/physical inventory)
│   │   ├── Auto-Replenishment (low stock → auto PR)
│   │   ├── Picking Lists (delivery preparation)
│   │   ├── Batch/Lot Traceability (ADV3)
│   │   └── Inventory Dashboard (KPIs, aging, turnover)
│   ├── Purchasing Module
│   │   ├── Purchase Requests (4-tier approval, bulk approve)
│   │   ├── PR Templates (repeatable orders)
│   │   ├── Purchase Orders (from PR conversion or direct)
│   │   ├── PO PDF Generation
│   │   ├── Approved Supplier List (per-item qualification)
│   │   ├── Three-Way Matching (PO vs GRN vs Bill)
│   │   ├── Supplier Performance Metrics (quality, delivery, cost)
│   │   ├── Auto-PO Generation (from MRP shortage)
│   │   └── Procurement Chain View (end-to-end visibility)
│   └── Supply Chain Module
│       ├── Shipments (import tracking, status lifecycle)
│       ├── Shipment Documents (BL, CI, PL upload/download)
│       ├── Shipment Lots (batch tracking per shipment)
│       ├── Fleet Management (vehicles CRUD)
│       ├── Deliveries (outbound, multi-item, status tracking)
│       ├── Delivery Items (line-level tracking)
│       ├── Proof of Delivery (multi-file photo upload)
│       └── Delivery Schedules (planned dates per vendor/customer)
│
├── CHAIN 1: ORDER TO CASH
│   ├── CRM Module
│   │   ├── Products (catalog with specs)
│   │   ├── Price Agreements (per-customer, date-range validity)
│   │   ├── Sales Orders (lifecycle: draft → confirmed → in-production → delivered → invoiced)
│   │   ├── Customer Complaints (tracking + resolution)
│   │   └── 8D Reports (root cause analysis per complaint)
│   ├── MRP Module
│   │   ├── Bill of Materials (multi-level, parent-child)
│   │   ├── BOM Items (material + quantity + scrap factor)
│   │   ├── Machines (status lifecycle, running hours, asset link)
│   │   ├── Molds (shot count, max life, compatibility matrix)
│   │   ├── Mold History (shot tracking, maintenance log)
│   │   ├── MRP Plans (material requirements explosion)
│   │   ├── MRP Runs (batch planning execution, netting)
│   │   └── Capacity Scheduler (machine/mold allocation, Gantt)
│   ├── Production Module
│   │   ├── Defect Types (catalog for tracking)
│   │   ├── Work Orders (full lifecycle: draft → confirmed → in-progress → completed → closed)
│   │   ├── Work Order Materials (planned vs actual consumption)
│   │   ├── Work Order Outputs (good + reject quantity, WebSocket real-time)
│   │   ├── Work Order Defects (linked to defect types)
│   │   ├── Machine Downtimes (breakdown recording)
│   │   ├── Production Schedules (Gantt chart)
│   │   ├── OEE Calculation (availability × performance × quality)
│   │   └── Production Dashboard (live KPIs, machine status)
│   └── Quality Module (IATF 16949 — 4 touchpoints)
│       ├── Inspection Specs (per-product, dimensions + tolerances)
│       ├── Inspection Spec Items (individual measurement definitions)
│       ├── Inspections (incoming / in-process / outgoing — 3 stages)
│       ├── Inspection Measurements (actual vs spec per item)
│       ├── AQL Sample Size Calculator (Level II, 0.65 AQL)
│       ├── Non-Conformance Reports (auto or manual, disposition workflow)
│       ├── NCR Actions (corrective/preventive per NCR)
│       ├── NCR Templates (pre-fill common failures)
│       ├── Certificate of Conformance (auto-generated from inspection data)
│       ├── Defect Pareto Analytics (top defects by frequency/cost)
│       ├── Batch/Lot Traceability Search
│       └── Shipment Lot QC Integration
│
├── ACCOUNTING & FINANCE
│   ├── Accounting Module
│   │   ├── Chart of Accounts (hierarchical, account types)
│   │   ├── Journal Entries (manual + auto-posted from payroll/bills/invoices)
│   │   ├── Journal Entry Reversal
│   │   ├── Vendors (supplier master for AP)
│   │   ├── Bills (AP — from PO/GRN, payment tracking)
│   │   ├── Bill Payments (partial/full, linked to bill)
│   │   ├── Customers (AR master, shared with CRM)
│   │   ├── Invoices (AR — from SO/delivery)
│   │   ├── Invoice Items (line-level detail)
│   │   ├── Collections (payment received against invoice)
│   │   ├── Trial Balance (real-time)
│   │   ├── Income Statement (date-range filtered)
│   │   ├── Balance Sheet (as-of-date)
│   │   └── Finance Dashboard (AP/AR aging, cash position)
│   ├── Budgeting Sub-Module
│   │   ├── Fiscal Years (define budget periods)
│   │   ├── Budgets (per-department, per-fiscal-year)
│   │   ├── Budget Line Items (account-level allocation)
│   │   ├── Budget Revisions (mid-year adjustments with audit trail)
│   │   ├── Budget Transfers (between line items, approval required)
│   │   └── Budget vs Actual Report
│   └── Assets Module
│       ├── Asset Register (fixed assets, QR code tracking)
│       ├── Asset Depreciation (straight-line, declining balance)
│       ├── Asset Disposal (write-off workflow)
│       └── Asset ↔ Machine/Vehicle Links
│
├── MAINTENANCE & RELIABILITY
│   ├── Maintenance Schedules (preventive, time/shot-based triggers)
│   ├── Maintenance Work Orders (assign → start → complete lifecycle)
│   ├── Maintenance Logs (per work order history)
│   ├── Spare Part Usage (linked to inventory items)
│   ├── Machine Condition Readings (vibration, temp, pressure)
│   ├── Predictive Maintenance Scoring (condition-based alerts)
│   └── Downtime Analytics (MTBF, MTTR, Pareto)
│
├── FORECASTING & PLANNING
│   ├── Demand Forecasts (time-series projections)
│   └── Stock-Out Projections (days-of-supply analysis)
│
├── RETURN MANAGEMENT
│   ├── Return Requests (submit → approve → receive → inspect → complete)
│   └── Return Request Items (line-level tracking)
│
├── B2B PORTALS
│   ├── Supplier Portal
│   │   ├── Authentication (separate guard, portal user accounts)
│   │   ├── View POs assigned to them
│   │   ├── Upload shipping documents
│   │   ├── View invoices / statement of account
│   │   ├── View deliveries + delivery schedules
│   │   └── Dashboard (pending POs, overdue, recent activity)
│   └── Customer Portal
│       ├── Authentication (separate guard)
│       ├── View Sales Orders
│       ├── View Invoices / statement of account
│       ├── View Deliveries + tracking
│       ├── File Complaints
│       ├── Delivery Schedules
│       └── Dashboard (order status, recent deliveries)
│
├── DASHBOARDS (Role-Based)
│   ├── Plant Manager Dashboard (OEE, machine status, WO progress, quality alerts)
│   ├── HR Dashboard (headcount, attendance %, leave calendar, separations)
│   ├── PPC Dashboard (schedule adherence, capacity utilization, WO pipeline)
│   ├── Finance Dashboard (AP/AR aging, cash position, budget utilization)
│   ├── Purchasing Dashboard (PO status, supplier performance, pending approvals)
│   ├── Warehouse Dashboard (stock levels, aging, movement trends)
│   ├── Quality Dashboard (inspection pass rate, NCR trends, Pareto)
│   ├── Employee Dashboard (my tasks, my approvals, announcements)
│   ├── Forecasting Integration (demand + stock-out panels)
│   ├── Chain Bottleneck Widget (cross-chain visibility)
│   └── Configurable Widget Layouts (per-role defaults, user customization)
│
├── CROSS-CUTTING FEATURES
│   ├── Approval Board (Kanban view of all pending approvals)
│   ├── Calendar Aggregator (leaves, holidays, maintenance, deliveries)
│   ├── Chain Bottleneck Detection (stuck items across all 3 chains)
│   ├── Activity Feed (SO, PO, WO, NCR — timeline view)
│   ├── Bulk PDF Printing (batch generate + download)
│   ├── Column Selector for Exports (user picks columns, CSV/Excel)
│   ├── Scheduled Exports (cron-based recurring reports)
│   ├── Command Palette (⌘K quick navigation)
│   ├── Keyboard Shortcuts (⌘S save, ⌘⇧N new, etc.)
│   ├── Form Draft Auto-Save (localStorage recovery)
│   ├── Real-Time Updates (Laravel Reverb WebSocket)
│   └── Badge Counters (unread notifications, pending approvals)
│
└── DEVOPS & TESTING
    ├── Docker Compose (dev: 9 services)
    ├── Docker Prod Images (PHP-FPM + Nginx + Node)
    ├── GitHub Actions CI (API tests, SPA lint + tests, deploy)
    ├── PHPUnit Feature Tests (45+ tests across all modules)
    ├── PHPUnit Unit Tests (12 tests — core services)
    ├── Vitest Component/Unit Tests (9 test files)
    ├── Playwright E2E Tests (6 specs — dashboard, payroll, sales)
    ├── k6 Load Tests (concurrent inventory, concurrent payroll)
    └── Makefile (25+ targets for common operations)
```

---

## 2. Module-by-Module Deep Dive

---

### 2.1 Authentication Module

**Current State & Process Flow:**
Sanctum SPA mode with HTTP-only cookies. Flow: GET /sanctum/csrf-cookie → POST /login → session cookie set → all requests carry cookie. Password policy: min 8 chars + uppercase + number + special. Account lockout after 5 failures (15 min). Password expiry at 90 days. History check prevents reusing last 3 passwords. Login history tracked with IP + user agent.

**Identified Gaps & Broken Logic:**
- No multi-factor authentication (MFA/2FA) — critical for ERP with financial data
- No "remember me" functionality documented (session timeout is 15–30 min hard)
- No session invalidation UI (user can't see/kill active sessions on other devices)
- No IP whitelist capability for admin accounts
- Password reset flow not implemented (no forgot-password endpoint found)

**Missing Essential Features:**
- Two-factor authentication (TOTP or SMS) — standard for enterprise systems handling payroll/financials
- Forgot password / password reset email flow
- Active session management (view + revoke sessions)
- Login anomaly detection (new device/location alerts)
- API key management for integration scenarios (future B2B webhooks)

**Enhancement & Modernization Strategy:**
1. Add TOTP-based 2FA with backup codes (critical for thesis defense — shows security depth)
2. Implement password reset via email token (Laravel's built-in `Password::sendResetLink`)
3. Add `/auth/sessions` endpoint showing active sessions with "revoke all other sessions" action
4. For thesis scope: IP whitelist is out-of-scope but mention as future work

---

### 2.2 Admin / RBAC Module

**Current State & Process Flow:**
280+ granular permissions across all modules. 12 predefined roles (system roles can't be deleted). Permission matrix UI with bulk editing. User permission overrides (grant/deny per-user beyond role). Role cloning. Role comparison view. Audit log viewer with CSV export. Settings management (feature toggles, company info). Global search powered by Meilisearch.

**Identified Gaps & Broken Logic:**
- No role hierarchy/inheritance (each role is flat — permissions must be manually duplicated across similar roles)
- No permission groups/categories in the UI for easier bulk assignment
- No "effective permissions" view showing final computed permissions (role + overrides combined)
- Audit log has no retention policy or archival strategy
- No bulk user import (only individual creation or provision from employee)

**Missing Essential Features:**
- Role templates (e.g., "create new role from Department Head template")
- Time-limited permission grants (e.g., "give user X access to payroll for 1 week during audit")
- Delegation ("I'm on leave, delegate my approvals to person Y")
- Permission change audit (who changed what permission, when)

**Enhancement & Modernization Strategy:**
1. Add "effective permissions" endpoint: merge role permissions + user overrides, show in UI
2. Implement approval delegation (critical for approval workflows when approvers are absent)
3. Add permission change tracking in audit_logs (already have the infrastructure)
4. Time-limited grants are scope expansion — document as future work

---

### 2.3 HR Module

**Current State & Process Flow:**
Full employee lifecycle: hire → onboarding (document checklist) → active employment → separation (multi-dept clearance) → final pay computation. Department tree with head assignment. Positions with salary grades. Employment history tracking. Employee property (company assets assigned). Profile update requests with HR + finance review. User provisioning (create system account from employee record). Employee directory with org chart.

**Identified Gaps & Broken Logic:**
- No employee photo/avatar management (directory shows names only)
- Org chart likely static/flat — no drag-and-drop reorganization
- Clearance workflow doesn't appear to block final pay computation (no hard dependency check)
- No probationary period tracking (regularization date, evaluation due dates)
- No contract/appointment letter generation
- Employment history is manual — no auto-tracking of position/department changes

**Missing Essential Features:**
- Probationary tracking (90/180 day alerts, regularization workflow)
- Employee performance evaluation (even basic — tied to regularization)
- Document expiration alerts (medical certs, NBI clearance renewal)
- Headcount planning (approved positions vs filled vs vacant)
- Automatic employment history entries on position/department/salary changes

**Enhancement & Modernization Strategy:**
1. Add `regularization_date` to employees table, create alert when approaching
2. Auto-insert employment_history record whenever employee's position/department/salary changes (model observer)
3. Document expiry: add `expires_at` to employee_documents, feed into alert engine
4. Headcount: positions table already has structure — add `approved_headcount` column, compute vacancy

---

### 2.4 Attendance Module

**Current State & Process Flow:**
Shift-based attendance. Biometric CSV import → DTR computation engine calculates: regular hours, overtime, night differential (10PM-6AM at 10%), undertime, tardiness, absent days. Extended shift (6AM-6PM) = auto-OT. Overtime requests with approval workflow (min 30m, max 4h). Holiday calendar (regular + special non-working) affects pay computation.

**Identified Gaps & Broken Logic:**
- No real-time attendance dashboard (only batch import from biometric)
- No geofencing or alternative clock-in methods (only biometric CSV)
- Overtime auto-detection from extended shift may conflict with explicit OT requests
- No "flexible time" or compressed work week support
- Holiday falling on rest day computation rules unclear in implementation

**Missing Essential Features:**
- Real-time attendance status board (who's in, who's late, who's absent — today)
- Tardiness/absence pattern detection (alerts for chronic tardiness)
- Work-from-home tracking (relevant post-COVID)
- Shift swap requests between employees
- Official business / official time tracking

**Enhancement & Modernization Strategy:**
1. Real-time dashboard is high-value for Plant Manager — aggregate today's attendance into dashboard widget
2. Add `attendance_type` enum: regular, wfh, official_business, official_time
3. Pattern detection: simple SQL query (>3 tardiness in a month → alert) — hook into alert engine
4. Shift swap: out of thesis scope but mention as future enhancement

---

### 2.5 Leave Module

**Current State & Process Flow:**
Configurable leave types (VL, SL, EL, maternity, paternity, etc.). Annual balance allocation. Leave requests with department + HR two-level approval. Conflict detection (overlapping requests). Balance deduction on approval. Integration with payroll (leave without pay deduction).

**Identified Gaps & Broken Logic:**
- No carry-over policy enforcement (max carry-over days, expiry of carried balance)
- No leave accrual rules (e.g., 1.25 days/month instead of lump-sum annual)
- No half-day leave support visible in schema
- No leave calendar visualization (who's out this week/month)
- No automatic balance reset on anniversary/year start

**Missing Essential Features:**
- Leave accrual engine (monthly/bi-monthly credit)
- Leave calendar (team view — critical for managers planning coverage)
- Carry-over rules (max days, expiry date)
- Compensatory time-off (earned from worked holidays/rest days → convertible to leave)
- Leave encashment (convert unused VL to cash at year-end)

**Enhancement & Modernization Strategy:**
1. Leave calendar already partially addressed via Calendar Aggregator — ensure leave requests appear there
2. Add `accrual_rate` and `max_carry_over` to leave_types table
3. Create monthly cron job to credit accrual (Laravel scheduler)
4. Half-day: add `duration` enum (full_day, first_half, second_half) to leave_requests
5. Compensatory off and encashment: document as thesis enhancement possibility

---

### 2.6 Loans Module

**Current State & Process Flow:**
Employee loans (zero interest, max 1 month salary) and cash advances (separate category). One loan + one cash advance concurrent. Amortization preview before approval. Auto-deduction via payroll integration. Approval workflow with limit checks.

**Identified Gaps & Broken Logic:**
- No loan balance dashboard for HR/Finance (aggregate outstanding)
- No early termination or full settlement workflow
- No deduction holiday (pause deduction for a period, e.g., during maternity leave)
- Loan limit validation may not account for existing outstanding balance correctly

**Missing Essential Features:**
- Outstanding loans report (aggregate by department, aging)
- Loan restructuring (extend term, adjust amount)
- Deduction suspension (during extended leave)
- Loan clearance check integration (separation: must clear loans before final pay)

**Enhancement & Modernization Strategy:**
1. Ensure separation/clearance workflow checks loan balance (block clearance if outstanding > 0)
2. Add "deduction_suspended_until" to employee_loans for leave-period suspension
3. Aggregate loan report: SQL group by department + status for finance dashboard
4. Early settlement: add `settled_at` and `settlement_type` (regular/early/final_pay)

---

### 2.7 Payroll Module

**Current State & Process Flow:**
Semi-monthly payroll. Pipeline: create period → compute (batch calculator) → review anomalies → approve → finalize → generate bank file → upload disbursement proof. Supports both monthly-salaried and daily-rated. Government deductions (SSS, PhilHealth, Pag-IBIG, BIR) on first period only. Auto-creates journal entries on finalization. 13th month pay accrual-based. Payroll adjustments for corrections (never unlock finalized — adjust in next period). Anomaly detection flags unusual computations.

**Identified Gaps & Broken Logic:**
- No payroll comparison (period-over-period variance report)
- No de minimis benefits computation (meal allowance, clothing, etc.)
- No government report generation (BIR 2316, SSS R-3, PhilHealth RF-1)
- No year-end tax annualization (BIR requirement)
- No payroll calendar (planned computation/release dates)
- Anomaly thresholds may be hardcoded rather than configurable

**Missing Essential Features:**
- Government compliance reports (BIR 2316 annual, monthly remittance reports)
- Year-end tax annualization (required by Philippine BIR)
- Payroll comparison/variance report (this period vs last period)
- De minimis benefits tracking (tax-exempt allowances up to statutory limits)
- Payroll cost distribution report (by department, cost center)

**Enhancement & Modernization Strategy:**
1. **Critical for thesis**: Add BIR alphalist (2316) generation — demonstrates Philippine compliance knowledge
2. Year-end annualization: compute total taxable income, compare with withheld tax, output refund/balance
3. Government remittance reports: generate CSV in SSS/PhilHealth/Pag-IBIG submission format
4. Variance report: compare two payroll periods, highlight changes > threshold
5. Configure anomaly thresholds via settings table (already have settings infrastructure)

---

### 2.8 Accounting Module

**Current State & Process Flow:**
Chart of Accounts (hierarchical, typed: asset/liability/equity/income/expense). Manual journal entries with reversal support. Auto-posted JEs from payroll finalization, bill approval, invoice creation. Vendors (AP): bills → partial/full payments. Customers (AR): invoices → collections. Three financial statements: trial balance, income statement, balance sheet — all real-time computed. Finance dashboard with AP/AR aging.

**Identified Gaps & Broken Logic:**
- No account locking by period (can post to closed months)
- No recurring journal entries (monthly accruals like rent, depreciation)
- No sub-ledger reconciliation (AP/AR sub-ledger vs GL control account)
- No VAT computation or tax handling on bills/invoices (mentioned in module list but not in schema)
- No multi-currency support (fine for thesis — PHP only per spec)
- No aging schedule PDF export

**Missing Essential Features:**
- Fiscal period locking (prevent posting to closed months) — **explicitly cut from scope** in CLAUDE.md
- Recurring journal entries (depreciation, rent, insurance accruals)
- VAT tracking on bills and invoices (12% output/input VAT — Philippines standard)
- Aging schedule reports (AR + AP, 30/60/90/120+ day buckets)
- Bank reconciliation — **explicitly cut from scope**

**Enhancement & Modernization Strategy:**
1. Recurring JEs: create `recurring_journal_entries` with template + frequency, Laravel scheduler generates monthly
2. VAT: add `vat_amount` and `vat_inclusive` fields to bill_items and invoice_items (12% standard)
3. Aging report: already have bills/invoices with dates — compute aging buckets in service, add PDF export
4. Period locking: was explicitly cut — do NOT implement unless user changes scope
5. Respect the "NOT BUILDING" section — bank reconciliation, cost accounting, cash flow stay out

---

### 2.9 Budgeting Sub-Module

**Current State & Process Flow:**
Fiscal years define budget periods. Budgets created per department per fiscal year. Line items map to accounts (from COA). Budget revisions track mid-year changes with audit trail. Budget transfers between line items require approval. Budget vs Actual report compares allocation vs journal entries.

**Identified Gaps & Broken Logic:**
- No budget enforcement at transaction time (PO/Bill creation doesn't check remaining budget)
- BudgetEnforcementService exists but integration with Purchasing/AP unclear
- No budget utilization alerts (80% consumed → warning)
- No multi-year budget comparison
- No budget template (copy last year's budget as starting point)

**Missing Essential Features:**
- Real-time budget enforcement (block PO if would exceed department budget) — BudgetEnforcementService exists, needs wiring
- Budget utilization alerts (threshold-based, feed into alert engine)
- Copy budget from previous fiscal year (template action)
- Budget variance report by account/department with drill-down

**Enhancement & Modernization Strategy:**
1. Wire BudgetEnforcementService into PurchaseOrderService and BillService (pre-check on create)
2. Add alert rule: when budget line item > 80% utilized, fire alert to department head + finance
3. "Copy from previous year" action on budget create form — clone line items with editable amounts
4. Budget variance drill-down: click a line item → see underlying journal entries

---

### 2.10 Inventory Module

**Current State & Process Flow:**
Comprehensive WMS: items with categories (tree), warehouse → zone → location hierarchy. Stock levels per-item per-location. Stock movements for all operations. Weighted average cost recalculated on receipt. GRN with QC gate (accept/reject at receiving). Material Issue Slips (consumption for work orders). Auto-replenishment (low stock triggers auto-PR). Stock count sessions (cycle count + physical inventory). Transfer orders for inter-warehouse moves. Warehouse map visualization. Batch/lot traceability. Picking lists for delivery prep.

**Identified Gaps & Broken Logic:**
- No FIFO/LIFO valuation option (only weighted average — fine for this use case)
- No bin-level capacity tracking (warehouse map is visual but doesn't enforce capacity)
- No inventory aging report (how long has stock been sitting)
- No ABC analysis (classify items by value/movement frequency)
- Auto-replenishment thresholds may not be easily configurable per-item
- No dead stock identification

**Missing Essential Features:**
- Inventory aging report (days since last movement per item)
- ABC/XYZ analysis (Pareto classification of items by value + variability)
- Safety stock calculation (based on lead time + demand variability)
- Item cost history (track cost changes over time for analysis)
- Inventory valuation report (total stock value by category/warehouse)

**Enhancement & Modernization Strategy:**
1. Aging report: query stock_movements for last movement date, compute days_since_last_move
2. ABC analysis: compute annual consumption value, classify into A (80% value) / B (15%) / C (5%)
3. Safety stock: (average daily usage × max lead time days) — store on item as `safety_stock_qty`
4. Inventory valuation: group stock_levels × items.unit_cost by category/warehouse → dashboard widget
5. These are DSS (decision support) enhancements — excellent thesis material

---

### 2.11 Purchasing Module

**Current State & Process Flow:**
Purchase Requests with 4-tier approval workflow. PR templates for repeatable orders. PR → PO conversion (manual or auto from MRP). PO PDF generation and supplier send. Approved Supplier List (qualification per item). Three-way matching (PO qty/price vs GRN qty vs Bill amount — with tolerance and override). Supplier performance metrics (quality %, on-time delivery %, cost variance). Auto-PO from MRP shortage signals. Procurement chain view (end-to-end PR→PO→GRN→Bill→Payment visibility).

**Identified Gaps & Broken Logic:**
- No RFQ process — **explicitly cut from scope**
- No blanket/framework POs (long-term agreements with call-offs)
- No PO amendment workflow (change quantity/price after approval without full re-approval)
- Supplier performance doesn't integrate with approved supplier status (poor performance should trigger review)
- No purchase price variance report (standard cost vs actual purchase price)

**Missing Essential Features:**
- PO amendment/revision tracking (version history for changes post-approval)
- Supplier rating thresholds (auto-flag suppliers below performance threshold)
- Purchase price variance analysis (actual vs standard/budgeted)
- Blanket PO with release orders (for recurring raw material purchases)
- Vendor evaluation scorecard (periodic formal assessment)

**Enhancement & Modernization Strategy:**
1. PO amendment: add `revision` column to purchase_orders, create revision history table
2. Supplier auto-flag: when performance score < threshold, create alert for purchasing manager
3. Purchase price variance: compare PO item price vs item's standard_cost, report monthly
4. Blanket POs: scope expansion — document as future enhancement
5. These enhance the procure-to-pay chain visibility significantly for thesis

---

### 2.12 Supply Chain Module

**Current State & Process Flow:**
Shipments (inbound from overseas suppliers): track status, link documents (Bill of Lading, Commercial Invoice, Packing List). Shipment lots for batch tracking. Vehicles (fleet management CRUD). Deliveries (outbound to customers): create from SO, multi-item, status lifecycle, receipt confirmation. Proof of delivery (multi-file photo upload by driver/delivery team). Delivery schedules (planned delivery dates for planning).

**Identified Gaps & Broken Logic:**
- No delivery route planning/optimization
- No vehicle maintenance integration (fleet → maintenance schedule link)
- No freight cost tracking per shipment
- No customs clearance workflow (important for Japanese-sourced materials)
- Shipment ETA tracking appears manual (no carrier API integration)
- No delivery performance metrics (on-time delivery %)

**Missing Essential Features:**
- Freight/logistics cost tracking (per shipment, allocated to GRN cost)
- Import duty/tax calculation (or at minimum tracking fields)
- Delivery performance KPI (on-time %, complete %, damage %)
- Vehicle ↔ maintenance schedule integration (preventive maintenance for fleet)
- Carrier/forwarder management (separate from vendor — freight vendor concept)

**Enhancement & Modernization Strategy:**
1. Add `freight_cost`, `duty_amount`, `insurance_cost` to shipments table — flows into landed cost
2. Delivery KPI: compute from deliveries table (promised_date vs actual_date, full vs partial)
3. Vehicle-maintenance link: vehicles already have asset_id FK → maintenance schedules can target asset
4. Carrier integration: out of scope — but freight cost fields enable manual tracking
5. Landed cost: freight + duty + insurance ÷ GRN items → update weighted avg cost

---

### 2.13 CRM Module

**Current State & Process Flow:**
Products (catalog of sellable items with specs). Price agreements (per-customer, date-range validity, negotiated prices). Sales Orders (lifecycle: draft → confirmed → in-production → shipped → delivered → invoiced → collected). Customer complaints with 8D root cause analysis reports. Activity feed on SOs for chain visibility.

**Identified Gaps & Broken Logic:**
- No customer credit limit management (can over-sell without credit check)
- No sales forecast integration (demand forecasts exist separately but don't feed back to CRM)
- No quotation/proforma invoice stage before sales order
- No customer classification (A/B/C tiers by revenue)
- No sales pipeline/funnel visualization
- Price agreement expiry doesn't appear to auto-notify before lapse

**Missing Essential Features:**
- Customer credit limit (block SO if would exceed AR balance + pending SOs)
- Quotation stage (quote → approve → convert to SO)
- Customer tiering/classification (revenue-based segmentation)
- Price agreement expiry alerts (30-day advance warning)
- Sales performance report (by customer, by product, by period)

**Enhancement & Modernization Strategy:**
1. Credit limit: add `credit_limit` to customers, check (outstanding AR + open SOs) < limit on SO confirm
2. Price agreement alerts: hook into alert engine — 30 days before expiry, notify sales team
3. Customer tiering: compute annual revenue per customer, classify A/B/C, display on customer detail
4. Quotation: scope expansion — but worth mentioning in thesis as future work
5. Sales report: aggregate SO data by customer/product/month — add to finance dashboard

---

### 2.14 MRP Module

**Current State & Process Flow:**
Bill of Materials (multi-level with parent-child explosion). Machines with status lifecycle (available, running, maintenance, breakdown) and running hours tracking. Molds with shot count tracking, max life alerts at 80%, machine compatibility matrix. MRP plans (material requirements explosion based on SO demand). MRP runs (batch execution with netting against stock). Capacity scheduler (machine/mold allocation, Gantt visualization, confirm/reorder/reassign).

**Identified Gaps & Broken Logic:**
- No lead time consideration in MRP netting (when to order, not just what quantity)
- No phantom BOM support (sub-assemblies that don't stock)
- No yield/scrap factor application in MRP explosion (BOM has scrap factor but unclear if MRP uses it)
- Capacity scheduler may not account for setup time between different products
- No "what-if" scenario planning (run MRP with different assumptions)

**Missing Essential Features:**
- Lead time offsetting in MRP (planned order release = need date - lead time)
- Setup time consideration in scheduling (product changeover on injection molding machines)
- MRP exception messages (reschedule in, reschedule out, cancel recommendations)
- Rough-cut capacity planning (before detailed scheduling)
- BOM cost roll-up (total material cost explosion for quoting)

**Enhancement & Modernization Strategy:**
1. Lead time: items table likely has `lead_time_days` — ensure MRP engine offsets planned order release
2. Setup time: add `setup_time_minutes` to mold_machine_compatibility — scheduler subtracts from available capacity
3. BOM cost roll-up: explode BOM, multiply quantities × item unit costs, sum = product material cost
4. Exception messages: when MRP run detects order date < today, flag as "expedite" — shows planning intelligence
5. These are high-value for thesis (demonstrates MRP II competency)

---

### 2.15 Production Module

**Current State & Process Flow:**
Work Orders (full lifecycle: draft → confirmed → in-progress → paused → completed → closed/cancelled). Material planning per WO (planned vs actual). Output recording with WebSocket real-time updates (good qty + reject qty). Defect tracking by type. Machine downtime recording (links to maintenance). Production schedules (Gantt chart). OEE calculation (availability × performance × quality rate). Production dashboard (live KPIs, machine status grid).

**Identified Gaps & Broken Logic:**
- No work order costing (material cost + labor cost + overhead = WO cost)
- No labor tracking per work order (which operators, how many hours)
- No rework work orders (NCR → rework WO automatic generation)
- Production schedule vs actual comparison (schedule adherence %)
- No shift-level production reporting (output per shift)
- Cycle time not explicitly tracked (theoretical vs actual)

**Missing Essential Features:**
- WO costing (material consumption × cost + labor hours × rate + overhead allocation)
- Labor assignment/tracking (operators per WO per shift)
- Rework WO auto-generation from NCR (CLAUDE.md mentions this but implementation unclear)
- Schedule adherence KPI (planned output vs actual output)
- Cycle time tracking (theoretical vs actual per machine per product)
- Scrap rate tracking (reject qty / total output) — beyond just defect recording

**Enhancement & Modernization Strategy:**
1. WO costing: sum(material_issues × item.unit_cost) = material cost. Labor needs operator assignment first
2. Auto-rework: when NCR disposition = "rework", auto-create child WO linked to parent — check if already done
3. Schedule adherence: planned_qty (from production_schedules) vs actual_qty (from work_order_outputs)
4. Scrap rate: already computable from outputs (reject / (good + reject)) — add to OEE quality component
5. Labor tracking: scope expansion but high-value for thesis defense Q&A

---

### 2.16 Quality Module

**Current State & Process Flow:**
IATF 16949 compliance across 4 touchpoints: incoming (GRN), in-process (during production), outgoing (before delivery — AQL 0.65 Level II). Inspection specs per product (dimensions + tolerances). Measurement recording (actual vs spec with pass/fail). NCR creation (auto from failed inspection or manual). NCR workflow: disposition → corrective action → close. NCR templates for common failures. Certificate of Conformance auto-generated from inspection data. Defect Pareto analytics. Batch/lot traceability search.

**Identified Gaps & Broken Logic:**
- No SPC (Statistical Process Control) charts (Cp, Cpk, control charts)
- No calibration management (measuring instruments used for inspection)
- No inspection result trending (are we improving or degrading over time?)
- No corrective action effectiveness verification (was the fix effective after N batches?)
- No supplier quality incoming rejection rate tracking per supplier
- No customer return analysis (returns → link to quality data)

**Missing Essential Features:**
- SPC / Control Charts (X-bar R chart, Cp/Cpk calculation) — **strong thesis differentiator**
- Calibration management (instrument register, due dates, out-of-cal alerts)
- CAPA effectiveness verification (re-inspect after corrective action, confirm improvement)
- Supplier quality scorecard (rejection rate per supplier, integrated with supplier performance)
- Quality cost tracking (cost of poor quality: scrap + rework + returns + inspection)

**Enhancement & Modernization Strategy:**
1. **High thesis value**: Add Cp/Cpk calculation from inspection_measurements (existing data!) — display on inspection spec detail
2. Trending: plot measurement means over time per spec item — simple recharts line chart
3. Supplier quality: count rejected GRN items by vendor → feed into supplier performance score
4. CAPA verification: add `verified_at` and `verification_result` to ncr_actions
5. Calibration: scope expansion but mention in thesis recommendations

---

### 2.17 Maintenance Module

**Current State & Process Flow:**
Preventive maintenance schedules (time-based and shot-count-based triggers for injection molding machines). Maintenance work orders (assign → start → complete lifecycle). Work order logs (activity history). Spare part usage tracking (linked to inventory items — auto-deduct stock). Machine condition readings (vibration, temperature, pressure). Predictive maintenance scoring (condition-based alerts). Downtime analytics (MTBF, MTTR, Pareto by failure type).

**Identified Gaps & Broken Logic:**
- No maintenance budget tracking (cost per maintenance event)
- No warranty tracking on machines/molds
- Preventive maintenance compliance rate not tracked (scheduled vs completed on time)
- Condition-based thresholds may be hardcoded rather than configurable per machine
- No maintenance request workflow (operator reports issue → maintenance team responds)

**Missing Essential Features:**
- Maintenance cost tracking (spare parts used × cost + labor hours)
- PM compliance KPI (on-time PM completion rate)
- Maintenance request from production floor (operator-initiated)
- Warranty management (track warranty periods, claim history)
- MTBF/MTTR trend over time (are we improving reliability?)

**Enhancement & Modernization Strategy:**
1. Maintenance cost: sum(spare_part_usage × item.unit_cost) per work order — add to maintenance dashboard
2. PM compliance: count(completed_on_time) / count(scheduled) per month — critical KPI for IATF
3. Maintenance request: add `requested_by` and `request_type` (breakdown vs preventive vs request) to work orders
4. MTBF trending: already have downtime data — compute monthly averages, chart over time
5. Condition thresholds: add per-machine configurable thresholds in a `machine_alert_thresholds` table

---

### 2.18 Assets Module

**Current State & Process Flow:**
Fixed asset register with QR code generation. Depreciation calculation (straight-line and declining balance methods). Asset disposal workflow. Asset linked to machines (asset_id FK on machines) and vehicles (asset_id FK on vehicles).

**Identified Gaps & Broken Logic:**
- No asset transfer between departments (custody change tracking)
- No asset physical verification/audit workflow
- No asset insurance tracking
- No asset maintenance cost accumulation (total cost of ownership)
- QR code scanning workflow unclear (scan → what action?)
- Depreciation doesn't appear to auto-post journal entries

**Missing Essential Features:**
- Asset transfer/custody change log
- Asset physical verification (periodic audit checklist)
- Auto-JE for monthly depreciation (debit expense, credit accumulated depreciation)
- Total cost of ownership (purchase + maintenance + depreciation)
- Asset tag printing (QR label generation for physical tagging)

**Enhancement & Modernization Strategy:**
1. Auto-depreciation JE: monthly scheduler computes depreciation, creates journal entry per asset
2. Asset transfer: add `department_id` to assets + transfer_history table
3. TCO: sum(purchase_cost + maintenance_costs + depreciation_to_date) — display on asset detail
4. QR payload endpoint already exists — ensure it returns asset details for mobile scanning
5. Physical verification: add annual audit workflow — list assets per location, confirm/flag missing

---

### 2.19 Dashboard Module

**Current State & Process Flow:**
Role-based dashboards (8 specialized views: plant manager, HR, PPC, finance, purchasing, warehouse, quality, employee). Configurable widget layouts (per-role defaults + user customization). Badge counters (pending approvals, unread notifications). Chain bottleneck widget (stuck items across chains). Real-time updates via WebSocket. Demand forecast and stock-out projection panels.

**Identified Gaps & Broken Logic:**
- No drill-down from dashboard widgets to detail pages (or unclear if implemented)
- No historical trend comparison (this month vs last month, this year vs last year)
- No configurable alert thresholds per widget
- No dashboard sharing (manager can't share their view with a colleague)
- No mobile-responsive dashboard layout

**Missing Essential Features:**
- Widget drill-down (click KPI → navigate to filtered list)
- Period-over-period comparison (current vs previous period automatically)
- Dashboard export to PDF (for management reporting)
- Mobile-optimized widget layout (critical for factory floor tablets)
- Custom date range for all dashboard queries

**Enhancement & Modernization Strategy:**
1. Drill-down: each StatCard/widget should link to the relevant list page with pre-applied filters
2. Period comparison: add `previous_value` to each KPI computation, show delta/trend
3. PDF export: leverage existing PDF infrastructure (DomPDF) for dashboard snapshot
4. Mobile: ensure responsive breakpoints in widget grid (already using configurable layouts)
5. Date range: add global date picker to dashboard header, pass to all widget queries

---

### 2.20 B2B Portal Module

**Current State & Process Flow:**
Two separate portals with isolated authentication (supplier_portal_users, customer_portal_users tables). Supplier portal: view assigned POs, upload shipping documents, view invoices/statement of account, delivery schedules. Customer portal: view sales orders, view invoices/statement, track deliveries, file complaints, delivery schedules.

**Identified Gaps & Broken Logic:**
- No Service layer (controllers may contain business logic directly)
- No Form Requests (no input validation on portal endpoints)
- Portal user registration/invitation workflow unclear (how do suppliers/customers get accounts?)
- No portal activity logging (who accessed what, when)
- No notification system for portal users (email when new PO, new delivery, etc.)
- No portal user management UI for internal admins

**Missing Essential Features:**
- Portal user invitation workflow (internal user sends invite → supplier/customer registers)
- Email notifications for portal events (new PO assigned, delivery shipped, invoice issued)
- Portal activity audit log
- Internal admin UI to manage portal users (activate/deactivate/reset password)
- Document collaboration (supplier can attach response/confirmation to PO)

**Enhancement & Modernization Strategy:**
1. Add Services for supplier/customer portal logic (extract from controllers)
2. Add Form Requests for portal input validation (security requirement)
3. Portal user invitation: create `/admin/portal-users` CRUD with invite action
4. Email notifications: queue-based (Laravel jobs) — on PO creation, notify assigned supplier
5. Audit: reuse existing HasAuditLog trait on portal actions

---

### 2.21 Forecasting Module

**Current State & Process Flow:**
Demand forecasts (time-series projections for products). Stock-out projections (days-of-supply analysis per item based on consumption rate vs current stock). Dashboard integration (forecast panels on relevant dashboards).

**Identified Gaps & Broken Logic:**
- Forecasting algorithm unclear (simple moving average? exponential smoothing? or manual entry?)
- No forecast accuracy measurement (MAPE — did forecast match actual demand?)
- No seasonality handling (Philippine holidays, automotive production cycles)
- No connection between demand forecast and MRP planning (forecast should feed MRP)
- No collaborative forecasting (sales team input + statistical model)

**Missing Essential Features:**
- Forecast accuracy tracking (compare forecast vs actual orders received)
- Forecast-to-MRP feed (demand forecast triggers MRP run for planned production)
- Multiple forecasting methods (simple, weighted, exponential smoothing)
- Forecast override/adjustment (planner adjusts statistical forecast based on market knowledge)
- Revenue forecasting (demand × price agreement — for finance planning)

**Enhancement & Modernization Strategy:**
1. Forecast accuracy: when SO arrives, compare with forecast for that period — compute MAPE
2. Forecast → MRP: on forecast approval, auto-create or update MRP plan for forecasted quantities
3. Method selection: let user choose method per product (settings) — default to weighted moving average
4. Override: add `adjusted_quantity` + `adjustment_reason` to demand_forecasts
5. This module is DSS — enhancements strengthen thesis chapter on decision support

---

### 2.22 Return Management Module

**Current State & Process Flow:**
Return requests with full lifecycle: submit → approve → receive → inspect → complete/reject/cancel. Line-item level tracking (return_request_items). Linked to quality inspection (returned items get incoming QC).

**Identified Gaps & Broken Logic:**
- No Form Requests (no input validation)
- No credit note generation (return approved → auto-create credit note in AR)
- No return reason categorization (quality defect, wrong item, excess, etc.)
- No cost impact tracking (return cost: shipping + inspection + restock)
- Return → inventory restock workflow unclear

**Missing Essential Features:**
- Return reason codes (categorize for analysis)
- Auto credit note / AR adjustment on return completion
- Return impact on customer quality metrics
- Restocking workflow (inspected OK → add back to stock with movement record)
- Return analytics (return rate by customer, by product, by reason)

**Enhancement & Modernization Strategy:**
1. Return reasons: add `reason_code` enum to return_request_items
2. Credit note: on return complete, auto-create negative invoice or AR credit
3. Restock: on disposition "restock", create stock_movement (type: return_restock)
4. Analytics: aggregate returns by customer/product/reason — add to CRM dashboard
5. Customer return rate feeds into customer tiering logic

---

### 2.23 Maintenance Cross-Reference: Common Module

**Current State & Process Flow:**
Cross-cutting services: approval workflow engine (4-tier escalation), document sequences, notifications (in-app + WebSocket), audit logging, alert engine (threshold-based), global search, document vault, export engine (column selection + scheduled exports), calendar aggregator, chain bottleneck detection, activity feed, bulk PDF printing.

**Identified Gaps & Broken Logic:**
- Approval escalation timeout unclear (if approver doesn't act in X days, escalate?)
- Alert dismissal doesn't prevent re-firing (need "snoozed until" or "acknowledged" state)
- Scheduled exports may not handle large datasets gracefully (no streaming/pagination)
- Document vault doesn't appear to have version control (upload replaces, no history)
- Global search indexing strategy unclear (which models are indexed, freshness)

**Missing Essential Features:**
- Approval escalation with timeout (auto-escalate after N days of inaction)
- Alert snooze ("remind me in 1 hour / tomorrow / next week")
- Document versioning (upload new version, keep history, compare)
- Bulk operations on notifications (mark all read, bulk dismiss)
- Export progress indication (long-running exports need progress feedback)

**Enhancement & Modernization Strategy:**
1. Escalation timeout: approval_records already has escalation columns (migration 0115) — implement scheduler job
2. Alert snooze: add `snoozed_until` to alerts, exclude from unread count until time passes
3. Document versioning: add `version` column + parent_document_id to documents table
4. Bulk notification actions: add batch endpoint for mark-read/dismiss
5. Export progress: use WebSocket to push progress percentage during generation

---

## 3. Cross-Module Synergies

### 3.1 Already Implemented (Verify Working)

| Integration | Source → Target | Mechanism |
|---|---|---|
| Payroll → GL | Payroll finalization → Journal Entry | PayrollGlPostingService |
| MRP → Purchasing | Material shortage → Auto PR/PO | AutoPurchaseOrderService |
| Low Stock → PR | Stock below reorder → Auto PR | AutoReplenishmentService |
| Quality → NCR | Failed inspection → Auto NCR | InspectionService triggers NcrService |
| WO → Inventory | Output recording → stock movement | WorkOrderOutputService |
| SO → WO → Delivery | Sales order → production → delivery chain | Chain events/broadcasting |
| Attendance → Payroll | DTR hours → salary computation | PayrollCalculatorService reads attendance |
| Leave → Payroll | Approved leave → deduction/credit | PayrollCalculatorService reads leave |
| Loan → Payroll | Outstanding loan → auto-deduction | PayrollCalculatorService reads loans |
| PO → GRN → Bill | Three-way match verification | ThreeWayMatchService |
| Mold shots → Alert | Shot count → 80% life alert | AlertEngineService |
| Machine breakdown → NCR | Downtime → auto NCR (if product affected) | Production events |

### 3.2 Missing or Weak Synergies (Recommended)

| Integration | Source → Target | Business Value | Complexity |
|---|---|---|---|
| **Forecast → MRP** | Demand forecast → MRP plan creation | Proactive material planning vs reactive | Medium |
| **Return → AR Credit** | Completed return → auto credit note | Eliminates manual AR adjustment | Low |
| **Return → Quality** | Return items → incoming inspection trigger | Closed-loop quality feedback | Low |
| **Supplier Quality → ASL** | Below-threshold performance → ASL review flag | Automated supplier governance | Low |
| **NCR → Rework WO** | NCR disposition "rework" → auto work order | Eliminates manual WO creation for rework | Medium |
| **Budget → PO Block** | PO amount > remaining budget → block/warn | Prevents overspend | Low (service exists) |
| **Asset Depreciation → JE** | Monthly depreciation → auto journal entry | Eliminates manual monthly posting | Low |
| **Customer Credit → SO** | AR balance + open SOs > credit limit → block confirm | Prevents bad debt exposure | Low |
| **Delivery KPI → Customer** | On-time delivery rate → customer dashboard | Customer satisfaction visibility | Low |
| **Maintenance Cost → Asset TCO** | Sum maintenance spend per asset | Total cost of ownership visibility | Low |
| **Clearance → Loan** | Separation clearance checks outstanding loan | Prevents premature final pay release | Low |
| **Holiday → Payroll** | Holiday calendar auto-applies to DTR computation | Already done? Verify holiday premium calculation | Verify |
| **Inspection Data → SPC** | Historical measurements → Cp/Cpk/control charts | Statistical process control (IATF value) | Medium |
| **Production Output → Forecast Accuracy** | Actual production vs forecast → accuracy KPI | Closes forecast feedback loop | Low |

### 3.3 Data Flow Optimization Opportunities

1. **Event-Driven Chain Broadcasting:** Already uses Laravel events + Reverb. Ensure ALL chain transitions broadcast (some may be missing — audit each status change).

2. **Unified Timeline:** Activity events table exists but may not capture ALL cross-module interactions. Every chain status change should create an activity_event for full traceability.

3. **Cross-Module Reporting:** Financial impact of quality issues (NCR cost + rework cost + customer complaints + returns) should aggregate into a "Cost of Poor Quality" (COPQ) dashboard widget. Data exists across modules but isn't synthesized.

4. **Predictive Integration:** Machine condition readings → predictive maintenance score → proactive WO scheduling → capacity scheduler adjustment. This pipeline exists in pieces but end-to-end automation may be incomplete.

5. **Document Chain:** SO → WO → Inspection → CoC → Delivery → Invoice — each step generates a document. Ensure document_vault links all chain documents for single-click access from any chain member.

---

## 4. Priority Matrix — What To Fix First

### Critical (Should Fix for Thesis Defense)

| # | Item | Module | Effort | Defense Value |
|---|---|---|---|---|
| 1 | Wire BudgetEnforcementService into PO/Bill creation | Budgeting | Low | Shows budget controls actually work |
| 2 | Auto-depreciation journal entries (monthly scheduler) | Assets/Accounting | Low | Shows automation depth |
| 3 | Verify NCR → auto rework WO generation works | Quality/Production | Verify | Core IATF claim |
| 4 | Customer credit limit check on SO confirmation | CRM/Accounting | Low | Shows AR risk management |
| 5 | Clearance → loan balance check (block if outstanding) | HR/Loans | Low | Shows process integrity |
| 6 | B2B Portal: add Form Requests (input validation) | B2B | Medium | Security requirement |
| 7 | Approval escalation timeout (auto-escalate after N days) | Common | Medium | Shows workflow maturity |

### High Value (Strengthen Thesis Differentiator)

| # | Item | Module | Effort | Defense Value |
|---|---|---|---|---|
| 8 | SPC Cp/Cpk calculation from existing measurement data | Quality | Medium | IATF 16949 showcase |
| 9 | Government compliance reports (BIR 2316 alphalist) | Payroll | Medium | Philippine localization |
| 10 | Forecast accuracy tracking (MAPE) | Forecasting | Low | DSS sophistication |
| 11 | Cost of Poor Quality dashboard widget | Quality/Dashboard | Medium | Cross-module synthesis |
| 12 | MRP lead time offsetting | MRP | Medium | MRP II competency |
| 13 | Payroll period-over-period variance report | Payroll | Low | Analytical depth |

### Nice-to-Have (Polish)

| # | Item | Module | Effort |
|---|---|---|---|
| 14 | Dashboard drill-down (KPI → filtered list) | Dashboard | Low |
| 15 | Leave calendar team view | Leave | Low |
| 16 | Alert snooze functionality | Common | Low |
| 17 | Portal user invitation workflow | B2B | Medium |
| 18 | Document versioning | Common | Medium |
| 19 | Half-day leave support | Leave | Low |
| 20 | Inventory aging report | Inventory | Low |

---

## 5. Architecture Assessment

### Strengths

1. **Modular monolith done right** — 23 modules with clear boundaries, own routes, own models
2. **Security posture excellent** — HTTP-only cookies, HashIDs, row-level filtering, input sanitization, encrypted PII
3. **Pattern discipline** — PATTERNS.md enforces consistency across 232 pages
4. **Real-time foundation** — Reverb WebSocket already wired for production output, badges, chain events
5. **Three-chain thinking** — Every feature maps to a business process, not a CRUD endpoint
6. **Approval engine reusable** — Single ApprovalService powers all 4-tier workflows across modules
7. **Test coverage adequate** — 57+ backend tests, 9 frontend tests, 6 E2E specs, load tests
8. **Documentation exceptional** — CLAUDE.md + TASKS.md + SCHEMA.md + PATTERNS.md + DESIGN-SYSTEM.md

### Weaknesses

1. **B2B Portal thin** — No services, no validation, no tests — security risk if deployed
2. **Forecasting underdeveloped** — Algorithms unclear, no accuracy feedback loop
3. **Cross-module synergies partially wired** — Budget enforcement service exists but may not be integrated
4. **Test coverage uneven** — Payroll/Purchasing well-tested, B2B/Forecasting/Returns have zero tests
5. **No 2FA** — Enterprise ERP with payroll data should have MFA option
6. **Government compliance reports missing** — BIR/SSS/PhilHealth report generation critical for Philippine deployment
7. **SPC absent** — IATF 16949 thesis claim needs at minimum Cp/Cpk to be credible

### Technical Debt

1. **Migration gaps** (0132-0149 reserved but unused) — intentional, not a problem
2. **Some controllers may be fat** (B2B Portal controllers without services) — extract to services
3. **No database indexes documented** (migration 0108/0171 add performance indexes — verify coverage)
4. **Rate limiting configuration** — verify it's applied globally, not just auth routes

---

## 6. Thesis Defense Preparation — Anticipated Questions

| Question | Your Answer Should Reference |
|---|---|
| "How do you ensure data integrity in payroll?" | DB::transaction wrapping, anomaly detection, never-unlock-finalized rule, GL auto-posting verification |
| "How does quality integrate with production?" | 4 touchpoints, auto-NCR from failed inspection, NCR → corrective action, Pareto analytics, CoC generation |
| "What makes this IATF 16949 compliant?" | Inspection specs with tolerances, measurement recording, NCR workflow, traceability (batch/lot), CoC, defect tracking |
| "How do you handle concurrent access?" | Optimistic locking (version column), DB transactions, document sequence concurrency tests (existing test) |
| "What's the approval workflow architecture?" | Single reusable ApprovalService, HasApprovalWorkflow trait, 4-tier configurable per entity, escalation |
| "How do you handle security?" | HTTP-only cookies, HashIDs, RBAC (280+ permissions), rate limiting, encrypted PII, input sanitization, CSP headers |
| "What decision support does this provide?" | Role-based dashboards, OEE, Pareto, forecast, budget vs actual, supplier performance, chain bottleneck detection |
| "How do the three chains connect?" | Chain events + broadcasting, activity feed, chain bottleneck widget, linked records component, procurement chain view |

---

*Report generated: 2026-06-04*
*System totals: 23 modules, 152 migrations, ~85 tables, 232 pages, 71 components, 83 API modules, 57+ backend tests*
