<?php

declare(strict_types=1);

namespace App\Common\Middleware;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idle-session timeout. Durations and the short-timeout role set are
 * configurable via admin settings; defaults remain 15 min and 30 min.
 * Refreshes last_activity at most once per minute for authenticated requests.
 */
class SessionTimeout
{
    public function __construct(private readonly SettingsService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        // This middleware is also appended to the API group so security policy
        // cannot be accidentally omitted from a new module route. Public and
        // portal routes use different principals/policies.
        if (! $this->usesInternalSanctumGuard($request)) {
            return $next($request);
        }

        // Portal clients authenticate with a bearer token and their own guards.
        // Idle-session bookkeeping is only for the cookie-backed internal SPA
        // session; applying it to a portal principal would reject an otherwise
        // valid portal request as an internal-user mismatch, and those models
        // have no `last_activity` column to stamp.
        //
        // The skip keys off the RESOLVED PRINCIPAL, never off the mere presence
        // of an Authorization header. The header is entirely client-controlled,
        // so `if ($request->bearerToken())` alone let any cookie-authenticated
        // internal user opt out of the idle timeout indefinitely by sending
        // `Authorization: Bearer <anything>` — measured during the M001
        // re-audit as 25 min idle on a 15 min policy returning 200 with the
        // header and 401 without it. `$request->user()` resolves the default
        // `web` session guard, so a genuine portal token (which the web guard
        // cannot resolve) still short-circuits here exactly as before.
        if ($request->bearerToken() && ! ($request->user() instanceof User)) {
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

        $shortTimeoutRoles = $this->settings->get('security.session_timeout_short_roles');
        $shortTimeoutRoles = is_array($shortTimeoutRoles)
            ? array_values(array_filter($shortTimeoutRoles, 'is_string'))
            : ['employee'];
        $isShortTimeout = in_array((string) ($user->role?->slug ?? ''), $shortTimeoutRoles, true);
        $minutes = $isShortTimeout
            ? $this->settings->requiredInt('security.session_timeout_employee', 1)
            : $this->settings->requiredInt('security.session_timeout_default', 1);
        $sessionLastActivity = $request->hasSession()
            ? DB::table('sessions')
                ->where('id', $request->session()->getId())
                ->value('last_activity')
            : null;
        $lastActivity = $sessionLastActivity !== null
            ? Carbon::createFromTimestamp((int) $sessionLastActivity)
            : ($user->last_activity ? Carbon::parse($user->last_activity) : null);

        if ($lastActivity && $lastActivity->diffInMinutes(now(), true) >= $minutes) {
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
