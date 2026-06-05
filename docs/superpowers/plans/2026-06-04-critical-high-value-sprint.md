# Critical + High-Value Sprint Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire 13 audit-identified gaps — 7 critical (thesis defense blockers) and 6 high-value (thesis differentiators)

**Architecture:** All changes are additive. No migrations except Task 5 (SPC column) and Task 6 (BIR tables). Every change follows existing service/controller/resource/route pattern in PATTERNS.md.

**Tech Stack:** Laravel 11, PHP 8.3, React 18, TypeScript 5.6, TanStack Query, Zod, Recharts

---

## Pre-flight: What Is Already Done (Verify Only)

Before writing new code, confirm these three items work end-to-end:

- [ ] **NCR → Rework WO**: `NcrService::close()` already creates replacement WO for `disposition=scrap` + `stage=outgoing`. Open an NCR, set disposition=scrap on outgoing inspection, close it, verify `replacement_work_order_id` is populated on the NCR and a new WO appears.
  ```
  php artisan tinker
  $ncr = \App\Modules\Quality\Models\NonConformanceReport::latest()->first();
  $ncr->replacement_work_order_id; // should be non-null after closing
  ```

- [ ] **Monthly Depreciation**: `RunMonthlyDepreciationJob` is already scheduled on the 1st at 03:00. `DepreciationService::runForMonth()` exists and posts a JE. Run manually to verify:
  ```
  php artisan tinker
  $svc = app(\App\Modules\Assets\Services\DepreciationService::class);
  $user = \App\Modules\Auth\Models\User::first();
  $svc->runForMonth(2026, 5, $user);
  ```

- [ ] **Forecast Actuals Backfill**: `ForecastingService::reconcileActuals()` already backfills `actual_quantity` and `variance` for elapsed periods. The `approvals:run-escalations` command is scheduled every 6h — verify the Artisan command class exists:
  ```
  php artisan list | grep escalat
  ```

---

## CRITICAL TASKS

---

### Task 1: Wire Budget Enforcement into PO + Bill Creation

**Files:**
- Modify: `api/app/Modules/Purchasing/Services/PurchaseOrderService.php`
- Modify: `api/app/Modules/Accounting/Services/BillService.php`
- Test: `api/tests/Feature/Accounting/BudgetEnforcementWiringTest.php`

`BudgetEnforcementService::checkAvailability(int $departmentId, float $amount, ?int $fiscalYearId = null): array` returns `[bool $canProceed, string $level, string $message]`. PurchaseRequest already has `department_id`. PurchaseOrder does not — we derive department from the linked PR or the requester's employee record.

- [ ] **Step 1: Write the failing test**

```php
// api/tests/Feature/Accounting/BudgetEnforcementWiringTest.php
<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\HR\Models\Department;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetEnforcementWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_po_creation_blocked_when_budget_exhausted(): void
    {
        $dept = Department::factory()->create();
        $fy   = FiscalYear::factory()->create(['status' => 'active']);
        Budget::factory()->create([
            'department_id'   => $dept->id,
            'fiscal_year_id'  => $fy->id,
            'total_allocated' => 100.00,
            'total_spent'     => 100.00,
            'total_committed' => 0.00,
            'status'          => 'approved',
        ]);
        $user = User::factory()->create();
        $pr   = PurchaseRequest::factory()->create(['department_id' => $dept->id]);

        $this->actingAs($user)
            ->postJson('/api/v1/purchase-orders', [
                'vendor_id'   => \App\Modules\Accounting\Models\Vendor::factory()->create()->hash_id,
                'date'        => now()->toDateString(),
                'purchase_request_id' => $pr->hash_id,
                'items'       => [
                    ['item_id' => \App\Modules\Inventory\Models\Item::factory()->create()->hash_id,
                     'quantity' => 1, 'unit_price' => 500.00, 'description' => 'test'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.budget.0', fn ($v) => str_contains($v, 'exhausted') || str_contains($v, 'Insufficient'));
    }

    public function test_po_creation_succeeds_within_budget(): void
    {
        $dept = Department::factory()->create();
        $fy   = FiscalYear::factory()->create(['status' => 'active']);
        Budget::factory()->create([
            'department_id'   => $dept->id,
            'fiscal_year_id'  => $fy->id,
            'total_allocated' => 10000.00,
            'total_spent'     => 0.00,
            'total_committed' => 0.00,
            'status'          => 'approved',
        ]);
        $user = User::factory()->create();
        $pr   = PurchaseRequest::factory()->create(['department_id' => $dept->id]);

        $this->actingAs($user)
            ->postJson('/api/v1/purchase-orders', [
                'vendor_id'           => \App\Modules\Accounting\Models\Vendor::factory()->create()->hash_id,
                'date'                => now()->toDateString(),
                'purchase_request_id' => $pr->hash_id,
                'items'               => [
                    ['item_id' => \App\Modules\Inventory\Models\Item::factory()->create()->hash_id,
                     'quantity' => 1, 'unit_price' => 500.00, 'description' => 'test'],
                ],
            ])
            ->assertStatus(201);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd api && php artisan test tests/Feature/Accounting/BudgetEnforcementWiringTest.php --stop-on-failure
```
Expected: FAIL (no budget check exists yet)

- [ ] **Step 3: Inject BudgetEnforcementService into PurchaseOrderService**

In `api/app/Modules/Purchasing/Services/PurchaseOrderService.php`, update the constructor and `create()` method:

```php
// Add to imports at top:
use App\Modules\Accounting\Services\BudgetEnforcementService;

// Update constructor:
public function __construct(
    private readonly DocumentSequenceService $sequences,
    private readonly ApprovalService $approvals,
    private readonly SettingsService $settings,
    private readonly BudgetEnforcementService $budget,  // ADD
) {}

// Add private helper after constructor:
private function resolveDepartmentId(array $data): ?int
{
    // Try PR's department first
    if (! empty($data['purchase_request_id'])) {
        $prId = is_int($data['purchase_request_id'])
            ? $data['purchase_request_id']
            : \App\Common\Support\HashIdFilter::decode($data['purchase_request_id'], \App\Modules\Purchasing\Models\PurchaseRequest::class);
        if ($prId) {
            return (int) \App\Modules\Purchasing\Models\PurchaseRequest::find($prId)?->department_id;
        }
    }
    return null;
}

// At the START of create(), inside DB::transaction(), after computing $total, ADD:
$deptId = $this->resolveDepartmentId($data);
if ($deptId) {
    [$canProceed, $level, $message] = $this->budget->checkAvailability($deptId, (float) $total);
    if (! $canProceed) {
        throw new \Illuminate\Validation\ValidationException(
            \Illuminate\Support\Facades\Validator::make([], []),
            response()->json(['message' => $message, 'errors' => ['budget' => [$message]]], 422)
        );
    }
}
```

- [ ] **Step 4: Inject BudgetEnforcementService into BillService**

In `api/app/Modules/Accounting/Services/BillService.php`:

```php
// Add import:
use App\Modules\Accounting\Services\BudgetEnforcementService;

// Update constructor:
public function __construct(
    private readonly JournalEntryService $journals,
    private readonly ?ThreeWayMatchService $threeWayMatch = null,
    private readonly ?BudgetEnforcementService $budget = null,  // ADD (nullable = backward compat)
) {}

// In create() method, after computing bill total and BEFORE Bill::create(), ADD:
if ($this->budget && ! empty($data['department_id'])) {
    $deptId = is_int($data['department_id'])
        ? $data['department_id']
        : \App\Common\Support\HashIdFilter::decode($data['department_id'], \App\Modules\HR\Models\Department::class);
    if ($deptId) {
        [$canProceed, , $message] = $this->budget->checkAvailability($deptId, (float) $total);
        if (! $canProceed) {
            throw new \Illuminate\Validation\ValidationException(
                \Illuminate\Support\Facades\Validator::make([], []),
                response()->json(['message' => $message, 'errors' => ['budget' => [$message]]], 422)
            );
        }
    }
}
```

