# OGAMI ERP — Polish & Improvement Tasks

> Final task set based on user feedback session.
> Covers: Role-specific dashboards, Sidebar restructure + badge counts,
> Chain automation hardening, Self-service enhancements.
>
> **How to execute:**
> `claude "Read CLAUDE.md, docs/PATTERNS.md, docs/DESIGN-SYSTEM.md.
> Execute Task [CODE] from docs/POLISH-TASKS.md completely."`

---
Read CLAUDE.md, docs/PATTERNS.md, docs/DESIGN-SYSTEM.md. Enhance and improve Task S1-2 and D1-8 from docs/POLISH-TASKS.md completely. Those tasks was already implemented but they need a improvement and   enhancement.



## SERIES D — ROLE-SPECIFIC DASHBOARDS

*Every role gets a completely different dashboard tuned to their exact responsibilities.
No more one-size-fits-all. The dashboard is the first thing they see — it must feel like it was built for them.*

---

### Task D1: Dashboard Router (Role-Based Entry Point)

**What to build:**

When a user logs in, redirect to their role-specific dashboard instead of a generic page.

```typescript
// spa/src/pages/dashboard/index.tsx
export default function DashboardRouter() {
  const { user } = useAuthStore();

  const dashboardMap: Record<string, string> = {
    system_admin:       '/dashboard/admin',
    plant_manager:      '/dashboard/plant',
    production_manager: '/dashboard/production',
    ppc_head:           '/dashboard/ppc',
    hr_officer:         '/dashboard/hr',
    finance_officer:    '/dashboard/finance',
    purchasing_officer: '/dashboard/purchasing',
    warehouse_staff:    '/dashboard/warehouse',
    qc_inspector:       '/dashboard/quality',
    maintenance_tech:   '/dashboard/maintenance',
    impex_officer:      '/dashboard/impex',
    department_head:    '/dashboard/department-head',
    employee:           '/self-service',
  };

  const target = dashboardMap[user.role.slug] ?? '/dashboard/default';
  return <Navigate to={target} replace />;
}
```

Each dashboard is a **separate page** with completely different widgets, layout, and data. They share the same design tokens and components but display entirely different information.

---

### Task D2: Plant Manager Dashboard

**Audience:** Plant Manager, VP (read-only access to everything)

**Layout: 4 rows**

```
ROW 1 — KPI Cards (4 across)
┌─────────────┐ ┌─────────────┐ ┌─────────────┐ ┌─────────────┐
│ Revenue      │ │ Production  │ │ OEE Avg     │ │ On-Time Del │
│ ₱ 4.82M     │ │ 48,250 pcs  │ │ 82.4%       │ │ 96.2%       │
│ ↑ 12.4% MTD │ │ ↑ 8.1% WoW  │ │ ↓ 2.3% WoW  │ │ ↑ 1.1% WoW  │
└─────────────┘ └─────────────┘ └─────────────┘ └─────────────┘

ROW 2 — Chain Stage + Alerts (2/3 + 1/3)
┌──────────────────────────────────┐ ┌─────────────────────┐
│ Active Orders by Chain Stage     │ │ Critical Alerts (5)  │
│ Order Entered    12 ████████████ │ │ 🔴 IM-003 Breakdown  │
│ MRP Planned       9 ████████     │ │ 🔴 Resin C critical  │
│ In Production     7 ██████       │ │ 🟡 Mold M-008 80%    │
│ QC Pending        4 ████         │ │ 🟡 Nissan PO late    │
│ Ready to Ship     3 ███          │ │ 🔵 Payroll Apr 1-15  │
│ Delivered Unpaid  6 ██████       │ │                      │
│ At Risk           2 ██           │ │ [View All Alerts]    │
└──────────────────────────────────┘ └─────────────────────┘

ROW 3 — Machine Status + QC Pareto (1/2 + 1/2)
┌─────────────────────────────────┐ ┌────────────────────────┐
│ Machine Utilization (Live)      │ │ QC Defect Pareto       │
│ IM-001 150T [Running] 87.2%     │ │ Flash      ████████ 45 │
│ IM-002 150T [Running] 84.5%     │ │ Short shot █████    22 │
│ IM-003 200T [Breakdown] 58.1%   │ │ Sink mark  ████     15 │
│ IM-004 200T [Running] 89.4%     │ │ Weld line  ██       10 │
│ IM-005 300T [Idle] 72.3%        │ │ Warping    █         7 │
│ IM-006 300T [Setup] —           │ │ Other      █         5 │
└─────────────────────────────────┘ └────────────────────────┘

ROW 4 — Financial Snapshot (3 cards)
┌─────────────┐ ┌─────────────┐ ┌─────────────┐
│ AR Outstanding│ │ AP Due Soon │ │ Budget Used  │
│ ₱ 2.4M      │ │ ₱ 340K      │ │ 68% of FY   │
│ 6 invoices  │ │ Due in 7d   │ │ ₱ 33M / 48M │
└─────────────┘ └─────────────┘ └─────────────┘
```

