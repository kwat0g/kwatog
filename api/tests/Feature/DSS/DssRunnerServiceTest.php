<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Jobs\RunDssDetectorJob;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\DSS\Contracts\DetectorEmission;
use App\Modules\DSS\Contracts\DetectorInterface;
use App\Modules\DSS\Contracts\DetectorScanResult;
use App\Modules\DSS\Contracts\RecommendationVerifierInterface;
use App\Modules\DSS\Contracts\VerificationResult;
use App\Modules\DSS\Models\DssRecommendation;
use App\Modules\DSS\Models\DssRunLog;
use App\Modules\DSS\Services\DetectorRegistry;
use App\Modules\DSS\Services\DssParameterService;
use App\Modules\DSS\Services\DssRunnerService;
use App\Modules\DSS\Services\RecommendationScorer;
use App\Modules\DSS\Services\RecommendationService;
use App\Modules\DSS\Verifiers\WorkOrderInterventionVerifier;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EmittingFakeDetector implements DetectorInterface
{
    public function id(): string
    {
        return 'test.emitting';
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
        return new DetectorScanResult([
            new DetectorEmission(
                type: 'low_stock', severity: 'warning', dedupeKey: 'item-1',
                rationale: ['rule' => 'usable < reorder_point', 'inputs' => ['usable' => 3, 'reorder_point' => 10]],
                confidence: 0.8,
            ),
            new DetectorEmission(
                type: 'low_stock', severity: 'critical', dedupeKey: 'item-2',
                rationale: ['rule' => 'usable < safety_stock'],
                impactEstimate: '1500.00', impactUnit: 'PHP', confidence: 0.9,
                expiresAt: new \DateTimeImmutable('+12 hours'),
            ),
        ], rowsScanned: 2);
    }
}

class ThrowingFakeDetector implements DetectorInterface
{
    public function id(): string
    {
        return 'test.throwing';
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
        throw new \RuntimeException('boom');
    }
}

class ChangingFakeDetector extends EmittingFakeDetector
{
    public static bool $emit = true;

    public static bool $complete = true;

    public function id(): string
    {
        return 'test.changing';
    }

    public function detect(array $context = []): DetectorScanResult
    {
        return new DetectorScanResult(
            self::$emit ? parent::detect($context)->emissions : [],
            rowsScanned: 2,
            complete: self::$complete,
        );
    }
}

class SlowFakeDetector extends EmittingFakeDetector
{
    public function id(): string
    {
        return 'test.slow';
    }

    public function detect(array $context = []): DetectorScanResult
    {
        usleep(20000);

        return parent::detect($context);
    }
}

class WorkOrderFakeDetector extends EmittingFakeDetector
{
    public static bool $emit = true;

    public function id(): string
    {
        return 'dss.work_order_intervention';
    }

    public function detect(array $context = []): DetectorScanResult
    {
        if (! self::$emit) {
            return new DetectorScanResult([], rowsScanned: 1);
        }

        return new DetectorScanResult([new DetectorEmission(
            type: 'work_order_intervention', severity: 'warning', dedupeKey: 'slow-verification',
            rationale: ['rule' => 'test'],
            verificationContext: ['source_id' => 999999999, 'planned_end' => now()->subDay()->toIso8601String()],
        )], rowsScanned: 1);
    }
}

class SlowMultiQueryVerifier implements RecommendationVerifierInterface
{
    public function verify(DssRecommendation $recommendation): VerificationResult
    {
        DB::selectOne('select 1 as dss_deadline_probe');
        usleep(1_100_000);
        DB::selectOne('select 1 as dss_deadline_probe');

        return new VerificationResult(VerificationResult::VERIFIED_CLEAR, 'The source condition cleared.', now());
    }
}

class DuplicateFakeDetector extends EmittingFakeDetector
{
    public function id(): string
    {
        return 'test.duplicate';
    }

    public function detect(array $context = []): DetectorScanResult
    {
        $emission = parent::detect($context)->emissions[0];

        return new DetectorScanResult([$emission, $emission], rowsScanned: 2);
    }
}

class DssRunnerServiceTest extends TestCase
{
    use RefreshDatabase;

    private function runner(DetectorRegistry $registry): DssRunnerService
    {
        $params = app(DssParameterService::class);

        return new DssRunnerService($registry, $params, new RecommendationScorer($params));
    }

