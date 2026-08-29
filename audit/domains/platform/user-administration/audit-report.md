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

---

# Re-audit — 2026-08-30 (Asia/Manila)

Status recommendation: `🔁 Needs Re-audit`

Lock was an orphan from 2026-08-25 (106h) → **RECLAIMED**. The crashed session
had left a **fully written** `fix-log.md` and **committed** code: all nine
planned items are in the tree, swept into `167de85e` ("remaining uncommitted
work from ~50 crashed audit sessions"). Nothing was stranded uncommitted.

Method: this pass measured the privilege boundary with real HTTP requests
through the full middleware stack (`postJson`/`patchJson` against
`/api/v1/admin/*`) as six distinct actors, plus a real cookie-session login for
the session-invalidation probes and a two-process `pcntl_fork` harness for the
last-admin race. Findings below cite what was measured, not what was read.

## Re-verification of the prior findings — 9 of 9 confirmed FIXED

Each was re-attempted against the running stack; none reproduces.

| id | prior finding | measured now |
|---|---|---|
| UA-B01 | delegated manager can assign `system_admin` | 403 on all four write paths (create / role / bulk-role / self-role) |
| UA-B02 | self / last-admin lockout unguarded | 403 self-verbs, 422 peer-verbs, race-safe (see UA-I08) |
| UA-B03 | password-reset mail failure leaves unusable account | reset returns the one-time credential; `Hash::check` confirms it is the stored credential |
| UA-B04 | override restore cannot bind trashed rows | route has `withTrashed()`; covered by `UserPermissionOverrideTest` |
| UA-I01 | lifecycle mutations not audited | all six actions write `audit_logs` with actor + IP + user agent + reason |
| UA-I02 | hashed FKs not validated | 422 + field error for stale/undecodable `role_id`, `department_id`, `sort`, `direction` |
| UA-I04 | two conflicting override policies | `RequireSystemAdmin` middleware + permission on the whole override group |
| UA-I05 | role change without confirmation/reason | `reason` is `required|min:5`; SPA opens a confirm modal |
| UA-I06 | no profile-correction path | `PATCH /admin/users/{user}/profile` + UI form, audited |

`docs/PATTERNS.md:262-268`'s unvalidated `direction` → `orderBy()` bug is **not**
present here: `ListUsersRequest.php:27-28` whitelists both `sort` and
`direction` with `Rule::in`.

## Escalation matrix as executed

Actor `delegate` = a custom role holding **only** `admin.users.manage` (the
dynamic-RBAC path UA-B01 warned about; in `RolePermissionSeeder` only
`system_admin` holds that permission today, via the `'*'` wildcard at
`api/database/seeders/RolePermissionSeeder.php:470-474`).

| # | actor → attempted privilege change | measured |
|---|---|---|
| E01 | delegate → create user with `role_id=system_admin` | **403** "Only a system administrator may assign the system administrator role." |
| E02 | delegate → change another user's role to `system_admin` | **403** same |
| E03 | delegate → bulk-role another user to `system_admin` | **403** same |
| E04 | delegate → change **own** role to `system_admin` | **403** same |
| E05 | delegate → change **own** role to `finance_officer` | **403** "You cannot change your own role." |
| E06 | delegate → grant **itself** a permission override | **403** "Only system administrators may manage permission overrides." |
| E07 | delegate → reset a `system_admin`'s password | **403** "Only a system administrator may manage a system administrator account." |
| E08 | delegate → strip `system_admin` role from an administrator | **403** same |
| E09 | delegate → deactivate a `system_admin` | **403** same |
| E10 | delegate → create account with `role_id=finance_officer` | **201, `temp_password` echoed (36 chars)** → see UA-B06 |
| E11 | delegate → reset an existing `finance_officer`'s password | **200, `temp_password` echoed in the response body** → see UA-B06 |
| E12 | `hr_officer` → `GET /admin/users` | 403 |
| E13 | `employee` / `finance_officer` / `production_manager` → `GET /admin/users` | 403 |
| E14 | delegate → edit its **own** name + login email | **200 — allowed** → see UA-M03 |
| E15 | delegate holding `admin.users.manage_permissions` (not `system_admin`) → grant an override | 403 (route middleware wins over the named permission) |
| E16 | **inactive** delegate with live session state → `GET /admin/users` | **200** → see UA-M01 |
| E17 | `system_admin` → edit another `system_admin`'s profile | 200 (intended) |

### Last-admin verbs (premise: exactly one ACTIVE `system_admin`)

| verb | actor | measured |
|---|---|---|
| deactivate (self) | the last admin | **403** "You cannot deactivate your own account." |
| role-strip (self) | the last admin | **403** "You cannot change your own role." |
| deactivate (peer-driven) | an inactive `system_admin` peer | **422** "At least one active system administrator must remain." |
| role-strip (peer-driven) | same | **422** same |
| bulk role-strip | same | **422** same |
| soft-delete | same | **405** — `DELETE /admin/users/{user}` is not routed (see UA-M02) |
| force-lock | same | **404** — no lock route exists; lockout is login-driven only |
| lockout via failed logins | unauthenticated attacker | **`locked_until` set 15 min ahead after 5 attempts** (see UA-I10) |
| admin password reset | peer | 200, `must_change_password=true` — recoverable, not a lockout |
| **HR account deactivation** | `hr_officer` | **204 → 0 active system administrators** (see UA-B05) |

### Race safety of the last-admin check

Measured with two OS processes on separate PDO connections, both demoting the
other's administrator at a socket barrier (2 active administrators → both
attempt to leave 1):

