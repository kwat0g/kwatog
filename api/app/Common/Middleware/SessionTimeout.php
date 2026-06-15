<?php

declare(strict_types=1);

namespace App\Common\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idle-session timeout: 15 min for `employee` role, 30 min for everyone else.
 * Updates last_activity on every authenticated request.
 */
class SessionTimeout
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        // Block all activity if password is expired, except change-password
        if ($user->must_change_password) {
            $allowedPaths = [
                'api/v1/auth/change-password',
                'api/v1/auth/user',
            ];
            $path = $request->path();
            if (! in_array($path, $allowedPaths, true)) {
                return response()->json([
                    'message' => 'Your password has expired. Please change it before proceeding.',
                    'code'    => 'password_expired',
                ], 403);
            }
        }

        $isEmployee = ($user->role?->slug ?? null) === 'employee';
        $minutes = $isEmployee ? 15 : 30;
        $lastActivity = $user->last_activity ? Carbon::parse($user->last_activity) : null;

        if ($lastActivity && $lastActivity->diffInMinutes(now()) >= $minutes) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'message' => 'Your session has expired due to inactivity.',
                'code'    => 'session_timeout',
            ], 401);
        }

        $user->forceFill(['last_activity' => now()])->saveQuietly();

        return $next($request);
    }
}
