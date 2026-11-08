<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Services;

use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\User;
use App\Modules\Maintenance\Events\MaintenanceWorkOrderChanged;
use App\Modules\Maintenance\Models\MaintenanceLog;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable, transaction-coalesced publisher for the maintenance WO aggregate. */
final class MaintenanceWorkOrderRealtimeService
{
    public function stage(MaintenanceWorkOrder $order, string $action): void
    {
        $userIds = collect([$order->assignee()->with('user')->first()?->user?->getKey()])
            ->merge(MaintenanceLog::query()
                ->where('work_order_id', $order->getKey())
                ->whereNotNull('logged_by')
                ->distinct()
                ->pluck('logged_by'))
            ->filter()
            ->unique()
            ->values();

        $viewerUserHashIds = $userIds->isEmpty()
            ? []
            : User::query()->whereKey($userIds)->where('is_active', true)->get()
                ->filter(static fn (User $user): bool => $user->hasPermission('maintenance.work.assigned'))
                ->map(static fn (User $user): string => (string) $user->hash_id)
                ->values()
                ->all();

        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(new MaintenanceWorkOrderChanged(
            hashId: (string) $order->hash_id,
            action: $action,
            version: now()->format('Y-m-d\TH:i:s.uP'),
            actorId: auth()->user()?->hash_id,
            viewerUserHashIds: $viewerUserHashIds,
            timestamp: now()->toISOString(),
        ), "maintenance-work-order-refresh:{$order->getKey()}:{$transactionId}");
    }
}
