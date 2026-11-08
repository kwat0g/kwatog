<?php

declare(strict_types=1);

namespace Tests\Unit\Common\Events;

use App\Modules\Dashboard\Events\BadgesChanged;
use PHPUnit\Framework\TestCase;

class BadgesChangedBroadcastQueueTest extends TestCase
{
    public function test_badge_refresh_pings_use_the_dedicated_broadcast_lane(): void
    {
        $event = new BadgesChanged;

        self::assertSame('broadcasts', $event->broadcastQueue());
        self::assertSame('redis_broadcasts', $event->broadcastConnection());
    }
}
