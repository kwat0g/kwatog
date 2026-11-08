<?php

declare(strict_types=1);

namespace App\Common\Events;

use DateTimeInterface;
use Illuminate\Support\Str;

/** Builds the shared, content-free metadata envelope for refresh broadcasts. */
final class BroadcastEventEnvelope
{
    /**
     * Complete the common metadata at the last shared boundary before a
     * Pusher/Reverb broadcast. Older event classes keep their existing wire
     * keys for compatibility; this adds the fields every refresh consumer can
     * rely on without exposing model values or numeric database identifiers.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function normalizeBroadcastPayload(string $eventName, array $payload): array
    {
        $timestamp = self::stringValue($payload['timestamp'] ?? null)
            ?? self::stringValue($payload['occurred_at'] ?? null)
            ?? now()->toISOString();
        $related = self::normalizeRelated($payload['related'] ?? []);
        if ($related === []) {
            $related = self::normalizeRelated($payload['related_entities'] ?? []);
        }

        if (array_key_exists('hash_id', $payload)) {
            $payload['hash_id'] = self::opaqueString($payload['hash_id']);
        }
        if (array_key_exists('actor_hash_id', $payload)) {
            $payload['actor_hash_id'] = self::opaqueString($payload['actor_hash_id']);
        }
        if (array_key_exists('entity_ids', $payload)) {
            $payload['entity_ids'] = self::normalizeOpaqueIds($payload['entity_ids']);
        }
        if (array_key_exists('related_entities', $payload)) {
            $payload['related_entities'] = self::normalizeLegacyRelatedEntities($payload['related_entities']);
        }

        return array_replace($payload, [
            'refresh' => true,
            'event_id' => self::stringValue($payload['event_id'] ?? null) ?? (string) Str::uuid(),
            'entity_type' => self::stringValue($payload['entity_type'] ?? null)
                ?? self::entityTypeFromBroadcastName($eventName),
            'entity_id' => self::opaqueString($payload['entity_id'] ?? null)
                ?? self::opaqueString($payload['hash_id'] ?? null),
            'action' => self::stringValue($payload['action'] ?? null) ?? 'updated',
            'version' => self::stringValue($payload['version'] ?? null)
                ?? self::stringValue($payload['updated_at'] ?? null)
                ?? $timestamp,
            'actor_id' => self::opaqueString($payload['actor_id'] ?? null)
                ?? self::opaqueString($payload['actor_hash_id'] ?? null),
            'related' => $related,
            'timestamp' => $timestamp,
        ]);
    }

    /**
     * @param  list<array{entity_type: string, hash_id: string}>  $related
     * @return array{
     *     refresh: true,
     *     event_id: string,
     *     entity_type: string,
     *     entity_id: ?string,
     *     action: string,
     *     version: string,
     *     actor_id: ?string,
     *     related: list<array{entity_type: string, hash_id: string}>,
     *     timestamp: string
     * }
     */
    public static function refresh(
        string $eventId,
        string $entityType,
        ?string $entityId,
        string $action,
        string $version,
        ?string $actorId,
        string $timestamp,
        array $related = [],
    ): array {
        return [
            'refresh' => true,
            'event_id' => $eventId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'version' => $version,
            'actor_id' => $actorId,
            'related' => $related,
            'timestamp' => $timestamp,
        ];
    }

    private static function entityTypeFromBroadcastName(string $eventName): string
    {
        $name = str_contains($eventName, '\\')
            ? class_basename($eventName)
            : $eventName;
        $normalized = Str::snake(str_replace(['.', '-'], '_', $name));
        $normalized = preg_replace('/_(?:changed|created|updated|deleted|completed|confirmed|submitted|decided|pruned|generated|requested|refresh)$/', '', $normalized) ?? $normalized;

        return $normalized !== '' ? $normalized : 'realtime_change';
    }

    /** @return list<array{entity_type: string, hash_id: string}> */
    private static function normalizeRelated(mixed $related): array
    {
        if (! is_array($related)) {
            return [];
        }

        $normalized = [];
        foreach ($related as $reference) {
            if (! is_array($reference)) {
                continue;
            }

            $entityType = self::stringValue($reference['entity_type'] ?? $reference['type'] ?? null);
            $hashId = self::opaqueString(
                $reference['hash_id'] ?? $reference['entity_id'] ?? null,
            );
            if ($entityType === null || $hashId === null) {
                continue;
            }

            $normalized[] = ['entity_type' => $entityType, 'hash_id' => $hashId];
        }

        return $normalized;
    }

    /** @return list<array{entity_type: string, entity_id: string}> */
    private static function normalizeLegacyRelatedEntities(mixed $related): array
    {
        return array_map(
            static fn (array $reference): array => [
                'entity_type' => $reference['entity_type'],
                'entity_id' => $reference['hash_id'],
            ],
            self::normalizeRelated($related),
        );
    }

    /** @return list<string> */
    private static function normalizeOpaqueIds(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        $normalized = [];
        foreach ($ids as $id) {
            $opaqueId = self::opaqueString($id);
            if ($opaqueId !== null) {
                $normalized[] = $opaqueId;
            }
        }

        return $normalized;
    }

    private static function opaqueString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' && ! ctype_digit($value) ? $value : null;
    }

    private static function stringValue(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_string($value)) {
            return trim($value) !== '' ? trim($value) : null;
        }

        return is_int($value) || is_float($value) ? (string) $value : null;
    }
}
