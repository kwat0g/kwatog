<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Models\SchedulerTaskRun;
use App\Common\Models\SchedulerTickRun;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Durable, best-effort execution evidence for Laravel's scheduler.
 *
 * The ledger must never become a new reason for a business task to fail. All
 * writes are therefore isolated and swallowed after logging; the scheduler's
 * own task result remains authoritative for success/failure.
 */
final class SchedulerExecutionLedger
{
    /** @var array<int, string> */
    private array $taskRunIds = [];

    private ?string $currentTickId = null;

    private ?bool $available = null;

    public function beginTick(): ?string
    {
        $id = (string) Str::uuid();

        $created = $this->safe(function () use ($id): string {
            SchedulerTickRun::query()->create([
                'id' => $id,
                'status' => SchedulerTickRun::STATUS_RUNNING,
                'failed_tasks' => 0,
                'started_at' => now(),
            ]);

            $this->currentTickId = $id;

            return $id;
        });

        return is_string($created) ? $created : null;
    }

    public function finishTick(?string $tickId, int $failedTasks, ?string $error = null, ?int $exitCode = null): void
    {
        if ($tickId === null) {
            return;
        }

        $this->safe(function () use ($tickId, $failedTasks, $error, $exitCode): void {
            $status = $failedTasks > 0 || $error !== null || ($exitCode !== null && $exitCode !== 0)
                ? SchedulerTickRun::STATUS_FAILED
                : SchedulerTickRun::STATUS_SUCCEEDED;
            $finishedAt = now();
            $attributes = [
                'status' => $status,
                'failed_tasks' => max(0, $failedTasks),
                'exit_code' => $exitCode,
                'finished_at' => $finishedAt,
                'last_error' => $error === null ? null : $this->errorText($error),
                'updated_at' => $finishedAt,
            ];

            $updated = SchedulerTickRun::query()
                ->whereKey($tickId)
                ->update($attributes);

            if ($updated > 0) {
                return;
            }

            // Preserve terminal evidence when the running row disappeared
            // before the finish event (for example after a restore or
            // operator cleanup). There is no trustworthy original start time
            // left, so use the terminal timestamp rather than inventing age.
            unset($attributes['updated_at']);
            SchedulerTickRun::query()->create([
                'id' => $tickId,
                'started_at' => $finishedAt,
                ...$attributes,
            ]);
        });

        if ($this->currentTickId === $tickId) {
            $this->currentTickId = null;
        }
    }

    public function recordTaskStarting(ScheduledTaskStarting $event): void
    {
        $task = $event->task;
        $runId = (string) Str::uuid();

        $created = $this->safe(function () use ($task, $runId): string {
            SchedulerTaskRun::query()->create([
                'id' => $runId,
                'task_key' => $this->taskKey($task),
                'task_name' => $this->taskName($task),
                'command' => $this->taskCommand($task),
                'expression' => (string) $task->getExpression(),
                'status' => SchedulerTaskRun::STATUS_RUNNING,
                'scheduler_tick_id' => $this->currentTickId,
                'started_at' => now(),
            ]);

            return $runId;
        });

        if (is_string($created)) {
            $this->taskRunIds[spl_object_id($task)] = $created;
        }
    }

    public function recordTaskFinished(ScheduledTaskFinished $event): void
    {
        $task = $event->task;
        $this->finishTask(
            $task,
            SchedulerTaskRun::STATUS_SUCCEEDED,
            $event->runtime,
        );
    }

    public function recordTaskFailed(ScheduledTaskFailed $event): void
    {
        $this->finishTask(
            $event->task,
            SchedulerTaskRun::STATUS_FAILED,
            null,
            $event->exception->getMessage(),
        );
    }

