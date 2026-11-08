<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Common\Services\OutboxService;
use App\Modules\CRM\Models\CustomerComplaint;
use App\Modules\CRM\Services\CustomerComplaintRealtimeService;
use App\Modules\Quality\Events\NcrChanged;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Production\Services\AssignedWorkOrderRefreshService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stages a single parent NCR refresh for all root/action writes in one transaction. */
final class NcrRealtimeService
{
    public function stage(NonConformanceReport $ncr, string $action): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $dedupeKey = "ncr-refresh:{$ncr->getKey()}:{$transactionId}";

        app(OutboxService::class)->recordCoalesced(new NcrChanged(
            ncrHashId: (string) $ncr->hash_id,
            action: $action,
            version: $ncr->updated_at?->toISOString() ?? now()->toISOString(),
            actorId: auth()->id() ? (int) auth()->id() : null,
            actorHashId: auth()->user()?->hash_id ? (string) auth()->user()->hash_id : null,
        ), $dedupeKey);

        // Complaint detail/list resources embed the linked NCR status and
        // disposition. Fan out a content-free complaint refresh in the same
        // transaction so those viewers do not need an NCR screen open.
        if ($ncr->complaint_id !== null) {
            $complaint = CustomerComplaint::query()->find((int) $ncr->complaint_id);
            if ($complaint !== null) {
                app(CustomerComplaintRealtimeService::class)->stageForRecord($complaint, 'updated');
            }
        }

        app(AssignedWorkOrderRefreshService::class)->stageForNcr($ncr);
    }
}
