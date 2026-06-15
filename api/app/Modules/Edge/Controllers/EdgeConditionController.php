<?php

declare(strict_types=1);

namespace App\Modules\Edge\Controllers;

use App\Modules\Edge\Models\EdgeDevice;
use App\Modules\Edge\Requests\ConditionIngestRequest;
use App\Modules\Edge\Services\EdgeConditionIngestService;
use Illuminate\Http\JsonResponse;

/**
 * T2.3 — POST /edge/v1/condition. IoT sensor pushes one metric reading.
 * Response surfaces the persisted reading plus any auto-generated MWO when
 * the consecutive-breach gate trips inside PredictiveMaintenanceService.
 */
class EdgeConditionController
{
    public function __construct(private readonly EdgeConditionIngestService $service) {}

    public function ingest(ConditionIngestRequest $request): JsonResponse
    {
        /** @var EdgeDevice $device */
        $device = $request->user();

        $payload = $request->validated();
        $idem = $payload['idempotency_key'] ?? $request->header('X-Idempotency-Key');
        unset($payload['idempotency_key']);

        $result = $this->service->ingest($device, $payload, $idem);
        $reading = $result['reading'];

        $body = [
            'reading_id' => $reading->hash_id,
            'metric'     => $reading->metric,
            'value'      => (string) $reading->value,
            'unit'       => $reading->unit,
            'triggered'  => (bool) ($result['triggered'] ?? false),
        ];
        if (! empty($result['reason'])) {
            $body['reason'] = $result['reason'];
        }
        if (! empty($result['replayed'])) {
            $body['replayed'] = true;
        }
        if (! empty($result['work_order'])) {
            $wo = $result['work_order'];
            $body['work_order'] = [
                'id'         => $wo->hash_id,
                'mwo_number' => $wo->mwo_number,
                'machine_id' => app('hashids')->encode($wo->maintainable_id),
            ];
        }

        return response()->json(['data' => $body], 201);
    }
}
