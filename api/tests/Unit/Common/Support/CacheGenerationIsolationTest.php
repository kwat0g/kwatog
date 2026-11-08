<?php

declare(strict_types=1);

namespace Tests\Unit\Common\Support;

use App\Common\Support\CacheGeneration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class CacheGenerationIsolationTest extends TestCase
{
    public function test_default_generation_markers_are_stored_outside_the_payload_cache(): void
    {
        config(['cache.stores.generations.driver' => 'array']);

        $key = 'cache-generation:isolation:'.Str::uuid();
        $payloadCache = Cache::store('array');
        $payloadCache->put($key, 'old-payload-marker', 60);

        try {
            $generation = CacheGeneration::currentDefault($key, 60, 'test.isolation');

            self::assertNotSame('old-payload-marker', $generation);
            self::assertSame('old-payload-marker', $payloadCache->get($key));
            self::assertTrue(CacheGeneration::rotateDefault($key, 60, 'test.isolation', 'new-generation'));
            self::assertSame('new-generation', CacheGeneration::currentDefault($key, 60, 'test.isolation'));
            self::assertSame('old-payload-marker', $payloadCache->get($key));
        } finally {
            Cache::store('generations')->forget($key);
            $payloadCache->forget($key);
        }
    }

    public function test_generation_store_is_a_distinct_database_on_non_evicting_redis(): void
    {
        self::assertSame('generations', config('cache.stores.generations.connection'));
        self::assertSame('4', (string) config('database.redis.generations.database'));
        self::assertSame('array', config('cache.stores.generations.driver'));
    }
}
