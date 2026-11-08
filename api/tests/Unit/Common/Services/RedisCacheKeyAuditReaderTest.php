<?php

declare(strict_types=1);

namespace Tests\Unit\Common\Services;

use App\Common\Services\RedisCacheKeyAuditReader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class RedisCacheKeyAuditReaderTest extends TestCase
{
    public function test_it_scans_generation_store_keys_without_reading_values(): void
    {
        config(['cache.stores.generations.driver' => 'redis']);

        $store = Cache::store('generations');
        $reader = app(RedisCacheKeyAuditReader::class);
        $key = 'test:cache-generation-audit:'.Str::uuid();

        try {
            $store->put($key, 'opaque-generation-value', now()->addMinutes(5));

            $entry = collect($reader->scan())->first(
                static fn (array $entry): bool => $entry['store'] === 'generations' && $entry['key'] === $key,
            );

            $this->assertNotNull($entry);
            $this->assertGreaterThan(0, $entry['ttl']);
            $this->assertGreaterThan(0, $entry['bytes']);
            $this->assertArrayNotHasKey('value', $entry);
        } finally {
            $store->forget($key);
        }
    }

    public function test_it_atomically_deletes_only_unbounded_keys_in_the_cache_store_namespace(): void
    {
        $store = Cache::store('redis');
        $reader = app(RedisCacheKeyAuditReader::class);
        $unboundedKey = 'test:cache-prune:'.Str::uuid();
        $boundedKey = 'test:cache-prune:'.Str::uuid();

        try {
            $store->forever($unboundedKey, 'opaque-test-value');
            $store->put($boundedKey, 'opaque-test-value', now()->addMinutes(5));

            $this->assertTrue($reader->deleteIfUnbounded($unboundedKey));
            $this->assertFalse($store->has($unboundedKey));
            $this->assertFalse($reader->deleteIfUnbounded($boundedKey));
            $this->assertTrue($store->has($boundedKey));
        } finally {
            $store->forget($unboundedKey);
            $store->forget($boundedKey);
        }
    }

    public function test_it_reads_active_generation_tokens_and_deletes_only_retired_bounded_payloads(): void
    {
        config(['cache.stores.generations.driver' => 'redis']);

        $payloads = Cache::store('redis');
        $markers = Cache::store('generations');
        $reader = app(RedisCacheKeyAuditReader::class);
        $markerKey = 'test:retired-generation-marker:'.Str::uuid();
        $activeToken = (string) Str::uuid();
        $retiredToken = (string) Str::uuid();
        $retiredKey = 'settings:test:retired-generation:v'.$retiredToken;
        $activeKey = 'settings:test:retired-generation:v'.$activeToken;
        $unboundedKey = 'settings:test:retired-generation:v'.$retiredToken.':unbounded';

        try {
            $markers->put($markerKey, $activeToken, now()->addMinutes(5));
            $payloads->put($retiredKey, 'retired-snapshot', now()->addMinutes(5));
            $payloads->put($activeKey, 'current-snapshot', now()->addMinutes(5));
            $payloads->forever($unboundedKey, 'unbounded-test-value');

            self::assertContains($activeToken, $reader->currentGenerationTokens());
            self::assertTrue($reader->deleteIfRetiredGeneration($retiredKey, [$retiredToken]));
            self::assertFalse($reader->deleteIfRetiredGeneration($activeKey, [$retiredToken]));
            self::assertFalse($reader->deleteIfRetiredGeneration($unboundedKey, [$retiredToken]));
            self::assertFalse($payloads->has($retiredKey));
            self::assertTrue($payloads->has($activeKey));
            self::assertTrue($payloads->has($unboundedKey));
        } finally {
            $markers->forget($markerKey);
            $payloads->forget($retiredKey);
            $payloads->forget($activeKey);
            $payloads->forget($unboundedKey);
        }
    }
}
