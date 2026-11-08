<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Support\CacheGeneration;
use App\Common\Support\FailOpenCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Versioned cache for stock-derived dashboard data. */
class InventoryDashboardCache
{
    private const VERSION_KEY = 'inv:dashboard:summary.version';

    private const VERSION_TTL_SECONDS = 604800;

    private const SUMMARY_TTL_SECONDS = 30;

    public function remember(callable $compute): mixed
    {
        return FailOpenCache::remember(
            Cache::store(),
            $this->summaryKey($this->version()),
            self::SUMMARY_TTL_SECONDS,
            $compute,
            'inventory.dashboard.summary',
        );
    }

    /** Rotate the generation after commit, preventing a concurrent stale read from repopulating it. */
    public function invalidateAfterCommit(): string
    {
        $nextVersion = (string) Str::uuid();
        DB::afterCommit(static function () use ($nextVersion): void {
            CacheGeneration::rotateDefault(
                self::VERSION_KEY,
                self::VERSION_TTL_SECONDS,
                'inventory.dashboard.summary',
                $nextVersion,
            );
        });

        return $nextVersion;
    }

    /** Retire inventory summaries after the source database has been replaced. */
    public function invalidateForDatabaseRestore(): bool
    {
        return CacheGeneration::rotateDefault(
            self::VERSION_KEY,
            self::VERSION_TTL_SECONDS,
            'inventory.dashboard.database_restore',
        );
    }

    public function version(): string
    {
        return CacheGeneration::currentDefault(
            self::VERSION_KEY,
            self::VERSION_TTL_SECONDS,
            'inventory.dashboard.summary',
        );
    }

    private function summaryKey(string $version): string
    {
        return "inv:dashboard:summary:v{$version}";
    }
}
