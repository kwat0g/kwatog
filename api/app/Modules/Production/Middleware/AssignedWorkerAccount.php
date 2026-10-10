<?php

declare(strict_types=1);

namespace App\Modules\Production\Middleware;

use App\Modules\Auth\Models\User;
use App\Modules\Production\Services\WorkOrderAssignmentService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AssignedWorkerAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user() instanceof User && $request->user()->is_active, 403);
        if (! $request->user()->hasPermission('production.wo.record')) {
            app(WorkOrderAssignmentService::class)->internal($request->user());
        }

        return $next($request);
    }
}
