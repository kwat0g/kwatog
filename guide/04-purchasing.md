# Purchasing — PRs, RFQs, POs

## What is this?

Purchasing turns a department's need into goods ordered from a supplier:
**Purchase Request (PR) → approval → RFQ quotes → Purchase Order (PO).**

## Before you start

1. Go to `/sign-in`.
2. You will use these logins (password for all: `password`):
   - `depthead@ogami.test` — creates PRs (department head).
   - `finance@ogami.test` — approval step 1 (Finance officer).
   - `vp@ogami.test` — approval step 2 (Vice President).
   - `purchasing@ogami.test` — the buyer (RFQs, POs).

## Part A — Create a PR (as the department head)

1. Log in as `depthead@ogami.test`.
2. Go to `/purchasing/purchase-requests`.
3. Click **New PR**.
4. On the "New purchase request" form
   (`/purchasing/purchase-requests/create`):
   - Pick your **department** (this decides whose budget is checked).
   - Add at least one item line: item, quantity, estimated price.
   - Write a clear **reason**.
5. Click **Submit for approval** (popup → **Submit**).
6. Open the PR (`/purchasing/purchase-requests/<id>`).
   You see a **Department budget** panel: allocated, available, % used.

## Part B — Approve a PR: Finance, then VP (amounts ≥ ₱50,000)

Big PRs need TWO approvals in order: Finance first, VP last.

1. Log in as `finance@ogami.test`.
2. Open the submitted PR (or find it in `/approvals`).
3. Click **Approve** (popup → **Approve**).
4. Log out. Log in as `vp@ogami.test`.
5. Open the same PR. Click **Approve** again.
6. The PR status becomes approved.
7. To refuse instead, click **Reject** and type a rejection reason.

## Part C — Live demo script with the two demo PRs

1. As anyone with purchasing view, go to `/purchasing/purchase-requests`.
2. Search for **PR-DEMO-BUDGET** and open it.
3. It totals ₱500,000, so both approval steps apply.
4. Step 1 (Finance) is already done. The pending step is the VP's.
5. Log in as `vp@ogami.test` and click **Approve** on it.
6. Now open **PR-DEMO-CONVERT** (status: approved).
7. This one demonstrates the next stage: turning an approved PR into a PO.

## Part D — RFQ: compare quotes and award (the centerpiece)

1. Log in as `purchasing@ogami.test`.
2. Go to `/purchasing/rfqs`.
3. Open the RFQ titled **"DEMO Resin RFQ — quotable, ready to award"**
   (number like `RFQ-202610-0001`; if you re-seeded, look for the
   title starting with `DEMO` — numbers are system-generated).
4. On the detail page (`/purchasing/rfqs/<id>`) you see status **open**
   and two submitted quotes: **₱950** and **₱1,020**.
5. Open the comparison view and pick the winning supplier per line.
6. Click **Award & create draft POs** (busy text: "Awarding…").
7. Success message: "Awarded. N draft purchase order(s) created."
8. The new draft POs appear under `/purchasing/purchase-orders`.
9. Related buttons you may see: **Publish** (sends an RFQ to suppliers),
   **New RFQ** (starts one from `/purchasing/rfqs/create`).

## Part E — PO from an approved PR

There is no standalone "new PO" page — every PO is born from a PR.

1. As `purchasing@ogami.test`, open approved **PR-DEMO-CONVERT**.
2. Click **Convert to PO**.
3. A draft PO is created (one per vendor).
4. Open it under `/purchasing/purchase-orders` to review lines and totals.
5. The PO follows its own Finance → VP approval (same ≥ ₱50,000 rule),
   then it can be sent to the supplier.

## Part F — What the "Finance acknowledge" button means

1. Open **PR-DEMO-BUDGET**. Notice the **Budget critical** warning box:
   the Maintenance budget is ~98% spent, so this PR exceeds it.
2. When a PR/PO is over budget (levels `exhausted`/`overdrawn`),
   approvals pause until Finance signs off on the overrun.
3. Finance clicks **Finance acknowledge** on the PR or PO.
4. The box changes to a **Finance acknowledged** chip,
   and the approval chain may proceed.
5. Meaning in one sentence: **"Finance has seen the overrun and
   accepts responsibility, so the purchase may continue."**

## Who can do what (cheat sheet)

- `depthead@ogami.test` — create PRs, view PRs.
- `finance@ogami.test` — approve PRs/POs (step 1), acknowledge overruns.
- `vp@ogami.test` — final approval (step 2, ≥ ₱50,000).
- `purchasing@ogami.test` — run RFQs, convert PRs to POs.
