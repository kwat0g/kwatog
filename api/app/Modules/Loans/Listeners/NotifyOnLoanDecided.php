<?php

declare(strict_types=1);

namespace App\Modules\Loans\Listeners;

use App\Common\Services\NotificationService;
use App\Common\Services\CurrencyDisplayService;
use App\Modules\Loans\Enums\LoanType;
use App\Modules\Loans\Events\LoanDecided;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyOnLoanDecided implements ShouldQueue
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly ?CurrencyDisplayService $currency = null,
    ) {}

    public function handle(LoanDecided $event): void
    {
        try {
            $loan = $event->loan->loadMissing('employee.user');
            $user = $loan->employee?->user;

            if (! $user) {
                return;
            }

            $typeLabel = $loan->loan_type === LoanType::CashAdvance
                ? 'Cash Advance'
                : 'Company Loan';

            $label = $event->approved ? 'Approved' : 'Rejected';
            $type  = $event->approved ? 'loans.approved' : 'loans.rejected';

            $currency = $this->currency ?? app(CurrencyDisplayService::class);
            $this->notifications->send($user, $type, [
                'title'       => "{$typeLabel} Request {$label}",
                'message'     => "Your {$currency->format($loan->principal)} {$typeLabel} request was {$label}.",
                'link_to'     => "/self-service/loans/{$loan->hash_id}",
                'entity_type' => 'employee_loan',
                'entity_id'   => $loan->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnLoanDecided failed', ['error' => $e->getMessage()]);
        }
    }
}
