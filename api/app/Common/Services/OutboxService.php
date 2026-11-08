<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Jobs\DispatchOutboxMessage;
use App\Common\Models\ChainStepRun;
use App\Common\Models\OutboxMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

/**
 * Records a domain event and its optional chain-step ledger row in the same
 * database transaction as the business mutation. Queue publication is only an
 * optimization; the scheduled dispatcher is the recovery path.
 */
class OutboxService
{
    private const BATCH_INSERT_SIZE = 500;

    private const INLINE_DISPATCH_BATCH_SIZE = 250;

    public function __construct(private readonly OutboxEventCodec $codec) {}

    /**
     * @param  array{chain: string, entity_type: string, entity_id: int, entity_hash_id?: string|null, step: string}|null  $chain
     */
    public function record(
        object $event,
        ?string $dedupeKey = null,
        ?array $chain = null,
        bool $publishInlineAfterCommit = false,
    ): OutboxMessage
    {
        $encoded = $this->codec->encode($event);
        $dedupeKey ??= 'event:'.hash('sha256', $encoded['event_type'].':'.$this->json($encoded['payload']));

        $persist = function () use ($encoded, $dedupeKey, $chain): OutboxMessage {
            $now = now();
            DB::table('event_outbox')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'event_type' => $encoded['event_type'],
                'payload' => $this->json($encoded['payload']),
                'dedupe_key' => $dedupeKey,
                'status' => OutboxMessage::STATUS_PENDING,
                'attempts' => 0,
                'available_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $message = OutboxMessage::query()
                ->where('dedupe_key', $dedupeKey)
                ->firstOrFail();

            if ($chain !== null) {
                $this->recordChainStep($message, $chain);
            }

            return $message;
        };

        $message = DB::transactionLevel() === 0
            ? DB::transaction($persist)
            : $persist();

        // Some callers need a single queue hop after commit. They publish
        // through the durable row inline; other callers queue an outbox worker.
        DB::afterCommit(function () use ($message, $publishInlineAfterCommit): void {
            if ($publishInlineAfterCommit) {
                try {
                    app(OutboxDispatcher::class)->dispatch((string) $message->getKey());
                } catch (Throwable $e) {
                    Log::error('Inline outbox publication failed; durable state remains for recovery.', [
                        'outbox_id' => $message->getKey(),
                        'event_type' => $message->event_type,
                        'error' => $e->getMessage(),
                    ]);
                }

                return;
            }

            try {
                DispatchOutboxMessage::dispatch((string) $message->getKey());
            } catch (Throwable $e) {
                Log::error('Outbox dispatch callback failed; inspect durable state before recovery.', [
                    'outbox_id' => $message->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        });

        return $message;
    }

    /**
     * Persist a fan-out of content-free events together and publish them after
     * commit with one batch lease. Used where one mutation creates a per-user
     * signal for many recipients.
     *
     * @param  list<object>  $events
     */
    public function recordManyAndDispatchInlineAfterCommit(array $events): void
    {
        if ($events === []) {
            return;
        }

        $now = now();
        $rows = [];
        $eventsByOutboxId = [];

        foreach ($events as $event) {
            $encoded = $this->codec->encode($event);
            $dedupeKey = 'event:'.hash('sha256', $encoded['event_type'].':'.$this->json($encoded['payload']));
            $outboxId = (string) Str::uuid();
            $rows[] = [
                'id' => $outboxId,
                'event_type' => $encoded['event_type'],
                'payload' => $this->json($encoded['payload']),
                'dedupe_key' => $dedupeKey,
                'status' => OutboxMessage::STATUS_PENDING,
                'attempts' => 0,
                'available_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $eventsByOutboxId[$outboxId] = [
                'event_type' => $encoded['event_type'],
                'event' => $event,
            ];
        }

        $persist = function () use ($rows): void {
            foreach (array_chunk($rows, self::BATCH_INSERT_SIZE) as $chunk) {
                DB::table('event_outbox')->insert($chunk);
            }
        };

        if (DB::transactionLevel() === 0) {
            DB::transaction($persist);
        } else {
            $persist();
        }

        DB::afterCommit(function () use ($eventsByOutboxId): void {
            foreach (array_chunk($eventsByOutboxId, self::INLINE_DISPATCH_BATCH_SIZE, true) as $batch) {
                try {
                    app(OutboxDispatcher::class)->dispatchBatchInline($batch);
                } catch (Throwable $e) {
                    Log::error('Batched outbox publication failed; durable events remain for recovery.', [
                        'outbox_ids' => array_keys($batch),
                        'event_count' => count($batch),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    /**
     * Store only the latest event metadata for a single pending coalesced event.
     *
     * Domain services use this for mutations that may touch one aggregate several
     * times in the same transaction. The first call creates the durable row and
     * schedules its dispatcher; later calls replace its payload while it is still
     * pending. The dispatcher cannot see an uncommitted row, so this preserves
     * after-commit delivery while avoiding stale action/version metadata.
     */
    public function recordCoalesced(object $event, string $dedupeKey): OutboxMessage
    {
        $encoded = $this->codec->encode($event);
        $persist = function () use ($encoded, $dedupeKey): array {
            $now = now();
            $inserted = DB::table('event_outbox')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'event_type' => $encoded['event_type'],
                'payload' => $this->json($encoded['payload']),
                'dedupe_key' => $dedupeKey,
                'status' => OutboxMessage::STATUS_PENDING,
                'attempts' => 0,
                'available_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $message = OutboxMessage::query()
                ->where('dedupe_key', $dedupeKey)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $inserted && $message->status === OutboxMessage::STATUS_PENDING) {
                $message->forceFill([
                    'event_type' => $encoded['event_type'],
                    'payload' => $encoded['payload'],
                    'updated_at' => $now,
                ])->save();
            }

            return [$message, (bool) $inserted];
        };

        [$message, $inserted] = DB::transactionLevel() === 0
            ? DB::transaction($persist)
            : $persist();

        if ($inserted) {
            DB::afterCommit(function () use ($message): void {
                try {
                    DispatchOutboxMessage::dispatch((string) $message->getKey());
                } catch (Throwable $e) {
                    Log::error('Outbox dispatch callback failed; inspect durable state before recovery.', [
                        'outbox_id' => $message->getKey(),
                        'error' => $e->getMessage(),
                    ]);
                }
            });
        }

        return $message;
    }

    public function recordForChain(
        object $event,
        Model $entity,
        string $chain,
        string $entityType,
        string $step,
        ?string $dedupeKey = null,
    ): OutboxMessage {
        $hashId = property_exists($entity, 'hash_id') || isset($entity->hash_id)
            ? (string) $entity->hash_id
            : null;

        return $this->record($event, $dedupeKey, [
            'chain' => $chain,
            'entity_type' => $entityType,
            'entity_id' => (int) $entity->getKey(),
            'entity_hash_id' => $hashId,
            'step' => $step,
        ]);
    }

    private function recordChainStep(OutboxMessage $message, array $chain): void
    {
        foreach (['chain', 'entity_type', 'entity_id', 'step'] as $required) {
            if (! array_key_exists($required, $chain) || $chain[$required] === '') {
                throw new \InvalidArgumentException("Outbox chain context is missing {$required}.");
            }
        }

        $now = now();
        DB::table('chain_step_runs')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'outbox_id' => $message->getKey(),
            'chain' => $chain['chain'],
            'entity_type' => $chain['entity_type'],
            'entity_id' => $chain['entity_id'],
            'entity_hash_id' => $chain['entity_hash_id'] ?? null,
            'step' => $chain['step'],
            'event_type' => $message->event_type,
            'event_key' => $message->dedupe_key,
            'status' => ChainStepRun::STATUS_PENDING,
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function json(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new \RuntimeException('Unable to encode event outbox payload.', 0, $e);
        }
    }
}
