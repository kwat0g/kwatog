# Tasks R1–R4 — RBAC Enhancement Execution Plan

> Series R from [`docs/NEW-TASKS-V2.md`](../docs/NEW-TASKS-V2.md:826). Goal: turn the seed-only RBAC into a UI-driven, per-user, component-aware, role-defaulted permission system.

---

## 1. Reality check vs. the task spec

The task spec was written before the backend Admin module was built. A survey of the codebase shows that **roughly 60% of R1's backend already exists** and several R2/R3 prerequisites are also in place. The plan below reflects the actual delta, not a from-scratch build.

### What is already implemented (do NOT rewrite)

| Component | File | Status |
|---|---|---|
| `roles`, `permissions`, `role_permissions` tables | [`0001`](../api/database/migrations/0001_create_roles_table.php), [`0002`](../api/database/migrations/0002_create_permissions_table.php), [`0003`](../api/database/migrations/0003_create_role_permissions_table.php) | Exists |
| `Role`, `Permission` Eloquent models | [`api/app/Modules/Auth/Models/`](../api/app/Modules/Auth/Models) | Exists |
| Role CRUD + permission sync API | [`RoleController`](../api/app/Modules/Admin/Controllers/RoleController.php) under `/api/v1/admin/roles` | Exists |
| Permission matrix API | [`PermissionController::matrix`](../api/app/Modules/Admin/Controllers/PermissionController.php) at `GET /api/v1/admin/permissions/matrix` | Exists |
| Role + permission seeding (12 roles) | [`RolePermissionSeeder`](../api/database/seeders/RolePermissionSeeder.php) | Exists |
| Admin Users module (list/detail/create/role-change/reset/lock) | [`UserAdminController`](../api/app/Modules/Admin/Controllers/UserAdminController.php), [`UserAdminService`](../api/app/Modules/Admin/Services/UserAdminService.php) | Exists (Task U2 already shipped) |
| Login history table + service | [`0119_create_login_history_table`](../api/database/migrations/0119_create_login_history_table.php), [`LoginHistoryService`](../api/app/Modules/Admin/Services/LoginHistoryService.php) | Exists |
| `usePermission` hook with `can`/`canAny`/`canAll`/`isAdmin` | [`spa/src/hooks/usePermission.ts`](../spa/src/hooks/usePermission.ts:1) | Exists |
| `CheckPermission` middleware | [`api/app/Common/Middleware/CheckPermission.php`](../api/app/Common/Middleware/CheckPermission.php:16) | Exists; needs override-aware extension for R2 |

### What is missing (the actual scope of R1–R4)

- **R1:** Frontend pages for roles + clone endpoint + per-role audit logging on permission sync.
- **R2:** `user_permission_overrides` table, model, service, middleware integration, API endpoints, frontend section.
- **R3:** `<CanDo>` wrapper component + retrofit across action buttons (NOT routes — those already use `PermissionGuard`).
- **R4:** `dashboard_layouts` + `dashboard_widgets` tables (the task spec falsely claims these exist), seed of role defaults, copy-on-first-login logic.

---

## 2. Schema corrections to surface

The task spec for R4 says *"`dashboard_layouts` table already exists"* — it does not. [`docs/SCHEMA.md`](../docs/SCHEMA.md) has no such table and there is no migration for it. R4 must therefore include the migrations for `dashboard_widgets` (catalog) and `dashboard_layouts` (per-user/per-role widget placement). This will be flagged as an addendum in [`docs/SCHEMA.md`](../docs/SCHEMA.md).

---

## 3. Execution order (within Series R)

```
R1 (frontend-only delta) → R2 (overrides) → R3 (CanDo + retrofit) → R4 (dashboard defaults)
```

R2 must land before R3's full retrofit, because `<CanDo>` will read the user's effective permissions which include overrides.

---

## 4. Task R1 — Dynamic Role Management UI

### Backend delta (small)

