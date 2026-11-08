<?php

declare(strict_types=1);

namespace Tests\Feature\Cache;

use App\Common\Support\RetiredCacheGenerationKeys;
use PHPUnit\Framework\TestCase;

class RetiredCacheGenerationKeysTest extends TestCase
{
    public function test_it_extracts_only_uuid_generation_segments_from_cache_keys(): void
    {
        $global = '123e4567-e89b-12d3-a456-426614174000';
        $metric = '223e4567-e89b-12d3-a456-426614174001';
        $scope = '323e4567-e89b-12d3-a456-426614174002';

        self::assertSame(
            [$global, $metric],
            RetiredCacheGenerationKeys::fromCacheKey("dash:an:returns:v{$global}.{$metric}:company:filters"),
        );
        self::assertSame(
            [$scope, $global],
            RetiredCacheGenerationKeys::fromCacheKey("auth:role_perms:g{$scope}:r7:v{$global}"),
        );
        self::assertSame(
            [$global, $metric],
            RetiredCacheGenerationKeys::fromCacheKey("dashboard:widget:rich:v3:{$global}.{$metric}:user:7"),
        );
        self::assertSame(
            [$scope],
            RetiredCacheGenerationKeys::fromCacheKey("finance_dashboard:summary:v4:{$scope}:panels"),
        );
        self::assertSame(
            [$global, $metric],
            RetiredCacheGenerationKeys::fromCacheKey("badges.user.7.v{$global}.s{$metric}"),
        );
        self::assertSame(
            [$scope],
            RetiredCacheGenerationKeys::fromCacheKey("holidays:2026:{$scope}"),
        );
        self::assertSame([], RetiredCacheGenerationKeys::fromCacheKey("purchase-orders:{$global}:detail"));
        self::assertSame([], RetiredCacheGenerationKeys::fromCacheKey("supplier:catalog:v{$global}"));
        self::assertSame([], RetiredCacheGenerationKeys::fromCacheKey('dashboard:widget:v3:plant'));
    }
}
