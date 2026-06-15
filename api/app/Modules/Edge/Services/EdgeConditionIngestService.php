<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Modules\Edge\Models\EdgeDevice;
use App\Modules\Maintenance\Models\MachineConditionReading;
use App\Modules\Maintenance\Services\PredictiveMaintenanceService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * T2.3 — Edge → machine condition reading ingest.
 *
 * Persists one reading per call and delegates breach detection +
 * corrective-WO triggering to {@see PredictiveMaintenanceService} so the
 * IoT path inherits the same consecutive-breach gate as the manual SPA
 * condition-readings flow.
 *
 * @return array{reading: MachineConditionReading, triggered: bool, reason?: string, work_order?: object, replayed?: bool}
 */
class EdgeConditionIngestService
{
    private const IDEMPOTENCY_TTL_SECONDS = 86400;

    public function __construct(
        private readonly PredictiveMaintenanceService $predictive,
        private readonly EdgeSystemUserResolver $systemUser,
    ) {}

    public function ingest(EdgeDevice $device, array $payload, ?string $idemKey = null): array
    {
        if (! $device->machine_id) {
            throw ValidationException::withMessages([
                'device' => ['device_not_bound_to_machine'],
            ]);
        }

        if ($idemKey !== null && $idemKey !== '') {
            $cached = Cache::get($this->cacheKey($idemKey));
            if (is_int($cached)) {
                $cachedReading = MachineConditionReading::find($cached);
                if ($cachedReading) {
                    return [
                        'reading'   => $cachedReading,
                        'triggered' => false,
                        'replayed'  => true,
                    ];
                }
            }
        }

        $data = [
            'machine_id'  => (int) $device->machine_id,
            'metric'      => (string) $payload['metric'],
            'value'       => (float) $payload['value'],
            'unit'        => $payload['unit'] ?? null,
            'recorded_at' => $payload['recorded_at'] ?? now(),
            'source'      => 'iot_sensor',
            'notes'       => $payload['notes'] ?? null,
        ];

        $result = $this->systemUser->impersonate(
            fn () => $this->predictive->recordAndEvaluate($data, $this->systemUser->user()),
        );

        if ($idemKey !== null && $idemKey !== '' && isset($result['reading'])) {
            Cache::put(
                $this->cacheKey($idemKey),
                (int) $result['reading']->id,
                self::IDEMPOTENCY_TTL_SECONDS,
            );
        }

        return $result;
    }

    private function cacheKey(string $key): string
    {
        return 'edge:condition:idem:' . $key;
    }
}
