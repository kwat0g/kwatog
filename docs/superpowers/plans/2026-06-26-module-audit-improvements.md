# Module Audit Improvements — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the 10 highest-impact improvements identified by the comprehensive module audit — closing chain gaps, completing thin modules, and adding high-value UX features.

**Architecture:** All improvements follow existing modular-monolith conventions: thin controllers, service-layer business logic, FormRequest authorization, API Resources with hash_id. Frontend follows PATTERNS.md: DataTable, FilterBar, Zod forms, TanStack Query, lazy-loaded pages behind AuthGuard + ModuleGuard + PermissionGuard.

**Tech Stack:** Laravel 11 (PHP 8.3), React 18 + TypeScript + Vite, TanStack Query, Zustand, Zod, Recharts, Lucide icons, Tailwind CSS.

## Global Constraints

- Migration numbering: next = `0247_`, increment per migration
- Money fields: `decimal(15,2)` — NEVER float
- Every model: `HasHashId` trait, API Resource returns `hash_id`
- Every financial mutation: `DB::transaction()`
- Every FormRequest: `authorize()` checks permission
- Frontend numbers: `font-mono tabular-nums`
- Status fields: `<Chip>` with semantic variant
- Never use Bearer tokens — HTTP-only cookies only
- Follow PATTERNS.md exactly — copy and adapt, don't improvise

---

### Task 1: Return Management — Disposition Workflow + NCR Auto-Link + Credit Memo

**Files:**
- Create: `api/database/migrations/0247_add_disposition_fields_to_return_requests.php`
- Create: `api/app/Modules/ReturnManagement/Enums/DispositionType.php`
- Modify: `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php` — add `dispose()` method
- Modify: `api/app/Modules/ReturnManagement/Models/ReturnRequest.php` — add disposition fields to fillable/casts
- Modify: `api/app/Modules/ReturnManagement/Resources/ReturnRequestResource.php` — expose new fields
- Create: `api/app/Modules/ReturnManagement/Requests/DisposeReturnRequest.php`
- Modify: `api/app/Modules/ReturnManagement/Controllers/ReturnRequestController.php` — add `dispose()` action
- Modify: `api/app/Modules/ReturnManagement/routes.php` — add POST route
- Modify: `spa/src/types/returnManagement.ts` — add disposition types
- Modify: `spa/src/api/returnManagement.ts` — add dispose API call
- Create: `spa/src/pages/return-management/dispose.tsx` — disposition dialog/form
- Create: `api/tests/Feature/ReturnManagement/DispositionTest.php`

**Interfaces:**
- Consumes: `ReturnRequestService::inspect()`, `NcrService::create()`, `InvoiceService` (for credit memo)
- Produces: `ReturnRequestService::dispose(ReturnRequest $rma, array $data, User $by): ReturnRequest` — sets disposition per item (scrap/rework/restock), auto-creates NCR if quality-related, auto-creates credit memo invoice if customer return

**Context for implementer:**

The Return Management module currently has a full lifecycle (Draft → Approved → Received → Inspected → Completed) but the "Inspected → Completed" jump skips a critical business step: deciding WHAT to do with the returned goods. This task adds a `dispose()` step between inspect and complete.

Read these files before implementing:
- `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php` — existing full service with lifecycle methods
- `api/app/Modules/ReturnManagement/Enums/ReturnRequestStatus.php` — existing statuses
- `api/app/Modules/ReturnManagement/Models/ReturnRequest.php` — existing model
- `api/app/Modules/Quality/Services/NcrService.php` — to create NCR on quality-related returns
- `api/app/Modules/Accounting/Services/InvoiceService.php` — to understand credit memo creation pattern
- `docs/PATTERNS.md` — for migration, enum, service, controller patterns

- [ ] **Step 1: Create DispositionType enum**

```php
<?php
// api/app/Modules/ReturnManagement/Enums/DispositionType.php
declare(strict_types=1);

namespace App\Modules\ReturnManagement\Enums;

enum DispositionType: string
{
    case Scrap             = 'scrap';
    case Rework            = 'rework';
    case Restock           = 'restock';
    case ReturnToSupplier  = 'return_to_supplier';

    public function label(): string
    {
        return match ($this) {
            self::Scrap            => 'Scrap',
            self::Rework           => 'Rework',
            self::Restock          => 'Restock',
            self::ReturnToSupplier => 'Return to Supplier',
        };
    }
}
```

- [ ] **Step 2: Create migration adding disposition fields**

```php
<?php
// api/database/migrations/0247_add_disposition_fields_to_return_requests.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_request_items', function (Blueprint $table) {
            $table->string('disposition', 30)->nullable()->after('condition');
            $table->text('disposition_notes')->nullable()->after('disposition');
            $table->foreignId('ncr_id')->nullable()->constrained('non_conformance_reports')->after('disposition_notes');
        });

        Schema::table('return_requests', function (Blueprint $table) {
            $table->foreignId('credit_memo_id')->nullable()->constrained('invoices')->after('inspection_id');
            $table->string('disposition_status', 20)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('return_request_items', function (Blueprint $table) {
            $table->dropForeign(['ncr_id']);
            $table->dropColumn(['disposition', 'disposition_notes', 'ncr_id']);
        });
        Schema::table('return_requests', function (Blueprint $table) {
            $table->dropForeign(['credit_memo_id']);
            $table->dropColumn(['credit_memo_id', 'disposition_status']);
        });
    }
};
```

- [ ] **Step 3: Run migration**

```bash
cd api && php artisan migrate
```

- [ ] **Step 4: Update ReturnRequest model — add new fields to fillable/casts**

Add to `$fillable`: `'credit_memo_id'`, `'disposition_status'`
Add to ReturnRequestItem model `$fillable`: `'disposition'`, `'disposition_notes'`, `'ncr_id'`

- [ ] **Step 5: Add `dispose()` method to ReturnRequestService**

After `inspect()` method, add the `dispose()` method that:
1. Validates status is `Inspected`
2. For each item, sets disposition (scrap/rework/restock/return_to_supplier)
3. If any item disposition is scrap or rework AND reason is quality-related → auto-create NCR via `NcrService::create()`
4. If customer return → auto-create a credit memo (negative invoice) linking to the original invoice
5. Updates RMA `disposition_status` = 'disposed'
6. Allows proceeding to `complete()`

