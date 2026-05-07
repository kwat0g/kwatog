<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Listeners;

use App\Modules\Auth\Models\User;
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

            // We notify all active users who had a payroll row this period.
            // Separated employees are skipped (their user.is_active=false +
            // employees.user_id may be null).
            $userIds = DB::table('payrolls')
                ->where('payroll_period_id', $period->id)
                ->join('employees', 'payrolls.employee_id', '=', 'employees.id')
                ->whereNotNull('employees.user_id')
                ->pluck('employees.user_id');
            if ($userIds->isEmpty()) return;

            $users = User::query()->whereIn('id', $userIds)->where('is_active', true)->get();
            $periodLabel = $this->periodLabel($period);
            $periodHashId = method_exists($period, 'getHashIdAttribute') ? $period->hash_id : null;

            foreach ($users as $user) {
                $user->notifications()->create([
                    'id'              => (string) Str::uuid(),
                    'type'            => 'chain.payslip_ready',
                    'notifiable_type' => $user::class,
                    'notifiable_id'   => $user->id,
                    'data'            => [
                        'period_id'   => $periodHashId,
                        'period_name' => $periodLabel,
                        'message'     => 'Your payslip is ready.',
                        'link'        => '/self-service/payslips',
                    ],
                    'read_at'         => null,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('NotifyEmployeesOnPayrollFinalized failed', ['error' => $e->getMessage()]);
        }
    }

    /** Defensive period label — payroll periods may carry start_date / end_date or pay_period_start / etc. */
    private function periodLabel(object $period): string
    {
        $start = $period->start_date ?? $period->period_start ?? null;
        $end   = $period->end_date   ?? $period->period_end   ?? null;
        return $start && $end ? "{$start} – {$end}" : (string) ($period->name ?? 'Payroll Period');
    }
}
