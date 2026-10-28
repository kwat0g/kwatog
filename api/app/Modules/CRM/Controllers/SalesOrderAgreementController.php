<?php

declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderAgreement;
use App\Modules\CRM\Requests\PublishSalesOrderAgreementRequest;
use App\Modules\CRM\Services\SalesOrderAgreementService;
use App\Modules\Auth\Models\User;
use App\Modules\ReturnManagement\Models\ReturnCase;
use App\Modules\ReturnManagement\Support\ReturnManagementAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SalesOrderAgreementController
{
    public function __construct(private readonly SalesOrderAgreementService $service) {}

    public function index(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        if ($request->is('api/v1/b2b/customer/*')) {
            $portal = auth('customer_portal')->user();
            abort_unless($portal && (int) $portal->customer_id === (int) $salesOrder->customer_id, 403);
        } else {
            $internal = auth('sanctum')->user();
            $canView = $internal instanceof User && $internal->hasPermission('crm.sales_orders.view');
            abort_unless($canView || $this->hasReturnAccess($internal, $salesOrder), 403);
        }
        return response()->json(['data' => $this->service->summary($salesOrder, $request)]);
    }

    public function publish(PublishSalesOrderAgreementRequest $request, SalesOrder $salesOrder): JsonResponse
    {
        $this->service->publish($salesOrder, $request->user(), $request->validated('terms'), $request->file('file'));
        return response()->json(['data' => $this->service->summary($salesOrder->fresh(), $request)], 201);
    }

    public function acknowledgeExternal(Request $request, SalesOrder $salesOrder, SalesOrderAgreement $agreement): JsonResponse
    {
        $data = $request->validate(['representative' => ['required', 'string', 'max:200', 'regex:/\S/'],
            'acknowledged_at' => ['required', 'date', 'before_or_equal:now'], 'reference' => ['required', 'string', 'max:2000', 'regex:/\S/']]);
        $this->service->acknowledgeExternal($salesOrder, $agreement, $request->user(), $data);
        return response()->json(['data' => $this->service->summary($salesOrder->fresh(), $request)]);
    }

    public function acknowledgePortal(Request $request, SalesOrder $salesOrder, SalesOrderAgreement $agreement): JsonResponse
    {
        $request->validate(['acknowledged' => ['required', 'accepted']]);
        /** @var CustomerPortalUser $actor */
        $actor = auth('customer_portal')->user();
        abort_unless($actor && (int) $actor->customer_id === (int) $salesOrder->customer_id, 403);
        $this->service->acknowledgePortal($salesOrder, $agreement, $actor);
        return response()->json(['data' => $this->service->summary($salesOrder->fresh(), $request)]);
    }

    public function file(Request $request, SalesOrder $salesOrder, SalesOrderAgreement $agreement)
    {
        abort_unless((int) $agreement->sales_order_id === (int) $salesOrder->id, 404);
        $isPortal = $request->is('api/v1/b2b/customer/*');
        $portal = $isPortal ? auth('customer_portal')->user() : null;
        $internal = $isPortal ? null : auth('sanctum')->user();
        $canView = $isPortal
            ? ($portal && (int) $portal->customer_id === (int) $salesOrder->customer_id)
            : (($internal instanceof User && $internal->hasPermission('crm.sales_orders.view')) || $this->hasReturnAccess($internal, $salesOrder));
        abort_unless($canView && $agreement->file_path && Storage::disk('local')->exists($agreement->file_path), 404);
        return Storage::disk('local')->download($agreement->file_path, $agreement->file_name, ['X-Content-Type-Options' => 'nosniff']);
    }

    private function hasReturnAccess(?User $user, SalesOrder $order): bool
    {
        if (! $user || ! $user->hasPermission('return_management.view')) return false;
        $cases = ReturnCase::query()->where('type', 'customer')->whereHas('delivery', fn ($query) => $query->where('sales_order_id', $order->id));
        if (ReturnManagementAccess::customerReturnsOnly($user)) $cases->where('customer_id', $order->customer_id);
        return $cases->exists();
    }
}
