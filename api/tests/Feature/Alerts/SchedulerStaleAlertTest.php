<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Common\Enums\AlertSeverity;
use App\Common\Enums\AlertType;
use App\Common\Models\Alert;
use App\Common\Models\SchedulerTaskRun;
use App\Common\Models\SchedulerTickRun;
use App\Common\Services\AlertEngineService;
use App\Common\Services\SchedulerExecutionLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `SchedulerExecutionLedger::health()` already computed staleness — nothing
 * consumed it, so a scheduler that stalled took all 42 entries in
 * `api/routes/console.php` down with it and said nothing.
 *
 * What these two tests can and cannot prove. They prove the engine consumes
 * the ledger's verdict in both directions: an unhealthy ledger raises the
 * alert, a healthy one does not. They cannot prove the alert fires when the
 * scheduler is completely dead, because it cannot: `runAllChecks()` is itself
 * driven by `alerts:run` every 15 minutes, so nothing raises anything while
 * the scheduler is down. That case is covered outside the application, by the
 * `scheduler:health` Docker healthcheck on `docker-compose.prod.yml`'s
 * `scheduler` service, whose command is exercised by
 * `Tests\Feature\Infrastructure\SchedulerHealthTest`.
 */
class SchedulerStaleAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // ScheduleTickFailureTest exercises the real scheduler without
        // RefreshDatabase and can therefore leave durable ledger rows visible
        // to this transaction. Health must describe only this test's scenario.
        SchedulerTaskRun::query()->delete();
        SchedulerTickRun::query()->delete();
    }

    public function test_a_ledger_whose_latest_tick_is_long_finished_raises_a_critical_alert(): void
    {
        // 90 minutes idle against the seeded 30-minute threshold (migration
        // 0474). One tick only, so this is the "no tick has completed since"
        // arm of health() rather than the two-tick gap arm.
        $this->finishedTickMinutesAgo(90);

        app(AlertEngineService::class)->runAllChecks();

        $alerts = Alert::query()->where('type', AlertType::SchedulerStale->value)->get();

        $this->assertCount(1, $alerts, 'a stale scheduler ledger must raise exactly one alert');
        $alert = $alerts->first();
        $this->assertSame(AlertType::SchedulerStale, $alert->type);
        $this->assertSame(AlertSeverity::Critical, $alert->severity);
        $this->assertNull($alert->entity_id, 'the alert is about the scheduler itself, not a row');
        $this->assertSame(30, $alert->metadata['stale_minutes']);
        $this->assertNotEmpty(
            $alert->metadata['issues'],
            'the ledger issues must be carried into the alert, not discarded',
        );
    }

    public function test_a_normally_ticking_ledger_raises_no_scheduler_alert(): void
    {
        $this->finishedTickMinutesAgo(0);

        app(AlertEngineService::class)->runAllChecks();

        $this->assertSame(
            0,
            Alert::query()->where('type', AlertType::SchedulerStale->value)->count(),
            'a healthy ledger must not raise a scheduler alert',
        );
    }

    private function finishedTickMinutesAgo(int $minutes): void
    {
        $ledger = app(SchedulerExecutionLedger::class);
        $tickId = $ledger->beginTick();
        $ledger->finishTick($tickId, 0, null, 0);

        if ($minutes > 0) {
            SchedulerTickRun::query()->whereKey($tickId)->update([
                'started_at' => now()->subMinutes($minutes),
                'finished_at' => now()->subMinutes($minutes),
            ]);
        }
    }
}
