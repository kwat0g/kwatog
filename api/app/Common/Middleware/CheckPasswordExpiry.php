<?php

declare(strict_types=1);

namespace App\Common\Middleware;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces a password change when the configured maximum age has elapsed or
 * when `must_change_password` is true. An age of zero disables timed expiry.
 *
 * The frontend Axios interceptor watches for 403 + code=password_expired
 * and redirects to /change-password.
 */
class CheckPasswordExpiry
{
    public function __construct(private readonly SettingsService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->usesInternalSanctumGuard($request)) {
            return $next($request);
        }

        if ($request->attributes->get('_ogami_password_expiry_checked')) {
            return $next($request);
        }
        $request->attributes->set('_ogami_password_expiry_checked', true);

        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request);
        }

        if (in_array($request->path(), [
            'api/v1/auth/user',
            'api/v1/auth/change-password',
            'api/v1/auth/logout',
        ], true)) {
            return $next($request);
        }

        $expired = $user->must_change_password === true;

        $expiryDays = $this->settings->requiredInt('security.password_expiry_days', 0);
        if (! $expired && $expiryDays > 0 && $user->password_changed_at) {
            $expired = Carbon::parse($user->password_changed_at)->lt(now()->subDays($expiryDays));
        }

        if ($expired) {
            return response()->json([
                'message' => 'Your password has expired. Please change it to continue.',
                'code' => 'password_expired',
            ], 403);
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
