<?php

declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderResponse;
use App\Modules\CRM\Requests\AcceptSalesOrderResponseRequest;
use App\Modules\CRM\Requests\RejectSalesOrderResponseRequest;
use App\Modules\CRM\Resources\SalesOrderResponseResource;
use App\Modules\CRM\Services\SalesOrderResponseService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SalesOrderResponseController
{
    public function __construct(private readonly SalesOrderResponseService $service) {}

    public function index(Request $request, SalesOrder $salesOrder): AnonymousResourceCollection
    {
        abort_unless($request->user()?->hasPermission('crm.sales_orders.view') ?? false, 403, 'You do not have permission to view these responses.');

        $responses = $salesOrder->responses()
            ->with(['items', 'resolver:id,name'])
            ->orderByDesc('id')
            ->get();

        return SalesOrderResponseResource::collection($responses);
    }

    public function accept(AcceptSalesOrderResponseRequest $request, SalesOrderResponse $response): SalesOrderResponseResource
    {
        $resolved = $this->service->resolve($response, $request->user(), 'accept', $request->validated()['notes'] ?? null);

        return new SalesOrderResponseResource($resolved);
    }

    public function reject(RejectSalesOrderResponseRequest $request, SalesOrderResponse $response): SalesOrderResponseResource
    {
        $resolved = $this->service->resolve($response, $request->user(), 'reject', $request->validated()['reason']);

        return new SalesOrderResponseResource($resolved);
    }
}