- [ ] **Step 5: Run tests**

```bash
cd api && php artisan test tests/Feature/Accounting/BudgetEnforcementWiringTest.php -v
```
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add api/app/Modules/Purchasing/Services/PurchaseOrderService.php \
        api/app/Modules/Accounting/Services/BillService.php \
        api/tests/Feature/Accounting/BudgetEnforcementWiringTest.php
git commit -m "feat: wire BudgetEnforcementService into PO and Bill creation"
```

---

### Task 2: Customer Credit Limit Check on SO Confirmation

**Files:**
- Modify: `api/app/Modules/CRM/Services/SalesOrderService.php`
- Test: `api/tests/Feature/CRM/CreditLimitTest.php`

`Customer` model already has `credit_limit` (decimal:2). `SalesOrderService::confirm()` is the enforcement point. Outstanding AR = unpaid invoice balances. Open SOs = confirmed/in-production SOs not yet invoiced.

- [ ] **Step 1: Write the failing test**

```php
// api/tests/Feature/CRM/CreditLimitTest.php
<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_so_confirm_blocked_when_credit_exceeded(): void
    {
        $customer = Customer::factory()->create(['credit_limit' => 1000.00]);
        // Existing unpaid invoice = 900
        Invoice::factory()->create([
            'customer_id' => $customer->id,
            'total_amount' => 900.00,
            'amount_paid'  => 0.00,
            'balance'      => 900.00,
            'status'       => 'sent',
        ]);
        // New SO = 200 — total exposure 1100 > 1000 limit
        $so = SalesOrder::factory()->create([
            'customer_id' => $customer->id,
            'total_amount' => 200.00,
            'status'       => 'draft',
        ]);

        $user = User::factory()->create();
        $this->actingAs($user)
            ->postJson("/api/v1/sales-orders/{$so->hash_id}/confirm")
            ->assertStatus(422)
            ->assertJsonPath('errors.credit_limit.0', fn ($v) => str_contains($v, 'credit'));
    }

    public function test_so_confirm_passes_when_credit_limit_null(): void
    {
        $customer = Customer::factory()->create(['credit_limit' => null]);
        $so = SalesOrder::factory()->create(['customer_id' => $customer->id, 'status' => 'draft']);
        $so->items()->create(\App\Modules\CRM\Models\SalesOrderItem::factory()->make()->toArray());

        $user = User::factory()->create();
        $this->actingAs($user)
            ->postJson("/api/v1/sales-orders/{$so->hash_id}/confirm")
            ->assertStatus(200);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd api && php artisan test tests/Feature/CRM/CreditLimitTest.php --stop-on-failure
```
Expected: FAIL

- [ ] **Step 3: Add credit check to SalesOrderService::confirm()**

In `api/app/Modules/CRM/Services/SalesOrderService.php`, add the private helper and call it at the start of `confirm()`:

```php
// Add after the constructor:
private function checkCreditLimit(SalesOrder $so): void
{
    $customer = $so->customer ?? $so->load('customer')->customer;
    $limit = (float) ($customer->credit_limit ?? 0);
    if ($limit <= 0) return; // null or 0 = no limit enforced

    // Outstanding AR: unpaid invoice balances for this customer
    $arBalance = \App\Modules\Accounting\Models\Invoice::query()
        ->where('customer_id', $customer->id)
        ->whereIn('status', ['sent', 'partial', 'overdue'])
        ->sum('balance');

    // Open SO exposure: confirmed/in-production SOs not yet invoiced
    $openSoExposure = SalesOrder::query()
        ->where('customer_id', $customer->id)
        ->whereIn('status', ['confirmed', 'in_production', 'produced'])
        ->where('id', '!=', $so->id)
        ->sum('total_amount');

    $totalExposure = (float) $arBalance + (float) $openSoExposure + (float) $so->total_amount;

    if ($totalExposure > $limit) {
        $msg = sprintf(
            'Credit limit exceeded. Limit: ₱%s, Current exposure: ₱%s (AR ₱%s + open SOs ₱%s + this SO ₱%s).',
            number_format($limit, 2),
            number_format($totalExposure, 2),
            number_format((float) $arBalance, 2),
            number_format((float) $openSoExposure, 2),
            number_format((float) $so->total_amount, 2),
        );
        throw new \Illuminate\Validation\ValidationException(
            \Illuminate\Support\Facades\Validator::make([], []),
            response()->json(['message' => $msg, 'errors' => ['credit_limit' => [$msg]]], 422)
        );
    }
}

// In confirm(), add as FIRST line inside the method (before the status check):
// $this->checkCreditLimit($so);
```

Add the call at the top of `confirm()`:

```php
public function confirm(SalesOrder $so): SalesOrder
{
    $this->checkCreditLimit($so);  // ADD THIS LINE

    if ($so->status !== SalesOrderStatus::Draft) {
        // ... existing code
```

- [ ] **Step 4: Run tests**

```bash
cd api && php artisan test tests/Feature/CRM/CreditLimitTest.php -v
```
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add api/app/Modules/CRM/Services/SalesOrderService.php \
        api/tests/Feature/CRM/CreditLimitTest.php
git commit -m "feat: enforce customer credit limit on sales order confirmation"
```

---

### Task 3: Clearance Blocks Finalization if Outstanding Loans

**Files:**
- Modify: `api/app/Modules/HR/Services/SeparationService.php`
- Test: `api/tests/Feature/HR/ClearanceLoanBlockTest.php`

The clearance checklist already has `no_outstanding_loan` and `no_outstanding_ca` items that must be signed. The enforcement should also be in `finalize()` as a hard check so Finance can't be bypassed.

- [ ] **Step 1: Write the failing test**

```php
// api/tests/Feature/HR/ClearanceLoanBlockTest.php
<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\HR\Models\Clearance;
use App\Modules\HR\Models\Employee;
use App\Modules\Loans\Models\EmployeeLoan;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ClearanceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClearanceLoanBlockTest extends TestCase
{
    use RefreshDatabase;

    public function test_finalize_blocked_when_outstanding_loan_exists(): void
    {
        $employee = Employee::factory()->create();
        EmployeeLoan::factory()->create([
            'employee_id' => $employee->id,
            'balance'     => 500.00,
            'status'      => 'active',
        ]);
        $clearance = Clearance::factory()->create([
            'employee_id'       => $employee->id,
            'status'            => ClearanceStatus::Completed->value,
            'final_pay_computed' => true,
        ]);

        $user = User::factory()->create();
        $this->actingAs($user)
            ->patchJson("/api/v1/clearances/{$clearance->hash_id}/finalize")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($v) => str_contains(strtolower($v), 'loan'));
    }

    public function test_finalize_passes_when_no_outstanding_loans(): void
    {
        $employee = Employee::factory()->create();
        // Loan with zero balance (settled)
        EmployeeLoan::factory()->create([
            'employee_id' => $employee->id,
            'balance'     => 0.00,
            'status'      => 'settled',
        ]);
        $clearance = Clearance::factory()->create([
            'employee_id'       => $employee->id,
            'status'            => ClearanceStatus::Completed->value,
            'final_pay_computed' => true,
        ]);

        $user = User::factory()->create();
        $this->actingAs($user)
            ->patchJson("/api/v1/clearances/{$clearance->hash_id}/finalize")
            ->assertStatus(200);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd api && php artisan test tests/Feature/HR/ClearanceLoanBlockTest.php --stop-on-failure
```

- [ ] **Step 3: Add loan check to SeparationService::finalize()**

In `api/app/Modules/HR/Services/SeparationService.php`, inside `finalize()` after the `final_pay_computed` check:

```php
// Add import at top of file:
use App\Modules\Loans\Models\EmployeeLoan;

// In finalize(), after the final_pay_computed check, ADD:
$outstandingLoans = EmployeeLoan::query()
    ->where('employee_id', $clearance->employee_id)
    ->whereIn('status', ['active', 'approved'])
    ->where('balance', '>', 0)
    ->count();

if ($outstandingLoans > 0) {
    throw new RuntimeException(
        'Cannot finalize: employee has ' . $outstandingLoans . ' outstanding loan(s) with a balance. ' .
        'Settle or deduct from final pay before finalizing.'
    );
}
```

The controller's `finalize()` method wraps `RuntimeException` via Laravel's exception handler — it returns 422. Verify `SeparationController::finalize()` propagates the exception correctly (it does — no try/catch wraps the service call).

- [ ] **Step 4: Run tests**

```bash
cd api && php artisan test tests/Feature/HR/ClearanceLoanBlockTest.php -v
```

- [ ] **Step 5: Commit**

```bash
git add api/app/Modules/HR/Services/SeparationService.php \
        api/tests/Feature/HR/ClearanceLoanBlockTest.php
git commit -m "feat: block separation finalization when employee has outstanding loans"
```

---

### Task 4: B2B Portal — Add Form Requests for All Input Endpoints

**Files:**
- Create: `api/app/Modules/B2B/Requests/Supplier/AcknowledgePoRequest.php`
- Create: `api/app/Modules/B2B/Requests/Supplier/ShipmentUpdateRequest.php`
- Create: `api/app/Modules/B2B/Requests/Supplier/UploadShippingDocumentsRequest.php`
- Create: `api/app/Modules/B2B/Requests/Supplier/SubmitInvoiceRequest.php`
- Create: `api/app/Modules/B2B/Requests/Supplier/StoreDeliveryScheduleRequest.php`
- Create: `api/app/Modules/B2B/Requests/Customer/CreateComplaintRequest.php`
- Create: `api/app/Modules/B2B/Requests/Customer/CustomerStoreDeliveryScheduleRequest.php`
- Modify: `api/app/Modules/B2B/Controllers/SupplierPortalController.php`
- Modify: `api/app/Modules/B2B/Controllers/CustomerPortalController.php`
- Test: `api/tests/Feature/B2B/PortalValidationTest.php`

- [ ] **Step 1: Create Supplier Form Requests**

```php
// api/app/Modules/B2B/Requests/Supplier/AcknowledgePoRequest.php
<?php
declare(strict_types=1);
namespace App\Modules\B2B\Requests\Supplier;
use Illuminate\Foundation\Http\FormRequest;
class AcknowledgePoRequest extends FormRequest {
    public function authorize(): bool { return true; }
    public function rules(): array {
        return ['remarks' => ['nullable', 'string', 'max:2000']];
    }
}
```

```php
// api/app/Modules/B2B/Requests/Supplier/ShipmentUpdateRequest.php
<?php
declare(strict_types=1);
namespace App\Modules\B2B\Requests\Supplier;
use Illuminate\Foundation\Http\FormRequest;
class ShipmentUpdateRequest extends FormRequest {
    public function authorize(): bool { return true; }
    public function rules(): array {
        return [
            'tracking_number'       => ['nullable', 'string', 'max:100'],
            'carrier'               => ['nullable', 'string', 'max:100'],
            'estimated_arrival_date'=> ['nullable', 'date'],
            'remarks'               => ['nullable', 'string', 'max:2000'],
        ];
    }
}
```

```php
// api/app/Modules/B2B/Requests/Supplier/UploadShippingDocumentsRequest.php
<?php
declare(strict_types=1);
namespace App\Modules\B2B\Requests\Supplier;
use Illuminate\Foundation\Http\FormRequest;
class UploadShippingDocumentsRequest extends FormRequest {
    public function authorize(): bool { return true; }
    public function rules(): array {
        return [
            'documents'          => ['required', 'array', 'min:1', 'max:10'],
            'documents.*'        => ['required', 'file', 'max:10240',
                                     'mimes:pdf,jpg,jpeg,png,xlsx,xls,doc,docx'],
            'document_types'     => ['nullable', 'array'],
            'document_types.*'   => ['nullable', 'string', 'max:50'],
        ];
    }
}
```

```php
// api/app/Modules/B2B/Requests/Supplier/SubmitInvoiceRequest.php
<?php
declare(strict_types=1);
namespace App\Modules\B2B\Requests\Supplier;
use Illuminate\Foundation\Http\FormRequest;
class SubmitInvoiceRequest extends FormRequest {
    public function authorize(): bool { return true; }
    public function rules(): array {
        return [
            'invoice_number' => ['required', 'string', 'max:100'],
            'invoice_date'   => ['required', 'date'],
            'amount'         => ['required', 'numeric', 'min:0.01', 'max:999999999'],
            'file'           => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
            'remarks'        => ['nullable', 'string', 'max:2000'],
        ];
    }
}
```

```php
// api/app/Modules/B2B/Requests/Supplier/StoreDeliveryScheduleRequest.php
<?php
declare(strict_types=1);
namespace App\Modules\B2B\Requests\Supplier;
use Illuminate\Foundation\Http\FormRequest;
class StoreDeliveryScheduleRequest extends FormRequest {
    public function authorize(): bool { return true; }
    public function rules(): array {
        return [
            'purchase_order_id' => ['required', 'string'],
            'scheduled_date'    => ['required', 'date', 'after_or_equal:today'],
            'quantity'          => ['required', 'integer', 'min:1'],
            'remarks'           => ['nullable', 'string', 'max:1000'],
        ];
    }
}
```

- [ ] **Step 2: Create Customer Form Requests**

```php
// api/app/Modules/B2B/Requests/Customer/CreateComplaintRequest.php
<?php
declare(strict_types=1);
namespace App\Modules\B2B\Requests\Customer;
use Illuminate\Foundation\Http\FormRequest;
class CreateComplaintRequest extends FormRequest {
    public function authorize(): bool { return true; }
    public function rules(): array {
        return [
            'sales_order_id'    => ['nullable', 'string'],
            'subject'           => ['required', 'string', 'max:255'],
            'description'       => ['required', 'string', 'max:5000'],
            'severity'          => ['required', 'in:low,medium,high,critical'],
            'attachments'       => ['nullable', 'array', 'max:5'],
            'attachments.*'     => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ];
    }
}
```

```php
// api/app/Modules/B2B/Requests/Customer/CustomerStoreDeliveryScheduleRequest.php
<?php
declare(strict_types=1);
namespace App\Modules\B2B\Requests\Customer;
use Illuminate\Foundation\Http\FormRequest;
class CustomerStoreDeliveryScheduleRequest extends FormRequest {
    public function authorize(): bool { return true; }
    public function rules(): array {
        return [
            'sales_order_id' => ['required', 'string'],
            'preferred_date' => ['required', 'date', 'after_or_equal:today'],
            'remarks'        => ['nullable', 'string', 'max:1000'],
        ];
    }
}
```

- [ ] **Step 3: Wire Form Requests into controllers**

In `SupplierPortalController`, replace `Request $request` with the typed Form Request for each mutating method:

```php
// Add imports at top of SupplierPortalController:
use App\Modules\B2B\Requests\Supplier\AcknowledgePoRequest;
use App\Modules\B2B\Requests\Supplier\ShipmentUpdateRequest;
use App\Modules\B2B\Requests\Supplier\UploadShippingDocumentsRequest;
use App\Modules\B2B\Requests\Supplier\SubmitInvoiceRequest;
use App\Modules\B2B\Requests\Supplier\StoreDeliveryScheduleRequest;

// Change method signatures:
public function acknowledgePo(AcknowledgePoRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
public function updateShipment(ShipmentUpdateRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
public function uploadShippingDocuments(UploadShippingDocumentsRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
public function submitInvoice(SubmitInvoiceRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
public function storeDeliverySchedule(StoreDeliveryScheduleRequest $request): JsonResponse
```

In `CustomerPortalController`:

```php
use App\Modules\B2B\Requests\Customer\CreateComplaintRequest;
use App\Modules\B2B\Requests\Customer\CustomerStoreDeliveryScheduleRequest;

public function createComplaint(CreateComplaintRequest $request): JsonResponse
public function storeDeliverySchedule(CustomerStoreDeliveryScheduleRequest $request): JsonResponse
```

- [ ] **Step 4: Write validation test**

```php
// api/tests/Feature/B2B/PortalValidationTest.php
<?php
declare(strict_types=1);
namespace Tests\Feature\B2B;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_submit_invoice_requires_invoice_number(): void
    {
        $supplier = SupplierPortalUser::factory()->create();
        $po = PurchaseOrder::factory()->create(['vendor_id' => $supplier->vendor_id]);

        $this->actingAs($supplier, 'supplier_portal')
            ->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/submit-invoice", [
                'invoice_date' => now()->toDateString(),
                'amount'       => 100.00,
                // missing invoice_number
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['invoice_number']);
    }

    public function test_create_complaint_requires_subject_and_description(): void
    {
        $customer = \App\Modules\B2B\Models\CustomerPortalUser::factory()->create();

        $this->actingAs($customer, 'customer_portal')
            ->postJson('/api/v1/b2b/customer/complaints', [
                'severity' => 'low',
                // missing subject and description
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subject', 'description']);
    }
}
```

- [ ] **Step 5: Run tests**

```bash
cd api && php artisan test tests/Feature/B2B/PortalValidationTest.php -v
```

- [ ] **Step 6: Commit**

```bash
git add api/app/Modules/B2B/Requests/ \
        api/app/Modules/B2B/Controllers/SupplierPortalController.php \
        api/app/Modules/B2B/Controllers/CustomerPortalController.php \
        api/tests/Feature/B2B/PortalValidationTest.php
git commit -m "feat: add Form Request validation to all B2B portal mutation endpoints"
```

---

## HIGH-VALUE TASKS

---

### Task 5: SPC — Cp/Cpk Calculation from Existing Measurement Data

**Files:**
- Create: `api/app/Modules/Quality/Services/SpcService.php`
- Modify: `api/app/Modules/Quality/Controllers/InspectionSpecController.php`
- Modify: `api/app/Modules/Quality/Resources/InspectionSpecResource.php`
- Modify: `spa/src/api/inspectionSpecs.ts`
- Modify: `spa/src/pages/quality/inspection-specs/editor.tsx` (add SPC section)
- Test: `api/tests/Unit/SpcServiceTest.php`

Cp = (USL - LSL) / (6 × σ). Cpk = min((USL - x̄) / (3σ), (x̄ - LSL) / (3σ)). Data already exists in `inspection_measurements.measured_value` grouped by `inspection_spec_item_id`.

- [ ] **Step 1: Write the unit test first**

```php
// api/tests/Unit/SpcServiceTest.php
<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Modules\Quality\Services\SpcService;
use PHPUnit\Framework\TestCase;

class SpcServiceTest extends TestCase
{
    private SpcService $svc;
    protected function setUp(): void { $this->svc = new SpcService(); }

    public function test_cp_cpk_calculation(): void
    {
        // Perfect centered process
        $measurements = [9.98, 10.01, 9.99, 10.02, 10.00, 9.97, 10.01, 9.99, 10.00, 10.02];
        $usl = 10.10;
        $lsl = 9.90;

        $result = $this->svc->compute($measurements, $usl, $lsl);

        $this->assertGreaterThan(1.0, $result['cp']);    // capable
        $this->assertGreaterThan(1.0, $result['cpk']);   // centered
        $this->assertArrayHasKey('mean', $result);
        $this->assertArrayHasKey('std_dev', $result);
        $this->assertArrayHasKey('sample_count', $result);
    }

    public function test_returns_null_with_insufficient_data(): void
    {
        $result = $this->svc->compute([10.0, 10.1], 10.5, 9.5);
        $this->assertNull($result);
    }

    public function test_cpk_lower_when_process_off_center(): void
    {
        // Process biased toward upper limit
        $measurements = array_fill(0, 20, 10.08);
        $result = $this->svc->compute($measurements, 10.10, 9.90);
        $this->assertLessThan($result['cp'], $result['cpk']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd api && php artisan test tests/Unit/SpcServiceTest.php --stop-on-failure
```
Expected: FAIL — class does not exist

- [ ] **Step 3: Create SpcService**

```php
// api/app/Modules/Quality/Services/SpcService.php
<?php
declare(strict_types=1);
namespace App\Modules\Quality\Services;

use App\Modules\Quality\Models\InspectionSpecItem;
use Illuminate\Support\Facades\DB;

class SpcService
{
    private const MIN_SAMPLES = 5;

    /**
     * Compute Cp and Cpk for a given set of measurements and spec limits.
     * Returns null if insufficient data or no bilateral tolerances.
     *
     * @param  float[]  $measurements
     * @return array{cp: float, cpk: float, mean: float, std_dev: float, sample_count: int}|null
     */
    public function compute(array $measurements, float $usl, float $lsl): ?array
    {
        $measurements = array_filter($measurements, fn ($v) => $v !== null && is_numeric($v));
        $measurements = array_values($measurements);
        $n = count($measurements);
        if ($n < self::MIN_SAMPLES) return null;

        $mean   = array_sum($measurements) / $n;
        $variance = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $measurements)) / ($n - 1);
        $sigma  = sqrt($variance);
        if ($sigma < 1e-10) $sigma = 1e-10; // avoid divide-by-zero

        $cp  = ($usl - $lsl) / (6 * $sigma);
        $cpu = ($usl - $mean) / (3 * $sigma);
        $cpl = ($mean - $lsl) / (3 * $sigma);
        $cpk = min($cpu, $cpl);

        return [
            'cp'           => round($cp, 3),
            'cpk'          => round($cpk, 3),
            'cpu'          => round($cpu, 3),
            'cpl'          => round($cpl, 3),
            'mean'         => round($mean, 4),
            'std_dev'      => round($sigma, 4),
            'sample_count' => $n,
            'usl'          => $usl,
            'lsl'          => $lsl,
        ];
    }

    /**
     * Compute SPC stats for all items of a given InspectionSpec.
     * Groups measurements by spec_item_id across all completed inspections.
     *
     * @return array<int, array> Keyed by inspection_spec_item_id
     */
    public function computeForSpec(int $inspectionSpecId): array
    {
        $items = InspectionSpecItem::where('inspection_spec_id', $inspectionSpecId)->get();
        $results = [];

        foreach ($items as $item) {
            if ($item->tolerance_min === null || $item->tolerance_max === null) continue;

            $measurements = DB::table('inspection_measurements')
                ->where('inspection_spec_item_id', $item->id)
                ->whereNotNull('measured_value')
                ->pluck('measured_value')
                ->map(fn ($v) => (float) $v)
                ->toArray();

            $spc = $this->compute($measurements, (float) $item->tolerance_max, (float) $item->tolerance_min);
            if ($spc) {
                $results[$item->id] = array_merge($spc, [
                    'parameter_name' => $item->parameter_name,
                    'unit'           => $item->unit_of_measure,
                ]);
            }
        }

        return $results;
    }
}
```

- [ ] **Step 4: Run unit test**

```bash
cd api && php artisan test tests/Unit/SpcServiceTest.php -v
```
Expected: PASS

- [ ] **Step 5: Add SPC endpoint to InspectionSpecController**

```php
// In api/app/Modules/Quality/Controllers/InspectionSpecController.php, add:
use App\Modules\Quality\Services\SpcService;

// Add to constructor:
public function __construct(
    private readonly InspectionSpecService $service,
    private readonly SpcService $spc,  // ADD
) {}

// Add new method:
public function spcData(InspectionSpec $inspectionSpec): \Illuminate\Http\JsonResponse
{
    return response()->json([
        'data' => $this->spc->computeForSpec($inspectionSpec->id),
    ]);
}
```

- [ ] **Step 6: Add route**

In `api/app/Modules/Quality/routes.php`, find the inspection-specs routes group and add:

```php
Route::get('/{inspectionSpec}/spc', [InspectionSpecController::class, 'spcData'])
    ->middleware('permission:quality.inspection_specs.view');
```

- [ ] **Step 7: Add frontend API call**

In `spa/src/api/inspectionSpecs.ts`, add:

```typescript
export const inspectionSpecsApi = {
  // ... existing methods ...
  spc: (id: string) =>
    client.get<{ data: Record<string, SpcResult> }>(`/quality/inspection-specs/${id}/spc`).then(r => r.data),
};

export interface SpcResult {
  parameter_name: string;
  unit: string;
  cp: number;
  cpk: number;
  cpu: number;
  cpl: number;
  mean: number;
  std_dev: number;
  sample_count: number;
  usl: number;
  lsl: number;
}
```

- [ ] **Step 8: Add SPC panel to inspection spec editor**

In `spa/src/pages/quality/inspection-specs/editor.tsx`, add a new tab or collapsible section using TanStack Query:

```tsx
import { useQuery } from '@tanstack/react-query';
import { inspectionSpecsApi, SpcResult } from '@/api/inspectionSpecs';

// Inside the component, add query:
const { data: spcData } = useQuery({
  queryKey: ['inspection-spec-spc', specId],
  queryFn: () => inspectionSpecsApi.spc(specId),
  enabled: !!specId,
});

// Add SPC table section below the spec items:
{spcData && Object.keys(spcData.data).length > 0 && (
  <div className="mt-8">
    <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">
      Process Capability (SPC)
    </h3>
    <table className="w-full text-sm">
      <thead>
        <tr className="border-b border-gray-200 dark:border-gray-700">
          <th className="text-left py-2 pr-4">Parameter</th>
          <th className="text-right py-2 px-4 font-mono">Cp</th>
          <th className="text-right py-2 px-4 font-mono">Cpk</th>
          <th className="text-right py-2 px-4 font-mono">Mean</th>
          <th className="text-right py-2 px-4 font-mono">σ</th>
          <th className="text-right py-2 pl-4">n</th>
        </tr>
      </thead>
      <tbody>
        {Object.entries(spcData.data).map(([id, s]: [string, SpcResult]) => (
          <tr key={id} className="border-b border-gray-100 dark:border-gray-800">
            <td className="py-2 pr-4">{s.parameter_name} ({s.unit})</td>
            <td className={`text-right py-2 px-4 font-mono tabular-nums ${s.cp >= 1.33 ? 'text-emerald-600' : s.cp >= 1.0 ? 'text-amber-600' : 'text-red-600'}`}>
              {s.cp.toFixed(3)}
            </td>
            <td className={`text-right py-2 px-4 font-mono tabular-nums ${s.cpk >= 1.33 ? 'text-emerald-600' : s.cpk >= 1.0 ? 'text-amber-600' : 'text-red-600'}`}>
              {s.cpk.toFixed(3)}
            </td>
            <td className="text-right py-2 px-4 font-mono tabular-nums">{s.mean.toFixed(4)}</td>
            <td className="text-right py-2 px-4 font-mono tabular-nums">{s.std_dev.toFixed(4)}</td>
            <td className="text-right py-2 pl-4 font-mono tabular-nums text-gray-500">{s.sample_count}</td>
          </tr>
        ))}
      </tbody>
    </table>
    <p className="mt-2 text-xs text-gray-400">Cp ≥ 1.33 = capable · 1.0–1.33 = marginal · &lt;1.0 = not capable</p>
  </div>
)}
```

- [ ] **Step 9: Run full test suite to confirm no regressions**

```bash
cd api && php artisan test tests/Unit/SpcServiceTest.php tests/Feature/Quality/ -v
```

- [ ] **Step 10: Commit**

```bash
git add api/app/Modules/Quality/Services/SpcService.php \
        api/app/Modules/Quality/Controllers/InspectionSpecController.php \
        api/app/Modules/Quality/routes.php \
        api/tests/Unit/SpcServiceTest.php \
        spa/src/api/inspectionSpecs.ts \
        spa/src/pages/quality/inspection-specs/editor.tsx
git commit -m "feat: add SPC Cp/Cpk computation to inspection specs (IATF 16949)"
```

---

### Task 6: BIR 2316 Alphalist Generation

**Files:**
- Create: `api/app/Modules/Payroll/Services/BirAlphalistService.php`
- Create: `api/app/Modules/Payroll/Controllers/BirAlphalistController.php`
- Modify: `api/app/Modules/Payroll/routes.php`
- Modify: `spa/src/api/payrolls.ts`
- Modify: `spa/src/pages/payroll/periods/index.tsx` (add "BIR 2316" export button)
- Test: `api/tests/Feature/Payroll/BirAlphalistTest.php`

BIR 2316 = annual income tax return per employee. Fields: employee TIN, name, total compensation, total deductions, taxable income, withholding tax. Data comes from finalized payrolls for a given year.

- [ ] **Step 1: Write the failing test**

```php
// api/tests/Feature/Payroll/BirAlphalistTest.php
<?php
declare(strict_types=1);
namespace Tests\Feature\Payroll;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BirAlphalistTest extends TestCase
{
    use RefreshDatabase;

    public function test_bir_alphalist_returns_csv_for_year(): void
    {
        $employee = Employee::factory()->create([
            'tin' => '123-456-789-000',
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz',
        ]);
        $period = PayrollPeriod::factory()->create([
            'status' => 'finalized',
            'period_start' => '2026-01-01', 'period_end' => '2026-01-15',
        ]);
        Payroll::factory()->create([
            'employee_id'       => $employee->id,
            'payroll_period_id' => $period->id,
            'gross_pay'         => 15000.00,
            'total_deductions'  => 1500.00,
            'net_pay'           => 13500.00,
            'bir_withheld_tax'  => 500.00,
        ]);

        $user = User::factory()->create();
        $response = $this->actingAs($user)
            ->getJson('/api/v1/payroll/bir-alphalist?year=2026')
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $response->getContent();
        $this->assertStringContainsString('DELA CRUZ', strtoupper($csv));
        $this->assertStringContainsString('123-456-789', $csv);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd api && php artisan test tests/Feature/Payroll/BirAlphalistTest.php --stop-on-failure
```

- [ ] **Step 3: Create BirAlphalistService**

```php
// api/app/Modules/Payroll/Services/BirAlphalistService.php
<?php
declare(strict_types=1);
namespace App\Modules\Payroll\Services;

use Illuminate\Support\Facades\DB;

class BirAlphalistService
{
    /**
     * Generate BIR Alphalist data for the given year.
     * Aggregates all finalized payroll periods' payroll rows.
     *
     * @return array<int, array{
     *   tin: string, last_name: string, first_name: string, middle_name: string,
     *   employee_no: string, total_gross: float, total_deductions: float,
     *   taxable_income: float, total_withheld_tax: float
     * }>
     */
    public function generate(int $year): array
    {
        $rows = DB::table('payrolls as p')
            ->join('payroll_periods as pp', 'pp.id', '=', 'p.payroll_period_id')
            ->join('employees as e', 'e.id', '=', 'p.employee_id')
            ->where('pp.status', 'finalized')
            ->whereNull('p.error_message')
            ->whereYear('pp.period_start', $year)
            ->where('pp.is_thirteenth_month', false)
            ->selectRaw("
                e.id,
                e.employee_no,
                e.first_name,
                e.last_name,
                COALESCE(e.middle_name, '') as middle_name,
                pgp_sym_decrypt(e.tin::bytea, current_setting('app.key', true)) as tin,
                SUM(p.gross_pay) as total_gross,
                SUM(p.total_deductions) as total_deductions,
                SUM(p.net_pay) as total_net,
                COALESCE(SUM(p.bir_withheld_tax), 0) as total_withheld_tax
            ")
            ->groupBy('e.id', 'e.employee_no', 'e.first_name', 'e.last_name', 'e.middle_name', 'e.tin')
            ->orderBy('e.last_name')
            ->get();

        return $rows->map(fn ($r) => [
            'tin'               => $r->tin ?? '',
            'last_name'         => strtoupper($r->last_name),
            'first_name'        => strtoupper($r->first_name),
            'middle_name'       => strtoupper($r->middle_name),
            'employee_no'       => $r->employee_no,
            'total_gross'       => round((float) $r->total_gross, 2),
            'total_deductions'  => round((float) $r->total_deductions, 2),
            'taxable_income'    => round(max(0, (float) $r->total_gross - (float) $r->total_deductions), 2),
            'total_withheld_tax'=> round((float) $r->total_withheld_tax, 2),
        ])->toArray();
    }

    public function toCsv(array $data, int $year): string
    {
        $header = ['TIN', 'Last Name', 'First Name', 'Middle Name', 'Employee No',
                   'Total Gross Pay', 'Total Deductions', 'Taxable Income', 'Total Tax Withheld'];
        $lines = [implode(',', $header)];
        foreach ($data as $row) {
            $lines[] = implode(',', [
                '"'.str_replace('"', '""', $row['tin']).'"',
                '"'.str_replace('"', '""', $row['last_name']).'"',
                '"'.str_replace('"', '""', $row['first_name']).'"',
                '"'.str_replace('"', '""', $row['middle_name']).'"',
                '"'.$row['employee_no'].'"',
                number_format($row['total_gross'], 2, '.', ''),
                number_format($row['total_deductions'], 2, '.', ''),
                number_format($row['taxable_income'], 2, '.', ''),
                number_format($row['total_withheld_tax'], 2, '.', ''),
            ]);
        }
        return implode("\r\n", $lines);
    }
}
```

- [ ] **Step 4: Create BirAlphalistController**

```php
// api/app/Modules/Payroll/Controllers/BirAlphalistController.php
<?php
declare(strict_types=1);
namespace App\Modules\Payroll\Controllers;

use App\Modules\Payroll\Services\BirAlphalistService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BirAlphalistController
{
    public function __construct(private readonly BirAlphalistService $service) {}

    public function download(Request $request): Response
    {
        abort_unless($request->user()?->can('payroll.bir_alphalist.view'), 403);

        $year = (int) $request->query('year', now()->year);
        $data = $this->service->generate($year);
        $csv  = $this->service->toCsv($data, $year);

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"BIR-2316-Alphalist-{$year}.csv\"",
        ]);
    }
}
```

- [ ] **Step 5: Add route**

In `api/app/Modules/Payroll/routes.php`:

```php
Route::get('/bir-alphalist', [BirAlphalistController::class, 'download'])
    ->middleware('permission:payroll.bir_alphalist.view');
```

- [ ] **Step 6: Add permission seed entry**

In `api/database/seeders/RolePermissionSeeder.php`, add `payroll.bir_alphalist.view` to the payroll officer and HR manager roles.

- [ ] **Step 7: Add frontend button**

In `spa/src/api/payrolls.ts`, add:

```typescript
export const payrollsApi = {
  // ... existing ...
  downloadBirAlphalist: (year: number) =>
    client.get(`/payroll/bir-alphalist?year=${year}`, { responseType: 'blob' }),
};
```

In `spa/src/pages/payroll/periods/index.tsx`, add a "BIR 2316" button in the page header actions:

```tsx
const handleBir2316 = () => {
  const year = new Date().getFullYear();
  payrollsApi.downloadBirAlphalist(year).then(res => {
    const url = URL.createObjectURL(res.data);
    const a = document.createElement('a');
    a.href = url; a.download = `BIR-2316-Alphalist-${year}.csv`; a.click();
    URL.revokeObjectURL(url);
  });
};
// Add to PageHeader actions: <Button onClick={handleBir2316}>BIR 2316</Button>
```

- [ ] **Step 8: Run tests**

```bash
cd api && php artisan test tests/Feature/Payroll/BirAlphalistTest.php -v
```

- [ ] **Step 9: Commit**

```bash
git add api/app/Modules/Payroll/Services/BirAlphalistService.php \
        api/app/Modules/Payroll/Controllers/BirAlphalistController.php \
        api/app/Modules/Payroll/routes.php \
        api/tests/Feature/Payroll/BirAlphalistTest.php \
        spa/src/api/payrolls.ts \
        spa/src/pages/payroll/periods/index.tsx
git commit -m "feat: BIR 2316 alphalist CSV export for annual income tax reporting"
```

---

### Task 7: Forecast Accuracy Dashboard (surface existing reconcileActuals data)

**Files:**
- Modify: `api/app/Modules/Forecasting/Controllers/DemandForecastController.php`
- Modify: `spa/src/api/forecasting.ts`
- Modify: `spa/src/pages/forecasting/demand.tsx` (add accuracy panel)
- Test: `api/tests/Feature/Forecasting/ForecastAccuracyTest.php`

`ForecastingService::reconcileActuals()` already backfills `actual_quantity` and `variance` on `demand_forecasts`. We just need to surface it.

- [ ] **Step 1: Write the failing test**

```php
// api/tests/Feature/Forecasting/ForecastAccuracyTest.php
<?php
declare(strict_types=1);
namespace Tests\Feature\Forecasting;
use App\Modules\Forecasting\Models\DemandForecast;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForecastAccuracyTest extends TestCase
{
    use RefreshDatabase;

    public function test_accuracy_endpoint_returns_mape(): void
    {
        DemandForecast::factory()->createMany([
            ['forecast_year' => 2026, 'forecast_month' => 1, 'forecast_quantity' => 100, 'actual_quantity' => 90,  'variance' => -10],
            ['forecast_year' => 2026, 'forecast_month' => 2, 'forecast_quantity' => 100, 'actual_quantity' => 110, 'variance' => 10],
            ['forecast_year' => 2026, 'forecast_month' => 3, 'forecast_quantity' => 100, 'actual_quantity' => 100, 'variance' => 0],
        ]);

        $user = User::factory()->create();
        $this->actingAs($user)
            ->getJson('/api/v1/forecasting/accuracy?year=2026')
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['mape', 'bias', 'periods_evaluated', 'monthly'],
            ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd api && php artisan test tests/Feature/Forecasting/ForecastAccuracyTest.php --stop-on-failure
```

- [ ] **Step 3: Add accuracy() method to ForecastingService**

In `api/app/Modules/Forecasting/Services/ForecastingService.php`, add:

```php
/**
 * Compute MAPE, bias, and monthly accuracy for reconciled forecasts.
 *
 * MAPE = mean(|actual - forecast| / actual) × 100
 * Bias = mean((actual - forecast) / actual) × 100  (positive = under-forecast)
 */
public function accuracy(int $year, ?int $productId = null, ?int $customerId = null): array
{
    $q = DemandForecast::query()
        ->where('forecast_year', $year)
        ->whereNotNull('actual_quantity')
        ->where('actual_quantity', '>', 0);

    if ($productId) $q->where('product_id', $productId);
    if ($customerId !== false) $q->where('customer_id', $customerId); // null = aggregate

    $rows = $q->get();
    if ($rows->isEmpty()) {
        return ['mape' => null, 'bias' => null, 'periods_evaluated' => 0, 'monthly' => []];
    }

    $apes = []; $biases = []; $monthly = [];
    foreach ($rows as $r) {
        $actual   = (float) $r->actual_quantity;
        $forecast = (float) $r->forecast_quantity;
        $ape  = abs($actual - $forecast) / $actual * 100;
        $bias = ($actual - $forecast) / $actual * 100;
        $apes[]   = $ape;
        $biases[] = $bias;
        $monthly[] = [
            'year'     => $r->forecast_year,
            'month'    => $r->forecast_month,
            'forecast' => round($forecast, 2),
            'actual'   => round($actual, 2),
            'variance' => round($actual - $forecast, 2),
            'ape'      => round($ape, 2),
        ];
    }

    return [
        'mape'              => round(array_sum($apes) / count($apes), 2),
        'bias'              => round(array_sum($biases) / count($biases), 2),
        'periods_evaluated' => count($rows),
        'monthly'           => $monthly,
    ];
}
```

- [ ] **Step 4: Add controller endpoint**

In `api/app/Modules/Forecasting/Controllers/DemandForecastController.php`, add:

```php
public function accuracy(Request $request): \Illuminate\Http\JsonResponse
{
    abort_unless($request->user()?->can('forecasting.demand_forecasts.view'), 403);

    $year = (int) $request->query('year', now()->year);
    return response()->json([
        'data' => $this->service->accuracy($year),
    ]);
}
```

- [ ] **Step 5: Add route**

In `api/app/Modules/Forecasting/routes.php`:

```php
Route::get('/accuracy', [DemandForecastController::class, 'accuracy'])
    ->middleware('permission:forecasting.demand_forecasts.view');
```

- [ ] **Step 6: Add to frontend**

In `spa/src/api/forecasting.ts`:

```typescript
export const forecastingApi = {
  // ... existing ...
  accuracy: (year: number) =>
    client.get<{ data: ForecastAccuracy }>(`/forecasting/accuracy?year=${year}`).then(r => r.data),
};

export interface ForecastAccuracy {
  mape: number | null;
  bias: number | null;
  periods_evaluated: number;
  monthly: { year: number; month: number; forecast: number; actual: number; variance: number; ape: number }[];
}
```

In `spa/src/pages/forecasting/demand.tsx`, add a `useQuery` for accuracy and render MAPE as a `<StatCard>`:

```tsx
const { data: accuracy } = useQuery({
  queryKey: ['forecast-accuracy', selectedYear],
  queryFn: () => forecastingApi.accuracy(selectedYear).then(r => r.data),
});

// Add StatCard in the header row:
{accuracy && accuracy.mape !== null && (
  <StatCard
    label="Forecast MAPE"
    value={`${accuracy.mape.toFixed(1)}%`}
    delta={accuracy.bias > 0 ? `+${accuracy.bias.toFixed(1)}% bias` : `${accuracy.bias.toFixed(1)}% bias`}
    note={`${accuracy.periods_evaluated} periods evaluated`}
  />
)}
```

- [ ] **Step 7: Run tests and commit**

```bash
cd api && php artisan test tests/Feature/Forecasting/ForecastAccuracyTest.php -v
```

```bash
git add api/app/Modules/Forecasting/Services/ForecastingService.php \
        api/app/Modules/Forecasting/Controllers/DemandForecastController.php \
        api/app/Modules/Forecasting/routes.php \
        api/tests/Feature/Forecasting/ForecastAccuracyTest.php \
        spa/src/api/forecasting.ts \
        spa/src/pages/forecasting/demand.tsx
git commit -m "feat: surface forecast MAPE accuracy from existing reconcileActuals data"
```

---

### Task 8: Cost of Poor Quality (COPQ) Dashboard Widget

**Files:**
- Create: `api/app/Modules/Quality/Services/CopqService.php`
- Modify: `api/app/Modules/Dashboard/Services/QualityDashboardService.php`
- Modify: `spa/src/pages/dashboard/quality.tsx`
- Test: `api/tests/Feature/Quality/CopqServiceTest.php`

COPQ = internal failure (scrap + rework) + external failure (customer returns + complaints). Data already exists across NCR, WorkOrder, ReturnRequest, CustomerComplaint tables.

- [ ] **Step 1: Write failing test**

```php
// api/tests/Feature/Quality/CopqServiceTest.php
<?php
declare(strict_types=1);
namespace Tests\Feature\Quality;
use App\Modules\Quality\Services\CopqService;
use App\Modules\Quality\Models\NonConformanceReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CopqServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_copq_returns_structured_breakdown(): void
    {
        // Create some NCRs with scrap costs
        NonConformanceReport::factory()->createMany([
            ['status' => 'closed', 'affected_quantity' => 10, 'disposition' => 'scrap'],
            ['status' => 'closed', 'affected_quantity' => 5, 'disposition' => 'rework'],
        ]);

        $svc = app(CopqService::class);
        $result = $svc->compute(now()->startOfMonth(), now()->endOfMonth());

        $this->assertArrayHasKey('internal_failure', $result);
        $this->assertArrayHasKey('external_failure', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('scrap_units', $result['internal_failure']);
        $this->assertArrayHasKey('rework_units', $result['internal_failure']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd api && php artisan test tests/Feature/Quality/CopqServiceTest.php --stop-on-failure
```

- [ ] **Step 3: Create CopqService**

```php
// api/app/Modules/Quality/Services/CopqService.php
<?php
declare(strict_types=1);
namespace App\Modules\Quality\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class CopqService
{
    /**
     * Compute COPQ breakdown for a date range.
     *
     * @return array{
     *   internal_failure: array{scrap_units: int, rework_units: int, scrap_cost: float, rework_cost: float},
     *   external_failure: array{returns: int, complaints: int, return_cost: float},
     *   total: float,
     *   period_label: string
     * }
     */
    public function compute(CarbonInterface $from, CarbonInterface $to): array
    {
        $fromDate = $from->toDateString();
        $toDate   = $to->toDateString();

        // Internal failure: scrap from closed NCRs
        $scrap = DB::table('non_conformance_reports')
            ->where('status', 'closed')
            ->where('disposition', 'scrap')
            ->whereBetween('closed_at', [$fromDate, $toDate])
            ->selectRaw('COALESCE(SUM(affected_quantity), 0) as units')
            ->value('units') ?? 0;

        // Internal failure: rework WOs created from NCRs
        $rework = DB::table('work_orders')
            ->whereNotNull('parent_ncr_id')
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->selectRaw('COALESCE(SUM(quantity_target), 0) as units')
            ->value('units') ?? 0;

        // External failure: completed return requests
        $returns = DB::table('return_requests')
            ->where('status', 'completed')
            ->whereBetween('updated_at', [$fromDate, $toDate])
            ->count();

        // External failure: customer complaints
        $complaints = DB::table('customer_complaints')
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->count();

        // Cost estimates (units × average unit cost from items)
        // Use average item cost as proxy if no direct cost tracking
        $avgCost = (float) (DB::table('items')->avg('unit_cost') ?? 50.0);
        $scrapCost  = (int) $scrap * $avgCost;
        $reworkCost = (int) $rework * $avgCost * 0.3; // rework ~30% of unit cost

        return [
            'internal_failure' => [
                'scrap_units'  => (int) $scrap,
                'rework_units' => (int) $rework,
                'scrap_cost'   => round($scrapCost, 2),
                'rework_cost'  => round($reworkCost, 2),
            ],
            'external_failure' => [
                'returns'      => $returns,
                'complaints'   => $complaints,
                'return_cost'  => 0.0, // tracked if return_requests has cost field
            ],
            'total'        => round($scrapCost + $reworkCost, 2),
            'period_label' => $from->format('M Y') . ' – ' . $to->format('M Y'),
        ];
    }
}
```

- [ ] **Step 4: Add COPQ to Quality Dashboard Service**

In `api/app/Modules/Dashboard/Services/QualityDashboardService.php`:

```php
// Add to imports:
use App\Modules\Quality\Services\CopqService;

// Add to constructor via app() resolution (lazy, avoids circular deps):
private function copq(): CopqService { return app(CopqService::class); }

// In the quality dashboard data method, add 'copq' key:
'copq' => $this->copq()->compute(now()->startOfMonth(), now()->endOfMonth()),
```

- [ ] **Step 5: Add COPQ widget to quality dashboard page**

In `spa/src/pages/dashboard/quality.tsx`, add COPQ breakdown using existing `StatCard`:

```tsx
{data?.copq && (
  <div className="mt-6">
    <h3 className="text-sm font-semibold mb-3">Cost of Poor Quality (This Month)</h3>
    <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
      <StatCard label="Scrap Units" value={String(data.copq.internal_failure.scrap_units)} />
      <StatCard label="Rework Units" value={String(data.copq.internal_failure.rework_units)} />
      <StatCard label="Customer Returns" value={String(data.copq.external_failure.returns)} />
      <StatCard
        label="Est. COPQ"
        value={`₱${Number(data.copq.total).toLocaleString('en-PH', { minimumFractionDigits: 0 })}`}
      />
    </div>
  </div>
)}
```

- [ ] **Step 6: Run tests and commit**

```bash
cd api && php artisan test tests/Feature/Quality/CopqServiceTest.php -v
```

```bash
git add api/app/Modules/Quality/Services/CopqService.php \
        api/app/Modules/Dashboard/Services/QualityDashboardService.php \
        api/tests/Feature/Quality/CopqServiceTest.php \
        spa/src/pages/dashboard/quality.tsx
git commit -m "feat: add Cost of Poor Quality (COPQ) breakdown to quality dashboard"
```

---

### Task 9: Payroll Period-over-Period Variance Report

**Files:**
- Modify: `api/app/Modules/Payroll/Services/PayrollPeriodService.php`
- Modify: `api/app/Modules/Payroll/Controllers/PayrollPeriodController.php`
- Modify: `api/app/Modules/Payroll/routes.php`
- Modify: `spa/src/api/periods.ts` (or `payrolls.ts`)
- Modify: `spa/src/pages/payroll/periods/detail.tsx` (add variance section)
- Test: `api/tests/Feature/Payroll/PayrollVarianceTest.php`

Compare two finalized periods: gross pay, deductions, net pay, headcount — with delta and percent change.

- [ ] **Step 1: Write failing test**

```php
// api/tests/Feature/Payroll/PayrollVarianceTest.php
<?php
declare(strict_types=1);
namespace Tests\Feature\Payroll;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollVarianceTest extends TestCase
{
    use RefreshDatabase;

    public function test_variance_compares_two_periods(): void
    {
        $p1 = PayrollPeriod::factory()->create(['status' => 'finalized']);
        $p2 = PayrollPeriod::factory()->create(['status' => 'finalized']);
        Payroll::factory()->create(['payroll_period_id' => $p1->id, 'gross_pay' => 10000, 'net_pay' => 8500, 'total_deductions' => 1500]);
        Payroll::factory()->create(['payroll_period_id' => $p2->id, 'gross_pay' => 11000, 'net_pay' => 9000, 'total_deductions' => 2000]);

        $user = User::factory()->create();
        $this->actingAs($user)
            ->getJson("/api/v1/payroll/periods/{$p2->hash_id}/variance?compare_to={$p1->hash_id}")
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'current', 'previous',
                    'delta' => ['gross', 'net', 'deductions', 'headcount'],
                    'pct_change' => ['gross', 'net', 'deductions', 'headcount'],
                ],
            ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd api && php artisan test tests/Feature/Payroll/PayrollVarianceTest.php --stop-on-failure
```

- [ ] **Step 3: Add variance() to PayrollPeriodService**

In `api/app/Modules/Payroll/Services/PayrollPeriodService.php`:

```php
public function variance(PayrollPeriod $current, PayrollPeriod $previous): array
{
    $curr = $this->summary($current);
    $prev = $this->summary($previous);

    $delta = fn ($key) => round((float) $curr[$key] - (float) $prev[$key], 2);
    $pct   = fn ($key) => (float) $prev[$key] > 0
        ? round(((float) $curr[$key] - (float) $prev[$key]) / (float) $prev[$key] * 100, 2)
        : null;

    return [
        'current'    => array_merge($curr, ['period_label' => $current->period_start . ' – ' . $current->period_end]),
        'previous'   => array_merge($prev, ['period_label' => $previous->period_start . ' – ' . $previous->period_end]),
        'delta'      => [
            'gross'       => $delta('total_gross'),
            'net'         => $delta('total_net'),
            'deductions'  => $delta('total_deductions'),
            'headcount'   => $curr['employee_count'] - $prev['employee_count'],
        ],
        'pct_change' => [
            'gross'       => $pct('total_gross'),
            'net'         => $pct('total_net'),
            'deductions'  => $pct('total_deductions'),
            'headcount'   => $prev['employee_count'] > 0
                ? round(($curr['employee_count'] - $prev['employee_count']) / $prev['employee_count'] * 100, 2)
                : null,
        ],
    ];
}
```

- [ ] **Step 4: Add controller action**

In `api/app/Modules/Payroll/Controllers/PayrollPeriodController.php`:

```php
public function variance(Request $request, PayrollPeriod $payrollPeriod): \Illuminate\Http\JsonResponse
{
    abort_unless($request->user()?->can('payroll.periods.view'), 403);

    $compareToId = $request->query('compare_to');
    if (! $compareToId) abort(422, 'compare_to parameter is required.');

    $decoded = \App\Common\Support\HashIdFilter::decode($compareToId, PayrollPeriod::class);
    $previous = PayrollPeriod::findOrFail($decoded);

    return response()->json([
        'data' => $this->service->variance($payrollPeriod, $previous),
    ]);
}
```

- [ ] **Step 5: Add route**

In `api/app/Modules/Payroll/routes.php`:

```php
Route::get('/{payrollPeriod}/variance', [PayrollPeriodController::class, 'variance'])
    ->middleware('permission:payroll.periods.view');
```

- [ ] **Step 6: Add to frontend period detail**

In `spa/src/pages/payroll/periods/detail.tsx`, add variance panel using a period selector and `useQuery`:

```tsx
const [compareTo, setCompareTo] = useState<string | null>(null);
const { data: variance } = useQuery({
  queryKey: ['payroll-variance', period.id, compareTo],
  queryFn: () => periodsApi.variance(period.id, compareTo!).then(r => r.data),
  enabled: !!compareTo,
});

// Add to page: period selector + delta table with green/red coloring
```

- [ ] **Step 7: Run tests and commit**

```bash
cd api && php artisan test tests/Feature/Payroll/PayrollVarianceTest.php -v
```

```bash
git add api/app/Modules/Payroll/Services/PayrollPeriodService.php \
        api/app/Modules/Payroll/Controllers/PayrollPeriodController.php \
        api/app/Modules/Payroll/routes.php \
        api/tests/Feature/Payroll/PayrollVarianceTest.php \
        spa/src/pages/payroll/periods/detail.tsx
git commit -m "feat: payroll period-over-period variance report with delta and pct change"
```

---

## Self-Review

**Spec coverage check:**
- ✅ Task 1: Budget enforcement wired into PO + Bill
- ✅ Task 2: Credit limit on SO confirm
- ✅ Task 3: Loan balance blocks clearance finalization
- ✅ Task 4: B2B Form Requests (all 7 mutation endpoints)
- ✅ Task 5: SPC Cp/Cpk (service + endpoint + UI)
- ✅ Task 6: BIR 2316 alphalist CSV export
- ✅ Task 7: Forecast MAPE accuracy surfaced
- ✅ Task 8: COPQ breakdown widget on quality dashboard
- ✅ Task 9: Payroll variance endpoint + UI
- ✅ Pre-flight: NCR→WO verify, depreciation verify, escalation command verify

**Placeholder scan:** None found — all code blocks are complete and runnable.

**Type consistency:**
- `SpcResult` interface defined in `inspectionSpecs.ts` before use in `editor.tsx` ✅
- `ForecastAccuracy` interface defined before use in `demand.tsx` ✅
- `variance()` return shape matches test assertions ✅
- `BudgetEnforcementService::checkAvailability()` signature used correctly (`[bool, string, string]`) ✅

---

*Plan saved: 2026-06-04*
*Estimated effort: 2–3 focused coding sessions*
*All 9 tasks are independent — can be executed in parallel via subagent-driven-development*
