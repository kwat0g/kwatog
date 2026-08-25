# M002 — Platform / RBAC Audit Report

- Audit date: 2026-08-24 (Asia/Manila; registry generated 2026-08-23 UTC)
- Domain/module: platform / rbac
- Tier/surface: Tier 1 / M
- Session result: 📋 Plan Ready
- Scope: role and permission catalog, effective-permission resolution, role CRUD and permission sync, per-user overrides, API enforcement, SPA guards/editor, seed integrity, audit and broadcast contracts.
- Boundary: adjacent Auth, Common, Admin, database, seeder, and SPA files were read where they participate in the RBAC contract. No implementation files outside this audit module were changed.

## Executive result

The authorization boundary is generally well structured: API routes and FormRequests enforce permissions independently of the SPA, system_admin is an explicit server-side bypass, role permission caches are flushed on sync, overrides are read fresh so expiry is honored, resources use opaque hash IDs, and the static RBAC scan found zero referenced-but-unseeded permissions.

Twelve findings remain:

1. **Broken / P1:** role and override restore routes cannot bind soft-deleted records.
2. **Broken / P1:** removing an override makes a later regrant collide with the permanent unique key.
3. **Broken / P1:** clicking a permission checkbox toggles the row twice and leaves the value unchanged.
4. **Broken / P1:** override deletion/restoration accepts a permission holder even though the write contract says only system_admin may grant or revoke overrides.
5. **Incomplete / P1:** normal role create/update/archive/restore actions are not fully represented in the audit trail or last-modified metadata.
6. **Incomplete / P1:** RolePermissionSeeder can leave a partially updated authorization catalog after an exception.
7. **Broken / P2:** the backend override broadcast name does not match the SPA listener, so live permission refresh never runs.
8. **Incomplete / P2:** the override broadcast exposes a raw integer user ID in a client-facing payload.
9. **Incomplete / P2:** override mutations can create duplicate audit rows because model observers and service-level audit writes are both active.
10. **Incomplete / P2:** concurrent custom-role permission syncs are last-writer-wins without a version or row lock.
11. **Incomplete / P2:** the first concurrent override set makes its create/update and event classification from an unlocked, possibly missing row.
12. **Polish / P2:** the role pages contain an undefined border token, 9px type outside the Atelier scale, and no visible stale-state indicator while placeholder data is shown.

The majority require RBAC policy, audit, concurrency, migration, or cross-layer event changes. No same-session implementation was applied; the ordered work is in action-plan.md.

## Discovery

### Backend surface

- Role and permission models, relations, and the effective-permission resolver live in api/app/Modules/Auth/Models/Role.php:14-33, api/app/Modules/Auth/Models/Permission.php:12-21, and api/app/Modules/Auth/Models/User.php:83-165.
- Admin role CRUD, permission matrix, compare/clone, and per-user override endpoints are defined in api/app/Modules/Admin/Controllers/RoleController.php:22-93, api/app/Modules/Admin/Controllers/UserPermissionOverrideController.php:31-70, and api/app/Modules/Admin/routes.php:34-60.
- Server enforcement is defense-in-depth: CheckPermission and CheckAnyPermission reject unauthenticated or unauthorized requests, while AuthServiceProvider resolves permission-shaped Gate abilities through User::hasPermission (api/app/Common/Middleware/CheckPermission.php:18-29, api/app/Common/Middleware/CheckAnyPermission.php:18-32, api/app/Providers/AuthServiceProvider.php:16-41).
- Role/permission schema is a unique catalog plus a composite role pivot. Override rows have a unique user/permission pair and later gained soft deletes (api/database/migrations/0001_create_roles_table.php:13-19, api/database/migrations/0002_create_permissions_table.php:13-22, api/database/migrations/0003_create_role_permissions_table.php:13-17, api/database/migrations/0127_create_user_permission_overrides_table.php:22-35, api/database/migrations/0444_add_soft_deletes_to_all_tables.php:56-71).
- The role list supports archived filtering and the SPA exposes restore actions, confirming that role restoration is an intended workflow (api/app/Common/Support/TrashedFilter.php:17-25, spa/src/pages/admin/roles/index.tsx:52-80).

### Frontend surface

