---
name: add-database-migration
description: Use when adding any Laravel migration under api/database/migrations/. Codifies kwatog's numerical-prefix convention, FK constraints, reversibility, and the seed/test follow-up steps that prevent the migration from breaking CI or prod.
---

# Add a Database Migration (kwatog)

## Source of truth

Migration shape is locked in [`docs/PATTERNS.md`](../../../docs/PATTERNS.md) section 1, and the schema map is in [`docs/SCHEMA.md`](../../../docs/SCHEMA.md). Read both before writing.

## Naming - non-standard but consistent

kwatog uses **numerically-prefixed** filenames, not Laravel's default timestamps:

```
api/database/migrations/0NNN_<verb>_<table>_table.php
```

Examples that exist:

- `0001_create_roles_table.php`
- `0016_create_employees_table.php`

Find the next number:

```bash
ls api/database/migrations | tail -1
# Take the number, +1, zero-pad to 4 digits.
```

Do **not** generate via `php artisan make:migration` (it produces a timestamp). Create the file manually with the correct prefix, or rename the artisan output before committing.

## Required structure

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_no', 20)->unique();
            $table->string('first_name', 100);
            // FKs ALWAYS use foreignId(...)->constrained(...)
            $table->foreignId('department_id')->constrained('departments');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
```

PATTERNS.md section 1 has the full template including indexes, unique composites, decimal columns, enums.

## Required habits

1. **Always implement `down()`.** No empty downs. Tests run `migrate:fresh` repeatedly; broken downs poison local DBs.
2. **FKs must use `foreignId(...)->constrained(<table>)`.** Never raw `unsignedBigInteger` + manual `foreign(...)`.
3. **Money columns:** `decimal('amount', 18, 2)`. Never `float`.
4. **Decimal precision in rules:** match Schema with `decimal:0,2` in FormRequest.
5. **Booleans default explicitly:** `->default(false)`, never null booleans unless modeling tri-state.
6. **String length always specified:** `string('name', 200)`. Magic 255 default is a code smell.
7. **Add indexes for any column you'll filter or sort by.** Especially FKs that are queried without joining.
8. **Soft deletes:** `$table->softDeletes()` only if the model uses `SoftDeletes` and queries handle the trash.

## Modifying an existing table

For backward-compatible changes (add column, add index): add a new migration with the next prefix and a descriptive name like `0123_add_priority_to_alerts_table.php`. Use `Schema::table(...)`.

For destructive changes (drop column, change type): be cautious. Some columns are referenced by views, triggers, or seeders. Search the codebase before dropping:

```bash
grep -r '<column_name>' api/ spa/ --include='*.php' --include='*.ts' --include='*.tsx'
```

## Follow-up steps (DO NOT skip)

1. **Update [`docs/SCHEMA.md`](../../../docs/SCHEMA.md)** if the migration changes the public schema (add table/column/FK, not internal indexes).
2. **Update or add a Seeder** under [`api/database/seeders/`](../../../api/database/seeders/) if the new table needs seed data, or if the migration depends on existing rows that need to exist for tests. See [`docs/SEEDS.md`](../../../docs/SEEDS.md).
3. **Update the Model** at `api/app/Modules/<Module>/Models/<Model>.php` (`$fillable`, casts, relationships).
4. **Adjust factories** under [`api/database/factories/`](../../../api/database/factories/) - currently empty; this is the time to add one if your tests need it.

## Verification

```bash
cd api

# Apply on a clean DB (uses sqlite in testing):
php artisan migrate:fresh --seed --env=testing

# Apply, rollback, re-apply (catches broken downs):
php artisan migrate
php artisan migrate:rollback --step=1
php artisan migrate

# PHPUnit (Feature tests use migrate:fresh):
php artisan test
```

Then run [`code-quality-gate.md`](code-quality-gate.md).

## Common mistakes

- **Forgetting the prefix or using a timestamp.** CI does not care, but the convention is enforced by review.
- **Missing `down()`.** Local devs cannot rollback; tests get stuck.
- **`->nullable()` everywhere "to be safe".** Causes silent data quality bugs. Only nullable when the domain actually allows it.
- **Adding a permission-related column without seeding the permission.** See [`rbac-and-permissions.md`](rbac-and-permissions.md).
- **Not updating the model's `$fillable`.** Mass-assignment writes silently drop the field.

## Production deploys

[`docs/DEPLOY.md`](../../../docs/DEPLOY.md) and [`.github/workflows/deploy.yml`](../../../.github/workflows/deploy.yml) cover the deploy. For migrations:

- Migrations run automatically as part of deploy, BUT large or destructive ones should land in their own PR ahead of the dependent code, with a maintenance note.
- Avoid migrations that take a non-trivial table lock during business hours.
- For zero-downtime: add column nullable -> deploy code that writes both -> backfill -> deploy code that reads new -> drop old (multi-PR sequence). Do not collapse.
