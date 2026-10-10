<?php

declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Modules\CRM\Models\PriceRequest;
use App\Modules\CRM\Requests\PriceNegotiationRequest;
use App\Modules\CRM\Resources\PriceRequestResource;
use App\Modules\CRM\Services\PriceNegotiationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PriceNegotiationController
{
    public function __construct(private readonly PriceNegotiationService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return PriceRequestResource::collection($this->service->list());
    }

    public function show(Request $request, PriceRequest $priceRequest): PriceRequestResource
    {
        if ($request->user()->hasPermission('crm.price_agreements.approve')) {
            $review = $this->service->reviewCost($priceRequest->product);
            $priceRequest->setAttribute('cost_review', ['cost' => $review['cost'], 'incomplete' => $review['incomplete'], 'signature' => $review['signature'], 'minimum_price' => $priceRequest->product->minimum_selling_price, 'base_price' => $priceRequest->product->base_selling_price]);
        }

        return new PriceRequestResource($priceRequest->load(['product', 'customer']));
    }

    public function portalIndex(Request $request): AnonymousResourceCollection
    {
        return PriceRequestResource::collection($this->service->list($request->user('customer_portal')->customer_id));
    }

    public function store(PriceNegotiationRequest $request): JsonResponse
    {
        return (new PriceRequestResource($this->service->offer(null, $request->user(), $request->validated())))->response()->setStatusCode(201);
    }

    public function portalStore(PriceNegotiationRequest $request): JsonResponse
    {
        return (new PriceRequestResource($this->service->request($request->user('customer_portal'), $request->validated())))->response()->setStatusCode(201);
    }

    public function offer(PriceNegotiationRequest $request, PriceRequest $priceRequest): PriceRequestResource
    {
        return new PriceRequestResource($this->service->offer($priceRequest, $request->user(), $request->validated()));
    }

    public function approve(PriceNegotiationRequest $request, PriceRequest $priceRequest): PriceRequestResource
    {
        return new PriceRequestResource($this->service->approve($priceRequest, $request->user(), $request->validated()));
    }

    public function respond(PriceNegotiationRequest $request, PriceRequest $priceRequest): PriceRequestResource
    {
        return new PriceRequestResource($this->service->respond($priceRequest, $request->user('customer_portal'), $request->validated('decision'), (int) $request->validated('version')));
    }

    public function withdraw(PriceNegotiationRequest $request, PriceRequest $priceRequest): PriceRequestResource
    {
        return new PriceRequestResource($this->service->withdraw($priceRequest, $request->user(), $request->validated('notes')));
    }
}
