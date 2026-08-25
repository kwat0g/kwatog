# M003 — User Administration Audit Report

Audit date: 2026-08-24 (Asia/Manila)

Status recommendation: `📋 Plan Ready`

## Scope

Audited the Admin > Users surface only: user-management controllers, requests,
resources, service, login-history persistence, routes, the `/admin/users`
frontend pages and API clients, the embedded per-user override surface, related
tests, and the direct permission wiring. Dependency modules were read only for
context.

## Discovery

Implemented and wired:

- API list/options/show/create/unlock/deactivate/activate/role-change/reset-
  password/login-history/bulk-role endpoints in
  `api/app/Modules/Admin/Controllers/UserAdminController.php:28-150`.
- Shared `auth:sanctum`, session-timeout, password-expiry, and
  `admin.users.manage` route protection in `api/app/Modules/Admin/routes.php:24-26,62-79`.
- User, role, employee summary, lock state, password state, and recent login
  resources in `api/app/Modules/Admin/Resources/AdminUserListResource.php:18-43`
  and `AdminUserDetailResource.php:18-55`.
- Standalone-account creation, lifecycle actions, optimistic role changes, and
  bulk role changes in `api/app/Modules/Admin/Services/UserAdminService.php:34-281`.
- Canonical list/create/detail pages and API client in
  `spa/src/pages/admin/users/index.tsx:36-300`, `create.tsx:19-163`,
  `detail.tsx:18-334`, and `spa/src/api/admin/users.ts:12-68`.
- Per-user override list/add/remove UI and API wiring in
  `spa/src/pages/admin/users/_components/PermissionOverrides.tsx:36-220,222-397`
  and `spa/src/api/admin/user-overrides.ts:11-35`.
- Targeted feature coverage exists in
  `api/tests/Feature/Admin/UserAdminCreateStandaloneTest.php`,
  `UserRoleConcurrencyTest.php`, and `UserPermissionOverrideTest.php`.

Missing or partial from the discovered surface:

- There is no general admin update endpoint or form for correcting a user's
  name/email; the controller/routes expose creation and lifecycle/role actions
  only (`UserAdminController.php:28-150`, `api/app/Modules/Admin/routes.php:62-79`).
- The backend accepts a `department_id` user-list filter, and the frontend type
  declares it, but the list filter UI exposes only role and status
  (`api/app/Modules/Admin/Requests/ListUsersRequest.php:18-29`,
  `UserAdminService.php:70-75`, `spa/src/types/admin.ts:56-67`,
  `spa/src/pages/admin/users/index.tsx:78-91`).
- The restore API for soft-deleted permission overrides is declared but not
  surfaced by the user-detail UI (`spa/src/api/admin/user-overrides.ts:30-35`;
  no component call to `restore`).

## Findings

### Broken

#### UA-B01 — A delegated user manager can assign the full `system_admin` role

`admin.users.manage` protects the user routes, but create and role-change
requests only decode a role hash and pass the resulting ID through; they do not
restrict privileged roles or compare the actor's authority:

- `api/app/Modules/Admin/Requests/CreateUserRequest.php:17-39`
- `api/app/Modules/Admin/Requests/ChangeUserRoleRequest.php:17-43`
- `api/app/Modules/Admin/Services/UserAdminService.php:93-108,151-164`
- `api/app/Modules/Admin/routes.php:62-78`

The permission catalog contains a distinct `admin.users.manage` permission
(`api/database/seeders/RolePermissionSeeder.php:21-41`), while `system_admin`
is the wildcard role (`RolePermissionSeeder.php:446-453`). If that permission
is granted to a custom role through RBAC, that actor can create or promote an
account to `system_admin`, which is a privilege-escalation path. The current
seed data happens to grant the permission only through the wildcard role; that
does not protect the dynamic-RBAC path.

#### UA-B02 — Self/last-administrator lockout is not guarded

The service can deactivate any bound user, including the current user, and can
change any user's role without checking for self-targeting or the last active
system administrator:

- `UserAdminService.php:123-149` clears/changes account state without an actor
  or last-admin guard; deactivation also deletes the target's sessions at
  `:134-140`.
- `UserAdminService.php:151-188` changes roles without a current-user or
  last-break-glass check.

This can remove the only administrative path or downgrade the actor who is
performing the operation. No targeted test covers self-deactivation,
last-system-admin protection, or privileged-role downgrade.

#### UA-B03 — Password-reset mail failure can leave an unusable account

`UserProvisioningService::resetPasswordForUser` commits a new forced-change
password, catches notification failure, and returns the temporary password
(`api/app/Modules/HR/Services/UserProvisioningService.php:136-170`). The admin
controller discards that return value and always reports that the password was
emailed (`api/app/Modules/Admin/Controllers/UserAdminController.php:117-123`).

Unlike account creation, which returns the one-time temporary password in its
response (`UserAdminController.php:76-84`), reset has no fallback credential or
failure response. A mail outage therefore leaves the user forced to change a
password they never received.

#### UA-B04 — Permission-override restore is not reachable for deleted rows

`UserPermissionOverride` uses `SoftDeletes` (`api/app/Modules/Admin/Models/UserPermissionOverride.php:17-19`),
but the restore route does not opt into trashed model binding
(`api/app/Modules/Admin/routes.php:52-60`). The controller then calls
`restore()` on the bound model (`UserPermissionOverrideController.php:66-70`).
Deleted overrides are excluded before the controller can run, so the restore
operation is effectively dead. The same method also lacks the parent-user
ownership check that the delete method performs at `:56-60`.

