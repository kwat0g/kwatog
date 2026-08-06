<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Common\Services\ApprovalService;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\SalaryAdjustment;
use App\Modules\HR\Enums\SalaryAdjustmentStatus;
use App\Modules\HR\Requests\ActSalaryAdjustmentRequest;
use App\Modules\HR\Requests\RequestSalaryAdjustmentRequest;
use App\Modules\HR\Resources\SalaryAdjustmentResource;
use App\Modules\HR\Services\SalaryAdjustmentService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * REC-03 — maker-checker gate for salary changes. Requesting an adjustment
 * defers the pay change; it applies only when the salary_adjustment approval
 * chain is fully approved.
 */
class SalaryAdjustmentController
{
    public function __construct(
        private readonly SalaryAdjustmentService $service,
        private readonly ApprovalService $approvals,
    ) {}

    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'statuses' => array_map(static fn (SalaryAdjustmentStatus $status): array => ['value' => $status->value, 'label' => ucfirst($status->value)], SalaryAdjustmentStatus::cases()),
        ]]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = SalaryAdjustment::query()->with(['employee', 'requester']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return SalaryAdjustmentResource::collection(
            $query->latest()->paginate(min((int) $request->query('per_page', 25), 100)),
        );
    }

    public function show(SalaryAdjustment $salaryAdjustment): array
    {
        return [
            'data'  => new SalaryAdjustmentResource($salaryAdjustment->load(['employee', 'requester'])),
            'chain' => $this->approvals->chain($salaryAdjustment),
        ];
    }

    public function store(RequestSalaryAdjustmentRequest $request, Employee $employee): SalaryAdjustmentResource
    {
        $adjustment = $this->service->request($employee, $request->validated(), $request->user());

        return new SalaryAdjustmentResource($adjustment->load(['employee', 'requester']));
    }

    public function act(ActSalaryAdjustmentRequest $request, SalaryAdjustment $salaryAdjustment): SalaryAdjustmentResource
    {
        $updated = $request->validated('action') === 'approve'
            ? $this->service->approve($salaryAdjustment, $request->user(), $request->validated('remarks'))
            : $this->service->reject($salaryAdjustment, $request->user(), (string) $request->validated('remarks'));

        return new SalaryAdjustmentResource($updated);
    }
}
