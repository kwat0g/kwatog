<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\DashboardRoleLayoutSeeder;
use Database\Seeders\DashboardWidgetSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
