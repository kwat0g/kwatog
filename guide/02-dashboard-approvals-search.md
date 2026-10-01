# Dashboards, Approvals, Action Center, Search, Notifications

## 1. Role dashboards (`/dashboard`)

1. Sign in at `/sign-in` (any demo account, password `password`).
2. You land on `/dashboard`. It auto-redirects to YOUR role's page:
   - HR officer → `/dashboard/hr`
   - Finance → `/dashboard/finance`
   - Production manager / VP → `/dashboard/plant-manager`
   - PPC head → `/dashboard/ppc`
   - Purchasing → `/dashboard/purchasing`
   - Warehouse → `/dashboard/warehouse`
   - QC inspector → `/dashboard/quality`
3. A plain dashboard with widgets also exists at `/dashboard/default`.
4. Company scorecard (KPIs, all signed-in users): `/dashboard/scorecard`.
5. What you see depends on permission, never on your name.
   Without the dashboard permission you get a 403 page.
6. Admin account (`admin@ogami.test`) can open every dashboard.

## 2. Approval Queue (`/approvals`) — the signing board

1. Open `/approvals` (sidebar: **Approval Queue** under Overview).
2. The board shows cards grouped by status (Pending, Approved,
   Rejected). The header counts come from the server summary.
3. EVERY signed-in role can open the board, but each person only
   sees cards for THEIR approval step (Finance sees Finance-step
   PRs/POs; the VP sees VP-step items; a department head sees
   only their department's leave/loan cards).
4. Click a card to open the underlying record
   (PR, PO, leave, overtime, loan, return, budget).
5. Approve or Reject on the record itself with the **Approve** /
   **Reject** buttons (and **Confirm reject** + reason where asked).
6. Counts on the sidebar (**Approval Queue** badge) show your
   pending items. It updates as work is approved.

## 3. Action Center (`/action-center`) — your personal to-do list

1. Open `/action-center` (sidebar: **Action Center** under Overview).
2. This is your personal work queue: approvals due, exceptions
   assigned to you, expiring items.
3. EVERY role can open it, but the rows are filtered to YOU — you
   never see another employee's private tasks.
4. Click a row to jump to the record that needs action.
5. After acting (approve, sign, upload), return to
   `/action-center` and refresh — the row should be gone.

## 4. Global search (the top Search box / Ctrl+K)

1. Click the **Search…** box at the top of the screen,
   or press Ctrl+K (Windows) / Cmd+K (Mac).
2. A palette opens with the hint
   **"Search pages, employees, orders, vendors, items, NCRs…"**.
3. It has two parts:
   - **Go to** — jumps to pages (same menu as the sidebar;
     only pages you may open are listed).
   - **Record search** — finds employees, orders, vendors,
     items, NCRs, budgets, and budget transfers by number or name.
4. Results reuse each module's own visibility rules:
   a buyer searching finds POs/vendors/RFQs;
   search never shows records the module list itself would hide.
5. Who does NOT get search: `employee@`, `driver@`,
   `warehouse@`, `qc@`, `impex@` do NOT hold search permission.
   They see **"Search is not available to your account"** instead.

## 5. Notifications (`/notifications` + the bell)

1. The bell icon (top bar) shows unread notifications with a count.
2. Click the bell for the latest items; click through to open
   **Notifications** (`/notifications`, full list).
3. EVERY role has own-notifications-only — nobody reads another
   person's inbox.
4. Notification preferences: open
   `/self-service/notification-preferences`.
5. Company calendar (leave, holidays, schedules in one view):
   `/calendar`.

## Who sees what — cheat sheet

| Page | URL | Who can open it |
|------|-----|-----------------|
| Dashboard (auto) | `/dashboard` | everyone (redirects by role) |
| HR dashboard | `/dashboard/hr` | hr_officer (+ admin) |
| Finance dashboard | `/dashboard/finance` | finance_officer (+ admin) |
| Plant dashboard | `/dashboard/plant-manager` | production_manager, vice_president (+ admin) |
| PPC dashboard | `/dashboard/ppc` | ppc_head (+ admin) |
| Purchasing dashboard | `/dashboard/purchasing` | purchasing_officer (+ admin) |
| Warehouse dashboard | `/dashboard/warehouse` | warehouse_staff (+ admin) |
| QC dashboard | `/dashboard/quality` | qc_inspector (+ admin) |
| Approval Queue | `/approvals` | everyone (rows scoped to own steps) |
| Action Center | `/action-center` | everyone (rows scoped to self) |
| Search | top **Search…** box | all EXCEPT employee, driver, warehouse, qc, impex |
| Notifications | `/notifications` | everyone (own inbox only) |
| Calendar | `/calendar` | everyone |

## Try it (3-minute cross-check)

1. Sign in as `employee@ogami.test`. Open `/action-center`
   (works, only your items) and try search (blocked message).
2. Sign out. Sign in as `depthead@ogami.test`. Open `/approvals`
   (your team's leave/OT/loan cards only).
3. Sign out. Sign in as `admin@ogami.test`. Open `/approvals`
   and `/dashboard/scorecard` (full view).
