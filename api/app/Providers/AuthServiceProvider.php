<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    protected $policies = [];

    public function boot(): void
    {
        // System Admin role bypasses all gates.
        Gate::before(function (?User $user, string $ability) {
            if ($user?->role?->slug === 'system_admin') {
                return true;
            }
            return null;
        });

        // Permission slug as an ability — checks user.permissions cache.
        Gate::define('*', function (User $user, string $ability) {
            return $user->hasPermission($ability);
        });
    }
}
