# M019 — People / Leave Management fix log

Audit date: 2026-08-25  
Module status: 🔁 Needs Re-audit  
Implementation status: **Leave-owned hardening applied; proration and runtime verification remain deferred**

## Fixes applied

### M019-F01 — department decision scope

- Before: single and bulk department decisions checked only workflow role and permission; a department head could mutate another department's request.
- After: api/app/Modules/Leave/Services/LeaveRequestService.php:303-324,415-438,631-653 locks the request, requires the approver's department to match the target employee, and applies the same check to single/bulk approve and reject paths. HR/admin users with the company-wide HR approval grant retain the explicit override.
- Regression coverage: api/tests/Feature/Leave/LeaveRequestHardeningTest.php:101-135.

### M019-F02 — cancellation authorization and actor audit

- Before: any caller with leave.create could cancel an arbitrary request; cancellation did not record who acted.
- After: api/app/Modules/Leave/Services/LeaveRequestService.php:441-465 locks the request, permits only the request owner or HR/admin override, and records cancelled_by/cancelled_at. The schema, relation, and resource are in api/database/migrations/2026_08_25_210000_harden_leave_request_integrity.php:13-25, api/app/Modules/Leave/Models/LeaveRequest.php:29-70, and api/app/Modules/Leave/Resources/LeaveRequestResource.php:46-51.
- Regression coverage: api/tests/Feature/Leave/LeaveRequestHardeningTest.php:137-158.

### M019-F03 — payroll-locked attendance dates

- Before: leave approval/cancellation directly rewrote or deleted attendance without checking finalized/disbursed/voided payroll periods.
- After: api/app/Modules/Leave/Services/LeaveRequestService.php:327-354,583-629 uses the existing authoritative AttendanceDateMutabilityGuard before every attendance mutation, inside the approval/cancellation transaction. A guard failure rolls back status and balance side effects.
- Regression coverage: api/tests/Feature/Leave/LeaveRequestHardeningTest.php:160-192.

### M019-F04 — half-day representation and attendance rollback

- Before: AM/PM approval was written as a full-day on_leave row and cancellation could delete a pre-existing punch.
- After: api/app/Modules/Leave/Services/LeaveRequestService.php:491-575,583-629 snapshots the prior row, leaves an existing DTR intact for half-day leave, marks only full-day rows, and restores/deletes only the exact lineage it owns. The snapshot column is added by api/database/migrations/2026_08_25_210000_harden_leave_request_integrity.php:13-17.
- Regression coverage: api/tests/Feature/Leave/LeaveRequestHardeningTest.php:194-272. The selected canonical half-day representation (the leave request plus unchanged date-level DTR) should be confirmed in a payroll integration rehearsal.

### M019-F05 — rollover retry preservation

- Before: January updateOrInsert reset an already-consumed target balance's used and remaining values on retry.
- After: api/app/Console/Commands/ResetLeaveBalancesForYear.php:110-145 locks and preserves an existing target row; only a missing row is inserted.
- Regression coverage: api/tests/Feature/Leave/YearEndLeaveReconciliationTest.php:132-140.

### M019-F06 — cross-year balance allocation

- Before: one request could span December/January while approval and cancellation charged only the start year's balance.
- After: the service rejects cross-year ranges at api/app/Modules/Leave/Services/LeaveRequestService.php:179-188, mirrored by both SPA forms at spa/src/pages/leaves/create.tsx:33-38 and spa/src/pages/self-service/leave.tsx:41-50.
- Regression coverage: api/tests/Feature/Leave/LeaveRequestHardeningTest.php:351-367.

### M019-F07 — server-owned required documents

- Before: callers supplied arbitrary document_path strings and required document metadata was never enforced.
- After: api/app/Modules/Leave/Requests/StoreLeaveRequestRequest.php:40-43 accepts validated uploads; api/app/Modules/Leave/Services/LeaveRequestService.php:211-222,480-487 enforces required files and stores them on the private local disk. The resource exposes only has_document at api/app/Modules/Leave/Resources/LeaveRequestResource.php:31-35; both filing forms provide upload controls at spa/src/pages/leaves/create.tsx:152-162 and spa/src/pages/self-service/leave.tsx:308-316.
- Regression coverage: api/tests/Feature/Leave/LeaveRequestHardeningTest.php:274-304. Retention duration and secure HR document-view/download policy remain unspecified and should be confirmed during re-audit.

### M019-F08 — active type and balance invariants

- Before: inactive types and missing balances could enter the workflow, with a later missing-row firstOrFail becoming a 500.
- After: submission now requires an active type and initialized locked balance at api/app/Modules/Leave/Services/LeaveRequestService.php:206-239; consume/restore fail with typed business errors at api/app/Modules/Leave/Services/LeaveBalanceService.php:27-72. The SPA filters active types at spa/src/api/leave/index.ts:23-26 and spa/src/api/self-service.ts:126-128.
- Regression coverage: api/tests/Feature/Leave/LeaveRequestHardeningTest.php:306-349 and api/tests/Feature/Leave/LeaveBalanceTest.php:205-212.

### M019-F09 — archive restore

- Before: the soft-deleted type could not bind to the restore route.
- After: api/app/Modules/Leave/routes.php:15-20 opts the restore route into withTrashed(), and api/app/Modules/Leave/Services/LeaveTypeService.php:43-55 performs a locked transactional restore exposed by api/app/Modules/Leave/Controllers/LeaveTypeController.php:52-57.
- Regression coverage: api/tests/Feature/Leave/LeaveRequestHardeningTest.php:369-380.

## Deferred items

- M019-F10 remains deferred. The conflicting initializer is api/app/Modules/HR/Services/EmployeeService.php:217-241 and api/app/Modules/HR/Listeners/InitializeLeaveBalances.php:38-66, outside this module's allowed modification scope. A product decision is still needed on the single authoritative proration owner.
- M019-F11 remains open because the PostgreSQL-backed suite and authenticated browser/Vitest paths could not execute in this environment. The new regression tests are present, but not runtime-proven here.

## Verification performed

| Check | Result | Notes |
|---|---|---|
| PHP syntax | PASS | Leave PHP, Leave tests, rollover command, and migration passed php -l. |
| Route registration | PASS | php artisan route:list --path=leaves shows 20 routes. |
| Diff whitespace | PASS | git diff --check passed for targeted tracked changes. |
| SPA typecheck | PASS | npm run typecheck. |
| Targeted SPA lint | PASS | ESLint passed all changed Leave API/page/type files. |
| Full SPA lint | BLOCKED | Four pre-existing errors remain in unrelated hook/accounting/quality files. |
| Focused Leave feature suite | BLOCKED | PostgreSQL host db unresolved; 52 tests reported, 0 assertions. |
| SPA unit tests/browser smoke | NOT RUN | Prior audit's root-owned Vite cache/live-auth blockers remain. |
| Live API/payroll integration | NOT RUN | No reachable PostgreSQL/production-like dataset. |

## Release handoff

Release M019 as 🔁 Needs Re-audit. The next session should first resolve the F10 proration ownership decision, then run the new PostgreSQL authorization, attendance/payroll, document, restore, and rollover tests before considering ✅ Verified.
