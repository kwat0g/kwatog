<?php

declare(strict_types=1);

namespace App\Modules\Leave\Services;

use App\Common\Models\OutboxMessage;
use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\User;
use App\Modules\Leave\Events\YearEndLeaveProcessingRequested;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owns the initiation boundary for year-end leave processing.
 *
 * The business job remains the execution primitive, while this service makes
 * every production trigger use the same durable, deduplicated request path.
 */
class YearEndLeaveProcessingService
{
    public function __construct(private readonly OutboxService $outbox) {}

    /**
     * @param array<int>|null $leaveTypeIds
     */
    public function request(User $runBy, int $year, ?array $leaveTypeIds = null): OutboxMessage
    {
        $normalizedTypeIds = $leaveTypeIds === null
            ? null
            : collect($leaveTypeIds)
                ->map(static fn (mixed $id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->unique()
                ->sort()
                ->values()
                ->all();

        $scope = $normalizedTypeIds === null
            ? 'all'
            : implode(',', $normalizedTypeIds);
        $dedupeKey = 'leave-year-end:'.$year.':'.hash('sha256', $scope);

        return DB::transaction(fn (): OutboxMessage => $this->outbox->record(
            new YearEndLeaveProcessingRequested(
                year: $year,
                runById: (int) $runBy->getKey(),
                leaveTypeIds: $normalizedTypeIds,
                requestId: (string) Str::uuid(),
            ),
            dedupeKey: $dedupeKey,
            chain: [
                'chain' => 'h2r',
                'entity_type' => 'year_end_leave',
                'entity_id' => $year,
                'step' => 'disposition',
            ],
        ));
    }
}
