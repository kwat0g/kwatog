<?php

declare(strict_types=1);

namespace App\Common\Middleware;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idle-session timeout. Durations are configurable via admin settings;
 * defaults: 15 min for `employee` role, 30 min for everyone else.
 * Refreshes last_activity at most once per minute for authenticated requests.
 */
class SessionTimeout
{
    public function __construct(private readonly SettingsService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Portal clients authenticate with a bearer token and their own
        // guards. Idle-session bookkeeping is only for the cookie-backed
        // internal SPA session; applying it to a portal token would reject
        // an otherwise valid portal request as an internal-user mismatch.
        if ($request->bearerToken()) {
            return $next($request);
        }

        // This middleware is also appended to the API group so security policy
        // cannot be accidentally omitted from a new module route. Public,
        // portal, and edge-device routes use different principals/policies.
        if (! $this->usesInternalSanctumGuard($request)) {
            return $next($request);
        }

        if ($request->attributes->get('_ogami_session_timeout_checked')) {
            return $next($request);
        }
        $request->attributes->set('_ogami_session_timeout_checked', true);

        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        // OGAMI audit DEFECT-3 — this middleware (and the auth:sanctum SPA stack
        // it guards) is for internal Users only. A B2B portal bearer token can
        // resolve under the sanctum guard with a SupplierPortalUser /
        // CustomerPortalUser principal, which has no role / must_change_password /
        // last_activity columns; writing last_activity on it threw a SQL 500.
        // Reject any non-User principal here with a clean 401.
        if (! $user instanceof User) {
            return response()->json([
                'message' => 'Unauthenticated.',
                'code' => 'guard_mismatch',
            ], 401);
        }

        // Block all activity if password is expired, except change-password
        if ($user->must_change_password) {
            $allowedPaths = [
                'api/v1/auth/change-password',
                'api/v1/auth/user',
                'api/v1/auth/logout',
            ];
            $path = $request->path();
            if (! in_array($path, $allowedPaths, true)) {
                return response()->json([
                    'message' => 'Your password has expired. Please change it before proceeding.',
                    'code' => 'password_expired',
                ], 403);
            }
        }

        $isEmployee = ($user->role?->slug ?? null) === 'employee';
        $minutes = $isEmployee
            ? $this->settings->requiredInt('security.session_timeout_employee', 1)
            : $this->settings->requiredInt('security.session_timeout_default', 1);
        $lastActivity = $user->last_activity ? Carbon::parse($user->last_activity) : null;

        if ($lastActivity && $lastActivity->diffInMinutes(now()) >= $minutes) {
            // `auth()` resolves Sanctum's RequestGuard on these routes, which
            // has no logout() method. The SPA identity lives on the web guard.
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json([
                'message' => 'Your session has expired due to inactivity.',
                'code' => 'session_timeout',
            ], 401);
        }

        // Avoid a database write on every polling/dashboard request while still
        // retaining minute-level idle-time accuracy.
        if (! $lastActivity || $lastActivity->lt(now()->subMinute())) {
            $user->forceFill(['last_activity' => now()])->saveQuietly();
        }

        return $next($request);
    }

    private function usesInternalSanctumGuard(Request $request): bool
    {
        if ($request->is('api/v1/edge/*')) {
            return false;
        }

        $route = $request->route();
        if (! is_object($route) || ! method_exists($route, 'gatherMiddleware')) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if ($middleware === 'auth:sanctum' || str_contains((string) $middleware, 'Authenticate:sanctum')) {
                return true;
            }
        }

        return false;
    }
}
