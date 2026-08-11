<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Models\ChainListenerRun;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Records the execution outcome of listeners emitted by a durable event.
 *
 * Laravel's queued listener wrapper normally has no business-event identity.
 * OutboxDispatchContext adds that identity to the queue payload, allowing the
 * standard queue lifecycle hooks to turn failed_jobs into actionable chain
 * telemetry without changing every listener signature.
 */
class ChainListenerRunService
{
    /** @var list<array{outbox_id: string, job_uuid: string, event_type: string, listener_class: string, listener_method: string, replayed_from_id: string|null}> */
    private static array $contextStack = [];

    public function markProcessing(JobProcessing $event): void
    {
        $this->pushContext($this->metadata($event->job));
        $this->safe(fn () => $this->record($event->job, ChainListenerRun::STATUS_PROCESSING));
    }

    public function markProcessed(JobProcessed $event): void
    {
        $this->safe(fn () => $this->record($event->job, ChainListenerRun::STATUS_COMPLETED));
        $this->popContext($event->job);
    }

    public function markRetrying(JobExceptionOccurred $event): void
    {
        $this->safe(fn () => $this->record(
            $event->job,
            ChainListenerRun::STATUS_RETRYING,
            $event->exception,
        ));
        $this->popContext($event->job);
    }

    public function markFailed(JobFailed $event): void
    {
        $this->safe(fn () => $this->record(
            $event->job,
            ChainListenerRun::STATUS_FAILED,
            $event->exception,
        ));
        $this->popContext($event->job);
    }

