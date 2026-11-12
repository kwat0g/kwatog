<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Requests\StoreCustomerRequest;
use App\Modules\Accounting\Requests\UpdateCustomerRequest;
use App\Modules\Accounting\Resources\CustomerResource;
use App\Modules\Accounting\Support\CustomerInputSanitizer;
use App\Modules\Accounting\Services\CustomerService;
use App\Modules\Accounting\Services\StatementOfAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerController
{
    public function __construct(
        private readonly CustomerService $service,
        private readonly StatementOfAccountService $soa,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return CustomerResource::collection($this->service->list($request->query()));
    }

    public function checkName(Request $request): JsonResponse
    {
        $name = trim((string) $request->validate([
            'name' => ['required', 'string', 'max:200'],
        ])['name']);
        $normalizedName = mb_strtolower(CustomerInputSanitizer::normalizeText($name), 'UTF-8');

        $matchesQuery = Customer::query()
            ->whereRaw(CustomerInputSanitizer::NORMALIZED_NAME_SQL.' = ?', [$normalizedName]);
        $count = (clone $matchesQuery)->count();
        $matches = $matchesQuery
            ->orderBy('name')
            ->orderBy('id')
            ->limit(5)
            ->get(['id', 'name', 'code'])
            ->map(fn (Customer $customer): array => [
                'id' => $customer->hash_id,
                'name' => $customer->name,
                'code' => $customer->code,
            ])
            ->values();

        return response()->json([
            'data' => [
                'exists' => $count > 0,
                'count' => $count,
                'normalized_name' => $normalizedName,
                'matches' => $matches,
            ],
        ]);
    }

    public function show(Customer $customer): CustomerResource
    {
        $customer = $this->service->show($customer);
        $customer->setAttribute('credit_used', $this->service->creditUsed($customer));
        return new CustomerResource($customer);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = $this->service->create($request->validated());
        return (new CustomerResource($customer))->response()->setStatusCode(201);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): CustomerResource
    {
        $customer = $this->service->update($customer, $request->validated());
        return new CustomerResource($customer);
    }

    public function destroy(Customer $customer): JsonResponse
    {
        try {
            $this->service->delete($customer);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(null, 204);
    }

    public function restore(Customer $customer): JsonResponse
    {
        $customer->restore();
        return response()->json(['message' => 'Customer restored.']);
    }

    /**
     * GET /api/v1/accounting/customers/{customer}/statement-of-account
     * Generate a customer statement of account with running balance and aging.
     */
    public function statementOfAccount(Customer $customer, Request $request): JsonResponse
    {
        $asOf = $request->validate([
            'as_of' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ])['as_of'] ?? null;

        $result = $this->soa->forCustomer($customer, $asOf);

        return response()->json(['data' => $result]);
    }
}
