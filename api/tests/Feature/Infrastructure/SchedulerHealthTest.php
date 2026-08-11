<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Common\Models\SchedulerTaskRun;
use App\Common\Models\SchedulerTickRun;
use App\Common\Services\SchedulerExecutionLedger;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SchedulerHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // ScheduleTickFailureTest exercises the real scheduler without
        // RefreshDatabase and therefore can leave durable ledger rows behind
        // for this transaction. Health assertions must describe only the
        // scenario created by the current test.
        SchedulerTaskRun::query()->delete();
        SchedulerTickRun::query()->delete();
    }

    public function test_a_completed_tick_and_task_are_healthy(): void
    {
        $ledger = app(SchedulerExecutionLedger::class);
        $tickId = $ledger->beginTick();
        $event = $this->scheduledEvent();

        event(new ScheduledTaskStarting($event));
        event(new ScheduledTaskFinished($event, 0.42));
        $ledger->finishTick($tickId, 0, null, 0);

        $this->artisan('scheduler:health')
            ->expectsOutputToContain('Scheduler health is OK.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('scheduler_tick_runs', [
            'id' => $tickId,
            'status' => SchedulerTickRun::STATUS_SUCCEEDED,
        ]);
        $this->assertDatabaseHas('scheduler_task_runs', [
            'task_key' => $ledger->taskKey($event),
            'status' => SchedulerTaskRun::STATUS_SUCCEEDED,
        ]);
    }

    public function test_a_stale_running_tick_fails_health(): void
    {
        $tickId = app(SchedulerExecutionLedger::class)->beginTick();
        SchedulerTickRun::query()->whereKey($tickId)->update([
            'started_at' => now()->subMinutes(20),
        ]);

        $this->artisan('scheduler:health', ['--stale-minutes' => 15])
            ->expectsOutputToContain('latest scheduler tick has been running')
            ->assertExitCode(1);
    }

    public function test_the_latest_failed_task_fails_health_even_after_the_tick_finishes(): void
    {
        $ledger = app(SchedulerExecutionLedger::class);
        $tickId = $ledger->beginTick();
        $event = $this->scheduledEvent();

        event(new ScheduledTaskStarting($event));
        event(new ScheduledTaskFailed($event, new RuntimeException('provider unavailable')));
        $ledger->finishTick($tickId, 1, 'scheduled task failed', 1);

        $this->artisan('scheduler:health')
            ->expectsOutputToContain('last failed')
            ->assertExitCode(1);
    }

    public function test_a_terminal_event_recreates_missing_task_evidence(): void
    {
        $ledger = app(SchedulerExecutionLedger::class);
        $tickId = $ledger->beginTick();
        $event = $this->scheduledEvent();

        event(new ScheduledTaskStarting($event));
        SchedulerTaskRun::query()->where('task_key', $ledger->taskKey($event))->delete();

        event(new ScheduledTaskFinished($event, 0.42));

        $this->assertDatabaseHas('scheduler_task_runs', [
            'task_key' => $ledger->taskKey($event),
            'scheduler_tick_id' => $tickId,
            'status' => SchedulerTaskRun::STATUS_SUCCEEDED,
        ]);
    }

    public function test_finishing_a_missing_tick_recreates_terminal_tick_evidence(): void
    {
        $ledger = app(SchedulerExecutionLedger::class);
        $tickId = $ledger->beginTick();
        SchedulerTickRun::query()->whereKey($tickId)->delete();

        $ledger->finishTick($tickId, 1, 'scheduled task failed', 1);

        $this->assertDatabaseHas('scheduler_tick_runs', [
            'id' => $tickId,
            'status' => SchedulerTickRun::STATUS_FAILED,
            'failed_tasks' => 1,
            'exit_code' => 1,
            'last_error' => 'scheduled task failed',
        ]);
    }

    public function test_a_gap_between_ticks_fails_health_after_the_scheduler_recovers(): void
    {
        $ledger = app(SchedulerExecutionLedger::class);
        $firstTick = $ledger->beginTick();
        $ledger->finishTick($firstTick, 0, null, 0);
        SchedulerTickRun::query()->whereKey($firstTick)->update([
            'started_at' => now()->subMinutes(45),
            'finished_at' => now()->subMinutes(45),
        ]);

        $secondTick = $ledger->beginTick();
        $ledger->finishTick($secondTick, 0, null, 0);

        $this->artisan('scheduler:health', ['--stale-minutes' => 15])
            ->expectsOutputToContain('Scheduler tick gap detected')
            ->assertExitCode(1);
    }

    public function test_pruning_removes_old_terminal_evidence_but_keeps_stuck_runs(): void
    {
        $ledger = app(SchedulerExecutionLedger::class);
        $oldTick = $ledger->beginTick();
        $ledger->finishTick($oldTick, 0, null, 0);
        $oldTask = $this->scheduledEvent();
        event(new ScheduledTaskStarting($oldTask));
        event(new ScheduledTaskFinished($oldTask, 0.1));

        SchedulerTickRun::query()->whereKey($oldTick)->update([
            'started_at' => now()->subDays(100),
            'finished_at' => now()->subDays(100),
        ]);
        SchedulerTaskRun::query()->where('task_key', $ledger->taskKey($oldTask))->update([
            'started_at' => now()->subDays(100),
            'finished_at' => now()->subDays(100),
        ]);
        $stuckTick = $ledger->beginTick();
        SchedulerTickRun::query()->whereKey($stuckTick)->update([
            'started_at' => now()->subDays(100),
        ]);

        $deleted = $ledger->prune(90);
        $this->assertSame(1, $deleted['task_runs']);
        $this->assertSame(1, $deleted['tick_runs']);

        $this->assertDatabaseMissing('scheduler_tick_runs', ['id' => $oldTick]);
        $this->assertDatabaseMissing('scheduler_task_runs', ['task_key' => $ledger->taskKey($oldTask)]);
        $this->assertDatabaseHas('scheduler_tick_runs', ['id' => $stuckTick]);
    }

    private function scheduledEvent(): CallbackEvent
    {
        return new CallbackEvent(new CacheEventMutex(app('cache')), static function (): void {});
    }
}
