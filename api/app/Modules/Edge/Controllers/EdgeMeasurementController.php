<?php

declare(strict_types=1);

namespace App\Modules\Edge\Controllers;

use App\Modules\Edge\Models\EdgeDevice;
use App\Modules\Edge\Requests\MeasurementIngestRequest;
use App\Modules\Edge\Services\EdgeMeasurementIngestService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * T2.4 — POST /edge/v1/measurement. Caliper or scale pushes one reading
 * to an open inspection. The service delegates to InspectionService so
 * tolerance auto-evaluation, defect counting, and status transition all
 * mirror the SPA path identically.
 */
class EdgeMeasurementController
{
    public function __construct(private readonly EdgeMeasurementIngestService $service) {}

    public function ingest(MeasurementIngestRequest $request): JsonResponse
    {
        /** @var EdgeDevice $device */
        $device = $request->user();

        $payload = $request->validated();
        $idem = $payload['idempotency_key'] ?? $request->header('X-Idempotency-Key');
        unset($payload['idempotency_key']);

        try {
            $measurement = $this->service->ingest($device, $payload, $idem);
        } catch (RuntimeException $e) {
            // Mirror EdgeOutputController: any service-layer guard (e.g. the
            // delegated InspectionService throws when status is terminal) is
            // surfaced as a 422 rather than bubbling to 500.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $inspection = $measurement->inspection()->first();

        return response()->json([
            'data' => [
                'measurement_id' => $measurement->hash_id,
                'parameter_name' => $measurement->parameter_name,
                'nominal_value'  => $measurement->nominal_value !== null ? (string) $measurement->nominal_value : null,
                'tolerance_min'  => $measurement->tolerance_min !== null ? (string) $measurement->tolerance_min : null,
                'tolerance_max'  => $measurement->tolerance_max !== null ? (string) $measurement->tolerance_max : null,
                'measured_value' => $measurement->measured_value !== null ? (string) $measurement->measured_value : null,
                'is_pass'        => $measurement->is_pass,
                'is_critical'    => (bool) $measurement->is_critical,
                'inspection'     => [
                    'id'           => $inspection?->hash_id,
                    'status'       => $inspection?->status?->value,
                    'defect_count' => (int) ($inspection?->defect_count ?? 0),
                ],
            ],
        ], 201);
    }
}