    /** @return array{healthy:bool, issues:list<string>, latest_tick:SchedulerTickRun|null, failed_tasks:list<SchedulerTaskRun>, stuck_tasks:list<SchedulerTaskRun>} */
    public function health(int $staleMinutes = 15): array
    {
        if (! $this->isAvailable()) {
            return [
                'healthy' => false,
                'issues' => ['Scheduler execution ledger is unavailable.'],
                'latest_tick' => null,
                'failed_tasks' => [],
                'stuck_tasks' => [],
            ];
        }

        $now = Carbon::now();
        $staleBefore = $now->copy()->subMinutes(max(1, $staleMinutes));
        $latestTick = SchedulerTickRun::query()->latest('started_at')->first();
        $recentTicks = SchedulerTickRun::query()->latest('started_at')->limit(2)->get();
        $issues = [];

        if (! $latestTick) {
            $issues[] = 'No scheduler tick has been recorded yet.';
        } elseif ($latestTick->status === SchedulerTickRun::STATUS_RUNNING && $latestTick->started_at?->lt($staleBefore)) {
            $issues[] = sprintf('The latest scheduler tick has been running since %s.', $latestTick->started_at?->toDateTimeString());
        } elseif ($latestTick->status === SchedulerTickRun::STATUS_FAILED) {
            $issues[] = 'The latest scheduler tick failed.'.($latestTick->last_error ? ' '.$latestTick->last_error : '');
        } elseif (($latestTick->finished_at ?? $latestTick->started_at)?->lt($staleBefore)) {
            $issues[] = sprintf('No scheduler tick has completed since %s.', $latestTick->finished_at?->toDateTimeString());
        }

        if ($recentTicks->count() === 2) {
            $previousTick = $recentTicks->get(1);
            $previousEnd = $previousTick->finished_at ?? $previousTick->started_at;
            $latestStart = $recentTicks->first()->started_at;
            // Signed on purpose. This measures the GAP from the previous tick's
            // end to the next tick's start, and the previous tick can still be
            // running when the next one starts — `finished_at` then lands after
            // `$latestStart` and the diff is negative, which correctly reads as
            // "no gap". An absolute magnitude would report an overlap of N
            // seconds as a gap of N seconds and raise a false stale alert.
            if ($previousEnd && $latestStart && $previousEnd->diffInSeconds($latestStart, false) > max(60, $staleMinutes * 60)) {
                $issues[] = sprintf(
                    'Scheduler tick gap detected: no tick started between %s and %s.',
                    $previousEnd->toDateTimeString(),
                    $latestStart->toDateTimeString(),
                );
            }
        }

        $stuckTasks = SchedulerTaskRun::query()
            ->where('status', SchedulerTaskRun::STATUS_RUNNING)
            ->where('started_at', '<', $staleBefore)
            ->orderBy('started_at')
            ->get();
        foreach ($stuckTasks as $task) {
            $issues[] = sprintf('Scheduled task [%s] has been running since %s.', $task->task_name, $task->started_at?->toDateTimeString());
        }

        $latestByTask = SchedulerTaskRun::query()
            ->orderByDesc('started_at')
            ->get()
            ->groupBy('task_key')
            ->map(static fn ($runs): SchedulerTaskRun => $runs->first());
        $failedTasks = $latestByTask
            ->filter(static fn (SchedulerTaskRun $task): bool => $task->status === SchedulerTaskRun::STATUS_FAILED)
            ->values();
        foreach ($failedTasks as $task) {
            $issues[] = sprintf('Scheduled task [%s] last failed at %s.', $task->task_name, $task->started_at?->toDateTimeString());
        }

        return [
            'healthy' => $issues === [],
            'issues' => $issues,
            'latest_tick' => $latestTick,
            'failed_tasks' => $failedTasks->all(),
            'stuck_tasks' => $stuckTasks->all(),
        ];
    }

