<?php

declare(strict_types=1);

namespace App\Modules\B2B\Middleware;

use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\SupplierPortalUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPortalPasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (($user instanceof CustomerPortalUser || $user instanceof SupplierPortalUser)
            && $user->must_change_password) {
            return response()->json([
                'message' => 'You must change your temporary password before continuing.',
                'code' => 'password_change_required',
            ], 403);
        }

        return $next($request);
    }
}
