<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Enums\PermissionOverrideType;
use App\Common\Events\PermissionOverrideChanged;
use Illuminate\Broadcasting\PrivateChannel;
use Tests\TestCase;

class PermissionOverrideChangedTest extends TestCase
{
    public function test_broadcast_contract_matches_the_spa_listener_without_raw_user_id(): void
    {
        $event = new PermissionOverrideChanged(
            42,
            'hr.employees.view',
            PermissionOverrideType::Revoke,
            PermissionOverrideType::Grant,
            'Temporary coverage.',
        );

        $this->assertSame('permission.override.changed', $event->broadcastAs());
        $hashId = app('hashids')->encode(42);
        $this->assertSame([
            "private-user.{$hashId}",
            "private-user.access.{$hashId}",
            "private-admin.user.{$hashId}",
        ], array_map(
            static fn (PrivateChannel $channel): string => $channel->name,
            $event->broadcastOn(),
        ));
        $this->assertSame(['refresh' => true, 'event_id' => $event->eventId], $event->broadcastWith());
        $this->assertArrayNotHasKey('user_id', $event->broadcastWith());
        $this->assertArrayNotHasKey('permission_slug', $event->broadcastWith());
        $this->assertArrayNotHasKey('reason', $event->broadcastWith());
    }
}
