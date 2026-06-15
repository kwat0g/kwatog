<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Modules\Edge\Models\EdgeDevice;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Services\InspectionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * T2.4 — Edge → inspection measurement ingest.
 *
 * Resolves the {inspection, measurement} pair from hash IDs, then
 * delegates to InspectionService::recordMeasurements() so tolerance
 * auto-evaluation and status transitions match the SPA flow exactly.
 *
 * The idempotency cache short-circuits BEFORE delegating so a replay
 * with a different measured_value must NOT overwrite the stored row.
 */
class EdgeMeasurementIngestService
{
    private const IDEMPOTENCY_TTL_SECONDS = 86400;

    public function __construct(
        private readonly InspectionService $inspections,
        private readonly EdgeSystemUserResolver $systemUser,
    ) {}

    public function ingest(EdgeDevice $device, array $payload, ?string $idemKey = null): InspectionMeasurement
    {
        $inspectionId  = $this->decode($payload['inspection_id'] ?? null);
        $measurementId = $this->decode($payload['measurement_id'] ?? null);

        if (! $inspectionId) {
            throw ValidationException::withMessages(['inspection_id' => ['invalid_inspection']]);
        }
        if (! $measurementId) {
            throw ValidationException::withMessages(['measurement_id' => ['invalid_measurement']]);
        }

        $inspection = Inspection::query()->find($inspectionId);
        if (! $inspection) {
            throw ValidationException::withMessages(['inspection_id' => ['invalid_inspection']]);
        }
        if ($inspection->status->isTerminal()) {
            throw ValidationException::withMessages(['inspection' => ['inspection_finalised']]);
        }

        $measurement = InspectionMeasurement::query()
            ->where('id', $measurementId)
            ->where('inspection_id', $inspectionId)
            ->first();
        if (! $measurement) {
            throw ValidationException::withMessages(['measurement_id' => ['measurement_not_in_inspection']]);
        }

        // Idempotent replay: same key → return previously persisted row WITHOUT
        // re-delegating to InspectionService. This guarantees that a replay with
        // a different measured_value does not overwrite the first reading.
        if ($idemKey) {
            $cachedId = Cache::get($this->cacheKey($idemKey));
            if (is_int($cachedId)) {
                $cached = InspectionMeasurement::find($cachedId);
                if ($cached) {
                    return $cached;
                }
            }
        }

        $row = [
            'measured_value' => (string) $payload['measured_value'],
        ];
        if (array_key_exists('notes', $payload)) {
            $row['notes'] = $payload['notes'];
        }

        $this->systemUser->impersonate(function () use ($inspection, $measurementId, $row): void {
            $this->inspections->recordMeasurements(
                $inspection,
                [$measurementId => $row],
                $this->systemUser->user(),
            );
        });

        $fresh = $measurement->fresh();

        if ($idemKey && $fresh) {
            Cache::put($this->cacheKey($idemKey), (int) $fresh->id, self::IDEMPOTENCY_TTL_SECONDS);
        }

        return $fresh ?? $measurement;
    }

    private function decode(mixed $hash): ?int
    {
        if (! is_string($hash) || $hash === '') {
            return null;
        }
        $decoded = app('hashids')->decode($hash);
        return empty($decoded) ? null : (int) $decoded[0];
    }

    private function cacheKey(string $key): string
    {
        return 'edge:measurement:idem:'.$key;
    }
}
