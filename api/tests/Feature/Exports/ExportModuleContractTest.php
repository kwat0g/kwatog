<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Common\Exports\BaseModuleExport;
use App\Common\Services\Export\ExportColumnRegistry;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M011-F03 drift guard.
 *
 * An export module used to be advertised in three places that could disagree:
 * a controller permission map, a runner class map, and the SPA. A module that
 * appeared in one but not the others produced either "unknown module" or
 * "no export class registered" at the moment a user pressed Export.
 *
 * There is now one authoritative contract (ExportColumnRegistry::registerModule),
 * so this test asserts that everything the registry advertises is actually
 * runnable end to end. It iterates the registry rather than a hardcoded list so
 * a newly registered module is covered the moment it is added.
 */
class ExportModuleContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_the_registry_advertises_at_least_one_module(): void
    {
        // Guards against a silent regression where boot registration is lost
        // and every assertion below vacuously passes.
        $this->assertNotEmpty(ExportColumnRegistry::modules());
    }

    public function test_every_advertised_module_has_a_runnable_contract(): void
    {
        foreach (ExportColumnRegistry::modules() as $module) {
            $implementation = ExportColumnRegistry::implementationFor($module);
            $this->assertIsString($implementation, "Module [{$module}] has no implementation class.");
            $this->assertTrue(
                is_a($implementation, BaseModuleExport::class, true),
                "Module [{$module}] implementation [{$implementation}] is not a BaseModuleExport.",
            );

            $permission = ExportColumnRegistry::permissionFor($module);
            $this->assertIsString($permission, "Module [{$module}] has no permission.");
            $this->assertTrue(
                Permission::query()->where('slug', $permission)->exists(),
                "Module [{$module}] requires unseeded permission [{$permission}].",
            );

            $columns = ExportColumnRegistry::for($module);
            $this->assertNotEmpty($columns, "Module [{$module}] registered no columns.");
            $this->assertNotEmpty(
                ExportColumnRegistry::defaultsFor($module),
                "Module [{$module}] has no default columns, so an export with no explicit selection is impossible.",
            );

            foreach ($columns as $key => $definition) {
                $this->assertIsString($definition['label'] ?? null, "Column [{$module}.{$key}] has no label.");
                // A column without a resolver falls through to BaseModuleExport's
                // LogicException at map() time — i.e. at download time, for the user.
                $this->assertTrue(
                    isset($definition['resolver']) && is_callable($definition['resolver']),
                    "Column [{$module}.{$key}] has no resolver.",
                );
                if (isset($definition['permission'])) {
                    $this->assertTrue(
                        Permission::query()->where('slug', $definition['permission'])->exists(),
                        "Column [{$module}.{$key}] requires unseeded permission [{$definition['permission']}].",
                    );
                }
            }
        }
    }

    public function test_a_column_only_registration_is_not_downloadable(): void
    {
        // Registering columns is enough for the selector UI and unit tests; it
        // must never be enough to make a production export run.
        ExportColumnRegistry::register('test.columns_only', [
            'a' => ['label' => 'A', 'default' => true, 'resolver' => static fn (): string => 'x'],
        ]);
        $this->assertFalse(ExportColumnRegistry::hasImplementation('test.columns_only'));

        $admin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/exports/test.columns_only/download')
            ->assertNotFound();
    }
}
