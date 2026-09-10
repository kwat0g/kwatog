<?php

declare(strict_types=1);

namespace App\Common\Middleware;

use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\SupplierPortalUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asserts that the user resolved under a B2B portal guard is actually the
 * portal model for that guard — never an internal {@see User}.
 *
 * Both portal guards are `session` drivers over their own providers, so a
 * portal session and the internal SPA session are distinct session keys that
 * cannot satisfy each other's guards. This middleware is the belt-and-braces
 * layer on top of that: it runs right after auth:<portal_guard> and rejects
 * anything whose resolved user is not the exact portal model, so a guard
 * misconfiguration or provider swap cannot turn a portal route into a crash
 * or a cross-guard authentication bleed.
 *
 * Usage: ->middleware('portal:customer_portal')
 */
class EnsurePortalGuard
{
    /** @var array<string, class-string> */
    private const MODELS = [
        'customer_portal' => CustomerPortalUser::class,
        'supplier_portal' => SupplierPortalUser::class,
    ];

    public function handle(Request $request, Closure $next, string $guard): Response
    {
        $expected = self::MODELS[$guard] ?? null;
        $user = $request->user($guard);

        if ($expected === null || ! $user instanceof $expected) {
            return response()->json([
                'message' => 'Unauthenticated.',
                'code' => 'portal_guard_mismatch',
            ], 401);
        }

        if (in_array($guard, ['customer_portal', 'supplier_portal'], true) && ! $user->is_active) {
            $user->tokens()->delete();

            return response()->json([
                'message' => 'Unauthenticated.',
                'code' => 'portal_account_inactive',
            ], 401);
        }

        return $next($request);
    }
}
