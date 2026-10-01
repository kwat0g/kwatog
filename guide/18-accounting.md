# Accounting — COA, Journals, Bills, Invoices, Reports

## What is this?

Accounting is the money ledger: the chart of accounts, journal entries,
supplier bills (we pay), customer invoices (they pay us), the two main
reports, and the monthly locks (periods) inside each fiscal year.

## Before you start

1. Go to `/sign-in`.
2. Log in as Finance:
   - Email: `finance@ogami.test`
   - Password: `password`
3. For second-person checks you need two more logins (password: `password`):
   - `finance2@ogami.test` — the checker (second Finance officer).
   - `vp@ogami.test` — approves bill payments.

## Part A — Chart of Accounts, COA (`/accounting/coa`)

The COA is the list of every ledger account (cash, payables, expenses…).

1. Go to `/accounting/coa`.
2. Browse by type: assets, liabilities, equity, income, expenses.
3. To add one, click **Add account**.
4. On "New account" (`/accounting/coa/create`):
   pick type, code, name, and parent (if it is a sub-account).
5. Click **Create account**.
6. Rule: entries post only to leaf accounts (accounts with no children).

## Part B — Journal entries: maker then checker (`/accounting/journal-entries`)

Money moves by balanced entries: total debits must equal total credits.

1. As `finance@ogami.test`, go to `/accounting/journal-entries`.
2. Click **New entry**.
3. On "New journal entry" (`/accounting/journal-entries/create`):
   add at least two lines (a debit line and a credit line),
   each with an account and amount. The page shows whether it balances.
4. Save. The entry is a **draft** — balances are NOT yet updated.
5. Open it (`/accounting/journal-entries/<id>`).
6. Maker-checker rule: **the person who created the entry cannot post it.**
7. Log out. Log in as `finance2@ogami.test`.
8. Reopen the entry and click **Post**
   (popup: "Post journal entry?" → **Post**).
9. Balances update. Posted entries cannot be edited.
10. A wrong-but-posted entry is fixed with **Reverse**
    (creates an opposite entry; the original stays for audit).
11. **Print** downloads the entry as PDF.

## Part C — Supplier bills + the 3-way match (`/accounting/bills`)

A bill is paid only if three documents agree:
PO quantity/price vs GRN received vs supplier invoice (the 3-way match).

1. As `finance@ogami.test`, go to `/accounting/bills`.
2. Click **New bill**, fill vendor + PO link + amounts
   (page title: "New bill"), then click **Create bill**.
3. Open the bill (`/accounting/bills/<id>`).
4. Read the **3-way match** panel / **Matched** chip:
   green means PO, GRN and invoice agree.
5. If the match fails, the bill cannot be posted until someone with the
   override permission clears it.
6. Click **Post bill** (confirm: **Post bill**).
7. To pay, click **Record payment**, enter amount, date, method.
8. The payment waits for approval: as `vp@ogami.test`, open the bill
   and click **Approve** on the payment line.

## Part D — Customer invoices + collections (`/accounting/invoices`)

1. As `finance@ogami.test`, go to `/accounting/invoices`.
2. Click **New invoice** (page title: "New invoice"),
   pick the customer and delivered lines, save.
3. Open the invoice (`/accounting/invoices/<id>`).
4. When the customer's check arrives, click **Record collection**.
5. Fill collection date, amount, payment method; click **Record**.
6. The invoice balance and Collected totals update.

## Part E — The two main reports

1. Income statement (profit/loss): `/accounting/income-statement`.
2. Balance sheet (assets = liabilities + equity): `/accounting/balance-sheet`.
3. Both read the POSTED entries only — drafts never appear.
4. Sidebar keeps both under Finance next to the invoice/bill pages.

## Part F — Fiscal years vs accounting periods (do not confuse)

They are two different locks:

- **Fiscal year** = the whole year (FY2026, currently active).
  Every budget, and the year dropdown on reports, points at it.
- **Accounting period** = one month inside the year.
  Months close one by one at `/accounting/periods`:
  1. Go to `/accounting/periods`.
  2. To lock this month, click **Close current month**
     (row button: **Close**; popup → **Close period**).
  3. Closed months reject new entries; to fix one, click **Reopen**
     and type the audited reason.

## Who can do what (cheat sheet)

- `finance@ogami.test` — create bills/invoices/entries, post bills,
  record payments and collections, close periods.
- `finance2@ogami.test` — posts entries finance@ created (the checker).
- `vp@ogami.test` — approves bill payments; views statements.
- No single person creates AND posts the same journal entry.
