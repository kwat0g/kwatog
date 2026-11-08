<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Tests\TestCase;

class RedisTopologyTest extends TestCase
{
    public function test_application_cache_uses_a_dedicated_redis_host_and_queue_keeps_its_own_connection(): void
    {
        $this->assertSame('cache', config('cache.stores.redis.connection'));
        $this->assertSame('default', config('queue.connections.redis.connection'));
        $this->assertNotSame(
            config('database.redis.default.host'),
            config('database.redis.cache.host'),
            'Queue and cache Redis connections must not share a Redis instance.',
        );
    }
}
