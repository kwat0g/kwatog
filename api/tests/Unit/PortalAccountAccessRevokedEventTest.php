<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Services\OutboxEventCodec;
use App\Modules\B2B\Events\PortalAccountAccessRevoked;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use InvalidArgumentException;
use Tests\TestCase;

final class PortalAccountAccessRevokedEventTest extends TestCase
{
    public function test_revocation_is_private_after_commit_and_contains_no_identity_data(): void
    {
        foreach (['customer', 'supplier'] as $realm) {
            $event = new PortalAccountAccessRevoked(
                $realm,
                'opaque-user-hash',
                'event-1',
                '2026-11-01T00:00:00Z',
            );

            $this->assertInstanceOf(ShouldBroadcast::class, $event);
            $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
            $this->assertInstanceOf(PrivateChannel::class, $event->broadcastOn());
            $this->assertSame(
                "private-portal.{$realm}.user.opaque-user-hash",
                $event->broadcastOn()->name,
            );
            $this->assertSame('b2b.portal-access.revoked', $event->broadcastAs());
            $this->assertSame([
                'refresh' => true,
                'event_id' => 'event-1',
                'entity_type' => 'b2b_portal_access',
                'timestamp' => '2026-11-01T00:00:00Z',
            ], $event->broadcastWith());

            $codec = app(OutboxEventCodec::class);
            $encoded = $codec->encode($event);
            $decoded = $codec->decode($encoded['event_type'], $encoded['payload']);
            $this->assertSame($event->broadcastWith(), $decoded->broadcastWith());
        }
    }

    public function test_revocation_rejects_a_non_portal_realm(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PortalAccountAccessRevoked('internal', 'opaque-user-hash');
    }
}
