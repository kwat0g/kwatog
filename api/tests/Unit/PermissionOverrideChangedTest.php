<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Enums\PermissionOverrideType;
use App\Common\Events\PermissionOverrideChanged;
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
        $this->assertSame([
            'permission_slug' => 'hr.employees.view',
            'old_type' => 'revoke',
            'new_type' => 'grant',
            'reason' => 'Temporary coverage.',
        ], $event->broadcastWith());
        $this->assertArrayNotHasKey('user_id', $event->broadcastWith());
    }
}