    public function test_run_persists_emissions_as_open_rows_with_scores(): void
    {
        $registry = new DetectorRegistry;
        $registry->register('test.emitting', EmittingFakeDetector::class);

        $outcome = $this->runner($registry)->run();

        $this->assertSame('enabled', $outcome['engine']);
        $this->assertSame('completed', $outcome['detectors']['test.emitting']['status']);
        $this->assertSame(2, $outcome['detectors']['test.emitting']['emitted']);
        $this->assertSame(2, DssRecommendation::query()->where('status', 'open')->count());

        $critical = DssRecommendation::query()->where('dedupe_key', 'item-2')->firstOrFail();
        $warning = DssRecommendation::query()->where('dedupe_key', 'item-1')->firstOrFail();
        $this->assertTrue($warning->expires_at->gt(now()->addHours(25)));
        $this->assertTrue($warning->expires_at->lte(now()->addHours(26)));
        $this->assertTrue($critical->expires_at->gt(now()->addHours(11)));
        $this->assertGreaterThan((float) $warning->priority_score, (float) $critical->priority_score);
        $this->assertSame(['rule' => 'usable < safety_stock'], $critical->rationale);

        $log = DssRunLog::query()->where('detector_id', 'test.emitting')->firstOrFail();
        $this->assertSame('completed', $log->status);
        $this->assertSame(2, $log->emitted);
        $this->assertSame(2, $log->rows_scanned);
        $this->assertNotNull($log->duration_ms);
        $this->assertSame($log->id, $critical->last_evaluated_run_id);
        $this->assertNotNull($critical->first_detected_at);
        $this->assertSame('unavailable', $critical->verification_status);
    }

    public function test_second_run_updates_instead_of_duplicating(): void
    {
        $registry = new DetectorRegistry;
        $registry->register('test.emitting', EmittingFakeDetector::class);
        $runner = $this->runner($registry);

        $first = $runner->run();
        $before = DssRecommendation::query()->where('dedupe_key', 'item-1')->firstOrFail();
        $firstRunId = $before->last_evaluated_run_id;
        $second = $runner->run();

        $this->assertSame(0, $first['detectors']['test.emitting']['updated']);
        $this->assertSame(2, $second['detectors']['test.emitting']['updated']);
        $this->assertSame(2, DssRecommendation::query()->count());
        $recommendation = DssRecommendation::query()->where('dedupe_key', 'item-1')->firstOrFail();
        $this->assertTrue($recommendation->last_evaluated_at->gte($before->last_evaluated_at));
        $this->assertTrue($recommendation->first_detected_at->equalTo($before->first_detected_at));
        $this->assertNotSame($firstRunId, $recommendation->last_evaluated_run_id);
    }

    public function test_throwing_detector_is_isolated(): void
    {
        $registry = new DetectorRegistry;
        $registry->register('test.emitting', EmittingFakeDetector::class);
        $registry->register('test.throwing', ThrowingFakeDetector::class);

        $outcome = $this->runner($registry)->run();

        $this->assertSame(['test.throwing'], $outcome['failed']);
        $this->assertSame('completed', $outcome['detectors']['test.emitting']['status']);
        $this->assertSame(2, DssRecommendation::query()->count());

        $log = DssRunLog::query()->where('detector_id', 'test.throwing')->firstOrFail();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('boom', (string) $log->error);
    }

    public function test_kill_switch_runs_nothing(): void
    {
        $registry = new DetectorRegistry;
        $registry->register('test.emitting', EmittingFakeDetector::class);

        app(DssParameterService::class)->set(DssParameterService::GLOBAL, 'dss.engine.enabled', false);

        $outcome = $this->runner($registry)->run();

        $this->assertSame('disabled', $outcome['engine']);
        $this->assertSame(0, DssRecommendation::query()->count());
        $this->assertSame(0, DssRunLog::query()->count());
    }

    public function test_shadow_mode_still_persists_for_later_judgment(): void
    {
        $registry = new DetectorRegistry;
        $registry->register('test.emitting', EmittingFakeDetector::class);

        app(DssParameterService::class)->set('test.emitting', 'mode', 'shadow');

        $outcome = $this->runner($registry)->run();

        // Display filtering is W0-3's job; the runner persists in every mode.
        $this->assertSame('completed', $outcome['detectors']['test.emitting']['status']);
        $this->assertSame(2, DssRecommendation::query()->count());
    }