```
RACE parent  => ok
RACE child   => error:Illuminate\Database\QueryException: SQLSTATE[40P01]: Deadlock detected
RACE remaining active system_admins => 1
```

The invariant **holds** — it is not a count read outside the transaction.
`assertAtLeastOneActiveSystemAdminRemains()` takes `lockForUpdate()` over the
active-administrator set (`UserAdminService.php:578-582`) inside the same
transaction as the write. The loser is serialised by a PostgreSQL deadlock
rather than a clean wait, which is a response-quality defect (UA-I08), not a
safety one.

## New findings

### Broken

#### UA-B05 — an `hr_officer` can deactivate the last system administrator (P0, cross-module)

`UserProvisioningService::deactivateForEmployee()` flips `is_active=false` and
revokes sessions with **no last-admin guard**
(`api/app/Modules/HR/Services/UserProvisioningService.php:82-104`). The
`UserAdminService` guard is therefore bypassable entirely.

Measured: with exactly one ACTIVE `system_admin` (linked to an employee record),
an `hr_officer` called `POST /api/v1/hr/employees/{employee}/deactivate-account`
→ **204**, and the system was left with **0 active administrators**. Nobody can
then reactivate the account, because `admin.users.manage` is held only by
`system_admin`.

Four reachable call paths:

- `api/app/Modules/HR/Controllers/EmployeeAccountController.php:56` — permission
  `hr.employees.deactivate_account`, held by `hr_officer`.
- `api/app/Modules/HR/Services/EmployeeService.php:344` — employee archive,
  permission `hr.employees.delete`.
- `api/app/Modules/HR/Listeners/DeactivateAccountOnClearanceComplete.php:54` —
  fires on clearance completion, i.e. **no operator at all**.
- direct service calls.

The last path is the worst: completing a separation clearance for whoever holds
the sole administrator account locks the company out of its own ERP with no
confirmation prompt anywhere.

**Not fixed here — the code is outside this module's scope** (`api/app/Modules/HR/`).
The invariant belongs to user administration, so the fix wants a shared guard
(e.g. extracting `assertAtLeastOneActiveSystemAdminRemains` into a service both
modules call) rather than a copy-paste of the check.

#### UA-B06 — `admin.users.manage` is effective account takeover of every non-administrator

`PATCH /admin/users/{user}/reset-password` returns the new plaintext credential
in the response body (`UserAdminController.php:133-142`), and
`assertCanManageTarget()` only protects `system_admin` targets
(`UserAdminService.php:561-569`). There is no comparison of the target role's
authority against the actor's.

Measured (E11): a delegate holding only `admin.users.manage` reset an existing
`finance_officer`'s password and received the plaintext in the 200 body. Since
`must_change_password` is then `true` and the delegate knows the temporary
password, it can complete the change itself and hold `finance_officer` authority
(payroll approve / finalize / void). E10 is the same escalation via creation
rather than reset.

