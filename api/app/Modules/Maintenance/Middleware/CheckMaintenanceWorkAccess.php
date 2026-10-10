<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Middleware;

use App\Common\Support\HashIdFilter;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Support\MaintenanceWorkAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CheckMaintenanceWorkAccess
{
    public function handle(Request $request, Closure $next, string $mode): Response
    {
        $user = $request->user();
        abort_unless($user, 401);
        $permission = $mode === 'read' ? 'maintenance.view' : 'maintenance.wo.complete';
        if ($user->can($permission) || ($mode === 'reconcile' && $user->can('maintenance.wo.assign'))) {
            return $next($request);
        }

        abort_unless($user->is_active && $user->can('maintenance.work.assigned'), 403);
        $order = $request->route('workOrder');
        if (! $order && $mode !== 'read') {
            abort_unless($request->filled('work_order_id'), 403);
            $order = MaintenanceWorkOrder::query()->findOrFail(
                HashIdFilter::decode($request->input('work_order_id'), MaintenanceWorkOrder::class));
        }
        if ($mode === 'read') {
            abort_if($order && ! MaintenanceWorkAccess::canRead($order, $user), 404);
        } else {
            abort_unless($order && MaintenanceWorkAccess::canRecord($order, $user), 403);
        }

        return $next($request);
    }
}
