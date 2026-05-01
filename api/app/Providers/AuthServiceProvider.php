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
        // 1. System Admin role bypasses every gate.
        // 2. For any other ability that LOOKS LIKE a permission slug
        //    ({module}.{resource}.{action}), fall through to the user's
        //    permission cache. This lets us call `$user->can('hr.employees.view')`
        //    or `Gate::allows('hr.employees.view')` without registering each
        //    permission as an ability up-front.
        Gate::before(function (?User $user, string $ability) {
            if (! $user) {
                return null;
            }

            if ($user->role?->slug === 'system_admin') {
                return true;
            }

            // Permission-slug shape: lowercase, dot-separated, 3+ segments.
            if (preg_match('/^[a-z0-9_]+(?:\.[a-z0-9_]+){2,}$/', $ability) === 1) {
                return $user->hasPermission($ability);
            }

            return null;
        });
    }
}
