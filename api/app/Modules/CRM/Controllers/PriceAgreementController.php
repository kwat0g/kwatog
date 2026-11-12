<?php

declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\PriceAgreement;
use App\Modules\CRM\Requests\RequestPriceAgreementTermination;
use App\Modules\CRM\Requests\StorePriceAgreementRequest;
use App\Modules\CRM\Requests\UpdatePriceAgreementRequest;
use App\Modules\CRM\Resources\PriceAgreementResource;
use App\Modules\CRM\Services\PriceAgreementService;
use App\Modules\CRM\Services\PriceAgreementTerminationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PriceAgreementController
{
    public function __construct(
        private readonly PriceAgreementService $service,
        private readonly PriceAgreementTerminationService $terminations,
    ) {}

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
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return (new PriceAgreementResource($a))->response()->setStatusCode(201);
    }

    public function update(UpdatePriceAgreementRequest $request, PriceAgreement $priceAgreement): PriceAgreementResource|JsonResponse
    {
        try {
            $a = $this->service->update($priceAgreement, $request->validated());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new PriceAgreementResource($a);
    }

    public function terminationPreview(Request $request, PriceAgreement $priceAgreement): JsonResponse
    {
        try {
            $data = $request->validate(['last_valid_date' => ['nullable', 'date_format:Y-m-d']]);
            $preview = $this->terminations->preview($priceAgreement, $data['last_valid_date'] ?? null);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $preview]);
    }

    public function terminationHistory(PriceAgreement $priceAgreement): JsonResponse
    {
        return response()->json(['data' => $this->terminations->history($priceAgreement)]);
    }

    public function requestTermination(RequestPriceAgreementTermination $request, PriceAgreement $priceAgreement): PriceAgreementResource|JsonResponse
    {
        $actor = $request->user('sanctum');
        abort_unless($actor instanceof User, 401);
        try {
            $agreement = $this->terminations->request($priceAgreement, $request->validated(), $actor);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new PriceAgreementResource($agreement);
    }

    public function cancelTerminationRequest(Request $request, PriceAgreement $priceAgreement): PriceAgreementResource|JsonResponse
    {
        $data = $request->validate(['document_id' => ['required', 'string', 'max:30']]);
        $actor = $request->user('sanctum');
        abort_unless($actor instanceof User, 401);
        try {
            $agreement = $this->terminations->cancelRequest($priceAgreement, $data['document_id'], $actor);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new PriceAgreementResource($agreement);
    }

    public function destroy(PriceAgreement $priceAgreement): JsonResponse
    {
        $this->service->delete($priceAgreement);
        return response()->json(null, 204);
    }

    public function restore(PriceAgreement $priceAgreement): JsonResponse
    {
        try {
            $this->service->restore($priceAgreement);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['message' => 'Price agreement restored.']);
    }

    public function forCustomer(Customer $customer): AnonymousResourceCollection
    {
        return PriceAgreementResource::collection(
            $this->service->listForCustomer($customer->id),
        );
    }
}
