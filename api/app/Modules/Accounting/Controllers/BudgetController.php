<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Common\Support\HashIdFilter;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Resources\BudgetResource;
use App\Modules\Accounting\Resources\FiscalYearResource;
use App\Modules\Accounting\Services\BudgetEnforcementService;
use App\Modules\Accounting\Services\BudgetService;
use App\Modules\HR\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class BudgetController extends Controller
{
    public function __construct(
        private readonly BudgetService $budgetService,
        private readonly BudgetEnforcementService $enforcementService,
    ) {}

    /**
     * Decode HashID values on the incoming request so the rest of the
     * controller can stay numeric. Skips values that already look like
     * integers (Artisan, tests) or are missing.
     */
    private function decodeHashIds(Request $request): void
    {
        $merge = [];

        $fiscalYearId = $request->input('fiscal_year_id');
        if (is_string($fiscalYearId) && $fiscalYearId !== '' && ! ctype_digit($fiscalYearId)) {
            $merge['fiscal_year_id'] = HashIdFilter::decode($fiscalYearId, FiscalYear::class);
        }

        $departmentId = $request->input('department_id');
        if (is_string($departmentId) && $departmentId !== '' && ! ctype_digit($departmentId)) {
            $merge['department_id'] = HashIdFilter::decode($departmentId, Department::class);
        }

        $items = $request->input('line_items');
        if (is_array($items)) {
            $changed = false;
            foreach ($items as $idx => $line) {
                $acctId = $line['account_id'] ?? null;
                if (is_string($acctId) && $acctId !== '' && ! ctype_digit($acctId)) {
                    $items[$idx]['account_id'] = HashIdFilter::decode($acctId, Account::class);
                    $changed = true;
                }
            }
            if ($changed) {
                $merge['line_items'] = $items;
            }
        }

        if (! empty($merge)) {
            $request->merge($merge);
        }
    }

    /**
     * List budgets.
     */
    public function index(Request $request): JsonResponse
    {
        $this->decodeHashIds($request);

        $query = Budget::with(['fiscalYear', 'department']);

        if ($request->filled('fiscal_year_id')) {
            $query->byFiscalYear((int) $request->input('fiscal_year_id'));
        }
        if ($request->filled('department_id')) {
            $query->byDepartment((int) $request->input('department_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $budgets = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => BudgetResource::collection($budgets->items()),
            'error'   => null,
            'meta'    => [
                'page'     => $budgets->currentPage(),
                'per_page' => $budgets->perPage(),
                'total'    => $budgets->total(),
            ],
        ]);
    }

    /**
     * Show a single budget with line items.
     */
    public function show(Budget $budget): JsonResponse
    {
        $budget->load(['fiscalYear', 'department', 'lineItems.account', 'submittedBy', 'approvedBy']);

        return response()->json([
            'success' => true,
            'data'    => new BudgetResource($budget),
            'error'   => null,
            'meta'    => null,
        ]);
    }

    /**
     * Create a budget.
     */
    public function store(Request $request): JsonResponse
    {
        $this->decodeHashIds($request);

        $validated = $request->validate([
            'fiscal_year_id' => 'required|exists:fiscal_years,id',
            'department_id'  => 'nullable|exists:departments,id',
            'budget_type'    => 'required|string|max:30',
            'name'           => 'required|string|max:200',
            'line_items'     => 'required|array|min:1',
            'line_items.*.account_id' => 'required|exists:accounts,id',
            'line_items.*.jan' => 'numeric|min:0',
            'line_items.*.feb' => 'numeric|min:0',
            'line_items.*.mar' => 'numeric|min:0',
            'line_items.*.apr' => 'numeric|min:0',
            'line_items.*.may' => 'numeric|min:0',
            'line_items.*.jun' => 'numeric|min:0',
            'line_items.*.jul' => 'numeric|min:0',
            'line_items.*.aug' => 'numeric|min:0',
            'line_items.*.sep' => 'numeric|min:0',
            'line_items.*.oct' => 'numeric|min:0',
            'line_items.*.nov' => 'numeric|min:0',
            'line_items.*.dec' => 'numeric|min:0',
        ]);

        $budget = $this->budgetService->create(
            $validated,
            $validated['line_items'],
        );

        return response()->json([
            'success' => true,
            'data'    => new BudgetResource($budget->load(['fiscalYear', 'department', 'lineItems.account'])),
            'error'   => null,
            'meta'    => null,
        ], 201);
    }

    /**
     * Update a budget (only while in draft status).
     */
    public function update(Request $request, Budget $budget): JsonResponse
    {
        $this->decodeHashIds($request);

        if ($budget->status !== 'draft') {
            return response()->json([
                'success' => false,
                'data'    => null,
                'error'   => 'Only draft budgets can be edited.',
                'meta'    => null,
            ], 422);
        }

        $validated = $request->validate([
            'budget_type' => 'sometimes|string|max:30',
            'name'        => 'sometimes|string|max:200',
        ]);

        $budget->update($validated);

        return response()->json([
            'success' => true,
            'data'    => new BudgetResource($budget->fresh()->load(['fiscalYear', 'department'])),
            'error'   => null,
            'meta'    => null,
        ]);
    }

    /**
     * Submit budget for approval.
     */
    public function submit(Budget $budget): JsonResponse
    {
        $this->budgetService->submit($budget, auth()->id());

        return response()->json(['success' => true, 'data' => null, 'error' => null, 'meta' => null]);
    }

    /**
     * Approve budget.
     */
    public function approve(Budget $budget): JsonResponse
    {
        $this->budgetService->approve($budget, auth()->id());

        return response()->json(['success' => true, 'data' => null, 'error' => null, 'meta' => null]);
    }

    /**
     * Close budget.
     */
    public function close(Budget $budget): JsonResponse
    {
        $this->budgetService->close($budget);

        return response()->json(['success' => true, 'data' => null, 'error' => null, 'meta' => null]);
    }

    /**
     * Budget overview (department summary).
     */
    public function overview(Request $request): JsonResponse
    {
        $this->decodeHashIds($request);

        $fiscalYearId = (int) $request->input('fiscal_year_id', $this->budgetService->getCurrentFiscalYear()?->id);
        if (! $fiscalYearId) {
            return response()->json([
                'success' => true,
                'data'    => [],
                'error'   => null,
                'meta'    => ['no_fiscal_year' => true],
            ]);
        }

        return response()->json([
            'success' => true,
            'data'    => $this->budgetService->overview($fiscalYearId),
            'error'   => null,
            'meta'    => null,
        ]);
    }

    /**
     * Budget vs Actual (P&L style).
     */
    public function budgetVsActual(Request $request): JsonResponse
    {
        $this->decodeHashIds($request);

        $fiscalYearId = (int) $request->input('fiscal_year_id', $this->budgetService->getCurrentFiscalYear()?->id);
        if (! $fiscalYearId) {
            return response()->json([
                'success' => true,
                'data'    => ['rows' => [], 'total_budgeted' => 0, 'total_actual' => 0, 'total_variance' => 0],
                'error'   => null,
                'meta'    => ['no_fiscal_year' => true],
            ]);
        }

        return response()->json([
            'success' => true,
            'data'    => $this->budgetService->budgetVsActual($fiscalYearId),
            'error'   => null,
            'meta'    => null,
        ]);
    }

    /**
     * Check budget availability for a department/amount.
     */
    public function checkAvailability(Request $request): JsonResponse
    {
        $this->decodeHashIds($request);

        $validated = $request->validate([
            'department_id' => 'required|exists:departments,id',
            'amount'        => 'required|numeric|min:0',
            'fiscal_year_id' => 'nullable|exists:fiscal_years,id',
        ]);

        [$canProceed, $level, $message] = $this->enforcementService->checkAvailability(
            (int) $validated['department_id'],
            (float) $validated['amount'],
            isset($validated['fiscal_year_id']) ? (int) $validated['fiscal_year_id'] : null,
        );

        return response()->json([
            'success' => true,
            'data'    => [
                'can_proceed' => $canProceed,
                'level'       => $level,
                'message'     => $message,
            ],
            'error'   => null,
            'meta'    => null,
        ]);
    }

    /**
     * List fiscal years.
     */
    public function fiscalYears(): JsonResponse
    {
        $years = FiscalYear::orderByDesc('year')->get();

        return response()->json([
            'success' => true,
            'data'    => FiscalYearResource::collection($years),
            'error'   => null,
            'meta'    => null,
        ]);
    }
}
