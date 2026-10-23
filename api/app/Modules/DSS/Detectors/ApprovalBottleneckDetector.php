<?php

declare(strict_types=1);

namespace App\Modules\DSS\Detectors;

use App\Common\Services\SettingsService;
use App\Common\Support\ApprovalSourceScope;
use App\Common\Support\ApprovalTypeRegistry;
use App\Modules\DSS\Contracts\DetectorEmission;
use App\Modules\DSS\Contracts\DetectorInterface;
use App\Modules\DSS\Contracts\DetectorScanResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** D25 — aggregate overdue active approval steps; never expose approver or document data. */
class ApprovalBottleneckDetector implements DetectorInterface
{
    public function __construct(private readonly SettingsService $settings) {}

    public function id(): string
    {
        return 'dss.approval_bottlenecks';
    }

    public function domain(): string
    {
        return 'approvals';
    }

    public function ownerPermissions(): array
    {
        return ['approvals.board.view'];
    }

    public function version(): string
    {
        return 'v2';
    }

    public function defaultParams(): array
    {
        return [];
    }

    public function detect(array $context = []): DetectorScanResult
    {
        $hours = $this->settings->requiredInt('approvals.reminder_hours', 1, 87600);
        $limit = max(1, (int) ($context['limit'] ?? 200));
        $types = [];
        $excludedQueues = [];
        foreach (ApprovalTypeRegistry::all() as $class => $meta) {
            if (ApprovalSourceScope::hasScope($class)) {
                $excludedQueues[] = $meta['label'];

                continue;
            }
            $types[$class] = $meta;
        }

        $rows = DB::table('approval_records')
            ->where('action', 'pending')
            ->where('is_current', true)
            ->where(function ($query) use ($types): void {
                foreach ($types as $class => $meta) {
                    $table = $meta['table'];
                    $query->orWhere(function ($source) use ($class, $table): void {
                        // Match ApprovalBoardService: raw-table existence includes soft-deleted rows.
                        $source->where('approval_records.approvable_type', $class)
                            ->whereExists(fn ($exists) => $exists->selectRaw('1')->from($table)
                                ->whereColumn($table.'.id', 'approval_records.approvable_id'));
                    });
                }
            })
            ->where('created_at', '<=', now()->subHours($hours))
            ->select(['approvable_type', 'role_slug'])
            ->selectRaw('COUNT(*) as waiting_count, MIN(created_at) as oldest_created_at')
            ->groupBy('approvable_type', 'role_slug')
            ->orderBy('oldest_created_at')
            ->limit($limit + 1)
            ->get();

        $complete = $rows->count() <= $limit;
        $emissions = $rows->take($limit)->map(function ($row) use ($hours, $types, $excludedQueues): DetectorEmission {
            $oldest = Carbon::parse($row->oldest_created_at);
            $meta = $types[$row->approvable_type];
            $role = $row->role_slug === null || $row->role_slug === '' ? null : (string) $row->role_slug;

            return new DetectorEmission(
                type: 'approval_bottleneck',
                severity: 'warning',
                dedupeKey: 'approval:'.$meta['kind'].':role:'.($role ?? 'unassigned'),
                rationale: [
                    'rule' => 'current approval step exceeded reminder SLA',
                    'queue' => $meta['label'],
                    'role' => $role ?? 'unassigned',
                    'waiting_count' => (int) $row->waiting_count,
                    'oldest_wait_hours' => $oldest->diffInHours(now(), true),
                    'reminder_threshold_hours' => $hours,
                    'excluded_queues' => $excludedQueues,
                ],
                confidence: 1.0,
                dataSufficiency: 'partial',
                action: ['route' => $meta['link'], 'label' => 'Open '.$meta['label'].' approvals'],
                ownerPermissions: array_values(array_unique($meta['permissions'])),
                ownerRoleSlugs: $role === null ? null : [$role],
                policyVersion: $this->version(),
                businessDueAt: $oldest->copy()->addHours($hours),
            );
        })->all();

        return new DetectorScanResult($emissions, (int) $rows->sum('waiting_count'), $complete);
    }
}
