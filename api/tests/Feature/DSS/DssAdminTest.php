<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Common\Models\AuditLog;
use App\Common\Services\SettingsService;
use App\Jobs\RunDssDetectorJob;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\DSS\Models\DssParameter;
use App\Modules\DSS\Models\DssRecommendation;
use App\Modules\DSS\Models\DssRunLog;
use App\Modules\DSS\Services\DssParameterService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
            'dss.quality_suspect_material_lot',
            'dss.receivables_priority',
            'dss.payroll_anomaly_summary',
            'dss.work_order_intervention',
            'dss.training_expiry_summary',
            'dss.preventive_maintenance_overdue',
            'dss.three_way_match_review',
        ], $rows->keys()->all());
        $this->assertTrue($rows->has('dss.master_hygiene'));
        $hygiene = $rows->get('dss.master_hygiene');
        $this->assertSame('platform', $hygiene['domain']);
        $this->assertSame('v1', $hygiene['version']);
        $this->assertTrue($hygiene['enabled']);
        // Engine default is shadow until promoted: listed, never displayed.
        $this->assertSame('shadow', $hygiene['mode']);
        $this->assertSame(0, $hygiene['open_count']);
        $this->assertSame(0, $hygiene['active_count']);
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
        $this->assertArrayHasKey('started_at', $hygiene['last_run']);
        $this->assertArrayHasKey('error_summary', $hygiene['last_run']);
        $this->assertSame(DssRunLog::query()->where('detector_id', 'dss.master_hygiene')->latest('id')->first()->hash_id, $hygiene['last_run']['run_reference']);
        $this->assertFalse($hygiene['last_run']['stale']);
    }

    public function test_detector_count_includes_active_follow_up_and_due_snoozes_only(): void
    {
        $manager = $this->userWithPermissions(['dss.manage']);
        foreach ([
            ['open', null, null],
            ['in_progress', null, null],
            ['snoozed', now()->subSecond(), null],
            ['snoozed', now()->addDay(), null],
            ['resolved', null, null],
            ['open', null, now()->subSecond()],
        ] as [$status, $snoozedUntil, $expiresAt]) {
            $recommendation = new DssRecommendation;
            $recommendation->forceFill([
                'detector_id' => 'dss.master_hygiene',
                'domain' => 'platform',
                'type' => 'master_hygiene',
                'severity' => 'warning',
                'status' => $status,
                'snoozed_until' => $snoozedUntil,
                'expires_at' => $expiresAt,
                'rationale' => ['rule' => uniqid()],
                'dedupe_key' => uniqid(),
            ])->save();
        }

        $hygiene = collect($this->actingAs($manager, 'sanctum')->getJson('/api/v1/dss/admin/detectors')->assertOk()->json('data'))
            ->firstWhere('id', 'dss.master_hygiene');

        $this->assertSame(3, $hygiene['active_count']);
        $this->assertSame($hygiene['active_count'], $hygiene['open_count']);
    }

    public function test_run_now_returns_accepted_queued_ack(): void
    {
        Queue::fake();
        $manager = $this->userWithPermissions(['dss.manage']);
        $response = $this->actingAs($manager, 'sanctum')
            ->postJson('/api/v1/dss/admin/detectors/dss.master_hygiene/run')
            ->assertAccepted();

        $this->assertSame([], $response->json('data.failed'));
        $queuedRun = $response->json('data.detectors')['dss.master_hygiene'];
        $this->assertSame('queued', $queuedRun['status']);
        $this->assertArrayNotHasKey('error', $queuedRun);
        Queue::assertPushed(RunDssDetectorJob::class);
    }

    public function test_running_detector_run_becomes_stale_after_lock_margin(): void
    {
        $manager = $this->userWithPermissions(['dss.manage']);
        DssRunLog::query()->create([
            'detector_id' => 'dss.master_hygiene',
            'status' => 'running',
            'started_at' => now()->subSeconds(311),
        ]);

        $hygiene = collect($this->actingAs($manager, 'sanctum')->getJson('/api/v1/dss/admin/detectors')->assertOk()->json('data'))
            ->firstWhere('id', 'dss.master_hygiene');

        $this->assertSame('running', $hygiene['last_run']['status']);
        $this->assertTrue($hygiene['last_run']['stale']);
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

    public function test_run_now_queues_single_detector(): void
    {
        Queue::fake();
        $manager = $this->userWithPermissions(['dss.manage']);

        $response = $this->actingAs($manager, 'sanctum')
            ->postJson('/api/v1/dss/admin/detectors/dss.master_hygiene/run')
            ->assertAccepted()
            ->assertJsonPath('data.engine', 'enabled');

        // Detector ids contain dots, which assertJsonPath reads as nesting —
        // assert on the decoded payload instead.
        $this->assertSame(
            'queued',
            $response->json('data.detectors')['dss.master_hygiene']['status']
        );
        Queue::assertPushed(RunDssDetectorJob::class, fn (RunDssDetectorJob $job): bool => $job->detectorId === 'dss.master_hygiene');

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
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.default_mode', 'shadow')
            ->assertJsonPath('data.legacy_global_advisory_ignored', false);

        // Kill switch is dss.admin-only: manage holders get 403, not a silent no-op.
        $this->actingAs($manager, 'sanctum')
            ->patchJson('/api/v1/dss/admin/engine', ['enabled' => false])
            ->assertForbidden();

        $this->actingAs($this->sysadmin(), 'sanctum')
            ->patchJson('/api/v1/dss/admin/engine', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        Queue::fake();
        $this->artisan('dss:run')->assertSuccessful();
    }

    public function test_global_advisory_is_rejected_and_legacy_value_can_be_corrected(): void
    {
        $admin = $this->sysadmin();
        DssParameter::query()->create([
            'detector_id' => 'global',
            'key' => 'dss.engine.default_mode',
            'value' => 'advisory',
            'updated_by' => $admin->id,
        ]);
        DssParameter::query()->create([
            'detector_id' => 'dss.master_hygiene',
            'key' => 'mode',
            'value' => 'advisory',
            'updated_by' => $admin->id,
        ]);
        $recommendation = DssRecommendation::query()->create([
            'detector_id' => 'dss.master_hygiene',
            'domain' => 'platform',
            'type' => 'master_hygiene',
            'severity' => 'warning',
            'rationale' => ['rule' => 'shadow result'],
            'dedupe_key' => 'old-engine-mode-result',
        ]);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/dss/admin/engine')
            ->assertOk()
            ->assertJsonPath('data.default_mode', 'shadow')
            ->assertJsonPath('data.legacy_global_advisory_ignored', true);

        $detectors = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/dss/admin/detectors')
            ->assertOk()
            ->json('data');
        $unpromotedDetector = collect($detectors)->firstWhere('id', 'dss.quality_ncr_sla');
        $this->assertNotNull($unpromotedDetector);
        $this->assertSame('shadow', $unpromotedDetector['mode']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/dss/admin/engine', ['default_mode' => 'advisory'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('default_mode')
            ->assertJsonPath('errors.default_mode.0', 'The global default mode must remain shadow; enable advisory mode per detector.');

        $auditCount = AuditLog::query()->where('model_type', DssParameter::class)->count();
        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/dss/admin/engine', ['default_mode' => 'shadow'])
            ->assertOk()
            ->assertJsonPath('data.default_mode', 'shadow')
            ->assertJsonPath('data.legacy_global_advisory_ignored', false);

        $this->assertSame($auditCount + 1, AuditLog::query()->where('model_type', DssParameter::class)->count());
        $this->assertSame('open', $recommendation->fresh()->status);
        $this->assertSame('advisory', app(DssParameterService::class)->detectorMode('dss.master_hygiene'));
        $this->assertSame('shadow', app(DssParameterService::class)->detectorMode('dss.quality_ncr_sla'));
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
