<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Listeners;

use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\Payroll\Events\PayrollPeriodFinalized;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Services\PayrollPublicationPolicy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NotifyEmployeesOnPayrollFinalized implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(PayrollPeriodFinalized $event): void
    {
        try {
            $period = $event->period->fresh();
            if (! app(PayrollPublicationPolicy::class)->isPeriodPublishable($period)) {
                return;
            }

            $payrolls = Payroll::query()
                ->where('payroll_period_id', $period->id)
                ->where(fn ($query) => $query->whereNull('error_message')->orWhere('error_message', ''))
                ->with('employee.user')
                ->get()
                ->filter(function (Payroll $payroll): bool {
                    $user = $payroll->employee?->user;

                    return $user !== null && $user->is_active && $user->hasPermission('payroll.view');
                })
                ->values();

            if ($payrolls->isEmpty()) {
                return;
            }

            // P01-03 — cross-call dedupe. A redelivered finalize event (the
            // listener failed after the insert committed, so the outbox row
            // returned to PENDING) must not stack a second "payslip ready"
            // inbox row per employee. Skip recipients who already hold this
            // period's notice.
            $periodHashId = $period->hash_id;
            $payrollHashIds = $payrolls->map(fn (Payroll $payroll): string => $payroll->hash_id);
            $alreadyNotified = DB::table('notifications')
                ->where('type', 'chain.payslip_ready')
                ->where('notifiable_type', User::class)
                ->whereIn('notifiable_id', $payrolls->map(fn (Payroll $payroll): int => (int) $payroll->employee->user->id))
                ->where(function ($query) use ($periodHashId, $payrollHashIds): void {
                    $query->whereRaw("data->>'entity_id' = ?", [$periodHashId])
                        ->orWhereIn(DB::raw("data->>'entity_id'"), $payrollHashIds);
                })
                ->distinct()
                ->pluck('notifiable_id');
            $periodLabel = $this->periodLabel($period);

            foreach ($payrolls as $payroll) {
                $user = $payroll->employee->user;
                if ($alreadyNotified->contains($user->id)) {
                    continue;
                }

                $this->notifications->sendForSubject($payroll, $user, 'chain.payslip_ready', [
                    'title' => 'Payslip Ready',
                    'message' => "Your payslip for {$periodLabel} is ready.",
                    'link_to' => "/self-service/payslips/{$payroll->hash_id}",
                    'entity_type' => 'payroll',
                    'entity_id' => $payroll->hash_id,
                ], "payroll-payslip-ready:{$payroll->id}");
            }
        } catch (\Throwable $e) {
            Log::warning('NotifyEmployeesOnPayrollFinalized failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function periodLabel(object $period): string
    {
        $start = $period->start_date ?? $period->period_start ?? null;
        $end = $period->end_date ?? $period->period_end ?? null;

        return $start && $end ? "{$start} – {$end}" : (string) ($period->name ?? 'Payroll Period');
    }
}