Migration `0126_add_is_system_to_roles_table.php`
- Add `is_system` boolean default `false` to [`roles`](../api/database/migrations/0001_create_roles_table.php) so the UI can render the **System / Custom** badge and forbid deleting/renaming system roles. Backfill: set `is_system = true` where slug ∈ {`system_admin`, `hr_officer`, `finance_officer`, `plant_manager`, `ppc_head`, `purchasing_officer`, `warehouse_staff`, `qc_inspector`, `maintenance_tech`, `impex_officer`, `dept_head`, `employee`} (the 12 seeded roles).
- Update [`RolePermissionSeeder`](../api/database/seeders/RolePermissionSeeder.php) to set `is_system = true` on insert.

[`RoleController`](../api/app/Modules/Admin/Controllers/RoleController.php) + [`RoleService`](../api/app/Modules/Admin/Services/RoleService.php) additions:
- `POST /api/v1/admin/roles/{role}/clone` → body `{ name, slug, description? }` → duplicates role with all permission rows, `is_system = false`. New service method `clone(Role $source, array $data): Role` wrapped in `DB::transaction`.
- Hard-block via existing `abort_if($role->is_system, 422, ...)` on `update`/`destroy` of system roles (extend the existing `system_admin`-only check).
- New FormRequest [`CloneRoleRequest.php`](../api/app/Modules/Admin/Requests/CloneRoleRequest.php) — same shape as [`StoreRoleRequest`](../api/app/Modules/Admin/Requests/StoreRoleRequest.php:13) plus `source_role_id`.
- Audit-log every permission sync change: capture diff `{added: [], removed: []}` in `audit_logs` via [`HasAuditLog`](../api/app/Common/Traits) — currently the sync just calls `$role->permissions()->sync()` with no diff capture.

[`RoleResource`](../api/app/Modules/Admin/Resources/RoleResource.php): expose `is_system` and `type` (`'System' | 'Custom'`).

### Frontend (the bulk of R1)

**Types** ([`spa/src/types/admin.ts`](../spa/src/types/admin.ts)) — extend existing `Role` interface with `is_system`, `users_count`, `permissions_count`, optional nested `permissions[]`. Add `PermissionMatrix` shape `{ module: string, items: Array<{ slug, name, description }> }[]`.

**API client** ([`spa/src/api/admin.ts`](../spa/src/api/admin.ts) — extend if exists, otherwise create) — add `rolesApi.list/show/create/update/delete/clone/syncPermissions` and `permissionsApi.matrix()`.

**Pages (follow [`docs/PATTERNS.md`](../docs/PATTERNS.md) §10/12/19 exactly):**

1. [`spa/src/pages/admin/roles/index.tsx`](../spa/src/pages/admin/roles/index.tsx) — list page.
   - All 5 mandatory states (loading skeleton, error, empty, data, stale via `placeholderData`).
   - Columns: Role Name, Type (Chip: `success` for System / `neutral` for Custom), Users (mono), Actions.
   - 32px rows, uppercase letter-spaced headers, `font-mono tabular-nums` on the Users column.
   - Action menu (`⋯`): **Edit Permissions**, **Clone**, **View Users**, **Delete** (disabled with tooltip when `is_system` or `users_count > 0`).
   - `<CanDo permission="admin.roles.manage">` (once R3 is done; until then a plain `usePermission().can(...)` check) on the Create button.

2. [`spa/src/pages/admin/roles/create.tsx`](../spa/src/pages/admin/roles/create.tsx) — create form ([`docs/PATTERNS.md`](../docs/PATTERNS.md) §12).
   - Zod schema mirroring [`StoreRoleRequest`](../api/app/Modules/Admin/Requests/StoreRoleRequest.php:20) (name max 50, slug `alpha_dash` unique, description nullable).
   - Toggle: **Start from scratch / Clone from existing role**. If clone, show source-role `<Select>` (populated from `rolesApi.list({ per_page: 100 })`).
   - Submit → POST `/admin/roles` or `/admin/roles/{source}/clone` → toast.success → `navigate(/admin/roles/${id}/permissions)`.
   - Server-side 422 mapping via `setError`, disabled-while-pending submit, cancel button.

