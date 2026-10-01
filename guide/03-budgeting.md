# Budgeting — Allocation, Transfers & Reports

## What is this?

A budget is your department's spending plan for the year.
Finance creates budgets, the VP approves them, money can be moved
between lines (transfers), and plan-vs-actual shows where the money went.

## Before you start

1. Go to `/sign-in`.
2. Log in as a Finance officer:
   - Email: `finance@ogami.test`
   - Password: `password`
3. For approvals you will also use the Vice President:
   - Email: `vp@ogami.test`
   - Password: `password`
4. Make sure the Fiscal Year dropdown shows **FY2026 (active)**.
   All demo budgets below belong to FY2026.

## Part A — See all budgets (`/budgeting`)

1. In the sidebar, under Finance, click **Budgeting** (`/budgeting`).
2. At the top, confirm the Fiscal Year box says FY2026.
3. You will see three live department budgets (all active):
   - **Production Operating Budget**
   - **Finance & Admin Budget**
   - **Maintenance Budget** (almost fully spent — this matters later)
4. You will also see two demo budgets:
   - **DEMO Draft Budget — submit me** (status: draft)
   - **DEMO Submitted Budget — approve me** (status: submitted)
5. Click any budget name to open its detail page (`/budgeting/<id>`).

## Part B — Create a budget with the monthly grid (`/budgeting/create`)

1. On `/budgeting`, click **Create Budget**.
2. In **Fiscal Year**, pick FY2026.
3. Type a **Name** (example: `Panel Test Budget`).
4. Pick a **Department** and **Budget type** (operating).
5. In the monthly grid, type amounts per account per month
   (Jan through Dec).
6. Only leaf expense accounts are allowed (the page blocks the rest).
7. Click **Create Budget** to save as a draft.
8. To change a draft later, open it and click **Edit**, then
   **Save Draft**. Click **Cancel** to leave without saving.

## Part C — Submit, then approve (the click-through script)

Finance submits. The VP approves. Two different people, two logins.

1. As `finance@ogami.test`, open **DEMO Draft Budget — submit me**.
2. Click **Submit for Approval**.
3. In the popup titled "Submit budget for approval?", click **Submit**.
4. The budget status changes from draft to submitted.
5. Log out. Log in as `vp@ogami.test` (password: `password`).
6. Open **DEMO Submitted Budget — approve me**.
7. Click **Approve**.
8. In the popup titled "Approve budget?", click **Approve**.
9. The budget becomes approved/active and its money can now be spent.

## Part D — Reject back to draft

1. As `vp@ogami.test`, open any **submitted** budget.
2. Click **Return to draft**.
3. Type a reason (minimum 5 characters) and confirm.
4. The budget goes back to draft so Finance can fix and re-submit it.
   The reason is recorded in the Approval History panel.

## Part E — Delete a draft / Close a budget

1. As `finance@ogami.test`, open a **draft** budget.
2. Click **Delete**, confirm **Delete draft** — the draft and its
   lines are permanently removed. Only drafts can be deleted.
3. To end an **active/approved** budget, open it and click **Close**,
   confirm **Close**. Rule to remember: **closed budgets are read-only.**
   To adjust one, create a supplemental budget for the same department.

## Part F — Transfers: request and approve (`/budgeting/transfers`)

A transfer moves money from one budget line to another in one month.

1. Go to `/budgeting/transfers` (or click **Transfers** on `/budgeting`).
2. Find the pending demo transfer:
   **"DEMO transfer — approve or reject me." (feb, ₱25)**.
3. To request a NEW transfer, click **Request Transfer**
   (or go to `/budgeting/transfers/create`).
4. Pick **Fiscal Year**, the from-budget/line, the to-budget/line,
   the **month**, the **amount**, and a **reason** (min 5 characters).
5. Click **Request Transfer**. The transfer waits as pending.
6. As `vp@ogami.test`, on the pending row click **Approve**
   (money moves), or **Reject** then **Reject** in the
   "Reject this transfer?" popup (nothing moves).
7. Rule: the requester — and anyone sharing the requester's role —
   cannot approve. Finance requests, VP approves.

## Part G — Budget vs actual (`/budgeting/budget-vs-actual`)

1. Go to `/budgeting/budget-vs-actual`.
2. Pick FY2026. You see Budgeted vs Actual per account,
   variance, and a line-item table.
3. If numbers look stale, click **Rebuild actuals** and wait.

## Important note for spenders

Department spenders do **not** open these budgeting pages
(they hold no budgeting permission). They see their department's
position — allocated, available, % used — **inside the Purchase
Request detail** (`/purchasing/purchase-requests/<id>`), as a
"Department budget" panel next to any budget warning.

## Who can do what (cheat sheet)

- `finance@ogami.test` — create, edit, submit, close, delete drafts,
  request transfers, acknowledge PR/PO overruns.
- `vp@ogami.test` — approve/reject budgets and transfers only.
- Everyone else — read-only or no access.
