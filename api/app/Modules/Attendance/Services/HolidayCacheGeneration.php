<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Common\Support\CacheGeneration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Rotates holiday snapshots before an after-commit realtime refetch can run. */
final class HolidayCacheGeneration
{
    private const VERSION_KEY = 'holidays:cache_version';

    private const VERSION_TTL_SECONDS = 604800;

    public static function current(): string
    {
        return CacheGeneration::currentDefault(
            self::VERSION_KEY,
            self::VERSION_TTL_SECONDS,
            'attendance.holidays',
        );
    }

    public function invalidateAfterCommit(int $year): void
    {
        DB::afterCommit(static function () use ($year): void {
            CacheGeneration::rotateDefault(
                self::VERSION_KEY,
                self::VERSION_TTL_SECONDS,
                'attendance.holidays.'.$year,
            );

            try {
                // The marker is authoritative for new reads. Cleanup is
                // best-effort and must not prevent that generation rotation.
                Cache::forget("holidays:{$year}");
                Cache::tags(['attendance-holidays'])->flush();
            } catch (Throwable $exception) {
                // Cache failure must not prevent the later after-commit outbox
                // callback from delivering the realtime refresh signal.
                Log::warning('Holiday legacy snapshot cleanup failed after commit.', [
                    'year' => $year,
                    'exception' => $exception,
                ]);
            }
        });
    }

    /** Retire every year snapshot after a database restore or rollback. */
    public function invalidateForDatabaseRestore(): bool
    {
        $rotated = CacheGeneration::rotateDefault(
            self::VERSION_KEY,
            self::VERSION_TTL_SECONDS,
            'attendance.holidays.database_restore',
        );

        try {
            Cache::tags(['attendance-holidays'])->flush();
        } catch (Throwable $exception) {
            // New reads use the new (or reinitialized) namespace; old entries
            // remain bounded by their TTL if tag cleanup is unavailable.
            Log::warning('Holiday tagged cache cleanup failed after database restore.', [
                'exception' => $exception::class,
            ]);
        }

        return $rotated;
    }
}
