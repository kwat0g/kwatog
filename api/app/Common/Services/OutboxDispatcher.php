<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Models\ChainStepRun;
use App\Common\Models\OutboxMessage;
use App\Common\Support\OutboxDispatchContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class OutboxDispatcher
{
    private const STALE_AFTER_MINUTES = 10;

    public function __construct(private readonly OutboxEventCodec $codec) {}

    public function dispatch(string $outboxId, ?callable $onClaim = null): ?string
    {
        $message = $this->claim($outboxId);
        if ($message === null) {
            return null;
        }

        $leaseToken = (string) $message->lease_token;
        if ($onClaim !== null) {
            $onClaim($leaseToken);
        }

        try {
            $event = $this->codec->decode($message->event_type, $message->payload);
            OutboxDispatchContext::run(
                (string) $message->getKey(),
                $message->event_type,
                static fn () => event($event),
            );
            $this->markPublished($message->getKey(), $leaseToken);
        } catch (Throwable $e) {
            $this->markPending($message->getKey(), $e, $leaseToken);
            throw $e;
        }

        return $leaseToken;
    }

    public function markFailed(string $outboxId, Throwable $exception, ?string $leaseToken = null): void
    {
        DB::transaction(function () use ($outboxId, $exception, $leaseToken): void {
            $message = OutboxMessage::query()->lockForUpdate()->find($outboxId);
            if (! $message || $message->status === OutboxMessage::STATUS_PUBLISHED) {
                return;
            }

            if (! $this->ownsLease($message, $leaseToken)) {
                return;
            }

            $message->forceFill([
                'status' => OutboxMessage::STATUS_FAILED,
                'locked_at' => null,
                'lease_token' => null,
                'last_error' => $this->errorText($exception),
                'updated_at' => now(),
            ])->save();

            $this->updateChain($outboxId, [
                'status' => ChainStepRun::STATUS_FAILED,
                'last_error' => $this->errorText($exception),
                'updated_at' => now(),
            ]);
        });
    }

    /** @return array{requeued: int, dispatched: int} */
    public function requeueFailed(int $limit = 100): array
    {
        return DB::transaction(function () use ($limit): array {
            // Claim the failed rows while holding their locks. Without this,
            // a worker can claim a row after the select but before the bulk
            // update, and the retry command can overwrite its processing state.
            $ids = OutboxMessage::query()
                ->where('status', OutboxMessage::STATUS_FAILED)
                ->orderBy('updated_at')
                ->limit($limit)
                ->lockForUpdate()
                ->pluck('id');

            if ($ids->isEmpty()) {
                return ['requeued' => 0, 'dispatched' => 0];
            }

            $now = now();
            OutboxMessage::query()
                ->whereIn('id', $ids)
                ->update([
                    'status' => OutboxMessage::STATUS_PENDING,
                    'available_at' => $now,
                    'last_error' => null,
                    'updated_at' => $now,
                ]);
            ChainStepRun::query()
                ->whereIn('outbox_id', $ids)
                ->update([
                    'status' => ChainStepRun::STATUS_PENDING,
                    'last_error' => null,
                    'updated_at' => $now,
                ]);

            return ['requeued' => $ids->count(), 'dispatched' => 0];
        });
    }

    private function claim(string $outboxId): ?OutboxMessage
    {
        return DB::transaction(function () use ($outboxId): ?OutboxMessage {
            $message = OutboxMessage::query()->lockForUpdate()->find($outboxId);
            if (! $message || $message->status === OutboxMessage::STATUS_PUBLISHED) {
                return null;
            }

            // A delayed duplicate queue job must not defeat the retry
            // backoff written by markPending(). The minute-level dispatcher
            // already filters this boundary, but direct/late queue delivery
            // must enforce it too.
            if (
                $message->status === OutboxMessage::STATUS_PENDING
                && $message->available_at?->gt(now())
            ) {
                return null;
            }

            if (
                $message->status === OutboxMessage::STATUS_PROCESSING
                && $message->locked_at?->gt(now()->subMinutes(self::STALE_AFTER_MINUTES))
            ) {
                return null;
            }

            $message->forceFill([
                'status' => OutboxMessage::STATUS_PROCESSING,
                'attempts' => $message->attempts + 1,
                'locked_at' => now(),
                'lease_token' => (string) Str::uuid(),
                'last_error' => null,
                'updated_at' => now(),
            ])->save();

            $this->updateChain($outboxId, [
                'status' => ChainStepRun::STATUS_PROCESSING,
                'attempts' => $message->attempts,
                'last_attempt_at' => now(),
                'last_error' => null,
                'updated_at' => now(),
            ]);

            return $message->fresh();
        });
    }

    private function markPublished(string $outboxId, string $leaseToken): void
    {
        DB::transaction(function () use ($outboxId, $leaseToken): void {
            $message = OutboxMessage::query()->lockForUpdate()->find($outboxId);
            if (! $message || $message->status === OutboxMessage::STATUS_PUBLISHED) {
                return;
            }

            if (! $this->ownsLease($message, $leaseToken)) {
                return;
            }

            $now = now();
            $message->forceFill([
                'status' => OutboxMessage::STATUS_PUBLISHED,
                'locked_at' => null,
                'lease_token' => null,
                'published_at' => $now,
                'updated_at' => $now,
            ])->save();

            $this->updateChain($outboxId, [
                'status' => ChainStepRun::STATUS_PUBLISHED,
                'completed_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    private function markPending(string $outboxId, Throwable $exception, string $leaseToken): void
    {
        DB::transaction(function () use ($outboxId, $exception, $leaseToken): void {
            $message = OutboxMessage::query()->lockForUpdate()->find($outboxId);
            if (! $message || $message->status === OutboxMessage::STATUS_PUBLISHED) {
                return;
            }

            if (! $this->ownsLease($message, $leaseToken)) {
                return;
            }

            $error = $this->errorText($exception);
            $now = now();
            $message->forceFill([
                'status' => OutboxMessage::STATUS_PENDING,
                'available_at' => $now->copy()->addSeconds(min(300, 10 * max(1, $message->attempts))),
                'locked_at' => null,
                'lease_token' => null,
                'last_error' => $error,
                'updated_at' => $now,
            ])->save();
            $this->updateChain($outboxId, [
                'status' => ChainStepRun::STATUS_PENDING,
                'last_error' => $error,
                'updated_at' => $now,
            ]);
        });
    }

    private function updateChain(string $outboxId, array $attributes): void
    {
        ChainStepRun::query()->where('outbox_id', $outboxId)->update($attributes);
    }

    private function errorText(Throwable $exception): string
    {
        return mb_substr($exception::class.': '.$exception->getMessage(), 0, 8000);
    }

    private function ownsLease(OutboxMessage $message, ?string $leaseToken): bool
    {
        if ($leaseToken !== null && $leaseToken !== '') {
            return hash_equals((string) $message->lease_token, $leaseToken);
        }

        // Keep direct/manual failure marking useful for already-pending rows,
        // while never allowing a legacy caller without a token to overwrite a
        // currently processing lease owned by a newer worker.
        return $message->status !== OutboxMessage::STATUS_PROCESSING;
    }
}
