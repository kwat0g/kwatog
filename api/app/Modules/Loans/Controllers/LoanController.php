<?php

declare(strict_types=1);

namespace App\Modules\Loans\Controllers;

use App\Common\Support\HashIdFilter;
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
use Illuminate\Support\Facades\Validator;

class LoanController
{
    public function __construct(
        private readonly LoanService $service,
        private readonly AmortizationService $amortization,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return EmployeeLoanResource::collection($this->service->list($request->query(), $request->user()));
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

    public function show(EmployeeLoan $loan, Request $request): EmployeeLoanResource
    {
        $user = $request->user();
        $isAdmin = $user?->role?->slug === 'system_admin';
        $canApprove = $user?->hasPermission('loans.approve') ?? false;

        if (! $isAdmin && ! $canApprove) {
            $isOwn = (int) $loan->employee?->user_id === (int) $user?->id;
            $isDeptMember = false;
            $isDeptHead = $user?->role?->slug === 'department_head';
            if ($isDeptHead && $user?->employee_id) {
                $deptId = \App\Modules\HR\Models\Employee::query()
                    ->whereKey($user->employee_id)->value('department_id');
                $isDeptMember = (int) $loan->employee?->department_id === (int) $deptId;
            }
            if (! $isOwn && ! $isDeptMember) {
                abort(403, 'You do not have permission to view this loan.');
            }
        }

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

    /**
     * T1.7 — Bulk approve loan applications.
     * Body: { ids: ["hashId1", ...], remarks?: string }
     */
    public function bulkApprove(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'ids'     => 'required|array|min:1',
            'ids.*'   => 'string',
            'remarks' => 'nullable|string|max:500',
        ])->validate();

        $ids = array_filter(array_map(
            fn ($hash) => HashIdFilter::decode($hash, EmployeeLoan::class),
            $validated['ids'],
        ));
        if (empty($ids)) {
            return response()->json(['message' => 'No valid loan IDs provided.'], 422);
        }

        $results = $this->service->bulkApprove($ids, $request->user(), $validated['remarks'] ?? null);

        return response()->json([
            'data' => [
                'approved' => array_map(fn ($r) => (new EmployeeLoanResource($r))->toArray($request), $results['approved']),
                'failed'   => $results['failed'],
            ],
        ]);
    }
}