3. [`spa/src/pages/admin/roles/[id]/permissions.tsx`](../spa/src/pages/admin/roles/[id]/permissions.tsx) — **the key page**.
   - Two `useQuery`s: role (with permissions) + permission matrix (grouped by module).
   - Local state: `selected: Set<string>` of permission slugs, initialized from role permissions.
   - Diff badge near header: `Chip variant="info"` showing `N changes unsaved` (count = symmetric diff).
   - Filter bar: module dropdown + permission search (filters tree client-side).
   - Tree render: section header per module = `text-2xs uppercase tracking-wider text-muted` with `[Select All]` text-button toggling all module slugs.
   - Each leaf: `<Checkbox>` + slug (mono) + name + description (text-muted).
   - **Save** button is hidden when `selected` matches initial set; appears with `loading` state during mutation.
   - Mutation: `PUT /admin/roles/{id}/permissions` with `permission_slugs: [...selected]` → on success `queryClient.invalidateQueries(['admin','roles', id])` + toast + reset baseline.
   - Disable all controls if `role.is_system && role.slug === 'system_admin'` (system_admin is `*`).

**Routes** ([`spa/src/App.tsx`](../spa/src/App.tsx)) — three lazy imports under `<AuthGuard><AppLayout></AuthGuard>` → `<ModuleGuard module="admin">` → `<PermissionGuard permission="admin.roles.manage">` for each. `/admin/roles`, `/admin/roles/create`, `/admin/roles/:id/permissions`.

**Sidebar:** add **Roles & Permissions** entry under the **Admin** section, gated by `admin.roles.manage`.

---

## 5. Task R2 — Per-User Permission Overrides

### Backend

Migration `0127_create_user_permission_overrides_table.php` — exact spec from [`docs/NEW-TASKS-V2.md`](../docs/NEW-TASKS-V2.md:911):

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | foreignId, constrained `users`, cascadeOnDelete | |
| `permission_id` | foreignId, constrained `permissions`, cascadeOnDelete | |
| `type` | string(10) | `Rule::in(['grant','revoke'])` enforced at request layer |
| `granted_by` | foreignId, constrained `users` | actor for audit |
| `reason` | text, nullable on the column but **required** in the FormRequest |
| `expires_at` | timestamp, nullable | |
| `created_at`, `updated_at` | timestamps | |

Indexes: `unique(user_id, permission_id)` (one override per user per permission), `index('expires_at')` for sweeper queries.

Backed enum `PermissionOverrideType` (`grant`, `revoke`) under [`api/app/Common/Enums/`](../api/app/Common/Enums).

Model [`UserPermissionOverride`](../api/app/Modules/Admin/Models/UserPermissionOverride.php) — `HasHashId`, `HasAuditLog`, casts `expires_at => datetime`, `type => PermissionOverrideType::class`. Relations: `user`, `permission`, `grantedBy`.

Service [`UserPermissionOverrideService`](../api/app/Modules/Admin/Services/UserPermissionOverrideService.php):
- `list(User $user)` — returns active (non-expired) overrides eager-loaded with `permission`, `grantedBy`.
- `grant(User $user, array $data)` — `DB::transaction`, upsert by `(user_id, permission_id)`, set `type='grant'`. Bust [`User::permission_slugs`](../api/app/Modules/Auth/Models/User.php:88) cache.
- `revoke(User $user, array $data)` — same pattern, `type='revoke'`.
- `remove(UserPermissionOverride $override)` — delete + bust cache.

FormRequests: `StoreUserOverrideRequest` (`permission_slug` exists, `type` in enum, `reason` required min 5, `expires_at` nullable date `after:now`), authorization gate `admin.users.manage_permissions` (new permission).

Resource [`UserPermissionOverrideResource`](../api/app/Modules/Admin/Resources/UserPermissionOverrideResource.php) — `id` (hash_id), `permission` (slug, name, description), `type`, `granted_by` (id, name), `reason`, `expires_at` (ISO), `is_expired` (computed).

Routes added to [`api/app/Modules/Admin/routes.php`](../api/app/Modules/Admin/routes.php) under existing `admin.users.manage_permissions`-gated group:
- `GET    /admin/users/{user}/overrides`
- `POST   /admin/users/{user}/overrides`
- `DELETE /admin/users/{user}/overrides/{override}`

**Permission resolution change** (the critical wiring):