    /** @return array{task_runs:int, tick_runs:int} */
    public function prune(int $days = 90): array
    {
        if (! $this->isAvailable()) {
            return ['task_runs' => 0, 'tick_runs' => 0];
        }

        $cutoff = Carbon::now()->subDays(max(7, $days));

        $taskRuns = $this->safe(static fn (): int => SchedulerTaskRun::query()
            ->where('status', '!=', SchedulerTaskRun::STATUS_RUNNING)
            ->where(function ($query) use ($cutoff): void {
                $query->where('finished_at', '<', $cutoff)
                    ->orWhere(function ($query) use ($cutoff): void {
                        $query->whereNull('finished_at')->where('started_at', '<', $cutoff);
                    });
            })
            ->delete());
        $tickRuns = $this->safe(static fn (): int => SchedulerTickRun::query()
            ->where('status', '!=', SchedulerTickRun::STATUS_RUNNING)
            ->where(function ($query) use ($cutoff): void {
                $query->where('finished_at', '<', $cutoff)
                    ->orWhere(function ($query) use ($cutoff): void {
                        $query->whereNull('finished_at')->where('started_at', '<', $cutoff);
                    });
            })
            ->delete());

        return [
            'task_runs' => is_int($taskRuns) ? $taskRuns : 0,
            'tick_runs' => is_int($tickRuns) ? $tickRuns : 0,
        ];
    }

    public function taskKey(Event $task): string
    {
        return substr(hash('sha256', (string) $task->getExpression().'|'.$this->taskName($task)), 0, 64);
    }

    private function finishTask(Event $task, string $status, ?float $runtime = null, ?string $error = null): void
    {
        $objectId = spl_object_id($task);
        $runId = $this->taskRunIds[$objectId] ?? null;

        $this->safe(function () use ($task, $runId, $status, $runtime, $error): void {
            $attributes = [
                'status' => $status,
                'finished_at' => now(),
                'runtime_seconds' => $runtime === null ? null : round($runtime, 2),
                'last_error' => $error === null ? null : $this->errorText($error),
                'updated_at' => now(),
            ];

            if ($runId !== null) {
                // A task can finish after its evidence row disappeared (for
                // example, a restore, manual cleanup, or an isolated test).
                // Only stop when the terminal update actually found a row;
                // otherwise fall through to the defensive recreate below so
                // the execution cannot become an untracked success/failure.
                $updated = SchedulerTaskRun::query()->whereKey($runId)->update($attributes);

                if ($updated > 0) {
                    return;
                }
            }

            // Defensive fallback for a finished/failed event emitted without
            // the matching starting event (for example, a custom test or a
            // listener registered after the scheduler started).
            $createAttributes = $attributes;
            unset($createAttributes['updated_at']);

            SchedulerTaskRun::query()->create([
                'id' => (string) Str::uuid(),
                'task_key' => $this->taskKey($task),
                'task_name' => $this->taskName($task),
                'command' => $this->taskCommand($task),
                'expression' => (string) $task->getExpression(),
                'status' => $status,
                'scheduler_tick_id' => $this->currentTickId,
                'started_at' => now()->subSeconds((int) round($runtime ?? 0)),
                ...$createAttributes,
            ]);
        });

        unset($this->taskRunIds[$objectId]);
    }

    private function taskName(Event $task): string
    {
        return mb_substr(trim((string) $task->getSummaryForDisplay()), 0, 255);
    }

    private function taskCommand(Event $task): ?string
    {
        return $task->command === null ? null : mb_substr((string) $task->command, 0, 8000);
    }

    private function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        try {
            return $this->available = Schema::hasTable('scheduler_tick_runs')
                && Schema::hasTable('scheduler_task_runs');
        } catch (Throwable) {
            return $this->available = false;
        }
    }

    private function safe(\Closure $callback): mixed
    {
        if (! $this->isAvailable()) {
            return null;
        }

        try {
            return $callback();
        } catch (Throwable $exception) {
            Log::warning('Scheduler execution ledger write failed; scheduler result remains authoritative.', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function errorText(string $error): string
    {
        return mb_substr(trim($error), 0, 8000);
    }
}
