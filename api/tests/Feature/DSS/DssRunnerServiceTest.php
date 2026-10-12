<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Modules\DSS\Contracts\DetectorEmission;
use App\Modules\DSS\Contracts\DetectorInterface;
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

    public function detect(array $context = []): array
    {
        return [
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
        ];
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

    public function detect(array $context = []): array
    {
        throw new \RuntimeException('boom');
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
        $this->assertGreaterThan((float) $warning->priority_score, (float) $critical->priority_score);
        $this->assertSame(['rule' => 'usable < safety_stock'], $critical->rationale);

        $log = DssRunLog::query()->where('detector_id', 'test.emitting')->firstOrFail();
        $this->assertSame('completed', $log->status);
        $this->assertSame(2, $log->emitted);
        $this->assertNotNull($log->duration_ms);
    }

    public function test_second_run_updates_instead_of_duplicating(): void
    {
        $registry = new DetectorRegistry;
        $registry->register('test.emitting', EmittingFakeDetector::class);
        $runner = $this->runner($registry);

        $first = $runner->run();
        $second = $runner->run();

        $this->assertSame(0, $first['detectors']['test.emitting']['updated']);
        $this->assertSame(2, $second['detectors']['test.emitting']['updated']);
        $this->assertSame(2, DssRecommendation::query()->count());
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
    }

    public function test_command_succeeds_empty_and_fails_on_detector_failure(): void
    {
        $this->artisan('dss:run')->assertSuccessful();

        app(DetectorRegistry::class)->register('test.throwing', ThrowingFakeDetector::class);

        $this->artisan('dss:run')->assertFailed();
    }
}
