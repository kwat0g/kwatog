<?php

declare(strict_types=1);

namespace App\Modules\DSS\Controllers;

use App\Modules\Auth\Models\User;
use App\Modules\DSS\Models\DssRunLog;
use App\Modules\DSS\Services\DetectorRegistry;
use App\Modules\DSS\Services\DssParameterService;

/** User-visible detector health, limited to checks owned by the current user. */
class DssCoverageController
{
    public function __construct(
        private readonly DetectorRegistry $registry,
        private readonly DssParameterService $params,
    ) {}

    /** @return array{data: array<string, mixed>} */
    public function __invoke(): array
    {
        /** @var User $user */
        $user = request()->user();
        $visible = [];
        foreach ($this->registry->all() as $id => $_class) {
            $detector = $this->registry->make($id);
            if ($user->role?->slug !== 'system_admin' && ! collect($detector->ownerPermissions())->contains(fn (string $permission): bool => $user->can($permission))) {
                continue;
            }
            $visible[] = [
                'id' => $id,
                'domain' => $detector->domain(),
                'enabled' => $this->params->detectorEnabled($id),
                'mode' => $this->params->detectorMode($id),
            ];
        }

        $latestRunIds = DssRunLog::query()
            ->selectRaw('MAX(id)')
            ->whereIn('detector_id', array_column($visible, 'id'))
            ->groupBy('detector_id');
        $lastRuns = DssRunLog::query()
            ->whereIn('id', $latestRunIds)
            ->get(['detector_id', 'status', 'finished_at'])
            ->keyBy('detector_id');

        $checks = array_map(static function (array $detector) use ($lastRuns): array {
            $last = $lastRuns->get($detector['id']);

            return [...$detector, 'last_run' => $last === null ? null : [
                'status' => $last->status,
                'finished_at' => $last->finished_at?->toISOString(),
            ]];
        }, $visible);

        return ['data' => [
            'engine_enabled' => $this->params->engineEnabled(),
            'available_detectors' => count($checks),
            'shadow_detectors' => count(array_filter($checks, static fn (array $row): bool => $row['enabled'] && $row['mode'] === 'shadow')),
            'advisory_detectors' => count(array_filter($checks, static fn (array $row): bool => $row['enabled'] && $row['mode'] === 'advisory')),
            'disabled_detectors' => count(array_filter($checks, static fn (array $row): bool => ! $row['enabled'])),
            'failed_detectors' => count(array_filter($checks, static fn (array $row): bool => ($row['last_run']['status'] ?? null) === 'failed')),
            'partial_detectors' => count(array_filter($checks, static fn (array $row): bool => ($row['last_run']['status'] ?? null) === 'partial')),
            'checks' => $checks,
        ]];
    }
}