Modify [`User::getPermissionSlugsAttribute`](../api/app/Modules/Auth/Models/User.php:88):

```php
return Cache::remember("auth:permissions:{$this->id}", 300, function () {
    $rolePerms = $this->role
        ? $this->role->permissions()->pluck('permissions.slug', 'permissions.id')->all()
        : [];
    // [permission_id => slug]

    $overrides = UserPermissionOverride::query()
        ->where('user_id', $this->id)
        ->where(fn($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
        ->with('permission:id,slug')
        ->get();

    foreach ($overrides as $o) {
        if ($o->type === PermissionOverrideType::Grant) {
            $rolePerms[$o->permission_id] = $o->permission->slug;
        } else { // revoke
            unset($rolePerms[$o->permission_id]);
        }
    }
    return array_values($rolePerms);
});
```

[`CheckPermission`](../api/app/Common/Middleware/CheckPermission.php:16) does not need to change — it delegates to `hasPermission()` which reads `permission_slugs`. **However**, the `system_admin` short-circuit on line 23 currently bypasses overrides entirely. The spec implies revokes should still work for system_admin too; we will keep the short-circuit (system_admin is intended as a hard escape hatch) but document the carve-out in the override service so the UI shows a banner *"Overrides do not apply to system_admin"* when listing for that role.

Daily sweeper: register a Laravel scheduled command `permissions:expire-overrides` in `bootstrap/app.php` that bulk-deletes rows where `expires_at < now()` (or just relies on the runtime filter — DB cleanup is not required for correctness, only for housekeeping).

### Frontend (R2)

**Types** — `UserPermissionOverride` interface in [`spa/src/types/admin.ts`](../spa/src/types/admin.ts).

**API** — `userOverridesApi` in [`spa/src/api/admin.ts`](../spa/src/api/admin.ts) (`list/grant/revoke/remove`).

**Component:** new section embedded in the existing user detail page (already implemented under Series U). Sub-component [`spa/src/pages/admin/users/_components/PermissionOverrides.tsx`](../spa/src/pages/admin/users/_components/PermissionOverrides.tsx):
- Table (32px rows, mono permission slug column): Permission, Type chip (`success` for GRANTED / `danger` for REVOKED), Granted by, Reason (truncate), Expires (mono date or "No expiry"), Actions (Remove button gated by permission).
- All 5 page states (skeleton, error, empty `EmptyState`, data, stale).
- **Add Override modal:** searchable permission select (uses permission matrix endpoint), Type radio (Grant/Revoke), Reason textarea (required, 5–500 char counter), Expiry date picker (optional). Submit → toast → invalidate `['admin','users',id,'overrides']`.

If R2 ships before the user detail page is touched, mount the section behind the existing **Permission Overrides** placeholder area mentioned in [`docs/NEW-TASKS-V2.md:925`](../docs/NEW-TASKS-V2.md:925).

---

## 6. Task R3 — Frontend RBAC (Component-Level Permission Checks)

### The hook is already enhanced

[`usePermission`](../spa/src/hooks/usePermission.ts:1) already exposes `can/canAny/canAll/isAdmin`. **No change needed** to the hook itself.

### New component

[`spa/src/components/guards/CanDo.tsx`](../spa/src/components/guards/CanDo.tsx):

```tsx
interface CanDoProps {
  permission: string | string[];
  requireAll?: boolean;     // default false
  fallback?: ReactNode;     // default null
  children: ReactNode;
}
```

Implementation reads `usePermission()`, normalizes `permission` to `string[]`, picks `canAll` or `canAny`, returns `children` or `fallback`. Single-line behavior, no extra DOM wrapper. Re-exported from [`spa/src/components/guards/index.ts`](../spa/src/components/guards/index.ts).

### Retrofit (the bulk of R3)

Apply `<CanDo>` (or the `disabled+title` fallback variant) to action buttons in these locations. Routes already use [`PermissionGuard`](../spa/src/components/guards/PermissionGuard.tsx) — do **not** duplicate at the route level.

