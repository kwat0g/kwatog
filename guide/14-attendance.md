# Attendance — Shifts, DTR, Biometric Import, Overtime

Sign in at `/sign-in` (password: `password`).
Use `hr@ogami.test` for the full HR view.
Attendance pages live under `/hr/attendance/*`.

## 14.1 Shifts (`/hr/attendance/shifts`)

1. Open `/hr/attendance/shifts`. Title: **Shifts** with a count.
2. Search box reads **"Search name…"**.
3. Seeded shifts:
   - **Day Shift** 06:00–14:00 (default), break 30 min.
   - **Extended Day** 06:00–18:00, auto-overtime 4.0 h.
   - **Night Shift** 18:00–06:00 (night-differential hours).
   - **Office Hours** 08:00–17:00, break 60 min.
4. To add: click **Add shift**. Dialog title: **Add shift**
   (editing: **Edit shift**). Set start, end, break minutes,
   night/extended flags.
5. To remove: the dialog asks **Archive shift?** with an
   **Archive** button. Restoring asks **Restore shift?**.
6. Assign a whole department at once:
   open `/hr/attendance/shifts/assign`. Title: **Bulk assign shift**.
   Pick shift + department and confirm.
7. Permissions: `attendance.shifts.manage` to add/assign
   (HR officer holds it); the list also opens with `attendance.edit`.

## 14.2 Daily Time Records (`/hr/attendance`)

1. Open `/hr/attendance`. Title: **Daily Time Records**
   with a "N records" subtitle.
2. Each row = one employee + one day: time in, time out,
   late, undertime, night hours, overtime.
3. Empty state reads: "Import a biometric CSV to get started."
4. To fix or add a row manually: use the record dialog —
   titles read **Add manual attendance** (new) or
   **Correct attendance record** (edit). Needs `attendance.edit`.
5. To load machine data: click **Import DTR**.
   It jumps to `/hr/attendance/import` (next section).
6. Own view for every employee: `/self-service/dtr`
   (any signed-in user sees ONLY their own days there).
7. Permissions: the HR board needs one of `attendance.edit`,
   `attendance.import`, or `attendance.ot.approve`.

## 14.3 Biometric CSV import (`/hr/attendance/import`)

1. Open `/hr/attendance/import`. Title: **Import biometric DTR**,
   subtitle: **"CSV columns required: employee_no, date,
   time_in, time_out"**.
2. Prepare the CSV with exactly those four columns, for example:
   `DEMO-0001,2026-09-01,06:00,14:00`
   (employee numbers are the `DEMO-0000…` series).
3. Drop the file on **"Drop CSV here, or click to browse"**,
   or click **Browse files**. Only `.csv` files are accepted.
4. Click **Import** (while uploading it reads **Uploading…**).
   **Cancel** goes back to `/hr/attendance` without importing.
5. Result panel **Import summary** shows Total / Imported /
   Skipped. Skipped rows list their errors — fix the CSV
   and import again.
6. After import, open `/hr/attendance` and search the employee
   number to see the new day rows.
7. Permission: `attendance.import` (HR officer holds it;
   department heads and plain employees do NOT).

## 14.4 Overtime — request and approve

Request (employee side):

1. As any employee, open `/self-service/overtime` to file,
   or (HR) open `/hr/attendance/overtime/create`.
   Title: **New overtime request**, panel **Request details**.
2. Pick date, hours, reason. Rules: minimum 30 minutes,
   maximum 4 hours; night work 10PM–6AM earns +10%.
   Extended Day shift (6AM–6PM) auto-creates its 4 h.
3. Submit and note the request number for tracking.

Approve (boss side):

1. As `depthead@ogami.test` or `hr@ogami.test`, open
   `/hr/attendance/overtime`. Requests group by status
   (Pending first).
2. Each pending row has **Approve** and **Reject** buttons.
3. **Approve** opens a confirm dialog (**Approve overtime
   request?**, confirm **Approve**). **Reject** opens
   **Reject overtime request** — type a reason, then
   **Confirm reject** (while saving: **Rejecting…**).
4. Many at once: tick rows, click **Approve selected**,
   confirm **Approve all**. The toast reports
   "Approved N of M…" with any failures listed.
5. Open one record at `/hr/attendance/overtime/:id`
   for the full detail + history.
6. Permission: requesting needs self-service access;
   approving needs `attendance.ot.approve`
   (HR officer + department head hold it).

## Try it (10-minute attendance loop)

1. As `hr@ogami.test`: `/hr/attendance/shifts` → open Day Shift.
2. `/hr/attendance/import` → import a 2-line CSV for `DEMO-0001`.
3. `/hr/attendance` → search `DEMO-0001` → confirm the rows.
4. As `employee@ogami.test`: `/self-service/overtime` → file 2 h.
5. As `depthead@ogami.test`: `/hr/attendance/overtime` →
   find it → **Approve**. Done — the hours flow to payroll.
