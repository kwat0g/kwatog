<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Controllers;

use App\Modules\Maintenance\Models\MachineConditionReading;
use App\Modules\Maintenance\Resources\MachineConditionReadingResource;
use App\Modules\Maintenance\Services\PredictiveMaintenanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Validator;

/**
 * ADV8 — Maintenance Automation.
 * REST endpoints for condition readings and predictive health checks.
 */
class MachineConditionReadingController
{
    public function __construct(
        private readonly PredictiveMaintenanceService $predictive,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'machine_id' => ['required', 'integer', 'exists:machines,id'],
            'metric'     => ['nullable', 'string', 'in:temperature,vibration,pressure,current,oil_quality'],
        ]);

        $q = MachineConditionReading::query()
            ->with(['machine:id,machine_code,name'])
            ->where('machine_id', (int) $request->input('machine_id'));

        if ($request->filled('metric')) {
            $q->where('metric', $request->input('metric'));
        }

        return MachineConditionReadingResource::collection(
            $q->orderByDesc('recorded_at')->paginate(min((int) $request->input('per_page', 50), 200))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'machine_id'  => ['required', 'integer', 'exists:machines,id'],
            'metric'      => ['required', 'string', 'in:temperature,vibration,pressure,current,oil_quality'],
            'value'       => ['required', 'numeric'],
            'unit'        => ['nullable', 'string', 'max:20'],
            'recorded_at' => ['nullable', 'date'],
            'source'      => ['nullable', 'string', 'in:manual,iot_sensor,plc,api'],
            'notes'       => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $result = $this->predictive->recordAndEvaluate($data, $request->user());
        } catch (\Throwable $e) {
            // Reading was already saved inside the transaction; WO creation failed.
            // Return the reading with a warning so the user isn't left in the dark.
            $reading = MachineConditionReading::query()
                ->where('machine_id', (int) $data['machine_id'])
                ->where('metric', $data['metric'])
                ->orderByDesc('recorded_at')
                ->first();

            return response()->json([
                'data'      => $reading ? new MachineConditionReadingResource($reading) : null,
                'triggered' => false,
                'reason'    => $e->getMessage(),
                'work_order' => null,
            ], 201);
        }

        return response()->json([
            'data' => new MachineConditionReadingResource($result['reading']),
            'triggered' => $result['triggered'],
            'reason'    => $result['reason'] ?? null,
            'work_order' => isset($result['work_order']) ? [
                'id'         => $result['work_order']->hash_id,
                'mwo_number' => $result['work_order']->mwo_number,
            ] : null,
        ], 201);
    }

    public function show(MachineConditionReading $reading): MachineConditionReadingResource
    {
        return new MachineConditionReadingResource($reading->load(['machine:id,machine_code,name']));
    }

    public function trend(Request $request): JsonResponse
    {
        $request->validate([
            'machine_id' => ['required', 'integer', 'exists:machines,id'],
            'metric'     => ['required', 'string', 'in:temperature,vibration,pressure,current,oil_quality'],
            'limit'      => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $trend = $this->predictive->trend(
            (int) $request->input('machine_id'),
            (string) $request->input('metric'),
            (int) $request->input('limit', 30)
        );

        return response()->json(['data' => $trend]);
    }

    public function healthSnapshot(Request $request): JsonResponse
    {
        $request->validate([
            'machine_id' => ['required', 'integer', 'exists:machines,id'],
        ]);

        $snapshot = $this->predictive->machineHealthSnapshot(
            (int) $request->input('machine_id')
        );

        return response()->json(['data' => $snapshot]);
    }
}
