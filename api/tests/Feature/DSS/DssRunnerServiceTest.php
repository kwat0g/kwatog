<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Modules\DSS\Contracts\DetectorEmission;
use App\Modules\DSS\Contracts\DetectorInterface;
use App\Modules\DSS\Contracts\DetectorScanResult;
use App\Modules\DSS\Models\DssRecommendation;
use App\Modules\DSS\Models\DssRunLog;
use App\Modules\DSS\Services\DetectorRegistry;
use App\Modules\DSS\Services\DssParameterService;
use App\Modules\DSS\Services\DssRunnerService;
use App\Modules\DSS\Services\RecommendationScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_command_succeeds_empty_and_fails_on_detector_failure(): void
    {
        $this->artisan('dss:run')->assertSuccessful();

        app(DetectorRegistry::class)->register('test.throwing', ThrowingFakeDetector::class);

        $this->artisan('dss:run')->assertFailed();
    }
}
