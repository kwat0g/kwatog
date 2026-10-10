# Maintenance employee assignment implementation plan

**Goal:** Let ordinary employees in Maintenance & Engineering record work and spare parts for their assigned maintenance work order, while supervisors control its lifecycle.

**Architecture:** Reuse maintenance detail pages, controllers, work records, stock services and availability checks. Treat the current assignment and work-order state as a temporary capability checked on every request. A self-service permission opens only assignment-scoped reads; it never grants maintenance management. Preserve the existing technician workflow.

**Approved scope:** User discussion on 2026-10-10: ordinary assigned employees may record findings, actions, results and spare parts; access ends on completion, cancellation or reassignment. Supervisors start work and handle completion or repair submission. Existing independent verification still applies.

## Constraints

- Do not change employee positions, roles, attendance, shifts, live stock or existing work-order assignments.
- Eligibility requires active employee, active Maintenance department and active Employee or Maintenance Technician account. Preserve attendance, shift, busy-worker and equipment checks.
- Employees cannot create, assign, start, complete, cancel or verify maintenance orders through temporary access.
- Employees may record structured work, issue parts, return unused parts and reconcile consumption only while assigned and work is in progress with no submitted repair awaiting verification.
- Keep a readable work history without granting access to unrelated orders. Clear write access immediately when assignment or status changes.
- Preserve audit attribution, stock costing, lot handling, retry safety, closure gates and maker/checker verification.
- Use the isolated PostgreSQL test database, never the live database, for mutation tests.

## Task 1: Assignment and temporary access

Files: new `api/app/Modules/Maintenance/Support/MaintenanceWorkAccess.php`; existing assignment suggestion/service, maintenance controller/resources/routes/requests; permission seeder and additive permission migration; new `api/tests/Feature/Maintenance/MaintenanceEmployeeAssignmentTest.php`.

- [x] Add failing tests for an ordinary employee position receiving an assignment; supervisor Start; assignment-only reads; work/parts writes; denial of lifecycle actions and unrelated records; immediate removal on closure or reassignment; inactive accounts/departments and unchanged availability checks.
- [x] Run failing tests with `/tmp/phpunit-hr-request-dates-20261009.xml`.
- [x] Centralize assignment eligibility and temporary recording/read checks. Expand assignment pool from technician-only to Employee or Maintenance Technician accounts in MAINT, without testing job title.
- [x] Add a self-service assignment permission and assignment-scoped API routes; controller and request checks continue to authorize each actual order. Require work-order context for spare-part lookups used through self-service.
- [x] Allow supervisor lifecycle actions for orders assigned to ordinary employees. Extend parts attestation to assigned employees; preserve independent verification and unavailable-technician cancellation behavior.
- [x] Run new tests and nearest maintenance role, assignment, work-record, reconciliation and verified-recovery tests.

## Task 2: Self-service UI

Files: existing maintenance API types/client, desktop/mobile detail and work/parts components, list page, self-service routes/home; new targeted frontend test.

- [x] Add self-service assigned-maintenance list/detail routes using existing pages. Scope list calls to the signed-in employee on the server.
- [x] Use server `can_record_work` for temporary work/parts controls; use server lifecycle actions for supervisor controls.
- [x] Add a self-service shortcut and correct back links. Assignment notification links must open the scoped employee page.
- [x] Refetch active detail so closure/reassignment removes stale controls; failed reads must not retain editable data.
- [x] Test employee work/parts controls without lifecycle buttons and closed-order removal on desktop/mobile; run existing affected frontend gates.

## Task 3: Deployment and proof

- [x] Run typecheck, targeted lint, production build and diff whitespace check.
- [x] Apply only the additive self-service permission migration to live; clear cached routes if needed.
- [x] Perform read-only live checks: ordinary MAINT employee appears in suggestions with truthful availability blockers; assignment page accessible, unrelated records denied. Do not create attendance or transact stock to pass a demonstration.
- [x] Update this plan with actual outcomes and give the user a next-step test guide and remaining live prerequisites.

## Manual acceptance sequence

Supervisor opens MWO-202610-0009, reviews ordinary Maintenance employees and their availability, assigns an eligible one, and starts the order. Employee opens self-service assigned maintenance, records findings/actions/results, issues parts against a work record, returns unused parts and confirms consumption. Supervisor reviews and closes a legacy order or submits repair evidence for independent verification. Employee recording controls disappear after closure/reassignment; direct write requests are denied. History remains readable for the employee who performed work.

## Verification results

- Initial regression: 2 expected failures proved technician-only eligibility blocked ordinary Employee accounts.
- Final backend gate: 46 maintenance tests, 423 assertions passed, including assignment scope, spare-part lookup context, stock issue/return/cost, supervisor lifecycle, cancellation, inactive employee/department, reassignment, verified repair rejection/acceptance and existing technician behavior.
- Frontend gate: 26 tests across 8 files passed, including desktop/mobile temporary recording controls and closure/reassignment control removal.
- TypeScript build check and targeted ESLint passed. Production Vite build passed in 20.63 seconds. New PHP support/middleware/migration passed Pint. `git diff --check` passed.
- Applied only `2026_10_10_000400_enable_assigned_maintenance_self_service.php` and cleared route cache. Employee role receives scoped self-service access; no global lifecycle permissions or user role changes.
- Read-only live audit: Joseph Gonzales, EMP-0010, Employee account in Maintenance & Engineering, now passes the account-role eligibility check. Assignment is truthfully blocked because HR working days are missing. MWO-202610-0009 remains Open and unassigned.
- Read-only live middleware/controller check: Joseph's scoped list returned 200 with zero records despite a forged assignment filter; unrelated order read returned 404 and recording access returned 403.
- Full browser sign-in verification was unavailable: known demo passwords have changed. No password resets, account grants, attendance changes, work-order assignments or stock transactions were performed for testing. Automated desktop/mobile tests cover UI behavior; user should complete the live acceptance sequence with current credentials and a valid work calendar.
