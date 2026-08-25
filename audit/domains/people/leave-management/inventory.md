# M019 — People / Leave Management inventory

Audit date: 2026-08-24  
Claim: people / leave-management  
Registry tier: 4  
Surface: M

## Release surface

| Area | Evidence reviewed | Operational role |
|---|---|---|
| API | `api/app/Modules/Leave/routes.php:11-45`; Leave request, balance, calendar, and type controllers | Filing, two-step approval, cancellation, balances, calendar, type administration, year-end trigger |
| Authorization | `api/database/seeders/RolePermissionSeeder.php:670-688,731-738`; `api/app/Common/Services/ApprovalService.php:82-125,172-185` | Employee self-service, department-head approval, HR approval, type management |
| Core state | `LeaveRequestService`, `LeaveBalanceService`, `LeaveTypeService`, Leave models/resources | Overlap, balance reservation/consumption, approval state, soft deletion |
| Cross-module writes | `api/app/Modules/Leave/Services/LeaveRequestService.php:293-299,416-444` | Approved/cancelled leave mutates Attendance and payroll inputs |
| Background work | `ProcessYearEndLeave`, `RunYearEndLeaveOnRequested`, `ProcessYearEndLeaveCommand`, `ResetLeaveBalancesForYear`, `api/routes/console.php:188-198,286-292` | Durable year-end disposition and January rollover |
| SPA | `spa/src/api/leave/index.ts`; `spa/src/pages/leaves/{index,create,detail,types,calendar,year-end}.tsx`; `spa/e2e/chain-leave.spec.ts` | Employee filing, HR/dept-head actions, leave-type administration, calendar, year-end trigger |
| Persistence | Leave types/balances/requests, half-day extension, year-end disposition and processed-type migrations | Unique balances, request numbering, half-day state, rollover auditability |

## Dependency and change context

- Dependencies are registry-ready: employee master, attendance/DTR, and approval workflows are all `📋 Plan Ready`.
- Recent leave commits cover visibility/grant cleanup, bulk approval, failure redaction, and archive UX; the audit therefore re-checked mutation authorization rather than treating list visibility as proof of decision authorization (`git log -- api/app/Modules/Leave`).
- The worktree already contained unrelated user changes. No application source file was changed by this audit.

## Critical workflows checked

1. Employee/HR submission → `pending_dept` → `pending_hr` → `approved`/`rejected`/`cancelled`.
2. Same-employee overlap and AM/PM submission behavior.
3. Balance consume/restore and employee-row serialization.
4. Approval/cancellation attendance side effects and payroll-date mutability.
5. Leave-type archive/restore, required-document metadata, and inactive-type behavior.
6. Durable year-end request, disposition, payroll encashment adjustment, and January rollover.
7. SPA filing/detail/approval/type/calendar/year-end surfaces and available E2E chain coverage.

## Verification and evidence limits

- PHP lint over the Leave module, year-end commands, and related seeders passed.
- `php artisan route:list --path=leaves` registered 20 Leave routes.
- `npm run typecheck` and `npm run lint` passed in `spa`.
- The focused Leave suite contains 42 tests but could not reach PostgreSQL because host `db` was unresolved; it ran 0 assertions.
- Vitest could not start because Vite could not write the existing root-owned `spa/node_modules/.vite-temp` file. No live authenticated API/SPA smoke test or production-like dataset was available.