Key logic:
```php
public function dispose(ReturnRequest $rma, array $dispositions, User $by): ReturnRequest
{
    $this->ensureStatus($rma, ReturnRequestStatus::Inspected);

    return DB::transaction(function () use ($rma, $dispositions, $by) {
        foreach ($rma->items as $item) {
            $disp = collect($dispositions)->firstWhere('item_id', $item->hash_id);
            if (!$disp) continue;

            $item->update([
                'disposition' => $disp['disposition'],
                'disposition_notes' => $disp['notes'] ?? null,
            ]);

            // Auto-NCR for quality issues
            if (in_array($disp['disposition'], ['scrap', 'rework']) && $item->product_id) {
                $ncrService = app(\App\Modules\Quality\Services\NcrService::class);
                $ncr = $ncrService->create([
                    'source' => 'customer_complaint',
                    'product_id' => $item->product_id,
                    'quantity_affected' => (int) ($item->returned_quantity ?: $item->quantity),
                    'description' => "Auto-created from RMA {$rma->rma_number}. Disposition: {$disp['disposition']}. " . ($disp['notes'] ?? ''),
                    'disposition' => $disp['disposition'],
                    'entity_type' => 'return_request',
                    'entity_id' => $rma->id,
                ], $by);
                $item->update(['ncr_id' => $ncr->id]);
            }
        }

        $rma->update(['disposition_status' => 'disposed']);

        // Auto credit memo for customer returns
        if ($rma->type === ReturnRequestType::CustomerReturn && $rma->invoice_id) {
            $creditTotal = $rma->items->sum(fn ($i) => (float) $i->total);
            if ($creditTotal > 0) {
                $creditMemo = $this->createCreditMemo($rma, $creditTotal, $by);
                $rma->update(['credit_memo_id' => $creditMemo->id]);
            }
        }

        return $rma->fresh()->load('items');
    });
}

private function createCreditMemo(ReturnRequest $rma, float $amount, User $by): \App\Modules\Accounting\Models\Invoice
{
    $sequences = app(DocumentSequenceService::class);
    return \App\Modules\Accounting\Models\Invoice::create([
        'invoice_number' => $sequences->generate('invoice'),
        'customer_id' => $rma->customer_id,
        'type' => 'credit_memo',
        'status' => 'finalized',
        'subtotal' => -abs($amount),
        'vat_amount' => -abs(round($amount * 0.12, 2)),
        'total_amount' => -abs(round($amount * 1.12, 2)),
        'balance' => -abs(round($amount * 1.12, 2)),
        'date' => now()->toDateString(),
        'due_date' => now()->toDateString(),
        'notes' => "Credit memo for RMA {$rma->rma_number}",
        'created_by' => $by->id,
    ]);
}
```

- [ ] **Step 6: Add FormRequest for disposition**

```php
<?php
// api/app/Modules/ReturnManagement/Requests/DisposeReturnRequest.php
declare(strict_types=1);

namespace App\Modules\ReturnManagement\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DisposeReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('return_management.return_requests.dispose');
    }

    public function rules(): array
    {
        return [
            'dispositions' => ['required', 'array', 'min:1'],
            'dispositions.*.item_id' => ['required', 'string'],
            'dispositions.*.disposition' => ['required', 'string', 'in:scrap,rework,restock,return_to_supplier'],
            'dispositions.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
```

- [ ] **Step 7: Add controller action + route**

Add to `ReturnRequestController`:
```php
public function dispose(DisposeReturnRequest $request, ReturnRequest $returnRequest): ReturnRequestResource
{
    return new ReturnRequestResource(
        $this->service->dispose($returnRequest, $request->validated()['dispositions'], $request->user())
    );
}
```

Add to `routes.php`:
```php
Route::post('return-requests/{returnRequest}/dispose', [ReturnRequestController::class, 'dispose'])
    ->middleware('permission:return_management.return_requests.dispose');
```

- [ ] **Step 8: Update API Resource to include new fields**

Add to `ReturnRequestResource::toArray()`: `'credit_memo_id'`, `'disposition_status'`
Add to item resource: `'disposition'`, `'disposition_notes'`, `'ncr_id'`

- [ ] **Step 9: Update frontend types and API**

Add to `spa/src/types/returnManagement.ts`:
```typescript
export type DispositionType = 'scrap' | 'rework' | 'restock' | 'return_to_supplier';

export interface DispositionPayload {
  item_id: string;
  disposition: DispositionType;
  notes?: string;
}
```

Add to `spa/src/api/returnManagement.ts`:
```typescript
dispose: (id: string, dispositions: DispositionPayload[]) =>
  client.post<{ data: ReturnRequest }>(`/return-requests/${id}/dispose`, { dispositions }),
```

- [ ] **Step 10: Create disposition dialog component on detail page**

Create `spa/src/pages/return-management/dispose.tsx` — a modal with a table of items, each row has a `<Select>` for disposition type (scrap/rework/restock/return_to_supplier) and a notes textarea. Submit button calls dispose API. Follow PATTERNS.md form pattern with Zod validation.

- [ ] **Step 11: Write tests**

Create `api/tests/Feature/ReturnManagement/DispositionTest.php`:
- `test_dispose_sets_item_dispositions`
- `test_dispose_creates_ncr_for_scrap_items`
- `test_dispose_creates_credit_memo_for_customer_return`
- `test_dispose_rejects_non_inspected_rma`
- `test_dispose_requires_permission`

- [ ] **Step 12: Run tests**

```bash
cd api && php artisan test --filter=DispositionTest
```

- [ ] **Step 13: Commit**

```bash
git add -A && git commit -m "feat(return-management): add disposition workflow with NCR auto-link + credit memo"
```

---

### Task 2: Forecast Accuracy Tracking + Reconciliation Dashboard

**Files:**
- Create: `api/app/Modules/Forecasting/Controllers/ForecastAccuracyController.php`
- Create: `api/app/Modules/Forecasting/Resources/ForecastAccuracyResource.php`
- Create: `api/app/Console/Commands/ReconcileForecastActuals.php`
- Modify: `api/app/Modules/Forecasting/routes.php` — add accuracy routes
- Modify: `api/routes/console.php` — schedule reconciliation
- Create: `spa/src/pages/forecasting/accuracy.tsx` — accuracy dashboard page
- Modify: `spa/src/types/forecasting.ts` — add accuracy types
- Modify: `spa/src/api/forecasting.ts` — add accuracy API calls
- Create: `api/tests/Feature/Forecasting/ForecastAccuracyTest.php`

**Interfaces:**
- Consumes: `ForecastingService::accuracy()` (already exists), `ForecastingService::reconcileActuals()` (already exists)
- Produces: `GET /api/v1/forecasting/accuracy?year=2026` endpoint, `GET /api/v1/forecasting/accuracy/products` for per-product breakdown, Artisan command `forecasting:reconcile-actuals`, SPA page `/forecasting/accuracy`

**Context for implementer:**

The `ForecastingService` ALREADY has `reconcileActuals()` and `accuracy()` methods. The controller ALREADY has a basic `accuracy()` action. This task:
1. Creates a scheduled Artisan command to run `reconcileActuals()` monthly
2. Adds a per-product accuracy endpoint with trend data
3. Builds a frontend accuracy dashboard showing MAPE, bias, and monthly variance chart

Read these files:
- `api/app/Modules/Forecasting/Services/ForecastingService.php` — full service with `accuracy()` and `reconcileActuals()`
- `api/app/Modules/Forecasting/Controllers/DemandForecastController.php` — existing controller (line 133-139 has basic accuracy endpoint)
- `api/app/Modules/Forecasting/Models/DemandForecast.php` — model with actual_quantity, variance fields
- `docs/PATTERNS.md` — for controller, page patterns