All numbers clickable → navigate to filtered list. Time range selector: Today / Week / Month / Quarter.

---

### Task D3: PPC Head Dashboard

**Audience:** Production Planning & Control Head

```
ROW 1 — Planning Status (4 KPI cards)
  MRP Last Run | Unplanned WOs | Capacity Used | Material Shortages
  2h ago       | 3 WOs         | 78% this week | 4 items

ROW 2 — Production Gantt (full width)
  Mini Gantt showing next 7 days across all machines
  Bars: confirmed WOs (green), planned WOs (gray), maintenance (amber)
  [Open Full Gantt] button

ROW 3 — MRP Shortages + Machine Availability (1/2 + 1/2)
  Left: List of items with net shortage, urgency flag, linked PR status
  Right: Machine availability grid (machine × day = available/busy/maintenance)

ROW 4 — WO Status Breakdown
  Planned (12) | Confirmed (8) | In Progress (5) | Paused (1) | Completed today (3)
  Each count is clickable chip → filtered WO list
```

---

### Task D4: HR Officer Dashboard

**Audience:** HR Officer, HR Manager

```
ROW 1 — People KPIs (4 cards)
  Total Active Employees | On Leave Today | Probation Alert | Pending Requests
  213                    | 8              | 3 expiring soon | 12

ROW 2 — Department Headcount + Attendance Summary (1/2 + 1/2)
  Left: Bar chart — headcount per department (horizontal bars)
  Right: This month attendance summary
    Present avg: 96.2%
    Absent avg:   2.1%
    Late avg:     1.7%
    [View Full DTR]

ROW 3 — Leave Calendar Preview + Upcoming Events (2/3 + 1/3)
  Left: This week's calendar — who is on leave per day
    Mon: Ana R. (VL), Pedro G. (SL)
    Tue: Carlos M. (VL)
    ...
  Right: HR Calendar
    Apr 15 — Payroll cutoff
    Apr 30 — Regularization: 3 employees
    May 01 — Labor Day (holiday)
    May 14 — SIL accrual run

ROW 4 — Pending Approvals (table)
  All pending leave requests, OT requests, loan applications needing HR action
  [Approve] [Reject] inline buttons
```

---

### Task D5: Finance Officer Dashboard

**Audience:** Finance Officer, Accounting Staff

```
ROW 1 — Cash & Financial KPIs (4 cards)
  Cash in Bank | AR Outstanding | AP Due This Week | Net Income MTD
  ₱ 8.2M      | ₱ 2.4M         | ₱ 485K           | ₱ 1.2M

ROW 2 — AR Aging + AP Aging (1/2 + 1/2)
  Left: AR Aging donut chart
    Current:  ₱ 1.8M (75%)
    30 days:  ₱ 420K (17.5%)
    60 days:  ₱ 120K (5%)
    90+ days: ₱ 60K (2.5%)
  Right: AP Aging same format
    [Pay Selected] button on overdue items

ROW 3 — Payroll Status + Unposted JEs (1/2 + 1/2)
  Left: Payroll Periods status
    Apr 1-15: ✅ Disbursed
    Apr 16-30: ⚠ Pending HR approval
    [Review Payroll]
  Right: Unposted Journal Entries needing Finance action
    5 draft JEs pending review
    [Review JEs]

ROW 4 — Budget vs Actual (full width, horizontal bar per dept)
  Each department: budget bar vs actual bar
  Color: green (< 80%), amber (80-95%), red (> 95%)
```

---

### Task D6: Purchasing Officer Dashboard

**Audience:** Purchasing Officer, Purchasing Staff

```
ROW 1 — Procurement KPIs (4 cards)
  PRs Pending My Action | Open POs | Overdue Deliveries | Suppliers Due Review
  8                     | 12       | 3                  | 2

ROW 2 — PR Action Queue (full width — this is their main job)
  Table of PRs awaiting Purchasing Officer action
  Columns: PR No., Department, Items, Estimated Total, Urgency, Days Waiting
  Inline: [Convert to PO] [Reject] buttons
  Urgent PRs highlighted with amber row background

ROW 3 — PO Status + Supplier Performance (1/2 + 1/2)
  Left: PO pipeline
    Draft: 3 | Approved: 5 | Sent: 4 | Receiving: 3 | Complete: 8
  Right: Top 5 suppliers by score
    Taiwan Plastics: 94% ●
    Ogami Co., Ltd.: 98% ●
    Phil Metals: 87% ●

ROW 4 — Upcoming Deliveries (table)
  Expected GRNs this week with: PO No., Vendor, Items, Expected Date, Status
```

