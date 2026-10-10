# Production employee demo setup

Applied on October 8, 2026 to the `ogami` demo database, restricted to active employees in department `PROD`.

## Result

- Created 82 active Employee accounts using the previously approved demo password `password`.
- Preserved all three existing accounts, including their passwords and roles.
- Assigned 85 missing shifts using random employee order and balanced crew allocation: 29 day, 28 afternoon, and 28 night.
- Shift hours in Asia/Manila: 6:00 AM–2:00 PM, 2:00 PM–10:00 PM, and 10:00 PM–6:00 AM. Working days are Monday–Saturday.
- Assignments take effect on October 7 so the overnight crew can cover early October 8. Saturday night also covers early Sunday morning; this does not create a Sunday-starting shift.
- There are now 83 eligible Production workers. The production manager and department head retain their existing roles and remain excluded from worker selection.
- Attendance and work orders were not changed. Accounts outside Production were not changed.

## Verification

An assert-based operational check verified every new account's role, active state, password hash, and password history. It also compared existing accounts and work orders against the private snapshot and checked attendance and other departments' account counts.

A second dry run proposed zero account creations, zero shift assignments, and zero calendar changes. A new Employee account successfully logged in through the live API; access to the general work-order listing returned HTTP 403 as expected for that role.

Read-only planner previews succeeded for both `WO-202610-0002` and `WO-202610-0003`, with 27 eligible workers covering each proposed two-hour window. These previews did not save schedules or confirm work orders. Run the scheduler and reopen the work order confirmation to replace the previously saved conflict diagnostics.

## Repeating the setup

The existing script now accepts an exact department code and optional balanced random allocation. Without `--apply`, it previews changes.

```bash
docker exec ogami-api php scripts/employee-test-setup.php \
  --department=PROD --randomize \
  --floor-working-days=1,2,3,4,5,6 --office-working-days=1,2,3,4,5
```

The applied command additionally used `--apply --actor-email=admin@ogami.test`. The script refuses production environments and requires a demo or dedicated test database. Calendar writes require an active actor with `attendance.shifts.manage`.

## Private evidence and recovery

Evidence is under `api/storage/app/production-worker-setup-20261008/`, in a directory accessible only to its owner. `accounts.csv` contains employee names, login emails, roles, and shifts; it contains no passwords or password hashes. `before.json` is a private database snapshot and must not be committed or shared.

For recovery before follow-on use, identify new accounts and assignments by comparing the applied report against the snapshot. Deactivate only the newly created accounts and end only the new shift assignments through the existing administrative services. Preserve audit and password history. If work orders have subsequently used these workers, reconcile their assignments before ending their HR shifts. No recovery operation was run.

The shared password is for the approved demo test only; change it before real use. This task did not investigate why the earlier employee setup was absent from this database, modify attendance, or persist production schedules.
