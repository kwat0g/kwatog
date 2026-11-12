<?php

declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Modules\CRM\Models\PriceRequest;
use App\Modules\CRM\Models\PriceAgreement;
use App\Modules\CRM\Exceptions\PriceRequestStateConflictException;
use App\Modules\CRM\Requests\PriceNegotiationRequest;
use App\Modules\CRM\Resources\PriceRequestResource;
use App\Modules\CRM\Services\PriceNegotiationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class PriceNegotiationController
{
    public function __construct(private readonly PriceNegotiationService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return PriceRequestResource::collection($this->service->list(requestHashId: $request->query('request')));
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
        return PriceRequestResource::collection($this->service->list($request->user('customer_portal')->customer_id, $request->query('request')));
    }

    public function portalOpenIndex(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->openForCustomer((int) $request->user('customer_portal')->customer_id)]);
    }

    public function store(PriceNegotiationRequest $request): JsonResponse
    {
        return (new PriceRequestResource($this->service->offer(null, $request->user(), $request->validated())))->response()->setStatusCode(201);
    }

    public function replacementProposal(PriceNegotiationRequest $request, PriceAgreement $priceAgreement): JsonResponse
    {
        $terms = $request->validated();
        $startsBeforeAnotherAgreement = PriceAgreement::query()
            ->where('customer_id', $priceAgreement->customer_id)
            ->where('product_id', $priceAgreement->product_id)
            ->whereDate('effective_from', '>=', $terms['effective_from'])
            ->whereDate('effective_from', '<=', $terms['effective_to'])
            ->whereDate('effective_to', '>=', $terms['effective_from'])
            ->exists();

        if ($startsBeforeAnotherAgreement) {
            throw ValidationException::withMessages([
                'effective_from' => ['Choose a replacement date after the start of every current or scheduled agreement it overlaps.'],
            ]);
        }

        $terms['customer_id'] = $priceAgreement->customer_id;
        $terms['product_id'] = $priceAgreement->product_id;

        return (new PriceRequestResource(
            $this->service->offer(null, $request->user(), $terms),
        ))->response()->setStatusCode(201);
    }

    public function portalStore(PriceNegotiationRequest $request): JsonResponse
    {
        return (new PriceRequestResource($this->service->request($request->user('customer_portal'), $request->validated())))->response()->setStatusCode(201);
    }

    public function offer(PriceNegotiationRequest $request, PriceRequest $priceRequest): PriceRequestResource|JsonResponse
    {
        try {
            return new PriceRequestResource($this->service->offer($priceRequest, $request->user(), $request->validated(), $request->validated('version')));
        } catch (PriceRequestStateConflictException $exception) {
            return $this->conflict($exception);
        }
    }

    public function approve(PriceNegotiationRequest $request, PriceRequest $priceRequest): PriceRequestResource|JsonResponse
    {
        try {
            return new PriceRequestResource($this->service->approve($priceRequest, $request->user(), $request->validated()));
        } catch (PriceRequestStateConflictException $exception) {
            return $this->conflict($exception);
        }
    }

    public function respond(PriceNegotiationRequest $request, PriceRequest $priceRequest): PriceRequestResource|JsonResponse
    {
        try {
            return new PriceRequestResource($this->service->respond($priceRequest, $request->user('customer_portal'), $request->validated('decision'), (int) $request->validated('version')));
        } catch (PriceRequestStateConflictException $exception) {
            return $this->conflict($exception);
        }
    }

    public function portalCancel(PriceNegotiationRequest $request, PriceRequest $priceRequest): PriceRequestResource|JsonResponse
    {
        try {
            return new PriceRequestResource($this->service->cancelByCustomer(
                $priceRequest,
                $request->user('customer_portal'),
                $request->validated('version'),
            ));
        } catch (PriceRequestStateConflictException $exception) {
            return $this->conflict($exception);
        }
    }

    public function reject(PriceNegotiationRequest $request, PriceRequest $priceRequest): PriceRequestResource|JsonResponse
    {
        try {
            return new PriceRequestResource($this->service->rejectBySales(
                $priceRequest,
                $request->user(),
                $request->validated('notes'),
                $request->validated('version'),
            ));
        } catch (PriceRequestStateConflictException $exception) {
            return $this->conflict($exception);
        }
    }

    public function withdraw(PriceNegotiationRequest $request, PriceRequest $priceRequest): PriceRequestResource|JsonResponse
    {
        try {
            return new PriceRequestResource($this->service->withdraw($priceRequest, $request->user(), $request->validated('notes'), $request->validated('version')));
        } catch (PriceRequestStateConflictException $exception) {
            return $this->conflict($exception);
        }
    }

    private function conflict(PriceRequestStateConflictException $exception): JsonResponse
    {
        return response()->json(['message' => $exception->getMessage(), 'code' => $exception->errorCode()], 409);
    }
}
