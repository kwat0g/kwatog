<?php

declare(strict_types=1);

namespace App\Modules\DSS\Services;

use App\Common\Support\CacheGeneration;
use App\Modules\DSS\Models\DssParameter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Per-detector (or `global`) tunables with detector-code defaults.
 *
 * Read path: detector default ← `global` row ← detector row (most specific
 * wins). Cached per (detector, key) with a generation marker so a late read
 * cannot restore an old value after a committed write. Writes also rely on
 * DssParameter::HasAuditLog for the who-changed-what trail (parameter
 * governance without a second history table).
 */
class DssParameterService
{
    public const GLOBAL = 'global';

    private const CACHE_TTL_SECONDS = 3600;

    private const VERSION_TTL_SECONDS = 604800;

    private const RESTORE_VERSION_KEY = 'dss:params:restore_version';

    /** Advisory-only detectors that are safe to surface without a DB override. */
    private const DEFAULT_DETECTOR_MODES = [
        'dss.inventory_stockout' => 'advisory',
    ];

    public function get(string $detectorId, string $key, mixed $default = null, bool $globalFallback = true): mixed
    {
        $version = CacheGeneration::currentDefault(
            $this->versionKey($detectorId, $key),
            self::VERSION_TTL_SECONDS,
            'dss.parameters',
        );
        $restoreVersion = CacheGeneration::currentDefault(
            self::RESTORE_VERSION_KEY,
            self::VERSION_TTL_SECONDS,
            'dss.parameters.restore',
        );
        $cacheKey = $this->cacheKey($detectorId, $key, $restoreVersion.'.'.$version);
        $miss = new \stdClass;

        try {
            $cached = Cache::get($cacheKey, $miss);
        } catch (Throwable $exception) {
            Log::warning('DSS parameter cache read failed; resolving from the source table.', [
                'scope_hash' => hash('sha256', $detectorId."\0".$key),
                'exception' => $exception::class,
            ]);
            $cached = $miss;
        }

        if ($cached !== $miss) {
            return $cached;
        }

        $row = DssParameter::query()
            ->where('detector_id', $detectorId)
            ->where('key', $key)
            ->first(['value']);

        if ($row !== null) {
            try {
                Cache::put($cacheKey, $row->value, self::CACHE_TTL_SECONDS);
            } catch (Throwable $exception) {
                Log::warning('DSS parameter cache write failed; returning the source value.', [
                    'scope_hash' => hash('sha256', $detectorId."\0".$key),
                    'exception' => $exception::class,
                ]);
            }

            return $row->value;
        }

        if ($detectorId !== self::GLOBAL && $globalFallback) {
            return $this->get(self::GLOBAL, $key, $default);
        }

        return $default;
    }

    public function set(string $detectorId, string $key, mixed $value, ?int $updatedBy = null): void
    {
        DB::transaction(function () use ($detectorId, $key, $value, $updatedBy) {
            $param = DssParameter::query()
                ->where('detector_id', $detectorId)
                ->where('key', $key)
                ->lockForUpdate()
                ->first();

            if ($param === null) {
                $param = new DssParameter;
                // Fill in one shot: fill() then attribute, single save → one
                // audit row (repo mass-assignment hygiene convention).
                $param->fill(['detector_id' => $detectorId, 'key' => $key, 'value' => $value]);
                $param->updated_by = $updatedBy;
                $param->save();
            } else {
                $param->fill(['value' => $value]);
                $param->updated_by = $updatedBy;
                $param->save();
            }

        });
    }

    /** Retire detector/key-scoped cache generations before the realtime callback. */
    public function invalidateCacheAfterCommit(string $detectorId, string $key): void
    {
        DB::afterCommit(static function () use ($detectorId, $key): void {
            CacheGeneration::rotateDefault(
                self::versionKey($detectorId, $key),
                self::VERSION_TTL_SECONDS,
                'dss.parameters.'.hash('sha256', $detectorId."\0".$key),
            );

            try {
                Cache::forget("dss:params:{$detectorId}:{$key}");
            } catch (Throwable $exception) {
                Log::warning('DSS parameter legacy cache cleanup failed after commit.', [
                    'scope_hash' => hash('sha256', $detectorId."\0".$key),
                    'exception' => $exception::class,
                ]);
            }
        });
    }

    /** Retire parameters removed as well as changed by a restored snapshot. */
    public function invalidateForDatabaseRestore(): bool
    {
        return CacheGeneration::rotateDefault(
            self::RESTORE_VERSION_KEY,
            self::VERSION_TTL_SECONDS,
            'dss.parameters.database_restore',
        );
    }

    private function cacheKey(string $detectorId, string $key, string $version): string
    {
        return "dss:params:{$detectorId}:{$key}:v{$version}";
    }

    private static function versionKey(string $detectorId, string $key): string
    {
        return 'dss:params:version:'.hash('sha256', $detectorId."\0".$key);
    }

    /** Engine kill switch + default mode live here, seeded by the W0-1 migration. */
    public function engineEnabled(): bool
    {
        return $this->get(self::GLOBAL, 'dss.engine.enabled', true) === true;
    }

    public function defaultMode(): string
    {
        return 'shadow';
    }

    public function legacyGlobalAdvisoryIgnored(): bool
    {
        return $this->get(self::GLOBAL, 'dss.engine.default_mode') === 'advisory';
    }

    public function detectorEnabled(string $detectorId): bool
    {
        return $this->get($detectorId, 'enabled', true) === true;
    }

    public function detectorMode(string $detectorId): string
    {
        $default = self::DEFAULT_DETECTOR_MODES[$detectorId] ?? 'shadow';
        $mode = $this->get($detectorId, 'mode', $default, globalFallback: false);

        return in_array($mode, ['shadow', 'advisory'], true) ? $mode : 'shadow';
    }
}