    public function test_disabled_detector_is_skipped(): void
    {
        $registry = new DetectorRegistry;
        $registry->register('test.emitting', EmittingFakeDetector::class);

        app(DssParameterService::class)->set('test.emitting', 'enabled', false);

        $outcome = $this->runner($registry)->run();

        $this->assertSame('skipped', $outcome['detectors']['test.emitting']['status']);
        $this->assertSame(0, DssRecommendation::query()->count());
        $this->assertDatabaseHas('dss_run_logs', ['detector_id' => 'test.emitting', 'status' => 'skipped']);
    }

    public function test_complete_scan_without_source_proof_keeps_missing_recommendations_unverified(): void
    {
        ChangingFakeDetector::$emit = true;
        ChangingFakeDetector::$complete = true;
        $registry = new DetectorRegistry;
        $registry->register('test.changing', ChangingFakeDetector::class);
        $runner = $this->runner($registry);

        $runner->run();
        ChangingFakeDetector::$emit = false;
        $runner->run();

        $missing = DssRecommendation::query()->where('dedupe_key', 'item-1')->firstOrFail();
        $this->assertSame('open', $missing->status);
        $this->assertSame('unavailable', $missing->verification_status);
        $this->assertSame(2, DssRecommendation::query()->where('status', 'open')->count());
        $this->assertSame(0, DssRecommendation::query()->where('status', 'resolved')->count());
        $this->assertSame('completed', DssRunLog::query()->where('detector_id', 'test.changing')->latest('id')->value('status'));
    }

    public function test_incomplete_scan_does_not_expire_existing_recommendations(): void
    {
        ChangingFakeDetector::$emit = true;
        ChangingFakeDetector::$complete = true;
        $registry = new DetectorRegistry;
        $registry->register('test.changing', ChangingFakeDetector::class);
        $runner = $this->runner($registry);
        $runner->run();
        $evaluationRun = DssRecommendation::query()->where('dedupe_key', 'item-1')->value('last_evaluated_run_id');

        ChangingFakeDetector::$emit = false;
        ChangingFakeDetector::$complete = false;
        $outcome = $runner->run();

        $this->assertSame(2, DssRecommendation::query()->where('status', 'open')->count());
        $this->assertSame($evaluationRun, DssRecommendation::query()->where('dedupe_key', 'item-1')->value('last_evaluated_run_id'));
        $this->assertSame('partial', $outcome['detectors']['test.changing']['status']);
    }

    public function test_over_budget_scan_is_discarded_and_reported(): void
    {
        $registry = new DetectorRegistry;
        $registry->register('test.slow', SlowFakeDetector::class);

        $outcome = $this->runner($registry)->run('test.slow', ['time_budget_ms' => 1]);

        $this->assertSame(['test.slow'], $outcome['failed']);
        $this->assertSame(0, DssRecommendation::query()->count());
        $this->assertStringContainsString('exceeded its 1ms time budget', $outcome['detectors']['test.slow']['error']);
        $this->assertSame('failed', DssRunLog::query()->where('detector_id', 'test.slow')->value('status'));
    }

    public function test_slow_verification_times_out_without_resolving_missing_recommendations(): void
    {
        WorkOrderFakeDetector::$emit = true;
        $registry = new DetectorRegistry;
        $registry->register('dss.work_order_intervention', WorkOrderFakeDetector::class);
        $runner = $this->runner($registry);
        $runner->run();
        WorkOrderFakeDetector::$emit = false;
        if (DB::getDriverName() === 'pgsql') {
            DB::selectOne("select set_config('statement_timeout', '27s', true)");
        }

        $delayed = false;
        DB::listen(function (QueryExecuted $query) use (&$delayed): void {
            if (! $delayed && str_contains($query->sql, 'work_orders')) {
                $delayed = true;
                usleep(150000);
            }
        });

        $outcome = $runner->run('dss.work_order_intervention', ['time_budget_ms' => 100]);
        $recommendation = DssRecommendation::query()->where('dedupe_key', 'slow-verification')->firstOrFail();

        $this->assertTrue($delayed);
        $this->assertSame('failed', $outcome['detectors']['dss.work_order_intervention']['status']);
        $this->assertStringContainsString('exceeded its 100ms time budget', $outcome['detectors']['dss.work_order_intervention']['error']);
        $this->assertSame('open', $recommendation->status);
        $this->assertSame('still_open', $recommendation->verification_status);
        $this->assertNull($recommendation->resolution_evidence);
        $this->assertSame('failed', DssRunLog::query()->where('detector_id', 'dss.work_order_intervention')->latest('id')->value('status'));
        if (DB::getDriverName() === 'pgsql') {
            $this->assertSame('27s', DB::selectOne("select current_setting('statement_timeout') as timeout")->timeout);
        }
    }

