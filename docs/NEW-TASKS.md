# OGAMI ERP — New Tasks (Post-Audit Automation & Polish)

> These are new tasks beyond the original 85. Focused on AUTOMATION (proactive intelligence)
> and POLISH (making existing features production-grade).
>
> **Goal:** Transform the system from "digital manual process" to "automated ERP."
> The difference: manual = human decides every step. Automated = system decides,
> human only handles exceptions.
>
> **How to use:** `claude "Read docs/NEW-TASKS.md. Execute New Task [N]. Follow CLAUDE.md, docs/PATTERNS.md, docs/DESIGN-SYSTEM.md."`

---

## AUTOMATION TASKS (A-Series)

These tasks add proactive intelligence to the system. The chain processes exist —
now we make them self-driving.

---

### Task A1: Scheduled MRP Auto-Run (Daily at 6 AM)

**Current behavior:** MRP only runs when a new Sales Order is confirmed. Between orders, shortages grow silently.

**New behavior:** Every morning at 6 AM, the system re-runs MRP across all active Sales Orders, recalculates net requirements using current inventory, and updates or creates Purchase Requests automatically.

**Implementation:**
- Create `app/Console/Commands/RunDailyMrp.php` console command
- Register in `app/Console/Kernel.php`: `->dailyAt('06:00')`
- Command calls `MrpEngineService::runForAllActiveSalesOrders()`
- Compares current planned PRs vs new requirements — only creates NEW PRs for new shortages, does not duplicate
- Sends summary notification to PPC Head: "Daily MRP complete. 3 new shortages found. 2 PRs updated."
- Logs MRP run to a `mrp_runs` table: run_at, triggered_by (scheduled/manual), shortages_found, prs_created, prs_updated

**DB:** Migration `0110_create_mrp_runs_table.php` — id, run_at, triggered_by, shortages_found, prs_created, prs_updated, duration_ms, status (completed/failed), error_message nullable

**Frontend:** Add "Last MRP run: [date] · [shortages found]" to the MRP Plans page header. Add "Run MRP Now" button (manual trigger for PPC Head).

---

### Task A2: Smart Alert Engine (Proactive Warnings)

**Current behavior:** Problems discovered when someone manually checks a page.

**New behavior:** System monitors thresholds continuously and pushes alerts BEFORE problems become critical.

**Implementation:**
- Create `app/Common/Services/AlertEngineService.php`
- Create `app/Console/Commands/RunAlertEngine.php` — runs every 15 minutes via scheduler
- Create `alerts` table: id, type, severity (critical/warning/info), title, message, entity_type, entity_id, is_read, is_dismissed, created_at
- Create `AlertResource` and API endpoints: GET /api/v1/alerts (unread), PATCH /api/v1/alerts/{id}/dismiss

**Alert types to implement (all 12):**

```
INVENTORY ALERTS
  type: stock_critical       severity: critical  trigger: stock < safety_stock
  type: stock_low            severity: warning   trigger: stock < reorder_point  
  type: no_supplier          severity: warning   trigger: item has no approved_supplier and stock < reorder_point

PRODUCTION ALERTS
  type: machine_breakdown    severity: critical  trigger: machine.status = 'breakdown'
  type: mold_shot_limit      severity: warning   trigger: mold.current_shot_count > mold.max_shots * 0.80
  type: mold_shot_critical   severity: critical  trigger: mold.current_shot_count > mold.max_shots * 0.95
  type: wo_overdue           severity: warning   trigger: wo.planned_end < now() AND wo.status != 'completed'
  type: oee_below_threshold  severity: warning   trigger: machine OEE < 75% for 3+ consecutive days

FINANCE ALERTS
  type: ar_overdue_30        severity: warning   trigger: invoice.due_date < today - 30 AND unpaid
  type: ar_overdue_60        severity: critical  trigger: invoice.due_date < today - 60 AND unpaid
  type: ap_due_soon          severity: info      trigger: bill.due_date = today + 3 AND unpaid

QUALITY ALERTS
  type: qc_fail_rate_high    severity: warning   trigger: daily scrap rate > 5% on any product
```

