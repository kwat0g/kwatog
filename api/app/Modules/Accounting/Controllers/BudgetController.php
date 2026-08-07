<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Common\Support\HashIdFilter;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Jobs\SyncBudgetActuals;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Enums\BudgetType;
use App\Modules\Accounting\Enums\BudgetStatus;
use App\Modules\Accounting\Resources\BudgetResource;
use App\Modules\Accounting\Resources\FiscalYearResource;
use App\Modules\Accounting\Services\BudgetEnforcementService;
use App\Modules\Accounting\Services\BudgetService;
use App\Modules\HR\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class BudgetController extends Controller
{
    public function __construct(
        private readonly BudgetService $budgetService,
        private readonly BudgetEnforcementService $enforcementService,
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

    /**
     * Dispatch the SyncBudgetActuals job for a given fiscal year.
     *
     * POST /api/v1/budgets/sync-actuals
     * Permission: budgeting.manage
     */
    public function syncActuals(Request $request): JsonResponse
    {
        $this->decodeHashIds($request);

        $fiscalYearId = $request->input('fiscal_year_id');
        if (is_string($fiscalYearId) && $fiscalYearId !== '' && ctype_digit($fiscalYearId)) {
            $fiscalYearId = (int) $fiscalYearId;
        } elseif ($fiscalYearId !== null) {
            $fiscalYearId = null;
        }

        SyncBudgetActuals::dispatch($fiscalYearId);

        return response()->json([
            'success' => true,
            'data'    => ['dispatched' => true],
            'error'   => null,
            'meta'    => null,
        ], 202);
    }
}
