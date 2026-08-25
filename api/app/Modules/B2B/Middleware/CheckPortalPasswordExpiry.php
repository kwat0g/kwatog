<?php

declare(strict_types=1);

namespace App\Modules\B2B\Middleware;

use App\Common\Services\SettingsService;
use App\Modules\B2B\Models\CustomerPortalUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPortalPasswordExpiry
{
    public function __construct(private readonly SettingsService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('customer_portal');
        if (! $user instanceof CustomerPortalUser) {
            return $next($request);
        }

        // The first-login flag has its own middleware and response code. Keep
        // the identity and change-password endpoints reachable for both cases.
        if (in_array($request->path(), [
            'api/v1/b2b/customer/me',
            'api/v1/b2b/customer/change-password',
        ], true)) {
            return $next($request);
        }

        $expiryDays = $this->settings->requiredInt('security.password_expiry_days', 0, 3650);
        if ($expiryDays > 0
            && $user->password_changed_at !== null
            && $user->password_changed_at->lt(now()->subDays($expiryDays))) {
            return response()->json([
                'message' => 'Your portal password has expired. Please change it to continue.',
                'code' => 'password_expired',
            ], 403);
        }

        return $next($request);
    }
}