**Frontend:** 
- Alerts panel on Plant Manager Dashboard (already designed in mockup — now powered by real data)
- Alert bell in topbar shows count of critical + warning alerts
- `/alerts` page: full list with filter by severity, type, entity
- Dismiss button on each alert
- Critical alerts send email notification immediately

---

### Task A3: Auto Payroll Period Creation

**Current behavior:** HR manually creates payroll period on the 1st and 16th.

**New behavior:** System auto-creates the period, auto-queues computation, and notifies HR to review.

**Implementation:**
- Scheduled command: runs on the 14th at 11 PM (creates period for 16th–end of month) and last day of month at 11 PM (creates period for 1st–15th of next month)
- Auto-creates `payroll_period` record with status = 'draft'
- Auto-dispatches `ProcessPayrollJob` for all active employees
- On job completion: sends notification to HR Officer: "Payroll for Apr 16–30 computed. 213 employees. Review and approve."
- HR reviews → approves → Finance confirms → finalizes

**Guard:** If period already exists for that date range, skip (prevents duplicates on retry).

**Frontend:** Add "Auto" badge next to auto-created periods. Add "Manually triggered" vs "Auto-scheduled" in period metadata.

---

### Task A4: Delivery-to-Invoice Auto-Trigger

**Current behavior:** After customer confirms delivery, Finance manually creates the invoice draft.

**New behavior:** Customer confirmation automatically creates a draft invoice and notifies Finance to review and finalize.

**Implementation:**
- Event: `DeliveryConfirmed` (already exists per Task 66)
- Listener: `CreateDraftInvoiceOnDeliveryConfirmed`
  - Finds the Sales Order linked to the delivery
  - Gets the price from `product_price_agreements` for the customer + product + date
  - Computes subtotal, VAT (12%), total
  - Creates invoice with status = 'draft'
  - Attaches the CoC PDF to the invoice
  - Notifies Finance Officer: "Delivery DN-202604-0006 confirmed. Draft invoice INV-202604-0012 ready for review."
- Finance reviews draft → clicks "Finalize" → GL auto-posts → customer notified

**ChainHeader update:** SO ChainHeader step "Invoiced" now auto-advances when draft invoice created.

---

### Task A5: Preventive Maintenance Auto-Scheduling

**Current behavior:** Maintenance Head manually creates maintenance work orders.

**New behavior:** System monitors machine hours, mold shots, and calendar intervals — auto-generates maintenance work orders before the threshold is reached.

**Implementation:**
- Scheduled command: runs daily at 7 AM
- For each active `maintenance_schedule`:
  - interval_type = 'shots': check current mold shot count vs (last_performed + interval_value). If ≥ 80%: create maintenance WO
  - interval_type = 'days': check if next_due_at is within 3 days. If yes: create maintenance WO
  - interval_type = 'hours': check machine running hours vs last maintenance
- Created WO status = 'pending', assigned to Maintenance Head for technician assignment
- Notifies Maintenance Head: "3 preventive maintenance orders created."
- Updates `maintenance_schedule.next_due_at` after each WO creation

**Database:** Add `machine_running_hours` (decimal) to machines table. Increment via daily scheduled job that reads machine downtime logs.

---

### Task A6: QC Auto-NCR on Inspection Failure

**Current behavior:** After an inspection fails, QC Inspector manually creates an NCR.

**New behavior:** System auto-creates the NCR draft when inspection result = 'fail', pre-fills all known data, and notifies QC Head to review and complete the root cause section.

