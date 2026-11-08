<?php

declare(strict_types=1);

namespace Tests\Feature\Cache;

use App\Common\Services\CacheKeyAuditReader;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CacheAuditCommandTest extends TestCase
{
    public function test_cache_audit_reports_ttl_size_and_key_families_without_values(): void
    {
        config(['cache.stores.redis' => ['driver' => 'array', 'serialize' => false]]);
        Cache::purge('redis');
        $auditStore = Cache::store('redis');
        $auditStore->forget('ops:cache-audit:last:v1');

        $reader = new class implements CacheKeyAuditReader
        {
            public function scan(): iterable
            {
                yield ['store' => 'redis', 'key' => 'badges:user:42', 'ttl' => 15, 'bytes' => 80];
                yield ['store' => 'generations', 'key' => 'legacy:orphan', 'ttl' => -1, 'bytes' => 2048];
                yield ['store' => 'redis', 'key' => 'illuminate:queue:restart', 'ttl' => -1, 'bytes' => 64];
                yield ['store' => 'redis', 'key' => 'dashboard:plant:9', 'ttl' => 60, 'bytes' => 1200];
            }

            public function deleteIfUnbounded(string $key, string $store = 'redis'): bool
            {
                return false;
            }
        };
        $this->app->instance(CacheKeyAuditReader::class, $reader);

        $this->artisan('cache:audit --max-bytes=1024')
            ->expectsOutputToContain('Cache entries inspected: 4')
            ->expectsOutputToContain('Entries without TTL: 1')
            ->expectsOutputToContain('Persistent infrastructure entries without TTL: 1')
            ->expectsOutputToContain('Entries above 1024 bytes: 2')
            ->expectsOutputToContain('Key families: generations:legacy=1, redis:badges=1, redis:dashboard=1, redis:illuminate=1')
            ->expectsOutputToContain('generations:legacy:orphan')
            ->doesntExpectOutputToContain('illuminate:queue:restart')
            ->doesntExpectOutputToContain('cached value')
            ->assertExitCode(0);

        $summary = $auditStore->get('ops:cache-audit:last:v1');
        $this->assertSame(4, $summary['entries']);
        $this->assertSame(1, $summary['without_ttl']);
        $this->assertSame(1, $summary['persistent_infrastructure_without_ttl']);
        $this->assertSame(2, $summary['oversized']);
        $this->assertSame(0, $summary['unknown_size']);
    }

    public function test_cache_audit_refuses_an_invalid_size_threshold(): void
    {
        $this->app->instance(CacheKeyAuditReader::class, new class implements CacheKeyAuditReader
        {
            public function scan(): iterable
            {
                return [];
            }

            public function deleteIfUnbounded(string $key, string $store = 'redis'): bool
            {
                return false;
            }
        });

        $this->artisan('cache:audit --max-bytes=0')
            ->expectsOutputToContain('--max-bytes must be between 1 and 67108864.')
            ->assertExitCode(1);
    }
}
