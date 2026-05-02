<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Controllers;

use App\Modules\Payroll\Models\PayrollAdjustment;
use App\Modules\Payroll\Requests\CreatePayrollAdjustmentRequest;
use App\Modules\Payroll\Requests\RejectPayrollAdjustmentRequest;
use App\Modules\Payroll\Resources\PayrollAdjustmentResource;
use App\Modules\Payroll\Services\PayrollAdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PayrollAdjustmentController
{
    public function __construct(private readonly PayrollAdjustmentService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return PayrollAdjustmentResource::collection($this->service->list($request->query()));
    }

    public function show(PayrollAdjustment $adjustment): PayrollAdjustmentResource
    {
        return new PayrollAdjustmentResource(
            $adjustment->load(['period', 'employee.department', 'originalPayroll', 'approver']),
        );
    }

    public function store(CreatePayrollAdjustmentRequest $request): JsonResponse
    {
        $adj = $this->service->create($request->validatedData(), $request->user());
        return (new PayrollAdjustmentResource($adj->load(['period', 'employee'])))
            ->response()
            ->setStatusCode(201);
    }

    public function approve(PayrollAdjustment $adjustment, Request $request): PayrollAdjustmentResource
    {
        return new PayrollAdjustmentResource(
            $this->service->approve($adjustment, $request->user())->load(['period', 'employee', 'approver']),
        );
    }

    public function reject(PayrollAdjustment $adjustment, RejectPayrollAdjustmentRequest $request): PayrollAdjustmentResource
    {
        return new PayrollAdjustmentResource(
            $this->service->reject($adjustment, $request->user(), $request->validated('remarks'))->load(['period', 'employee', 'approver']),
        );
    }
}
