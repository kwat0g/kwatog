# M002 — Platform / RBAC Action Plan

Status: ✅ Verified  
Audit report: audit-report.md  
Fix log: fix-log.md

The ordered implementation is complete. Evidence and verification are recorded in fix-log.md.

## 1. Repair the permission editor toggle (RBAC-003)

- Priority: P1
- Scope: Small
- Session recommendation: same-session-ok
- Files/surface: spa/src/pages/admin/roles/permissions.tsx, spa/src/components/ui/Checkbox.tsx if needed, focused SPA test.
- Work: Remove the double event path. Keep one keyboard-accessible checkbox/label or make the row an explicit accessible control with one handler. Preserve disabled system-role behavior.
- Acceptance: Pointer click changes one permission exactly once; Space/Enter changes it once; save sends the intended set; system roles remain read-only; typecheck, lint, and focused UI tests pass.

## 2. Make archive, restore, and regrant a coherent override/role lifecycle (RBAC-001, RBAC-002)

- Priority: P1
- Scope: Medium / schema and API lifecycle
- Session recommendation: separate-recommended
- Files/surface: admin routes/controllers/services, override migration/model, role/override feature tests, PostgreSQL migration verification.
- Work: Opt restore routes into withTrashed and enforce route-user ownership. Choose whether regrant restores a trashed row or whether a new active row is allowed through a reviewed partial-unique/index migration. Keep history and effective-permission semantics explicit.
- Acceptance: archived roles and overrides restore successfully; wrong-user restore returns 404/403; remove → regrant works; repeated set/remove is idempotent; expired rows remain ignored; audit and cache behavior is deterministic.

## 3. Make override authorization one policy (RBAC-004)

- Priority: P1
- Scope: Medium / security policy
- Session recommendation: separate-recommended
- Files/surface: StoreUserOverrideRequest, override controller/service/policy, routes, custom-role permission tests, SPA copy if delegation is permitted.
- Work: Confirm whether only system_admin may grant/revoke. Enforce that decision for list/create/remove/restore in a shared guard and always bind the override to the route user. Do not rely on the frontend gate.
- Acceptance: system_admin follows the approved policy; weak admins are denied; delegated custom roles are either denied consistently or allowed consistently with explicit policy/tests; cross-user override hashes cannot be acted on through another user URL.

## 4. Establish exactly-once RBAC audit semantics (RBAC-005, RBAC-009)

- Priority: P1
- Scope: Medium / shared audit contract
- Session recommendation: separate-recommended
- Files/surface: Role model/service/controller, UserPermissionOverride model/service, HasAuditLog integration choice, RoleResource metadata query, audit tests.
- Work: Add role create/update/archive/restore audit coverage and choose one writer for override audits. Preserve actor, source, request correlation, old/new permission context, target, IP, and user agent without duplicate generic rows. Include restore and scheduled prune.
- Acceptance: exactly one authoritative audit row per role and override mutation; role last-modified metadata reflects every lifecycle action; clone and permission-sync lineage remains intact; scheduled prune has one row per override; no raw secrets or client IDs are introduced.

## 5. Align the live permission-change event contract and payload (RBAC-007, RBAC-008)

- Priority: P2
- Scope: Medium / backend-SPA boundary
- Session recommendation: separate-recommended
- Files/surface: PermissionOverrideChanged, OutboxEventCodec/dispatch path, usePermissionSync, broadcast/channel tests.
- Work: Pick one event name and use it for broadcast, listen, and teardown. Remove user_id from the payload or replace it with the target hash_id; keep private-channel authorization.
- Acceptance: an override mutation causes one browser refresh/toast after commit; teardown removes the exact listener; payload contains no raw integer IDs; outbox replay preserves the same contract.

## 6. Make permission writes serialization-safe (RBAC-010, RBAC-011)

- Priority: P2
- Scope: Medium / concurrent state mutation
- Session recommendation: separate-recommended
- Files/surface: RoleService::syncPermissions, UserPermissionOverrideService::set, database locking/upsert strategy, two-connection PostgreSQL tests.
- Work: Lock a stable role/user row or require an expected revision before syncing. For first override sets, derive create/update and old/new event data from the authoritative upsert result rather than a possibly missing pre-read. Define 409 versus last-writer-wins behavior.
- Acceptance: conflicting role saves never silently lose a reviewed change; first concurrent override set produces one deterministic lifecycle classification and event; permission cache invalidation and audit chronology match the committed winner.

## 7. Make the permission seeder atomic and preflighted (RBAC-006)

- Priority: P1
- Scope: Medium / deployment safety
- Session recommendation: separate-recommended
- Files/surface: RolePermissionSeeder, DatabaseSeeder or a dedicated seed transaction, seeder tests/commands.
- Work: Validate all catalog and role references before writes, then transact permission upserts, role upserts, and pivot syncs. Keep reruns idempotent and ensure a failure cannot leave partial authorization state.
- Acceptance: injected catalog/role/database failures roll back all RBAC writes; a successful rerun remains idempotent; seed output matches the static audit and all system roles.

## 8. Close role-page polish gaps (RBAC-012)

- Priority: P2
- Scope: Small
- Session recommendation: same-session-ok
- Files/surface: spa/src/pages/admin/roles/permissions.tsx, spa/src/pages/admin/roles/index.tsx, shared list pattern if necessary.
- Work: Replace border-default-default with a defined token, use text-2xs/text-xs rather than 9px arbitrary sizes, and expose a subtle Refreshing/stale state while placeholder data is displayed.
- Acceptance: token discipline remains green, controls retain visible borders across palettes, labels meet the Atelier scale, and filter/page changes visibly distinguish stale data from a completed response.

## Re-audit gate

Before claiming ✅ Verified, run the RBAC backend feature tests against an isolated PostgreSQL database, add two-connection concurrency coverage, run SPA test/typecheck/lint/token/RBAC gates, verify broadcast behavior after commit, and perform authenticated browser checks for the role matrix, archive/restore, and per-user override flows. Update fix-log.md with before/after file:line evidence for every implemented item; leave deferred items as 🔁 Needs Re-audit.
