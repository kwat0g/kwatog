<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Common\Services\ScheduleTickFailureTracker;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use RuntimeException;
use Tests\TestCase;

class ScheduleTickFailureTest extends TestCase
{
    public function test_a_scheduled_task_failure_is_counted_for_the_fail_fast_wrapper(): void
    {
        $tracker = app(ScheduleTickFailureTracker::class);
        $tracker->reset();

        $event = new CallbackEvent(new CacheEventMutex(app('cache')), static function (): void {
            throw new RuntimeException('scheduled task failed');
        });

        event(new ScheduledTaskFailed($event, new RuntimeException('scheduled task failed')));

        $this->assertSame(1, $tracker->count());
    }

    public function test_a_clean_scheduler_tick_returns_success(): void
    {
        // Do not let the wall clock turn this unit-level wrapper test into a
        // run of the production calendar. On a 15-minute boundary that can
        // execute a real due command and make a healthy fail-fast wrapper
        // look broken for unrelated reasons.
        $originalSchedule = app(Schedule::class);
        app()->forgetInstance(Schedule::class);
        app()->instance(Schedule::class, new Schedule);

        try {
            $this->artisan('schedule:run-fail-fast', ['--no-interaction' => true])
                ->assertExitCode(0);
        } finally {
            app()->forgetInstance(Schedule::class);
            app()->instance(Schedule::class, $originalSchedule);
        }
    }

    public function test_a_due_task_failure_makes_the_wrapper_return_non_zero(): void
    {
        $originalSchedule = app(Schedule::class);
        app()->forgetInstance(Schedule::class);
        app()->instance(Schedule::class, tap(new Schedule, static function (Schedule $schedule): void {
            $schedule->call(static function (): void {
                throw new RuntimeException('deterministic scheduler smoke failure');
            })->everyMinute();
        }));

        try {
            $this->artisan('schedule:run-fail-fast', ['--no-interaction' => true])
                ->assertExitCode(1);
        } finally {
            app()->forgetInstance(Schedule::class);
            app()->instance(Schedule::class, $originalSchedule);
        }
    }
}
