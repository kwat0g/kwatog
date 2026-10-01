# Production — Work Orders, Output & OEE

**Demo login:** `production@ogami.test` (Production Manager) · password: `password`

Log in at `/sign-in` (click **Sign in**).

## 1. What a work order is (30 seconds)

A work order (WO) is one factory job: make X pieces of one product by a
planned date. Most work orders are **auto-created by the MRP engine** when a
sales order is confirmed.

## 2. Tour the work-order list

1. Open `/production/work-orders`.
2. You see the **Work orders** list with a **Status** filter and a search box
   ("Search WO number or product…").
3. Orders that need attention (paused, overdue, late start) appear as cards
   under **Needs attention** at the top.
4. Click any row to open its detail page (`/production/work-orders/:id`).

## 3. Create a work order by hand

1. Open `/production/work-orders/create` (page title **New work order**).
2. Fill in the product, quantity, and dates, then submit.
3. The new order appears in the list with status **Planned**.

## 4. The lifecycle: Confirm → Start → Complete → Close

Open any work order detail page. The buttons change with the status:

1. **Planned** order → click **Confirm**. A dialog checks that both
   a machine and a mold are assigned, then confirm in the dialog.
2. **Confirmed** order → click **Start**. The order is now **In progress**.
3. **In progress** order → click **Complete** when the run finishes.
4. **Completed** order → click **Close** to finish it.

Two extra buttons you may see:

- **Pause** (only while In progress) — opens the **Pause work order** dialog.
  Pick a downtime category, type a reason, confirm.
  This reason feeds the machine downtime and OEE reports below.
- **Resume** (only while Paused) — puts the order back In progress.
- There is also **Cancel** for orders that will never run.

## 5. Record production output

1. On an In-progress order, click **Record output**.
2. You land on `/production/work-orders/:id/record-output`.
3. Enter good pieces, rejects, and (optionally) defect lines, then click
   **Record** (shows **Recording…** while saving).
4. The **Live cumulative** panel (Produced / Good / Reject /
   Scrap rate) updates via WebSocket.

## 6. Machine downtime and OEE

1. Open `/production/dashboard` and follow the **OEE Report** link.
2. The **OEE Report** page shows KPI cards (Overall OEE, Availability,
   Performance, Quality), an **OEE trend** chart, and the downtime breakdown.
3. Behind the scenes: every **Pause** reason + category recorded in step 4
   becomes downtime minutes in this report.

## 7. The production schedule (Gantt)

1. Open `/production/schedule` (**Production Schedule (Gantt)** in the sidebar).
2. If PPC has proposed a schedule, click **Confirm N schedules** to accept it.

## 8. What this login can and cannot do

- `production@ogami.test` can confirm, start, pause, record output on,
  complete, and close work orders, and manage machines and molds.
- It can **view** production routings (process plans) but **cannot** publish
  new versions — that belongs to PPC (`ppc@ogami.test`).
- Operators on the shop floor use the separate Factory Floor app at
  `/factory` (machine_operator role).

## 9. If something looks wrong

- Empty list? That is normal — confirm a sales order first (see CRM guide).
- A button you expect is missing? Buttons are permission-gated by order
  status: e.g. **Confirm** only shows on Planned orders.
