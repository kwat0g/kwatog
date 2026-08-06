<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Services\SettingsService;
use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Models\Budget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class BudgetEnforcementService
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Check if a department has remaining budget for a given amount.
     * Returns [bool $canProceed, string $level, string $message].
     *
     * Levels are determined by the configured budget ratios.
     */
    public function checkAvailability(int $departmentId, float $amount, ?int $fiscalYearId = null): array
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

        $available = $budgets->sum(fn ($b) => $b->available);
        $pct       = $available > 0
            ? round(($amount + ($budgets->sum('total_spent') + $budgets->sum('total_committed'))) / $budgets->sum('total_allocated') * 100, 1)
            : 0;

        if ($available <= 0) {
            return [false, 'exhausted', 'Budget exhausted. No remaining available funds ('.app(\App\Common\Services\CurrencyDisplayService::class)->format(0).' available).'];
        }

        if ($amount > $available) {
            return [false, 'overdrawn', 'Insufficient budget. Requested: '.app(\App\Common\Services\CurrencyDisplayService::class)->format($amount)
                . ', Available: '.app(\App\Common\Services\CurrencyDisplayService::class)->format($available).'.'];
        }

        $warning = $this->settings->requiredFloat('budget.warning_ratio', 0, 1);
        $critical = $this->settings->requiredFloat('budget.critical_ratio', $warning, 1);
        $exhausted = $this->settings->requiredFloat('budget.exhausted_ratio', $critical, null);
        $overdrawn = $this->settings->requiredFloat('budget.overdrawn_ratio', $exhausted, null);
        if ($pct / 100 >= $overdrawn) {
            return [false, 'overdrawn', "Budget {$pct}% consumed. VP approval required."];
        }

        if ($pct / 100 >= $exhausted) {
            return [false, 'exhausted', "Budget {$pct}% consumed. Finance acknowledgment required."];
        }

        if ($pct / 100 >= $critical) {
            return [false, 'critical', "Budget {$pct}% consumed. Finance acknowledgment required."];
        }

        if ($pct / 100 >= $warning) {
            return [true, 'warning', "Budget {$pct}% consumed. Warning sent to department head."];
        }

        return [true, 'ok', "Budget within limits ({$pct}% consumed). ".app(\App\Common\Services\CurrencyDisplayService::class)->format($available)." available."];
    }

    public function assess(Model $document, int $departmentId, float $amount, ?int $fiscalYearId = null): array
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
            throw new RuntimeException($message);
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
            throw new RuntimeException($document->budget_warning_message ?? 'Finance acknowledgment is required.');
        }
    }

    /**
     * Actively enforce the budget for a spend against a department, driven by the
     * `budgeting.enforcement_mode` config:
     *   - 'off'   (default) — no-op. Existing behaviour fully preserved.
     *   - 'warn'  — logs a warning when at/over the ceiling but allows through.
     *   - 'block' — throws RuntimeException when the spend hits 'exhausted' or
     *               'overdrawn' (100%+). Controllers translate this to HTTP 422.
     *
     * Graceful by design: when no budget exists for the department/fiscal-year,
     * checkAvailability() returns canProceed=true and nothing is blocked.
     */
    public function enforce(int $departmentId, float $amount, ?int $fiscalYearId = null): void
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
            throw new RuntimeException($message);
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
