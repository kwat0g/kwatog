<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\BudgetLineItem;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Services\BudgetService;
use App\Modules\HR\Models\Department;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GoodsReceiptNoteItem;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetCommitmentNettingTest extends TestCase
{
    use RefreshDatabase;

    private FiscalYear $fiscalYear;
    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fiscalYear = $this->currentFiscalYear();
        $this->department = Department::factory()->create();
    }

    public function test_draft_bill_does_not_release_commitment(): void
    {
        $account = $this->expenseAccount();
        $budget = $this->createBudget('100.00');
        BudgetLineItem::create(['budget_id' => $budget->id, 'account_id' => $account->id, 'jan' => '100.00']);

        $po = $this->createPurchaseOrder('50.00');

        // Commitment should be 50.00 (order not yet billed)
        $this->assertSame('50.00', app(BudgetService::class)->overview($this->fiscalYear->id)['total_committed']);

        // Create a draft bill (auto-created on GRN acceptance)
        Bill::create([
            'bill_number' => 'B-'.uniqid(),
            'purchase_order_id' => $po->id,
            'vendor_id' => $po->vendor_id,
            'total_amount' => '50.00',
            'status' => 'draft',
            'date' => now(),
            'due_date' => now()->addDays(30),
        ]);

        // Commitment should STILL be 50.00 (draft bill doesn't release commitment)
        $this->assertSame('50.00', app(BudgetService::class)->overview($this->fiscalYear->id)['total_committed']);
    }

    public function test_posted_bill_releases_commitment(): void
    {
        $account = $this->expenseAccount();
        $budget = $this->createBudget('100.00');
        BudgetLineItem::create(['budget_id' => $budget->id, 'account_id' => $account->id, 'jan' => '100.00']);

        $po = $this->createPurchaseOrder('50.00');

        // Commitment should be 50.00
        $this->assertSame('50.00', app(BudgetService::class)->overview($this->fiscalYear->id)['total_committed']);

        // Create an unpaid bill (commitment is released)
        Bill::create([
            'bill_number' => 'B-'.uniqid(),
            'purchase_order_id' => $po->id,
            'vendor_id' => $po->vendor_id,
            'total_amount' => '50.00',
            'status' => 'unpaid',
            'date' => now(),
            'due_date' => now()->addDays(30),
        ]);

        // Commitment should be 0.00 (bill is posted/unpaid, commitment is released)
        $this->assertSame('0.00', app(BudgetService::class)->overview($this->fiscalYear->id)['total_committed']);
    }

    public function test_partial_bill_payment_reduces_commitment(): void
    {
        $account = $this->expenseAccount();
        $budget = $this->createBudget('100.00');
        BudgetLineItem::create(['budget_id' => $budget->id, 'account_id' => $account->id, 'jan' => '100.00']);

        $po = $this->createPurchaseOrder('50.00');

        // Create a bill
        Bill::create([
            'bill_number' => 'B-'.uniqid(),
            'purchase_order_id' => $po->id,
            'vendor_id' => $po->vendor_id,
            'total_amount' => '50.00',
            'status' => 'partial',
            'date' => now(),
            'due_date' => now()->addDays(30),
        ]);

        // Commitment should be 0.00 (bill is posted, commitment released)
        $this->assertSame('0.00', app(BudgetService::class)->overview($this->fiscalYear->id)['total_committed']);
    }

    public function test_paid_bill_releases_commitment(): void
    {
        $account = $this->expenseAccount();
        $budget = $this->createBudget('100.00');
        BudgetLineItem::create(['budget_id' => $budget->id, 'account_id' => $account->id, 'jan' => '100.00']);

        $po = $this->createPurchaseOrder('50.00');

        // Create a paid bill
        Bill::create([
            'bill_number' => 'B-'.uniqid(),
            'purchase_order_id' => $po->id,
            'vendor_id' => $po->vendor_id,
            'total_amount' => '50.00',
            'status' => 'paid',
            'date' => now(),
            'due_date' => now()->addDays(30),
        ]);

        // Commitment should be 0.00 (bill is paid, commitment released)
        $this->assertSame('0.00', app(BudgetService::class)->overview($this->fiscalYear->id)['total_committed']);
    }

    public function test_short_closed_po_commits_only_the_accepted_share_incl_vat(): void
    {
        $account = $this->expenseAccount();
        $budget = $this->createBudget('500.00');
        BudgetLineItem::create(['budget_id' => $budget->id, 'account_id' => $account->id, 'jan' => '500.00']);

        // 10 × 10.00 + 12% VAT = 112.00; 8 received, 1 of them rejected → 7 accepted.
        $po = $this->createPurchaseOrder('112.00');
        \App\Modules\Purchasing\Models\PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => \App\Modules\Inventory\Models\Item::factory()->create()->id,
            'description' => 'Resin',
            'quantity' => '10',
            'unit_price' => '10.00',
            'total' => '100.00',
            'quantity_received' => '8',
            'quantity_accepted' => '7',
        ]);
        $po->forceFill(['status' => 'closed'])->save();

        // Accepted share of the gross order: 112.00 × 70 / 100.
        $this->assertSame('78.40', app(BudgetService::class)->overview($this->fiscalYear->id)['total_committed']);

        Bill::create([
            'bill_number' => 'B-'.uniqid(),
            'purchase_order_id' => $po->id,
            'vendor_id' => $po->vendor_id,
            'total_amount' => '78.40',
            'status' => 'unpaid',
            'date' => now(),
            'due_date' => now()->addDays(30),
        ]);

        $this->assertSame('0.00', app(BudgetService::class)->overview($this->fiscalYear->id)['total_committed']);
    }

    public function test_cancelled_po_commits_zero(): void
    {
        $account = $this->expenseAccount();
        $budget = $this->createBudget('100.00');
        BudgetLineItem::create(['budget_id' => $budget->id, 'account_id' => $account->id, 'jan' => '100.00']);

        $po = $this->createPurchaseOrder('50.00');

        // Commitment should be 50.00
        $this->assertSame('50.00', app(BudgetService::class)->overview($this->fiscalYear->id)['total_committed']);

        // Cancel the PO (without any GRNs or bills, this should succeed)
        $po->forceFill(['status' => 'cancelled'])->save();

        // Commitment should be 0.00
        $this->assertSame('0.00', app(BudgetService::class)->overview($this->fiscalYear->id)['total_committed']);
    }

    private function createBudget(string $allocated): Budget
    {
        return Budget::factory()->create([
            'fiscal_year_id' => $this->fiscalYear->id,
            'department_id' => $this->department->id,
            'status' => 'active',
            'total_allocated' => $allocated,
            'total_spent' => '0.00',
        ]);
    }

    private function createPurchaseOrder(string $amount): PurchaseOrder
    {
        $pr = $this->purchaseRequest();
        return PurchaseOrder::factory()->create([
            'vendor_id' => $this->vendor()->id,
            'purchase_request_id' => $pr->id,
            'status' => 'approved',
            'total_amount' => $amount,
        ]);
    }

    private function purchaseRequest(): PurchaseRequest
    {
        return PurchaseRequest::factory()->create([
            'department_id' => $this->department->id,
        ]);
    }

    private function vendor()
    {
        return \App\Modules\Accounting\Models\Vendor::factory()->create();
    }

    private function expenseAccount()
    {
        return \App\Modules\Accounting\Models\Account::create([
            'code' => 'B-'.substr(uniqid(), -6),
            'name' => 'Budget test expense',
            'type' => 'expense',
            'normal_balance' => 'debit',
            'is_active' => true,
        ]);
    }

    private function currentFiscalYear(): FiscalYear
    {
        return FiscalYear::factory()->create([
            'status' => 'active',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);
    }
}
