<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Exceptions\ChainListenerRecoveryException;
use App\Common\Jobs\ReplayChainListenerJob;
use App\Common\Models\AuditLog;
use App\Common\Models\ChainListenerRun;
use App\Common\Models\OutboxMessage;
use App\Common\Support\OutboxDispatchContext;
use App\Modules\Auth\Models\User;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Operator-facing recovery contract for queued chain listeners.
 *
 * Historical listener rows are never rewritten into a new execution result.
 * Resolve adds an immutable operator disposition to the source row; replay
 * schedules one narrowly-targeted listener job and records the new run with
 * replayed_from_id lineage.
 */
class ChainListenerRecoveryService
{
    private const REPLAY_COOLDOWN_SECONDS = 60;
    private const STALE_PROCESSING_MINUTES = 10;

    /** @return array{items: array<int, array<string, mixed>>, meta: array<string, int|null>, generated_at: string} */
    public function list(Request $request): array
    {
        $query = ChainListenerRun::query()
            ->with([
                'outbox:id,event_type,status,attempts,available_at,locked_at,published_at,last_error',
                'outbox.chainStep:id,outbox_id,chain,entity_type,entity_hash_id,step,event_key,status',
                'resolvedBy:id,name',
                'replayRequestedBy:id,name',
            ])
            ->withCount('replays')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $this->applyFilters($query, $request);

        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));
        $paginator = $query->paginate($perPage)->appends($request->query());

        return [
            'items' => $paginator->getCollection()
                ->map(fn (ChainListenerRun $run): array => $this->serializeRun($run))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array{status: string, source_run_id: string, outbox_id: string, event_type: string, listener_class: string, listener_method: string, replay_count: int}
     */
    public function replay(ChainListenerRun $run, User $actor): array
    {
        /** @var array{source_run_id: string, outbox_id: string, event_type: string, listener_class: string, listener_method: string, event: object, replay_count: int} $command */
        $command = DB::transaction(function () use ($run, $actor): array {
            $locked = ChainListenerRun::query()
                ->lockForUpdate()
                ->find($run->getKey());

            if (! $locked) {
                throw new ModelNotFoundException();
            }

            $this->assertReplayable($locked);

            if (
                $locked->replay_requested_at !== null
                && $locked->replay_requested_at->gt(now()->subSeconds(self::REPLAY_COOLDOWN_SECONDS))
            ) {
                throw new ChainListenerRecoveryException(
                    'A replay was requested for this run less than one minute ago. Wait for the new run to appear before replaying again.',
                );
            }

            $outbox = OutboxMessage::query()
                ->lockForUpdate()
                ->find($locked->outbox_id);

            if (! $outbox) {
                throw new ChainListenerRecoveryException(
                    'The correlated outbox message no longer exists and cannot be replayed.',
                    422,
                );
            }

            if ($outbox->event_type !== $locked->event_type) {
                throw new ChainListenerRecoveryException(
                    'The listener and outbox event types do not match. Reconcile the ledger before replaying.',
                    422,
                );
            }

            $this->assertListenerReplayable($locked);

            try {
                $event = app(OutboxEventCodec::class)->decode($outbox->event_type, $outbox->payload);
            } catch (Throwable $exception) {
                throw new ChainListenerRecoveryException(
                    'The stored event payload cannot be decoded against current records. Resolve the data issue manually.',
                    422,
                    $exception,
                );
            }

            $now = now();
            $replayCount = (int) $locked->replay_count + 1;
            $locked->forceFill([
                'replay_count' => $replayCount,
                'replay_requested_at' => $now,
                'replay_requested_by' => $actor->id,
                'updated_at' => $now,
            ])->save();

            $this->writeAudit(
                'chain_listener.replay_requested',
                $actor,
                $locked,
                [
                    'run_id' => (string) $locked->getKey(),
                    'outbox_id' => (string) $locked->outbox_id,
                    'event_type' => $locked->event_type,
                    'listener_class' => $locked->listener_class,
                    'listener_method' => $locked->listener_method,
                    'replay_count' => $replayCount,
                    'replay_mode' => 'target_listener_only',
                ],
            );

            return [
                'source_run_id' => (string) $locked->getKey(),
                'outbox_id' => (string) $locked->outbox_id,
                'event_type' => $outbox->event_type,
                'listener_class' => $locked->listener_class,
                'listener_method' => $locked->listener_method,
                'event' => $event,
                'replay_count' => $replayCount,
            ];
        });

        try {
            OutboxDispatchContext::run(
                $command['outbox_id'],
                $command['event_type'],
                function () use ($command): mixed {
                    // Dispatch directly inside the correlation context. The
                    // static dispatch helper returns a PendingDispatch whose
                    // destructor may run after this context has unwound,
                    // losing the outbox/replay metadata on sync queues.
                    return app(Dispatcher::class)->dispatch(new ReplayChainListenerJob(
                        $command['listener_class'],
                        $command['listener_method'],
                        $command['event'],
                    ));
                },
                $command['source_run_id'],
            );
        } catch (Throwable $exception) {
            Log::error('Unable to enqueue chain listener replay.', [
                'run_id' => $command['source_run_id'],
                'outbox_id' => $command['outbox_id'],
                'listener_class' => $command['listener_class'],
                'error' => $exception->getMessage(),
            ]);

            $this->writeReplayFailureAudit($actor, $command, $exception);

            throw new ChainListenerRecoveryException(
                'The replay could not be queued. The original run remains unchanged; retry after the queue is healthy.',
                503,
                $exception,
            );
        }

        return [
            'status' => 'queued',
            'source_run_id' => $command['source_run_id'],
            'outbox_id' => $command['outbox_id'],
            'event_type' => $command['event_type'],
            'listener_class' => $command['listener_class'],
            'listener_method' => $command['listener_method'],
            'replay_count' => $command['replay_count'],
        ];
    }

    /** @return array<string, mixed> */
    public function resolve(ChainListenerRun $run, User $actor, string $note): array
    {
        $note = trim($note);
        if ($note === '') {
            throw new ChainListenerRecoveryException('A resolution note is required.', 422);
        }

        return DB::transaction(function () use ($run, $actor, $note): array {
            $locked = ChainListenerRun::query()
                ->lockForUpdate()
                ->find($run->getKey());

            if (! $locked) {
                throw new ModelNotFoundException();
            }

            if ($locked->resolution_status === ChainListenerRun::RESOLUTION_RESOLVED) {
                return [
                    'run_id' => (string) $locked->getKey(),
                    'resolution_status' => ChainListenerRun::RESOLUTION_RESOLVED,
                    'resolution_note' => $locked->resolution_note,
                    'resolved_at' => $locked->resolved_at?->toIso8601String(),
                    'resolved_by' => $locked->resolvedBy?->name,
                    'idempotent' => true,
                ];
            }

            if (in_array($locked->status, [
                ChainListenerRun::STATUS_PROCESSING,
                ChainListenerRun::STATUS_RETRYING,
            ], true)) {
                throw new ChainListenerRecoveryException(
                    'This listener is still active in the queue. Wait for the current attempt to finish before resolving it.',
                );
            }

            if (! $this->requiresResolution($locked)) {
                throw new ChainListenerRecoveryException(
                    'Only failed or manual-handoff listener runs can be manually resolved.',
                    422,
                );
            }

            $now = now();
            $locked->forceFill([
                'resolution_status' => ChainListenerRun::RESOLUTION_RESOLVED,
                'resolution_note' => mb_substr($note, 0, 2000),
                'resolved_by' => $actor->id,
                'resolved_at' => $now,
                'updated_at' => $now,
            ])->save();

            $this->writeAudit(
                'chain_listener.resolved',
                $actor,
                $locked,
                [
                    'run_id' => (string) $locked->getKey(),
                    'outbox_id' => (string) $locked->outbox_id,
                    'event_type' => $locked->event_type,
                    'listener_class' => $locked->listener_class,
                    'outcome_status' => $locked->outcome_status,
                    'outcome_code' => $locked->outcome_code,
                    'resolution_status' => ChainListenerRun::RESOLUTION_RESOLVED,
                    'resolution_note' => mb_substr($note, 0, 2000),
                ],
            );

            return [
                'run_id' => (string) $locked->getKey(),
                'resolution_status' => ChainListenerRun::RESOLUTION_RESOLVED,
                'resolution_note' => $locked->resolution_note,
                'resolved_at' => $now->toIso8601String(),
                'resolved_by' => $actor->name,
                'idempotent' => false,
            ];
        });
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        $attention = $request->has('attention') ? $request->boolean('attention') : true;
        if ($attention) {
            $staleAt = now()->subMinutes(self::STALE_PROCESSING_MINUTES);
            $query
                ->whereNull('resolved_at')
                ->where(function (Builder $attentionQuery) use ($staleAt): void {
                    $attentionQuery
                        ->where('status', ChainListenerRun::STATUS_FAILED)
                        ->orWhereIn('outcome_status', [
                            ChainListenerRun::OUTCOME_MANUAL_REQUIRED,
                            ChainListenerRun::OUTCOME_FAILED,
                        ])
                        ->orWhere('status', ChainListenerRun::STATUS_RETRYING)
                        ->orWhere(function (Builder $staleQuery) use ($staleAt): void {
                            $staleQuery
                                ->where('status', ChainListenerRun::STATUS_PROCESSING)
                                ->where(function (Builder $attemptQuery) use ($staleAt): void {
                                    $attemptQuery
                                        ->where('last_attempt_at', '<=', $staleAt)
                                        ->orWhereNull('last_attempt_at');
                                });
                        });
                });
        }

        $status = $request->query('status');
        if (is_string($status) && in_array($status, [
            ChainListenerRun::STATUS_PROCESSING,
            ChainListenerRun::STATUS_RETRYING,
            ChainListenerRun::STATUS_COMPLETED,
            ChainListenerRun::STATUS_FAILED,
        ], true)) {
            $query->where('status', $status);
        }

        $outcome = $request->query('outcome');
        if (is_string($outcome) && $outcome === 'unclassified') {
            $query->whereNull('outcome_status');
        } elseif (is_string($outcome) && in_array($outcome, [
            ChainListenerRun::OUTCOME_COMPLETED,
            ChainListenerRun::OUTCOME_SKIPPED,
            ChainListenerRun::OUTCOME_MANUAL_REQUIRED,
            ChainListenerRun::OUTCOME_FAILED,
        ], true)) {
            $query->where('outcome_status', $outcome);
        }

        $resolution = $request->query('resolution');
        if (is_string($resolution) && $resolution === ChainListenerRun::RESOLUTION_RESOLVED) {
            $query->where('resolution_status', ChainListenerRun::RESOLUTION_RESOLVED);
        } elseif (is_string($resolution) && $resolution === 'open') {
            $query->whereNull('resolved_at');
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $searchQuery) use ($like): void {
                $searchQuery
                    ->where('event_type', 'like', $like)
                    ->orWhere('listener_class', 'like', $like)
                    ->orWhere('outcome_code', 'like', $like)
                    ->orWhere('job_uuid', 'like', $like)
                    ->orWhere('outbox_id', 'like', $like);
            });
        }
    }

    private function assertReplayable(ChainListenerRun $run): void
    {
        if (in_array($run->status, [
            ChainListenerRun::STATUS_PROCESSING,
            ChainListenerRun::STATUS_RETRYING,
        ], true)) {
            throw new ChainListenerRecoveryException(
                'This listener is still active in the queue. Wait for the current attempt to finish before replaying it.',
            );
        }

        if (! $this->requiresResolution($run)) {
            throw new ChainListenerRecoveryException(
                'Only failed or manual-handoff listener runs can be replayed.',
                422,
            );
        }
    }

    private function requiresResolution(ChainListenerRun $run): bool
    {
        return $run->status === ChainListenerRun::STATUS_FAILED
            || in_array($run->outcome_status, [
                ChainListenerRun::OUTCOME_MANUAL_REQUIRED,
                ChainListenerRun::OUTCOME_FAILED,
            ], true);
    }

    private function assertListenerReplayable(ChainListenerRun $run): void
    {
        $class = $run->listener_class;
        if (
            $run->listener_method !== 'handle'
            || ! str_starts_with($class, 'App\\Modules\\')
            || ! str_contains($class, '\\Listeners\\')
            || ! class_exists($class)
            || ! is_a($class, ShouldQueue::class, true)
            || ! method_exists($class, 'handle')
        ) {
            throw new ChainListenerRecoveryException(
                'This listener is not on the approved queued-listener replay allow-list.',
                422,
            );
        }
    }

    /** @return array<string, mixed> */
    private function serializeRun(ChainListenerRun $run): array
    {
        $outbox = $run->outbox;
        $chainStep = $outbox?->chainStep;
        $actionable = $this->requiresResolution($run);

        return [
            'id' => (string) $run->getKey(),
            'event_type' => $run->event_type,
            'listener_class' => $run->listener_class,
            'listener_method' => $run->listener_method,
            'queue' => [
                'status' => $run->status,
                'attempts' => (int) $run->attempts,
                'started_at' => $run->started_at?->toIso8601String(),
                'last_attempt_at' => $run->last_attempt_at?->toIso8601String(),
                'completed_at' => $run->completed_at?->toIso8601String(),
                'failed_at' => $run->failed_at?->toIso8601String(),
                'last_error' => $this->shortText($run->last_error),
            ],
            'outcome' => [
                'status' => $run->outcome_status ?? 'unclassified',
                'code' => $run->outcome_code,
                'message' => $this->shortText($run->outcome_message),
                'at' => $run->outcome_at?->toIso8601String(),
            ],
            'resolution' => [
                'status' => $run->resolution_status
                    ?? ($actionable ? 'open' : 'not_required'),
                'note' => $run->resolution_note,
                'resolved_at' => $run->resolved_at?->toIso8601String(),
                'resolved_by' => $run->resolvedBy?->name,
            ],
            'correlation' => [
                'outbox_id' => (string) $run->outbox_id,
                'job_uuid' => $run->job_uuid,
                'replayed_from_id' => $run->replayed_from_id,
            ],
            'replay' => [
                'count' => max((int) $run->replay_count, (int) ($run->replays_count ?? 0)),
                'requested_at' => $run->replay_requested_at?->toIso8601String(),
                'requested_by' => $run->replayRequestedBy?->name,
            ],
            'outbox' => $outbox ? [
                'status' => $outbox->status,
                'attempts' => (int) $outbox->attempts,
                'available_at' => $outbox->available_at?->toIso8601String(),
                'locked_at' => $outbox->locked_at?->toIso8601String(),
                'published_at' => $outbox->published_at?->toIso8601String(),
                'last_error' => $this->shortText($outbox->last_error),
            ] : null,
            'chain_step' => $chainStep ? [
                'chain' => $chainStep->chain,
                'entity_type' => $chainStep->entity_type,
                'entity_hash_id' => $chainStep->entity_hash_id,
                'step' => $chainStep->step,
                'event_key' => $chainStep->event_key,
                'status' => $chainStep->status,
            ] : null,
            'created_at' => $run->created_at?->toIso8601String(),
            'updated_at' => $run->updated_at?->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $newValues */
    private function writeAudit(string $action, User $actor, ChainListenerRun $run, array $newValues): void
    {
        AuditLog::create([
            'user_id' => $actor->id,
            'action' => $action,
            'model_type' => 'chain_listener_run',
            'model_id' => null,
            'old_values' => [
                'run_id' => (string) $run->getKey(),
                'resolution_status' => $run->getOriginal('resolution_status'),
            ],
            'new_values' => $newValues,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'created_at' => now(),
        ]);
    }

    /** @param array{source_run_id: string, outbox_id: string, event_type: string, listener_class: string, listener_method: string, event: object, replay_count: int} $command */
    private function writeReplayFailureAudit(User $actor, array $command, Throwable $exception): void
    {
        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'chain_listener.replay_failed',
            'model_type' => 'chain_listener_run',
            'model_id' => null,
            'old_values' => null,
            'new_values' => [
                'run_id' => $command['source_run_id'],
                'outbox_id' => $command['outbox_id'],
                'listener_class' => $command['listener_class'],
                'error' => $this->shortText($exception->getMessage()),
            ],
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'created_at' => now(),
        ]);
    }

    private function shortText(?string $value): ?string
    {
        return $value === null ? null : mb_substr($value, 0, 4000);
    }
}
