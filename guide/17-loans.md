# Loans & Cash Advances — Borrow With Zero Interest

**What this module does:** lets an employee borrow from the company
and repay through automatic salary deductions — with zero interest.

**Password for ALL demo accounts:** `password`

| Who | Email | Job in this flow |
|---|---|---|
| Employee (requester) | `employee@ogami.test` | Files the request |
| Department Head (step 1) | `depthead@ogami.test` | First approval |
| Manager (company-loan step 2 only) | `production@ogami.test` | Second approval for company loans |
| Finance Officer | `finance@ogami.test` | Reviews the money |
| Vice President | `vp@ogami.test` | Final approval |

**The two loan types and their chains:**

- **Cash advance** — `Dept Head → Accounting → VP` (3 steps).
- **Company loan** — `Dept Head → Manager → Accounting → VP` (4 steps).
- HR (`hr@ogami.test`) can file and view loans but is **not**
  an approver in either chain.

## 1. The three rules (say these out loud in the demo)

1. **Zero interest.** The employee repays exactly what they borrowed,
   split into equal monthly deductions.
2. **One at a time.** An employee can hold at most one active
   company loan and one active cash advance. A second request of
   the same type is blocked while the first is still being repaid.
3. **Auto-deduction.** Once approved, every payroll Compute
   automatically deducts the monthly amortization. Nobody types
   the deduction by hand.

## 2. File a request as the employee (self-service)

1. Go to `/sign-in`.
2. Log in as `employee@ogami.test` (password `password`).
3. Go to `/self-service/loans`.
4. You see **Loans & Cash Advances** with **Active** and
   **History** sections.
5. Click **"Apply for a loan"**.
6. A box titled **Apply for a Loan** opens.
7. In **Type**, pick the loan type (Company Loan or Cash Advance).
8. In **Amount**, type the pesos (example: 10000).
9. In **Periods (months)**, type the repayment months (example: 5).
10. Read the **Estimated monthly deduction** preview box.
11. Optionally type a **Reason**.
12. Click **"Submit request"**.
13. The request appears under Active with status `Pending`.
14. To take it back before approval, click **"Withdraw"**,
    then **"Yes, withdraw"**.

## 3. File a request as HR (for someone else)

1. Log in as `hr@ogami.test` (password `password`).
2. Go to `/hr/loans` (type the address directly — this page has
   no sidebar entry).
3. The page title is **Loans & Cash Advance**.
4. Click **"New request"** (you are at `/hr/loans/create`,
   titled **New loan request**).
5. Under **Loan type**, click the radio for the type you want.
6. In **Find employee**, type the employee name or number.
7. In **Employee**, pick the employee from the dropdown.
8. Below it, read **Max principal** — the cap for this person.
   If it says the employee already has an active loan of this
   type, stop: the one-at-a-time rule blocks you.
9. In **Principal**, type the amount in pesos.
10. In **Pay periods**, type the number of months.
11. Optionally type a **Purpose**.
12. Read the **Amortization preview** table (equal monthly amounts).
13. Click **"Submit request"**. Or click **"Cancel"** to abandon.
14. You land on the loan detail page (`/hr/loans/<id>`).

## 4. Approve as Department Head (step 1)

1. Log in as `depthead@ogami.test` (password `password`).
2. Go to `/hr/loans` and click the pending loan row.
3. Read the **Loan summary**: Principal, Balance, Repayment months,
   Monthly amortization, Interest rate (0%), and the
   **Approval chain** panel.
4. Click **"Approve"**, then **"Approve"** again to confirm.
5. To refuse instead: click **"Reject"**, type the reason,
   click **"Confirm reject"**.
6. The request moves to the next step. One approval is never enough —
   the chain needs every step below.

## 5. Approve a company loan as Manager (step 2, company loans only)

1. Log in as `production@ogami.test` (password `password`).
2. Open `/hr/loans`, click the same loan row.
3. Click **"Approve"** and confirm.
4. Cash advances skip this step entirely — they go straight
   from the Department Head to Finance.

## 6. Review as Finance (second-to-last step)

1. Log in as `finance@ogami.test` (password `password`).
2. Open `/hr/loans`, click the loan row.
3. Check Principal, Monthly amortization, and repayment months.
4. Click **"Approve"** and confirm.
5. The loan is now waiting only for the Vice President.

## 7. Final approval as VP (last step — money moves here)

1. Log in as `vp@ogami.test` (password `password`).
2. Open `/hr/loans`, click the loan row.
3. Click **"Approve"** and confirm.
4. Status becomes approved/active with a monthly amortization.
5. The **Payments** table on the same page is still empty —
   rows appear here as payroll deducts each month.

## 8. Watch the auto-deduction in payroll

1. Log in as `hr@ogami.test`.
2. Run the next payroll Compute at `/payroll/periods`.
3. Open the borrower's row
   (`/payroll/periods/<id>/employee/<eid>`).
4. The loan amortization appears as a deduction line.
   HR never typed it — Compute applied it automatically.
5. Back on `/hr/loans/<id>`, the **Balance** is smaller and
   the **Payments** table has a new row.
6. Repeat each cutoff until Balance reaches zero.

## 9. Cancel a request that has not been approved yet

1. Open `/hr/loans/<id>` as HR or as the requester.
2. Click **"Cancel"**.
3. Confirm with **"Yes, cancel"**.
4. Cancelled requests stay in History for the audit trail.

## 10. Quick demo script (5 minutes, in order)

1. `/sign-in` as `employee@ogami.test` → `/self-service/loans` →
   **"Apply for a loan"** → fill amount + months →
   **"Submit request"**.
2. Log in as `depthead@ogami.test` → `/hr/loans` → open it →
   **"Approve"**.
3. (Company loan only) Log in as `production@ogami.test` →
   **"Approve"**.
4. Log in as `finance@ogami.test` → **"Approve"**.
5. Log in as `vp@ogami.test` → **"Approve"** → show the
   amortization schedule and the 0% interest line.
6. Say the three rules: zero interest, one at a time,
   auto-deducted in payroll.
