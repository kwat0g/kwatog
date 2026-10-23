<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Common\Models\AuditLog;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\DSS\Models\DssParameter;
use App\Modules\DSS\Models\DssRecommendation;
use App\Modules\DSS\Models\DssRunLog;
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
        $this->assertEqualsCanonicalizing([
            'dss.master_hygiene',
            'dss.chain_bottlenecks',
            'dss.approval_bottlenecks',
            'dss.quality_ncr_sla',
            'dss.receivables_priority',
            'dss.payroll_anomaly_summary',
            'dss.work_order_intervention',
            'dss.training_expiry_summary',
            'dss.preventive_maintenance_overdue',
        ], $rows->keys()->all());
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

    public function test_detectors_list_uses_latest_run_for_each_detector(): void
    {
        $manager = $this->userWithPermissions(['dss.manage']);
        DssRunLog::query()->create([
            'detector_id' => 'dss.master_hygiene',
            'status' => 'failed',
            'started_at' => now()->subMinute(),
            'finished_at' => now()->subSeconds(30),
            'duration_ms' => 100,
            'error' => 'Old failed run.',
        ]);
        DssRunLog::query()->create([
            'detector_id' => 'dss.master_hygiene',
            'status' => 'completed',
            'started_at' => now(),
            'finished_at' => now(),
            'duration_ms' => 100,
        ]);

        $response = $this->actingAs($manager, 'sanctum')->getJson('/api/v1/dss/admin/detectors')->assertOk();
        $hygiene = collect($response->json('data'))->firstWhere('id', 'dss.master_hygiene');

        $this->assertSame('completed', $hygiene['last_run']['status']);
    }

    public function test_update_detector_mode_and_enabled(): void
    {
        $manager = $this->userWithPermissions(['dss.manage']);
        $oldRecommendation = DssRecommendation::query()->create([
            'detector_id' => 'dss.master_hygiene',
            'domain' => 'platform',
            'type' => 'master_hygiene',
            'severity' => 'warning',
            'rationale' => ['rule' => 'old shadow result'],
            'dedupe_key' => 'old-shadow-result',
        ]);

        $this->actingAs($manager, 'sanctum')
            ->patchJson('/api/v1/dss/admin/detectors/dss.master_hygiene', ['mode' => 'advisory'])
            ->assertOk()
            ->assertJsonPath('data.mode', 'advisory');
        $this->assertSame('expired', $oldRecommendation->fresh()->status);

        $this->actingAs($manager, 'sanctum')
            ->patchJson('/api/v1/dss/admin/detectors/dss.master_hygiene', ['mode' => 'broadcast'])
            ->assertStatus(422);
        $this->actingAs($manager, 'sanctum')
            ->patchJson('/api/v1/dss/admin/detectors/dss.master_hygiene', ['mode' => 'assisted'])
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

    public function test_engine_mode_change_expires_old_shadow_results(): void
    {
        $admin = $this->sysadmin();
        $recommendation = DssRecommendation::query()->create([
            'detector_id' => 'dss.master_hygiene',
            'domain' => 'platform',
            'type' => 'master_hygiene',
            'severity' => 'warning',
            'rationale' => ['rule' => 'shadow result'],
            'dedupe_key' => 'old-engine-mode-result',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/dss/admin/engine', ['default_mode' => 'advisory'])
            ->assertOk();

        $this->assertSame('expired', $recommendation->fresh()->status);
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

    public function test_parameter_endpoint_cannot_bypass_engine_admin_guard_or_set_unknown_values(): void
    {
        $manager = $this->userWithPermissions(['dss.manage']);

        $this->actingAs($manager, 'sanctum')
            ->putJson('/api/v1/dss/admin/parameters', [
                'detector_id' => 'global',
                'key' => 'dss.engine.enabled',
                'value' => false,
            ])
            ->assertStatus(422);

        $this->actingAs($manager, 'sanctum')
            ->putJson('/api/v1/dss/admin/parameters', [
                'detector_id' => 'dss.master_hygiene',
                'key' => 'critical_count',
                'value' => 'not-a-number',
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('dss_parameters', [
            'detector_id' => 'global',
            'key' => 'dss.engine.enabled',
        ]);
    }
}
