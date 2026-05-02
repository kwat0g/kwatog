<?php

declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Modules\Accounting\Models\Customer;
use App\Modules\CRM\Models\PriceAgreement;
use App\Modules\CRM\Requests\StorePriceAgreementRequest;
use App\Modules\CRM\Requests\UpdatePriceAgreementRequest;
use App\Modules\CRM\Resources\PriceAgreementResource;
use App\Modules\CRM\Services\PriceAgreementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

class PriceAgreementController
{
    public function __construct(private readonly PriceAgreementService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return PriceAgreementResource::collection($this->service->list($request->query()));
    }

    public function show(PriceAgreement $priceAgreement): PriceAgreementResource
    {
        return new PriceAgreementResource($this->service->show($priceAgreement));
    }

    public function store(StorePriceAgreementRequest $request): JsonResponse
    {
        try {
            $a = $this->service->create($request->validated());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return (new PriceAgreementResource($a))->response()->setStatusCode(201);
    }

    public function update(UpdatePriceAgreementRequest $request, PriceAgreement $priceAgreement): PriceAgreementResource|JsonResponse
    {
        try {
            $a = $this->service->update($priceAgreement, $request->validated());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new PriceAgreementResource($a);
    }

    public function destroy(PriceAgreement $priceAgreement): JsonResponse
    {
        $this->service->delete($priceAgreement);
        return response()->json(null, 204);
    }

    public function forCustomer(Customer $customer): AnonymousResourceCollection
    {
        return PriceAgreementResource::collection(
            $this->service->listForCustomer($customer->id)->load('product', 'customer'),
        );
    }
}
