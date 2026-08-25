<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Common\Support\HashIdFilter;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Enums\BudgetType;
use App\Modules\Accounting\Enums\BudgetStatus;
use App\Modules\Accounting\Resources\BudgetResource;
use App\Modules\Accounting\Resources\FiscalYearResource;
use App\Modules\Accounting\Services\BudgetEnforcementService;
use App\Modules\Accounting\Services\BudgetActualsSyncService;
use App\Modules\Accounting\Services\BudgetConsumptionService;
use App\Modules\Accounting\Services\BudgetService;
use App\Modules\HR\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class BudgetController extends Controller
{
    public function __construct(
        private readonly BudgetService $budgetService,
        private readonly BudgetEnforcementService $enforcementService,
        private readonly BudgetActualsSyncService $actualsSync,
        private readonly BudgetConsumptionService $consumption,
        private readonly SettingsService $settings,
    ) {}

    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'budget_types' => array_map(
                static fn (BudgetType $type): array => ['value' => $type->value, 'label' => $type->label()],
                BudgetType::cases(),
            ),
            'statuses' => array_map(
                static fn (BudgetStatus $status): array => ['value' => $status->value, 'label' => $status->label()],
                BudgetStatus::cases(),
            ),
            'warning_ratio_pct' => round($this->settings->requiredFloat('budget.warning_ratio', 0, 1) * 100, 1),
            'critical_ratio_pct' => round($this->settings->requiredFloat('budget.critical_ratio', 0, 1) * 100, 1),
            'exhausted_ratio_pct' => round($this->settings->requiredFloat('budget.exhausted_ratio', 0) * 100, 1),
        ]]);
    }

    /**
     * Decode HashID values on the incoming request so the rest of the
     * controller can stay numeric. Skips values that already look like
     * integers (Artisan, tests) or are missing.
     */
    private function decodeHashIds(Request $request): void
    {
        $merge = [];

        $fiscalYearId = $request->input('fiscal_year_id');
        if ($fiscalYearId !== null && $fiscalYearId !== '') {
            $merge['fiscal_year_id'] = $this->decodeId($fiscalYearId, FiscalYear::class, 'fiscal_year_id');
        }

        $departmentId = $request->input('department_id');
        if ($departmentId !== null && $departmentId !== '') {
            $merge['department_id'] = $this->decodeId($departmentId, Department::class, 'department_id');
        }

        $items = $request->input('line_items');
        if (is_array($items)) {
            $changed = false;
            foreach ($items as $idx => $line) {
                $accountId = $line['account_id'] ?? null;
                if ($accountId !== null && $accountId !== '') {
                    $items[$idx]['account_id'] = $this->decodeId($accountId, Account::class, "line_items.{$idx}.account_id");
                    $changed = true;
                }
            }
            if ($changed) {
                $merge['line_items'] = $items;
            }
        }

        if ($merge !== []) {
            $request->merge($merge);
        }
    }

    private function decodeId(mixed $value, string $modelClass, string $field): int
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $id = (int) $value;
            if ($id > 0) {
                return $id;
            }
        }

        $id = HashIdFilter::decode($value, $modelClass);
        if ($id === null || $id < 1) {
            throw ValidationException::withMessages([$field => 'The selected identifier is invalid.']);
        }

        return $id;
    }

    private function reportFiscalYearId(Request $request): ?int
    {
        if ($request->filled('fiscal_year_id')) {
            $id = (int) $request->input('fiscal_year_id');
            $request->validate(['fiscal_year_id' => 'required|exists:fiscal_years,id']);

            return $id;
        }

        return $this->budgetService->getCurrentFiscalYear()?->id;
    }

    /** List budgets. */
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
        if ($request->boolean('company_wide')) {
            $query->whereNull('department_id');
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
            'status' => ['nullable', Rule::in(array_map(static fn (BudgetStatus $status): string => $status->value, BudgetStatus::cases()))],
        ]);
        $perPage = (int) $request->input('per_page', 20);
        $budgets = $query->orderByDesc('created_at')->paginate($perPage);
        $this->consumption->hydrate(collect($budgets->items()));

        return response()->json([
            'success' => true,
            'data'    => BudgetResource::collection($budgets->items()),
            'error'   => null,
            'meta'    => [
                'page'     => $budgets->currentPage(),
                'current_page' => $budgets->currentPage(),
                'last_page' => $budgets->lastPage(),
                'per_page' => $budgets->perPage(),
                'total'    => $budgets->total(),
                'from' => $budgets->firstItem(),
                'to' => $budgets->lastItem(),
            ],
            'links' => [
                'first' => $budgets->url(1),
                'last' => $budgets->url($budgets->lastPage()),
                'prev' => $budgets->previousPageUrl(),
                'next' => $budgets->nextPageUrl(),
            ],
        ]);
    }

    /** Show a single budget with line items. */
    public function show(Budget $budget): JsonResponse
    {
        $budget->load(['fiscalYear', 'department', 'lineItems.account', 'submittedBy', 'approvedBy']);
        $this->consumption->hydrate(collect([$budget]));

        return response()->json([
            'success' => true,
            'data'    => new BudgetResource($budget),
            'error'   => null,
            'meta'    => null,
        ]);
    }

    /** Create a budget. */
    public function store(Request $request): JsonResponse
    {
        $this->decodeHashIds($request);

        $validated = $request->validate([
            'fiscal_year_id'           => 'required|exists:fiscal_years,id',
            'department_id'            => 'nullable|exists:departments,id',
            'budget_type'              => ['required', Rule::enum(BudgetType::class)],
            'name'                     => 'required|string|max:200',
            'line_items'               => 'required|array|min:1',
            'line_items.*.account_id'  => 'required|exists:accounts,id',
            'line_items.*.jan'         => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
            'line_items.*.feb'         => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
            'line_items.*.mar'         => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
            'line_items.*.apr'         => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
            'line_items.*.may'         => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
            'line_items.*.jun'         => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
            'line_items.*.jul'         => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
            'line_items.*.aug'         => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
            'line_items.*.sep'         => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
            'line_items.*.oct'         => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
            'line_items.*.nov'         => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
            'line_items.*.dec'         => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
        ]);

        $budget = $this->budgetService->create($validated, $validated['line_items']);

        return response()->json([
            'success' => true,
            'data'    => new BudgetResource($budget->load(['fiscalYear', 'department', 'lineItems.account'])),
            'error'   => null,
            'meta'    => null,
        ], 201);
    }

    /** Update a budget while it remains in draft status. */
    public function update(Request $request, Budget $budget): JsonResponse
    {
        $this->decodeHashIds($request);

        $validated = $request->validate([
            'fiscal_year_id' => 'sometimes|exists:fiscal_years,id',
            'department_id' => 'sometimes|nullable|exists:departments,id',
            'budget_type' => ['sometimes', Rule::enum(BudgetType::class)],
            'name' => 'sometimes|string|max:200',
            'line_items' => 'sometimes|array|min:1',
            'line_items.*.account_id' => 'required_with:line_items|exists:accounts,id',
            'line_items.*.jan' => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
            'line_items.*.feb' => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
            'line_items.*.mar' => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
            'line_items.*.apr' => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
            'line_items.*.may' => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
            'line_items.*.jun' => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
            'line_items.*.jul' => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
            'line_items.*.aug' => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
            'line_items.*.sep' => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
            'line_items.*.oct' => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
            'line_items.*.nov' => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
            'line_items.*.dec' => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
        ]);
        $budget = $this->budgetService->updateDraft($budget, $validated, array_key_exists('line_items', $validated) ? $validated['line_items'] : null);

        return response()->json([
            'success' => true,
            'data'    => new BudgetResource($budget),
            'error'   => null,
            'meta'    => null,
        ]);
    }

    /** Submit a budget for approval. */
    public function submit(Budget $budget): JsonResponse
    {
        $budget = $this->budgetService->submit($budget, (int) auth()->id());
        return response()->json(['success' => true, 'data' => new BudgetResource($budget), 'error' => null, 'meta' => null]);
    }

    /** Approve a budget. */
    public function approve(Budget $budget): JsonResponse
    {
        $budget = $this->budgetService->approve($budget, (int) auth()->id());
        return response()->json(['success' => true, 'data' => new BudgetResource($budget), 'error' => null, 'meta' => null]);
    }

    /** Close a budget. */
    public function close(Budget $budget): JsonResponse
    {
        $budget = $this->budgetService->close($budget);
        return response()->json(['success' => true, 'data' => new BudgetResource($budget), 'error' => null, 'meta' => null]);
    }



    /**
     * Budget overview (department summary).
     */
    public function overview(Request $request): JsonResponse
    {
        $this->decodeHashIds($request);

        $fiscalYearId = $this->reportFiscalYearId($request);
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

        $fiscalYearId = $this->reportFiscalYearId($request);
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

        // `amount` is forwarded to the enforcement gate as a decimal string and
        // compared there with bcmath, whose grammar is narrower than `numeric`:
        // '1e3' and ' 1' satisfy `numeric` but make bccomp raise ValueError. The
        // regex pins the wire format to a canonical peso figure so a malformed
        // amount is a 422 naming the field, not an HTTP 500 — and, unlike the
        // `(float)` cast this replaces, nothing coerces the value on the way in.
        $validated = $request->validate([
            'department_id' => 'required|exists:departments,id',
            'amount'        => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d+)?$/'],
            'fiscal_year_id' => 'nullable|exists:fiscal_years,id',
        ], [
            'amount.regex' => 'The amount must be a plain decimal figure, e.g. 1000 or 1000.50.',
        ]);

        [$canProceed, $level, $message] = $this->enforcementService->checkAvailability(
            (int) $validated['department_id'],
            (string) $validated['amount'],
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

    /**
     * Dispatch the SyncBudgetActuals job for a given fiscal year.
     *
     * POST /api/v1/budgets/sync-actuals
     * Permission: budgeting.manage
     */
    public function syncActuals(Request $request): JsonResponse
    {
        $this->decodeHashIds($request);

        $value = $request->input('fiscal_year_id');
        $fiscalYearId = $value === null || $value === '' ? null : (int) $value;

        $outbox = $this->actualsSync->request($fiscalYearId);
        $run = $this->actualsSync->runForOutbox((string) $outbox->getKey());

        return response()->json([
            'success' => true,
            'data'    => [
                'dispatched' => true,
                'outbox_id' => (string) $outbox->getKey(),
                'status' => $outbox->status,
                'run_id' => $run?->getKey(),
                'fiscal_year_id' => $run?->fiscalYear?->hash_id,
                'run_status' => $run?->status,
            ],
            'error'   => null,
            'meta'    => null,
        ], 202);
    }

    /** Return the durable status of the latest requested sync. */
    public function syncStatus(Request $request): JsonResponse
    {
        $this->decodeHashIds($request);
        $value = $request->input('fiscal_year_id');
        $fiscalYearId = $value === null || $value === '' ? null : (int) $value;
        $run = $this->actualsSync->latest($fiscalYearId)?->load('fiscalYear');

        return response()->json([
            'success' => true,
            'data' => $run ? [
                'id' => (string) $run->getKey(),
                'fiscal_year_id' => $run->fiscalYear?->hash_id,
                'status' => $run->status,
                'processed_lines' => $run->processed_lines,
                'total_lines' => $run->total_lines,
                'last_error' => $run->last_error,
                'queued_at' => $run->queued_at?->toISOString(),
                'started_at' => $run->started_at?->toISOString(),
                'completed_at' => $run->completed_at?->toISOString(),
                'failed_at' => $run->failed_at?->toISOString(),
            ] : null,
            'error' => null,
            'meta' => null,
        ]);
    }
}
