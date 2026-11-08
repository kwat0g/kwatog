<?php

declare(strict_types=1);

namespace App\Modules\Leave\Services;

use App\Common\Services\OutboxService;
use App\Modules\Dashboard\Services\DashboardCacheGeneration;
use App\Modules\Dashboard\Services\EmployeeDashboardRealtimeService;
use App\Modules\Leave\Events\LeaveBalanceChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces balance changes into one post-commit refresh per transaction. */
final class LeaveBalanceRealtimeService
{
    public function stageForEmployee(int $employeeId): void
    {
        $this->stageRefreshes(static fn () => app(EmployeeDashboardRealtimeService::class)->stageForEmployee($employeeId));
    }

    public function stageForAllEmployees(): void
    {
        $this->stageRefreshes(static fn () => app(EmployeeDashboardRealtimeService::class)->stageForAllEmployees());
    }

    private function stageRefreshes(callable $stageEmployeeDashboard): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        // Call once per logical write transaction (bulk jobs stage after their
        // loop) so one dashboard generation and one outbox row cover all rows.
        DashboardCacheGeneration::touch();
        $stageEmployeeDashboard();

        app(OutboxService::class)->recordCoalesced(
            new LeaveBalanceChanged,
            'leave-balance-refresh:'.$transactionId,
        );
    }
}
