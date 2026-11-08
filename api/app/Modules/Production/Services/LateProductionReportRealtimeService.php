<?php

declare(strict_types=1);

namespace App\Modules\Production\Services;

use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Events\LateProductionReportChanged;
use App\Modules\Production\Models\LateProductionReport;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces report and related work-order refreshes per record and transaction. */
final class LateProductionReportRealtimeService
{
    public function stage(LateProductionReport $report, string $action): void
    {
        $workOrder = WorkOrder::query()->find((int) $report->work_order_id);
        if ($workOrder === null) {
            return;
        }

        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $actor = auth()->user();
        $submitterHashId = User::query()->find((int) $report->recorded_by)?->hash_id;
        $recipients = app(AssignedWorkOrderAudience::class)->userHashIdsFor($workOrder);
        if (is_string($submitterHashId) && $submitterHashId !== '') {
            $recipients[] = $submitterHashId;
        }

        app(OutboxService::class)->recordCoalesced(new LateProductionReportChanged(
            workOrderHashId: (string) $workOrder->hash_id,
            reportHashId: (string) $report->hash_id,
            action: $action,
            version: $report->updated_at?->format('Y-m-d\TH:i:s.uP') ?? now()->format('Y-m-d\TH:i:s.uP'),
            actorHashId: $actor instanceof User ? (string) $actor->hash_id : null,
            recipientUserHashIds: array_values(array_unique($recipients)),
        ), "late-production-report-refresh:{$report->getKey()}:{$transactionId}");
    }
}
