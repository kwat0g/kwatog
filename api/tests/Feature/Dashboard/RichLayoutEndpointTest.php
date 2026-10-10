<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\DashboardRoleLayoutSeeder;
use Database\Seeders\DashboardWidgetSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RichLayoutEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DashboardWidgetSeeder::class);
        $this->seed(DashboardRoleLayoutSeeder::class);
    }

    private function actingAsRole(string $slug): User
    {
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
        ]);
        $this->actingAs($user);

        return $user;
    }

    public function test_maintenance_supervisor_has_machine_visibility_and_operational_dashboard_defaults(): void
    {
        $user = $this->actingAsRole('maintenance_supervisor');
        $this->assertTrue($user->hasPermission('mrp.machines.view'));
        $this->assertFalse($user->hasPermission('mrp.machines.manage'));
        $this->assertTrue($user->hasPermission('maintenance.wo.assign'));
        $this->assertTrue($user->hasPermission('maintenance.wo.verify'));
        $keys = collect($this->getJson('/api/v1/dashboard/layout')->assertOk()->json('data'))->pluck('key')->all();
        foreach (['machine.status', 'maintenance.open_wos', 'maintenance.due_schedules', 'assets.under_maintenance'] as $key) {
            $this->assertContains($key, $keys);
        }
    }

    public function test_supervisor_visibility_migration_is_repeatable_and_preserves_personal_layouts(): void
    {
        $user = $this->actingAsRole('maintenance_supervisor');
        $permissionId = DB::table('permissions')->where('slug', 'mrp.machines.view')->value('id');
        DB::table('role_permissions')->where('role_id', $user->role_id)->where('permission_id', $permissionId)->delete();
        DB::table('dashboard_layouts')->where('owner_type', 'role')->where('owner_id', $user->role_id)->delete();
        DB::table('dashboard_layouts')->insert([
            'owner_type' => 'user', 'owner_id' => $user->id, 'widget_key' => 'self.leave_balance',
            'position_x' => 0, 'position_y' => 0, 'width' => 4, 'height' => 4,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Cache::forget("auth:role_perms:{$user->role_id}");
        $migration = require database_path('migrations/2026_10_10_000500_restore_maintenance_supervisor_visibility.php');
        $migration->up();
        $migration->up();
        $this->assertTrue($user->fresh()->hasPermission('mrp.machines.view'));
        $this->assertSame(9, DB::table('dashboard_layouts')->where('owner_type', 'role')->where('owner_id', $user->role_id)->count());
        $this->assertSame(['self.leave_balance'], DB::table('dashboard_layouts')->where('owner_type', 'user')->where('owner_id', $user->id)->pluck('widget_key')->all());
    }

    public function test_plain_layout_carries_render_kind(): void
    {
        $this->actingAsRole('production_manager');

        $this->getJson('/api/v1/dashboard/layout')
            ->assertOk()
            ->assertJsonStructure(['data' => [['key', 'name', 'module', 'render_kind', 'x', 'y', 'w', 'h', 'source']]]);
    }

    public function test_rich_layout_nests_data_per_widget(): void
    {
        $this->actingAsRole('production_manager');

        $response = $this->getJson('/api/v1/dashboard/layout?rich=1')->assertOk();

        $rows = collect($response->json('data'));
        $this->assertNotEmpty($rows);
        $this->assertTrue($rows->every(fn (array $r) => array_key_exists('data', $r)));

        // production_manager's layout includes production.kpi (trend) — its
        // rich payload must actually arrive, not fall back to null.
        $trend = $rows->firstWhere('key', 'production.kpi');
        $this->assertNotNull($trend);
        $this->assertArrayHasKey('points', $trend['data']);
    }

    /**
     * Rich mode must not widen access. It reuses the same permission strip, so
     * a role that cannot see a widget does not receive its data either.
     */
    public function test_rich_mode_still_strips_forbidden_widgets(): void
    {
        $this->actingAsRole('employee');

        $keys = collect($this->getJson('/api/v1/dashboard/layout?rich=1')->assertOk()->json('data'))
            ->pluck('key');

        $this->assertTrue($keys->every(fn (string $k) => str_starts_with($k, 'self.')));
        $this->assertFalse($keys->contains('finance.ar_aging'));
    }

    /** Scalar widgets carry no rich payload — the SPA renders them as before. */
    public function test_scalar_widgets_have_null_data(): void
    {
        $this->actingAsRole('employee');

        $rows = collect($this->getJson('/api/v1/dashboard/layout?rich=1')->assertOk()->json('data'));

        $this->assertTrue($rows->every(fn (array $r) => $r['data'] === null));
    }
}
