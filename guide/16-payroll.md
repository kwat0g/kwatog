# Payroll — Periods, Compute, Approve, Payslips, Adjustments, 13th Month

**What this module does:** pays every employee twice a month,
then proves the money is correct before anyone gets paid.

**Password for ALL demo accounts:** `password`

| Who | Email | Job in payroll |
|---|---|---|
| HR Officer (maker) | `hr@ogami.test` | Creates the period, clicks Compute |
| Finance Officer (checker) | `finance@ogami.test` | Approves, finalizes, downloads bank file |
| Second Finance checker | `finance2@ogami.test` | Backup approver |
| Employee (viewer only) | `employee@ogami.test` | Sees own payslip only |

**Maker / checker in plain words:** HR *prepares* the payroll.
Finance *checks* it. The person who computed a run cannot approve
their own work. Two sets of eyes on every peso before it leaves.

## 1. What "1st half / 2nd half" (H1 / H2) means

1. The company pays **semi-monthly**: two cutoffs per month.
2. Days **1–15** = 1st half. Government deductions (SSS, PhilHealth,
   Pag-IBIG, tax) are taken on this run only.
3. Days **16–end of month** = 2nd half. No government deductions.
4. You never pick the half yourself. The system reads it from the
   dates you type. A cutoff must stay inside one half of one month
   (you cannot make Aug 10–20).

## 2. Log in as HR

1. Go to `/sign-in`.
2. Type email `hr@ogami.test` and password `password`.
3. Click **"Sign in"**.
4. You land on the dashboard.

## 3. Open the payroll list

1. In the left sidebar, open **Human Resources**.
2. Click **Payroll Processing**.
3. You are now at `/payroll/periods`.
4. Each row shows the cutoff dates, the Half chip
   (`1st` or `2nd`), peso totals, and a status chip
   (`Draft`, `Computed`, `Approved`, `Finalized`).

## 4. Create a new period (HR does this)

1. On `/payroll/periods`, click **"New Period"**.
2. You are now at `/payroll/periods/create`.
3. Fill in **Period start** (example: 1st of the month).
4. Fill in **Period end** (example: 15th of the month).
5. Fill in **Payroll date** (the day the pay is for).
6. Read the **Cycle** box. It tells you `1st half` or `2nd half`.
   If it shows red text, your dates cross a boundary — fix them.
7. Leave all checkboxes empty to pay the whole company.
8. Read the **Preview** box: headcount and estimated gross.
9. Click **"Create period"**.
10. You land on the new period detail page (`/payroll/periods/<id>`).
11. Status is now `Draft`.

## 5. Compute the payroll (HR does this)

1. Open your `Draft` period at `/payroll/periods/<id>`.
2. Click **"Compute"**.
3. Wait. The page shows **Computing…** with a progress bar.
4. When it finishes, status becomes `Computed`.
5. Click the **Employees** tab. Check gross, deductions, and net pay.
6. Click any employee row to open their computation
   (`/payroll/periods/<id>/employee/<eid>`).
7. Open the **Failures** tab. Empty = every employee computed cleanly.
8. Open the **Anomaly review** tab. Resolve any red flags.
9. If numbers look wrong, click **"Recompute"** and confirm.
   This replaces all rows, so it asks first.

## 6. Approve the payroll (Finance does this — different person)

1. Log out. Go to `/sign-in`.
2. Log in as `finance@ogami.test` (password `password`).
3. Go to `/payroll/periods` and open the `Computed` period.
4. Check the totals in the confirmation box.
5. Click **"Approve"**, then click **"Approve"** again to confirm.
6. Status becomes `Approved`.
7. If something is wrong instead, click **"Request Correction"**,
   type a reason, and HR must fix and recompute (step 5 again).

## 7. Finalize the payroll (Finance does this — point of no return)

1. Still as `finance@ogami.test`, on the `Approved` period,
   click **"Finalize"**, then **"Finalize"** again to confirm.
2. Status becomes `Finalized`. Payslips are generated.
3. A finalized period is **never reopened**. Any later fix goes
   through Adjustments (section 10), never by editing this period.
4. Optional: click **"Mark as Disbursed"**, then **"Mark Disbursed"**,
   only after the money has actually left the company account.

## 8. Where employees see their payslip

1. Log in as `employee@ogami.test` (password `password`).
2. Go to `/self-service/payslips`.
3. You see **My Payslips** — only your own rows, nobody else's.
4. Click any row. The payslip PDF opens in a new tab.
5. If the list is empty, payroll has not been finalized yet.

## 9. Bank file (Finance, after Finalize)

1. As `finance@ogami.test`, open the `Finalized` period.
2. Find the bank-file area on the detail page.
3. Choose the bank format from the **Bank format** dropdown.
4. Click **"Generate & download"**.
5. The CSV file downloads. Send it to the bank.
6. If the page warns that some employees have no bank account,
   add their bank details in HR first, then generate again.
7. Optional proof step: upload the bank deposit slip, then finish
   with **"Mark as Disbursed"**.

## 10. Fixing a finalized payroll — Adjustments (next period, never edit)

1. Go to `/payroll/adjustments`.
2. Click **"Raise adjustment"** (you are at
   `/payroll/adjustments/create`).
3. In **Find source payroll**, search the employee name or number.
4. In **Source payroll**, pick the finalized payroll row.
5. Pick the **Type**, type the **Amount** in pesos.
6. In **Reason**, explain what was wrong (minimum 5 characters).
7. Click **"Submit adjustment"**.
8. An approver opens `/payroll/adjustments` and clicks
   **"Approve"** (or **"Reject"** with **"Confirm reject"**).
9. Once approved, it applies automatically to the next
   non-finalized period. Nothing in the old period changes.

## 11. Government reports — Statutory exports

1. As `hr@ogami.test` or `finance@ogami.test`,
   go to `/payroll/statutory`.
2. Set the **Year** and **Month** under Filing period.
3. Click one of the file cards to download it:
   **"BIR 1601-C"**, **"PhilHealth RF-1"**,
   **"Pag-IBIG MCRF"**, **"BIR 1604-CF"**,
   **"BIR 2316 Alphalist"**.
4. Each card downloads its CSV / extract for that month or year.

## 12. 13th month pay (once a year)

1. As `hr@ogami.test`, go to `/payroll/periods`.
2. Click **"Run 13th Month"**.
3. A box titled **Run 13th Month Pay** opens.
4. Type the **Year** (example: 2026).
5. Click **"Create period"**.
6. A special period with a **13th Month** chip appears.
   It computes like a normal run, then Finance approves
   and finalizes it the same way (sections 6–7).

## 13. Quick demo script (5 minutes, in order)

1. `/sign-in` as `hr@ogami.test` → `/payroll/periods` →
   **"New Period"** → fill dates → **"Create period"**.
2. Click **"Compute"**, wait for `Computed`.
3. Log in as `finance@ogami.test` → **"Approve"** → confirm.
4. Click **"Finalize"** → confirm → **"Generate & download"**.
5. Log in as `employee@ogami.test` → `/self-service/payslips` →
   click a row, show the PDF.
