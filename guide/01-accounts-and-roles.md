# Demo Accounts and Roles

## Password (same for everyone)

Password for ALL demo accounts: `password`

Sign in at `/sign-in` with the email + `password`.

## Every demo account

| # | Email | Name | Role | Department |
|---|-------|------|------|------------|
| 1 | admin@ogami.test | System Administrator | system_admin | IT |
| 2 | hr@ogami.test | Maria Santos | hr_officer | HR |
| 3 | finance@ogami.test | Ana Reyes | finance_officer | FIN |
| 4 | finance2@ogami.test | Liza Gomez | finance_officer | FIN |
| 5 | production@ogami.test | Ricardo Tanaka | production_manager | PROD |
| 6 | ppc@ogami.test | Pedro Garcia | ppc_head | PPC |
| 7 | purchasing@ogami.test | Elena Cruz | purchasing_officer | PUR |
| 8 | buyer2@ogami.test | Marco Dela Rosa | purchasing_officer | PUR |
| 9 | vp@ogami.test | Kenji Watanabe | vice_president | EXEC |
| 10 | employee@ogami.test | Manuel Cruz | employee | PROD |
| 11 | crm@ogami.test | Sara Sales | sales_officer | SALES |
| 12 | warehouse@ogami.test | Carlos Mendoza | warehouse_staff | WH |
| 13 | qc@ogami.test | Rosa Villareal | qc_inspector | QC |
| 14 | maintenance@ogami.test | Juan Bautista | maintenance_tech | MAINT |
| 15 | impex@ogami.test | Lisa Yamamoto | impex_officer | IMPEX |
| 16 | depthead@ogami.test | Roberto Santos | department_head | PROD |
| 17 | driver@ogami.test | Nestor Flores | driver | WH |
| 18 | customerservice@ogami.test | Maya Customer Service | customer_service_officer | CS |

Outsiders (portals): `portal@supp.test` (supplier), `portal@cust.test`
(customer). Same password.

## Which account to use for which demo

1. Full tour, sees everything: **admin@ogami.test**.
2. HR tasks (hire, attendance, leave, payroll): **hr@ogami.test**.
3. Money approval, checker step: **finance@ogami.test**.
4. Second finance checker (maker-checker pair): **finance2@ogami.test**.
5. Final boss approval (PRs, POs, budgets, loans over the limit):
   **vp@ogami.test**.
6. Buying (requests, orders, suppliers): **purchasing@ogami.test**
   (second buyer: **buyer2@ogami.test**).
7. Approving your team's leave and overtime: **depthead@ogami.test**.
8. Plain employee, self-service only: **employee@ogami.test**.
9. Driver deliveries: **driver@ogami.test**.

## SoD rules in plain words (Segregation of Duties)

These stop one person from controlling money end-to-end:

1. **Maker is not checker.** The person who creates or computes
   something cannot be the one who approves it.
   Example: HR creates and computes a payroll run;
   Finance approves and finalizes it.
2. **The submitter cannot approve their own request.**
   Example: if Ana (finance@) submits a budget, Ana cannot click
   Approve on it — a different person must.
3. **Purchase approvals are money-only, in two steps.**
   Purchase Requests: Finance first, then the Vice President
   (for amounts of ₱50,000 and above the VP step is required).
   The buyer creates the order but is NOT an approval step.
4. **Budgets and transfers: same role cannot approve twice.**
   Finance prepares and submits the budget;
   the Vice President approves it.
   One role cannot sign both sides of the same budget or transfer.
5. **Journal entries: drafter cannot post.**
   Drafting a journal entry and posting it to the ledger are
   separate permissions. Same person cannot do both alone.
6. **Loans need two different bosses.**
   A loan passes the department head, then a manager,
   then Finance/VP — never one approver twice.
7. **Admin (IT) is not a business approver.**
   `admin@ogami.test` can open everything for demo purposes,
   but real approval chains end at the Vice President,
   never at the IT administrator.

## What each role can and cannot do (HR-area summary)

- **hr_officer** (`hr@`): manages employees, departments, positions,
  attendance, leave (HR step), separations, recruitment, loans;
  creates + computes payroll but CANNOT approve it.
- **finance_officer** (`finance@`, `finance2@`): approves + finalizes
  payroll, manages accounting/budgets, approves PRs/POs (step 1),
  signs Finance clearance items.
- **vice_president** (`vp@`): final approver — PRs, POs, budgets,
  loans, salary adjustments, asset disposals, bill payments.
  Reads what it signs.
- **department_head** (`depthead@`): creates purchase requests for
  their department; approves leave (department step), overtime,
  and loans (first step) for their department only.
- **employee** (`employee@`): self-service only — own DTR,
  own leave requests, own payslips.
- **driver** (`driver@`): only `/driver` (own deliveries)
  plus self-service. Nothing else.

## Key permission matrix (verified against the live seeded database)

Y = holds the permission, . = does not hold it.

| role | budgeting.view | budgeting.manage | budgeting.approve | purchasing.view | pr.create | pr.approve | po.create | po.approve | rfq.view | accounting.view | payroll compute | payroll approve | loans approve |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| system_admin | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y |
| finance_officer | Y | Y | Y | Y | . | Y | . | Y | Y | Y | . | Y | Y |
| vice_president | Y | . | Y | Y | . | Y | . | Y | Y | Y | . | . | Y |
| department_head | . | . | . | Y | Y | Y | . | . | . | . | . | . | Y |
| purchasing_officer | . | . | . | Y | Y | Y | Y | Y | Y | . | . | . | . |
| production_manager | . | . | . | Y | . | . | . | . | . | . | . | . | Y |
| hr_officer | . | . | . | . | . | . | . | . | . | . | Y | . | Y |
| employee | . | . | . | . | . | . | . | . | . | . | . | . | . |

Notes: spenders (department_head, purchasing_officer, production_manager)
hold zero `budgeting.*` — they see their department's position inside the
PR detail page instead. `department_head` holds a dormant `pr.approve`
(the chain has no department step); `purchasing_officer` holds approve
slugs but the chain steps name Finance/VP.

## Try it (maker-checker in 2 minutes)

1. Sign in as `purchasing@ogami.test` (password `password`).
2. Open `/purchasing/purchase-requests` and create a request.
3. Sign out, sign in as `finance@ogami.test`.
4. Open `/approvals`, find the request, click **Approve**.
5. Sign out, sign in as `vp@ogami.test`.
6. Open `/approvals`, give the final **Approve**.
7. (Optional, shows the guard): sign in as the person who
   submitted in step 2 and try to approve it — the system
   refuses, because submitter cannot approve.
