<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Controllers;

use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderResponse;
use App\Modules\Purchasing\Policies\PurchaseOrderAccessPolicy;
use App\Modules\Purchasing\Requests\AcceptPurchaseOrderResponseRequest;
use App\Modules\Purchasing\Requests\RejectPurchaseOrderResponseRequest;
use App\Modules\Purchasing\Resources\PurchaseOrderResponseResource;
use App\Modules\Purchasing\Services\SupplierResponseService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Request;

class PurchaseOrderResponseController
{
    public function __construct(
        private readonly SupplierResponseService $service,
        private readonly PurchaseOrderAccessPolicy $access,
    ) {}

    public function index(Request $request, PurchaseOrder $purchaseOrder): AnonymousResourceCollection
    {
        abort_unless($this->access->canView($request->user(), $purchaseOrder), 403, 'You do not have permission to view these responses.');

        $responses = $purchaseOrder->responses()
            ->with(['items', 'resolver:id,name'])
            ->orderByDesc('id')
            ->get();

        return PurchaseOrderResponseResource::collection($responses);
    }

    public function accept(AcceptPurchaseOrderResponseRequest $request, PurchaseOrderResponse $response): PurchaseOrderResponseResource
    {
        $this->assertVisibleTo($request, $response);
        $resolved = $this->service->resolve($response, $request->user(), 'accept', $request->validated()['notes'] ?? null);

        return new PurchaseOrderResponseResource($resolved);
    }

    public function reject(RejectPurchaseOrderResponseRequest $request, PurchaseOrderResponse $response): PurchaseOrderResponseResource
    {
        $this->assertVisibleTo($request, $response);
        $resolved = $this->service->resolve($response, $request->user(), 'reject', $request->validated()['reason']);

        return new PurchaseOrderResponseResource($resolved);
    }

    private function assertVisibleTo(Request $request, PurchaseOrderResponse $response): void
    {
        $po = $response->purchaseOrder;
        abort_unless($po !== null && $this->access->canView($request->user(), $po), 403, 'You do not have permission to act on this supplier response.');
    }
}
