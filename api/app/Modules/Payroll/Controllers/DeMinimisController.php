<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Controllers;

use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Enums\DeMinimisBenefitType;
use App\Modules\Payroll\Models\DeMinimisBenefit;
use App\Modules\Payroll\Requests\StoreDeMinimisBenefitRequest;
use App\Modules\Payroll\Resources\DeMinimisBenefitResource;
use App\Modules\Payroll\Services\DeMinimisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DeMinimisController
{
    public function __construct(private readonly DeMinimisService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = DeMinimisBenefit::query()->with('employee');

        if ($request->filled('employee_id')) {
            $id = Employee::tryDecodeHash((string) $request->query('employee_id'));
            $query->where('employee_id', $id ?? -1);
        }

        if ($request->filled('period_year')) {
            $query->where('period_year', (int) $request->query('period_year'));
        }

        if ($request->filled('period_month')) {
            $query->where('period_month', (int) $request->query('period_month'));
        }

        if ($request->filled('benefit_type')) {
            $query->where('benefit_type', $request->query('benefit_type'));
        }

        $entries = $query->orderByDesc('id')->paginate(20);

        return DeMinimisBenefitResource::collection($entries);
    }

    public function store(StoreDeMinimisBenefitRequest $request): JsonResponse
    {
        $data        = $request->validated();
        $employee    = $request->employee();
        $benefitType = DeMinimisBenefitType::from($data['benefit_type']);

        $benefit = $this->service->record(
            employee:    $employee,
            benefitType: $benefitType,
            amount:      (string) $data['amount'],
            periodYear:  (int) $data['period_year'],
            periodMonth: (int) $data['period_month'],
            notes:       $data['notes'] ?? null,
        );

        return (new DeMinimisBenefitResource($benefit->load('employee')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(DeMinimisBenefit $deMinimisBenefit): DeMinimisBenefitResource
    {
        return new DeMinimisBenefitResource($deMinimisBenefit->load('employee'));
    }

    public function destroy(DeMinimisBenefit $deMinimisBenefit): JsonResponse
    {
        $this->service->delete($deMinimisBenefit->id);

        return response()->json(['message' => 'Deleted.']);
    }
}
