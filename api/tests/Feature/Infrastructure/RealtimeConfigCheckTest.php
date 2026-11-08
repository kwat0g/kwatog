<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Tests\TestCase;

class RealtimeConfigCheckTest extends TestCase
{
    public function test_it_fails_when_cached_broadcast_connection_differs_from_environment(): void
    {
        config()->set('broadcasting.default', 'null');

        $this->artisan('realtime:config-check')
            ->expectsOutputToContain('BROADCAST_CONNECTION')
            ->assertExitCode(1);
    }
}
