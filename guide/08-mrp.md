# MRP — BOMs, Plans, Scheduler, Machines & Molds

**Demo login:** `ppc@ogami.test` (PPC Head) · password: `password`

Log in at `/sign-in`, click **Sign in**. Password for all demo accounts is
`password`.

## 1. The big picture (30 seconds)

1. A customer order arrives (CRM) → MRP explodes each product's **Bill of
   Materials** (BOM) → it flags **shortages** → it auto-creates purchase
   requests and draft work orders → PPC confirms the **schedule** (Gantt).

## 2. Bills of Materials (BOMs)

1. Open `/mrp/boms` (**Bill of Materials** in the sidebar).
2. Click **Add BOM** (button only visible with manage permission).
3. Page **New BOM**: pick the finished product, add material lines with
   quantities, and submit.
4. Click any BOM row (`/mrp/boms/:id`) to inspect it; the detail page links
   to **Edit** at `/mrp/boms/:id/edit`.

## 3. Run MRP and read a plan

1. Open `/mrp/plans` (**MRP Plans** in the sidebar).
2. Click **Run MRP now** (shows **Running…** while it works).
3. Click a plan row to open `/mrp/plans/:id`. You see four summary cards:
   **Total demand**, **Shortages** (materials short), **Auto PRs**
   (purchase requests generated), **Planned cost**.
4. Below are the per-material diagnostics and the **Cost estimate** panel.
5. To refresh a plan after data changes, click **Re-run** — the plan version
   ticks up.

## 4. The scheduler / Gantt

1. Open `/production/schedule` (**Production Schedule (Gantt)** in the sidebar).
2. Review the proposed schedule bars.
3. Click **Confirm N schedules** to lock the proposal in.

## 5. Machines (view as PPC)

1. Open `/mrp/machines` (**Production Machines** in the sidebar).
2. Browse status and capacity per machine; click a row for
   `/mrp/machines/:id`.

> Note for the demo: the **New machine** button needs the manage permission,
> which `ppc@ogami.test` does **not** hold. Create or edit machines as
> `production@ogami.test` (Production Manager). If the button 403s for PPC,
> that is expected, not a bug.

## 6. Molds and shot counts

1. Open `/mrp/molds` (**Production Molds** in the sidebar).
2. The list shows a **Shots** column: current shots vs. max shots before
   maintenance, with a progress bar.
3. Click a mold (`/mrp/molds/:id`) to see the **Shot count** panel
   (current / max) plus the **Lifetime** line.
4. A mold nearing its limit shows a **Near shot limit** chip.
5. Same permission note as machines: **New mold** requires manage —
   use `production@ogami.test`.

## 7. What this login can and cannot do

- `ppc@ogami.test` owns BOMs, MRP runs (`Run MRP now`, `Re-run`), the
  schedule view, and product routings (author + publish).
- It can create work orders and confirm them.
- It **cannot** create machines/molds — that is the
  Production Manager's master data.

## 8. If something looks wrong

- **Run MRP now** missing? It needs the runs permission — log in as `ppc@ogami.test`.
- Plan shows shortages? That is the system working: each shortage line is a
  material to buy (auto-PR count tells you how many requests were raised).
- 403 on machine/mold create as PPC? Expected — switch to `production@ogami.test`.