**Implementation:**
- Event listener on `InspectionCompleted` where result = 'fail'
- Auto-creates `non_conformance_report` with:
  - source = inspection.stage
  - product_id from inspection
  - affected_quantity = reject_count
  - severity auto-set based on: critical dimension fail = 'critical', visual only = 'minor', dimensional non-critical = 'major'
  - description auto-filled: "Automated NCR from inspection [QC-YYYYMM-NNNN]. [N] parts rejected. Defects: [list]"
  - status = 'open'
  - Disposition left blank for QC to decide
- For outgoing inspection fail: auto-creates replacement Work Order for the rejected quantity
- Notifies QC Head: "NCR auto-created from outgoing inspection. [N] pcs rejected. [Severity]. Review disposition."

**Frontend:** NCR list shows "Auto-generated" chip. Root cause and corrective action still require human input.

---

### Task A7: Overdue Approval Escalation

**Current behavior:** Approval requests sit indefinitely if the approver forgets.

**New behavior:** If an approval is pending for more than 24 hours, the system sends a reminder. After 48 hours, it notifies the next level up.

**Implementation:**
- Scheduled command: runs every 6 hours
- Queries `approval_records` where action = 'pending' AND created_at < now() - 24 hours
- First reminder (24h): re-notifies the current approver
- Escalation (48h): notifies the approver's direct superior + current approver
- Never auto-approves (human must always approve financial requests)
- Adds note to approval record: "Reminder sent at 24h. Escalated at 48h."

**Frontend:** Pending approvals list shows overdue badge (red) if > 24h old. Tooltip: "Overdue by X hours."

---

### Task A8: Stock Replenishment Auto-PO (for critical items)

**Current behavior:** Low stock → draft PR created → 4-level approval → PO created manually.

**New behavior:** For items marked `is_critical = true` AND with a single preferred supplier, the system auto-creates a PO (not just PR) and sends directly to VP for one-step approval.

**Implementation:**
- Extend the low-stock automation from Task 45
- For critical items: check if one `approved_supplier` exists with `is_preferred = true`
- If yes: auto-create PO directly (skip PR), set status = 'pending_vp'
- Route to VP only (compressed approval chain for critical stock)
- Notify VP: "Critical stock alert. Auto-PO for Resin C (85kg remaining). Review and approve."
- For non-critical items: normal 4-level PR workflow (unchanged)

**Frontend:** POs with `is_auto_generated = true` show "Auto" chip. Supplier can be changed by Purchasing before final approval.

---

### Task A9: Payroll Anomaly Detection

**Current behavior:** Unusual payroll values go unnoticed until employees complain.

**New behavior:** Before HR approves payroll, system flags any anomalies for review.

**Implementation:**
- Service `PayrollAnomalyService::detect(payroll_period_id)`
- Runs automatically when payroll computation completes
- Checks each employee payroll against previous period:
  - Net pay change > 30% vs last period → flag "Large change"
  - OT hours > 80 in one period → flag "Excessive OT"
  - Deductions > 50% of gross → flag "High deduction ratio"
  - New employee (first payroll) → flag "First payroll — verify"
  - Zero net pay → flag "Zero pay check"
- Creates `payroll_anomaly_flags` table: payroll_id, employee_id, flag_type, details, is_resolved, resolved_by, resolved_at

**Frontend:** Payroll period detail page shows "Anomaly Review" tab. HR sees each flag with: employee name, flag type, previous value, current value, "Mark as reviewed" button. Cannot finalize payroll if unreviewed flags exist.

---

### Task A10: End-of-Day Production Summary (Scheduled Email)

**Current behavior:** Plant Manager must manually check the dashboard.

**New behavior:** Every day at 6 PM, system emails the Plant Manager and Production Manager a production summary.

**Implementation:**
- Scheduled command: daily at 6 PM
- Collects for today:
  - Total output per work order (target vs actual)
  - Machine OEE summary
  - Active breakdowns
  - Defects by type
  - QC inspection results
  - Pending materials