---

### Task D7: Warehouse Staff Dashboard

**Audience:** Warehouse Head, Warehouse Staff

```
ROW 1 — Warehouse KPIs (4 cards)
  Pending GRNs | Material Issues Today | Low Stock Items | Pending Transfers
  3            | 5                     | 8               | 2

ROW 2 — Incoming + Outgoing Queue (1/2 + 1/2)
  Left: INCOMING — Expected deliveries today + this week
    PO-202604-0015 | Ogami Co. | Today
    PO-202604-0016 | Taiwan    | Tomorrow
    [Receive Goods] button on each row
  Right: OUTGOING — Deliveries scheduled today
    SO-202604-0003 | Toyota | Apr 15 | [Pick & Pack]
    SO-202604-0004 | Nissan | Apr 16 | [Pick & Pack]

ROW 3 — Low Stock Alerts (full width table)
  Item | Current Stock | Reorder Point | Shortage | Supplier | Action
  Resin C | 85kg | 200kg | 115kg | Taiwan | [View PR]
  Metal Insert | 450pcs | 1000pcs | 550pcs | Ogami | [View PR]

ROW 4 — Zone Utilization (4 zone cards)
  Zone A (RM): 68% full
  Zone B (Staging): 23% full
  Zone C (FG): 45% full
  Zone D (Spares): 52% full
```

---

### Task D8: QC Inspector Dashboard

**Audience:** QC Inspector, QC Head, QC/QA Manager

```
ROW 1 — QC KPIs (4 cards)
  Pending Inspections | Pass Rate Today | Open NCRs | CoCs Generated MTD
  4                   | 97.2%           | 3          | 18

ROW 2 — Pending Inspections Queue (full width — main job)
  Table: QC No. | Stage | Product | Batch No. | Qty | Waiting Since | [Inspect]
  Sorted by urgency (outgoing > in-process > incoming)
  [Inspect] button opens inspection recording directly from dashboard

ROW 3 — Defect Pareto + NCR Status (1/2 + 1/2)
  Left: Defect Pareto this week (bar chart)
  Right: Open NCRs needing action
    NCR-202604-0003 | Critical | Toyota | RC-001 | [View]
    NCR-202604-0004 | Minor | Internal | WB-001 | [View]

ROW 4 — QC Chain Coverage
  Products inspected this week per stage:
  Incoming: 8/8 GRNs inspected (100%)
  In-Process: 5/7 active WOs (71%) — 2 missing
  Outgoing: 4/4 completed WOs (100%)
```

---

### Task D9: Department Head Dashboard

**Audience:** Production Head, Warehouse Head, any Dept Head role

```
ROW 1 — My Team (4 cards)
  My Team Size | Present Today | On Leave | Pending My Approval
  22           | 20            | 2         | 5

ROW 2 — Approval Queue (full width — most important action)
  ALL pending approvals where this user is the current approver:
  Leave requests, OT requests, PRs, etc.
  [Approve All] bulk button + individual [Approve] [Reject] per row
  BADGE: shows count — this drives the sidebar badge too

ROW 3 — My Department Attendance (this week calendar view)
  Each team member: row of 5 days with dot per day
  Green dot = present, Red = absent, Amber = late, Blue = on leave

ROW 4 — My Department Budget (if dept has budget)
  Budget Used: 68% | ₱ 3.2M of ₱ 4.7M | Remaining: ₱ 1.5M
  Top spending this month (bar chart by GL account)
```

---

### Task D10: Maintenance Tech Dashboard

**Audience:** Maintenance Head, Maintenance Technician

```
ROW 1 — Maintenance KPIs (4 cards)
  Open Work Orders | Overdue PM | Machine Downtime Today | Molds Near Limit
  5               | 2           | 2h 15min              | 3

ROW 2 — My Work Orders (full width table — main job)
  Active + pending maintenance work orders assigned to me
  Priority color-coded: Critical (red row), High (amber), Medium (gray)
  Columns: WO No. | Machine/Mold | Type | Priority | Since | [Start] [Complete]

ROW 3 — Preventive Maintenance Calendar (this month)
  Calendar showing upcoming PM schedules
  Overdue in red, Due this week in amber, Upcoming in gray

ROW 4 — Machine Breakdown History + Mold Status (1/2 + 1/2)
  Left: Last 7 days breakdown events with duration
  Right: Molds approaching shot limit (>80%)
    M-008: 126K/150K (84%) ⚠
    M-012: 89K/100K (89%) ⚠
```

---

## SERIES S — SIDEBAR RESTRUCTURE + BADGE COUNTS