| Module | Buttons to wrap | Permission slug |
|---|---|---|
| HR / employees list + detail | Edit, Delete, **Provision Account**, **Reset Password**, **View Sensitive** | `hr.employees.edit`, `hr.employees.delete`, `hr.employees.provision_account`, `hr.employees.view_sensitive` |
| HR / leaves | Approve, Reject (Dept Head only) | `leave.approve` |
| Payroll | **Finalize Period**, **Post to GL**, **Generate Bank File**, **Send Payslips** | `payroll.periods.finalize`, `payroll.periods.post_gl`, `payroll.bank_file.generate`, `payroll.payslips.send` |
| Purchasing | Approve PR, Reject PR, **Send PO** | `purchasing.pr.approve`, `purchasing.po.send` |
| Inventory | Edit, **Adjust Stock**, **Bulk Update** | `inventory.items.edit`, `inventory.adjust`, `inventory.bulk` |
| Production | **Record Output**, **Mark Breakdown**, **Confirm Schedule** | `production.output.record`, `production.machines.breakdown`, `production.schedule.confirm` |
| Quality | **Pass / Fail Inspection**, **Close NCR** | `quality.inspections.decide`, `quality.ncr.close` |
| Accounting | **Post**, **Finalize**, **Send Invoice**, **Record Payment** | `accounting.post`, `accounting.invoices.send`, `accounting.collections.record` |
| CRM | **Confirm SO**, **Cancel SO** | `crm.sales_orders.confirm`, `crm.sales_orders.cancel` |
| Maintenance | **Complete WO** | `maintenance.wo.complete` |
| Admin | All buttons in roles + users + audit | `admin.roles.manage`, `admin.users.manage`, `admin.users.manage_permissions` |
| DataTable bulk-action bar (every list page) | "Bulk …" buttons | per-action permission |

**Default fallback policy:** hide entirely, except for high-stakes finalize/post buttons where the spec asks for a disabled-with-tooltip variant — pass `fallback={<Button size="sm" disabled title="You don't have permission to ...">Label</Button>}`.

**Sidebar:** the existing [`Sidebar`](../spa/src/components/layout/Sidebar.tsx) already filters by permission in the nav item config. Audit each entry; add `<CanDo>` only where current filtering is missing.

### Verification step (mandatory before R3 is "done")

For each retrofitted page, log in as the matching seeded role from [`RolePermissionSeeder`](../api/database/seeders/RolePermissionSeeder.php) and confirm: (a) buttons hidden when permission absent, (b) buttons shown when present, (c) backend 403 still fires if a stale UI state lets the user click anyway. The 403 path must remain the source of truth — frontend guards are UX only.

---

## 7. Task R4 — Role-Based Dashboard Defaults

### Backend — schema first (the spec misstates that tables exist)

Migration `0128_create_dashboard_widgets_table.php` — catalog:

| Column | Type |
|---|---|
| `id` | bigint PK |
| `key` | string(100) unique — e.g. `production.kpi`, `finance.cash_position` |
| `name` | string(100) |
| `description` | text nullable |
| `module` | string(50) — used to gate widget by `feature:` middleware |
| `permission` | string(100) nullable — required permission to render |
| `default_w` / `default_h` | int — grid units |
| `created_at` / `updated_at` | timestamps |

Migration `0129_create_dashboard_layouts_table.php`:

| Column | Type |
|---|---|
| `id` | bigint PK |
| `owner_type` | string(20) — `'role'` or `'user'` |
| `owner_id` | unsignedBigInteger |
| `widget_key` | string(100) FK → `dashboard_widgets.key` |
| `position_x` / `position_y` | int |
| `width` / `height` | int |
| `created_at` / `updated_at` | timestamps |

Indexes: composite `(owner_type, owner_id)`. No FK on `widget_key` (string match) so widgets can be soft-removed without losing layouts.