- Generates HTML email with the same layout as the Plant Manager Dashboard KPIs
- Sends to users with role `plant_manager` and `production_manager`
- Also sends weekly summary every Friday at 6 PM (weekly totals + trend vs last week)

**Email template:** `resources/views/emails/production-summary.blade.php` — matches dashboard visual style (monochrome, dense, tabular numbers).

---

## POLISH TASKS (P-Series)

These tasks improve existing features to production-grade quality.

---

### Task P1: ChainHeader on ALL Chain Records (Consistency Pass)

**Problem found in audit:** ChainHeader not verified on all chain records.

**Fix:** Audit and add ChainHeader to every page that is part of a chain:

| Page | Chain | Steps |
|---|---|---|
| Sales Order detail | Order-to-Cash | Order Entered → MRP → Scheduled → In Production → QC → Delivered → Invoiced → Collected |
| Work Order detail | Order-to-Cash | Planned → Confirmed → Materials Issued → In Progress → Completed → QC Passed → Closed |
| Purchase Order detail | Procure-to-Pay | PR Created → Approved → PO Sent → Shipment → GRN → QC Passed → Billed → Paid |
| GRN detail | Procure-to-Pay | Received → QC Pending → QC Passed → Stocked |
| Leave Request detail | Hire-to-Retire | Submitted → Dept Head → HR → Approved → Deducted |
| Loan detail | Hire-to-Retire | Applied → Approved → Active → Paid Off |
| NCR detail | Quality | Raised → QC Head Review → Disposition → Corrective Action → Closed |
| Delivery detail | Order-to-Cash | Scheduled → Loading → In Transit → Delivered → Confirmed |

Each ChainHeader must:
- Show correct step as active based on record.status
- Show date below completed steps (from created_at or relevant timestamp)
- Update automatically when status changes (TanStack Query refetch on mutation success)

---

### Task P2: LinkedRecords on ALL Chain Records

**Problem found in audit:** LinkedRecords panel only partially implemented.

**Fix:** Every chain record detail page must have a right panel showing:

