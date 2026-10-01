# Maintenance — Work Orders, Preventive Schedules & Breakdowns

**Demo login:** `maintenance@ogami.test` (Maintenance Technician) ·
password: `password`

Log in at `/sign-in`, click **Sign in**.

## 1. The big picture (30 seconds)

Broken machine or due preventive task → **maintenance work order** →
technician **starts** it → fixes → **completes** it. **Preventive schedules**
auto-raise work orders when due, so maintenance happens before breakdowns.

## 2. Tour the maintenance home

1. Open `/maintenance`.
2. From here you reach **Maintenance Work Orders** (`/maintenance/work-orders`)
   and **Maintenance Schedules** (`/maintenance/schedules`).

## 3. The breakdown flow (the main demo)

1. Open `/maintenance/work-orders` and click **New work order**.
2. Page **New maintenance work order**: pick the machine, describe the
   fault, submit with **Create work order** (shows **Creating…**).
3. Open the work order (`/maintenance/work-orders/:id`).
4. Click **Start** → dialog **Start work order?** → confirm **Start**.
5. Do the (pretend) repair. Click **Complete** → dialog **Complete work order**
   → fill remarks → confirm.
6. Statuses move open → in progress → completed.

## 4. Assignment note (important for the demo)

- The detail page has an **Assignment** panel with an **Assign work order**
  button, but assigning needs `maintenance.wo.assign`, which
  `maintenance@ogami.test` does **not** hold. If it 403s, that is expected —
  ask an admin account to assign.

## 5. Preventive schedules

1. Open `/maintenance/schedules` — the page explains: "the system
   materialises a WO when due."
2. Click **New schedule** → page **New maintenance schedule**: pick a machine
   or mold, set the interval, submit with **Create schedule**.
3. Open a schedule (`/maintenance/schedules/:id`) to review; edits live at
   `/maintenance/schedules/:id/edit`.

> Permission note: creating/managing schedules may be admin-gated. If
> **New schedule** 403s as `maintenance@ogami.test`, say so openly and
> continue the demo as `admin@ogami.test`.

## 6. What this login can and cannot do

- `maintenance@ogami.test` can create and complete maintenance work orders,
  view machines (`mrp.machines.view`) and assets.
- It **cannot** assign work orders. Clearance signing
  (`hr.clearance.sign`) is also held but is an HR-flow item, not this demo.

## 7. If something looks wrong

- Machine list empty on the create form? Machines are created under MRP by
  `production@ogami.test` (see MRP guide §5) — create one first.
- 403 on assign/schedule create? Expected for this role — use admin.
- Mold shot-limit alerts (see MRP guide §6) can raise preventive maintenance
  work orders automatically — mention the link.
