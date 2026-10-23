<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\DSS\Contracts\DetectorInterface;
use App\Modules\DSS\Contracts\DetectorScanResult;
use App\Modules\DSS\Models\DssRecommendation;
use App\Modules\DSS\Models\DssRecommendationEvent;
use App\Modules\DSS\Models\DssRunLog;
use App\Modules\DSS\Services\DetectorRegistry;
use App\Modules\DSS\Services\DssParameterService;
use App\Modules\DSS\Services\RecommendationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LifecycleFakeDetector implements DetectorInterface
{
    public function id(): string
    {
        return 'test.advisory';
    }

    public function domain(): string
    {
        return 'test';
    }

    public function ownerPermissions(): array
    {
        return ['dss.view'];
    }

    public function version(): string
    {
        return 'v1';
    }

    public function defaultParams(): array
    {
        return [];
    }

    public function detect(array $context = []): DetectorScanResult
    {
        return new DetectorScanResult([]);
    }
}

class LifecycleRestrictedDetector extends LifecycleFakeDetector
{
    public function id(): string
    {
        return 'test.restricted';
    }

    public function ownerPermissions(): array
    {
        return ['forecasting.view'];
    }
}

class LifecycleShadowDetector extends LifecycleFakeDetector
{
    public function id(): string
    {
        return 'test.shadowed';
    }
}

class RecommendationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        app(SettingsService::class)->set('modules.dss', true, 'modules');

        $registry = app(DetectorRegistry::class);
        $registry->register('test.advisory', LifecycleFakeDetector::class);
        $registry->register('test.restricted', LifecycleRestrictedDetector::class);
        $registry->register('test.shadowed', LifecycleShadowDetector::class);

        $params = app(DssParameterService::class);
        $params->set('test.advisory', 'mode', 'advisory');
        $params->set('test.restricted', 'mode', 'advisory');
        // test.shadowed stays on the engine default (shadow): never displayed.
    }

    private function userWithPermissions(array $slugs): User
    {
        $role = Role::create([
            'name' => 'DSS Test '.uniqid(),
            'slug' => 'dss-test-'.uniqid(),
            'is_system' => false,
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', $slugs)->pluck('id')->all());

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function makeRecommendation(string $detector = 'test.advisory', array $overrides = []): DssRecommendation
    {
        $run = DssRunLog::query()->create([
            'detector_id' => $detector,
            'status' => 'completed',
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        return DssRecommendation::create([
            'detector_id' => $detector,
            'domain' => 'test',
            'type' => 'test_type',
            'severity' => 'warning',
            'priority_score' => 0.5,
            'rationale' => ['rule' => 'x'],
            'dedupe_key' => 'k-'.uniqid(),
            'last_evaluated_at' => $run->finished_at,
            'last_evaluated_run_id' => $run->id,
            ...$overrides,
        ]);
    }

    public function test_full_lifecycle_accept_execute_outcome(): void
    {
        $user = $this->userWithPermissions(['dss.view']);
        $rec = $this->makeRecommendation();
        $evaluationRunId = $rec->last_evaluated_run_id;

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/dss/recommendations/{$rec->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $rec->hash_id);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/dss/recommendations/{$rec->id}/actions", ['action' => 'start_follow_up'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/dss/recommendations/{$rec->id}/actions", ['action' => 'start_follow_up'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');
        $this->assertSame(1, DssRecommendationEvent::query()
            ->where('recommendation_id', $rec->id)->where('event', 'started')->count());

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/dss/recommendations/{$rec->id}/actions", ['action' => 'accept'])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/dss/recommendations/{$rec->id}/actions", ['action' => 'execute'])
            ->assertOk()
            ->assertJsonPath('data.status', 'executed');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/dss/recommendations/{$rec->id}/outcome", ['outcome' => ['note' => 'Stock-out avoided']])
            ->assertOk();
        $this->assertSame($evaluationRunId, $rec->fresh()->last_evaluated_run_id);

        $events = DssRecommendationEvent::query()
            ->where('recommendation_id', $rec->id)
            ->pluck('event')
            ->all();

        foreach (['viewed', 'started', 'accepted', 'executed', 'outcome'] as $expected) {
            $this->assertContains($expected, $events);
        }
    }

    public function test_dismiss_requires_reason(): void
    {
        $user = $this->userWithPermissions(['dss.view']);
        $rec = $this->makeRecommendation();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/dss/recommendations/{$rec->id}/actions", ['action' => 'dismiss'])
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/dss/recommendations/{$rec->id}/actions", ['action' => 'dismiss', 'reason' => 'Not applicable'])
            ->assertOk()
            ->assertJsonPath('data.status', 'dismissed');
    }

    public function test_snooze_hides_until_return_date(): void
    {
        $user = $this->userWithPermissions(['dss.view']);
        $rec = $this->makeRecommendation();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/dss/recommendations/{$rec->id}/actions", [
                'action' => 'snooze',
                'snoozed_until' => now()->addDays(7)->toISOString(),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'snoozed');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dss/recommendations')
            ->assertOk()
            ->assertJsonMissing(['id' => $rec->hash_id]);

        // Time passes: the snooze lapses and the row resurfaces with no cron.
        $rec->forceFill(['snoozed_until' => now()->subHour()])->save();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dss/recommendations')
            ->assertOk()
            ->assertJsonFragment(['id' => $rec->hash_id]);
    }

    public function test_invalid_transitions_are_rejected(): void
    {
        $user = $this->userWithPermissions(['dss.view']);
        $rec = $this->makeRecommendation();

        // Execute straight from open.
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/dss/recommendations/{$rec->id}/actions", ['action' => 'execute'])
            ->assertStatus(422);

        // Accept twice.
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/dss/recommendations/{$rec->id}/actions", ['action' => 'accept'])
            ->assertOk();
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/dss/recommendations/{$rec->id}/actions", ['action' => 'accept'])
            ->assertStatus(422);

        // Outcome on an undecided row.
        $open = $this->makeRecommendation();
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/dss/recommendations/{$open->id}/outcome", ['outcome' => ['note' => 'Not decided']])
            ->assertStatus(422);
    }

    public function test_expired_rows_vanish_lazily(): void
    {
        $user = $this->userWithPermissions(['dss.view']);
        $live = $this->makeRecommendation();
        $stale = $this->makeRecommendation();
        $stale->forceFill(['expires_at' => now()->subHour()])->save();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dss/recommendations')
            ->assertOk()
            ->assertJsonFragment(['id' => $live->hash_id])
            ->assertJsonMissing(['id' => $stale->hash_id]);
    }

    public function test_expired_recommendation_cannot_be_accepted_and_gets_audit_event(): void
    {
        $user = $this->userWithPermissions(['dss.view']);
        $rec = $this->makeRecommendation(overrides: ['expires_at' => now()->subMinute()]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/dss/recommendations/{$rec->id}/actions", ['action' => 'accept'])
            ->assertStatus(422);

        $this->assertDatabaseHas('dss_recommendations', ['id' => $rec->id, 'status' => 'expired']);
        $this->assertDatabaseHas('dss_recommendation_events', [
            'recommendation_id' => $rec->id,
            'event' => 'expired',
            'to_status' => 'expired',
        ]);
    }

    public function test_scheduled_expiry_expires_rows_and_records_events(): void
    {
        $rec = $this->makeRecommendation(overrides: ['expires_at' => now()->subMinute()]);

        $this->assertSame(1, app(RecommendationService::class)->expirePastValidity());
        $this->assertSame('expired', $rec->fresh()->status);
        $this->assertDatabaseHas('dss_recommendation_events', [
            'recommendation_id' => $rec->id,
            'event' => 'expired',
            'reason' => 'Recommendation validity window ended.',
        ]);
    }

    public function test_configuration_pause_expires_active_rows_with_audit_event(): void
    {
        $rec = $this->makeRecommendation();

        $this->assertSame(1, app(RecommendationService::class)->expireForPause('test.advisory'));
        $this->assertSame('expired', $rec->fresh()->status);
        $this->assertDatabaseHas('dss_recommendation_events', [
            'recommendation_id' => $rec->id,
            'event' => 'expired',
            'reason' => 'DSS configuration changed; previous recommendation invalidated.',
        ]);
    }

    public function test_viewed_records_once_per_user_per_day(): void
    {
        $user = $this->userWithPermissions(['dss.view']);
        $rec = $this->makeRecommendation();

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/dss/recommendations/{$rec->id}")->assertOk();
        $this->actingAs($user, 'sanctum')->getJson("/api/v1/dss/recommendations/{$rec->id}")->assertOk();

        $this->assertSame(1, DssRecommendationEvent::query()
            ->where('recommendation_id', $rec->id)
            ->where('event', 'viewed')
            ->count());
    }
}
