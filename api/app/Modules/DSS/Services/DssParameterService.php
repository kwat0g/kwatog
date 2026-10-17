<?php

declare(strict_types=1);

namespace App\Modules\DSS\Services;

use App\Modules\DSS\Models\DssParameter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Per-detector (or `global`) tunables with detector-code defaults.
 *
 * Read path: detector default ← `global` row ← detector row (most specific
 * wins). Cached per (detector, key); writes clear the key AND rely on
 * DssParameter::HasAuditLog for the who-changed-what trail (parameter
 * governance without a second history table).
 */
class DssParameterService
{
    public const GLOBAL = 'global';

    public function get(string $detectorId, string $key, mixed $default = null): mixed
    {
        $cacheKey = "dss:params:{$detectorId}:{$key}";
        $miss = new \stdClass;
        $cached = Cache::get($cacheKey, $miss);

        if ($cached !== $miss) {
            return $cached;
        }

        $row = DssParameter::query()
            ->where('detector_id', $detectorId)
            ->where('key', $key)
            ->first(['value']);

        if ($row !== null) {
            Cache::put($cacheKey, $row->value, 3600);

            return $row->value;
        }

        if ($detectorId !== self::GLOBAL) {
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

        Cache::forget("dss:params:{$detectorId}:{$key}");
    }

    /** Engine kill switch + default mode live here, seeded by the W0-1 migration. */
    public function engineEnabled(): bool
    {
        return (bool) $this->get(self::GLOBAL, 'dss.engine.enabled', true);
    }

    public function defaultMode(): string
    {
        $mode = $this->get(self::GLOBAL, 'dss.engine.default_mode', 'shadow');

        return in_array($mode, ['shadow', 'advisory', 'assisted'], true) ? $mode : 'shadow';
    }

    public function detectorEnabled(string $detectorId): bool
    {
        return (bool) $this->get($detectorId, 'enabled', true);
    }

    public function detectorMode(string $detectorId): string
    {
        $mode = $this->get($detectorId, 'mode', $this->defaultMode());

        return in_array($mode, ['shadow', 'advisory', 'assisted'], true) ? $mode : 'shadow';
    }
}