Sales Order:
- MRP Plan (link to MRP plan generated from this SO)
- Work Orders (all WOs created for this SO, with status chips)
- QC Inspections (outgoing inspections for this SO's products)
- Deliveries (delivery notes for this SO)
- Invoice (if created)

Purchase Order:
- Source Purchase Request
- GRN (goods receipts against this PO)
- QC Incoming Inspection (inspection of received goods)
- Bill (AP bill referencing this PO)
- Payment (bill payment records)

Work Order:
- Sales Order (parent SO)
- Material Issue Slips (materials drawn from warehouse)
- Production Output entries (supervisor recordings)
- QC In-Process Inspections
- Machine (current machine assignment)
- Mold (current mold assignment with shot count)

---

### Task P3: Approval Chain Visualization Component

**Problem:** Approvals exist but users can't see WHO approved WHAT and WHEN.

**Fix:** Create `ApprovalTimeline` component that shows on every approvable record.

```tsx
// Shows vertical timeline of approval steps
// Each step: role → approver name → date → action (approved/rejected/pending)
// Current pending step pulsing indicator

<ApprovalTimeline
  steps={[
    { role: 'Dept Head', approver: 'Roberto Santos', date: 'Apr 06 09:15', action: 'approved', remarks: '' },
    { role: 'Manager', approver: 'Ricardo Tanaka', date: 'Apr 06 14:30', action: 'approved', remarks: '' },
    { role: 'Purchasing Officer', approver: null, date: null, action: 'pending', remarks: '' },
    { role: 'Vice President', approver: null, date: null, action: 'pending', remarks: '' },
  ]}
/>
```

Visual: vertical line with dots. Done = emerald dot. Active = pulsing indigo dot. Future = gray dot. Shows "Approved by [name] on [date]" for completed steps.

Add to: Leave Requests, Loans, Purchase Requests, Purchase Orders, Payroll Periods, Work Orders, NCRs, Separations.

---

### Task P4: Notification Center Overhaul

**Problem audit found:** Notification bell real-time not verified. Notification page basic.

**Fix:** Make notifications first-class:

- Topbar bell: shows unread count badge. Click → dropdown shows last 8 notifications with:
  - Icon indicating type (leave = calendar, PO = package, breakdown = alert)
  - Title + brief description
  - Time ago (e.g., "2 hours ago")
  - Unread items have indigo left border
  - "View all" link at bottom

- `/notifications` page:
  - Groups: Today, Yesterday, Earlier this week, Older
  - Filter by: All, Unread, Approvals, Alerts, System
  - Mark all as read button
  - Each notification links to the relevant record (clicking "Leave request approved" → goes to that leave request)

- Real-time via WebSocket:
  - Bell count updates without refresh (Reverb channel: `private-user.{id}`)
  - New notification slides in from top-right as toast AND increments bell count

- Notification preferences per user:
  - Email on/off per type
  - In-app on/off per type

---

### Task P5: Employee Self-Service Mobile Experience

**Problem:** Self-service built but not verified on mobile. Factory workers use phones.

**Fix:** Full mobile optimization for `/self-service/*` pages:

- Bottom navigation: Home, DTR, Leave, Payslip, Me (44px minimum tap targets)
- Home: shows greeting, today's shift, current leave balance, pending requests count
- DTR: shows current month calendar view, color-coded by attendance status
- Leave: balance cards + quick apply form (minimal fields, one page, large buttons)
- Payslip: card view of latest payslip summary + download button
- Me: profile, emergency contact, update mobile number

Mobile-specific rules:
- All touch targets ≥ 44×44px
- No hover-dependent interactions
- Forms use native date/time pickers on mobile
- Tables replaced with card lists on < 640px screens
- No horizontal scroll anywhere

---

### Task P6: Global Search Enhancement (Meilisearch)

**Problem:** Global search exists but quality not verified.

**Fix:** Make search actually useful for an ERP:

Indexed models and what to surface:
- Employees: employee_no, full_name, department, position, status
- Sales Orders: so_number, customer_name, status, total_amount
- Purchase Orders: po_number, vendor_name, status, total_amount
- Work Orders: wo_number, product_name, machine_name, status
- Invoices: invoice_number, customer_name, status, total_amount
- Employees (for HR context)
- Products: part_number, name
- Items: code, name, item_type
- Vendors: name, contact_person
- Customers: name, contact_person

Search results display:
- Grouped by type with type icon
- Show key identifiers in mono font (SO number, PO number)
- Show status chip next to each result
- Keyboard navigable (↑↓ arrows, Enter to open, Esc to close)
- Show "No results for '[query]'" state with suggestions

Trigger: Cmd+K or click search bar in topbar.

---

### Task P7: Audit Log Enhancement

**Problem:** Audit logs exist but are raw JSON — not useful for a thesis demo.

**Fix:** Make audit logs readable and defensible:

- Human-readable diff: instead of showing raw JSON, show "Changed basic_monthly_salary from ₱18,000.00 to ₱20,000.00"
- Color coding: creates = emerald, updates = blue, deletes = red
- Filter by: user, module, action type, date range, entity type
- Export to CSV button (for IATF traceability requirement)
- Financial audit trail: dedicated view showing only payroll, GL, and AR/AP operations
- "Who changed what" report: for a specific employee, show all changes ever made to their record

---

### Task P8: Dashboard Drill-Down (Every Number Clickable)

**Problem:** Dashboard shows numbers but clicking them does nothing.

**Fix:** Every KPI card and every number in dashboard panels is clickable and navigates to a filtered list:

| Dashboard element | Click goes to |
|---|---|
| "213 Employees" card | /hr/employees (no filter) |
| "12 On Leave Today" | /hr/employees?status=on_leave |
| "₱ 4.82M Revenue Week" | /accounting/invoices?date_from=this_week |
| "₱ 340K AR Outstanding" | /accounting/invoices?status=unpaid |
| "3 Low Stock Items" | /inventory/items?below_reorder=true |
| "IM-003 Breakdown" alert | /production/machines/IM-003 |
| "82.4% OEE" | /production/oee-report |
| Stage "7 In Production" | /production/work-orders?status=in_progress |

Implementation: wrap every StatCard value and StageBreakdown count in a `<Link>` or `onClick={() => navigate(url)}`. Pass filters as URL query params. List pages must read and apply these params on mount.

---

### Task P9: Printable Approval Forms (All Levels)

**Problem:** Approved forms not verified to have all 4 signature lines.

**Fix:** Every financially approved document must print with:

- Header: Ogami logo, document number, date
- Body: full document details (line items, amounts)
- Approval section at bottom:
  ```
  Prepared by: ________________  Date: _______
  [name of creator]

  Noted by: ________________  Date: _______
  [Dept Head name if approved]

  Checked by: ________________  Date: _______
  [Manager name if approved]

  Reviewed by: ________________  Date: _______
  [Officer name if approved]

  Approved by: ________________  Date: _______
  [VP name if approved]
  ```
- Pending approvers show blank line (for physical signature)
- Approved approvers show typed name + date
- Footer: "Generated by [user] on [date] at [time] · Ogami ERP"

Documents: Purchase Request, Purchase Order, Cash Advance, Company Loan, Leave Request (single page), Payroll Register, Bill Payment Authorization.

---

### Task P10: OEE Report Page (Full)

**Problem:** OEE calculated but only shown as a number on dashboard.

**Fix:** Full OEE report page at `/production/oee`:

- Date range selector (today / this week / this month / custom)
- Per-machine OEE breakdown:
  - Availability = (planned - downtime) / planned
  - Performance = actual output / theoretical output
  - Quality = good count / total count
  - OEE = A × P × Q (shown as gauge chart 0–100%)
- Trend chart: OEE over time for selected machine
- Downtime breakdown: by category (breakdown, changeover, no order, maintenance)
- OEE benchmark line at 75% (typical world-class: 85%)
- Export to PDF button (for IATF records)

This page is a strong thesis defense point — it shows data-driven manufacturing management.

---

## EXECUTION ORDER

Run these in this order after the 5 defense blockers are cleared:

**Week 1 (Automation core):**
A1 → A2 → A3 → A4 → A5

**Week 2 (More automation):**
A6 → A7 → A8 → A9 → A10

**Week 3 (Polish):**
P1 → P2 → P3 → P4 → P5

**Week 4 (Final polish):**
P6 → P7 → P8 → P9 → P10

---

## AUTOMATION IMPACT SUMMARY

After implementing all A-series tasks, the system behavior changes:

| Process | Before (Manual) | After (Automated) |
|---|---|---|
| Material planning | Run MRP when you remember | Runs every morning, alerts on new shortages |
| Stock replenishment | Notice shortage, create PR manually | Auto-PR on reorder point, auto-PO for critical items |
| Payroll | HR creates period, triggers compute manually | Period created automatically, compute queued, HR just reviews |
| Invoice creation | Finance creates after checking delivery | Auto-draft on customer confirmation |
| Preventive maintenance | Maintenance Head remembers to schedule | Auto-scheduled from shot count and calendar |
| QC failure handling | Inspector manually creates NCR | NCR auto-created with severity, replacement WO auto-generated |
| Approval reminders | Approver forgets, nothing happens | Reminder at 24h, escalation at 48h |
| Payroll anomalies | HR doesn't notice until employee complains | Flags appear before approval, must be reviewed |
| Production reporting | Manager checks dashboard manually | Email summary at 6 PM every day |

**This is the thesis differentiator upgrade:** from a system that automates data entry to a system that automates decisions and escalates only the exceptions to humans.