*Sidebar should feel like a professional navigation tool, not a page directory.
Sub-pages belong inside their parent — not as separate sidebar items.*

---

### Task S1: Sidebar Consolidation (Remove Clutter)

**The problem:** Sidebar has 30+ items because every sub-page has its own entry.
**The fix:** Parent pages have tabs or sections. Sidebar only shows module-level entries.

**New rule:** A page only gets a sidebar entry if it is a PRIMARY module entry point.
Sub-features are accessed via tabs, sections, or buttons WITHIN the parent page.

**Before → After:**

```
BEFORE (too many sidebar items):        AFTER (clean):
──────────────────────────────────      ──────────────────────────
HUMAN RESOURCES                         HUMAN RESOURCES
  ├─ Employees           ← keep           ├─ Employees           ← has tabs inside
  ├─ Departments         ← move inside    └─ Payroll & Benefits
  ├─ Positions           ← move inside
  ├─ Shifts              ← move inside    [Employees page has tabs:]
  └─ Holidays            ← move inside    [Team] [Departments] [Positions]
                                          [Shifts] [Holidays] [Directory]

PAYROLL & BENEFITS                      PAYROLL & BENEFITS
  ├─ Payroll Periods     ← keep           ├─ Payroll
  ├─ Government Tables   ← move inside    └─ Attendance & Leave
  ├─ Attendance          ← keep
  ├─ Leave Requests      ← keep
  ├─ Loans               ← keep

INVENTORY                               INVENTORY (WAREHOUSE)
  ├─ Items               ← keep           ├─ Stock Overview
  ├─ Categories          ← move inside    ├─ Receiving (GRN)
  ├─ Warehouses          ← move inside    └─ Issuance
  ├─ Zones               ← move inside
  ├─ Locations           ← move inside    [Stock Overview has tabs:]
  ├─ Stock Levels        ← keep           [Items] [Locations] [Movements]
  ├─ Stock Movements     ← merge          [Adjustments] [Stock Count]
  └─ Stock Count         ← merge

QUALITY CONTROL                         QUALITY CONTROL
  ├─ Inspection Specs    ← move inside    └─ Quality (single entry)
  ├─ Inspections         ← keep
  ├─ NCR                 ← keep           [Quality page has tabs:]
  └─ Certificates        ← keep           [Dashboard] [Inspections] [NCR]
                                          [Certificates] [Specs] [Traceability]

ADMINISTRATION                          ADMINISTRATION
  ├─ User Management     ← keep           ├─ Users & Roles
  ├─ Roles               ← merge          └─ Settings
  ├─ Permissions         ← merge
  ├─ Settings            ← keep           [Users & Roles has tabs:]
  └─ Audit Logs          ← move inside    [Users] [Roles] [Permissions] [Audit]
```

**Result:** Sidebar goes from ~35 items to ~18 items. Clean, professional.

**Implementation:**
- Convert each consolidated parent page to use a `<TabNavigation>` component
- Tabs are full-width, sticky below the page header
- Deep links still work: `/hr/employees?tab=departments` activates the Departments tab
- Browser back button respects tab state

---

### Task S2: Sidebar Badge Count System

**The most important UX improvement:** Every sidebar item shows a count of pending/important work specific to the logged-in user. The count is role-aware — the same sidebar item shows different counts per role.

**Badge specification:**

```
┌─────────────────────────────────────────────┐
│ ○ Approvals          [12]   ← amber badge   │  12 pending MY approval
│ ○ Purchase Requests   [8]   ← amber badge   │  8 awaiting Purchasing action
│ ○ Work Orders         [3]   ← red badge     │  3 overdue WOs
│ ○ Quality Control     [4]   ← amber badge   │  4 pending inspections
│ ○ Payroll             [1]   ← blue badge    │  1 period awaiting my action
│ ○ Attendance          [15]  ← gray badge    │  15 employees with no DTR today
│ ○ Leave Requests       [5]  ← amber badge   │  5 pending my approval
└─────────────────────────────────────────────┘
```

**Badge colors:**
- 🔴 Red = critical / overdue (machine breakdown, overdue delivery, critical stock)
- 🟡 Amber = needs action (pending my approval, inspection due, PR to process)
- 🔵 Blue = informational (payroll ready to review, report generated)
- ⚫ Gray = count only (employees present today, items in zone)

**Per-role badge logic:**

