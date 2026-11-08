<?php

declare(strict_types=1);

namespace App\Modules\Admin\Observers;

use App\Modules\Admin\Services\AdminUserRealtimeService;
use App\Modules\Auth\Models\User;

/** Refreshes Admin Users readers when a User API representation changes. */
final class AdminUserRealtimeObserver
{
    private const DIRECTORY_FIELDS = [
        'name', 'email', 'role_id', 'employee_id', 'is_active',
        'must_change_password', 'last_activity', 'password_changed_at',
        'locked_until', 'theme_mode', 'deleted_at',
    ];

    public function created(User $user): void
    {
        app(AdminUserRealtimeService::class)->stage($user, 'created');
    }

    public function updated(User $user): void
    {
        if (! $user->wasChanged(self::DIRECTORY_FIELDS)) {
            return;
        }

        $action = $user->wasChanged(['is_active', 'role_id', 'must_change_password', 'locked_until'])
            ? 'status_changed'
            : 'updated';

        app(AdminUserRealtimeService::class)->stage($user, $action);
    }

    public function deleted(User $user): void
    {
        app(AdminUserRealtimeService::class)->stage($user, 'deleted');
    }

    public function restored(User $user): void
    {
        app(AdminUserRealtimeService::class)->stage($user, 'updated');
    }
}
