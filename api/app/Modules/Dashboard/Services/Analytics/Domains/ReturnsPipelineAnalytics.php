<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services\Analytics\Domains;

use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Support\Analytics\AnalyticsPeriod;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Enums\ReturnRequestType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `returns.pipeline` — the returns page's day-one read, and the first metric
 * that could not be served before this phase: `return_requests` had no index on
 * status, type or date (Phase 0 B-F12), so the counts were sequential scans.
 *
 * Status/type tallies are computed by the database (GROUP BY), not by loading
 * returns and counting in PHP. Zero-count statuses are omitted rather than sent
 * as zeros so the breakdown stays readable; `total` and `open_total` are the
 * authoritative sums.
 */
final class ReturnsPipelineAnalytics extends DomainMetric
{
    public function code(): string
    {
        return 'returns.pipeline';
    }

    protected function build(User $user, AnalyticsPeriod $period, array $filters): array
    {
        if (! Schema::hasTable('return_requests')) {
            return $this->emptyShape();
        }

        $from = $period->from->toDateString();
        $to = $period->to->toDateString();

        /** @var array<string, int> $byStatus */
        $byStatus = DB::table('return_requests')
            ->selectRaw('status, COUNT(*) AS total')
            ->whereBetween('return_date', [$from, $to])
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(static fn ($total): int => (int) $total)
            ->all();

        /** @var array<string, int> $byType */
        $byType = DB::table('return_requests')
            ->selectRaw('type, COUNT(*) AS total')
            ->whereBetween('return_date', [$from, $to])
            ->groupBy('type')
            ->pluck('total', 'type')
            ->map(static fn ($total): int => (int) $total)
            ->all();

        $openStatuses = array_map(
            static fn (ReturnRequestStatus $status): string => $status->value,
            array_values(array_filter(
                ReturnRequestStatus::cases(),
                static fn (ReturnRequestStatus $status): bool => $status->isActive(),
            )),
        );

        // Window-scoped on purpose: the whole payload answers ONE window. A
        // point-in-time "oldest open" mixed into a windowed breakdown reads as
        // if it belonged to the window (a 3-month-old return would show up on a
        // one-week tile). True ageing is its own metric, not this one.
        $oldestOpen = DB::table('return_requests')
            ->whereIn('status', $openStatuses)
            ->whereNotNull('return_date')
            ->whereBetween('return_date', [$from, $to])
            ->min('return_date');

        return [
            'total' => array_sum($byStatus),
            'open_total' => array_sum(array_intersect_key($byStatus, array_flip($openStatuses))),
            'awaiting_approval' => $byStatus[ReturnRequestStatus::PendingApproval->value] ?? 0,
            'oldest_open_days' => $oldestOpen === null
                ? null
                : max(0, (int) Carbon::parse((string) $oldestOpen)->diffInDays(Carbon::now(), true)),
            'segments' => $this->segments($byStatus),
            'by_type' => $this->typeSegments($byType),
            'handoff' => [
                // Returns that reached the physical stages but have no inspection
                // handoff stamped read as "stuck", not as zero volume (F3 class).
                'awaiting_inspection' => $byStatus[ReturnRequestStatus::Received->value] ?? 0,
                'in_inspection' => $byStatus[ReturnRequestStatus::Inspected->value] ?? 0,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function emptyShape(): array
    {
        return [
            'total' => 0,
            'open_total' => 0,
            'awaiting_approval' => 0,
            'oldest_open_days' => null,
            'segments' => [],
            'by_type' => [],
            'handoff' => ['awaiting_inspection' => 0, 'in_inspection' => 0],
        ];
    }

    /**
     * @param  array<string, int>  $byStatus
     * @return list<array{label: string, value: int, tone: string}>
     */
    private function segments(array $byStatus): array
    {
        $out = [];

        foreach (ReturnRequestStatus::cases() as $status) {
            $value = $byStatus[$status->value] ?? 0;

            if ($value === 0) {
                continue;
            }

            $out[] = ['label' => $status->label(), 'value' => $value, 'tone' => $this->tone($status)];
        }

        return $out;
    }

    /**
     * @param  array<string, int>  $byType
     * @return list<array{label: string, value: int}>
     */
    private function typeSegments(array $byType): array
    {
        $out = [];

        foreach (ReturnRequestType::cases() as $type) {
            $value = $byType[$type->value] ?? 0;

            if ($value === 0) {
                continue;
            }

            $out[] = ['label' => $type->label(), 'value' => $value];
        }

        return $out;
    }

    /** Brand-hued semantics, matching the Chip/breakdown tone vocabulary. */
    private function tone(ReturnRequestStatus $status): string
    {
        return match ($status) {
            ReturnRequestStatus::PendingApproval => 'warning',
            ReturnRequestStatus::Approved,
            ReturnRequestStatus::Received,
            ReturnRequestStatus::Inspected => 'info',
            ReturnRequestStatus::Completed => 'success',
            ReturnRequestStatus::Rejected => 'danger',
            ReturnRequestStatus::Draft,
            ReturnRequestStatus::Cancelled => 'neutral',
        };
    }
}
