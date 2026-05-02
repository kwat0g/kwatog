<?php

declare(strict_types=1);

namespace App\Modules\Production\Services;

use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Services\MoldService;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Events\WorkOrderOutputRecorded;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sprint 6 — Task 55.
 *
 * Records production output for an in-progress WO. Idempotent at the
 * X-Idempotency-Key header — duplicate keys within 24h return the cached
 * payload instead of double-recording.
 *
 * On success:
 *  - Persists work_order_outputs + work_order_defects rows.
 *  - Updates WO totals (quantity_produced/good/rejected) + scrap_rate.
 *  - Increments mold shot count atomically (which may auto-flip mold to
 *    Maintenance via MoldService).
 *  - Dispatches WorkOrderOutputRecorded event for live dashboard.
 */
class WorkOrderOutputService
{
    private const IDEMPOTENCY_TTL_SECONDS = 86400;

    public function __construct(private readonly MoldService $molds) {}

    /**
     * @param array $data {
     *   good_count: int, reject_count: int, shift?: string, remarks?: string,
     *   defects?: array<int, array{defect_type_id: int, count: int}>
     * }
     */
    public function record(
        WorkOrder $wo,
        array $data,
        int $recordedBy,
        ?string $idempotencyKey = null,
    ): WorkOrderOutput {
        // Idempotency: replay the cached output for the same key.
        if ($idempotencyKey) {
            $cacheKey = "production:idem:{$idempotencyKey}";
            if (($outputId = Cache::get($cacheKey)) !== null) {
                $cached = WorkOrderOutput::with('defects.defectType')->find($outputId);
                if ($cached) return $cached;
            }
        }

        if ($wo->status !== WorkOrderStatus::InProgress) {
            throw new RuntimeException('Only in-progress work orders can record output.');
        }

        $good   = (int) ($data['good_count'] ?? 0);
        $reject = (int) ($data['reject_count'] ?? 0);
        $total  = $good + $reject;
        if ($total <= 0) {
            throw new RuntimeException('At least one of good_count or reject_count must be positive.');
        }

        $defects = $data['defects'] ?? [];
        $defectSum = 0;
        foreach ($defects as $d) {
            $defectSum += (int) ($d['count'] ?? 0);
        }
        if ($defectSum > $reject) {
            throw new RuntimeException("Sum of defect counts ({$defectSum}) cannot exceed reject_count ({$reject}).");
        }

        $output = DB::transaction(function () use ($wo, $data, $recordedBy, $good, $reject, $total, $defects) {
            // Lock + reload the WO so concurrent recordings don't lose increments.
            $fresh = WorkOrder::lockForUpdate()->find($wo->id);

            // Generate batch code: {wo}-B{seq}.
            $existing = $fresh->outputs()->count();
            $batchCode = sprintf('%s-B%02d', $fresh->wo_number, $existing + 1);

            $output = WorkOrderOutput::create([
                'work_order_id' => $fresh->id,
                'recorded_by'   => $recordedBy,
                'recorded_at'   => Carbon::now(),
                'good_count'    => $good,
                'reject_count'  => $reject,
                'shift'         => $data['shift'] ?? null,
                'batch_code'    => $batchCode,
                'remarks'       => $data['remarks'] ?? null,
            ]);

            foreach ($defects as $d) {
                if ((int) ($d['count'] ?? 0) <= 0) continue;
                $output->defects()->create([
                    'defect_type_id' => (int) $d['defect_type_id'],
                    'count'          => (int) $d['count'],
                ]);
            }

            $fresh->update([
                'quantity_produced' => (int) $fresh->quantity_produced + $total,
                'quantity_good'     => (int) $fresh->quantity_good + $good,
                'quantity_rejected' => (int) $fresh->quantity_rejected + $reject,
                'scrap_rate'        => (int) $fresh->quantity_produced + $total > 0
                    ? round((((int) $fresh->quantity_rejected + $reject) /
                            ((int) $fresh->quantity_produced + $total)) * 100, 2)
                    : 0,
            ]);

            // Bump mold shot count (may auto-flip mold→Maintenance at threshold).
            if ($fresh->mold_id) {
                $mold = Mold::find($fresh->mold_id);
                if ($mold) {
                    $this->molds->incrementShots($mold, $total);
                }
            }

            return $output->load('defects.defectType');
        });

        // Cache idempotency key (Redis or array driver — service container picks).
        if ($idempotencyKey) {
            Cache::put("production:idem:{$idempotencyKey}", $output->id, self::IDEMPOTENCY_TTL_SECONDS);
        }

        // Broadcast.
        WorkOrderOutputRecorded::dispatch($wo->fresh(), $output);

        return $output;
    }
}