Returning the credential is a deliberate fix for UA-B03, and admin-initiated
reset is a normal capability — the defect is that it is **unbounded by role
authority**. Under the current seed only `system_admin` holds the permission, so
this is latent; it becomes live the moment the permission is delegated, which is
exactly the scenario UA-B01 was raised for. **Question for a human:** should
`admin.users.manage` be restricted to targets whose role permissions are a
subset of the actor's, or should the plaintext return be replaced by a
one-time-link/out-of-band delivery?

### Missing

#### UA-M01 — `is_active` is never re-checked on an authenticated request

No middleware on the internal session stack tests `is_active`.
`EnsurePortalGuard.php:55` does it, but only for the `customer_portal` and
`supplier_portal` guards. The **only** control that stops a deactivated internal
user is the physical `DB::table('sessions')->where('user_id', …)->delete()` in
`UserAdminService.php:227`.

That makes a single line load-bearing for a security property:

- it is a silent no-op under any `SESSION_DRIVER` other than `database`, and the
  driver is env-configurable (`api/config/session.php:6`); the test suite itself
  runs `array` (`api/phpunit.xml:255`), which is how this went unmeasured.
- any future code path that sets `is_active=false` without deleting sessions
  grants continued full access, and nothing fails to say so.

Measured under a forced `SESSION_DRIVER=database`: the session row **is** deleted
(1 → 0 rows), and login is correctly refused afterwards (422, `is_active` is
checked at `AuthService.php:125`). But with the session state still resolvable
the deactivated user's request returned **200** — i.e. nothing downstream of the
row deletion objects. **Caveat, stated plainly:** the surviving-session half of
that measurement is partly a test-harness artifact (Laravel's test session store
outlives the row deletion), so the *exploitability* of a stale cookie in
production was NOT proven. What IS proven is the absence of any `is_active`
gate — a defence-in-depth gap regardless.

By contrast, soft-delete **is** immediate (401 on the next request), because the
Eloquent user provider excludes trashed rows on every `retrieveById`.

#### UA-M02 — no soft-delete / restore surface for users at all

`User` uses `SoftDeletes` (`api/app/Modules/Auth/Models/User.php:18`) but the
Admin module exposes no destroy or restore route: `DELETE /admin/users/{user}`
measured **405**. The list query is a plain `User::query()`
(`UserAdminService.php:90`), so it excludes trashed rows.

Consequence: a user soft-deleted by any other path is invisible **and
unrecoverable** from Admin › Users. The design intent looks like
"deactivate, never delete" — `EmployeeService::delete()` deactivates rather than
deletes the linked account — which is defensible. **Question for a human:**
confirm that users are intentionally never deleted; if so the `SoftDeletes` trait
on `User` is a trap worth documenting, and if not this surface is missing.

#### UA-M03 — `updateProfile` has no self-target guard

`changeRole` (`UserAdminService.php:280-282`) and `deactivate` (`:215-217`)
both refuse self-targeting; `updateProfile` (`:460-487`) does not. Measured
(E14): a delegate changed its own display name **and its own login email** to a
value it chose, with no re-authentication and no notification to the account
owner.