| Sidebar Item | Badge = |
|---|---|
| Approvals (all roles) | Count of records pending MY specific approval step |
| Purchase Requests | Purchasing Officer: PRs pending Purchasing review |
| Work Orders | PPC Head: unconfirmed WOs. Production: active + overdue WOs |
| Quality Control | QC Inspector: pending inspections |
| Payroll | HR: period pending HR approval. Finance: period pending Finance confirmation |
| Leave Requests | Dept Head + HR: pending approval by my role |
| Inventory | Warehouse: items below reorder point |
| Deliveries | ImpEx: deliveries in transit needing update |
| Maintenance | Maintenance Head: overdue preventive maintenance |

**Implementation:**

```typescript
// spa/src/api/badges.ts
export const badgesApi = {
  // Single endpoint returns all badge counts for current user
  getAll: () => client.get<BadgeCounts>('/badges').then(r => r.data),
};

interface BadgeCounts {
  approvals: number;
  purchase_requests: number;
  work_orders: number;
  quality_control: number;
  payroll: number;
  leave_requests: number;
  inventory_alerts: number;
  deliveries: number;
  maintenance: number;
  notifications: number; // for topbar bell
}
```

```php
// app/Modules/Dashboard/Controllers/BadgeController.php
public function index(Request $request): JsonResponse
{
    $user = $request->user();
    return response()->json([
        'approvals'         => ApprovalRecord::pendingForUser($user)->count(),
        'purchase_requests' => $this->prBadge($user),
        'work_orders'       => $this->woBadge($user),
        'quality_control'   => Inspection::where('result', 'pending')->count(),
        'payroll'           => $this->payrollBadge($user),
        'leave_requests'    => LeaveRequest::pendingForApprover($user)->count(),
        'inventory_alerts'  => StockLevel::belowReorderPoint()->count(),
        'deliveries'        => Delivery::inTransit()->count(),
        'maintenance'       => MaintenanceWorkOrder::overdue()->count(),
        'notifications'     => $user->unreadNotifications()->count(),
    ]);
}
```

**Polling:** Refresh badge counts every 60 seconds via TanStack Query `refetchInterval`.
**Real-time:** When a relevant event fires (new approval request, new inspection), broadcast WebSocket event to update badges immediately.

```typescript
// In Sidebar component
const { data: badges } = useQuery({
  queryKey: ['badges'],
  queryFn: () => badgesApi.getAll(),
  refetchInterval: 60_000, // 60 seconds
});

// WebSocket subscription
useEffect(() => {
  window.Echo.private(`user.${user.id}`)
    .listen('BadgeCountChanged', () => {
      queryClient.invalidateQueries({ queryKey: ['badges'] });
    });
}, []);
```

---

## SERIES CA2 — CHAIN AUTOMATION HARDENING

*Reduce the number of screens to complete chain actions from many to one or two.*

---

### Task CA1: One-Click SO → Production Auto-Chain

**Current state:** SO confirmed → manually navigate to MRP → manually create WOs → manually confirm WOs → manually go to warehouse → materials issued → production starts. 6+ screens, 15+ clicks.

**Target state:** Click "Confirm Sales Order" → one confirmation dialog → system handles everything → user receives a summary of what was done.

**What happens automatically on SO confirm:**

```php
// SalesOrderService::confirm($so)
return DB::transaction(function () use ($so) {

    // 1. Change status
    $so->update(['status' => 'confirmed']);

    // 2. Run MRP immediately (synchronous for small SOs, queued for large)
    $mrpResult = $this->mrpEngine->runForSalesOrder($so);

    // 3. Create Work Orders for each SO line
    $workOrders = [];
    foreach ($so->items as $line) {
        $wo = $this->createWorkOrder($so, $line);

        // 4. Attempt auto-scheduling (machine + mold + capacity check)
        $schedule = $this->capacityPlanner->findBestSlot($wo);

        if ($schedule) {
            // 5. Reserve materials immediately
            $this->materialReservation->reserve($wo);

            // 6. Assign machine + mold
            $wo->update([
                'machine_id' => $schedule->machine_id,
                'mold_id'    => $schedule->mold_id,
                'planned_start' => $schedule->start,
                'planned_end'   => $schedule->end,
                'status'     => 'confirmed',
            ]);

            // 7. Auto-generate picking list for warehouse
            $this->pickingListService->generate($wo);
        } else {
            // Flag for PPC manual scheduling
            $wo->update(['status' => 'planned', 'needs_manual_scheduling' => true]);
        }

        $workOrders[] = $wo;
    }

    // 8. Notify all stakeholders in one batch
    $this->notifyChainStart($so, $workOrders, $mrpResult);

    // 9. Return summary for display in confirmation dialog
    return [
        'so_number'      => $so->so_number,
        'work_orders'    => count($workOrders),
        'auto_scheduled' => collect($workOrders)->where('status', 'confirmed')->count(),
        'needs_manual'   => collect($workOrders)->where('needs_manual_scheduling', true)->count(),
        'shortages'      => $mrpResult->shortage_count,
        'prs_created'    => $mrpResult->prs_created,
    ];
});
```