    public function test_deadline_is_refreshed_before_each_query_in_a_multi_query_verifier(): void
    {
        WorkOrderFakeDetector::$emit = true;
        $registry = new DetectorRegistry;
        $registry->register('dss.work_order_intervention', WorkOrderFakeDetector::class);
        $runner = $this->runner($registry);
        $runner->run('dss.work_order_intervention');
        WorkOrderFakeDetector::$emit = false;
        app()->instance(WorkOrderInterventionVerifier::class, new SlowMultiQueryVerifier);

        $probeQueries = 0;
        DB::listen(function (QueryExecuted $query) use (&$probeQueries): void {
            if ($query->sql === 'select 1 as dss_deadline_probe') {
                $probeQueries++;
            }
        });

        $outcome = $runner->run('dss.work_order_intervention', ['time_budget_ms' => 1000]);
        $recommendation = DssRecommendation::query()->where('dedupe_key', 'slow-verification')->firstOrFail();

        $this->assertSame(1, $probeQueries);
        $this->assertSame('failed', $outcome['detectors']['dss.work_order_intervention']['status']);
        $this->assertSame('open', $recommendation->status);
        $this->assertNull($recommendation->resolution_evidence);
    }

    public function test_nested_success_restores_callers_postgres_statement_timeout(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL statement timeout is not available.');
        }

