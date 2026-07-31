<?php

declare(strict_types=1);

namespace App\Common\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allows a request when the user has at least one of the supplied permissions.
 *
 * Usage: ->middleware('permission_any:leave.approve_dept,leave.approve_hr')
 */
class CheckAnyPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        abort_unless($user, 401);

        if ($user->role?->slug === 'system_admin') {
            return $next($request);
        }

        $allowed = method_exists($user, 'hasPermission')
            && collect($permissions)->contains(fn (string $permission) => $user->hasPermission($permission));

        abort_unless($allowed, 403, 'You do not have permission to perform this action.');

        return $next($request);
    }
}