**Frontend — SO Confirmation Dialog:**

```
Confirm Sales Order SO-202604-0003?
──────────────────────────────────────────────────────────
Customer: Toyota Motor Philippines
Products: 3 line items · Total: ₱ 486,500.00
Due: Apr 20, 2026

Confirming this order will automatically:
  ✓ Run MRP and check material availability
  ✓ Create Work Orders for all 3 lines
  ✓ Schedule production on available machines
  ✓ Reserve required materials in inventory
  ✓ Generate picking lists for warehouse
  ✓ Notify Production, Warehouse, and PPC teams

[Cancel]                          [Confirm & Start Chain]
```

After confirmation, show results summary:

```
✅ Sales Order Confirmed!
──────────────────────────────────────────────────────────
3 Work Orders created
  ✅ WO-202604-0006 → Auto-scheduled IM-002 · Apr 08-09
  ✅ WO-202604-0007 → Auto-scheduled IM-004 · Apr 09-11
  ⚠  WO-202604-0009 → Needs manual scheduling (no mold available)

Material Planning:
  ✅ 2 items: sufficient stock, materials reserved
  ⚠  1 shortage: Resin C (85kg short) → PR-202604-0022 auto-created [URGENT]

Notifications sent to: Pedro Garcia (PPC), Carlos Mendoza (Warehouse),
  Ricardo Tanaka (Production)

[View Work Orders]  [View MRP Plan]  [View PR Shortages]
```

---

### Task CA2: Single-Screen Receiving (GRN → QC → Inventory)

**Current state:** Create GRN on one page → navigate to QC module → create inspection → wait for result → navigate back to GRN → accept → inventory updates. 4 screens, completely disjointed.

**Target state:** One unified "Receive Goods" screen. Everything on one page. Warehouse and QC work together without navigating away.

**New page:** `pages/warehouse/receive.tsx`

```
RECEIVE GOODS — PO-202604-0015 (Taiwan Plastics Corp.)
Expected: 500kg Resin B · 200kg Resin C
════════════════════════════════════════════════════════════

STEP 1: WHAT DID WE RECEIVE?
──────────────────────────────────────────────────────────
Item            Ordered    Received    Condition
Resin B (PP)    500kg    [ 498 ]kg    ○ Good  ● Damaged  ○ Short
Resin C (PA)    200kg    [ 200 ]kg    ● Good  ○ Damaged  ○ Short

Supplier Lot No.: [SL-TW-0456    ]
Delivery Note:    [TW-DN-20260408]
Received by:      Carlos Mendoza (auto-filled)
Date & Time:      Apr 08, 2026 10:45 AM (auto-filled)

════════════════════════════════════════════════════════════

STEP 2: QC INCOMING INSPECTION
(Opens automatically after Step 1 is filled — no navigation)
──────────────────────────────────────────────────────────
Inspector:    [Rosa Villareal ▾]
Resin B — Check:
  ☑ Certificate of analysis attached
  ☑ Moisture content within spec (< 0.2%)
  ☑ Color/appearance normal
  ☑ Packaging intact

Resin C — Check:
  ☑ Certificate of analysis attached
  ☑ Moisture content within spec
  ☑ Color/appearance normal
  ☐ Packaging intact ← flagged

Remarks: [Minor packaging damage on 2 bags of Resin C,
           contents appear unaffected]

QC Decision:  ○ Pass   ○ Fail   ○ Pass with remarks

════════════════════════════════════════════════════════════

STEP 3: REVIEW & CONFIRM
──────────────────────────────────────────────────────────
GRN will be created:   GRN-202604-0011
QC Result:             ✅ Pass with remarks
Stock will increase:   Zone A · Bin A1-R1-B2 (select bin ▾)
  Resin B: +498kg (new avg cost: ₱ 96.50/kg)
  Resin C: +200kg (new avg cost: ₱ 151.00/kg)

[Cancel]                    [Create GRN & Update Inventory]
```

**What happens on submit (all atomic, single DB transaction):**
1. GRN created with received quantities
2. QC inspection created and linked to GRN
3. Inspection result recorded
4. Stock levels updated with new quantities
5. Weighted average cost recalculated
6. Bill draft created (pre-filled from PO)
7. Finance notified: "GRN received, QC passed. Draft bill ready for review."
8. 3-way match auto-triggered
9. GRN status = accepted (or pending if QC failed)

**If QC FAILS:** Step 3 changes to:
```
STEP 3: QC FAILED — DISPOSITION
──────────────────────────────────────────────────────────
QC Result: ❌ Failed — [description of failure]

Action Required:
  ○ Return to supplier (notify Purchasing)
  ○ Use under concession (requires Manager approval)
  ○ Partial accept (accept passing items, return failing)

NCR will be auto-created.
Stock will NOT be updated until disposition is resolved.

[Cancel]        [Record Failure & Create NCR]
```

