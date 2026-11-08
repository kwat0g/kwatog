<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Common\Support\CacheGeneration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Shared invalidation generation for cached scalar and rich dashboard widgets. */
final class DashboardCacheGeneration
{
    private const VERSION_KEY = 'dashboard:cache:version';

    private const SCOPED_VERSION_PREFIX = 'dashboard:cache:scope:';

    private const VERSION_TTL_SECONDS = 604800;

    public static function current(string $scope = 'global'): string
    {
        $global = CacheGeneration::currentDefault(
            self::VERSION_KEY,
            self::VERSION_TTL_SECONDS,
            'dashboard.global',
        );

        if ($scope === 'global') {
            return $global;
        }

        return $global.'.'.self::scopedVersion($scope);
    }

    /** Retire one generation after commit; the default rotates every dashboard family. */
    public static function touch(string $scope = 'global'): ?bool
    {
        $version = (string) Str::uuid();
        $key = $scope === 'global' ? self::VERSION_KEY : self::scopedVersionKey($scope);
        $rotated = null;

        DB::afterCommit(static function () use ($key, $version, &$rotated): void {
            // A failed write retires the old marker so future readers use a
            // fresh namespace; cache trouble never blocks the realtime ping.
            $rotated = CacheGeneration::rotateDefault(
                $key,
                self::VERSION_TTL_SECONDS,
                'dashboard.generation',
                $version,
            );
        });

        // Widget responses are cached separately from their source records.
        // Stage one coalesced ping here so every generation writer reaches
        // authorized dashboard viewers, even when they lack a record channel.
        if ($scope === 'global'
            || str_starts_with($scope, 'widget-summary:')
            || str_starts_with($scope, 'widget-rich:')) {
            app(DashboardWidgetDataRealtimeService::class)->stage();
        }

        return $rotated;
    }

    /** Rotate the shared dashboard namespace after a database snapshot is replaced. */
    public static function invalidateForDatabaseRestore(): bool
    {
        return CacheGeneration::rotateDefault(
            self::VERSION_KEY,
            self::VERSION_TTL_SECONDS,
            'dashboard.database_restore',
        );
    }

    /** Resolve the scoped marker, initializing it atomically if it is missing. */
    public static function scopedVersion(string $scope): string
    {
        $key = self::scopedVersionKey($scope);

        return CacheGeneration::currentDefault(
            $key,
            self::VERSION_TTL_SECONDS,
            'dashboard.scope',
        );
    }

    private static function scopedVersionKey(string $scope): string
    {
        return self::SCOPED_VERSION_PREFIX.hash('sha256', $scope);
    }
}
