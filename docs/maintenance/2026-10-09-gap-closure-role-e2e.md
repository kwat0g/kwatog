# Maintenance gap closure: role test record

Audit time: 2026-10-09 22:40 UTC (2026-10-10 06:40 Asia/Manila). Source: read-only queries against the live `ogami` database. Recheck every value before the live scenario; this file does not reuse the old manual runbook.

## Live prerequisites observed

| Role | Existing linked employee account | Observation |
| --- | --- | --- |
| Maintenance supervisor | Marites Santos, OGM-2024-0100 | Active account and employee. |
| Maintenance technician | Josefa Castillo, OGM-2024-0125 | Active; night shift 22:00–06:00, working days 1–6; present record for 2026-10-09 at 22:05. Shift ended before audit. |
| Maintenance technician | Rafael Castillo, OGM-2024-0199 | Active; night shift 22:00–06:00, working days 1–6; present record for 2026-10-09 at 22:06. Shift ended before audit. |
| Maintenance technician | Juan Bautista, DEMO-0013 | Active, but no shift assignment or recent attendance in the queried records. |
| Production manager | Ricardo Tanaka, DEMO-0004 | Active account and employee. |
| Warehouse staff | Carlos Mendoza, DEMO-0011 | Active account and employee. |

Live equipment snapshot: eight idle machines, two running machines, two under maintenance; thirteen available molds, two in use. The apparent idle/available counts are not proof of target eligibility until current production ownership and reservations are checked. Live inventory has three active spare-part items: DEMO-SP-M6-20 (18 pieces), DEMO-SP-ORING-24 (10 pieces), and DEMO-SP-PRX-M12 (3 pieces), with zero reserved in the queried stock levels. Lot availability requires a fresh check in the issue dialog.

`maintenance.closed_loop.enabled` was `false`. `MWO-202610-0008` was still `in_progress`, legacy verification mode, assigned to employee 125, with ₱24.00 parts cost. No live record was changed during this audit. No administrator account was used.

**Live role scenario status:** pending. Compatible code and additive migrations were deployed at 2026-10-09 23:31 UTC; the new-order verified-flow switch remains off. At audit time, no observed maintenance technician had a current covered shift with confirmed presence. Do not insert a shift, attendance, or outcome just to pass the demonstration. Resume with a real present technician during a covered shift, or have HR correct a genuine source record.

## Isolated scenario results

An isolated PostgreSQL test database ran a connected, non-administrator corrective-machine scenario in `MaintenanceRoleWorkflowTest::test_non_admin_roles_complete_a_verified_repair_with_net_parts_and_rework` (32 assertions). A maintenance supervisor created and assigned the order to a shift-covered, present technician. An unrelated employee received HTTP 403 on create. The technician started the order, recorded work, issued two pieces against that work record, returned one, recorded a follow-up result, then passed a loaded test. Submit Repair received HTTP 422 until both the latest work result passed and the one outstanding piece was reconciled. A production manager rejected the first submission; the technician recorded another pass and resubmitted; the manager accepted. Technician self-verification received HTTP 403. The order completed, the machine returned to idle, net stock changed from 3 to 2 pieces, parts cost was 10.00, and downtime was at least 5 minutes. These are controlled test fixtures, not claims about live equipment or attendance.

Other isolated checks covered preventive verification by a maintenance supervisor, mold rejection while production owns the target, forged machine/mold selection, Production Release resources before Start, shift and presence rejection, multi-lot issue and partial return, duplicate issue keys, issue/close races, cancellation with supervisor inspection when a technician is unavailable, and reassignment invalidating earlier part attestation. Desktop and phone tests covered closeout controls and blockers.

Full Maintenance API gate: `php artisan test tests/Feature/Maintenance tests/Feature/Operations/MaintenanceConsumptionAccountingTest.php` against `ogami_test_maintenancegap` — 158 tests and 786 assertions passed. SPA gate: `npm test -- --run src/pages/maintenance` — 26 tests passed; `npm run typecheck` and `npm run build` passed. No administrator account participated in the connected role scenario.

## Live scenario results

Pending fresh eligibility audit and role-based walkthrough. Capture actual work-order ID, operator, observed times, stock before/after, and any failed step when executed. No live business transaction was made for this release.

At 2026-10-10 08:01 Asia/Manila, a fresh read-only check found the same three active maintenance technician accounts. Josefa and Rafael still had night shifts ending at 06:00; Juan still had no active shift assignment. Their latest attendance records did not establish current day-shift presence. No technician was eligible for a Start test at that time.
