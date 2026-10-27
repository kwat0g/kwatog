<?php

declare(strict_types=1);

namespace App\Common\Support;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;

final class NotificationSubjectAccessRegistry
{
    public static function supports(string $class): bool
    {
        return $class === Invoice::class || ApprovalTypeRegistry::forClass($class) !== null;
    }

    public static function userCanViewRecord(User $user, Model $subject): bool
    {
        $class = $subject->getMorphClass();

        return match ($class) {
            // The route group rejects invoice access while Accounting is disabled.
            Invoice::class => app(SettingsService::class)->get('modules.accounting', false) === true
                && $user->hasPermission('accounting.invoices.view'),
            default => ApprovalTypeRegistry::forClass($class) !== null
                && ApprovalTypeRegistry::userCanViewRecord($user, $class, (int) $subject->getKey()),
        };
    }
}
