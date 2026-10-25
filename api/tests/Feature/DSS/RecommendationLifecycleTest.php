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
use Illuminate\Support\Facades\DB;
use App\Modules\DSS\Services\DetectorRegistry;
use App\Modules\DSS\Services\DssParameterService;
use App\Modules\DSS\Services\RecommendationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
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

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

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
            ->assertJsonFragment(['id' => $rec->hash_id]);
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dss/recommendations?status=open')
            ->assertOk()
            ->assertJsonMissing(['id' => $rec->hash_id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/dss/recommendations/{$rec->id}/actions", ['action' => 'accept'])
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/dss/recommendations/{$rec->id}/actions", ['action' => 'start_follow_up'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.snoozed_until', null);
        $this->assertDatabaseHas('dss_recommendation_events', [
            'recommendation_id' => $rec->id,
            'event' => 'started',
            'from_status' => 'snoozed',
            'to_status' => 'in_progress',
        ]);
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/dss/recommendations/{$rec->id}/actions", ['action' => 'start_follow_up'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');
        $this->assertSame(1, DssRecommendationEvent::query()
            ->where('recommendation_id', $rec->id)->where('event', 'started')->count());

        // Time passes: the snooze lapses and the row resurfaces with no cron.
        $rec->forceFill(['snoozed_until' => now()->subHour()])->save();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dss/recommendations')
            ->assertOk()
            ->assertJsonFragment(['id' => $rec->hash_id]);
    }

    public function test_due_snooze_can_be_accepted(): void
    {
        $user = $this->userWithPermissions(['dss.view']);
        $rec = $this->makeRecommendation();
        $rec->forceFill(['status' => 'snoozed', 'snoozed_until' => now()->subMinute()])->save();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/dss/recommendations/{$rec->id}/actions", ['action' => 'accept'])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');
    }

    public function test_legacy_overlong_snooze_expires_and_stays_in_history(): void
    {
        $user = $this->userWithPermissions(['dss.view']);
        $rec = $this->makeRecommendation(overrides: ['expires_at' => now()->subMinute()]);
        $rec->forceFill(['status' => 'snoozed', 'snoozed_until' => now()->addDay()])->save();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/dss/recommendations/{$rec->id}/actions", ['action' => 'start_follow_up'])
            ->assertStatus(422);
        $this->assertSame('expired', $rec->fresh()->status);
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dss/recommendations?status=expired')
            ->assertOk()
            ->assertJsonFragment(['id' => $rec->hash_id]);
    }

    public function test_snooze_at_or_after_expiry_is_rejected(): void
    {
        $user = $this->userWithPermissions(['dss.view']);
        $expiry = now()->addDays(3);
        $rec = $this->makeRecommendation(overrides: ['expires_at' => $expiry]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/dss/recommendations/{$rec->id}/actions", [
                'action' => 'snooze',
                'snoozed_until' => $expiry->toISOString(),
            ])
            ->assertStatus(422);
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

    public function test_concurrent_daily_view_conflict_is_ignored_by_database(): void
    {
        $user = $this->userWithPermissions(['dss.view']);
        $rec = $this->makeRecommendation();
        $now = now();
        $view = [
            'recommendation_id' => $rec->id,
            'event' => 'viewed',
            'actor_id' => $user->id,
            'viewed_on' => $now->toDateString(),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('dss_recommendation_events')->insertOrIgnore($view);
        $this->actingAs($user, 'sanctum')->getJson("/api/v1/dss/recommendations/{$rec->id}")->assertOk();

        $this->assertSame(1, DssRecommendationEvent::query()->where('recommendation_id', $rec->id)
            ->where('event', 'viewed')->where('actor_id', $user->id)->whereDate('viewed_on', $now->toDateString())->count());
    }

    public function test_simultaneous_service_opens_create_one_daily_view_event(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires PostgreSQL and pcntl for concurrent service calls.');
        }

        $user = $this->userWithPermissions(['dss.view']);
        $recommendation = $this->makeRecommendation();
        $userId = $user->id;
        $recommendationId = $recommendation->id;
        $runId = $recommendation->last_evaluated_run_id;
        $roleId = $user->role_id;
        while (DB::transactionLevel() > 0) {
            DB::commit();
        }

        $workers = [];
        foreach ([1, 2] as $_) {
            [$parentSocket, $childSocket] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new \RuntimeException('Could not start a DSS view race worker.');
            }
            if ($pid === 0) {
                fclose($parentSocket);
                try {
                    $base = config('database.connections.'.config('database.default'));
                    config(['database.connections.dss_view_race' => $base, 'database.default' => 'dss_view_race']);
                    DB::purge('dss_view_race');
                    DB::connection('dss_view_race')->getPdo();
                    DB::listen(static function (QueryExecuted $query) use ($childSocket): void {
                        if (str_contains(strtolower($query->sql), 'dss_run_logs')) {
                            fwrite($childSocket, "ready\n");
                            fflush($childSocket);
                            if (trim((string) fgets($childSocket)) !== 'go') {
                                throw new \RuntimeException('DSS view worker did not receive its start signal.');
                            }
                        }
                    });

                    DB::beginTransaction();
                    app(RecommendationService::class)->show(
                        User::query()->findOrFail($userId),
                        DssRecommendation::query()->findOrFail($recommendationId),
                    );
                    fwrite($childSocket, "inserted\n");
                    fflush($childSocket);
                    if (trim((string) fgets($childSocket)) !== 'commit') {
                        throw new \RuntimeException('DSS view worker did not receive its commit signal.');
                    }
                    DB::commit();
                    fwrite($childSocket, "done\n");
                    fflush($childSocket);
                } catch (\Throwable $exception) {
                    fwrite($childSocket, 'error:'.$exception->getMessage()."\n");
                    fflush($childSocket);
                    while (DB::transactionLevel() > 0) {
                        DB::rollBack();
                    }
                }
                fclose($childSocket);
                exit(0);
            }

            fclose($childSocket);
            stream_set_timeout($parentSocket, 20);
            $workers[] = ['pid' => $pid, 'socket' => $parentSocket];
        }

        try {
            foreach ($workers as $index => $worker) {
                $this->assertSame("ready\n", fgets($worker['socket']), "Worker {$index} must reach the pre-insert authorization barrier.");
            }
            foreach ($workers as $worker) {
                fwrite($worker['socket'], "go\n");
                fflush($worker['socket']);
            }

            $pending = array_keys($workers);
            while ($pending !== []) {
                $read = [];
                foreach ($pending as $index) {
                    $read[$index] = $workers[$index]['socket'];
                }
                $write = $except = [];
                $this->assertGreaterThan(0, stream_select($read, $write, $except, 20), 'Workers must reach the database uniqueness conflict.');
                foreach ($read as $index => $socket) {
                    $line = trim((string) fgets($socket));
                    $this->assertSame('inserted', $line, 'Worker error: '.$line);
                    fwrite($socket, "commit\n");
                    fflush($socket);
                    $this->assertSame("done\n", fgets($socket));
                    $pending = array_values(array_diff($pending, [$index]));
                }
            }

            $this->assertSame(1, DB::table('dss_recommendation_events')->where('recommendation_id', $recommendationId)
                ->where('event', 'viewed')->where('actor_id', $userId)->whereDate('viewed_on', now()->toDateString())->count());
        } finally {
            foreach ($workers as $worker) {
                fclose($worker['socket']);
                pcntl_waitpid($worker['pid'], $status);
            }
            DB::table('dss_recommendations')->where('id', $recommendationId)->delete();
            DB::table('dss_run_logs')->where('id', $runId)->delete();
            DB::table('users')->where('id', $userId)->delete();
            DB::table('roles')->where('id', $roleId)->delete();
        }
    }

    public function test_next_app_local_date_allows_another_view_event(): void
    {
        $user = $this->userWithPermissions(['dss.view']);
        $rec = $this->makeRecommendation();
        $this->travelTo(now()->startOfDay()->addHours(23)->addMinutes(59));
        $this->actingAs($user, 'sanctum')->getJson("/api/v1/dss/recommendations/{$rec->id}")->assertOk();
        $firstDay = now()->toDateString();

        $this->travelTo(now()->addMinutes(2));
        $this->actingAs($user, 'sanctum')->getJson("/api/v1/dss/recommendations/{$rec->id}")->assertOk();

        $views = DssRecommendationEvent::query()->where('recommendation_id', $rec->id)
            ->where('event', 'viewed')->where('actor_id', $user->id)->orderBy('id')->get();
        $this->assertCount(2, $views);
        $this->assertSame($firstDay, $views[0]->viewed_on->toDateString());
        $this->assertSame(now()->toDateString(), $views[1]->viewed_on->toDateString());
    }

    public function test_event_timeline_is_newest_first_with_stable_cursor_and_bounded_payload(): void
    {
        $user = $this->userWithPermissions(['dss.view']);
        $rec = $this->makeRecommendation();
        foreach (range(1, 55) as $i) {
            DssRecommendationEvent::query()->create([
                'recommendation_id' => $rec->id,
                'event' => $i === 55 ? 'outcome' : 'started',
                'actor_id' => $user->id,
                'reason' => $i === 55 ? str_repeat('private reason ', 30) : null,
                'metadata' => $i === 55 ? ['outcome' => ['note' => 'private outcome note', 'value' => 5, 'unit' => 'days'], 'source_payload' => 'secret-source', 'detector' => 'internal.detector'] : null,
            ]);
        }

        $url = "/api/v1/dss/recommendations/{$rec->id}/events";
        $first = $this->actingAs($user, 'sanctum')->getJson($url)->assertOk();
        $first->assertJsonCount(50, 'data')->assertJsonPath('data.0.label', 'Outcome recorded')
            ->assertJsonPath('data.0.outcome_summary', '5 days');
        $this->assertNotNull($first->json('next_cursor'));
        $this->assertLessThanOrEqual(200, mb_strlen($first->json('data.0.reason')));
        $this->assertStringNotContainsString('private outcome note', $first->getContent());
        $this->assertStringNotContainsString('secret-source', $first->getContent());
        $this->assertStringNotContainsString('internal.detector', $first->getContent());
        $this->assertArrayNotHasKey('metadata', $first->json('data.0'));

        $second = $this->getJson($url.'?cursor='.urlencode($first->json('next_cursor')))->assertOk();
        $second->assertJsonCount(5, 'data')->assertJsonPath('data.0.label', 'Follow-up started');
        $ids = array_column([...$first->json('data'), ...$second->json('data')], 'id');
        $this->assertCount(55, array_unique($ids));
        $this->assertNull($second->json('next_cursor'));
    }
}
