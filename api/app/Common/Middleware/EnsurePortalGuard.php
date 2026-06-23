<?php

declare(strict_types=1);

namespace App\Common\Middleware;

use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Edge\Models\EdgeDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asserts that the user resolved under a B2B portal guard is actually the
 * portal model for that guard — never an internal {@see \App\Modules\Auth\Models\User}.
 *
 * Why this exists: the portal guards use the `sanctum` driver, and
 * config('sanctum.guard') is ['web'] (required so the SPA's auth:sanctum
 * resolves the session user). A side effect is that on any first-party
 * STATEFUL request that carries the SPA session cookie but NO bearer token,
 * Sanctum's guard falls back to the web session user for EVERY sanctum guard —
 * including supplier_portal / customer_portal. That let an internal session
 * satisfy auth:customer_portal, after which the portal controllers hit
 * `$user->customer` (undefined on User) and 500'd — a crash AND a cross-guard
 * authentication bleed.
 *
 * This middleware runs right after auth:<portal_guard> and rejects anything
 * whose resolved user is not the exact portal model. Legitimate portal clients
 * authenticate with a Bearer token whose tokenable IS the portal model, so they
 * pass untouched.
 *
 * Usage: ->middleware('portal:customer_portal')
 */
class EnsurePortalGuard
{
    /** @var array<string, class-string> */
    private const MODELS = [
        'customer_portal' => CustomerPortalUser::class,
        'supplier_portal' => SupplierPortalUser::class,
        'edge_device'     => EdgeDevice::class,
    ];

    public function handle(Request $request, Closure $next, string $guard): Response
    {
        $expected = self::MODELS[$guard] ?? null;
        $user = $request->user($guard);

        if ($expected === null || ! $user instanceof $expected) {
            return response()->json([
                'message' => 'Unauthenticated.',
                'code'    => 'portal_guard_mismatch',
            ], 401);
        }

        return $next($request);
    }
}
