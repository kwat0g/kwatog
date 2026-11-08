<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Services\OutboxService;
use App\Modules\Admin\Services\AdminUserRealtimeService;
use App\Modules\HR\Events\PositionChanged;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable, transaction-coalesced publisher for position mutations. */
final class PositionRealtimeService
{
    public function stage(Position $position, string $action): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(new PositionChanged(
            hashId: (string) $position->hash_id,
            action: $action,
            version: $position->updated_at?->format('Y-m-d\\TH:i:s.uP') ?? now()->format('Y-m-d\\TH:i:s.uP'),
            timestamp: now()->toISOString(),
        ), "position-refresh:{$position->getKey()}:{$transactionId}");

        if (Employee::query()->where('position_id', $position->getKey())->whereHas('user')->exists()) {
            app(AdminUserRealtimeService::class)->stageDirectoryRefresh();
        }
    }
}
