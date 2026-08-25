# M003 — User Administration Fix Log

Implementation pass: 2026-08-25  
Release status: `🔁 Needs Re-audit`

All nine planned implementation items were addressed in this session. The
release remains `Needs Re-audit` only because the live browser role-permission
run was inconclusive after the local Nginx/SPA/Reverb containers were killed
mid-run; the backend and static verification gates are green.

## 1. Privileged-role escalation and administrator lockout — UA-B01, UA-B02

- Before: delegated managers could submit `system_admin` assignments, and
  self-targeting/last-active-system-admin mutations were not consistently
  protected.
- After: user-management service methods lock target and role rows, restrict
  `system_admin` assignment and system-admin target management, reject
  self-role changes and self-deactivation, and require at least one active
  system administrator to remain. See
  `api/app/Modules/Admin/Services/UserAdminService.php:143-300,554-586`.
- Verification: delegated assignment, self-deactivation, and last-admin tests
  pass in `UserAdministrationHardeningTest`.

## 2. Recoverable password reset delivery — UA-B03

- Before: a reset could leave the administrator without a recoverable
  delivery path when notification delivery failed.
- After: the reset uses the provisioning service's fallback path and returns a
  one-time temporary credential through the controlled admin response, with
  explicit approved-channel messaging in
  `api/app/Modules/Admin/Controllers/UserAdminController.php:133-145`.
- Verification: `UserAdministrationHardeningTest::test_reset_returns_one_time_credential_and_writes_audit`
  passes and confirms the credential matches the stored password.

## 3. Administrative mutation audit coverage — UA-I01

- Before: create, unlock, activate, deactivate, and reset actions did not
  share a complete actor/target/old-new/reason audit contract.
- After: `RbacAuditService` centralizes actor, source, correlation, request
  metadata, old/new snapshots, and reason capture; lifecycle, role, profile,
  and reset mutations use it. Bulk role changes retain their multi-target
  conflict/missing detail in the audit row. See
  `api/app/Modules/Admin/Services/RbacAuditService.php:19-84` and
  `api/app/Modules/Admin/Services/UserAdminService.php:187-483`.
- Verification: password-reset and profile-correction audit assertions pass;
  the existing role/override audit tests also pass.

## 4. Hashed IDs and stale/deleted references — UA-I02

- Before: stale role/department filters could silently produce empty results,
  and bulk updates did not distinguish missing decoded users from invalid
  hashes.
- After: list requests and the service reject unavailable role/department
  references with stable validation/business-rule responses; bulk responses
  return `conflicts`, `missing`, and `invalid_ids`. See
  `api/app/Modules/Admin/Requests/ListUsersRequest.php:20-56`,
  `api/app/Modules/Admin/Services/UserAdminService.php:88-132,318-420`, and
  `api/app/Modules/Admin/Controllers/UserAdminController.php:151-174`.
- Verification: the missing-decoded-user hardening test and existing
  concurrency tests pass.

## 5. User-management and override authority reconciliation — UA-B04, UA-I03, UA-I04

- Before: role assignment/permission override authority was coupled
  implicitly, and deleted override restoration was not reachable or fully
  scoped.
- After: `/admin/users/options` is available at the user-management boundary;
  override routes require `RequireSystemAdmin` plus the explicit permission,
  restore/delete use trashed binding, and controllers verify the route user is
  the override parent. The UI uses the same system-admin guard and supports
  showing/restoring removed rows. See
  `api/app/Modules/Admin/Middleware/RequireSystemAdmin.php:12-21`,
  `api/app/Modules/Admin/routes.php:55-85`, and
  `spa/src/pages/admin/users/_components/PermissionOverrides.tsx:52-207`.
- Verification: override authorization/restore tests, options authorization
  tests, RBAC static audit, and API route audit pass.

## 6. Deliberate role-change interaction — UA-I05

- Before: role changes could be submitted without confirmation or a reason.
- After: the request requires a five-character reason, the detail page opens a
  confirmation modal, shows the target role, preserves optimistic-concurrency
  checks, and reports conflicts. See
  `api/app/Modules/Admin/Requests/ChangeUserRoleRequest.php:17-45` and
  `spa/src/pages/admin/users/detail.tsx:115-127,376-431`.
- Verification: stale-role and role-audit concurrency tests pass; SPA
  typecheck, lint, and production build pass.

## 7. Admin profile correction contract — UA-I06

- Before: the account detail surface had no correction path for standalone
  user identity data.
- After: standalone accounts can update validated unique name/email values via
  `PATCH /admin/users/{user}/profile`; linked employee accounts receive the
  supported employee-profile correction message. The mutation is audited. See
  `api/app/Modules/Admin/Requests/UpdateUserProfileRequest.php:10-40`,
  `api/app/Modules/Admin/Services/UserAdminService.php:460-485`, and
  `spa/src/pages/admin/users/detail.tsx:433-477`.
- Verification: standalone profile correction and audit assertions pass.

## 8. Users & Roles hub consolidation — UA-I07

- Before: `/admin/users-roles` exposed a broader legacy hub guard than its
  individual tabs.
- After: the legacy route redirects to the canonical permission-aware Users
  page; role administration remains on its dedicated route. See
  `spa/src/routes/dashboardRoutes.tsx:110`.
- Verification: SPA route audit, typecheck, lint, and production build pass.

## 9. Frontend polish and filter completeness — UA-P01, UA-P02, discovery gap

- Before: visible `LuUser` icon names appeared in user-facing copy, role
  loading failures were opaque, and the supported department filter was not
  exposed on the canonical Users page.
- After: labels use user-facing terminology, role/options queries expose
  loading/error/retry states, and role, department, and status filters are
  sourced from `/admin/users/options`. See
  `spa/src/pages/admin/users/index.tsx:53-90,162-207` and
  `spa/src/pages/admin/users/create.tsx:30-105`.
- Verification: no `LuUser` user-facing copy remains in the touched surfaces;
  SPA typecheck, lint, and production build pass.

## Verification record

- PASS — PHP syntax check for all `api/app/Modules/Admin` files.
- PASS — Pint check for changed PHP files.
- PASS — targeted backend suite inside Compose: **27 tests, 110 assertions**.
- PASS — `npm run typecheck` and `npm run lint -- --no-warn-ignored`.
- PASS — `npm run build` inside the SPA container (`vite v7.3.6`, 7,990
  modules transformed).
- PASS — `npm run audit:rbac` and `npm run audit:api-routes`.
- PASS — admin route listing and `git diff --check`.
- INCONCLUSIVE — `npm run audit:role-permissions`: the local web gateway and
  SPA/Reverb containers exited with environment-level connection/reset errors
  during the 13-role run; a focused read-only check also could not establish a
  stable seeded login session. Re-run this browser audit against a stable
  local or preview stack before marking the module `✅ Verified`.

## Interrupted-session recovery

The parallel implementation worker terminated before completing Step 8. Preserve the existing fixes and the inconclusive browser gate, but keep this module at Needs Re-audit until the current worktree and the action plan are rechecked and the remaining verification is recorded.