---

### Task CA3: Streamlined Payroll Auto-Schedule

**Current state:** HR must remember to create payroll on the 14th and last day of month.

**Target state:** System auto-creates AND auto-computes. HR just reviews the result.

Expand Task A3 from NEW-TASKS-V2.md with a dedicated Payroll Pipeline page:

`pages/payroll/pipeline.tsx` — shows ALL payroll periods in one view:

```
PAYROLL PIPELINE
──────────────────────────────────────────────────────────────────
Period          Employees  Gross          Status           Action
Apr  1-15       213        ₱ 2,847,500   ✅ Disbursed      [View]
Apr 16-30       213        ₱ 2,815,200   ⚠ HR Review       [Review →]
May  1-15       213        Computing...  ⏳ Processing      [—]
May 16-31       —          —             ○ Scheduled        [—]
Jun  1-15       —          —             ○ Scheduled        [—]
──────────────────────────────────────────────────────────────────
Auto-schedule: ● ON   Next run: May 14 at 11:00 PM
```

Status progression is fully visual. HR clicks "Review →" → sees payroll detail with anomaly flags → clicks "Approve" → Finance sees it in their dashboard → clicks "Confirm" → system finalizes, generates payslips, bank file, GL posts.

---

## SERIES SS — SELF-SERVICE ENHANCEMENTS

*Employees should rarely need to call HR. The portal handles it.*

---

### Task SS1: Overtime Request in Self-Service

**What to build:**

Add "Overtime" section to self-service portal alongside Leave.

`pages/self-service/overtime.tsx`:

```
OVERTIME REQUESTS
──────────────────────────────────────────────────────────────────
[Apply for Overtime]

PENDING
  Apr 08 · 2 hours OT · "Urgent delivery preparation"
  Status: ⏳ Pending Dept Head approval

HISTORY
  Apr 02 · 1.5 hours OT · Approved ✅ · Added to Apr 1-15 payroll
  Mar 28 · 2 hours OT · Approved ✅ · Added to Mar 16-31 payroll
  Mar 20 · 1 hour OT  · Rejected ✗  · "Not pre-approved"
```

Apply for Overtime (bottom sheet, mobile-optimized):
```
Apply for Overtime
──────────────────────────────────────────────────────────
Date:          [Apr 09, 2026 ▾]         (must be today or future)
Hours:         [1.0]  [1.5]  [2.0]  [3.0]  [4.0]  (tap to select)
               Max 4 hours per day
Reason:        [________________________________]
               (required — sent to dept head)

Your shift today: Day Shift 6AM–2PM
OT would be:     2PM–6PM (up to 4 hours)
Estimated pay:   ₱ 125.00/hr × OT hours × 1.25 = ₱ 187.50

[Submit for Dept Head Approval]
```

Dept Head sees it in their Approval Queue dashboard widget + sidebar badge.

---

### Task SS2: Self-Service Profile Update (Pending HR Approval)

**What employees can update themselves:**
- Mobile number
- Personal email
- Home address (street, barangay, city, province, zip)
- Emergency contact (name, relationship, phone)
- Bank account number (requires HR + Finance approval — financial change)

**What they CANNOT update themselves (HR only):**
- Name, birth date, civil status
- Government IDs (SSS, TIN, PhilHealth, Pag-IBIG)
- Employment details (department, position, salary, employment type)

**UI in self-service profile page:**

```
PERSONAL INFORMATION
──────────────────────────────────────────────────────────
Mobile:    ● 09XX-XXX-XXXX    [Edit]
Email:     ● juan@personal.com [Edit]
Address:   ● 123 Rizal St, Cavite City  [Edit]

EMERGENCY CONTACT
──────────────────────────────────────────────────────────
Name:      Maria Cruz (Wife)   [Edit]
Phone:     09XX-XXX-XXXX       [Edit]

BANK ACCOUNT                   (requires HR + Finance approval)
──────────────────────────────────────────────────────────
Bank:      BDO Unibank
Account:   •••••••••1234       [Request Update]
```

Clicking [Edit]:
- Opens inline edit field (not a new page)
- Shows current value (pre-filled)
- Shows "Why are you updating this?" dropdown (optional)
- Submit → creates a `profile_change_requests` table entry
- HR sees it in their approval queue
- HR approves → value auto-updates on employee record
- Employee notified: "Your mobile number was updated"

For bank account changes:
- Requires TWO approvals: HR Officer AND Finance Officer
- Finance Officer approves because bank account affects payroll disbursement
- Until approved: old bank account used for payroll