### Incomplete

#### UA-I01 — Administrative lifecycle mutations are not consistently audited

Role changes explicitly write audit records (`UserAdminService.php:169-186,243-262`),
but standalone creation (`:93-120`), unlock (`:123-130`), deactivate
(`:132-143`), activate (`:145-149`), and the admin password-reset delegation
(`UserAdminService.php:268-270`) do not create corresponding `audit_logs` rows.
This leaves account creation, lockout recovery, activation, deactivation, and
password reset without the same traceability as role assignment.

#### UA-I02 — Hashed foreign-key inputs are not validated to active records

Create and bulk role requests accept a decodable hash but do not verify that it
maps to an existing, non-deleted role:

- `CreateUserRequest.php:30-39` calls `Role::tryDecodeHash` only, then
  `UserAdminService.php:99-104` writes the raw ID.
- `BulkChangeUserRoleRequest.php:54-58` decodes only, then
  `UserAdminService.php:231-233` performs a direct update.

A valid hash for a missing role can surface a database exception; a soft-deleted
role can be assigned and then appear to have no effective role. The list path
also silently ignores invalid role/department hashes instead of returning a
validation error (`UserAdminService.php:47-51,70-75`).

#### UA-I03 — User-management permission and role-management permission are coupled implicitly

The user pages load role options from `/admin/roles`
(`spa/src/pages/admin/users/index.tsx:56-63`, `create.tsx:34-38`,
`detail.tsx:30-34`), while the role routes require `admin.roles.manage`
(`api/app/Modules/Admin/routes.php:34-50`). The user routes themselves require
only `admin.users.manage` (`routes.php:62-78`). A future custom role with user
management but without role-management permission can open the user surface but
cannot filter, create, or change roles; the UI renders an empty select without a
role-query error state.

#### UA-I04 — Override authorization has two conflicting policies

The override route advertises `admin.users.manage_permissions`
(`api/app/Modules/Admin/routes.php:52-59`), and that permission is seeded in the
catalog (`RolePermissionSeeder.php:39-41`), but the FormRequest authorizes only
the literal `system_admin` role (`StoreUserOverrideRequest.php:19-37`). A custom
role granted the named permission will still receive 403. Either delegated
override management must be supported consistently, or the permission should be
removed/renamed and the system-admin-only policy made explicit at the route
boundary.

#### UA-I05 — High-impact role changes happen without confirmation or an explicit reason

The detail page mutates the role immediately from a select change
(`spa/src/pages/admin/users/detail.tsx:77-85,181-194`). The request makes
`reason` optional and silently supplies a default (`ChangeUserRoleRequest.php:17-23,40-43`).
This makes an accidental selection a privileged change with a generic audit
reason and no confirmation dialog.

#### UA-I06 — The admin account detail has no correction/edit path

The detail resource exposes name/email (`AdminUserDetailResource.php:24-28`),
and the page displays them (`spa/src/pages/admin/users/detail.tsx:107-114`), but
there is no update route/controller method or editable form. This is especially
incomplete for standalone accounts created by this module. Confirm whether name
and email are intentionally immutable or whether admin correction belongs here.

#### UA-I07 — The legacy Users & Roles hub has a broader guard than its tabs

`/admin/users-roles` is guarded only by `admin.users.manage`
(`spa/src/routes/dashboardRoutes.tsx:109-110`), but its four tabs call users,
roles, permissions, and audit APIs (`spa/src/pages/admin/users-roles.tsx:27-47,113-224`).
If permissions are delegated independently, the hub presents tabs that fail
with 403 rather than hiding or individually guarding them. It also duplicates
the canonical `/admin/users` and `/admin/roles` surfaces in `adminRoutes.tsx:154-178`
without being part of the Administration sidebar navigation.

### Polish

#### UA-P01 — User-facing labels contain the literal `LuUser` icon name

Visible copy is misspelled in the list title/button
(`spa/src/pages/admin/users/index.tsx:163-175`), create page title/toast/button
(`create.tsx:55,70,126`), and detail login-history header
(`detail.tsx:252`). This reads as an internal icon identifier rather than product
copy.

#### UA-P02 — The surface otherwise follows the Atelier system, but role-query failure is opaque

The pages use the shared `PageHeader`, `Panel`, `DataTable`, `Chip`, modal,
loading, retry, empty-state, token color, and mono date/ID patterns. This aligns
with `docs/DESIGN-SYSTEM.md:19-25,205-241,243-255`. The remaining polish gap is
that role-query failures on create/detail are rendered as an empty role select
instead of a clear retry/error state, which is especially confusing alongside
UA-I03.

## Verification

- `npm run typecheck` — passed.
- `npm run lint -- --no-warn-ignored` — passed.
- `npm run audit:rbac` — passed; no referenced-but-unseeded permissions.
- `npm run audit:api-routes` — passed; 826 SPA requests matched 1340 Laravel
  routes, with 27 explicitly classified exceptions.
- `php -l` across `api/app/Modules/Admin` — passed.
- Targeted backend tests (`UserAdminCreateStandaloneTest`,
  `UserRoleConcurrencyTest`, `UserPermissionOverrideTest`) — blocked before
  assertions: PostgreSQL host `db` could not be resolved (`SQLSTATE[08006]`).
- `npm run audit:role-permissions` — blocked by the unavailable local app at
  `http://localhost/login` (13 browser checks, 13 connection-refused failures).

No production code was changed in this audit.
