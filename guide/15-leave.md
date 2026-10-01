# Leave — Types, Request Flow, Approvals

Sign in at `/sign-in` (password: `password`).
Request as any employee; approve as `depthead@ogami.test`
(department step) then `hr@ogami.test` (HR step).

## 15.1 Leave types (what staff can file)

Seeded types, all paid:

| Code | Name | Yearly balance | Note |
|------|------|----------------|------|
| VL | Vacation Leave | 15 days | — |
| SL | Sick Leave | 15 days | needs document (med cert) |
| SIL | Service Incentive Leave | 5 days | convertible at year end |
| ML | Maternity Leave | 105 days | needs document |
| PL | Paternity Leave | 7 days | needs document |
| SPL | Solo Parent Leave | 7 days | needs document |
| VAWC | VAWC Leave | 10 days | needs document |
| SLW | Special Leave for Women | (see app) | needs document |

1. Where to see them: open `/hr/leaves`, click the types
   control (dialog title **Leave Types**).
   Managing types needs `leave.types.manage` (HR officer).
   (There is no separate types page — it is a modal on `/hr/leaves`.)
2. Balances reset yearly; the year-end conversion control is also
   a modal on `/hr/leaves`.
3. Calendar view of who's away: `/hr/leaves/calendar`.

## 15.2 Filing a leave (employee side)

1. Open `/hr/leaves/create` (or `/self-service/leaves`
   as a plain employee). Page title: **Request leave**,
   panel title: **Leave details**, back link **Leaves**.
2. Pick leave type, start date, end date, reason.
   Attach a document for SL/ML/PL/SPL/VAWC/SLW when asked.
3. Submit. You get a leave number like `LR-202604-0045`
   (format `LR-YYYYMM-NNNN`, monthly reset).
4. Track it: your request sits at status **pending_dept**
   (waiting on your department head).
5. Need to withdraw? Open the request (`/hr/leaves/:id`)
   and click **Cancel** (owner-only while pending/approved).
6. Permissions: `leave.view` + `leave.create`
   (every employee holds these via self-service).

## 15.3 Approving (two steps: dept, then HR)

Step 1 — department head (`depthead@ogami.test`):

1. Open `/hr/leaves`. Title: **Leave requests**,
   subtitle shows "N total · M awaiting approval".
2. Your team's requests sit in the **pending_dept** column.
   You see ONLY your department.
3. Click a row (`/hr/leaves/:id`) to read dates, balance,
   reason, documents.
4. Click **Approve** (confirm dialog **Approve leave request?**,
   confirm **Approve**) or **Reject** (dialog
   **Reject leave request** — a rejection reason is REQUIRED —
   then **Confirm reject**, while saving **Rejecting…**).
5. Approved requests move to **pending_hr** (waiting on HR).

Step 2 — HR officer (`hr@ogami.test`):

1. Open `/hr/leaves`. Requests awaiting you sit in
   the **pending_hr** column.
2. Same buttons: **Approve** / **Reject** with the same dialogs.
3. Final **Approve** sets status **approved** — balances deduct.
   **Reject** (either step) sets **rejected** with your reason shown
   to the employee.
4. Many at once: tick rows → **Approve selected** → confirm.
   The toast reports "Approved N of M…".

## 15.4 Permissions recap

- `leave.view`, `leave.create` — every role (file + see own).
- `leave.approve_dept` — department_head (+ admin).
- `leave.approve_hr` — hr_officer (+ admin).
- `leave.types.manage` — hr_officer (+ admin).
- A department head CANNOT do the HR step; HR CANNOT do
  the department step for another team. The submitter can
  never approve their own leave (maker≠checker).

## Try it (full loop in 5 minutes)

1. Sign in as `employee@ogami.test` (Manuel Cruz, PROD).
   File 1 day of Vacation Leave at `/self-service/leaves`.
2. Sign out. Sign in as `depthead@ogami.test` (PROD head).
   Open `/hr/leaves` → find it in **pending_dept** → **Approve**.
3. Sign out. Sign in as `hr@ogami.test`.
   Open `/hr/leaves` → find it in **pending_hr** → **Approve**.
4. Status is now **approved**. Open `/hr/leaves/calendar`
   and confirm the day is marked away.
5. (Bonus, shows the guard): file a leave AS `depthead@ogami.test`
   and try to approve it yourself — the dept-step Approve
   on your own request is refused (submitter cannot approve).
