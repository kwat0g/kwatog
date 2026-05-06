---
name: testing-strategy
description: Use when writing or modifying any test in kwatog. Codifies the PHPUnit (api) and Vitest (spa) split, what each layer is for, fixtures vs factories, and the minimum-viable-test-set for a new feature.
---

# Testing Strategy (kwatog)

## Layer responsibilities

| Layer | Tool | Location | Tests |
|---|---|---|---|
| API unit | PHPUnit | [`api/tests/Unit/`](../../../api/tests/Unit/) | Pure functions, helpers, value objects, Enums. No DB, no HTTP. |
| API feature | PHPUnit | [`api/tests/Feature/`](../../../api/tests/Feature/) | Hitting endpoints with `$this->postJson(...)`, hitting Services with real DB. Uses SQLite in-memory. |
| SPA unit | Vitest | `spa/src/**/*.test.{ts,tsx}` | Component rendering with @testing-library/react, hook behavior, lib/* helpers. |
| SPA integration | Vitest | same | Page-level rendering with mocked react-query and mocked API. Avoid network. |
| End-to-end | (not in repo) | n/a | Out of scope for this skill. |

## Minimum viable test set for a new API endpoint

For `POST /api/v1/<module>/<resource>` and friends, [`api/tests/Feature/<Module>/<Resource>Test.php`](../../../api/tests/Feature/) must cover:

1. **Authenticated user with permission can create** -> 201 + correct shape
2. **Authenticated user without permission gets 403**
3. **Unauthenticated request gets 401**
4. **Invalid payload returns 422 with the right error keys**
5. **Update/delete behave correctly** when the controller exposes them

Skeleton:

```php
<?php

namespace Tests\Feature\CRM;

use App\Models\User;
use App\Modules\CRM\Models\Product;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_permission_can_create_product(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('crm.products.manage');  // helper - or seed manually

        $resp = $this->actingAs($user)->postJson('/api/v1/crm/products', [
            'part_number'     => 'P-001',
            'name'            => 'Widget',
            'unit_of_measure' => 'pc',
            'standard_cost'   => 12.50,
        ]);

        $resp->assertCreated()->assertJsonPath('data.part_number', 'P-001');
        $this->assertDatabaseHas('products', ['part_number' => 'P-001']);
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $user = User::factory()->create();
        $resp = $this->actingAs($user)->postJson('/api/v1/crm/products', []);
        $resp->assertForbidden();
    }
}
```

## Minimum viable test set for a new SPA page

For a list+create+edit page:

1. List renders skeleton during loading
2. List renders empty state when API returns `[]`
3. List renders rows when API returns data
4. Create form: submitting with invalid data shows validation messages
5. Create form: submitting with valid data calls the API and navigates

Use `@testing-library/react` and mock the API module via `vi.mock('@/api/<module>/<resource>', ...)` or via msw if you prefer network-level mocks.

## Factories

[`api/database/factories/`](../../../api/database/factories/) is currently empty. When you add the first factory for your model:

```php
<?php

namespace Database\Factories;

use App\Modules\CRM\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'part_number'     => strtoupper($this->faker->bothify('P-####')),
            'name'            => $this->faker->words(2, true),
            'unit_of_measure' => 'pc',
            'standard_cost'   => $this->faker->randomFloat(2, 1, 1000),
            'is_active'       => true,
        ];
    }
}
```

The `User` model already has a factory; pattern off it.

## Seeders for tests

When a Feature test needs permissions to exist, run the seeder in the test setUp or via `RefreshDatabase` + the seeders Laravel calls. If a permission test is failing because "no permission found," check that the seeder is being run and that the permission name is identical (no typos, no trailing whitespace).

## Running tests

```bash
# API
cd api
php artisan test                           # all
php artisan test --filter=ProductTest      # by class
php artisan test --filter=test_user_with   # by method substring
php artisan test --parallel                # if the suite supports it

# SPA
cd spa
npm run test -- --run                      # one-shot, CI-style
npm run test -- --watch                    # only when iterating
npm run test -- --run path/to/file.test.tsx
```

CI runs PHPUnit on api/** changes and Vitest+typecheck+lint+build on spa/** changes (see [`code-quality-gate.md`](code-quality-gate.md)).

## What NOT to test

- Pure framework behavior (does Eloquent insert? does react-router navigate?). Trust your dependencies.
- Implementation details (private methods, internal state). Test behavior.
- "Happy path only." Always cover one error/permission-denied/empty case per public surface.

## Coverage

There is no enforced coverage threshold today. The bar is "every public endpoint and every page has at least the minimum-viable set above." Expanding to property tests, mutation tests, and snapshot tests is welcome but not required.

Then run [`code-quality-gate.md`](code-quality-gate.md).
