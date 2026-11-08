<?php

declare(strict_types=1);

namespace App\Common\Services;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Records wait and execution time for the two jobs that carry live signals. */
final class RealtimeQueueTelemetry
{
    private const MONITORED_JOBS = [
        'App\\Common\\Jobs\\DispatchOutboxMessage',
        'Illuminate\\Broadcasting\\BroadcastEvent',
    ];

    /** @var array<string, int> */
    private array $startedAt = [];

    public function queued(JobQueued $event): void
    {
        try {
            if (! in_array($event->queue, ['high', 'broadcasts'], true)) {
                return;
            }

            $payload = $event->payload();
            $jobName = $payload['data']['commandName'] ?? null;
            if (! is_string($jobName) || ! in_array($jobName, self::MONITORED_JOBS, true)) {
                return;
            }

            Log::channel('realtime')->info('realtime.queue.dispatched', [
                'job' => $jobName,
                'job_id' => (string) $event->id,
                'connection' => $event->connectionName,
                'queue' => $event->queue,
                'precise_enqueue_timestamp_present' => is_numeric($payload['realtime_queue_enqueued_at'] ?? null),
                'broadcast_event' => $jobName === \Illuminate\Broadcasting\BroadcastEvent::class
                    && is_string($payload['displayName'] ?? null)
                    ? $payload['displayName']
                    : null,
            ]);
        } catch (Throwable $exception) {
            Log::warning('realtime.queue.telemetry_failed', ['error_class' => $exception::class]);
        }
    }

    public function started(JobProcessing $event): void
    {
        try {
            $payload = $event->job->payload();
            $jobName = $this->jobClass($event->job, $payload);
            if (! in_array($jobName, self::MONITORED_JOBS, true)) {
                return;
            }

            $jobId = (string) $event->job->getJobId();
            $key = $event->connectionName.':'.$jobId;
            $this->startedAt[$key] = hrtime(true);
            $enqueuedAt = $payload['realtime_queue_enqueued_at'] ?? null;
            $queueWaitSource = 'custom';
            if (! is_numeric($enqueuedAt) && is_numeric($payload['createdAt'] ?? null)) {
                // Laravel's standard payload timestamp is second-granularity;
                // it still separates dispatch time from worker execution and
                // remains useful when custom payload hooks are not preserved.
                $enqueuedAt = $payload['createdAt'];
                $queueWaitSource = 'created_at_seconds';
            }
            $queueWaitMs = is_numeric($enqueuedAt)
                ? max(0, round((microtime(true) - (float) $enqueuedAt) * 1000, 1))
                : null;

            Log::channel('realtime')->info('realtime.queue.started', [
                'job' => $jobName,
                'job_id' => $jobId,
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
                'queue_wait_ms' => $queueWaitMs,
                'queue_wait_source' => $queueWaitMs === null ? null : $queueWaitSource,
                'broadcast_event' => $jobName === \Illuminate\Broadcasting\BroadcastEvent::class
                    && is_string($payload['displayName'] ?? null)
                    ? $payload['displayName']
                    : null,
            ]);
        } catch (Throwable $exception) {
            // Telemetry must never interrupt a queued broadcast or outbox job.
            Log::warning('realtime.queue.telemetry_failed', ['error_class' => $exception::class]);
        }
    }

    public function processed(JobProcessed|JobExceptionOccurred $event): void
    {
        try {
            $payload = $event->job->payload();
            $jobName = $this->jobClass($event->job, $payload);
            if (! in_array($jobName, self::MONITORED_JOBS, true)) {
                return;
            }

            $jobId = (string) $event->job->getJobId();
            $key = $event->connectionName.':'.$jobId;
            $startedAt = $this->startedAt[$key] ?? null;
            unset($this->startedAt[$key]);

            $context = [
                'job' => $jobName,
                'job_id' => $jobId,
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
                'broadcast_event' => $jobName === \Illuminate\Broadcasting\BroadcastEvent::class
                    && is_string($payload['displayName'] ?? null)
                    ? $payload['displayName']
                    : null,
                'duration_ms' => $startedAt === null
                    ? null
                    : round((hrtime(true) - $startedAt) / 1_000_000, 1),
            ];

            if ($event instanceof JobExceptionOccurred) {
                $context['error_class'] = $event->exception::class;
                Log::channel('realtime')->warning('realtime.queue.failed', $context);

                return;
            }

            Log::channel('realtime')->info('realtime.queue.completed', $context);
        } catch (Throwable $exception) {
            Log::warning('realtime.queue.telemetry_failed', ['error_class' => $exception::class]);
        }
    }

    /** Resolve the real queued wrapper class; displayName is the broadcast event class. */
    private function jobClass(Job $job, array $payload): string
    {
        $commandName = $payload['data']['commandName'] ?? null;

        return is_string($commandName) ? $commandName : $job->resolveName();
    }
}