        $registry = new DetectorRegistry;
        $registry->register('test.emitting', EmittingFakeDetector::class);
        $boundedTimeouts = [];
        Event::listen(TransactionCommitted::class, function (TransactionCommitted $event) use (&$boundedTimeouts): void {
            if ($event->connectionName === DB::getDefaultConnection() && $event->connection->transactionLevel() === 2) {
                $boundedTimeouts[] = (int) $event->connection->selectOne("select setting from pg_settings where name = 'statement_timeout'")->setting;
            }
        });
        DB::transaction(function () use ($registry): void {
            DB::selectOne("select set_config('statement_timeout', '27s', true)");
            $this->runner($registry)->run('test.emitting', ['time_budget_ms' => 5000]);

            $this->assertSame('27s', DB::selectOne("select current_setting('statement_timeout') as timeout")->timeout);
        });
        $this->assertNotEmpty($boundedTimeouts);
        $this->assertLessThan(27000, end($boundedTimeouts));
    }

    public function test_maintain_fails_stale_running_logs_idempotently_and_hides_their_recommendations(): void
    {
        $registry = app(DetectorRegistry::class);
        $registry->register('test.emitting', EmittingFakeDetector::class);
        app(DssParameterService::class)->set('test.emitting', 'mode', 'advisory');
        $run = DssRunLog::query()->create([
            'detector_id' => 'test.emitting', 'status' => 'running',
            'started_at' => now()->subMinutes(6),
        ]);
        $recommendation = DssRecommendation::query()->create([
            'detector_id' => 'test.emitting', 'domain' => 'test', 'type' => 'low_stock',
            'rationale' => ['rule' => 'test'], 'dedupe_key' => 'interrupted-run',
            'last_evaluated_at' => now()->subMinutes(6), 'last_evaluated_run_id' => $run->id,
            'owner_permissions' => ['dss.view'],
        ]);
        $adminRole = Role::query()->create(['name' => 'DSS test admin', 'slug' => 'system_admin', 'is_system' => true]);
        $user = User::factory()->create(['role_id' => $adminRole->id]);

        $this->artisan('dss:maintain')->assertSuccessful();
        $this->artisan('dss:maintain')->assertSuccessful();

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(DssRunLog::INTERRUPTION_REASON, $run->fresh()->error);
        $this->assertNotNull($run->fresh()->finished_at);
        $this->assertSame('open', $recommendation->fresh()->status);
        $this->assertFalse(app(RecommendationService::class)->canSee($user, 'test.emitting', $recommendation->fresh()));
    }

    public function test_duplicate_emissions_fail_before_any_recommendation_write(): void
    {
        $registry = new DetectorRegistry;
        $registry->register('test.duplicate', DuplicateFakeDetector::class);

        $outcome = $this->runner($registry)->run();

        $this->assertSame('failed', $outcome['detectors']['test.duplicate']['status']);
        $this->assertSame(0, DssRecommendation::query()->count());
        $this->assertSame('failed', DssRunLog::query()->where('detector_id', 'test.duplicate')->value('status'));
    }

    public function test_interrupted_emission_persistence_rolls_back_and_records_failed_run(): void
    {
        $registry = new DetectorRegistry;
        $registry->register('test.emitting', EmittingFakeDetector::class);
        $recommendationInserts = 0;
        DB::listen(function (QueryExecuted $query) use (&$recommendationInserts): void {
            if (str_contains(strtolower($query->sql), 'insert into "dss_recommendations"')) {
                $recommendationInserts++;
                if ($recommendationInserts === 2) {
                    throw new \RuntimeException('simulated interruption during persistence');
                }
            }
        });

        $outcome = $this->runner($registry)->run();

        $this->assertSame(2, $recommendationInserts);
        $this->assertSame('failed', $outcome['detectors']['test.emitting']['status']);
        $this->assertSame(0, DssRecommendation::query()->count());
        $run = DssRunLog::query()->where('detector_id', 'test.emitting')->firstOrFail();
        $this->assertSame('failed', $run->status);
        $this->assertSame(0, $run->emitted);
        $this->assertStringContainsString('simulated interruption', (string) $run->error);
    }

    public function test_reemission_links_only_a_previously_resolved_occurrence(): void
    {
        ChangingFakeDetector::$emit = true;
        ChangingFakeDetector::$complete = true;
        $registry = new DetectorRegistry;
        $registry->register('test.changing', ChangingFakeDetector::class);
        $runner = $this->runner($registry);

        $runner->run();
        $resolved = DssRecommendation::query()->where('dedupe_key', 'item-1')->firstOrFail();
        $resolved->forceFill(['status' => 'resolved', 'verification_status' => 'verified_clear', 'resolution_evidence' => ['proof' => 'source state changed']])->save();
        $runner->run();
        $recurrence = DssRecommendation::query()->where('dedupe_key', 'item-1')->where('status', 'open')->firstOrFail();
        $this->assertSame($resolved->id, $recurrence->predecessor_id);

        $recurrence->forceFill(['status' => 'accepted'])->save();
        $runner->run();
        $afterAcknowledgement = DssRecommendation::query()->where('dedupe_key', 'item-1')->where('status', 'open')->firstOrFail();
        $this->assertNull($afterAcknowledgement->predecessor_id);
        $this->assertNull($afterAcknowledgement->resolution_evidence);
    }

    public function test_command_queues_all_registered_detectors_independently(): void
    {
        Queue::fake();
        $this->artisan('dss:run')->assertSuccessful();
        Queue::assertPushed(RunDssDetectorJob::class, 11);

        app(DetectorRegistry::class)->register('test.throwing', ThrowingFakeDetector::class);

        $this->artisan('dss:run')->assertSuccessful();
        Queue::assertPushed(RunDssDetectorJob::class, 12);
    }

    public function test_detector_queue_job_runs_inline_and_respects_horizon_lease_bounds(): void
    {
        $registry = new DetectorRegistry;
        $registry->register('test.emitting', EmittingFakeDetector::class);
        $params = app(DssParameterService::class);
        $runner = $this->runner($registry);

        (new RunDssDetectorJob('test.emitting'))->handle($runner, $params);

        $this->assertSame('completed', DssRunLog::query()->where('detector_id', 'test.emitting')->value('status'));
        $this->assertSame(2, DssRecommendation::query()->where('detector_id', 'test.emitting')->count());
        $this->assertSame(280, (new RunDssDetectorJob('test.emitting'))->timeout);
        $this->assertSame('dss', (new RunDssDetectorJob('test.emitting'))->queue);
        $this->assertSame('redis_dss', (new RunDssDetectorJob('test.emitting'))->connection);
        $this->assertLessThan(310, config('horizon.defaults.supervisor-dss.timeout'));
        $this->assertGreaterThan(config('horizon.defaults.supervisor-dss.timeout'), config('queue.connections.redis_dss.retry_after'));
    }

    public function test_queue_timeout_failure_marks_active_run_failed_without_recommendations(): void
    {
        $run = DssRunLog::query()->create([
            'detector_id' => 'test.emitting',
            'status' => 'running',
            'started_at' => now()->subSeconds(280),
        ]);
        $job = new RunDssDetectorJob('test.emitting');

        DB::beginTransaction();
        $job->failed([], new TimeoutExceededException('timed out'), 'test-uuid', null);

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(DssRunLog::INTERRUPTION_REASON, $run->fresh()->error);
        $this->assertSame(0, DssRecommendation::query()->where('detector_id', 'test.emitting')->count());
        $this->assertSame(1, DB::connection()->transactionLevel());
    }
}
