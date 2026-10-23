<?php

declare(strict_types=1);

namespace App\Modules\DSS\Controllers;

use App\Modules\DSS\Models\DssParameter;
use App\Modules\DSS\Models\DssRecommendation;
use App\Modules\DSS\Models\DssRecommendationEvent;
use App\Modules\DSS\Models\DssRunLog;
use App\Modules\DSS\Requests\SetDssParameterRequest;
use App\Modules\DSS\Requests\UpdateDetectorRequest;
use App\Modules\DSS\Requests\UpdateEngineRequest;
use App\Modules\DSS\Services\DetectorRegistry;
use App\Modules\DSS\Services\DssParameterService;
use App\Modules\DSS\Services\DssRunnerService;
use App\Modules\DSS\Services\RecommendationService;
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
        private readonly RecommendationService $recommendations,
    ) {}

    /** @return array{data: list<array<string, mixed>>} */
    public function detectors(): array
    {
        $openCounts = DssRecommendation::query()
            ->where(function ($query): void {
                $query->where('status', 'open')
                    ->orWhere(fn ($snoozed) => $snoozed->where('status', 'snoozed')->where('snoozed_until', '<=', now()));
            })
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->selectRaw('detector_id, COUNT(*) as count')
            ->groupBy('detector_id')
            ->pluck('count', 'detector_id');

        $latestRunIds = DssRunLog::query()
            ->selectRaw('MAX(id)')
            ->whereIn('detector_id', array_keys($this->registry->all()))
            ->groupBy('detector_id');
        $lastRuns = DssRunLog::query()
            ->whereIn('id', $latestRunIds)
            ->get(['detector_id', 'status', 'finished_at', 'duration_ms', 'rows_scanned', 'emitted'])
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
                    'rows_scanned' => $last->rows_scanned,
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
        $invalidate = (array_key_exists('enabled', $validated) && $this->params->detectorEnabled($id) !== (bool) $validated['enabled'])
            || (array_key_exists('mode', $validated) && $this->params->detectorMode($id) !== $validated['mode']);

        if (array_key_exists('enabled', $validated)) {
            $this->params->set($id, 'enabled', (bool) $validated['enabled'], $userId);
        }

        if (array_key_exists('mode', $validated)) {
            $this->params->set($id, 'mode', $validated['mode'], $userId);
        }
        if ($invalidate) {
            $this->recommendations->expireForPause($id);
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

    /** @return array{data: array{period_days: int, recommendation_opens: int, follow_ups_started: int, source_verified_resolutions: int, recurring_recommendations: int}} */
    public function activitySummary(): array
    {
        $since = now()->subDays(30);
        $events = DssRecommendationEvent::query()->where('created_at', '>=', $since);

        return ['data' => [
            'period_days' => 30,
            'recommendation_opens' => (clone $events)->where('event', 'viewed')->count(),
            'follow_ups_started' => (clone $events)->where('event', 'started')->count(),
            'source_verified_resolutions' => (clone $events)
                ->where('event', 'resolved')
                ->where('metadata->source', 'source_verification')
                ->count(),
            'recurring_recommendations' => DssRecommendation::query()
                ->where('created_at', '>=', $since)
                ->whereNotNull('predecessor_id')
                ->count(),
        ]];
    }

    /** @return array{data: array{enabled: bool, default_mode: string}} */
    public function updateEngine(UpdateEngineRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $userId = $request->user()->id;
        $invalidate = (array_key_exists('enabled', $validated) && $this->params->engineEnabled() !== (bool) $validated['enabled'])
            || (array_key_exists('default_mode', $validated) && $this->params->defaultMode() !== $validated['default_mode']);

        if (array_key_exists('enabled', $validated)) {
            $this->params->set(DssParameterService::GLOBAL, 'dss.engine.enabled', (bool) $validated['enabled'], $userId);
        }

        if (array_key_exists('default_mode', $validated)) {
            $this->params->set(DssParameterService::GLOBAL, 'dss.engine.default_mode', $validated['default_mode'], $userId);
        }
        if ($invalidate) {
            $this->recommendations->expireForPause();
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
