<?php

declare(strict_types=1);

namespace App\Modules\Loans\Listeners;

use App\Common\Services\NotificationService;
use App\Common\Services\CurrencyDisplayService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Loans\Enums\LoanType;
use App\Modules\Loans\Events\LoanSubmitted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyOnLoanSubmitted implements ShouldQueue
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly ?SettingsService $settings = null,
        private readonly ?CurrencyDisplayService $currency = null,
    ) {}

    public function handle(LoanSubmitted $event): void
    {
        try {
            $loan = $event->loan->loadMissing('employee');
            $emp  = $loan->employee;
            if (! $emp) return;

            $typeLabel = $loan->loan_type === LoanType::CashAdvance
                ? 'Cash Advance'
                : 'Company Loan';

            $roles = array_values(array_filter((array) ($this->settings ?? app(SettingsService::class))->get('loans.submitted.notification_roles', []), static fn ($role): bool => is_string($role) && $role !== ''));
            $audience = User::query()
                ->whereHas('role', fn ($q) => $q->whereIn('slug', $roles))
                ->where('is_active', true)
                ->get();

            $currency = $this->currency ?? app(CurrencyDisplayService::class);
            $this->notifications->send($audience, 'loans.submitted', [
                'title'       => "{$typeLabel} Request from {$emp->full_name}",
                'message'     => $currency->format($loan->principal) . " — awaiting Finance approval.",
                'link_to'     => "/hr/loans/{$loan->hash_id}",
                'entity_type' => 'employee_loan',
                'entity_id'   => $loan->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnLoanSubmitted failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