    /**
     * Record the business meaning of the current listener attempt.
     *
     * Queue lifecycle status answers whether Laravel ran the job. This
     * separate outcome answers what the stateful listener did: a side effect
     * completed, a duplicate/stale event was safely skipped, or an operator
     * handoff is required. A context stack keeps nested sync-queue jobs from
     * overwriting their parent's correlation.
     */
    public function recordOutcome(string $outcome, string $code, ?string $message = null): void
    {
        $this->safe(function () use ($outcome, $code, $message): void {
            if (! in_array($outcome, [
                ChainListenerRun::OUTCOME_COMPLETED,
                ChainListenerRun::OUTCOME_SKIPPED,
                ChainListenerRun::OUTCOME_MANUAL_REQUIRED,
                ChainListenerRun::OUTCOME_FAILED,
            ], true)) {
                throw new InvalidArgumentException("Unknown chain listener outcome [{$outcome}].");
            }

            $context = $this->currentContext();
            if ($context === null) {
                return;
            }

            $normalizedCode = trim($code);
            if ($normalizedCode === '') {
                throw new InvalidArgumentException('Chain listener outcome code cannot be blank.');
            }

            $now = now();
            $attributes = [
                'outcome_status' => $outcome,
                'outcome_code' => mb_substr($normalizedCode, 0, 100),
                'outcome_message' => $message === null ? null : mb_substr($message, 0, 8000),
                'outcome_at' => $now,
                'updated_at' => $now,
            ];
            $updated = DB::table('chain_listener_runs')
                ->where('job_uuid', $context['job_uuid'])
                ->where('outbox_id', $context['outbox_id'])
                ->update($attributes);

            if ($updated > 0) {
                return;
            }

            // The queue can finish a business handler after an operator or a
            // restore removed its telemetry row. Recreate the in-flight row
            // so the explicit business disposition is not replaced by the
            // generic queue_completed fallback in markProcessed().
            DB::table('chain_listener_runs')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'outbox_id' => $context['outbox_id'],
                'job_uuid' => $context['job_uuid'],
                'event_type' => $context['event_type'],
                'listener_class' => $context['listener_class'],
                'listener_method' => $context['listener_method'],
                'status' => ChainListenerRun::STATUS_PROCESSING,
                'attempts' => 1,
                'started_at' => $now,
                'last_attempt_at' => $now,
                'outcome_status' => $outcome,
                'outcome_code' => mb_substr($normalizedCode, 0, 100),
                'outcome_message' => $message === null ? null : mb_substr($message, 0, 8000),
                'outcome_at' => $now,
                'replayed_from_id' => $context['replayed_from_id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    private function record(Job $job, string $status, ?Throwable $exception = null): void
    {
        $metadata = $this->metadata($job);
        if ($metadata === null) {
            return;
        }

        $now = now();
        $jobAttempts = method_exists($job, 'attempts') ? max(1, (int) $job->attempts()) : 1;

        // insertOrIgnore gives concurrent worker/retry notifications one
        // durable row; the update below then applies the latest lifecycle
        // state without relying on a race-prone firstOrCreate sequence.
        DB::table('chain_listener_runs')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'outbox_id' => $metadata['outbox_id'],
            'job_uuid' => $metadata['job_uuid'],
            'event_type' => $metadata['event_type'],
            'listener_class' => $metadata['listener_class'],
            'listener_method' => $metadata['listener_method'],
            'status' => $status,
            'attempts' => $jobAttempts,
            'started_at' => $status === ChainListenerRun::STATUS_PROCESSING ? $now : null,
            'last_attempt_at' => in_array($status, [
                ChainListenerRun::STATUS_PROCESSING,
                ChainListenerRun::STATUS_RETRYING,
            ], true) ? $now : null,
            'completed_at' => $status === ChainListenerRun::STATUS_COMPLETED ? $now : null,
            'failed_at' => $status === ChainListenerRun::STATUS_FAILED ? $now : null,
            'last_error' => $exception ? $this->errorText($exception) : null,
            'outcome_status' => $this->initialOutcome($status),
            'outcome_code' => $this->initialOutcomeCode($status),
            'outcome_message' => $exception ? $this->errorText($exception) : null,
            'outcome_at' => $this->initialOutcome($status) !== null ? $now : null,
            'replayed_from_id' => $metadata['replayed_from_id'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $attributes = [
            'event_type' => $metadata['event_type'],
            'listener_class' => $metadata['listener_class'],
            'listener_method' => $metadata['listener_method'],
            'status' => $status,
            'attempts' => $jobAttempts,
            'updated_at' => $now,
        ];

        if ($status === ChainListenerRun::STATUS_PROCESSING) {
            $attributes += [
                'started_at' => $now,
                'last_attempt_at' => $now,
                'completed_at' => null,
                'failed_at' => null,
                'last_error' => null,
                'outcome_status' => null,
                'outcome_code' => null,
                'outcome_message' => null,
                'outcome_at' => null,
            ];
        } elseif ($status === ChainListenerRun::STATUS_RETRYING) {
            $attributes += [
                'last_attempt_at' => $now,
                'failed_at' => null,
                'last_error' => $exception ? $this->errorText($exception) : null,
                'outcome_status' => null,
                'outcome_code' => null,
                'outcome_message' => null,
                'outcome_at' => null,
            ];
        } elseif ($status === ChainListenerRun::STATUS_COMPLETED) {
            $attributes += [
                'completed_at' => $now,
                'last_error' => null,
            ];
        } else {
            $error = $exception ? $this->errorText($exception) : 'Listener job failed.';
            $attributes += [
                'failed_at' => $now,
                'last_error' => $error,
                'outcome_status' => ChainListenerRun::OUTCOME_FAILED,
                'outcome_code' => 'queue_failed',
                'outcome_message' => $error,
                'outcome_at' => $now,
            ];
        }

        DB::table('chain_listener_runs')
            ->where('job_uuid', $metadata['job_uuid'])
            ->where('outbox_id', $metadata['outbox_id'])
            ->update($attributes);

        if ($status === ChainListenerRun::STATUS_COMPLETED) {
            DB::table('chain_listener_runs')
                ->where('job_uuid', $metadata['job_uuid'])
                ->where('outbox_id', $metadata['outbox_id'])
                ->whereNull('outcome_status')
                ->update([
                    'outcome_status' => ChainListenerRun::OUTCOME_COMPLETED,
                    'outcome_code' => 'queue_completed',
                    'outcome_at' => $now,
                    'updated_at' => $now,
                ]);
        }
    }

    /** @param array{outbox_id: string, job_uuid: string, event_type: string, listener_class: string, listener_method: string, replayed_from_id: string|null}|null $metadata */
    private function pushContext(?array $metadata): void
    {
        if ($metadata !== null) {
            self::$contextStack[] = $metadata;
        }
    }

    private function popContext(Job $job): void
    {
        $metadata = $this->metadata($job);
        if ($metadata === null) {
            return;
        }

        for ($index = count(self::$contextStack) - 1; $index >= 0; $index--) {
            if (self::$contextStack[$index]['job_uuid'] === $metadata['job_uuid']) {
                array_splice(self::$contextStack, $index, 1);
                return;
            }
        }
    }

    /** @return array{outbox_id: string, job_uuid: string, event_type: string, listener_class: string, listener_method: string, replayed_from_id: string|null}|null */
    private function currentContext(): ?array
    {
        $index = array_key_last(self::$contextStack);

        return $index === null ? null : self::$contextStack[$index];
    }

    private function initialOutcome(string $status): ?string
    {
        return match ($status) {
            ChainListenerRun::STATUS_COMPLETED => ChainListenerRun::OUTCOME_COMPLETED,
            ChainListenerRun::STATUS_FAILED => ChainListenerRun::OUTCOME_FAILED,
            default => null,
        };
    }

    private function initialOutcomeCode(string $status): ?string
    {
        return match ($status) {
            ChainListenerRun::STATUS_COMPLETED => 'queue_completed',
            ChainListenerRun::STATUS_FAILED => 'queue_failed',
            default => null,
        };
    }

    /** @return array{outbox_id: string, job_uuid: string, event_type: string, listener_class: string, listener_method: string, replayed_from_id: string|null}|null */
    private function metadata(Job $job): ?array
    {
        if (! method_exists($job, 'payload')) {
            return null;
        }

        $payload = $job->payload();
        $outboxId = $payload['outbox_id'] ?? null;
        $eventType = $payload['outbox_event_type'] ?? null;
        $jobUuid = $payload['uuid'] ?? null;
        $listenerClass = $payload['displayName'] ?? null;
        $replayedFromId = $payload['chain_replayed_from_id'] ?? null;

        if (! is_string($outboxId) || $outboxId === ''
            || ! is_string($eventType) || $eventType === ''
            || ! is_string($jobUuid) || $jobUuid === ''
            || ! is_string($listenerClass) || ! str_starts_with($listenerClass, 'App\\Modules\\')
            || ! str_contains($listenerClass, '\\Listeners\\')) {
            return null;
        }

        return [
            'outbox_id' => $outboxId,
            'job_uuid' => $jobUuid,
            'event_type' => $eventType,
            'listener_class' => $listenerClass,
            'listener_method' => 'handle',
            'replayed_from_id' => is_string($replayedFromId) && $replayedFromId !== '' ? $replayedFromId : null,
        ];
    }

    private function safe(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            // Observability must never turn a successful listener into a
            // failed business job because its telemetry table is unavailable.
            Log::warning('Unable to record chain listener run.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function errorText(Throwable $exception): string
    {
        return mb_substr($exception::class.': '.$exception->getMessage(), 0, 8000);
    }
}
