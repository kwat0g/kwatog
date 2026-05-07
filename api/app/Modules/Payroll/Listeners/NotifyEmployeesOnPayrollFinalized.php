<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Listeners;

use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Events\PayrollPeriodFinalized;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Series C — Task C3. After Finance finalises a payroll period, notify
 * each employee that their payslip is ready. The payslip generation
 * itself is handled by the existing PayrollPeriodService::finalize()
 * pipeline (GL post + bank file).
 *
 * Idempotent: notification rows are appended; duplicates are visually
 * noisy but harmless. The bell UI dedups by `data.period_id` if needed.
 *
 * Best-effort.
 */
class NotifyEmployeesOnPayrollFinalized implements ShouldQueue
{
    public function handle(PayrollPeriodFinalized $event): void
    {
        try {
            $period = $event->period;

            // We notify all active employees who had a payroll row this
            // period. This avoids a notification flood when a separated
            // employee's account is already deactivated.
            $userIds = DB::table('payrolls')
                ->where('payroll_period_id', $period->id)
                ->join('employees', 'payrolls.employee_id', '=', 'employees.id')
                ->whereNotNull('employees.user_id')
                ->pluck('employees.user_id');

            foreach ($userIds as $userId) {
                DB::table('notifications')->insert([
                    'id'              => (string) Str::uuid(),
                    'type'            => 'chain.payslip_ready',
                    'notifiable_type' => Employee::class === Employee::class ? \App\Modules\Auth\Models\User::class : '',
                    'notifiable_id'   => (int) $userId,
                    'data'            => json_encode([
                        'period_id'   => $period->hash_id ?? null,
                        'period_name' => "{$period->start_date} – {$period->end_date}",
                        'message'     => 'Your payslip is ready.',
                        'link'        => '/self-service/payslips',
                    ]),
                    'read_at'         => null,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('NotifyEmployeesOnPayrollFinalized failed', ['error' => $e->getMessage()]);
        }
    }
}
