<?php

declare(strict_types=1);

namespace App\Common\Middleware;

use App\Common\Models\ApprovalDelegation;
use Closure;
use Illuminate\Http\Request;

class CheckApprovalPermission
{
    public function handle(Request $request, Closure $next, string $permission): mixed
    {
        abort_unless(
            $request->user() !== null
                && ApprovalDelegation::canActForPermission($request->user(), $permission),
            403,
            'You do not have permission to make this approval decision.',
        );

        return $next($request);
    }
}
