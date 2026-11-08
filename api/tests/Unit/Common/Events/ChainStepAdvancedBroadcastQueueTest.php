<?php

declare(strict_types=1);

namespace Tests\Unit\Common\Events;

use App\Common\Events\ChainStepAdvanced;
use PHPUnit\Framework\TestCase;

class ChainStepAdvancedBroadcastQueueTest extends TestCase
{
    public function test_chain_refresh_broadcasts_use_the_dedicated_redis_lane(): void
    {
        $event = new ChainStepAdvanced(
            entityType: 'purchase_request',
            entityHashId: 'opaque-id',
            docNumber: 'PR-TEST',
            newStatus: 'approved',
            activeStep: 'complete',
            completedSteps: ['request'],
        );

        self::assertSame('broadcasts', $event->broadcastQueue());
        self::assertSame('redis_broadcasts', $event->broadcastConnection());
        self::assertSame(
            ['private-chain.purchase_request.opaque-id', 'private-entity.purchase_request.visible'],
            array_map(static fn ($channel) => $channel->name, $event->broadcastOn()),
        );
        $payload = $event->broadcastWith();
        self::assertSame(true, $payload['refresh']);
        self::assertArrayHasKey('event_id', $payload);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            (string) $payload['event_id'],
        );
    }

    public function test_chain_refresh_wire_includes_ordering_metadata_without_domain_details(): void
    {
        $event = new ChainStepAdvanced(
            entityType: 'work_order',
            entityHashId: 'opaque-work-order-id',
            docNumber: 'WO-PRIVATE-42',
            newStatus: 'in_progress',
            activeStep: 'production',
            completedSteps: ['confirmed'],
            actorName: 'Internal User',
            eventId: 'chain-envelope-event',
        );

        $payload = $event->broadcastWith();
        self::assertArrayHasKey('action', $payload);
        self::assertArrayHasKey('version', $payload);
        self::assertArrayHasKey('actor_id', $payload);
        self::assertArrayHasKey('related', $payload);
        self::assertArrayHasKey('timestamp', $payload);
        self::assertSame('status_changed', $payload['action']);
        self::assertSame('chain-envelope-event', $payload['event_id']);
        self::assertSame('work_order', $payload['entity_type']);
        self::assertSame('opaque-work-order-id', $payload['entity_id']);
        self::assertNull($payload['actor_id']);
        self::assertSame([], $payload['related']);
        self::assertMatchesRegularExpression('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d+)?Z$/', $payload['version']);
        self::assertArrayNotHasKey('docNumber', $payload);
        self::assertArrayNotHasKey('newStatus', $payload);
        self::assertArrayNotHasKey('activeStep', $payload);
        self::assertArrayNotHasKey('completedSteps', $payload);
        self::assertArrayNotHasKey('actorName', $payload);
    }

    public function test_grn_chain_refresh_also_targets_the_authorized_finance_dashboard(): void
    {
        $event = new ChainStepAdvanced(
            entityType: 'grn',
            entityHashId: 'opaque-grn-id',
            docNumber: 'GRN-TEST',
            newStatus: 'accepted',
            activeStep: 'bill',
            completedSteps: ['receipt', 'quality'],
        );

        self::assertSame(
            [
                'private-chain.grn.opaque-grn-id',
                'private-entity.grn.visible',
                'private-dashboard.finance',
            ],
            array_map(static fn ($channel) => $channel->name, $event->broadcastOn()),
        );
        $payload = $event->broadcastWith();
        self::assertSame([
            'refresh' => true,
            'event_id' => $event->eventId,
            'entity_type' => 'grn',
            'entity_id' => 'opaque-grn-id',
            'action' => 'status_changed',
            'version' => $event->timestamp,
            'actor_id' => null,
            'related' => [],
            'timestamp' => $event->timestamp,
        ], $payload);
    }

    public function test_delivery_chain_refresh_targets_the_dedicated_driver_channel_and_legacy_channel(): void
    {
        $event = new ChainStepAdvanced(
            entityType: 'delivery',
            entityHashId: 'opaque-delivery-id',
            docNumber: 'DR-TEST',
            newStatus: 'in_transit',
            activeStep: 'delivery',
            completedSteps: ['dispatch'],
            driverHashIds: ['driver-hash'],
        );

        $channels = array_map(static fn ($channel) => $channel->name, $event->broadcastOn());
        self::assertContains('private-driver.driver-hash', $channels);
        self::assertContains('private-user.driver-hash', $channels);
        self::assertArrayNotHasKey('driverHashIds', $event->broadcastWith());
    }

    public function test_source_refreshes_use_only_scopes_that_can_read_the_source_type(): void
    {
        foreach ([
            'delivery' => ['private-entity.return_case.customer', 'private-entity.return_case.all'],
            'purchase_order' => ['private-entity.return_case.all'],
        ] as $entityType => $expectedReturnCaseChannels) {
            $event = new ChainStepAdvanced(
                entityType: $entityType,
                entityHashId: 'source-hash',
                docNumber: 'SOURCE-TEST',
                newStatus: 'updated',
                activeStep: 'complete',
                completedSteps: [],
                action: 'updated',
            );
            $channels = array_map(static fn ($channel) => $channel->name, $event->broadcastOn());

            foreach ($expectedReturnCaseChannels as $expectedChannel) {
                self::assertContains($expectedChannel, $channels);
            }
            if ($entityType === 'purchase_order') {
                self::assertNotContains('private-entity.return_case.customer', $channels);
            }
            self::assertArrayNotHasKey('docNumber', $event->broadcastWith());
        }
    }
}
