# Maintenance: non-admin end-to-end manual test

This run changes equipment status, creates work orders, moves inventory, and may post accounting entries. Run it first on a disposable local/test database. Treat `47.129.228.44` as operational: do not seed, enable the workflow, or perform the live test until the release is reviewed and approved. Never use an administrator account in the walkthrough.

`DemoAccountSeeder` is allowed only in local/testing environments. The live `maintenance2@ogami.test` account does not exist. Do not assume any demo email or password works on the live site. On 2026-10-09, account provisioning was separately authorized: Marites Santos (`OGM-2024-0100`) was linked to a Maintenance Supervisor login, and Josefa Castillo (`OGM-2024-0125`) and Rafael Castillo (`OGM-2024-0199`) were linked to Maintenance Technician logins. Each must change the temporary password at first sign-in. Existing Ricardo Tanaka and Carlos Mendoza logins cover Production Manager and Warehouse Staff. No employee row or existing password was changed. The closed-loop setting remains disabled.

For the live test only, Josefa and Rafael's existing night-shift assignments (IDs 88 and 87) were moved from 2026-10-10 to 2026-10-09, and clearly marked manual attendance rows (IDs 60442 and 60443) plus test punch rows (IDs 5 and 6) were inserted for the 2026-10-09 overnight shift. These are synthetic HR test fixtures, not physical-presence or GPS proof. They make both technicians eligible until 06:00 Asia/Manila on 2026-10-10. After the walkthrough, remove the fixture rows and restore the assignment dates only after checking that no later punch, payroll run, or work-order action depends on them.

| Role | Minimum ready account | Work in this test |
| --- | --- | --- |
| Maintenance Technician | Two active users linked to active MAINT employees | Diagnose, log, issue/return parts, submit repair |
| Maintenance Supervisor | One active non-admin user | Review alerts, accept technician suggestion, confirm preventive window, verify preventive recovery |
| Production Manager | One active non-admin user | Pause/release production, verify corrective machine recovery, resume production |
| Warehouse Staff | One active non-admin user | Confirm usable stock and the exact issue/return movements |
| Finance | One active non-admin user, when accounting is enabled | Confirm posted issue/return value |
| Ordinary Employee | One user without Maintenance permissions | Confirm denied access |

Before testing, record account names, role slugs, employee numbers, MAINT department membership, shift start/end, working days, and today's attendance. Pick a safe machine and stock item with Warehouse and Production. Use separate browser profiles for each role. Capture the initial machine status, production work-order number, inventory quantity, and accounting value.

The new closed-loop path applies only to work orders created while `maintenance.closed_loop.enabled=true`. The setting defaults to `false`. Earlier orders, including live `MWO-202610-0007`, retain the legacy Complete action. Never enable the setting on the live site during this runbook's local preparation.

## 1. Check the role-based entry points

1. As a technician, open `/maintenance/work-orders` on desktop and phone widths. Expect a table on desktop and cards on phone at the same URL. Open one order; the desktop detail uses the standard page grid and the phone detail uses touch controls. There is no separate Mobile view link.
2. Confirm the technician can create an order and read schedules but cannot assign a coworker or manage a schedule. Confirm the ordinary employee receives HTTP 403 on Maintenance API reads. Record the request and response.
3. As Maintenance Supervisor, confirm the Unassigned and Critical queues filter server-side before pagination. A due preventive or corrective order should produce one in-app assignment notice even if its event is retried. A Critical/Open/unassigned order older than 15 minutes should produce one escalation to Supervisor and Production Manager.

## 2. Create a corrective order and show the production handoff

1. As Production Manager, use a safe production work order and report a machine breakdown. Record the paused production order, machine status, open downtime timestamp, and automatically created corrective Maintenance order. Repeat the triggering request only in the disposable test environment: there must still be one active repair order and one linked breakdown incident.
2. As Maintenance Supervisor, open the Maintenance order. Read the equipment status and production blocker before Start. The linked production work-order number should appear; its link should open only for a user with Production read permission. The technician must see the blocker text even without the Production link.
3. Attempt Start as the assigned technician while Production still owns or reserves the machine. Expect HTTP 422 and unchanged equipment ownership. The supervisor cannot release the machine from Maintenance.
4. As Production Manager, pause the production order if needed and use its **Release resources** action with a reason. Refresh the Maintenance order. Expect the blocker to clear. Release must not automatically start Maintenance or resume Production.

## 3. Review a shift-aware technician suggestion

1. As Maintenance Supervisor, open **Assignment**. Record suggestion timestamp, employee number, presence state, active load, and exclusion reasons. A present, on-shift, lower-load technician should rank first. An off-shift, on-leave, absent, clocked-out, inactive, non-MAINT, or busy employee must not be silently offered as ready.
2. Confirm the first eligible technician. For an unverified punch, a supervisor may enter a physical-presence attestation reason; a known absence, leave, clock-out, or missing shift cannot be overridden. A changed attendance record after loading the suggestion must cause a useful stale/blocked response and a refresh path.
3. As another technician, confirm execution actions are absent and direct Start, log, part issue, completion, and cancellation calls return HTTP 403. On a new verified-workflow order, an unassigned technician cannot self-claim through Start. Legacy orders retain their previous self-claim behavior.
4. For an in-progress order near shift end, wait for the scheduled check in local/test. Supervisor should get one replacement suggestion if another technician is present. No assignment changes automatically. Supervisor must enter a handover note to change the technician; the log must retain old/new employee, actor, and note. A worker whose shift has ended cannot be suggested solely because overtime was approved.

