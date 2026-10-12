<?php

declare(strict_types=1);

namespace App\Modules\DSS\Controllers;

use App\Modules\DSS\Models\DssParameter;
use App\Modules\DSS\Models\DssRecommendation;
use App\Modules\DSS\Models\DssRunLog;
use App\Modules\DSS\Requests\SetDssParameterRequest;
use App\Modules\DSS\Requests\UpdateDetectorRequest;
use App\Modules\DSS\Requests\UpdateEngineRequest;
use App\Modules\DSS\Services\DetectorRegistry;
use App\Modules\DSS\Services\DssParameterService;
use App\Modules\DSS\Services\DssRunnerService;
use Illuminate\Http\JsonResponse;

/**
 * DSS admin console API: detector list/status, enable + mode + run-now,
 * engine state (kill switch), and parameter edits. Reads and detector edits
 * need dss.manage; the kill switch needs dss.admin (route middleware).
 * Parameter writes are audited via DssParameter::HasAuditLog.
 */
class DssAdminController
{
    public function __construct(
        private readonly DetectorRegistry $registry,
        private readonly DssParameterService $params,
        private readonly DssRunnerService $runner,
    ) {}

    /** @return array{data: list<array<string, mixed>>} */
    public function detectors(): array
    {
        $openCounts = DssRecommendation::query()
            ->where('status', 'open')
            ->selectRaw('detector_id, COUNT(*) as count')
            ->groupBy('detector_id')
            ->pluck('count', 'detector_id');

        $lastRuns = DssRunLog::query()
            ->orderByDesc('id')
            ->limit(200)
            ->get(['detector_id', 'status', 'finished_at', 'duration_ms', 'emitted'])
            ->keyBy('detector_id');

        $rows = [];
        foreach ($this->registry->all() as $id => $class) {
            $detector = $this->registry->make($id);
            $last = $lastRuns->get($id);
            $rows[] = [
                'id' => $id,
                'domain' => $detector->domain(),
                'version' => $detector->version(),
                'owner_permissions' => $detector->ownerPermissions(),
                'enabled' => $this->params->detectorEnabled($id),
                'mode' => $this->params->detectorMode($id),
                'open_count' => (int) ($openCounts[$id] ?? 0),
                'last_run' => $last === null ? null : [
                    'status' => $last->status,
                    'finished_at' => $last->finished_at?->toISOString(),
                    'duration_ms' => $last->duration_ms,
                    'emitted' => $last->emitted,
                ],
            ];
        }

        return ['data' => $rows];
    }

    /** @return array{data: array<string, mixed>} */
    public function updateDetector(UpdateDetectorRequest $request, string $id): array
    {
        $this->assertKnown($id);
        $validated = $request->validated();
        $userId = $request->user()->id;

        if (array_key_exists('enabled', $validated)) {
            $this->params->set($id, 'enabled', (bool) $validated['enabled'], $userId);
        }

        if (array_key_exists('mode', $validated)) {
            $this->params->set($id, 'mode', $validated['mode'], $userId);
        }

        return ['data' => $this->detectorRow($id)];
    }

    /** @return array{data: array<string, mixed>} */
    public function runDetector(string $id): array
    {
        $this->assertKnown($id);

        return ['data' => $this->runner->run($id)];
    }

    /** @return array{data: array{enabled: bool, default_mode: string}} */
    public function engine(): array
    {
        return ['data' => [
            'enabled' => $this->params->engineEnabled(),
            'default_mode' => $this->params->defaultMode(),
        ]];
    }

    /** @return array{data: array{enabled: bool, default_mode: string}} */
    public function updateEngine(UpdateEngineRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $userId = $request->user()->id;

        if (array_key_exists('enabled', $validated)) {
            $this->params->set(DssParameterService::GLOBAL, 'dss.engine.enabled', (bool) $validated['enabled'], $userId);
        }

        if (array_key_exists('default_mode', $validated)) {
            $this->params->set(DssParameterService::GLOBAL, 'dss.engine.default_mode', $validated['default_mode'], $userId);
        }

        return response()->json(['data' => [
            'enabled' => $this->params->engineEnabled(),
            'default_mode' => $this->params->defaultMode(),
        ]]);
    }

    /** @return array{data: list<array<string, mixed>>} */
    public function parameters(): array
    {
        $detector = request()->query('detector');

        $query = DssParameter::query()->orderBy('detector_id')->orderBy('key');
        if (is_string($detector) && $detector !== '') {
            $query->where('detector_id', $detector);
        }

        return ['data' => $query->get(['detector_id', 'key', 'value', 'updated_at'])->all()];
    }

    /** @return array{data: array{detector_id: string, key: string, value: mixed}} */
    public function setParameter(SetDssParameterRequest $request): array
    {
        $validated = $request->validated();
        $this->params->set($validated['detector_id'], $validated['key'], $validated['value'], $request->user()->id);

        return ['data' => [
            'detector_id' => $validated['detector_id'],
            'key' => $validated['key'],
            'value' => $validated['value'],
        ]];
    }

    private function assertKnown(string $id): void
    {
        if (! $this->registry->has($id)) {
            abort(404, "Unknown detector '{$id}'.");
        }
    }

    /** @return array<string, mixed> */
    private function detectorRow(string $id): array
    {
        foreach ($this->detectors()['data'] as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        abort(404);
    }
}
