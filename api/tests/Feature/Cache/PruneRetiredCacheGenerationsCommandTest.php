<?php

declare(strict_types=1);

namespace Tests\Feature\Cache;

use App\Common\Services\RetiredGenerationCacheKeyReader;
use App\Common\Support\RetiredCacheGenerationKeys;
use Tests\TestCase;

class PruneRetiredCacheGenerationsCommandTest extends TestCase
{
    public const ACTIVE = '123e4567-e89b-12d3-a456-426614174000';

    public const RETIRED = '223e4567-e89b-12d3-a456-426614174001';

    public function test_dry_run_reports_retired_generation_payloads_without_deleting_them(): void
    {
        $reader = new FakeRetiredGenerationCacheKeyReader(self::ACTIVE);
        $this->app->instance(RetiredGenerationCacheKeyReader::class, $reader);

        $this->artisan('cache:prune-retired-generations')
            ->expectsOutputToContain('Retired-generation entries found: 1')
            ->expectsOutputToContain('Entries deleted: 0 (dry run)')
            ->doesntExpectOutputToContain(self::RETIRED)
            ->doesntExpectOutputToContain('dash:an:metric')
            ->assertExitCode(0);

        self::assertTrue($reader->has('dash:an:metric:v'.self::RETIRED.'.'.self::ACTIVE.':company'));
    }

    public function test_apply_deletes_only_ttl_bearing_payloads_with_a_retired_generation(): void
    {
        $reader = new FakeRetiredGenerationCacheKeyReader(self::ACTIVE);
        $this->app->instance(RetiredGenerationCacheKeyReader::class, $reader);

        $this->artisan('cache:prune-retired-generations --apply --limit=10')
            ->expectsOutputToContain('Retired-generation entries found: 1')
            ->expectsOutputToContain('Entries deleted: 1')
            ->assertExitCode(0);

        self::assertFalse($reader->has('dash:an:metric:v'.self::RETIRED.'.'.self::ACTIVE.':company'));
        self::assertTrue($reader->has('settings:example:v'.self::ACTIVE));
        self::assertTrue($reader->has('legacy:unbounded:v'.self::RETIRED));
        self::assertTrue($reader->has('record:'.self::RETIRED.':detail'));
    }

    public function test_apply_refuses_a_limit_outside_the_safe_range(): void
    {
        $reader = new FakeRetiredGenerationCacheKeyReader(self::ACTIVE);
        $this->app->instance(RetiredGenerationCacheKeyReader::class, $reader);

        $this->artisan('cache:prune-retired-generations --apply --limit=0')
            ->expectsOutputToContain('--limit must be between 1 and 500.')
            ->assertExitCode(1);

        self::assertTrue($reader->has('dash:an:metric:v'.self::RETIRED.'.'.self::ACTIVE.':company'));
    }
}

final class FakeRetiredGenerationCacheKeyReader implements RetiredGenerationCacheKeyReader
{
    /** @var array<string, array{store:string,key:string,ttl:int,bytes:?int}> */
    private array $entries;

    public function __construct(private readonly string $activeToken)
    {
        $this->entries = [];
        foreach ([
            ['redis', 'dash:an:metric:v'.PruneRetiredCacheGenerationsCommandTest::RETIRED.'.'.$activeToken.':company', 60],
            ['redis', 'settings:example:v'.$activeToken, 300],
            ['redis', 'legacy:unbounded:v'.PruneRetiredCacheGenerationsCommandTest::RETIRED, -1],
            ['redis', 'record:'.PruneRetiredCacheGenerationsCommandTest::RETIRED.':detail', 60],
            ['generations', 'cache:generation:v'.PruneRetiredCacheGenerationsCommandTest::RETIRED, 60],
        ] as [$store, $key, $ttl]) {
            $this->entries[$key] = ['store' => $store, 'key' => $key, 'ttl' => $ttl, 'bytes' => 32];
        }
    }

    public function scan(): iterable
    {
        yield from array_values($this->entries);
    }

    public function deleteIfUnbounded(string $key, string $store = 'redis'): bool
    {
        return false;
    }

    public function currentGenerationTokens(): array
    {
        return [$this->activeToken];
    }

    public function deleteIfRetiredGeneration(string $key, array $retiredTokens): bool
    {
        if (! isset($this->entries[$key]) || $this->entries[$key]['store'] !== 'redis' || $this->entries[$key]['ttl'] <= 0) {
            return false;
        }
        if (array_intersect(RetiredCacheGenerationKeys::fromCacheKey($key), $retiredTokens) === []) {
            return false;
        }

        unset($this->entries[$key]);

        return true;
    }

    public function has(string $key): bool
    {
        return isset($this->entries[$key]);
    }
}
