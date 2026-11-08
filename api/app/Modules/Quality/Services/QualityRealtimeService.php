<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Common\Services\OutboxService;
use App\Modules\Quality\Events\QualityRecordChanged;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\ItemQualityPlan;
use App\Modules\Production\Services\AssignedWorkOrderRefreshService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Common durable publisher for Quality entity and child-record mutations. */
final class QualityRealtimeService
{
    public function stage(string $entityType, Model $record, string $action): void
    {
        if (! in_array($entityType, ['calibration', 'ppap', 'inspection_spec', 'ncr_template', 'quality_plan'], true)) {
            throw new \InvalidArgumentException("Unsupported Quality realtime entity: {$entityType}");
        }

        // Quality plans are displayed as revisions beneath an inventory item.
        // Their channel identity is therefore the parent item, not a plan row.
        $channelRecord = $record instanceof ItemQualityPlan
            ? $record->item()->firstOrFail()
            : $record;

        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $dedupeKey = "quality-refresh:{$entityType}:{$channelRecord->getKey()}:{$transactionId}";

        app(OutboxService::class)->recordCoalesced(new QualityRecordChanged(
            entityType: $entityType,
            hashId: (string) $channelRecord->hash_id,
            action: $action,
            version: $record->updated_at?->toISOString() ?? now()->toISOString(),
            actorId: auth()->id() ? (int) auth()->id() : null,
            actorHashId: auth()->user()?->hash_id ? (string) auth()->user()->hash_id : null,
        ), $dedupeKey);

        if ($record instanceof InspectionSpec) {
            app(AssignedWorkOrderRefreshService::class)->stageForProduct((int) $record->product_id);
        }
    }
}
