<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Services\SettingsService;
use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\Money;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Support\BudgetConsumptionLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class BudgetEnforcementService
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Check if a department has remaining budget for a given amount.
     * Returns [bool $canProceed, string $level, string $message].
     *
     * Levels are determined by the configured budget ratios.
     */
    public function checkAvailability(int $departmentId, string $amount, ?int $fiscalYearId = null): array
    {
        $fyId = $fiscalYearId ?? app(BudgetService::class)->getCurrentFiscalYear()?->id;
        if (! $fyId) {
            return [true, 'ok', 'No active fiscal year found.'];
        }

        $budgets = Budget::with('lineItems')
            ->byFiscalYear($fyId)
            ->byDepartment($departmentId)
            ->active()
            ->get();

        if ($budgets->isEmpty()) {
            return [true, 'ok', 'No active budget for this department.'];
        }

        // Exact sums. Collection::sum() on these columns coerces to float.
        $available = Money::zero();
        $spent = Money::zero();
        $committed = Money::zero();
        $allocated = Money::zero();
        foreach ($budgets as $budget) {
            $available = Money::add($available, $budget->available);
            $spent     = Money::add($spent, (string) $budget->total_spent);
            $committed = Money::add($committed, (string) $budget->total_committed);
            $allocated = Money::add($allocated, (string) $budget->total_allocated);
        }

        $currency = app(\App\Common\Services\CurrencyDisplayService::class);

        if (Money::lte($available, '0')) {
            return [false, BudgetConsumptionLevel::EXHAUSTED, 'Budget exhausted. No remaining available funds ('.$currency->format(0).' available).'];
        }

        if (Money::gt($amount, $available)) {
            return [false, BudgetConsumptionLevel::OVERDRAWN, 'Insufficient budget. Requested: '.$currency->format($amount)
                . ', Available: '.$currency->format($available).'.'];
        }

        $level = BudgetConsumptionLevel::classify(
            Money::add($spent, $committed, $amount),
            $allocated,
            [
                'warning'   => $this->settings->requiredFloat('budget.warning_ratio', 0, 1),
                'critical'  => $this->settings->requiredFloat('budget.critical_ratio', $this->settings->requiredFloat('budget.warning_ratio', 0, 1), 1),
                'exhausted' => $this->settings->requiredFloat('budget.exhausted_ratio', $this->settings->requiredFloat('budget.critical_ratio', 0, 1)),
                'overdrawn' => $this->settings->requiredFloat('budget.overdrawn_ratio', $this->settings->requiredFloat('budget.exhausted_ratio', 0)),
            ],
        );

        // DISPLAY ONLY, and deliberately plain float math: rounding it is
        // precisely what misclassified the 99.95%-99.99% band as exhausted, so
        // routing it through Money would imply a precision that must never
        // matter here. Guarded on $allocated because the available <= 0 check
        // above is NOT sufficient: available = allocated - spent - committed, so
        // a negative spent/committed gives available > 0 with allocated == 0,
        // and dividing there is a DivisionByZeroError on PHP 8.
        $pct = Money::isZero($allocated)
            ? 0.0
            : round(((float) Money::add($spent, $committed, $amount) / (float) $allocated) * 100, 1);

        return match ($level) {
            BudgetConsumptionLevel::OVERDRAWN => [false, $level, "Budget {$pct}% consumed. VP approval required."],
            BudgetConsumptionLevel::EXHAUSTED => [false, $level, "Budget {$pct}% consumed. Finance acknowledgment required."],
            BudgetConsumptionLevel::CRITICAL  => [false, $level, "Budget {$pct}% consumed. Finance acknowledgment required."],
            BudgetConsumptionLevel::WARNING   => [true, $level, "Budget {$pct}% consumed. Warning sent to department head."],
            default                           => [true, $level, "Budget within limits ({$pct}% consumed). ".$currency->format($available)." available."],
        };
    }

    public function assess(Model $document, int $departmentId, string $amount, ?int $fiscalYearId = null): array
    {
        [$canProceed, $level, $message] = $this->checkAvailability($departmentId, $amount, $fiscalYearId);
        $requiresAcknowledgment = in_array($level, ['exhausted', 'overdrawn'], true);

        $document->forceFill([
            'budget_warning_level'    => $level === 'ok' ? null : $level,
            'budget_warning_message'  => $level === 'ok' ? null : $message,
            'budget_acknowledged_by'  => null,
            'budget_acknowledged_at'  => null,
        ])->save();

        if ($this->enforcementMode() === 'block' && ! $canProceed) {
            // "Insufficient budget. Requested: ₱120,000.00, Available:
            // ₱4,300.00." is the single most actionable sentence in this
            // service — trim the request, transfer budget, or get the VP
            // approval it names. As a bare RuntimeException it depended on the
            // calling controller happening to wrap the call.
            throw new BusinessRuleException($message);
        }

        if ($requiresAcknowledgment) {
            Log::warning('Budget acknowledgment required', [
                'document_type' => $document->getMorphClass(),
                'document_id'   => $document->getKey(),
                'department_id' => $departmentId,
                'amount'        => $amount,
                'level'         => $level,
            ]);
        }

        return [$canProceed, $level, $message];
    }

    public function acknowledge(Model $document, \App\Modules\Auth\Models\User $user): Model
    {
        if (! in_array($document->budget_warning_level, ['exhausted', 'overdrawn'], true)) {
            throw new BusinessRuleException('This transaction does not require Finance acknowledgment.');
        }
        if (! $user->hasPermission('budgeting.approve')) {
            throw new BusinessRuleException('Finance authorization is required to acknowledge a budget overrun.');
        }

        $document->forceFill([
            'budget_acknowledged_by' => $user->id,
            'budget_acknowledged_at' => now(),
        ])->save();

        return $document->fresh();
    }

    public function assertAcknowledged(Model $document): void
    {
        if (in_array($document->budget_warning_level, ['exhausted', 'overdrawn'], true)
            && ! $document->budget_acknowledged_at) {
            throw new BusinessRuleException($document->budget_warning_message ?? 'Finance acknowledgment is required.');
        }
    }

    /**
     * Actively enforce the budget for a spend against a department, driven by the
     * `budgeting.enforcement_mode` config:
     *   - 'off'   (default) — no-op. Existing behaviour fully preserved.
     *   - 'warn'  — logs a warning when at/over the ceiling but allows through.
     *   - 'block' — throws BusinessRuleException when the spend hits
     *               'exhausted' or 'overdrawn' (100%+), which renders as a 422
     *               carrying the message. This used to be a bare
     *               RuntimeException that relied on the calling controller
     *               wrapping it; the ones that did not returned a 500 for a
     *               ceiling the user could have raised or worked under.
     *
     * Graceful by design: when no budget exists for the department/fiscal-year,
     * checkAvailability() returns canProceed=true and nothing is blocked.
     */
    public function enforce(int $departmentId, string $amount, ?int $fiscalYearId = null): void
    {
        $mode = $this->enforcementMode();
        if ($mode === 'off') {
            return;
        }

        [$canProceed, $level, $message] = $this->checkAvailability($departmentId, $amount, $fiscalYearId);

        // Only the 100%+ levels are hard limits; warning/critical are advisory.
        $isOverCeiling = in_array($level, ['exhausted', 'overdrawn'], true);
        if (! $isOverCeiling) {
            return;
        }

        if ($mode === 'warn') {
            Log::warning('Budget over ceiling (enforcement=warn, allowed)', [
                'department_id' => $departmentId,
                'amount'        => $amount,
                'level'         => $level,
                'message'       => $message,
            ]);
            return;
        }

        if ($mode === 'block') {
            throw new BusinessRuleException($message);
        }
    }

    private function enforcementMode(): string
    {
        $mode = $this->settings->requiredString('budgeting.enforcement_mode');
        if (! in_array($mode, ['off', 'warn', 'block'], true)) {
            throw new BusinessRuleException('Invalid budgeting enforcement mode.');
        }
        return $mode;
    }
}
