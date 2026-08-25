<?php

declare(strict_types=1);

namespace App\Modules\Admin\Middleware;

use App\Common\Exceptions\ForbiddenActionException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSystemAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role?->slug !== 'system_admin') {
            throw new ForbiddenActionException('Only system administrators may manage permission overrides.');
        }

        return $next($request);
    }
}
