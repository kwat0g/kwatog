<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Common\Models\AuditLog;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\DSS\Models\DssParameter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DssAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        app(SettingsService::class)->set('modules.dss', true, 'modules');
    }

    private function userWithPermissions(array $slugs): User
    {
        $role = Role::create([
            'name' => 'DSS Admin Test '.uniqid(),
            'slug' => 'dss-admin-test-'.uniqid(),
            'is_system' => false,
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', $slugs)->pluck('id')->all());

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function sysadmin(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    public function test_detectors_list_shows_fleet_state(): void
    {
        $manager = $this->userWithPermissions(['dss.manage']);

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/dss/admin/detectors')
            ->assertOk();

        $rows = collect($response->json('data'))->keyBy('id');
        $this->assertTrue($rows->has('dss.master_hygiene'));
        $hygiene = $rows->get('dss.master_hygiene');
        $this->assertSame('platform', $hygiene['domain']);
        $this->assertSame('v1', $hygiene['version']);
        $this->assertTrue($hygiene['enabled']);
        // Engine default is shadow until promoted: listed, never displayed.
        $this->assertSame('shadow', $hygiene['mode']);
        $this->assertSame(0, $hygiene['open_count']);
        $this->assertNull($hygiene['last_run']);
    }

    public function test_update_detector_mode_and_enabled(): void
    {
        $manager = $this->userWithPermissions(['dss.manage']);

        $this->actingAs($manager, 'sanctum')
            ->patchJson('/api/v1/dss/admin/detectors/dss.master_hygiene', ['mode' => 'advisory'])
            ->assertOk()
            ->assertJsonPath('data.mode', 'advisory');

        $this->actingAs($manager, 'sanctum')
            ->patchJson('/api/v1/dss/admin/detectors/dss.master_hygiene', ['mode' => 'broadcast'])
            ->assertStatus(422);

        $this->actingAs($manager, 'sanctum')
            ->patchJson('/api/v1/dss/admin/detectors/nope.missing', ['mode' => 'advisory'])
            ->assertNotFound();
    }

    public function test_run_now_executes_single_detector(): void
    {
        $manager = $this->userWithPermissions(['dss.manage']);

        $response = $this->actingAs($manager, 'sanctum')
            ->postJson('/api/v1/dss/admin/detectors/dss.master_hygiene/run')
            ->assertOk()
            ->assertJsonPath('data.engine', 'enabled');

        // Detector ids contain dots, which assertJsonPath reads as nesting —
        // assert on the decoded payload instead.
        $this->assertSame(
            'completed',
            $response->json('data.detectors')['dss.master_hygiene']['status']
        );

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/v1/dss/admin/detectors/nope.missing/run')
            ->assertNotFound();
    }

    public function test_engine_read_needs_manage_write_needs_admin(): void
    {
        $manager = $this->userWithPermissions(['dss.manage']);
        $viewer = $this->userWithPermissions(['dss.view']);

        $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/dss/admin/engine')->assertForbidden();

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/dss/admin/engine')
            ->assertOk()
            ->assertJsonPath('data.enabled', true);

        // Kill switch is dss.admin-only: manage holders get 403, not a silent no-op.
        $this->actingAs($manager, 'sanctum')
            ->patchJson('/api/v1/dss/admin/engine', ['enabled' => false])
            ->assertForbidden();

        $this->actingAs($this->sysadmin(), 'sanctum')
            ->patchJson('/api/v1/dss/admin/engine', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $this->artisan('dss:run')->assertSuccessful();
    }

    public function test_parameter_set_is_audited(): void
    {
        $manager = $this->userWithPermissions(['dss.manage']);
        $before = AuditLog::query()->where('model_type', DssParameter::class)->count();

        $this->actingAs($manager, 'sanctum')
            ->putJson('/api/v1/dss/admin/parameters', [
                'detector_id' => 'dss.master_hygiene',
                'key' => 'critical_count',
                'value' => 25,
            ])
            ->assertOk()
            ->assertJsonPath('data.value', 25);

        $this->assertSame($before + 1, AuditLog::query()->where('model_type', DssParameter::class)->count());

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/dss/admin/parameters?detector=dss.master_hygiene')
            ->assertOk()
            ->assertJsonFragment(['key' => 'critical_count']);
    }
}
