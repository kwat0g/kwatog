<?php

declare(strict_types=1);

namespace App\Modules\Loans\Listeners;

use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\Loans\Enums\LoanType;
use App\Modules\Loans\Events\LoanSubmitted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyOnLoanSubmitted implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(LoanSubmitted $event): void
    {
        try {
            $loan = $event->loan->loadMissing('employee');
            $emp  = $loan->employee;
            if (! $emp) return;

            $typeLabel = $loan->loan_type === LoanType::CashAdvance
                ? 'Cash Advance'
                : 'Company Loan';

            $audience = User::query()
                ->whereHas('role', fn ($q) => $q->where('slug', 'finance_officer'))
                ->where('is_active', true)
                ->get();

            $this->notifications->send($audience, 'loans.submitted', [
                'title'       => "{$typeLabel} Request from {$emp->full_name}",
                'message'     => "\u{20B1}" . number_format((float) $loan->principal, 2) . " — awaiting Finance approval.",
                'link_to'     => "/hr/loans/{$loan->hash_id}",
                'entity_type' => 'employee_loan',
                'entity_id'   => $loan->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnLoanSubmitted failed', ['error' => $e->getMessage()]);
        }
    }
}
