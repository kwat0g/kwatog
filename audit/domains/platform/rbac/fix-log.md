# M002 — Platform / RBAC Fix Log

Implementation session: 2026-08-25 (Asia/Manila)  
Status: ✅ Verified

All twelve findings in the audit report were addressed in this session.

## 1. Permission editor toggle — RBAC-003

- Before: the permission row and nested checkbox both called `toggleSlug` (`spa/src/pages/admin/roles/permissions.tsx`, prior row handler at 769-786 and checkbox handler at 835).
- After: the row is layout-only and the labelled checkbox owns the single change path (`spa/src/pages/admin/roles/permissions.tsx:769-838`).
- Token cleanup in the same surface replaced arbitrary 9/10/11px classes with `text-2xs`/`text-xs` and invalid `border-default-default` with `border-default` (`spa/src/pages/admin/roles/permissions.tsx:454-839`).

## 2. Archive, restore, and regrant lifecycle — RBAC-001/RBAC-002

- Restore routes now opt into soft-deleted hash binding; repeated DELETE also binds trashed overrides for an idempotent no-op (`api/app/Modules/Admin/routes.php:45-64`).
- Controllers enforce route-user ownership for delete and restore (`api/app/Modules/Admin/Controllers/UserPermissionOverrideController.php:56-70`).
- Role restore is transactional and preserves the role pivot; delete/restore lifecycle audit rows are written by the service (`api/app/Modules/Admin/Services/RoleService.php:214-240`).
- Override set restores an existing trashed row in place, preserving the permanent `(user_id, permission_id)` unique key; remove and restore are serialized on the target user (`api/app/Modules/Admin/Services/UserPermissionOverrideService.php:82-155`, `175-291`).
- Coverage includes remove → regrant same-row restoration, repeated remove, trashed route binding, wrong-user 404, expiry, and cache behavior (`api/tests/Feature/Admin/UserPermissionOverrideTest.php:123-203`, `api/tests/Unit/PruneExpiredPermissionOverridesTest.php:29-129`).

## 3. One override authorization policy — RBAC-004

- The service now requires a system-admin actor for list, set, remove, and restore; the null actor path is reserved for the scheduled prune command (`api/app/Modules/Admin/Services/UserPermissionOverrideService.php:38-40`, `294-307`).
- HTTP routes retain defense-in-depth `RequireSystemAdmin` plus permission middleware (`api/app/Modules/Admin/routes.php:55-64`), and controllers pass the authenticated actor (`api/app/Modules/Admin/Controllers/UserPermissionOverrideController.php:31-70`).
- Custom roles holding `admin.users.manage_permissions` are denied consistently in feature coverage (`api/tests/Feature/Admin/UserPermissionOverrideTest.php:396-440`).

## 4. Exactly-once RBAC audit semantics — RBAC-005/RBAC-009

- Added one explicit audit writer carrying actor, actor type, source, correlation ID, reason, IP, user agent, old/new values, and last-modified metadata (`api/app/Modules/Admin/Services/RbacAuditService.php:12-85`).
- Role create/update/archive/restore and permission sync/clone use that writer; last-modified lookup includes the full lifecycle action set (`api/app/Modules/Admin/Services/RoleService.php:66-116`, `181-317`).
- Removed `HasAuditLog` from overrides so service-level audit rows are authoritative and non-duplicated (`api/app/Modules/Admin/Models/UserPermissionOverride.php:16-18`).
- Exact lifecycle counts and role restore metadata are covered by `api/tests/Feature/Admin/RoleManagementTest.php:180-235` and `api/tests/Feature/Admin/UserPermissionOverrideTest.php:123-161`; scheduled prune cardinality is asserted at `api/tests/Unit/PruneExpiredPermissionOverridesTest.php:95-113`.

## 5. Live permission-change event contract — RBAC-007/RBAC-008

- Backend broadcast name and SPA listener/teardown now agree on `permission.override.changed` (`api/app/Common/Events/PermissionOverrideChanged.php:26-45`, `spa/src/hooks/usePermissionSync.tsx:28-47`).
- The client-facing payload no longer contains the raw integer `user_id`; the private hash-ID channel remains the target boundary (`api/app/Common/Events/PermissionOverrideChanged.php:26-45`).
- The exact name and payload contract is unit-tested (`api/tests/Unit/PermissionOverrideChangedTest.php:12-37`).

## 6. Concurrent permission-write serialization — RBAC-010/RBAC-011

- Role sync locks and re-reads the stable role parent before reading/syncing the pivot and auditing the diff (`api/app/Modules/Admin/Services/RoleService.php:286-316`).
- First override sets lock the stable target user before inspecting the child row, making create/update/restored classification and outbox old/new types deterministic (`api/app/Modules/Admin/Services/UserPermissionOverrideService.php:94-152`).
- PostgreSQL two-process tests use independent post-fork PDO connections and cover both first override set and conflicting role sync baselines (`api/tests/Feature/Admin/RbacConcurrencyTest.php:20-243`).

## 7. Atomic, preflighted permission seeder — RBAC-006

- The complete catalog and role graph are validated before writes; permissions, roles, and pivots execute in one transaction (`api/database/seeders/RolePermissionSeeder.php:742-806`, `813-860`).
- Failure injection proves invalid references write nothing and sync failures roll back catalog, roles, and pivots (`api/tests/Unit/RolePermissionSeederTest.php:16-88`).

## 8. Role-page polish and stale state — RBAC-012

- Role list now passes its query key to the shared refreshing indicator (`spa/src/pages/admin/roles/index.tsx:242-246`).
- The permission matrix uses defined border/type tokens and has one keyboard-accessible checkbox handler (`spa/src/pages/admin/roles/permissions.tsx:769-839`).

## Verification

- Focused API gate: 33 tests, 140 assertions passed (roles, overrides, concurrency, seeder, event, prune).
- SPA gate: 35 test files, 253 tests passed; lint, typecheck, token discipline (769 files), and RBAC static audit (237 catalog / 0 unseeded references) passed.
- Browser QA: authenticated local admin session; the editable-role matrix was exercised with a read-only mocked matrix payload. Pointer click toggled once (`false → true`), Space toggled once (`true → false`), with zero console errors, page errors, or failed network responses. Screenshot inspected from `/tmp/rbac-role-permissions.png`.
- PHP syntax and `git diff --check` passed for the changed RBAC files.