## 4. Execute, record parts, and submit evidence

1. As the assigned technician, Start after the production blocker clears. Expect **In progress**, an actor-attributed start log, machine **Maintenance**, and an open downtime row. The displayed live duration is **Provisional recovery downtime** from the server-provided ledger start time; refresh should keep it increasing.
2. Add a work log describing diagnosis. In Add part, inspect same-target completed-history suggestions and usable stock by location/lot. No history should fall back to normal search. Expired, quality-held, fully reserved, or insufficient stock must not appear usable. Suggestions must never move stock by themselves.
3. Confirm a part issue. Request spare parts from the order (stock stays unchanged), then Warehouse confirms physical handover to the assigned technician in the picking queue (`/inventory/picking?maintenance_work_order_id=<id>`); stock deducts once at handover. Warehouse checks the stock movement and exact balance decrease. The order shows **Parts cost**, not total maintenance cost. If two eligible lots are consumed, expect two lot rows whose sum matches the requested quantity. Retry the same operation key: stock and cost must not change twice.
4. Return unused quantity from an issued row. Warehouse checks the original location/lot and exact reversal. Retry the same return key: no second receipt. Returning more than the unreturned quantity must fail. Finance checks the issue debit/credit and return reversal if accounting is enabled.
5. Submit nonempty **Diagnosis**, **Repair action**, and **Functional test result**. Blank evidence must fail. While awaiting verification, log edits, part issue/return, reassignment, cancellation, and direct Complete must fail. Record the submission timestamp and immutable evidence snapshot in Logs.

## 5. Reject, resubmit, and verify recovery

1. As Production Manager, open a corrective machine repair awaiting verification. The assigned technician cannot approve their own repair, even if they hold another role. A Maintenance Supervisor cannot accept this corrective machine repair.
2. Reject with a required reason. Expect the reason and verifier actor in Logs; Maintenance remains **In progress**, machine remains **Maintenance**, and downtime stays open. The technician can add work or parts again, then resubmit complete evidence.
3. As Production Manager, accept the corrected repair. Expect one atomic close: order **Completed**, verified actor/time, machine **Idle** when no sibling holds it, closed ledger duration, response time, technician repair time, and net Parts cost. A repeated accept must not close downtime or post cost twice. Release resources cleared Production's reservation: review a new slot and eligible crew, approve it, then **Resume production** separately after checking quality/readiness.
4. For a preventive machine or mold order, Maintenance Supervisor verifies. Production Manager must be denied. Mold acceptance may reset shots and update history once; rejection must not reset shots.
5. Open a pre-release order with `verification_required=false`. Its assigned technician still has the legacy Complete action. Confirm a new verified order has no direct Complete path, including by API.

## 6. Preventive window and repeat-failure path

1. As Supervisor, create a day/hour machine schedule and a shot-based mold schedule. Verify duration defaults to 60 minutes and accepts 15–480 minutes. An incompatible target/interval must fail. The due sweep creates one order, not two, and leaves cancelled work due.
2. Review up to three proposed slots in the next seven days. Each shows equipment and shift blockers and an eligible technician if one exists. A proposed slot changes no equipment state. Confirm a slot; production must not book the same machine/mold interval afterward. If production books between suggestion and confirmation, confirmation must reject the stale choice. Cancelling or completing the order releases its future window.
3. Start a confirmed window only after the interval and current production ownership checks pass. Supervisor verifies preventive recovery. Confirm next due date updates only after accepted completion.
4. In local/test, create three distinct breakdown downtime incidents on one machine within 30 days. Supervisor receives one repeat-breakdown review notice. Its main action opens machine history; **Review schedule** in Notifications opens the preventive schedule list. Replaying one breakdown event must not count as another incident. The alert offers review, not a predicted root cause.

## Evidence sheet

| Step | Account/role | Work-order or target ID | Before | Action and HTTP result | After | Screenshot or log |
| --- | --- | --- | --- | --- | --- | --- |
| Production breakdown/release | Production Manager | | | | | |
| Suggestion/assignment/stale attendance | Maintenance Supervisor | | | | | |
| Start/work log/part issue/return | Technician/Warehouse | | | | | |
| Submit/reject/resubmit/accept | Technician/Verifier | | | | | |
| Downtime, Parts cost, production resume | Production/Finance | | | | | |
| Preventive window/repeat alert | Supervisor | | | | | |

Mark unavailable prerequisites **Not run** with reason. Save desktop and phone screenshots of list/detail, blocker and release, suggestion, logs/evidence, part movements, verified result, schedule window, and downtime analytics. Do not claim labor cost, savings, predictive maintenance, or IoT data: those are not measured by this process.

## Production release checklist, separate approval required

1. Back up the operational database. Review additive migration SQL and role grants. Confirm old work orders retain `verification_required=false`.
2. Audit real non-admin accounts, permissions, MAINT employee records, current shifts, working days, leave, and attendance. Confirm at least two technicians, one Supervisor, one Production Manager, and one Warehouse user. Do not run `DemoAccountSeeder`.
3. Check queue worker, scheduler, notification delivery, and safe machine/stock records. Deploy with `maintenance.closed_loop.enabled=false`. Smoke an existing order, including `MWO-202610-0007`, without changing its completion contract.
4. Only after account and shift readiness, enable the setting for newly created orders. Run one controlled corrective and one preventive walkthrough with the approved roles. Record rollback steps: turn the setting off for new orders and preserve all already-gated orders for explicit resolution.
