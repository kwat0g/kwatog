<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Events\EmployeeDashboardChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Coalesces employee dashboard refreshes by transaction and audience. */
final class EmployeeDashboardRealtimeService
{
    public function stageForEmployee(int $employeeId): void
    {
        $user = User::query()
            ->where('employee_id', $employeeId)
            ->where('is_active', true)
            ->first(['id']);
        $userHashId = $user?->hash_id;
        if (! is_string($userHashId) || $userHashId === '') {
            return;
        }

        $this->stage($userHashId);
    }

    public function stageForAllEmployees(): void
    {
        $this->stage(null);
    }

    private function stage(?string $employeeUserHashId): void
    {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $audience = $employeeUserHashId ?? 'all';

        app(OutboxService::class)->recordCoalesced(
            new EmployeeDashboardChanged($employeeUserHashId),
            "employee-dashboard-refresh:{$audience}:{$transactionId}",
        );
    }
}
