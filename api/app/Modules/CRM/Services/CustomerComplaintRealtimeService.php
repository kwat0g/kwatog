<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Common\Services\OutboxService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\CRM\Events\CustomerComplaintChanged;
use App\Modules\CRM\Models\Complaint8DReport;
use App\Modules\CRM\Models\CustomerComplaint;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stages one durable complaint refresh per complaint and database transaction. */
final class CustomerComplaintRealtimeService
{
    public function stageForRecord(Model $record, string $action): void
    {
        $complaint = $record instanceof CustomerComplaint
            ? $record
            : ($record instanceof Complaint8DReport ? $record->complaint()->first() : null);

        if (! $complaint instanceof CustomerComplaint) {
            return;
        }

        $customer = $complaint->relationLoaded('customer')
            ? $complaint->getRelation('customer')
            : $complaint->customer()->first();
        if (! $customer instanceof Customer) {
            return;
        }

        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $dedupeKey = "customer-complaint-refresh:{$complaint->getKey()}:{$transactionId}";

        app(OutboxService::class)->recordCoalesced(new CustomerComplaintChanged(
            complaintHashId: (string) $complaint->hash_id,
            customerHashId: (string) $customer->hash_id,
            action: $action,
            version: $record->updated_at?->toISOString() ?? $complaint->updated_at?->toISOString() ?? now()->toISOString(),
            actorId: auth()->id() ? (int) auth()->id() : null,
        ), $dedupeKey);
    }
}