Seeder [`DashboardWidgetSeeder`](../api/database/seeders/DashboardWidgetSeeder.php) — registers every widget referenced in [`docs/NEW-TASKS-V2.md:1027`](../docs/NEW-TASKS-V2.md:1027) (Production KPIs, Chain Stage Breakdown, Machine Util, OEE Gauges, QC Pareto, Alerts, Active WOs, Cash Position, AR Aging, AP Aging, Revenue MTD, Unpaid Invoices, Upcoming Payables, Headcount, On Leave Today, Pending Approvals, Probation Alerts, Upcoming Payroll, Open PRs, Open POs, Supplier Performance, Overdue Deliveries, Low Stock, Pending Inspections, Defect Pareto, Open NCRs, Pass Rate, Pending GRNs, Low Stock Items, Pending Material Issues, Delivery Schedule, Payslip Summary, Leave Balance, DTR Today, Pending Requests).

Seeder [`DashboardRoleLayoutSeeder`](../api/database/seeders/DashboardRoleLayoutSeeder.php) — inserts default `dashboard_layouts` rows with `owner_type='role'` and `owner_id = roles.id`, mapping each role to its widget set per the spec table:

| Role | Widgets |
|---|---|
| `plant_manager` | production.kpi, chain.stage_breakdown, machine.utilization, oee.gauges, qc.pareto, alerts, production.active_wo |
| `ppc_head` | production.gantt_mini, mrp.shortages, machine.status, production.wo_breakdown, material.reservations |
| `finance_officer` | finance.cash_position, finance.ar_aging, finance.ap_aging, finance.revenue_mtd, finance.unpaid_invoices, finance.upcoming_payables |
| `hr_officer` | hr.headcount, hr.on_leave_today, approvals.pending, hr.probation_alerts, payroll.upcoming |
| `purchasing_officer` | purchasing.open_prs, purchasing.open_pos, purchasing.supplier_perf, supply.overdue_deliveries, inventory.low_stock |
| `qc_inspector` | qc.pending_inspections, qc.pareto, qc.open_ncrs, qc.pass_rate |
| `warehouse_staff` | inventory.pending_grns, inventory.low_stock, inventory.pending_issues, supply.delivery_schedule |
| `dept_head` | approvals.pending, hr.team_on_leave_today, hr.team_dtr_today |
| `employee` | self.payslip_summary, self.leave_balance, self.dtr_today, self.pending_requests |

Default grid: 2 columns × N rows, widgets stacked top-to-bottom in spec order, `width=12`, `height=4` (12-col grid assumption).

Service [`DashboardLayoutService`](../api/app/Modules/Dashboard/Services/DashboardLayoutService.php):
- `getEffectiveLayout(User $user): array` — returns user's personal layout if any rows exist for `(owner_type='user', owner_id=$user->id)`, otherwise the role's default. Falls back to empty array if neither exists.
- `cloneRoleDefaultToUser(User $user): void` — inside `DB::transaction`, copies role rows to user rows. Idempotent: no-op if user rows already exist.
- `saveUserLayout(User $user, array $widgets): void` — replaces user rows in transaction.

Hook the **first-login** clone into [`AuthService::login`](../api/app/Modules/Auth/Services/AuthService.php) (post-success branch): after `$user->update(['last_activity' => now()])`, if no user-owned dashboard rows exist and the user has a role with defaults, dispatch (or call synchronously) `DashboardLayoutService::cloneRoleDefaultToUser($user)`. Skip for `system_admin`.

