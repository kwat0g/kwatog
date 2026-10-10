# Maintenance — Work Orders, Preventive Schedules & Breakdowns

For role-based, end-to-end manual testing, use the [Maintenance test runbook](../docs/MAINTENANCE-E2E-MANUAL-TEST-RUNBOOK.md).

**Demo login:** `maintenance@ogami.test` (Maintenance Technician) ·
password: `password`

Log in at `/sign-in`, click **Sign in**.

## 1. The big picture (30 seconds)

Broken machine or due preventive task → **maintenance work order** →
technician **starts** it → fixes → **completes** it. **Preventive schedules**
auto-raise work orders when due, so maintenance happens before breakdowns.

## 2. Tour the maintenance home

1. Open `/maintenance`; it redirects to **Maintenance Work Orders**
   (`/maintenance/work-orders`). Phones show cards; desktops show a table.
2. Open **Maintenance Schedules** at `/maintenance/schedules`.

## 3. The breakdown flow (the main demo)

1. Open `/maintenance/work-orders` and click **New work order**.
2. Page **New maintenance work order**: pick the machine, describe the
   fault, submit with **Create work order** (shows **Creating…**).
3. Open the work order (`/maintenance/work-orders/:id`).
4. On desktop, click **Start** and confirm **Start**. On a phone, click
   **Start work** and confirm **Start work**.
5. Do the (pretend) repair. Record a spare part under **Spare parts** on desktop
   or **Parts used** on a phone if stock is available. On desktop, click
   **Complete**, fill **Remarks (optional)**, and confirm. On a phone, fill
   **Work performed**, click **Complete work order**, and confirm.
6. Statuses move open → in progress → completed.

## 4. Assignment note

- `maintenance@ogami.test` does not hold `maintenance.wo.assign`, so the
  **Assignment** panel does not appear. Assignment is optional for this test:
  continue directly from **Open** to **In progress**.

## 5. Preventive schedules

1. Open `/maintenance/schedules` — the page explains: "the system
   materialises a WO when due."
2. Open an existing schedule (`/maintenance/schedules/:id`) to review its
   target, interval, last performed date, and next due date.

> `maintenance@ogami.test` can view schedules but cannot create, edit, archive,
> or restore them. No seeded non-admin account holds the schedule-management
> permission.

## 6. What this login can and cannot do

- `maintenance@ogami.test` can create and complete maintenance work orders,
  view machines (`mrp.machines.view`) and assets.
- It **cannot** assign work orders. Clearance signing
  (`hr.clearance.sign`) is also held but is an HR-flow item, not this demo.

## 7. If something looks wrong

- Machine list empty on the create form? Machines are created under MRP by
  `production@ogami.test` (see MRP guide §5) — create one first.
- Assignment and schedule-management controls missing? Expected for this role.
- Mold shot-limit alerts (see MRP guide §6) can raise preventive maintenance
  work orders automatically — mention the link.