- Admin role routes are lazy-loaded and permission-gated (spa/src/routes/adminRoutes.tsx:45-85); component-level actions use CanDo/PermissionGuard (spa/src/components/guards/PermissionGuard.tsx:11-22, spa/src/components/guards/CanDo.tsx:41-58).
- The permission matrix loads the role and permission catalog, tracks a baseline, and submits a complete permission set (spa/src/pages/admin/roles/permissions.tsx:74-98, spa/src/pages/admin/roles/permissions.tsx:226-242).
- The per-user override component gates its UI with admin.users.manage_permissions and invalidates queries after mutations (spa/src/pages/admin/users/_components/PermissionOverrides.tsx:51-72, spa/src/pages/admin/users/_components/PermissionOverrides.tsx:78-101).

### Seed and static contract

- RolePermissionSeeder defines the catalog and role defaults, then performs one updateOrCreate/sync loop for all permissions and roles (api/database/seeders/RolePermissionSeeder.php:741-805).
- npm run audit:rbac passed with catalog 237, static references 231, referenced-but-not-seeded 0, and six seeded-only references. Those six are explained by legacy/backward-compatibility comments, auth-only cross-cutting access, or a seeder-only widget reference (api/database/seeders/RolePermissionSeeder.php:299-315, api/database/seeders/RolePermissionSeeder.php:402-405, api/database/seeders/RolePermissionSeeder.php:777-787, api/database/seeders/DashboardWidgetSeeder.php:265-269). They are not unexplained findings.

## Hardening findings

### RBAC-001 — Archived records cannot reach restore handlers

- **Classification:** Broken
- **Priority:** P1
- **Scope:** Medium / recovery and authorization state
- **Evidence:**
  - Role and override models use SoftDeletes (api/app/Modules/Auth/Models/Role.php:14-16, api/app/Modules/Admin/Models/UserPermissionOverride.php:17-20).
  - Both restore routes omit withTrashed (api/app/Modules/Admin/routes.php:41-45, api/app/Modules/Admin/routes.php:53-60).
  - HasHashId documents that soft-deleted route binding is only used when a route opts into withTrashed; normal binding excludes the deleted row (api/app/Common/Traits/HasHashId.php:23-50).
  - The controllers call restore only after implicit binding (api/app/Modules/Admin/Controllers/RoleController.php:49-52, api/app/Modules/Admin/Controllers/UserPermissionOverrideController.php:66-70).
- **Impact:** A role or override that has been archived cannot be restored through the exposed endpoint; binding returns 404 before the restore method runs. For overrides, the delete-to-regrant recovery path is therefore doubly broken.
- **Recommendation:** Add withTrashed to both restore routes, inject the route User into the override restore action, verify ownership, and audit restoration explicitly. Add archived-record HTTP tests.

### RBAC-002 — Removed overrides cannot be regranted

- **Classification:** Broken
- **Priority:** P1
- **Scope:** Medium / permission lifecycle and schema
- **Evidence:**
  - The table has a permanent unique user_id/permission_id pair (api/database/migrations/0127_create_user_permission_overrides_table.php:22-35).
  - The model uses SoftDeletes (api/app/Modules/Admin/Models/UserPermissionOverride.php:17-20), and remove() soft-deletes the row (api/app/Modules/Admin/Services/UserPermissionOverrideService.php:135-176).
  - set() queries through the normal soft-delete scope and then calls updateOrCreate for the same unique pair (api/app/Modules/Admin/Services/UserPermissionOverrideService.php:61-79).
  - The existing upsert test covers grant-to-revoke before deletion, but not delete-to-regrant (api/tests/Feature/Admin/UserPermissionOverrideTest.php:153-175).
- **Impact:** After an administrator removes an override, the next POST for the same user and permission cannot see the trashed row and collides with the unique key. The documented upsert behavior does not hold for the normal lifecycle.
- **Recommendation:** Choose one authoritative lifecycle: restore/update the trashed row inside the service, or replace the schema with a live-row partial unique index plus a deliberate history model. Test grant, update, remove, restore, regrant, expiry, and repeated removal on PostgreSQL.

### RBAC-003 — Permission matrix checkbox toggles twice

- **Classification:** Broken
- **Priority:** P1
- **Scope:** Small / admin UI
- **Evidence:**
  - Each permission row toggles on the containing div click (spa/src/pages/admin/roles/permissions.tsx:769-786).
  - The nested Checkbox also toggles the same slug on change (spa/src/pages/admin/roles/permissions.tsx:824-839).
  - Checkbox renders a label containing the input and does not stop event propagation (spa/src/components/ui/Checkbox.tsx:9-22).
