<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Controllers;

use App\Modules\Payroll\Jobs\ProcessPayrollJob;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Requests\CreatePayrollPeriodRequest;
use App\Modules\Payroll\Requests\RunThirteenthMonthRequest;
use App\Modules\Payroll\Resources\PayrollPeriodResource;
use App\Modules\Payroll\Services\PayrollPeriodService;
use App\Modules\Payroll\Services\ThirteenthMonthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PayrollPeriodController
{
    public function __construct(
        private readonly PayrollPeriodService $service,
        private readonly ThirteenthMonthService $thirteenthMonth,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return PayrollPeriodResource::collection($this->service->list($request->query()));
    }

    public function store(CreatePayrollPeriodRequest $request): JsonResponse
    {
        $period = $this->service->create($request->validated(), $request->user());
        return (new PayrollPeriodResource($period))->response()->setStatusCode(201);
    }

    public function show(PayrollPeriod $period): PayrollPeriodResource
    {
        $period = $this->service->show($period);
        $resource = new PayrollPeriodResource($period);
        return $resource->additional(['summary' => $this->service->summary($period)]);
    }

    public function compute(PayrollPeriod $period, Request $request): JsonResponse
    {
        ProcessPayrollJob::dispatch($period, $request->user()?->id);
        return (new PayrollPeriodResource($period->fresh()))
            ->response()
            ->setStatusCode(202);
    }

    public function approve(PayrollPeriod $period): PayrollPeriodResource
    {
        return new PayrollPeriodResource($this->service->approve($period));
    }

    public function finalize(PayrollPeriod $period): PayrollPeriodResource
    {
        $period = $this->service->finalize($period);
        // Dispatch GL posting job (Task 29). Wrapped in a class_exists check
        // so this controller still loads if PostPayrollToGlJob hasn't been
        // created yet.
        if (class_exists(\App\Modules\Payroll\Jobs\PostPayrollToGlJob::class)) {
            \App\Modules\Payroll\Jobs\PostPayrollToGlJob::dispatch($period);
        }
        return new PayrollPeriodResource($period);
    }

    public function bankFile(PayrollPeriod $period, Request $request)
    {
        if (! class_exists(\App\Modules\Payroll\Services\BankFileService::class)) {
            return response()->json(['message' => 'Bank file service not yet available.'], 503);
        }
        /** @var \App\Modules\Payroll\Services\BankFileService $svc */
        $svc = app(\App\Modules\Payroll\Services\BankFileService::class);
        return $svc->stream($period, $request->user());
    }

    public function runThirteenthMonth(RunThirteenthMonthRequest $request): JsonResponse
    {
        if (! method_exists($this->thirteenthMonth, 'computeAndPay')) {
            return response()->json(['message' => '13th month service not yet available.'], 503);
        }
        $period = $this->thirteenthMonth->computeAndPay(
            (int) $request->validated('year'),
            $request->user(),
            $request->validated('payroll_date'),
        );
        return (new PayrollPeriodResource($period))->response()->setStatusCode(201);
    }
}
