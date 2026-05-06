---
name: add-api-endpoint
description: Use when adding or modifying any API endpoint under api/app/Modules/<Module>/. Codifies the file order, the canonical patterns to copy, and the post-write verification checks specific to kwatog's modular monolith.
---

# Add or Modify an API Endpoint (kwatog)

## Source of truth

The shape of every layer (Controller, Service, FormRequest, Resource, Route, Migration, Model) is locked in [`docs/PATTERNS.md`](../../../docs/PATTERNS.md) sections 1-7. **Read those sections first**, then come back here for the workflow.

This skill adds the order and verification - PATTERNS.md is the what, this is the how.

## File order (do not skip steps)

For an endpoint that exposes a CRUD-ish resource:

1. **Migration** if a new table/column is needed.
   - Numerically prefixed: `0NNN_create_<table>_table.php`. Find the next number: `ls api/database/migrations/ | tail -1`.
   - FKs use `foreignId(...)->constrained(...)`.
   - See [`add-database-migration.md`](add-database-migration.md) and PATTERNS.md section 1.

2. **Model** at `api/app/Modules/<Module>/Models/<Model>.php`.
   - PATTERNS.md section 2. Set `$fillable`, casts, relationships.

3. **Service** at `api/app/Modules/<Module>/Services/<Model>Service.php`.
   - **All business logic lives here.** Controllers must stay thin.
   - Constructor-inject other services if needed.
   - PATTERNS.md section 3.

4. **FormRequests** at `api/app/Modules/<Module>/Requests/{Store,Update}<Model>Request.php`.
   - `authorize()` returns `$this->user()?->hasPermission('module.resource.action') ?? false`.
   - `rules()` declares validation. Use `decimal:0,2` for money, regex for codes.
   - PATTERNS.md section 4.

5. **API Resource** at `api/app/Modules/<Module>/Resources/<Model>Resource.php`.
   - Defines the JSON output shape. **Never expose Eloquent models directly from controllers.**
   - PATTERNS.md section 5.

6. **Controller** at `api/app/Modules/<Module>/Controllers/<Model>Controller.php`.
   - Thin: inject the Service, accept FormRequests, return Resources.
   - Standard methods: `index`, `show`, `store`, `update`, `destroy`. Each is a 1-3 liner.
   - PATTERNS.md section 6.

7. **Routes** in `api/app/Modules/<Module>/routes.php`.
   - Group under `Route::middleware(['auth:sanctum', 'feature:<module>'])->prefix('<module>')->group(...)`.
   - Each route attaches a permission middleware: `->middleware('permission:<module>.<resource>.<action>')`.
   - PATTERNS.md section 7.

8. **Permission seeding** if the new permission string did not exist before.
   - See [`rbac-and-permissions.md`](rbac-and-permissions.md). Without seeding, every prod user gets 403.

9. **Tests** at `api/tests/Feature/<Module>/<Model>Test.php`.
   - At minimum: index returns paginated, store validates + creates, store rejects without permission, update + destroy as needed.
   - See [`testing-strategy.md`](testing-strategy.md).

10. **OpenAPI / docs** if applicable. Update [`docs/SCHEMA.md`](../../../docs/SCHEMA.md) for new tables and any user-facing doc that references the endpoint.

## Verification before claiming done

```bash
cd api

# Routes are mounted (no typos in routes.php, no missing imports):
php artisan route:list | grep <module>

# Migrations apply on a fresh DB:
php artisan migrate:fresh --seed --env=testing

# PHPUnit (writes the gate command from code-quality-gate.md):
php artisan test --filter=<Model>

# Permission seeding picked up:
php artisan tinker --execute="dump(\App\Models\User::find(1)?->hasPermission('module.resource.action'));"
```

Then run the full [`code-quality-gate.md`](code-quality-gate.md) before pushing.

## Common mistakes (do NOT do these)

- **Putting business logic in the controller.** Move it to the Service.
- **Returning the Eloquent model from a controller method.** Wrap it in a Resource.
- **Skipping the FormRequest** and validating in the controller. Always create a Request class.
- **Forgetting the permission middleware** on a new route. The route will be reachable by anyone authenticated.
- **Forgetting to seed the permission.** New permission string + no seeder update = 403 storm.
- **Adding a route to [`api/routes/api.php`](../../../api/routes/api.php) instead of the module's `routes.php`.** Cross-module routes only belong in `api.php`. Module routes belong in the module.
- **Using `Route::resource(...)` shortcut.** kwatog uses explicit Route::get/post/etc. for clarity and per-route permission attachment. Match the existing style.
- **Naming permissions inconsistently.** Always `module.resource.action`, lowercase, dot-separated, `manage` or specific verbs (`view`, `approve`, `dismiss`).

## Cross-module endpoints

Belong in [`api/routes/api.php`](../../../api/routes/api.php) under a top-level prefix, with the controller in [`api/app/Common/Controllers/`](../../../api/app/Common/Controllers/). The Alerts endpoints in `api.php` are a canonical example.

## Modifying an existing endpoint

The same gate applies, with two extras:

- **Backwards compatibility.** Other SPA pages may consume the old shape. Do not silently change response keys; either deprecate first or update every consumer in the same PR.
- **Permission renames are dangerous.** Renaming a permission string requires updating the seed, the route middleware, the FormRequest, the SPA `usePermission` calls, and any saved role-permission rows. If unsure, add the new permission alongside the old one and migrate over multiple PRs.