- **Impact:** A pointer click on the checkbox fires the input change and then the row click, changing the state twice. The visible permission therefore appears unable to change through the primary control. The row itself is also a non-keyboard interactive div.
- **Recommendation:** Use one toggle owner: make the row a non-interactive layout container and keep a single labelled checkbox, or stop propagation and add an explicit keyboard-accessible row control. Add a click, keyboard, and save regression test.

### RBAC-004 — Override mutation authorization is inconsistent

- **Classification:** Broken
- **Priority:** P1
- **Scope:** Medium / privilege boundary
- **Evidence:**
  - StoreUserOverrideRequest explicitly states that only system_admin may grant or revoke and authorizes only that role (api/app/Modules/Admin/Requests/StoreUserOverrideRequest.php:19-38).
  - The DELETE and restore routes rely only on admin.users.manage_permissions (api/app/Modules/Admin/routes.php:52-60); the controller has an ownership check for destroy but none for restore (api/app/Modules/Admin/Controllers/UserPermissionOverrideController.php:56-70).
  - Custom roles are deliberately freely editable by permission sync (api/app/Modules/Admin/Services/RoleService.php:268-281), so a custom role can be granted admin.users.manage_permissions.
- **Impact:** A custom-role user with the route permission can remove or restore an override even though the local policy says only system_admin may grant or revoke. Restore also does not enforce that the override belongs to the route user. This creates an inconsistent privilege boundary and makes endpoint behavior depend on HTTP verb.
- **Recommendation:** Decide whether override management is system_admin-only or delegated. Enforce the decision in one policy/service guard for list, create, remove, and restore; always scope the override to the route user; add positive and negative tests for system_admin, delegated custom roles, and weak admins.

### RBAC-005 — Role lifecycle changes are not fully auditable

- **Classification:** Incomplete
- **Priority:** P1
- **Scope:** Medium / auditability
- **Evidence:**
  - Role uses HasHashId and SoftDeletes but not HasAuditLog (api/app/Modules/Auth/Models/Role.php:7-18).
  - Role create, update, and delete perform database mutations without an explicit AuditLog write (api/app/Modules/Admin/Services/RoleService.php:182-223); restore also mutates the model directly without an audit event (api/app/Modules/Admin/Controllers/RoleController.php:49-52).
  - Clone and permission sync do write explicit audit rows (api/app/Modules/Admin/Services/RoleService.php:229-263, api/app/Modules/Admin/Services/RoleService.php:283-306), while lastModifiedFor only searches updated, permissions_synced, and cloned (api/app/Modules/Admin/Services/RoleService.php:66-116).
- **Impact:** Role creation, rename/description changes, archive, and restore can be absent from the administrative audit trail; RoleResource last-modified metadata can remain null or stale. Reviewers cannot reconstruct the complete authority-change history.
- **Recommendation:** Introduce one explicit role lifecycle audit contract, including actor, source, request correlation, old/new values, and restored/deleted actions. Align last-modified aggregation and add exact-count/field assertions for every CRUD and restore path.

### RBAC-006 — RolePermissionSeeder is not atomic

- **Classification:** Incomplete
- **Priority:** P1
- **Scope:** Medium / deployment and authorization consistency
- **Evidence:**
  - The seeder writes every permission before syncing roles and performs each operation inline (api/database/seeders/RolePermissionSeeder.php:741-802).
  - Undefined role permissions are detected only during the role loop, after earlier catalog writes and possibly earlier role syncs (api/database/seeders/RolePermissionSeeder.php:791-799).
  - DatabaseSeeder calls RolePermissionSeeder directly and no surrounding transaction is present in the seeder chain (api/database/seeders/DatabaseSeeder.php:11-39).
- **Impact:** A bad catalog entry, database interruption, or later sync failure can leave a partially updated permission catalog or role matrix. A deployment may then run with an authorization state that is neither the old complete set nor the new intended set.
- **Recommendation:** Validate the complete catalog and role references before writes, then wrap the catalog/role/pivot operation in an explicit transaction or stage-and-swap process. Add failure-injection coverage proving no partial authorization state remains.

### RBAC-007 — Override broadcast contract never reaches the SPA listener

- **Classification:** Broken
- **Priority:** P2
- **Scope:** Medium / backend-SPA event contract
- **Evidence:**
  - The service records PermissionOverrideChanged for set and remove (api/app/Modules/Admin/Services/UserPermissionOverrideService.php:111-117, api/app/Modules/Admin/Services/UserPermissionOverrideService.php:170-176).
  - The backend event broadcasts as permission.override.changed (api/app/Common/Events/PermissionOverrideChanged.php:26-35).
  - usePermissionSync listens for .PermissionsChanged and tears down that different name (spa/src/hooks/usePermissionSync.tsx:25-49); no matching PermissionsChanged event exists elsewhere in the repository.
