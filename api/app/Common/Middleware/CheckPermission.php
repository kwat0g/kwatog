<?php

declare(strict_types=1);

namespace App\Common\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aborts with 403 if the authenticated user lacks the named permission.
 *
 * Usage: ->middleware('permission:hr.employees.view')
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        abort_unless($user, 401);

        if ($user->role && $user->role->slug === 'system_admin') {
            return $next($request);
        }

        abort_unless(method_exists($user, 'hasPermission') && $user->hasPermission($permission), 403, 'You do not have permission to perform this action.');

        return $next($request);
    }
}
