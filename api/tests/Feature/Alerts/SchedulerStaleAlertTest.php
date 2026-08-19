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
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * `SchedulerExecutionLedger::health()` already computed staleness — nothing
 * consumed it, so a scheduler that stalled took all 42 entries in
 * `api/routes/console.php` down with it and said nothing.
 *
 * What these tests can and cannot prove. They prove the engine consumes the
 * ledger's verdict in both directions — an unhealthy ledger raises the alert, a
 * healthy one does not — and that it does not throw while doing so. They cannot
 * prove the alert fires when the scheduler is completely dead, because it
 * cannot: `runAllChecks()` is itself driven by `alerts:run` every 15 minutes, so
 * nothing raises anything while the scheduler is down. That case is covered
 * outside the application, by the `scheduler:health` Docker healthcheck on
 * `docker-compose.prod.yml`'s `scheduler` service, whose command is exercised by
 * `Tests\Feature\Infrastructure\SchedulerHealthTest`.
 *
 * Every case asserts `$stats['failed'] === []`. `runAllChecks()` wraps each
 * check in `safe()`, which logs a throw and continues, so a `checkScheduler()`
 * that threw would raise no alert and look identical to one that correctly
 * found nothing wrong. Asserting the absence of an alert alone cannot tell
 * those apart — which is the silent-disable mode the `alerts.scheduler`
 * validation bound in `UpdateSettingRequest` exists to prevent.
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

        $stats = app(AlertEngineService::class)->runAllChecks();

        $alerts = Alert::query()->where('type', AlertType::SchedulerStale->value)->get();

        $this->assertSame([], $stats['failed'], 'no check may throw while raising the scheduler alert');
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

        $stats = app(AlertEngineService::class)->runAllChecks();

        $this->assertSame(
            [],
            $stats['failed'],
            'a silent throw must not be able to masquerade as "nothing was wrong"',
        );
        $this->assertSame(
            0,
            Alert::query()->where('type', AlertType::SchedulerStale->value)->count(),
            'a healthy ledger must not raise a scheduler alert',
        );
    }

    public function test_a_failed_task_on_a_punctual_scheduler_raises_without_claiming_the_scheduler_stopped(): void
    {
        // health() reports unhealthy when the LATEST run of any task failed,
        // regardless of tick timeliness — so this scenario is a scheduler
        // ticking perfectly with one failed task behind it. The alert must
        // still be raised, but its title must not assert a fault that is not
        // occurring: the original 'Scheduler is not running on schedule' was
        // false here, and `$latestByTask` holds a failed latest run until that
        // task next succeeds, so the false title would have stood for as long
        // as the failure did.
        $this->finishedTickMinutesAgo(0);
        $task = new CallbackEvent(new CacheEventMutex(app('cache')), static function (): void {});
        event(new ScheduledTaskStarting($task));
        event(new ScheduledTaskFailed($task, new RuntimeException('provider unavailable')));

        $stats = app(AlertEngineService::class)->runAllChecks();

        $alert = Alert::query()->where('type', AlertType::SchedulerStale->value)->first();

        $this->assertSame([], $stats['failed']);
        $this->assertNotNull($alert, 'a failed scheduled task must still surface in the application');
        $this->assertSame(
            'succeeded',
            $alert->metadata['latest_tick_status'],
            'this case is only meaningful while the heartbeat itself is healthy',
        );
        $this->assertSame(1, $alert->metadata['failed_task_count']);
        $this->assertStringNotContainsStringIgnoringCase(
            'not running',
            $alert->title,
            'the title must not claim the scheduler stopped when only a task failed',
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
