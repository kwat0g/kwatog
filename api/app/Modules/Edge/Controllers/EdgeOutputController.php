<?php

declare(strict_types=1);

namespace App\Modules\Edge\Controllers;

use App\Modules\Edge\Models\EdgeDevice;
use App\Modules\Edge\Requests\OutputIngestRequest;
use App\Modules\Edge\Services\EdgeOutputIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class EdgeOutputController
{
    public function __construct(private readonly EdgeOutputIngestService $service) {}

    public function ingest(OutputIngestRequest $request): JsonResponse
    {
        /** @var EdgeDevice $device */
        $device = $request->user();

        $payload = $request->validated();
        // Decode hash-ID defect_type_ids → ints for the underlying WO output service.
        // Rejects malformed hashes with 422 instead of silently coercing to 0 (which
        // would then violate the defect_types FK and surface as a 500 to the device).
        if (! empty($payload['defects'])) {
            $payload['defects'] = array_map(function (array $d, int $idx) {
                $hash = (string) ($d['defect_type_id'] ?? '');
                $decoded = app('hashids')->decode($hash);
                if (empty($decoded)) {
                    throw ValidationException::withMessages([
                        "defects.{$idx}.defect_type_id" => ['invalid_defect_type'],
                    ]);
                }
                return [
                    'defect_type_id' => (int) $decoded[0],
                    'count'          => (int) ($d['count'] ?? 0),
                ];
            }, $payload['defects'], array_keys($payload['defects']));
        }

        $idem = $payload['idempotency_key'] ?? $request->header('X-Idempotency-Key');
        unset($payload['idempotency_key']);

        try {
            $output = $this->service->ingest($device, $payload, $idem);
        } catch (RuntimeException $e) {
            // Mirror the SPA path (WorkOrderController::recordOutput): service-layer
            // guard violations (zero counts, exceed target, status, defect-sum
            // mismatch) propagate as 422 with the message body.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'output_id'    => $output->hash_id,
                'wo_id'        => $output->workOrder?->hash_id,
                'wo_number'    => $output->workOrder?->wo_number,
                'batch_code'   => $output->batch_code,
                'good_count'   => (int) $output->good_count,
                'reject_count' => (int) $output->reject_count,
                'recorded_at'  => optional($output->recorded_at)->toIso8601String(),
            ],
        ], 201);
    }
}
