<?php

declare(strict_types=1);

namespace Tests\Unit\Common\Events;

use App\Common\Broadcasting\StandardizedPusherBroadcaster;
use App\Common\Events\BroadcastEventEnvelope;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\BroadcastManager;
use Mockery;
use Pusher\Pusher;
use Tests\TestCase;

final class StandardizedPusherBroadcasterTest extends TestCase
{
    public function test_refresh_payloads_receive_the_shared_envelope_and_keep_legacy_fields(): void
    {
        $payload = [
            'refresh' => true,
            'event_id' => 'event-123',
            'hash_id' => 'work-order-hash',
            'action' => 'status_changed',
            'timestamp' => '2026-11-05T12:00:00.000000Z',
        ];

        $normalized = BroadcastEventEnvelope::normalizeBroadcastPayload('work_order.changed', $payload);

        $expectedEnvelope = [
            'refresh' => true,
            'event_id' => 'event-123',
            'entity_type' => 'work_order',
            'entity_id' => 'work-order-hash',
            'action' => 'status_changed',
            'version' => '2026-11-05T12:00:00.000000Z',
            'actor_id' => null,
            'related' => [],
            'timestamp' => '2026-11-05T12:00:00.000000Z',
        ];
        $actualEnvelope = array_intersect_key($normalized, array_flip([
            'refresh', 'event_id', 'entity_type', 'entity_id', 'action', 'version', 'actor_id', 'related', 'timestamp',
        ]));
        ksort($expectedEnvelope);
        ksort($actualEnvelope);
        $this->assertSame($expectedEnvelope, $actualEnvelope);
        $this->assertSame('work-order-hash', $normalized['hash_id']);
    }

    public function test_related_legacy_refs_are_normalized_without_copying_numeric_ids(): void
    {
        $normalized = BroadcastEventEnvelope::normalizeBroadcastPayload('goods_receipt_note.changed', [
            'refresh' => true,
            'event_id' => 'event-456',
            'entity_type' => 'grn',
            'hash_id' => 'grn-hash',
            'actor_hash_id' => 'actor-hash',
            'related_entities' => [
                ['entity_type' => 'purchase_order', 'entity_id' => 'po-hash'],
                ['entity_type' => 'item', 'entity_id' => 42],
            ],
            'occurred_at' => '2026-11-05T12:01:00.000000Z',
        ]);

        $this->assertSame('grn', $normalized['entity_type']);
        $this->assertSame('grn-hash', $normalized['entity_id']);
        $this->assertSame('actor-hash', $normalized['actor_id']);
        $this->assertSame([
            ['entity_type' => 'purchase_order', 'hash_id' => 'po-hash'],
        ], $normalized['related']);
        $this->assertSame([
            ['entity_type' => 'purchase_order', 'entity_id' => 'po-hash'],
        ], $normalized['related_entities']);
        $this->assertSame('2026-11-05T12:01:00.000000Z', $normalized['timestamp']);
        $this->assertSame($normalized['timestamp'], $normalized['version']);
    }

    public function test_entity_id_batches_keep_only_opaque_string_identifiers(): void
    {
        $normalized = BroadcastEventEnvelope::normalizeBroadcastPayload('asset.changed', [
            'entity_ids' => ['asset-hash-1', '12345', 678, '', 'asset-hash-2'],
        ]);

        $this->assertSame(['asset-hash-1', 'asset-hash-2'], $normalized['entity_ids']);
    }

    public function test_reverb_connection_uses_the_standardizing_transport(): void
    {
        config(['broadcasting.connections.reverb' => [
            'driver' => 'reverb',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'app_id' => 'test-app',
            'options' => ['host' => '127.0.0.1', 'port' => 8080, 'scheme' => 'http', 'useTLS' => false],
            'client_options' => [],
        ]]);

        $manager = app(BroadcastManager::class);
        $manager->forgetDrivers();

        $this->assertInstanceOf(StandardizedPusherBroadcaster::class, $manager->connection('reverb'));
    }

    public function test_transport_normalizes_payload_at_the_broadcast_boundary(): void
    {
        $pusher = Mockery::mock(Pusher::class);
        $pusher->shouldReceive('trigger')
            ->once()
            ->with(
                ['private-dashboard.finance'],
                'finance.dashboard.changed',
                Mockery::on(static fn (array $payload): bool => $payload['entity_type'] === 'finance_dashboard'
                    && $payload['entity_id'] === null
                    && $payload['actor_id'] === null
                    && $payload['related'] === []
                    && $payload['refresh'] === true
                    && $payload['version'] === $payload['timestamp']),
                [],
            )
            ->andReturn(new \stdClass);

        $broadcaster = new StandardizedPusherBroadcaster($pusher);
        $broadcaster->broadcast(['private-dashboard.finance'], 'finance.dashboard.changed', [
            'refresh' => true,
            'event_id' => 'finance-event',
            'timestamp' => '2026-11-05T12:02:00.000000Z',
        ]);

        $this->assertTrue(true);
    }

    public function test_transport_drops_fields_outside_the_refresh_payload_allowlist(): void
    {
        $pusher = Mockery::mock(Pusher::class);
        $pusher->shouldReceive('trigger')
            ->once()
            ->with(
                ['private-user.user-hash'],
                'notification.created',
                Mockery::on(static function (array $payload): bool {
                    $keys = array_keys($payload);
                    sort($keys);
                    $expectedKeys = [
                        'refresh', 'event_id', 'entity_type', 'entity_id', 'action', 'version', 'actor_id',
                        'related', 'timestamp', 'id', 'type',
                    ];
                    sort($expectedKeys);

                    return $keys === $expectedKeys
                        && $payload['event_id'] === 'notification-event'
                        && $payload['id'] === 'notification-hash'
                        && $payload['type'] === 'purchase_order_approved'
                        && $payload['refresh'] === true
                        && $payload['version'] === $payload['timestamp'];
                }),
                ['socket_id' => 'actor-socket'],
            )
            ->andReturn(new \stdClass);

        $broadcaster = new StandardizedPusherBroadcaster($pusher);
        $broadcaster->broadcast(['private-user.user-hash'], 'notification.created', [
            'event_id' => 'notification-event',
            'id' => 'notification-hash',
            'type' => 'purchase_order_approved',
            'customer_email' => 'private@example.test',
            'amount' => '125000.00',
            'status' => 'approved',
            'socket' => 'actor-socket',
        ]);

        $this->assertTrue(true);
    }

    public function test_transport_rejects_requests_over_the_configured_reverb_limit(): void
    {
        config([
            'reverb.servers.reverb.max_request_size' => 1300,
            'reverb.apps.apps.0.max_message_size' => 10_000,
        ]);
        $pusher = Mockery::mock(Pusher::class);
        $pusher->shouldNotReceive('trigger');
        $broadcaster = new StandardizedPusherBroadcaster($pusher, maxRequestSize: 1300);

        $this->expectException(BroadcastException::class);
        $broadcaster->broadcast(['private-entity.test.visible'], 'entity.changed', [
            'refresh' => true,
            'event_id' => 'oversized-event',
            'entity_id' => str_repeat('x', 512),
        ]);
    }
}
