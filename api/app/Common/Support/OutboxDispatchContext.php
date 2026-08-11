<?php

declare(strict_types=1);

namespace App\Common\Support;

/**
 * Correlates queued listener payloads with the outbox message that emitted
 * them. The stack matters for the sync queue: a listener may synchronously
 * produce another durable event, and the nested event must not overwrite its
 * parent's correlation context.
 */
final class OutboxDispatchContext
{
    /** @var list<array{outbox_id: string, event_type: string, replayed_from_id: string|null}> */
    private static array $stack = [];

    public static function run(
        string $outboxId,
        string $eventType,
        callable $callback,
        ?string $replayedFromId = null,
    ): mixed
    {
        self::$stack[] = [
            'outbox_id' => $outboxId,
            'event_type' => $eventType,
            'replayed_from_id' => $replayedFromId,
        ];

        try {
            return $callback();
        } finally {
            array_pop(self::$stack);
        }
    }

    /** @return array{outbox_id: string, outbox_event_type: string, chain_replayed_from_id?: string} */
    public static function payload(): array
    {
        $index = array_key_last(self::$stack);
        if ($index === null) {
            return [];
        }

        $context = self::$stack[$index];

        $payload = [
            'outbox_id' => $context['outbox_id'],
            'outbox_event_type' => $context['event_type'],
        ];

        if ($context['replayed_from_id'] !== null) {
            $payload['chain_replayed_from_id'] = $context['replayed_from_id'];
        }

        return $payload;
    }
}
