<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Models\ChainEvent;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * WS-D.2 — Idempotent recorder for chain transitions.
 *
 *   ChainEventRecorder::record(
 *       chainKey:   'sales_order',
 *       entity:     $salesOrder,
 *       eventType:  'confirmed',
 *       fromState:  'draft',
 *       toState:    'confirmed',
 *       actor:      $user,
 *       metadata:   ['source' => 'so.confirm'],
 *       idempotencyKey: "so:{$so->id}:confirmed",
 *   );
 *
 * The unique index on `idempotency_key` prevents the same logical event
 * from being written twice — re-firing a queued listener after a retry
 * is safe.
 *
 * This service is intentionally additive: existing services keep their
 * own status-mutation logic. Sites that opt in get the audit row and
 * the de-dup guard for free.
 */
class ChainEventRecorder
{
    public static function record(
        string $chainKey,
        Model $entity,
        string $eventType,
        ?string $fromState = null,
        ?string $toState = null,
        ?User $actor = null,
        ?string $reason = null,
        array $metadata = [],
        ?string $idempotencyKey = null,
    ): ?ChainEvent {
        $entityType = self::entityType($entity);
        $entityId   = (int) $entity->getKey();

        // If the caller passed an idempotency key and it already exists,
        // do nothing. We swallow the unique-constraint race below for the
        // case where two concurrent workers race on the same key.
        if ($idempotencyKey !== null
            && ChainEvent::query()->where('idempotency_key', $idempotencyKey)->exists()
        ) {
            return null;
        }

        try {
            return ChainEvent::create([
                'chain_key'        => $chainKey,
                'entity_type'      => $entityType,
                'entity_id'        => $entityId,
                'event_type'       => $eventType,
                'from_state'       => $fromState,
                'to_state'         => $toState,
                'actor_id'         => $actor?->id,
                'reason'           => $reason,
                'metadata'         => $metadata !== [] ? $metadata : null,
                'idempotency_key'  => $idempotencyKey,
                'occurred_at'      => Carbon::now(),
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Concurrent write of the same idempotency key — treat as no-op.
            return null;
        }
    }

    private static function entityType(Model $entity): string
    {
        // Prefer Laravel morph map alias if registered; fall back to base class name.
        $alias = array_search($entity::class, \Illuminate\Database\Eloquent\Relations\Relation::morphMap(), true);
        return $alias === false ? class_basename($entity) : $alias;
    }
}
