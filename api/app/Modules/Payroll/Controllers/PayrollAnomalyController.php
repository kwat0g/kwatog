<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Controllers;

use App\Modules\Payroll\Models\PayrollAnomalyFlag;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Resources\PayrollAnomalyFlagResource;
use App\Modules\Payroll\Services\PayrollAnomalyService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PayrollAnomalyController
{
    public function __construct(private readonly PayrollAnomalyService $service) {}

    public function index(PayrollPeriod $period, Request $request): AnonymousResourceCollection
    {
        $q = PayrollAnomalyFlag::query()
            ->where('payroll_period_id', $period->id)
            ->with(['employee:id,employee_no,first_name,last_name', 'resolver:id,name', 'payroll:id']);

        if ($request->filled('is_resolved')) {
            $q->where('is_resolved', filter_var($request->input('is_resolved'), FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->filled('flag_type')) {
            $q->where('flag_type', $request->input('flag_type'));
        }

        $perPage = min((int) $request->input('per_page', 50), 200);
        return PayrollAnomalyFlagResource::collection(
            $q->orderByDesc('id')->paginate($perPage)
        );
    }

    public function resolve(PayrollAnomalyFlag $flag, Request $request): PayrollAnomalyFlagResource
    {
        $request->validate([
            'remarks' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        $flag = $this->service->resolve($flag, (int) $request->user()->id, (string) $request->input('remarks'));
        return new PayrollAnomalyFlagResource($flag->load(['resolver', 'employee', 'payroll']));
    }
}