- **Impact:** Server cache invalidation still protects API authorization, but the affected browser never receives the intended toast or refresh. It can display stale navigation and permission affordances until another refresh.
- **Recommendation:** Align the Echo event name on both sides, document the contract, and add a broadcast-name test plus a hook-level refresh test.

### RBAC-008 — Override broadcast exposes a raw user ID

- **Classification:** Incomplete
- **Priority:** P2
- **Scope:** Small / client-facing data contract
- **Evidence:**
  - The event uses a hash ID for its private channel (api/app/Common/Events/PermissionOverrideChanged.php:26-30), but broadcastWith includes the raw integer user_id (api/app/Common/Events/PermissionOverrideChanged.php:37-45).
  - The repository convention requires API resources to expose hash_id rather than raw integer IDs (CLAUDE.md:570-572); the override resource follows that convention (api/app/Modules/Admin/Resources/UserPermissionOverrideResource.php:23-46).
- **Impact:** A client authorized to the private channel can inspect an unnecessary internal identifier. The private channel limits reachability, but the event contract is inconsistent with the application’s opaque-ID boundary.
- **Recommendation:** Omit user_id because the channel already identifies the target, or send the target hash_id. Add a contract assertion that no client-facing override event contains a raw numeric primary key.

### RBAC-009 — Override mutations can write duplicate audit rows

- **Classification:** Incomplete
- **Priority:** P2
- **Scope:** Medium / audit contract
- **Evidence:**
  - UserPermissionOverride uses HasAuditLog (api/app/Modules/Admin/Models/UserPermissionOverride.php:17-20), whose observer writes created, updated, and deleted rows (api/app/Common/Traits/HasAuditLog.php:13-24).
  - The service also writes explicit audit rows for set and remove (api/app/Modules/Admin/Services/UserPermissionOverrideService.php:83-107, api/app/Modules/Admin/Services/UserPermissionOverrideService.php:144-164).
  - The prune command documents one audit row per pruned override, but the test only checks existence, not cardinality (api/app/Console/Commands/PruneExpiredPermissionOverrides.php:12-22, api/tests/Unit/PruneExpiredPermissionOverridesTest.php:99-111).
- **Impact:** Create/update/delete paths can record both a generic model-event row and a richer service row. The audit feed can show duplicate actions with different metadata, which weakens counts and incident reconstruction.
- **Recommendation:** Choose either the generic observer or an explicit RBAC audit writer, not both. Preserve the richer target/permission context, then assert exactly one row per mutation for API and scheduled-prune paths.

### RBAC-010 — Permission sync has no concurrent-write serialization

- **Classification:** Incomplete
- **Priority:** P2
- **Scope:** Medium / concurrent authority mutation
- **Evidence:**
  - syncPermissions uses a transaction but reads the existing permission set and calls pivot sync without locking the role or checking a revision (api/app/Modules/Admin/Services/RoleService.php:268-314).
  - The audit diff is computed from that unversioned read (api/app/Modules/Admin/Services/RoleService.php:283-306).
  - Current coverage verifies one sequential diff, not two independent administrative writes (api/tests/Feature/Admin/RoleManagementTest.php:113-136).
- **Impact:** Two simultaneous saves can overwrite one another while each audit row describes a stale baseline. A later reviewer may see an apparently valid diff that does not describe the state that actually won.
- **Recommendation:** Lock the role row and re-read the pivot inside the transaction, or add an expected revision and return 409 on stale writes. Add an independent-connection concurrency test covering conflicting grants/removals and audit chronology.

### RBAC-011 — First concurrent override set is classified from a missing row

- **Classification:** Incomplete
- **Priority:** P2
- **Scope:** Medium / concurrent override audit and event semantics
- **Evidence:**
  - set() locks a query for the existing pair, but that query returns no row when two requests perform the first set concurrently (api/app/Modules/Admin/Services/UserPermissionOverrideService.php:57-66).
  - updateOrCreate then handles create-or-update separately, while the explicit audit action and broadcast oldType are derived from the earlier existing variable (api/app/Modules/Admin/Services/UserPermissionOverrideService.php:68-85, api/app/Modules/Admin/Services/UserPermissionOverrideService.php:111-116).
  - The database unique pair can make one request win and the other discover the row only after the attempted insert (api/database/migrations/0127_create_user_permission_overrides_table.php:22-35).
