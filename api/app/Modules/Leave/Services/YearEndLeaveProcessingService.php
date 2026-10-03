<?php

declare(strict_types=1);

namespace App\Modules\Leave\Services;

use App\Common\Models\ChainListenerRun;
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
     * @param  array<int>|null  $leaveTypeIds
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

        return DB::transaction(function () use ($dedupeKey, $normalizedTypeIds, $runBy, $year): OutboxMessage {
            $root = OutboxMessage::query()
                ->where('dedupe_key', $dedupeKey)
                ->lockForUpdate()
                ->first();

            if ($root) {
                $retryPrefix = $dedupeKey.':retry:';
                $keys = OutboxMessage::query()
                    ->where(fn ($query) => $query
                        ->where('dedupe_key', $dedupeKey)
                        ->orWhere('dedupe_key', 'like', $retryPrefix.'%'))
                    ->pluck('dedupe_key');
                $retryCount = $keys
                    ->filter(static fn (string $key): bool => str_starts_with($key, $retryPrefix))
                    ->count();
                $latestKey = $retryCount === 0 ? $dedupeKey : $retryPrefix.$retryCount;
                $latest = OutboxMessage::query()
                    ->where('dedupe_key', $latestKey)
                    ->lockForUpdate()
                    ->firstOrFail();
                $latestRun = ChainListenerRun::query()
                    ->where('outbox_id', $latest->getKey())
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->first();

                if ($this->requiresReplacementActor($latestRun)) {
                    $dedupeKey = $retryPrefix.($retryCount + 1);
                } else {
                    $dedupeKey = $latestKey;
                }
            }

            return $this->outbox->record(
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
            );
        });
    }

    private function requiresReplacementActor(?ChainListenerRun $run): bool
    {
        return $run?->status === ChainListenerRun::STATUS_COMPLETED
            && $run->outcome_status === ChainListenerRun::OUTCOME_MANUAL_REQUIRED
            && $run->outcome_code === 'year_end_automation_actor_unavailable';
    }
}
