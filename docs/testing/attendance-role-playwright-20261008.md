# Attendance and production: role-based Playwright verification

Result: **22 checks passed, 0 failed; no unexpected browser exceptions or API 500 responses.**

## Scope and environment

Playwright Chromium exercised the current SPA, Laravel API, and PostgreSQL together. Business API responses were not mocked. Test accounts used the repository's real role permissions.

All mutations ran in `ogami_test_attendance_browser_20261008`, a separate database containing disposable accounts and `WO-BROWSER-1008`. The application was served through a private SPA at `http://127.0.0.1:5249` and a temporary API process. No customer-facing work orders, attendance records, accounts, or HR calendars were changed.

The server and browser clocks were controlled to test multiple times on October 8, 2026 in Asia/Manila without waiting hours. Browser geolocation supplied the configured plant coordinates with 20 m accuracy. Session timeout was extended only in the isolated database so advancing test time did not terminate role sessions. External email and production queue workers were not exercised.

## Verified scenarios

| Scenario | Expected behavior |
| --- | --- |
| Production manager and employees sign in | Separate authenticated browser sessions; the real sign-in form works. |
| Workers clock in at 8:50 AM for a 9:00 AM shift | GPS punch accepted; next action becomes Time out. |
| Manager attempts production before 9:00 AM | Start is blocked because shift hours do not cover the time. The message acknowledges the existing clock-in. A direct request with presence confirmation cannot bypass the guard. |
| Manager refreshes workers at 9:05 AM | Presence becomes verified without changing the approved 9:15 AM schedule. Early Start becomes available. |
| Manager starts the WO | Status becomes In Progress and actual start is recorded. Raw stock changes from 100 to 90; reserved stock changes from 10 to 0. Issued material quantity is 10. |
| Duplicate Start | Returns 409; stock remains unchanged and only one WO material movement exists. |
| QC inspector completes the generated inspection | The real Appearance PASS checklist and coverage confirmation finalize in-process QC. This stage does not require an independent reviewer under the current policy. |
| Support worker clocks out at 10:45 AM | The actual punch button accepts OUT; next action becomes Time in. |
| Staffing notice | Both Production manager and PPC receive `production.assignment.review`. WO detail identifies the clocked-out worker; WO remains In Progress. |
| New operation with clocked-out crew | Returns 409 naming the unavailable worker; operation remains Pending. This check runs after QC passes, isolating the attendance gate. |
| Mobile crew replacement | At 390 px width, the manager removes the support worker, selects the present backup, enters a handover reason and saves through Change workers. No horizontal page overflow. Old crew remains in history; staffing warning clears. |
| Worker re-entry at 10:47 AM | Latest IN restores presence. Payroll keeps first IN at 8:50 AM and retained OUT at 10:45 AM. |
| Employee, Warehouse, HR, Sales and unrelated employee management access | Readiness, crew-options and Start requests return 403. |
| Warehouse Stock Levels | Returns 200 and renders for Warehouse staff. |
| Assigned and unrelated employee access | Assigned employee retrieves and opens their own WO; unrelated employee receives 404. |
| Restored crew starts an operation | Setup succeeds after QC and attendance checks. Materials are not issued again. |
| HR changes a committed, punched shift | Rejected with an actionable message; shift end remains 11:00 AM. |
| HR removes working-day coverage | Rejected; punched history and committed crew coverage remain protected. |
| HR records an absent worker | Worker options disable that employee. Including the employee in presence confirmation does not bypass absence; crew remains unchanged. |
| Employee attempts HR attendance edit | Returns 403. |
| Warehouse opens manager WO page | Management record and Start control are not exposed. |
| Browser and server errors | No unexpected page exceptions, failed API network requests, or API 500 responses in the completed runs. |

## Evidence and reproduction

Machine-readable case outcomes and request status evidence: [results.json](evidence/attendance-role-playwright-20261008/results.json).

Screenshots and per-role Playwright traces are in [the evidence directory](evidence/attendance-role-playwright-20261008/). Traces contain only disposable test-account sessions and should be treated as internal test artifacts.

The temporary harness remains at `/tmp/attendance-role-playwright-20261008/`: `seed.php`, `router.php`, `server.mjs`, `run.mjs`, and `guards.mjs`. The seed explicitly refuses any database other than the isolated browser database. `run.mjs` performs the attendance/QC/Start/replacement chain; `guards.mjs` continues with HR and role guards. Rerunning the full chain requires fresh isolated starting records, not the already-started final WO.

## Limits

This verifies the current code with isolated role accounts, not the deployed host's existing employee configuration. GPS hardware, midnight shift rotation, external email/WebSocket delivery, and concurrent multi-manager Start requests were not browser-tested in this run. Durable in-app notifications were verified through the real API and WO page. No production code was changed by this verification task.
