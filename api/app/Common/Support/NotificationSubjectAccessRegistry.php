<?php

declare(strict_types=1);

namespace App\Common\Support;

use App\Common\Models\ApprovalDelegation;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Enums\BudgetStatus;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\BudgetTransfer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Services\BudgetService;
use App\Modules\Accounting\Services\BudgetTransferService;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\StockAdjustment;
use Illuminate\Database\Eloquent\Model;

final class NotificationSubjectAccessRegistry
{
    public static function supports(string $class): bool
    {
        return in_array($class, [Invoice::class, Bill::class, Budget::class, BudgetTransfer::class, StockAdjustment::class], true)
            || ApprovalTypeRegistry::forClass($class) !== null;
    }

    public static function userCanViewRecord(User $user, Model $subject): bool
    {
        $class = $subject->getMorphClass();

        return match ($class) {
            // The route group rejects invoice access while Accounting is disabled.
            Invoice::class => app(SettingsService::class)->get('modules.accounting', false) === true
                && $user->hasPermission('accounting.invoices.view'),
            Bill::class => app(SettingsService::class)->get('modules.accounting', false) === true
                && $user->hasPermission('accounting.bills.view'),
            StockAdjustment::class => app(SettingsService::class)->get('modules.inventory', false) === true
                && ($user->hasPermission('inventory.adjust') || $user->hasPermission('inventory.adjust.approve')),
            Budget::class => self::budgetingEnabled() && self::userCanViewBudget($user, $subject),
            BudgetTransfer::class => self::budgetingEnabled() && (
                $user->hasPermission('budgeting.view')
                || app(BudgetTransferService::class)->canApprove($subject, $user)
            ),
            default => ApprovalTypeRegistry::forClass($class) !== null
                && ApprovalTypeRegistry::userCanViewRecord($user, $class, (int) $subject->getKey()),
        };
    }

    private static function budgetingEnabled(): bool
    {
        $settings = app(SettingsService::class);

        return $settings->get('modules.accounting', false) === true
            && $settings->get('modules.budgeting', false) === true;
    }

    private static function userCanViewBudget(User $user, Model $subject): bool
    {
        if ($user->hasPermission('budgeting.view')) {
            return true;
        }

        return $subject->status === BudgetStatus::Submitted->value
            && (int) $subject->submitted_by !== (int) $user->getKey()
            && ApprovalDelegation::canActForPermission($user, 'budgeting.approve', [(int) $subject->submitted_by])
            && app(BudgetService::class)->hasDifferentRoleFromSubmitter($subject, $user);
    }
}
