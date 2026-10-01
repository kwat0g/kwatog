# Inventory — Items, GRN, Issues, Transfers, Counts

## What is this?

Inventory tracks every raw material: what it is, where it sits,
what arrived (GRN), what left for production (issue), what moved
between bins (transfers), and what we counted (stock count).

## Before you start

1. Go to `/sign-in`.
2. Log in as warehouse staff:
   - Email: `warehouse@ogami.test`
   - Password: `password`
3. This one account can do every flow below
   (items, warehouse, GRN, issues, transfers, counts).

## Part A — Items: the material catalog (`/inventory/items`)

1. Go to `/inventory/items`.
2. Use the search box to find a material (example: resin).
3. Click a row to open `/inventory/items/<id>` — specs, stock, history.
4. To add a material, click **New item**.
5. Fill the "New item" form (`/inventory/items/create`):
   name, code, unit of measure, reorder level.
6. Click **Create item** (editing later shows **Save changes**).

## Part B — Warehouses and locations (`/inventory/warehouse`)

Stock lives in Warehouse → Zone → Location (bin).

1. Go to `/inventory/warehouse`.
2. To add a building, click **New warehouse**, fill the form,
   click **Create**.
3. Open a warehouse to see its zones. Add one via the zone button.
4. Open a zone to see its bin locations.
   Add one via the location button.
5. Mistyped a bin? Edit it and click **Save changes**.
6. Removing a bin archives it (it can be restored later).

## Part C — GRN: receive what the supplier delivered (`/inventory/grn`)

A GRN (Goods Receipt Note) records the truck arriving at the dock.

1. Go to `/inventory/grn`.
2. Click **New GRN**.
3. On "New GRN" (`/inventory/grn/create`):
   - Pick the **Purchase Order** this delivery belongs to.
   - Enter the **received date** and each line's **receive qty**.
4. Click **Create GRN**, then confirm in the popup.
5. Open the GRN (`/inventory/grn/<id>`).
6. Check quantities against the PO.
7. Click **Accept**. Accepting posts the stock into inventory
   and updates average cost.
8. If short or damaged: click **Reject** (give a reason), or
   **Reject remainder** to refuse only what is still unaccepted.
9. Partial deliveries show **Accept remaining** / **Partial accept**.

## Part D — Material issue: give stock to production

1. Go to `/inventory/material-issues/create`
   (page title: "New material issue").
2. Pick **Issue from** (which warehouse/bin).
3. Pick the **work order** receiving the material (if asked).
4. Add lines: item + quantity. The page blocks more than is in stock.
5. Check **Issued date**.
6. Click **Create issue** (busy text: "Saving…").
7. Open the issue (`/inventory/material-issues/<id>`) to see
   who issued what, when.

## Part E — Transfer orders: move stock between bins

1. Go to `/inventory/transfer-orders`.
2. Fill the create form: item, from-location, to-location,
   quantity, reason.
3. Click **Create transfer order**. Status starts as **pending**.
4. Click the pending order to open it.
5. Click **Execute** and confirm. Stock moves immediately.
   To void a pending one, use its cancel button.

## Part F — Stock levels and stock count

1. To SEE balances, go to `/inventory/stock-levels`
   (item × warehouse quantities, low-stock flags).
2. To COUNT bins, go to `/inventory/warehouse-map`
   and switch to the **Stock Count** view.
3. Start or open a count session (a session covers chosen locations).
4. On a row click **Count**, type the **counted quantity**,
   click **Record**.
5. Big differences need approval: click **Approve** on the variance.
6. When all bins are counted, click **Complete** / **Complete session**.

## Who can do what (cheat sheet)

- `warehouse@ogami.test` — everything above.
- `purchasing@ogami.test` — view stock + create GRNs only.
- Production/QC — view stock; QC can also quarantine bad stock (MRB).
