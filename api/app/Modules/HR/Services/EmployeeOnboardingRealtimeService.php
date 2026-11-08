<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Services\OutboxService;
use App\Modules\HR\Events\EmployeeOnboardingChanged;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeOnboarding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stages one refresh-only onboarding event per employee and transaction. */
final class EmployeeOnboardingRealtimeService
{
    public function stage(EmployeeOnboarding $onboarding, string $action): void
    {
        $this->stageForEmployee(
            (int) $onboarding->employee_id,
            $action,
            $onboarding->updated_at?->format('Y-m-d\\TH:i:s.uP'),
        );
    }

    public function stageForEmployee(int $employeeId, string $action, ?string $version = null): void
    {
        if ($employeeId <= 0) {
            return;
        }

        $employee = Employee::query()->whereKey($employeeId)->first(['id']);
        if ($employee === null) {
            return;
        }

        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(new EmployeeOnboardingChanged(
            employeeHashId: (string) $employee->hash_id,
            action: $action,
            version: $version ?? now()->format('Y-m-d\\TH:i:s.uP'),
        ), "employee-onboarding-refresh:{$employeeId}:{$transactionId}");
    }
}
