# OGAMI ERP — User Manual

> Sprint 8 — Task 84. Operations manual + thesis appendix source. Markdown
> here, exported to PDF via `make manual-pdf` (pandoc with the project's
> default Latin Modern template).

## Conventions

- **Bold** = button or screen label
- `mono` = literal text or filename
- `→` = navigation step (e.g. *Sidebar → HR → Employees*)
- All screenshots live alongside this file in `docs/screenshots/`.
  Filename pattern: `<section>-<short-description>.png`. Add new ones to
  the corresponding section as you go.

## Table of Contents

1. [Logging in & changing your password](#1-logging-in--changing-your-password)
2. [Navigation, modules, and permissions](#2-navigation-modules-and-permissions)
3. [HR — People](#3-hr--people)
4. [Attendance & DTR](#4-attendance--dtr)
5. [Leave](#5-leave)
6. [Loans & Cash Advance](#6-loans--cash-advance)
7. [Payroll](#7-payroll)
8. [Procurement (PR → PO → GRN → Bill → Payment)](#8-procurement)
9. [Inventory](#9-inventory)
10. [Sales & CRM](#10-sales--crm)
11. [MRP & Production](#11-mrp--production)
12. [Quality & NCR](#12-quality--ncr)
13. [Maintenance](#13-maintenance)
14. [Assets](#14-assets)
15. [Separation & Clearance](#15-separation--clearance)
16. [Self-service (mobile)](#16-self-service-mobile)
17. [Notifications & Search](#17-notifications--search)
18. [Admin: users, roles, settings, audit log](#18-admin)
19. [Troubleshooting](#19-troubleshooting)

---

## 1. Logging in & changing your password

1. Open the SPA URL provided by IT.
2. Enter your **email** and **password**, click **Sign in**.
3. If your password has expired (90 days), the app routes you to
   **Change Password** automatically. New password rules: minimum 8 characters,
   at least one uppercase, one number, and one special character. You cannot
   reuse your last 3 passwords.
4. After 5 failed attempts your account is locked for 15 minutes.

## 2. Navigation, modules, and permissions

- The **Sidebar** lists modules grouped by chain ownership. Modules you do
  not have permission for are hidden.
- The **Topbar** shows breadcrumbs, the global search trigger (⌘K), the
  theme toggle, the notification bell, and your avatar.
- **Three guards** protect every page: `AuthGuard` (must be logged in),
  `ModuleGuard` (module enabled), `PermissionGuard` (specific permission).
  Backend re-enforces every check independently.

## 3. HR — People

### 3.1 Onboarding an employee

*Sidebar → HR → Employees → Add Employee*

Fill the four sections (Personal, Employment, Government IDs, Banking),
click **Create Employee**. The system auto-generates `OGM-YYYY-NNNN`.

### 3.2 Updating an employee record

*Employees list → row click → Edit Employee*

Sensitive fields (SSS, TIN, bank account) are masked unless your role
includes `hr.employees.view_sensitive`.

## 4. Attendance & DTR

### 4.1 Importing biometric DTR CSV

*Sidebar → HR → Attendance → Import*

Upload a CSV with columns `employee_no, date, time_in, time_out`.
The system computes regular/OT/night-diff per record and respects the
14 holiday combinations.

### 4.2 Approving overtime

*Sidebar → HR → Attendance → Overtime*

Department heads approve from the Kanban view; HR officers can override.

## 5. Leave

### 5.1 Filing a leave (employee)

Self-service portal or *Sidebar → HR → Leaves → New Leave*. Select type,
date range, reason. Validates balance + no overlap server-side.

### 5.2 Approving a leave

*Sidebar → HR → Leaves → Approvals*. Two-tier flow: Department Head → HR.

## 6. Loans & Cash Advance

*Sidebar → HR → Loans → New Loan*. Validation: max 1 active company loan
plus 1 active cash advance per employee. Company loan capped at one month
basic salary. On approval the system generates the amortization schedule
and auto-deducts on subsequent payrolls.

## 7. Payroll

### 7.1 Creating a period

*Sidebar → Payroll → Periods → New Period*. Pick the half-month range and
payroll date.

### 7.2 Computing & approving

Click **Compute** to dispatch the queue job. Status moves to **draft**
when done. HR officer **Approves**, Finance officer **Finalizes**
(period locks; corrections go on the next period as adjustments).

### 7.3 Generating bank file & payslips

After finalize, click **Bank File** to download the CSV and **Payslips**
to download the per-employee A5 PDFs (2 per A4, watermarked CONFIDENTIAL).

## 8. Procurement

### 8.1 Creating a purchase request

*Sidebar → Purchasing → Requests → New Request*. Add items with quantities.
Auto-generated PRs (from the low-stock listener) appear with an
amber **AUTO** chip.

### 8.2 Generating a purchase order

After a PR is fully approved, **Convert to PO** consolidates by supplier.
PO ≥ ₱50,000 (configurable) requires VP signoff.

### 8.3 Recording a GRN

When the supplier delivers, *Inventory → GRN → New GRN* and pick the PO.
Stock increases and `weighted_avg_cost` recalculates on receipt.

### 8.4 3-way match

When the bill posts referencing the PO, the system compares quantities and
prices across PO → GRN → Bill. ≥ 5% variance flags a discrepancy and blocks
posting until Purchasing resolves it from the bill detail page.

### 8.5 Posting bill & payment

*Accounting → Bills → New Bill* (auto JE: DR Expense + DR VAT Input,
CR AP). Click **Pay** to post the payment (DR AP, CR Cash).

## 9. Inventory

- **Items**, **Categories**, **Warehouse** (zones + bins) are managed
  separately under the **Inventory** module.
- Stock movements are append-only: every receipt, issue, transfer, scrap
  goes through `StockMovementService::move`. Direct edits are forbidden.
- **Material issues** to work orders are recorded against open
  reservations.

## 10. Sales & CRM

### 10.1 Creating a sales order

*Sidebar → CRM → Sales Orders → New Order*. Pick a customer; the system
auto-pulls active price agreements. Each line has a delivery date that
becomes a future work order on confirmation.

### 10.2 Following the chain

The detail page renders a **ChainHeader** showing
`Order Entered → MRP Planned → In Production → QC → Delivered → Invoiced`.
The right panel **LinkedRecords** lists every related document.

## 11. MRP & Production

### 11.1 MRP plan review

On SO confirmation, **MrpEngineService** computes shortages and creates
draft PRs and planned work orders. Review under
*Sidebar → MRP → Plans*.

### 11.2 Production schedule (Gantt)

*Sidebar → Production → Schedule*. Drag bars to reorder, click to open
WO detail. PPC Head confirms the schedule before shifts start.

### 11.3 Recording output

*Production → Work Orders → record-output* (per shift). Enter good and
reject counts plus defect breakdown. Live updates flow to dashboards over
the `production.wo.{id}` channel.

## 12. Quality & NCR

### 12.1 Inspection specs

*Quality → Inspection Specs → {product}*. Enter dimensional, visual, or
functional parameters with tolerances; mark critical parameters.

### 12.2 Recording an inspection

Three stages — **incoming** (attached to GRN), **in-process** (attached to
WO), **outgoing** (attached to delivery). Outgoing uses AQL 0.65 Level II
sampling computed from batch quantity.

### 12.3 NCR & 8D

Failed inspections auto-open an NCR. Customer complaints can also open
NCRs and have an **8D Report** tab on the complaint detail.

## 13. Maintenance

### 13.1 Schedules

*Sidebar → Maintenance → Schedules → New Schedule*. Choose Machine or
Mold, then a time interval (hours/days) or shot count (mold-only). The
daily cron creates a **preventive** WO when the schedule is due or the
mold reaches 100% of its shot threshold.

### 13.2 Corrective work orders

Created when a machine breaks down or directly via
*Maintenance → Work Orders → New Work Order*. Lifecycle:
`open → assigned → in_progress → completed`. Spare parts consumed are
recorded under the WO and deducted from inventory.

## 14. Assets

### 14.1 Register

*Sidebar → Assets*. Each machine, mold, vehicle, or office equipment has
an asset row with acquisition cost, useful life, and salvage value.

### 14.2 Monthly depreciation

Runs automatically on the 1st of every month for the previous calendar
month, or via *Admin → Depreciation Runs* on demand. Idempotent.

### 14.3 Disposal

Click **Dispose** on the asset detail page, enter the disposal amount, and
the system posts a balanced JE that nets accumulated depreciation against
cost and books gain/loss.

### 14.4 QR labels

Click **Print QR Labels** on a multi-row selection in the assets list to
generate a printable label sheet (camera-scan opens the asset detail).

## 15. Separation & Clearance

*HR → Employees → {employee detail} → Initiate Separation*

1. Choose the **separation date** and **reason**.
2. Eleven default checklist items appear, grouped by department.
3. Each department signs off its items (HR can override).
4. Once every item is cleared, click **Compute Final Pay** to compute:
   - last salary pro-rated
   - unused convertible leave value
   - pro-rated 13th month
   - minus loan balance, cash advance, unreturned property
5. **Finalize** to flip the employee status, post the final-pay JE, and
   render the clearance PDF.

## 16. Self-service (mobile)

URL: `/self-service`. Bottom navigation: Home · DTR · Leave · Payslip · Me.
You only ever see your own data — the server scopes by `auth.user.employee_id`.

## 17. Notifications & Search

- **Bell** in the topbar shows unread count, dropdown lists last 25.
- **Notification preferences** under *Self-service → Notification
  Preferences* — toggle in-app vs. email per notification type.
- **Global search**: press `⌘K` (Mac) or `Ctrl+K`, type at least 2 chars,
  arrow keys to navigate, Enter to open. Results are permission-scoped.

## 18. Admin

- **Users**: *Admin → Users*. System Admin can create, edit, lock.
- **Roles & Permissions**: *Admin → Roles → {role} → Permissions* renders
  a matrix of permissions × this role.
- **Settings & feature toggles**: *Admin → Settings*.
- **Audit logs**: *Admin → Audit Logs*. Click a row to see a per-field
  diff (added/removed/changed).

## 19. Troubleshooting

| Symptom                                  | Where to look                        |
|---                                       |---                                   |
| Login loops back to /login               | session cookie missing — check CORS / `withCredentials`. |
| 403 on a page you should access          | Permission slug — see Admin → Roles. |
| Numbers misaligned in a table            | Verify `font-mono tabular-nums` on the cell. |
| Dashboard stale after change             | Cached 30s in Redis; wait or `redis-cli FLUSHDB`. |
| PDF blank in DomPDF                      | Missing `_layout.blade.php` partial. |
| Maintenance WO not auto-created          | `php artisan schedule:run` — confirm cron is wired. |
| Depreciation didn't post                 | Run `Admin → Depreciation Runs` for the month. |

---

## Generating the PDF

```bash
# Requires pandoc + a TeX distribution
make manual-pdf
# Output: docs/build/manual.pdf
```

The thesis appendix bundle additionally includes:
- `plans/sprint-8-polish-dss-and-defense-tasks-69-85.md` and prior sprint plans
- `docs/SCHEMA.md`, `docs/PATTERNS.md`, `docs/DESIGN-SYSTEM.md`
- Architecture diagrams from `docs/diagrams/`
