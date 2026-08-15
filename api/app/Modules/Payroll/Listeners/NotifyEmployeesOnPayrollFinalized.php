<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Listeners;

use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\Payroll\Events\PayrollPeriodFinalized;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NotifyEmployeesOnPayrollFinalized implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(PayrollPeriodFinalized $event): void
    {
        try {
            $period = $event->period;

            $userIds = DB::table('payrolls')
                ->where('payroll_period_id', $period->id)
                ->join('employees', 'payrolls.employee_id', '=', 'employees.id')
                ->join('users', 'users.employee_id', '=', 'employees.id')
                ->pluck('users.id');

            if ($userIds->isEmpty()) return;

            // P01-03 — cross-call dedupe. A redelivered finalize event (the
            // listener failed after the insert committed, so the outbox row
            // returned to PENDING) must not stack a second "payslip ready"
            // inbox row per employee. Skip recipients who already hold this
            // period's notice.
            $periodHashId = method_exists($period, 'getHashIdAttribute') ? $period->hash_id : null;
            if ($periodHashId) {
                $alreadyNotified = DB::table('notifications')
                    ->where('type', 'chain.payslip_ready')
                    ->where('notifiable_type', User::class)
                    ->whereIn('notifiable_id', $userIds)
                    ->whereRaw("data->>'entity_id' = ?", [$periodHashId])
                    ->distinct()
                    ->pluck('notifiable_id');

                $userIds = $userIds->diff($alreadyNotified);
            }

            if ($userIds->isEmpty()) return;

            $users = User::query()->whereIn('id', $userIds)->where('is_active', true)->get();
            $periodLabel = $this->periodLabel($period);

            $this->notifications->send($users, 'chain.payslip_ready', [
                'title'       => 'Payslip Ready',
                'message'     => "Your payslip for {$periodLabel} is ready.",
                'link_to'     => '/self-service/payslips',
                'entity_type' => 'payroll_period',
                'entity_id'   => method_exists($period, 'getHashIdAttribute') ? $period->hash_id : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyEmployeesOnPayrollFinalized failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function periodLabel(object $period): string
    {
        $start = $period->start_date ?? $period->period_start ?? null;
        $end   = $period->end_date   ?? $period->period_end   ?? null;
        return $start && $end ? "{$start} – {$end}" : (string) ($period->name ?? 'Payroll Period');
    }
}
