<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Requests\StoreCustomerRequest;
use App\Modules\Accounting\Requests\UpdateCustomerRequest;
use App\Modules\Accounting\Resources\CustomerResource;
use App\Modules\Accounting\Services\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerController
{
    public function __construct(private readonly CustomerService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return CustomerResource::collection($this->service->list($request->query()));
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
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(null, 204);
    }
}
