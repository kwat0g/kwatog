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
    public function __construct(private readonly OutboxEventCodec $codec) {}

    /**
     * @param  array{chain: string, entity_type: string, entity_id: int, entity_hash_id?: string|null, step: string}|null  $chain
     */
    public function record(object $event, ?string $dedupeKey = null, ?array $chain = null): OutboxMessage
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

        // If Redis is down, this callback only logs the enqueue failure; the
        // row remains pending for the scheduled dispatcher to recover.
        DB::afterCommit(function () use ($message): void {
            try {
                DispatchOutboxMessage::dispatch((string) $message->getKey());
            } catch (Throwable $e) {
                Log::error('Unable to enqueue outbox message; scheduler will retry it.', [
                    'outbox_id' => $message->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        });

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