- [ ] **Step 1: Create reconciliation command**

```php
<?php
// api/app/Console/Commands/ReconcileForecastActuals.php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Forecasting\Services\ForecastingService;
use Illuminate\Console\Command;

class ReconcileForecastActuals extends Command
{
    protected $signature = 'forecasting:reconcile-actuals';
    protected $description = 'Backfill actual_quantity and variance on elapsed forecast periods';

    public function handle(ForecastingService $service): int
    {
        $updated = $service->reconcileActuals();
        $this->info("Reconciled {$updated} forecast periods.");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: Schedule command in console.php**

Add to `api/routes/console.php`:
```php
Schedule::command('forecasting:reconcile-actuals')
    ->monthlyOn(2, '04:00')
    ->name('forecasting:reconcile-actuals')
    ->withoutOverlapping()
    ->onOneServer();
```

- [ ] **Step 3: Create ForecastAccuracyController with per-product breakdown**

```php
<?php
// api/app/Modules/Forecasting/Controllers/ForecastAccuracyController.php
declare(strict_types=1);

namespace App\Modules\Forecasting\Controllers;

use App\Modules\Forecasting\Services\ForecastingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ForecastAccuracyController
{
    public function __construct(private readonly ForecastingService $service) {}

    public function summary(Request $request): JsonResponse
    {
        $year = $request->integer('year', now()->year);
        return response()->json(['data' => $this->service->accuracy($year)]);
    }

    public function byProduct(Request $request): JsonResponse
    {
        $year = $request->integer('year', now()->year);
        $products = \App\Modules\CRM\Models\Product::where('is_active', true)->get();

        $results = $products->map(function ($product) use ($year) {
            $acc = $this->service->accuracy($year, $product->id);
            if ($acc['periods_evaluated'] === 0) return null;
            return [
                'product_id' => $product->hash_id,
                'part_number' => $product->part_number,
                'name' => $product->name,
                'mape' => $acc['mape'],
                'bias' => $acc['bias'],
                'periods_evaluated' => $acc['periods_evaluated'],
            ];
        })->filter()->values();

        return response()->json(['data' => $results]);
    }
}
```

- [ ] **Step 4: Add routes**

Add to `api/app/Modules/Forecasting/routes.php`:
```php
Route::get('forecasting/accuracy/summary', [ForecastAccuracyController::class, 'summary'])
    ->middleware('permission:forecasting.forecasts.view');
Route::get('forecasting/accuracy/products', [ForecastAccuracyController::class, 'byProduct'])
    ->middleware('permission:forecasting.forecasts.view');
```

- [ ] **Step 5: Add frontend types**

Add to `spa/src/types/forecasting.ts`:
```typescript
export interface ForecastAccuracy {
  mape: number | null;
  bias: number | null;
  periods_evaluated: number;
  monthly: Array<{
    year: number;
    month: number;
    forecast: number;
    actual: number;
    variance: number;
    ape: number;
  }>;
}

export interface ProductAccuracy {
  product_id: string;
  part_number: string;
  name: string;
  mape: number;
  bias: number;
  periods_evaluated: number;
}
```

- [ ] **Step 6: Add API calls**

Add to `spa/src/api/forecasting.ts`:
```typescript
accuracySummary: (year?: number) =>
  client.get<{ data: ForecastAccuracy }>('/forecasting/accuracy/summary', { params: { year } }),
accuracyByProduct: (year?: number) =>
  client.get<{ data: ProductAccuracy[] }>('/forecasting/accuracy/products', { params: { year } }),
```

- [ ] **Step 7: Create accuracy dashboard page**

Create `spa/src/pages/forecasting/accuracy.tsx`:
- Top row: 3 StatCards — overall MAPE%, Bias%, Periods Evaluated
- Middle: Recharts LineChart showing monthly forecast vs actual quantities (dual lines + variance bars)
- Bottom: DataTable of per-product accuracy (part_number, name, MAPE, bias, periods) with sort
- Year selector dropdown
- Follow PATTERNS.md list page pattern for loading/error/empty states

- [ ] **Step 8: Write tests**

```php
// api/tests/Feature/Forecasting/ForecastAccuracyTest.php
// test_accuracy_summary_returns_mape_and_bias
// test_accuracy_by_product_filters_active_products
// test_reconcile_actuals_command_runs_successfully
// test_accuracy_requires_permission
```

- [ ] **Step 9: Run tests and commit**

```bash
cd api && php artisan test --filter=ForecastAccuracy
git add -A && git commit -m "feat(forecasting): add accuracy tracking dashboard + reconciliation command"
```

---

### Task 3: Leave Calendar Heatmap

**Files:**
- Create: `api/app/Modules/Leave/Controllers/LeaveCalendarController.php`
- Modify: `api/app/Modules/Leave/routes.php` — add calendar endpoint
- Create: `spa/src/pages/leaves/calendar.tsx` — calendar heatmap page
- Modify: `spa/src/types/leave.ts` — add calendar types
- Modify: `spa/src/api/leave.ts` — add calendar API call
- Create: `api/tests/Feature/Leave/LeaveCalendarTest.php`

**Interfaces:**
- Consumes: `LeaveRequest` model, `Employee` model, `Department` model
- Produces: `GET /api/v1/leaves/calendar?department_id=X&month=6&year=2026` — returns per-day counts of approved/pending leaves by department, plus department headcount for coverage calculation

**Context for implementer:**

Department heads and HR need to see "if I approve this leave, how many people are left on shift?" before approving. This builds a monthly calendar view showing leave density per day, colored by coverage level (green = >80% present, amber = 60-80%, red = <60%).

Read these files:
- `api/app/Modules/Leave/Models/LeaveRequest.php` — has status, start_date, end_date, employee_id
- `api/app/Modules/Leave/Services/LeaveRequestService.php` — existing service
- `api/app/Modules/HR/Models/Department.php` — for headcount
- `spa/src/pages/leaves/index.tsx` — existing leaves page for pattern reference
- `docs/PATTERNS.md` — for controller, page patterns

- [ ] **Step 1: Create LeaveCalendarController**

```php
<?php
// api/app/Modules/Leave/Controllers/LeaveCalendarController.php
declare(strict_types=1);

namespace App\Modules\Leave\Controllers;

