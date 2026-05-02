<?php

declare(strict_types=1);

namespace App\Modules\Loans\Controllers;

use App\Modules\HR\Models\Employee;
use App\Modules\Loans\Enums\LoanType;
use App\Modules\Loans\Models\EmployeeLoan;
use App\Modules\Loans\Requests\ApproveLoanRequest;
use App\Modules\Loans\Requests\RejectLoanRequest;
use App\Modules\Loans\Requests\StoreLoanRequest;
use App\Modules\Loans\Resources\EmployeeLoanResource;
use App\Modules\Loans\Services\AmortizationService;
use App\Modules\Loans\Services\LoanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LoanController
{
    public function __construct(
        private readonly LoanService $service,
        private readonly AmortizationService $amortization,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return EmployeeLoanResource::collection($this->service->list($request->query()));
    }

    public function store(StoreLoanRequest $request): JsonResponse
    {
        $d = $request->validatedData();
        try {
            $loan = $this->service->request(
                $d['employee_id'],
                LoanType::from($d['loan_type']),
                $d,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return (new EmployeeLoanResource($loan))->response()->setStatusCode(201);
    }

    public function show(EmployeeLoan $loan): EmployeeLoanResource
    {
        return new EmployeeLoanResource($this->service->show($loan));
    }

    public function approve(ApproveLoanRequest $request, EmployeeLoan $loan): EmployeeLoanResource
    {
        try {
            $loan = $this->service->approve($loan, $request->user(), $request->input('remarks'));
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }
        return new EmployeeLoanResource($loan);
    }

    public function reject(RejectLoanRequest $request, EmployeeLoan $loan): EmployeeLoanResource
    {
        try {
            $loan = $this->service->reject($loan, $request->user(), $request->input('reason'));
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }
        return new EmployeeLoanResource($loan);
    }

    public function cancel(EmployeeLoan $loan): EmployeeLoanResource
    {
        try {
            $loan = $this->service->cancel($loan);
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }
        return new EmployeeLoanResource($loan);
    }

    /** GET /loans/limits/{employee}?loan_type=... — used by the create form. */
    public function limits(Request $request, Employee $employee): JsonResponse
    {
        $type = LoanType::tryFrom((string) $request->query('loan_type'));
        abort_if(!$type, 422, 'Invalid loan_type.');
        $limits = $this->service->limitsFor($employee, $type);
        return response()->json(['data' => $limits]);
    }

    /** POST /loans/preview-amortization — returns schedule without persisting. */
    public function previewAmortization(Request $request): JsonResponse
    {
        $data = $request->validate([
            'principal'   => ['required', 'decimal:0,2', 'min:1'],
            'pay_periods' => ['required', 'integer', 'min:1', 'max:60'],
        ]);
        return response()->json([
            'data' => $this->amortization->generate((string) $data['principal'], (int) $data['pay_periods']),
        ]);
    }
}