**Database:**
```sql
CREATE TABLE profile_change_requests (
    id BIGSERIAL PRIMARY KEY,
    employee_id BIGINT NOT NULL REFERENCES employees(id),
    field_name VARCHAR(100) NOT NULL,
    current_value TEXT,
    requested_value TEXT NOT NULL,
    reason TEXT,
    status VARCHAR(20) DEFAULT 'pending',  -- pending/approved/rejected
    reviewed_by BIGINT REFERENCES users(id),
    reviewed_at TIMESTAMP,
    rejection_reason TEXT,
    created_at TIMESTAMP DEFAULT NOW()
);
```

---

### Task SS3: Employee Document Downloads (Self-Service)

**What employees can download themselves (no HR involvement):**

Add "Documents" section to self-service Me page:

```
MY DOCUMENTS
────────────────────────────────────────────────────────
CERTIFICATES (Auto-Generated)
  [Download] Employment Certificate
  [Download] Certificate of Compensation (BIR 2316 — annual)
  [Download] Certificate of SSS Contributions
  [Download] Certificate of PhilHealth Contributions
  [Download] Certificate of Pag-IBIG Contributions

PAYSLIPS
  [Download] Apr 1–15, 2026
  [Download] Mar 16–31, 2026
  [Download] Mar 1–15, 2026
  [View All Payslips]

FILED DOCUMENTS (Uploaded by HR)
  [View] Contract of Employment
  [View] NDA (if applicable)
```

**Auto-generated certificates:**

*Employment Certificate* (most requested) — generates instantly as PDF:
```
TO WHOM IT MAY CONCERN:

This is to certify that JUAN DELA CRUZ, bearing Employee No.
OGM-2026-0142, is a bona fide employee of Philippine Ogami
Corporation, holding the position of Production Operator in the
Production Department since April 1, 2024, with employment
status of REGULAR.

His/Her current salary is [SHOWN IF EMPLOYEE REQUESTS WITH SALARY /
NOT SHOWN on standard certificate].

This certification is issued upon the employee's request for
whatever legal purpose it may serve.

Issued this 8th day of April 2026.

[Signature Line]
HR Officer / HR Manager
Philippine Ogami Corporation
```

*BIR 2316 download:* Available after year-end closing in January. Auto-generated from payroll records.

*Government contribution certificates:* Summary of monthly contributions for the calendar year.

---

## FULL IMPLEMENTATION PROMPT TEMPLATE

Use this exact prompt for each task in this file:

```
You are building the Ogami ERP system (Philippine manufacturing company).

Before writing any code:
1. Read CLAUDE.md completely (project rules, security, conventions)
2. Read docs/PATTERNS.md completely (copy-paste templates for all patterns)
3. Read docs/DESIGN-SYSTEM.md completely (Geist font, monochrome canvas,
   32px table rows, 6 accent colors only, dark mode first-class)

Now execute Task [CODE] from docs/POLISH-TASKS.md.

Key rules to remember for this task:
- Every page handles 5 states: loading skeleton / error / empty / data / stale
- Every form: Zod schema + disabled submit + server error mapping + cancel button
- Every number: font-mono tabular-nums
- Every status: <Chip> with semantic variant
- Auth: HTTP-only cookies, never localStorage, never Bearer tokens
- IDs: always hash_id strings, never raw integers
- Color: grayscale canvas only. Color ONLY on buttons, chips, alerts, deltas.
- Dark mode: CSS variables throughout, test in both themes

After completing, list every file created or modified.
Run the final checklist from docs/PATTERNS.md before marking done.
```

---

## PRIORITY EXECUTION ORDER

```
THIS WEEK (high visibility, high impact):
  S2  → Sidebar badge counts    (every user sees this immediately)
  D1  → Dashboard router        (foundation for all role dashboards)
  D2  → Plant Manager dashboard (defense showpiece)
  D4  → HR Officer dashboard
  D5  → Finance Officer dashboard

NEXT WEEK:
  S1  → Sidebar consolidation   (cleaner navigation)
  D3  → PPC Head dashboard
  D6  → Purchasing dashboard
  D7  → Warehouse dashboard
  D8  → QC Inspector dashboard

WEEK 3 (chain automation):
  CA1 → One-click SO confirm → full auto-chain
  CA2 → Single-screen GRN → QC → Inventory
  CA3 → Payroll pipeline page

WEEK 4 (self-service):
  SS1 → Overtime requests in self-service
  SS2 → Profile update with HR approval
  SS3 → Document downloads (employment cert, BIR 2316, payslips)

ONGOING (parallel with above):
  All ADV tasks from docs/ADVISER-TASKS.md (mandatory for defense)
```