use App\Common\Support\HashIdFilter;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Models\LeaveRequest;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveCalendarController
{
    public function index(Request $request): JsonResponse
    {
        $year = $request->integer('year', now()->year);
        $month = $request->integer('month', now()->month);
        $deptId = $request->filled('department_id')
            ? HashIdFilter::decode($request->input('department_id'), Department::class)
            : null;

        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $headcount = Employee::query()
            ->where('status', 'active')
            ->when($deptId, fn ($q) => $q->where('department_id', $deptId))
            ->count();

        $leaves = LeaveRequest::query()
            ->whereIn('status', ['approved', 'pending'])
            ->where('start_date', '<=', $end->toDateString())
            ->where('end_date', '>=', $start->toDateString())
            ->when($deptId, fn ($q) => $q->whereHas('employee', fn ($eq) => $eq->where('department_id', $deptId)))
            ->with('employee:id,first_name,last_name,department_id')
            ->get();

        $days = [];
        foreach (CarbonPeriod::create($start, $end) as $day) {
            $dateStr = $day->toDateString();
            $onLeave = $leaves->filter(fn ($l) =>
                $l->start_date->lte($day) && $l->end_date->gte($day)
            );
            $approvedCount = $onLeave->where('status', 'approved')->count();
            $pendingCount = $onLeave->where('status', 'pending')->count();
            $present = max(0, $headcount - $approvedCount);
            $coverage = $headcount > 0 ? round($present / $headcount * 100, 1) : 100;

            $days[] = [
                'date' => $dateStr,
                'day_of_week' => $day->dayOfWeek,
                'approved_count' => $approvedCount,
                'pending_count' => $pendingCount,
                'present_count' => $present,
                'headcount' => $headcount,
                'coverage_pct' => $coverage,
                'employees_on_leave' => $onLeave->map(fn ($l) => [
                    'employee_name' => $l->employee?->full_name,
                    'status' => $l->status,
                    'leave_type' => $l->leaveType?->name ?? '',
                ])->values(),
            ];
        }

        return response()->json([
            'data' => [
                'year' => $year,
                'month' => $month,
                'headcount' => $headcount,
                'days' => $days,
            ],
        ]);
    }
}
```

- [ ] **Step 2: Add route**

Add to `api/app/Modules/Leave/routes.php`:
```php
Route::get('leaves/calendar', [LeaveCalendarController::class, 'index'])
    ->middleware('permission:leave.requests.view');
```
Ensure this is declared BEFORE any `{leaveRequest}` parameter route.

- [ ] **Step 3: Add frontend types and API**

Add to `spa/src/types/leave.ts`:
```typescript
export interface LeaveCalendarDay {
  date: string;
  day_of_week: number;
  approved_count: number;
  pending_count: number;
  present_count: number;
  headcount: number;
  coverage_pct: number;
  employees_on_leave: Array<{
    employee_name: string;
    status: string;
    leave_type: string;
  }>;
}

export interface LeaveCalendarData {
  year: number;
  month: number;
  headcount: number;
  days: LeaveCalendarDay[];
}
```

Add to `spa/src/api/leave.ts`:
```typescript
calendar: (params: { year?: number; month?: number; department_id?: string }) =>
  client.get<{ data: LeaveCalendarData }>('/leaves/calendar', { params }),
