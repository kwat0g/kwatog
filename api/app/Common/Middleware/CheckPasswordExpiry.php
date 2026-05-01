<?php

declare(strict_types=1);

namespace App\Common\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces a password change if more than 90 days have elapsed since the
 * last change OR if `must_change_password` is true.
 *
 * The frontend Axios interceptor watches for 403 + code=password_expired
 * and redirects to /change-password.
 */
class CheckPasswordExpiry
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $expired = $user->must_change_password === true;

        if (! $expired && $user->password_changed_at) {
            $expired = Carbon::parse($user->password_changed_at)->lt(now()->subDays(90));
        }

        if ($expired) {
            return response()->json([
                'message' => 'Your password has expired. Please change it to continue.',
                'code'    => 'password_expired',
            ], 403);
        }

        return $next($request);
    }
}
