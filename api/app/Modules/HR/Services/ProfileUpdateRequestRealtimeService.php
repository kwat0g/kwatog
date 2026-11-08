<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Events\ProfileUpdateRequestChanged;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\ProfileUpdateRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces a request and its scoped refresh metadata within a transaction. */
final class ProfileUpdateRequestRealtimeService
{
    public function stage(ProfileUpdateRequest $request, string $action): void
    {
        $employee = Employee::withTrashed()->with('department')->find($request->employee_id);
        $requester = $request->requested_by === null
            ? null
            : User::query()->find($request->requested_by);
        $actor = auth()->user();
        $actorHashId = $actor instanceof User && is_string($actor->hash_id) ? $actor->hash_id : null;
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(new ProfileUpdateRequestChanged(
            requestHashId: (string) $request->hash_id,
            employeeHashId: $employee?->hash_id,
            departmentHashId: $employee?->department?->hash_id,
            requesterHashId: $requester instanceof User && is_string($requester->hash_id) ? $requester->hash_id : null,
            requiresFinance: (bool) $request->requires_finance,
            status: (string) $request->status,
            action: $action,
            version: $request->updated_at?->format('Y-m-d\\TH:i:s.uP') ?? now()->format('Y-m-d\\TH:i:s.uP'),
            actorHashId: $actorHashId,
        ), "profile-update-request-refresh:{$request->getKey()}:{$transactionId}");
    }
}