```

- [ ] **Step 4: Create calendar heatmap page**

Create `spa/src/pages/leaves/calendar.tsx`:
- Month/Year selectors + Department filter dropdown
- 7-column grid (Sun-Sat), each cell is a day
- Cell background color based on coverage_pct: green (>80%), amber (60-80%), red (<60%)
- Cell shows: day number, approved count, pending count (in smaller text)
- Click on a cell → popover/tooltip showing list of employees on leave that day
- Bottom legend: green/amber/red with percentage ranges
- Follow PATTERNS.md page states (loading skeleton, error, empty)

- [ ] **Step 5: Write tests**

```php
// api/tests/Feature/Leave/LeaveCalendarTest.php
// test_calendar_returns_daily_coverage
// test_calendar_filters_by_department
// test_calendar_counts_approved_and_pending_separately
// test_calendar_handles_multi_day_leaves
// test_calendar_requires_permission
```

- [ ] **Step 6: Run tests and commit**

```bash
cd api && php artisan test --filter=LeaveCalendar
git add -A && git commit -m "feat(leave): add department calendar heatmap with coverage tracking"
```

---

### Task 4: Audit Log Search + PDF Export

**Files:**
- Modify: `api/app/Modules/Admin/Controllers/AuditLogController.php` — add entity-scoped search + PDF export
- Create: `api/resources/views/pdf/audit-log.blade.php` — PDF template
- Modify: `api/app/Modules/Admin/routes.php` — add PDF route
- Create: `spa/src/pages/admin/audit-logs/entity.tsx` — entity-scoped audit trail page
- Modify: `spa/src/api/admin.ts` — add entity audit API
- Create: `api/tests/Feature/Admin/AuditLogSearchTest.php`

**Interfaces:**
- Consumes: `AuditLog` model (existing), `AuditLogController::filteredQuery()` (existing private method)
- Produces: `GET /api/v1/admin/audit-logs/entity?model_type=PurchaseOrder&model_id=yR3kLm` — returns all audit entries for a specific record, `GET /api/v1/admin/audit-logs/export/pdf?...` — streamed PDF

**Context for implementer:**

The AuditLogController already has `index()` (paginated list with filters), `show()` (single row with diff), and `export()` (CSV stream). This task adds:
1. An entity-scoped query: "show me all changes to PO-202604-0015" — needed for IATF compliance audits
2. A PDF export alongside the existing CSV export

Read these files:
- `api/app/Modules/Admin/Controllers/AuditLogController.php` — existing controller with `filteredQuery()`, `export()`, `show()`
- `api/app/Common/Models/AuditLog.php` — model
- `api/resources/views/pdf/_layout.blade.php` — existing PDF layout
- `api/resources/views/pdf/journal-entry.blade.php` — example PDF template
- `docs/PATTERNS.md` — patterns

- [ ] **Step 1: Add entityTrail method to AuditLogController**

```php
public function entityTrail(Request $request): AnonymousResourceCollection
{
    $modelType = $request->input('model_type');
    $modelId = $request->input('model_id');

    abort_if(!$modelType || !$modelId, 422, 'model_type and model_id required');

    $query = AuditLog::query()
        ->where('model_type', $modelType)
        ->where('model_id', $modelId)
        ->with(['user:id,name,email,role_id', 'user.role:id,name,slug'])
        ->orderByDesc('created_at');

    return AuditLogResource::collection($query->paginate(100));
}
```

- [ ] **Step 2: Create PDF Blade template**

```blade
{{-- api/resources/views/pdf/audit-log.blade.php --}}
@extends('pdf._layout')
@section('title', 'Audit Trail Report')
@section('content')
<h2 style="margin-bottom: 12px;">Audit Trail Report</h2>
<p style="font-size: 10px; color: #666;">Generated: {{ now()->format('M d, Y h:i A') }} | Filters: {{ $filterSummary }}</p>
<table style="width: 100%; border-collapse: collapse; font-size: 9px; margin-top: 8px;">
    <thead>
        <tr style="background: #f3f4f6;">
            <th style="border: 1px solid #e5e7eb; padding: 4px;">Date/Time</th>
            <th style="border: 1px solid #e5e7eb; padding: 4px;">User</th>
            <th style="border: 1px solid #e5e7eb; padding: 4px;">Action</th>
            <th style="border: 1px solid #e5e7eb; padding: 4px;">Record</th>
            <th style="border: 1px solid #e5e7eb; padding: 4px;">Changes</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($logs as $log)
        <tr>
            <td style="border: 1px solid #e5e7eb; padding: 4px; font-family: monospace;">{{ $log->created_at->format('Y-m-d H:i') }}</td>
            <td style="border: 1px solid #e5e7eb; padding: 4px;">{{ $log->user?->name ?? 'System' }}</td>
            <td style="border: 1px solid #e5e7eb; padding: 4px;">{{ ucfirst($log->action) }}</td>
            <td style="border: 1px solid #e5e7eb; padding: 4px; font-family: monospace;">{{ class_basename($log->model_type) }} #{{ $log->model_id }}</td>
            <td style="border: 1px solid #e5e7eb; padding: 4px; font-size: 8px;">{{ $log->changeSummary() }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endsection
```

- [ ] **Step 3: Add PDF export method to controller**

```php
public function exportPdf(Request $request): \Illuminate\Http\Response
{
    $logs = $this->filteredQuery($request)->limit(500)->get();
    $filterSummary = collect($request->only(['model_type', 'user_id', 'action', 'date_from', 'date_to']))
        ->filter()->map(fn ($v, $k) => "{$k}={$v}")->implode(', ') ?: 'None';

    $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.audit-log', compact('logs', 'filterSummary'))
        ->setPaper('a4', 'landscape');

    return $pdf->download('audit-trail-' . now()->format('Ymd-His') . '.pdf');
}
```

- [ ] **Step 4: Add routes**

```php
Route::get('admin/audit-logs/entity', [AuditLogController::class, 'entityTrail'])
    ->middleware('permission:admin.audit_logs.view');
Route::get('admin/audit-logs/export/pdf', [AuditLogController::class, 'exportPdf'])
    ->middleware('permission:admin.audit_logs.view');
```
Declare BEFORE any `{auditLog}` param route.

- [ ] **Step 5: Add frontend API + entity trail page**

API: `entityTrail: (modelType: string, modelId: string) => client.get('/admin/audit-logs/entity', { params: { model_type: modelType, model_id: modelId } })`

Create `spa/src/pages/admin/audit-logs/entity.tsx` — receives model_type and model_id as query params, renders a chronological timeline of all changes with expandable diffs. Add PDF download button using `window.open()` to the PDF export endpoint.

- [ ] **Step 6: Write tests + commit**

```php
// test_entity_trail_returns_scoped_audit_logs
// test_entity_trail_requires_both_params
// test_pdf_export_generates_downloadable_file
// test_audit_endpoints_require_permission
```

```bash
cd api && php artisan test --filter=AuditLogSearch
git add -A && git commit -m "feat(admin): add entity-scoped audit trail + PDF export"
```

---

### Task 5: COPQ Dashboard Widget

**Files:**
- Create: `api/app/Modules/Dashboard/Controllers/CopqWidgetController.php`
- Modify: `api/app/Modules/Dashboard/routes.php` — add COPQ widget route
- Create: `spa/src/pages/dashboard/widgets/CopqWidget.tsx` — COPQ widget component
- Modify: `spa/src/pages/dashboard/quality.tsx` — integrate widget
- Create: `api/tests/Feature/Dashboard/CopqWidgetTest.php`

**Interfaces:**
- Consumes: `CopqService::compute()` (existing), `CopqService::trend()` if exists or query `copq_snapshots` table directly
- Produces: `GET /api/v1/dashboard/copq-widget?months=6` — returns COPQ breakdown (scrap, rework, warranty, inspection costs) + monthly trend

**Context for implementer:**

`CopqService` and `copq_snapshots` table already exist. Monthly snapshot cron (`copq:snap-monthly`) runs on the 1st. The Quality dashboard (`QualityDashboardService`) already calls `CopqService::compute()` for current-month COPQ. This task creates a dedicated visual widget with breakdown + trend chart for prominent placement.

Read these files:
- `api/app/Modules/Quality/Services/CopqService.php` — existing COPQ computation
- `api/app/Modules/Dashboard/Services/QualityDashboardService.php` — already uses COPQ (line 53)
- `spa/src/pages/dashboard/quality.tsx` — existing quality dashboard page
- `spa/src/components/ui/StatCard.tsx` — for KPI cards

- [ ] **Step 1: Create CopqWidgetController**

```php
<?php
// api/app/Modules/Dashboard/Controllers/CopqWidgetController.php
declare(strict_types=1);

namespace App\Modules\Dashboard\Controllers;

use App\Modules\Quality\Services\CopqService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CopqWidgetController
{
    public function __construct(private readonly CopqService $copq) {}

    public function index(Request $request): JsonResponse
    {
        $months = min($request->integer('months', 6), 12);

        // Current month live computation
        $current = $this->copq->compute(now()->startOfMonth(), now()->endOfMonth());

        // Historical trend from snapshots
        $trend = DB::table('copq_snapshots')
            ->where('snapshot_date', '>=', now()->subMonths($months)->startOfMonth()->toDateString())
            ->orderBy('snapshot_date')
            ->get()
            ->map(fn ($row) => [
                'month' => Carbon::parse($row->snapshot_date)->format('M Y'),
                'scrap_cost' => (float) $row->scrap_cost,
                'rework_cost' => (float) $row->rework_cost,
                'warranty_cost' => (float) $row->warranty_cost,
                'inspection_cost' => (float) $row->inspection_cost,
                'total' => (float) $row->total_copq,
            ]);

        return response()->json([
            'data' => [
                'current' => $current,
                'trend' => $trend,
                'period' => now()->format('M Y'),
            ],
        ]);
    }
}
```

- [ ] **Step 2: Add route**

```php
Route::get('dashboard/copq-widget', [CopqWidgetController::class, 'index'])
    ->middleware('permission:quality.reports.view');
```

- [ ] **Step 3: Create CopqWidget component**

Create `spa/src/pages/dashboard/widgets/CopqWidget.tsx`:
- 4 StatCards in a row: Scrap Cost, Rework Cost, Warranty Cost, Inspection Cost (all ₱ formatted, font-mono)
- Below: Recharts StackedBarChart showing monthly breakdown by category
- Total COPQ as % of revenue if available
- Loading skeleton state
- Uses `useQuery` with `['copq-widget']` key

- [ ] **Step 4: Integrate into quality dashboard**

Add the `<CopqWidget />` component to `spa/src/pages/dashboard/quality.tsx` — place it prominently at the top or as a dedicated section.

- [ ] **Step 5: Write tests + commit**

```php
// test_copq_widget_returns_current_and_trend
// test_copq_widget_limits_months
// test_copq_widget_requires_permission
```

```bash
cd api && php artisan test --filter=CopqWidget
git add -A && git commit -m "feat(dashboard): add COPQ breakdown widget with trend chart"
```

---

### Task 6: B2B Portal Service Layer Refactor

**Files:**
- Create: `api/app/Modules/B2B/Services/CustomerPortalService.php`
- Create: `api/app/Modules/B2B/Services/SupplierPortalService.php`
- Modify: `api/app/Modules/B2B/Controllers/CustomerPortalController.php` — delegate to service
- Modify: `api/app/Modules/B2B/Controllers/SupplierPortalController.php` — delegate to service
- Create: `api/tests/Feature/B2B/CustomerPortalServiceTest.php`
- Create: `api/tests/Feature/B2B/SupplierPortalServiceTest.php`

**Interfaces:**
- Consumes: `SalesOrderService`, `DeliveryService`, `PurchaseOrderService`, `ShipmentService`, existing portal models
- Produces: `CustomerPortalService` (order history, delivery tracking, complaint submission, document access), `SupplierPortalService` (PO acknowledgment, shipment updates, invoice submission, document access)

**Context for implementer:**

The B2B module has 4 controllers and 43 routes but only 1 service (B2bAuthService for login). Business logic is in controllers — violating the project convention of thin controllers + service-layer logic. This task extracts controller logic into proper services.

Read these files:
- `api/app/Modules/B2B/Controllers/CustomerPortalController.php` — all current customer portal logic
- `api/app/Modules/B2B/Controllers/SupplierPortalController.php` — all current supplier portal logic
- `api/app/Modules/B2B/Services/B2bAuthService.php` — existing auth service
- `api/app/Modules/CRM/Services/SalesOrderService.php` — for order data
- `docs/PATTERNS.md` section 3 (Service pattern)

- [ ] **Step 1: Create CustomerPortalService**

Extract from `CustomerPortalController` into `CustomerPortalService`:
- `orderHistory(int $customerId, array $filters): LengthAwarePaginator`
- `orderDetail(int $customerId, int $orderId): SalesOrder`
- `deliverySchedule(int $customerId): Collection`
- `submitComplaint(int $customerId, array $data): CustomerComplaint`
- `documents(int $customerId, array $filters): LengthAwarePaginator`
- `downloadDocument(int $customerId, int $documentId): string` (returns file path)

Each method scopes queries to the customer's own data (row-level filtering — security critical).

- [ ] **Step 2: Create SupplierPortalService**

Extract from `SupplierPortalController` into `SupplierPortalService`:
- `purchaseOrders(int $vendorId, array $filters): LengthAwarePaginator`
- `acknowledgePo(int $vendorId, int $poId, array $data): PurchaseOrder`
- `updateShipment(int $vendorId, int $shipmentId, array $data): Shipment`
- `submitInvoice(int $vendorId, array $data): Bill`
- `documents(int $vendorId, array $filters): LengthAwarePaginator`

Same row-level filtering pattern — vendor can only see their own POs.

- [ ] **Step 3: Refactor controllers to delegate to services**

Update both controllers to inject services via constructor and delegate. Controllers become thin: validate request → call service → return resource.

- [ ] **Step 4: Write tests**

Test both services with proper authentication (portal guard), ensuring:
- Row-level filtering works (customer A can't see customer B's orders)
- All CRUD operations work through services
- Permission checks pass

- [ ] **Step 5: Run tests and commit**

```bash
cd api && php artisan test --filter='CustomerPortalService|SupplierPortalService'
git add -A && git commit -m "refactor(b2b): extract portal business logic into service layer"
```

---

### Task 7: Supply Chain ImpEx Document Generation

**Files:**
- Create: `api/app/Modules/SupplyChain/Services/ImpexDocumentService.php`
- Create: `api/resources/views/pdf/packing-list.blade.php`
- Create: `api/resources/views/pdf/commercial-invoice.blade.php`
- Create: `api/app/Modules/SupplyChain/Controllers/ImpexDocumentController.php`
- Create: `api/app/Modules/SupplyChain/Requests/GenerateImpexDocRequest.php`
- Modify: `api/app/Modules/SupplyChain/routes.php` — add routes
- Modify: `spa/src/pages/supply-chain/shipments/detail.tsx` or equivalent — add download buttons
- Create: `api/tests/Feature/SupplyChain/ImpexDocumentTest.php`

**Interfaces:**
- Consumes: `Shipment` model (has PO reference, vendor, items), `ShipmentLot` model, `PurchaseOrder` model
- Produces: `ImpexDocumentService::generatePackingList(Shipment): PDF`, `ImpexDocumentService::generateCommercialInvoice(Shipment): PDF`

**Context for implementer:**

Ogami imports resin from Japan. Shipment documents (packing list, commercial invoice) are needed for customs clearance. The SupplyChain module already has `ShipmentDocumentType` enum with `CommercialInvoice` and `PackingList` values, and `Shipment` model has `customs_clearance_date`. This task generates PDF versions auto-filled from shipment + PO data.

Read these files:
- `api/app/Modules/SupplyChain/Models/Shipment.php` — has vendor, PO, lots, customs fields
- `api/app/Modules/SupplyChain/Enums/ShipmentDocumentType.php` — already has CommercialInvoice, PackingList
- `api/app/Modules/SupplyChain/Services/ShipmentService.php` — existing service
- `api/resources/views/pdf/purchase-request.blade.php` — example PDF template
- `api/resources/views/pdf/_layout.blade.php` — base layout

- [ ] **Step 1: Create ImpexDocumentService**

```php
<?php
// api/app/Modules/SupplyChain/Services/ImpexDocumentService.php
declare(strict_types=1);

namespace App\Modules\SupplyChain\Services;

use App\Modules\SupplyChain\Models\Shipment;
use Barryvdh\DomPDF\Facade\Pdf;

class ImpexDocumentService
{
    public function generatePackingList(Shipment $shipment): \Barryvdh\DomPDF\PDF
    {
        $shipment->load(['lots.purchaseOrderItem.item', 'vendor', 'container']);
        $company = $this->companyInfo();

        return Pdf::loadView('pdf.packing-list', [
            'shipment' => $shipment,
            'company' => $company,
            'lots' => $shipment->lots,
        ])->setPaper('a4');
    }

    public function generateCommercialInvoice(Shipment $shipment): \Barryvdh\DomPDF\PDF
    {
        $shipment->load(['lots.purchaseOrderItem.item', 'vendor', 'purchaseOrder']);
        $company = $this->companyInfo();

        return Pdf::loadView('pdf.commercial-invoice', [
            'shipment' => $shipment,
            'company' => $company,
            'lots' => $shipment->lots,
            'po' => $shipment->purchaseOrder,
        ])->setPaper('a4');
    }

    private function companyInfo(): array
    {
        return [
            'name' => config('app.company_name', 'Philippine Ogami Corporation'),
            'address' => config('app.company_address', 'FCIE, Dasmariñas, Cavite'),
            'tin' => config('app.company_tin', ''),
        ];
    }
}
```

- [ ] **Step 2: Create Blade templates for packing list and commercial invoice**

Follow existing PDF layout pattern (`_layout.blade.php`). Packing list includes: shipper, consignee, vessel/voyage, container no, marks & numbers, item descriptions, quantities, weights. Commercial invoice includes: same header + unit prices, totals, payment terms, incoterms.

- [ ] **Step 3: Create controller + routes**

```php
<?php
// api/app/Modules/SupplyChain/Controllers/ImpexDocumentController.php
declare(strict_types=1);

namespace App\Modules\SupplyChain\Controllers;

use App\Modules\SupplyChain\Models\Shipment;
use App\Modules\SupplyChain\Services\ImpexDocumentService;

class ImpexDocumentController
{
    public function __construct(private readonly ImpexDocumentService $service) {}

    public function packingList(Shipment $shipment)
    {
        return $this->service->generatePackingList($shipment)
            ->download("packing-list-{$shipment->tracking_number}.pdf");
    }

    public function commercialInvoice(Shipment $shipment)
    {
        return $this->service->generateCommercialInvoice($shipment)
            ->download("commercial-invoice-{$shipment->tracking_number}.pdf");
    }
}
```

Routes:
```php
Route::get('supply-chain/shipments/{shipment}/packing-list', [ImpexDocumentController::class, 'packingList'])
    ->middleware('permission:supply_chain.shipments.view');
Route::get('supply-chain/shipments/{shipment}/commercial-invoice', [ImpexDocumentController::class, 'commercialInvoice'])
    ->middleware('permission:supply_chain.shipments.view');
```

- [ ] **Step 4: Add download buttons to shipment detail page**

Add PDF download buttons (Lucide `FileText` icon) to the shipment detail page. Use `window.open()` to the API endpoint.

- [ ] **Step 5: Write tests + commit**

```php
// test_packing_list_generates_pdf
// test_commercial_invoice_generates_pdf
// test_impex_docs_require_permission
```

```bash
cd api && php artisan test --filter=ImpexDocument
git add -A && git commit -m "feat(supply-chain): add packing list + commercial invoice PDF generation"
```

---

### Task 8: SO → WO Chain Bridge Auto-Generation

**Files:**
- Modify: `api/app/Modules/CRM/Services/SalesOrderService.php` — verify chain bridge works
- Create: `spa/src/pages/crm/sales-orders/chain-result.tsx` — chain result summary component
- Create: `api/tests/Feature/CRM/SalesOrderChainBridgeTest.php`

**Interfaces:**
- Consumes: `SalesOrderService::confirmWithChainResult()` (already exists!), `MrpEngineService::runForSalesOrder()` (already exists)
- Produces: Frontend component displaying chain result (WOs created, PRs generated, scheduling conflicts) after SO confirmation

**Context for implementer:**

The SO → WO chain bridge ALREADY EXISTS in the backend! `SalesOrderService::confirm()` calls `MrpEngineService::runForSalesOrder()` which creates MRP plan + WOs + auto-PRs. And `confirmWithChainResult()` returns a detailed summary. What's MISSING is:
1. Frontend visualization of the chain result after confirming an SO
2. Tests validating the end-to-end flow
3. Error handling UI when MRP fails (missing BOM, no supplier mapping)

Read these files:
- `api/app/Modules/CRM/Services/SalesOrderService.php` — `confirm()` and `confirmWithChainResult()` methods
- `api/app/Modules/MRP/Services/MrpEngineService.php` — `runForSalesOrder()` method
- `spa/src/pages/crm/sales-orders/` — existing SO pages
- `spa/src/components/chain/` — ChainHeader, LinkedRecords components

- [ ] **Step 1: Create chain result summary component**

Create `spa/src/pages/crm/sales-orders/chain-result.tsx`:
- Modal/panel that displays after SO confirmation
- Shows: WOs created (table with wo_number, product, quantity, machine, scheduled dates)
- Shows: PRs created count, shortages found
- Shows: scheduling conflicts (if any, as warning alerts)
- Shows: items needing manual scheduling (amber highlight)
- "View Work Orders" link, "View MRP Plan" link
- Uses existing `LinkedRecords` component pattern

- [ ] **Step 2: Update SO confirm action in frontend to use confirmWithChainResult**

Modify the confirm button handler in the SO detail page to call the `confirmWithChainResult` variant and display the chain result modal on success.

API call:
```typescript
confirmWithChain: (id: string) =>
  client.post<{ data: { so: SalesOrder; chain_result: ChainResult } }>(`/crm/sales-orders/${id}/confirm`),
```

- [ ] **Step 3: Add error handling for MRP failures**

When confirmation fails due to missing BOM or supplier mapping, display a clear error with:
- Which product has no BOM
- Which material has no supplier
- Link to fix the issue

- [ ] **Step 4: Write integration tests**

```php
// api/tests/Feature/CRM/SalesOrderChainBridgeTest.php
// test_confirm_so_triggers_mrp_and_creates_work_orders
// test_confirm_so_returns_chain_result_summary
// test_confirm_so_fails_gracefully_when_bom_missing
// test_confirm_so_creates_auto_prs_for_shortages
```

- [ ] **Step 5: Run tests + commit**

```bash
cd api && php artisan test --filter=SalesOrderChainBridge
git add -A && git commit -m "feat(crm): add chain result visualization for SO confirmation"
```

---

### Task 9: Maintenance Tech Mobile View

**Files:**
- Create: `spa/src/pages/maintenance/mobile/index.tsx` — mobile MWO list
- Create: `spa/src/pages/maintenance/mobile/work-order.tsx` — MWO detail + completion form
- Create: `spa/src/pages/maintenance/mobile/condition-reading.tsx` — condition reading form
- Modify: `spa/src/pages/maintenance/index.tsx` — add mobile view toggle link
- Create: `api/tests/Feature/Maintenance/MobileMaintenanceTest.php`

**Interfaces:**
- Consumes: existing `MaintenanceWorkOrderService`, `PredictiveMaintenanceService::recordAndEvaluate()`, `SparePartUsageService`
- Produces: 3 mobile-optimized pages for maintenance techs: assigned MWOs list, MWO completion with parts used, condition reading entry

**Context for implementer:**

Factory floor PWA already exists at `spa/src/pages/factory/` (ActiveOrders, RecordOutput, QcQuickCheck). Follow the same mobile-first pattern for maintenance. Maintenance techs need to see their assigned work orders, mark completion with parts used, and record condition readings — all from a phone on the shop floor.

Read these files:
- `spa/src/pages/factory/ActiveOrders.tsx` — existing factory floor mobile pattern
- `spa/src/pages/factory/RecordOutput.tsx` — existing mobile form pattern
- `api/app/Modules/Maintenance/Services/MaintenanceWorkOrderService.php` — MWO lifecycle
- `api/app/Modules/Maintenance/Services/PredictiveMaintenanceService.php` — `recordAndEvaluate()` for condition readings
- `api/app/Modules/Maintenance/Services/SparePartUsageService.php` — recording parts used
- `spa/src/components/ui/BottomSheet.tsx` — mobile-friendly component

- [ ] **Step 1: Create mobile MWO list page**

Create `spa/src/pages/maintenance/mobile/index.tsx`:
- Card-based layout (no DataTable — mobile-friendly)
- Each card shows: MWO number, machine name, priority (Chip), type (corrective/preventive), due date
- Filter tabs: My Assigned | All Open
- Pull-to-refresh pattern (same as factory floor)
- Bottom navigation or tab bar

- [ ] **Step 2: Create MWO detail + completion form**

Create `spa/src/pages/maintenance/mobile/work-order.tsx`:
- Full MWO details at top (machine, description, priority, SOP link)
- "Parts Used" section: add items from inventory (item search + quantity)
- "Work Performed" textarea
- "Complete" button with time spent input
- Photo upload for completed work (using existing file upload pattern)
- Zod validation, submit disabled while pending

- [ ] **Step 3: Create condition reading form**

Create `spa/src/pages/maintenance/mobile/condition-reading.tsx`:
- Machine selector (dropdown or scan)
- Reading type (temperature, vibration, pressure, etc.)
- Value input (numeric)
- Uses `PredictiveMaintenanceService::recordAndEvaluate()` endpoint
- Shows alert if reading breaches threshold (auto-created corrective MWO)

- [ ] **Step 4: Add routes and navigation**

Register mobile maintenance routes behind `ModuleGuard('maintenance')` + `PermissionGuard('maintenance.work_orders.view')`. Add link from main maintenance page.

- [ ] **Step 5: Write tests + commit**

```php
// test_mobile_mwo_list_filters_by_assigned_tech
// test_mobile_mwo_completion_records_parts_used
// test_mobile_condition_reading_triggers_alert_on_breach
```

```bash
cd api && php artisan test --filter=MobileMaintenance
git add -A && git commit -m "feat(maintenance): add mobile tech view for MWOs + condition readings"
```

---

### Task 10: Training Matrix Skill Heatmap

**Files:**
- Create: `api/app/Modules/HR/Controllers/TrainingMatrixController.php`
- Modify: `api/app/Modules/HR/routes.php` — add matrix endpoint
- Create: `spa/src/pages/hr/training/matrix.tsx` — skill matrix heatmap page
- Modify: `spa/src/types/hr.ts` — add matrix types
- Modify: `spa/src/api/hr.ts` — add matrix API call
- Create: `api/tests/Feature/HR/TrainingMatrixTest.php`

**Interfaces:**
- Consumes: `EmployeeSkillService`, `EmployeeTrainingService`, `Employee` model, `Skill` model, `EmployeeTraining` model
- Produces: `GET /api/v1/hr/training/matrix?department_id=X` — returns employees × skills grid with status (trained/expired/gap/not_required)

**Context for implementer:**

The HR module already has `EmployeeSkillService`, `EmployeeTrainingService`, `SkillService`, plus `Skill`, `EmployeeSkill`, `EmployeeTraining` models. Training expiry checks run via cron. This task visualizes the data as a heatmap grid.

Read these files:
- `api/app/Modules/HR/Services/EmployeeSkillService.php`
- `api/app/Modules/HR/Services/EmployeeTrainingService.php`
- `api/app/Modules/HR/Models/EmployeeSkill.php` — has skill_level enum
- `api/app/Modules/HR/Enums/EmployeeSkillLevel.php`
- `api/app/Modules/HR/Enums/EmployeeTrainingStatus.php`

- [ ] **Step 1: Create TrainingMatrixController**

```php
<?php
// api/app/Modules/HR/Controllers/TrainingMatrixController.php
declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Common\Support\HashIdFilter;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Skill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrainingMatrixController
{
    public function index(Request $request): JsonResponse
    {
        $deptId = $request->filled('department_id')
            ? HashIdFilter::decode($request->input('department_id'), Department::class)
            : null;

        $employees = Employee::query()
            ->where('status', 'active')
            ->when($deptId, fn ($q) => $q->where('department_id', $deptId))
            ->with(['skills.skill', 'trainings'])
            ->orderBy('last_name')
            ->get();

        $skills = Skill::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $matrix = $employees->map(function ($emp) use ($skills) {
            $cells = $skills->map(function ($skill) use ($emp) {
                $empSkill = $emp->skills->firstWhere('skill_id', $skill->id);
                $training = $emp->trainings
                    ->where('skill_id', $skill->id)
                    ->sortByDesc('completed_at')
                    ->first();

                $status = 'gap';
                if ($empSkill) {
                    $status = 'trained';
                    if ($training && $training->expiry_date && $training->expiry_date->isPast()) {
                        $status = 'expired';
                    }
                }

                return [
                    'skill_id' => $skill->hash_id,
                    'status' => $status,
                    'level' => $empSkill?->skill_level?->value,
                    'expiry_date' => $training?->expiry_date?->toDateString(),
                ];
            });

            return [
                'employee_id' => $emp->hash_id,
                'employee_name' => $emp->full_name,
                'department' => $emp->department?->name,
                'cells' => $cells,
            ];
        });

        return response()->json([
            'data' => [
                'skills' => $skills->map(fn ($s) => [
                    'id' => $s->hash_id,
                    'name' => $s->name,
                    'category' => $s->category,
                ]),
                'rows' => $matrix,
                'summary' => [
                    'total_employees' => $employees->count(),
                    'total_skills' => $skills->count(),
                    'gap_count' => $matrix->sum(fn ($r) => $r['cells']->where('status', 'gap')->count()),
                    'expired_count' => $matrix->sum(fn ($r) => $r['cells']->where('status', 'expired')->count()),
                ],
            ],
        ]);
    }
}
```

- [ ] **Step 2: Add route**

```php
Route::get('hr/training/matrix', [TrainingMatrixController::class, 'index'])
    ->middleware('permission:hr.training.view');
```
Declare BEFORE any `{training}` param route.

- [ ] **Step 3: Create matrix heatmap page**

Create `spa/src/pages/hr/training/matrix.tsx`:
- Department filter dropdown at top
- Grid: rows = employees, columns = skills
- Each cell colored by status: green (trained), red (expired), gray (gap)
- Cell tooltip shows: skill level, expiry date
- Summary bar at top: total gaps, total expired (StatCards)
- Click on employee name → navigate to employee detail
- Click on expired cell → could link to training enrollment
- Responsive: horizontal scroll for many skills

- [ ] **Step 4: Write tests + commit**

```php
// test_training_matrix_returns_employee_skill_grid
// test_training_matrix_filters_by_department
// test_training_matrix_identifies_expired_trainings
// test_training_matrix_requires_permission
```

```bash
cd api && php artisan test --filter=TrainingMatrix
git add -A && git commit -m "feat(hr): add training matrix skill heatmap visualization"
```

---

## Execution Order

Tasks are independent and can be parallelized in groups:

**Group A (can run in parallel):** Tasks 1, 2, 3, 4, 5
**Group B (can run in parallel):** Tasks 6, 7, 8, 9, 10

Group B can start as soon as Group A commits are merged (no dependencies between groups either — they can all run simultaneously if enough agents are available).
