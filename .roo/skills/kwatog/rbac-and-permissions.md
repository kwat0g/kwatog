---
name: rbac-and-permissions
description: Use when adding, renaming, or gating any permission. kwatog uses module.resource.action permission strings enforced in three places (route, FormRequest, UI). Forgetting to seed a new permission causes 403 storms in prod.
---

# RBAC and Permissions (kwatog)

## The naming rule

Permission strings are always `module.resource.action`, lowercase, dot-separated.

Examples that exist in the codebase:

- `crm.products.view`, `crm.products.manage`
- `crm.price_agreements.view`, `crm.price_agreements.manage`
- `alerts.view`, `alerts.dismiss`
- `accounting.invoices.approve`

Common actions: `view`, `manage` (catch-all CRUD), `approve`, `dismiss`, `export`. Use specific verbs when more granular control is needed; otherwise prefer `view` + `manage`.

## The three enforcement points (all required)

A new permission must be applied in all three places, or it does not actually protect the resource:

### 1. Route middleware ([`api/app/Modules/<Module>/routes.php`](../../../api/app/Modules/CRM/routes.php))

```php
Route::middleware(['auth:sanctum', 'feature:crm'])->prefix('crm')->group(function () {
    Route::get('/products', [ProductController::class, 'index'])
        ->middleware('permission:crm.products.view');
    Route::post('/products', [ProductController::class, 'store'])
        ->middleware('permission:crm.products.manage');
});
```

### 2. FormRequest `authorize()`

```php
public function authorize(): bool
{
    return $this->user()?->hasPermission('crm.products.manage') ?? false;
}
```

This is a defense-in-depth check; the route middleware should already have rejected unauthorized requests. Do **not** delete the FormRequest authorize and rely on the middleware alone - the FormRequest is the contract and is the layer tested by Feature tests.

### 3. SPA UI gating

```tsx
import { usePermission } from '@/hooks/usePermission';

const { can } = usePermission();
{can('crm.products.manage') && (
  <Button onClick={...}>Edit</Button>
)}
```

UI gating is for UX (don't show buttons that 403). The API enforcement above is the actual security boundary. Both are required.

## Seeding a new permission (DO NOT FORGET)

A new permission string in middleware/authorize/UI does **not** exist in the database until you seed it. Without seeding:

- Even superadmin users will get 403 because no role has the permission.
- Permission management UI cannot grant the permission because it does not exist.

The seeders live in [`api/database/seeders/`](../../../api/database/seeders/). Find the existing permission seeder for the module (commonly named `<Module>PermissionSeeder.php` or aggregated in `PermissionSeeder.php` or `RolePermissionSeeder.php`):

```bash
ls api/database/seeders/
grep -l 'permissions' api/database/seeders/*.php
```

Add the new permission to the seed array AND assign it to the appropriate roles (e.g., admin, manager).

After editing the seeder:

```bash
cd api
php artisan migrate:fresh --seed --env=testing  # locally to verify
```

For production, this seeder will run automatically on the next deploy (see [`docs/DEPLOY.md`](../../../docs/DEPLOY.md)) **only if it is idempotent** - usually `firstOrCreate` on the permission name. Verify the existing seeder is idempotent before adding new rows.

## Renaming a permission - dangerous

Renaming `crm.products.manage` to `crm.products.edit` requires:

1. Update every route middleware that referenced the old name.
2. Update every FormRequest `authorize()`.
3. Update every SPA `can(...)` call.
4. Migrate existing role-permission rows: a one-shot data migration that copies the old permission's role assignments to the new permission and then deletes the old one.
5. Decide whether to keep the old permission as an alias for one release or to break.

If you cannot do all 5 in one PR, **add the new permission alongside the old one** and grant both. Migrate over multiple PRs.

## Roles

Roles live in [`api/database/migrations/0001_create_roles_table.php`](../../../api/database/migrations/0001_create_roles_table.php) and are joined to permissions via `0003_create_role_permissions_table.php`. The role admin pages live under [`spa/src/pages/admin/roles/`](../../../spa/src/pages/admin/roles/).

Do **not** hard-code role names like `'admin'` in code paths. Hard-code on permissions, not roles. The only legitimate role check is in seeders/admin tooling.

## Verification

```bash
cd api

# A specific user has the permission?
php artisan tinker --execute="dump(\App\Models\User::find(1)?->hasPermission('crm.products.manage'));"

# Routes that reference a permission:
grep -r 'permission:crm.products' api/

# SPA usages:
grep -r "can('crm.products" spa/src/
```

Then run [`code-quality-gate.md`](code-quality-gate.md) including the Feature tests for the affected endpoints. The Feature tests should include at least one test that asserts a user without the permission gets 403.

## Common mistakes

- **Adding middleware/authorize/UI but skipping the seeder.** Most common production-impacting mistake.
- **Inconsistent action names.** Pick `view` + `manage` unless you genuinely need more granular.
- **Hard-coding role names.** Always check permissions, not roles.
- **Forgetting `feature:<module>` middleware.** kwatog gates entire modules behind a feature flag; without it the route works in dev but may be hidden in prod tenants. Match the existing module's group middleware.
- **Putting permission strings in random files.** Centralize via seeders; avoid string-typed sprinkled magic.
