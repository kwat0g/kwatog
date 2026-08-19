<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Controllers;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\HashIdFilter;
use App\Common\Services\SettingsService;
use App\Modules\Maintenance\Models\MachineConditionReading;
use App\Modules\Maintenance\Resources\MachineConditionReadingResource;
use App\Modules\Maintenance\Services\PredictiveMaintenanceService;
use App\Modules\MRP\Models\Machine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * ADV8 — Maintenance Automation.
 * REST endpoints for condition readings and predictive health checks.
 */
class MachineConditionReadingController
{
    public function __construct(
        private readonly PredictiveMaintenanceService $predictive,
        private readonly SettingsService $settings,
    ) {}

    /**
     * The SPA sends machine_id as a hash_id (per the ID-obfuscation rule), while the
     * validation rules below expect a raw integer. Decode before validating so these
     * endpoints are reachable from the UI at all.
     */
    private function decodeMachineId(Request $request): void
    {
        $raw = $request->input('machine_id');
        if ($raw === null || $raw === '') {
            return;
        }
        // Replace even when undecodable so `integer`/`exists` fails predictably.
        $request->merge(['machine_id' => HashIdFilter::decode($raw, Machine::class)]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->decodeMachineId($request);
        $request->validate([
            'machine_id' => ['required', 'integer', 'exists:machines,id'],
            'metric'     => ['nullable', Rule::in(array_column($this->predictive->metricOptions(), 'value'))],
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

    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'metrics' => $this->predictive->metricOptions(),
            'sources' => $this->sourceOptions(),
            'default_source' => (string) $this->settings->get('maintenance.predictive.default_source', ''),
        ]]);
    }

    /** @return list<string> */
    private function sourceValues(): array
    {
        return array_column($this->sourceOptions(), 'value');
    }

    /** @return list<array{value:string,label:string}> */
    private function sourceOptions(): array
    {
        $sources = $this->settings->get('maintenance.predictive.sources', []);
        return array_values(array_filter(is_array($sources) ? $sources : [], static fn ($source): bool => is_array($source)
            && is_string($source['value'] ?? null) && is_string($source['label'] ?? null)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->decodeMachineId($request);
        $data = $request->validate([
            'machine_id'  => ['required', 'integer', 'exists:machines,id'],
            'metric'      => ['required', Rule::in(array_column($this->predictive->metricOptions(), 'value'))],
            'value'       => ['required', 'numeric'],
            'unit'        => ['nullable', 'string', 'max:20'],
            'recorded_at' => ['nullable', 'date'],
            'source'      => ['nullable', Rule::in($this->sourceValues())],
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

            // `reason` is rendered to the technician, so it cannot be an
            // arbitrary exception string: this arm caught \Throwable and passed
            // getMessage() straight through, which meant a failure creating the
            // corrective MWO reported `SQLSTATE[23503]: Foreign key violation
            // ... (Connection: pgsql, SQL: insert into "maintenance_work_orders"
            // ...)` as the reason no work order was raised. Only
            // BusinessRuleException is copy written to be read; anything else
            // gets a fixed sentence and a log line for support.
            if ($e instanceof BusinessRuleException) {
                $reason = $e->getMessage();
            } else {
                Log::error('MachineConditionReadingController — reading saved but evaluation failed.', [
                    'machine_id' => (int) $data['machine_id'],
                    'metric'     => $data['metric'],
                    'exception'  => $e::class,
                    'message'    => $e->getMessage(),
                ]);
                $reason = 'The reading was saved, but the breach evaluation could not complete. Maintenance has been notified.';
            }

            return response()->json([
                'data'      => $reading ? new MachineConditionReadingResource($reading) : null,
                'triggered' => false,
                'reason'    => $reason,
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
        $this->decodeMachineId($request);
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
        $this->decodeMachineId($request);
        $request->validate([
            'machine_id' => ['required', 'integer', 'exists:machines,id'],
        ]);

        $snapshot = $this->predictive->machineHealthSnapshot(
            (int) $request->input('machine_id')
        );

        return response()->json(['data' => $snapshot]);
    }
}
