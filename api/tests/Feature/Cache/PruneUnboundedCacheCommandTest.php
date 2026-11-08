<?php

declare(strict_types=1);

namespace Tests\Feature\Cache;

use App\Common\Services\CacheKeyAuditReader;
use Tests\TestCase;

class PruneUnboundedCacheCommandTest extends TestCase
{
    public function test_dry_run_reports_unbounded_entries_without_deleting_them(): void
    {
        $reader = new FakePrunableCacheReader([
            'legacy:orphan' => -1,
            'dashboard:current' => 60,
        ]);
        $this->app->instance(CacheKeyAuditReader::class, $reader);

        $this->artisan('cache:prune-unbounded')
            ->expectsOutputToContain('Unbounded entries found: 1')
            ->expectsOutputToContain('Entries deleted: 0 (dry run)')
            ->assertExitCode(0);

        $this->assertSame(-1, $reader->ttl('legacy:orphan'));
    }

    public function test_apply_deletes_only_keys_that_still_have_no_ttl(): void
    {
        $reader = new FakePrunableCacheReader([
            'legacy:orphan' => -1,
            'illuminate:queue:restart' => -1,
            'dashboard:current' => 60,
            'race:became-bounded' => -1,
        ]);
        // Simulate a writer setting an expiry between SCAN and the atomic Redis check.
        $reader->setTtlBeforeDelete('race:became-bounded', 300);
        $this->app->instance(CacheKeyAuditReader::class, $reader);

        $this->artisan('cache:prune-unbounded --apply --limit=10')
            ->expectsOutputToContain('Unbounded entries found: 2')
            ->expectsOutputToContain('Entries deleted: 1')
            ->assertExitCode(0);

        $this->assertNull($reader->ttl('legacy:orphan'));
        $this->assertSame(-1, $reader->ttl('illuminate:queue:restart'));
        $this->assertSame(60, $reader->ttl('dashboard:current'));
        $this->assertSame(300, $reader->ttl('race:became-bounded'));
    }

    public function test_apply_refuses_unbounded_delete_limits_outside_safe_range(): void
    {
        $reader = new FakePrunableCacheReader(['legacy:orphan' => -1]);
        $this->app->instance(CacheKeyAuditReader::class, $reader);

        $this->artisan('cache:prune-unbounded --apply --limit=0')
            ->expectsOutputToContain('--limit must be between 1 and 500.')
            ->assertExitCode(1);

        $this->assertSame(-1, $reader->ttl('legacy:orphan'));
    }
}

final class FakePrunableCacheReader implements CacheKeyAuditReader
{
    /** @var array<string, int> */
    private array $scanSnapshot;

    /** @param array<string, int> $keys */
    public function __construct(private array $keys)
    {
        $this->scanSnapshot = $keys;
    }

    /** @param array<string, int> $keys */
    public function scan(): iterable
    {
        foreach ($this->scanSnapshot as $key => $ttl) {
            yield ['store' => 'redis', 'key' => $key, 'ttl' => $ttl, 'bytes' => 16];
        }
    }

    public function deleteIfUnbounded(string $key, string $store = 'redis'): bool
    {
        if (($this->keys[$key] ?? null) !== -1) {
            return false;
        }

        unset($this->keys[$key]);

        return true;
    }

    public function setTtlBeforeDelete(string $key, int $ttl): void
    {
        $this->keys[$key] = $ttl;
    }

    public function ttl(string $key): ?int
    {
        return $this->keys[$key] ?? null;
    }
}