- **Impact:** Concurrent first sets can be recorded as two creations or emit an event with oldType null even when one request actually updated the winner. The locking comment promises a guarantee the missing-row case does not provide.
- **Recommendation:** Serialize on a stable parent row or use a database-native upsert that returns the authoritative prior/current state, then write one deterministic audit/outbox event. Add two-connection first-set tests.

## Polish findings

### RBAC-012 — Role UI has token and stale-state polish gaps

- **Classification:** Polish
- **Priority:** P2
- **Scope:** Small / design-system adherence
- **Evidence:**
  - Module shortcut buttons use border-default-default (spa/src/pages/admin/roles/permissions.tsx:720-745), while Tailwind defines border-default, border-subtle, and border-strong (spa/tailwind.config.ts:100-116). The undefined class does not provide the intended token border.
  - Permission chips and change labels use text-[9px] (spa/src/pages/admin/roles/permissions.tsx:789-812), below the 10px text-2xs floor (docs/DESIGN-SYSTEM.md:205-217).
  - The role list keeps previous data via placeholderData but does not expose isFetching or a Refreshing state (spa/src/pages/admin/roles/index.tsx:56-60, spa/src/pages/admin/roles/index.tsx:278-325). The repository list-page contract requires a visible stale/placeholder state (CLAUDE.md:568-575).
- **Impact:** The permission matrix loses a visual border on three controls, very small labels drift from the type scale, and users can mistake a previous page of roles for current results while filters are changing.
- **Recommendation:** Replace the undefined token, use the nearest Atelier scale token, and show a compact stale/refreshing indicator whenever placeholder data is displayed. Add a focused render assertion if the shared list pattern supports it.

## Controls found working or substantially covered

- Route middleware, FormRequests, Gate hooks, and SPA guards form defense in depth; the backend remains the source of truth.
- User permission resolution caches role permissions by role, reads non-expired overrides fresh, and flushes affected caches after override/sync writes (api/app/Modules/Auth/Models/User.php:105-165, api/app/Modules/Admin/Services/RoleService.php:308-313).
- System roles are protected from direct permission sync and ordinary editing, while clone produces a custom role and records lineage (api/app/Modules/Admin/Services/RoleService.php:192-263, api/tests/Feature/Admin/RoleManagementTest.php:31-58, api/tests/Feature/Admin/RoleManagementTest.php:138-163).
- Resources and private-channel authorization use opaque hash IDs (api/app/Modules/Admin/Resources/RoleResource.php:15-38, api/app/Modules/Admin/Resources/UserPermissionOverrideResource.php:23-46, api/routes/channels.php:43-47), apart from the broadcast payload finding above.
- The static audit has zero unseeded permission references. The six seeded-only results were manually explained above; no unexplained catalog drift was filed.

## Verification performed

| Check | Result |
|---|---|
| Registry regeneration and atomic claim | Passed; registry was regenerated and platform/rbac was claimed with ./audit/scripts/claim-module.sh platform rbac. |
| SPA RBAC static audit | Passed; 237 catalog entries, 231 static references, 0 referenced-but-unseeded permissions. |
| SPA typecheck | Passed; npm run typecheck. |
| SPA lint | Passed; npm run lint. |
| SPA token discipline | Passed; npm run audit:tokens checked 769 files. |
| Backend PHP syntax | Passed for the audited Role, RoleService, UserPermissionOverrideService, override controller, and RolePermissionSeeder files. |
| Diff hygiene | Passed; git diff --check. |
| Backend feature tests | Not run. The repository warns that RefreshDatabase suites must not share ogami_test; no isolated verification database was created during this read-only audit. Existing RBAC tests were inspected for coverage gaps. |
| Browser/visual QA | Not run; static inspection found the matrix toggle and token issues, and no browser engine was required to identify them. |

## Open policy questions

1. Is per-user override management intentionally system_admin-only, or should a custom role be allowed to hold admin.users.manage_permissions?
2. Should deleted override rows be restored as the same lifecycle record, or should removal create immutable history and a new active row?
3. Should concurrent role/override edits reject stale writes with 409, or should the last committed writer win?

## Release decision

📋 Plan Ready. No implementation files were changed in this session. Release is appropriate because the majority of findings change RBAC privilege policy, permission persistence, audit semantics, concurrency behavior, or the backend-SPA event contract. Re-audit after the ordered action plan and isolated PostgreSQL/concurrent tests pass.
