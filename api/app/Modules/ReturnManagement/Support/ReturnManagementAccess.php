<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Support;

use App\Modules\Auth\Models\User;

final class ReturnManagementAccess
{
    public static function customerReturnsOnly(?User $user): bool
    {
        return $user?->role?->slug === 'customer_service_officer'
            && ! $user->hasPermission('purchasing.po.create');
    }
}