This is not an escalation on its own (the unique index blocks stealing another
user's address) but it is an unreviewed change to an authentication identifier
from inside an administrative surface, and it is inconsistent with the two
sibling verbs. **Question:** is self-correction of one's own name/email
intended here, or should it route through the account/profile surface?

### Incomplete

#### UA-I08 — the last-admin race resolves as a 500, not a 409

Measured above: the losing transaction raises `SQLSTATE[40P01]` and surfaces as
an unhandled `QueryException`. `ForbiddenActionException` and
`BusinessRuleException` map cleanly to 403/422; a deadlock does not, so a
correctly-refused security operation reports itself as a server fault. Lock
ordering (lock the administrator set before the individual target, or order the
set lock deterministically) or a deadlock retry/translation would fix it.

#### UA-I09 — `RbacConcurrencyTest` commits an ACTIVE `system_admin` that disarms later tests — **FIXED THIS SESSION**

Confirmed reproducing, then fixed. See `fix-log.md` §R1.

#### UA-I10 — the sole administrator can be locked out by an unauthenticated attacker (cross-module)

Measured: 7 failed logins against the last administrator's email set
`locked_until` 15 minutes ahead (`failed_login_attempts` capped at 5,
`is_active` untouched). Only a holder of `admin.users.manage` can call
`PATCH /{user}/unlock`, and that is the locked account itself. The lock expires
on its own, so this is a 15-minute-window DoS rather than a permanent lockout —
but it is repeatable, and `throttle:auth` keys on IP + email, so a rotating-IP
attacker can sustain it. Belongs to `auth-session`; reported, not fixed.

#### UA-I11 — a role-less user cannot be given a first role

`spa/src/pages/admin/users/detail.tsx:84` sends `expected_role_id: ''` when the
target has no role; `ChangeUserRoleRequest.php:20,34-38` requires a decodable
hash and aborts 422 "Invalid expected_role_id." `users.role_id` is nullable, so
the state is reachable. Fixing it means deciding what optimistic concurrency
means against a null baseline — a contract change, not a typo.

### Polish

#### UA-P03 — the 404 body names the internal model FQCN

With `APP_DEBUG=false`, `GET /admin/users/{valid-hash-no-row}` returns
`{"message":"No query results for model [App\\Modules\\Auth\\Models\\User]."}`,
whereas an undecodable hash returns `{"message":""}`. So the two cases are
distinguishable and the internal namespace leaks.

**No raw integer id appears anywhere** — not in list payloads, not in detail
payloads, not in 404 bodies (asserted by regex in the new test). The
`{"id":42,...}` oracle shape found in other modules is **not** present here.
This is Laravel's default `ModelNotFoundException` → `NotFoundHttpException`
message and affects every module's route binding, so it is a cross-cutting item
rather than an M003 defect. The empty `{"message":""}` is also a poor client
experience.

#### UA-P04 — UA-P01 is only partly fixed: `LuUser` still reaches the screen

- `spa/src/lib/emptyStateCopy.ts:335` — `actionLabel: 'Add First LuUser'` under
  the `'/admin/users'` key, rendered as the primary CTA on the **empty** users
  list (`ListEmptyState.tsx:60-64`, mounted at `index.tsx:212`). The prior pass
  fixed the three page files and missed the registry, so the string only shows
  up when the list has zero rows. **Fixed this session** — see `fix-log.md` §R2.
- `spa/src/pages/admin/sessions.tsx:24` — `header: 'LuUser'` on a DataTable
  column. Different page (`/admin/sessions`), outside this module; reported.

#### UA-P05 — the legacy hub is now unreachable dead code

`/admin/users-roles` redirects to `/admin/users`
(`spa/src/routes/dashboardRoutes.tsx:110`), which closed UA-I07, but
`spa/src/pages/admin/users-roles.tsx` still exists and nothing imports or routes
to it. Its own four tab links point back at `/admin/users-roles?tab=…`, which
the redirect swallows, so it could not function even if remounted.

#### UA-P06 — options-error copy omits the status filter

`spa/src/pages/admin/users/index.tsx:188` says "Role and department filters are
unavailable", but the status options come from the same query (`:87`) and empty
out too.

## Verification

- Targeted backend suite on an isolated database (`ogami_test_useradm`):
  **`tests/Feature/Admin` — 149 passed, 614 assertions.**
- New regression lock `UserAdministrationEscalationTest`: 13 tests, 117
  assertions; **1 test proven red** against unmodified source (see fix log).
- `php -l`, `phpstan analyse` (no errors), `pint --test` on changed PHP files.
- `npm run typecheck`, `npx eslint` on the changed SPA file.
- Race safety measured with a real two-process fork harness.
- **Not verified:** the browser role-permission audit
  (`npm run audit:role-permissions`) was left inconclusive by the prior session
  and was not re-attempted — the Nginx/SPA containers are intentionally down for
  this pipeline (only `db` and `redis` run). This remains the standing blocker on
  `✅ Verified`.
- **Not verified:** production exploitability of a stale session cookie after
  deactivation (UA-M01) — see the caveat in that finding.
- Dependency note: `auth-session` (M001) was being audited concurrently and
  `api/app/Common/Middleware/SessionTimeout.php` changed underneath this session.
  Nothing under it was modified here.