API endpoints (extend or create [`api/app/Modules/Dashboard/routes.php`](../api/app/Modules/Dashboard/routes.php)):
- `GET    /api/v1/dashboard/widgets` → catalog (filtered by user's permissions; widgets requiring missing permissions are omitted)
- `GET    /api/v1/dashboard/layout` → effective layout for current user
- `PUT    /api/v1/dashboard/layout` → save user layout `{widgets: [{key, x, y, w, h}, ...]}`
- `POST   /api/v1/dashboard/layout/reset` → delete user rows so the role default takes effect again
- `GET    /api/v1/admin/dashboard/role-defaults/{role}` → admin: view a role's default
- `PUT    /api/v1/admin/dashboard/role-defaults/{role}` → admin: edit role default (gated by `admin.roles.manage`)

FormRequests, Resources, Controllers per [`docs/PATTERNS.md`](../docs/PATTERNS.md) §3–§7.

### Frontend (R4)

This task is **layout-only** if the existing dashboard already renders widgets from a config; otherwise R4 includes the dashboard scaffolding work. Verify by reading [`spa/src/pages/dashboard/index.tsx`](../spa/src/pages/dashboard/index.tsx) before starting. Either way:

- API client [`spa/src/api/dashboard.ts`](../spa/src/api/dashboard.ts): `getLayout`, `saveLayout`, `resetLayout`, `listWidgets`.
- Type `DashboardWidget`, `DashboardLayoutItem` in [`spa/src/types/index.ts`](../spa/src/types/index.ts).
- The dashboard page reads its layout from `useQuery(['dashboard','layout'])` (5 states), maps each item key to a registered React widget component (registry lives in [`spa/src/components/dashboard/registry.ts`](../spa/src/components/dashboard/registry.ts)), and renders unknown keys as a `<EmptyState>` tile to fail safely.
- "Reset to default" button in the dashboard header behind `<CanDo permission="dashboard.layout.reset">` (added permission slug; default-granted to all roles in the seeder).

> **Out of scope for R4 (explicitly):** drag/resize/customize UI. The spec mentions *"They can then customize it (drag/resize)"* — that requires `react-grid-layout` and is part of Task X4 / cut-scope. R4 ships **read-only** role-default rendering plus the reset endpoint.

---

## 8. Permissions added by this series

Add to [`RolePermissionSeeder`](../api/database/seeders/RolePermissionSeeder.php):

| Slug | Granted to |
|---|---|
| `admin.users.manage_permissions` | `system_admin` (auto via `*`) |
| `dashboard.layout.reset` | all roles |
| `dashboard.role_defaults.manage` | `system_admin` |

Idempotency: re-running the seeder must not duplicate or remove unrelated rows — the existing seeder already uses `firstOrCreate` + `sync`, so just append entries.

---

## 9. Tests (per [`docs/PATTERNS.md`](../docs/PATTERNS.md) testing-strategy expectations)

PHPUnit Feature tests under [`api/tests/Feature/Admin/`](../api/tests/Feature/Admin):

- `RoleCloneTest` — clone copies all permissions, sets `is_system=false`, rejects clone of slug already taken.
- `RoleSystemProtectionTest` — `update`/`destroy` of `is_system=true` returns 422.
- `RolePermissionSyncAuditTest` — sync writes audit_log row with `{added, removed}` diff.
- `UserPermissionOverrideGrantTest` — granted permission appears in `permission_slugs` and clears the cache.
- `UserPermissionOverrideRevokeTest` — revoked permission no longer appears, even though role grants it.
- `UserPermissionOverrideExpiryTest` — expired override is ignored.
- `DashboardLayoutCloneOnLoginTest` — first login clones role default; second login is no-op.
- `DashboardLayoutEffectiveLayoutTest` — user override beats role default.
- `DashboardWidgetPermissionFilterTest` — widgets requiring unheld permissions are stripped from the catalog.
- **403-without-permission test** for every new endpoint (the kwatog minimum-viable test set).

Vitest specs under [`spa/src/`](../spa/src) `__tests__`:

- `CanDo.test.tsx` — renders/hides children based on `usePermission` mock; `requireAll` toggle works; `fallback` renders.
- `RolesList.test.tsx` — system role shows correct chip, delete button disabled on system or in-use role.
- `PermissionsEditor.test.tsx` — toggling checkbox flips diff badge; Save invalidates query.

---

## 10. File creation/modification summary (high level)

### Backend

**New migrations:** `0126`, `0127`, `0128`, `0129`.
**New PHP files:** `CloneRoleRequest`, `UserPermissionOverride` (model), `PermissionOverrideType` (enum), `UserPermissionOverrideService`, `StoreUserOverrideRequest`, `UserPermissionOverrideResource`, `DashboardLayoutService`, `DashboardWidget` (model), `DashboardLayout` (model), `DashboardWidgetSeeder`, `DashboardRoleLayoutSeeder`, `DashboardLayoutController` + supporting Requests/Resources, plus matching Feature tests.
**Modified:** [`User`](../api/app/Modules/Auth/Models/User.php) (override-aware permission resolution), [`RoleService`](../api/app/Modules/Admin/Services/RoleService.php) (clone + diff audit + system protection), [`RoleController`](../api/app/Modules/Admin/Controllers/RoleController.php) (clone route), [`Admin/routes.php`](../api/app/Modules/Admin/routes.php) (override + clone + dashboard admin routes), [`Dashboard/routes.php`](../api/app/Modules/Dashboard/routes.php), [`AuthService`](../api/app/Modules/Auth/Services/AuthService.php) (first-login dashboard clone), [`RolePermissionSeeder`](../api/database/seeders/RolePermissionSeeder.php) (new permissions + `is_system`), [`docs/SCHEMA.md`](../docs/SCHEMA.md) (document the four new tables).

### Frontend

**New types**: extend [`spa/src/types/admin.ts`](../spa/src/types/admin.ts), [`spa/src/types/index.ts`](../spa/src/types/index.ts) for dashboard.
**New API clients**: [`spa/src/api/admin.ts`](../spa/src/api/admin.ts) extensions, [`spa/src/api/dashboard.ts`](../spa/src/api/dashboard.ts).
**New pages:** `spa/src/pages/admin/roles/index.tsx`, `spa/src/pages/admin/roles/create.tsx`, `spa/src/pages/admin/roles/[id]/permissions.tsx`.
**New components:** `spa/src/components/guards/CanDo.tsx`, `spa/src/pages/admin/users/_components/PermissionOverrides.tsx`, `spa/src/components/dashboard/registry.ts`.
**Modified:** [`spa/src/App.tsx`](../spa/src/App.tsx) (3 lazy routes for R1), [`spa/src/components/layout/Sidebar.tsx`](../spa/src/components/layout/Sidebar.tsx) (Roles entry), every list/detail page identified in §6's retrofit table (action buttons wrapped in `<CanDo>`), [`spa/src/pages/dashboard/index.tsx`](../spa/src/pages/dashboard/index.tsx) (layout-driven render).
**New tests:** as listed in §9.

---

## 11. Pre-implementation verification checklist

Before opening a PR, the implementer must run [`.roo/skills/kwatog/code-quality-gate.md`](../.roo/skills/kwatog/code-quality-gate.md):

1. `cd api && composer test -- --filter='Admin|Dashboard|UserPermissionOverride'`
2. `cd api && ./vendor/bin/phpstan analyse` (or repo-configured static analysis)
3. `cd spa && npm run lint`
4. `cd spa && npm run typecheck`
5. `cd spa && npm test`
6. Confirm `php artisan migrate:fresh --seed` succeeds end-to-end with the new migrations + seeders.
7. Smoke-test at least three roles (system_admin, hr_officer, employee) and verify R1 + R3 + R4 visibly differ.

The completion message MUST include the gate command outputs (per the kwatog quality-gate mode).

---

## 12. Risks and call-outs

- **`system_admin` short-circuit + revokes:** documented carve-out in §5; if product wants revokes to bind even system_admin, remove the `slug === 'system_admin'` early-return in [`CheckPermission`](../api/app/Common/Middleware/CheckPermission.php:23) and rely solely on `permission_slugs`. That is a deliberate policy decision, not a bug — flag it on the PR.
- **Permission cache:** the 5-minute cache on [`User::permission_slugs`](../api/app/Modules/Auth/Models/User.php:88) means override changes are not instant. Service methods MUST `Cache::forget("auth:permissions:{$user->id}")` after every mutation. Reviewers should grep for `Cache::remember("auth:permissions` to ensure invalidation is paired.
- **Schema doc drift:** [`docs/SCHEMA.md`](../docs/SCHEMA.md) is the canonical source. Updating it in the same PR is mandatory per [`.roo/skills/kwatog/add-database-migration.md`](../.roo/skills/kwatog/add-database-migration.md).
- **R3 retrofit churn:** the retrofit touches a large number of files. Land it as a single mechanical PR (no behavior changes other than visibility) so review can scan diffs quickly.
- **R4 widget components:** a stub registry entry rendering `<EmptyState>` is acceptable for widgets whose underlying data services are not yet wired (e.g., supplier performance — Task F4). Do not block R4 on those; ship the layout machinery and stub-render unsupported keys.
