<?php

declare(strict_types=1);

namespace Tests\Unit\Common\Support;

use App\Common\Support\FailOpenCache;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class FailOpenCacheTest extends TestCase
{
    public function test_null_ttl_is_rejected_instead_of_creating_a_permanent_cache_entry(): void
    {
        $resolved = false;

        $exception = null;
        try {
            FailOpenCache::remember(
                Cache::store('array'),
                'fail-open-cache:null-ttl',
                null,
                static function () use (&$resolved): string {
                    $resolved = true;

                    return 'value';
                },
                'test.null_ttl',
            );
        } catch (\TypeError $caught) {
            $exception = $caught;
        }

        self::assertInstanceOf(\TypeError::class, $exception);
        self::assertFalse($resolved, 'The resolver must not run when the TTL contract is invalid.');
    }
}
