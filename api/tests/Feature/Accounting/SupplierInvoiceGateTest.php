<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\SupplierInvoice;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Accounting\Services\BillService;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierInvoiceGateTest extends TestCase
{
    use RefreshDatabase;

    private function stockBill(string $status): Bill
    {
        $vendor = Vendor::factory()->create();
        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        $grn = GoodsReceiptNote::factory()->create([
            'vendor_id' => $vendor->id,
            'purchase_order_id' => $po->id,
            'status' => 'accepted',
        ]);

        return Bill::factory()->create([
            'vendor_id' => $vendor->id,
            'purchase_order_id' => $po->id,
            'goods_receipt_note_id' => $grn->id,
            'provenance_type' => 'stock',
            'status' => $status,
        ]);
    }

    public function test_auto_draft_cannot_post_before_supplier_invoice_arrives(): void
    {
        $bill = $this->stockBill('draft');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Supplier invoice required before this bill can be posted or paid.');
        app(BillService::class)->postDraft($bill, User::factory()->create());
    }

    public function test_posted_legacy_stock_bill_cannot_request_payment_without_invoice(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $bill = $this->stockBill('unpaid');
        $cash = Account::query()->where('code', '1020')->firstOrFail();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Supplier invoice required before this bill can be posted or paid.');
        app(BillService::class)->recordPayment($bill, [
            'cash_account_id' => $cash->hash_id,
            'payment_date' => now()->toDateString(),
            'amount' => '1.00',
            'payment_method' => 'bank_transfer',
        ], User::factory()->create());
    }

    public function test_group_mismatch_or_cancelled_bill_blocks_other_payment(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $first = $this->stockBill('unpaid');
        $second = Bill::factory()->create([
            'vendor_id' => $first->vendor_id,
            'purchase_order_id' => $first->purchase_order_id,
            'goods_receipt_note_id' => $first->goods_receipt_note_id,
            'provenance_type' => 'stock',
            'status' => 'unpaid',
        ]);
        $invoice = SupplierInvoice::create([
            'vendor_id' => $first->vendor_id,
            'purchase_order_id' => $first->purchase_order_id,
            'number' => 'SUP-MISMATCH',
            'date' => now()->toDateString(),
            'gross_total' => '1.00',
            'submitted_at' => now(),
        ]);
        $first->update(['supplier_invoice_id' => $invoice->id]);
        $second->update(['supplier_invoice_id' => $invoice->id]);
        $cash = Account::query()->where('code', '1020')->firstOrFail();

        try {
            app(BillService::class)->recordPayment($first, [
                'cash_account_id' => $cash->hash_id,
                'payment_date' => now()->toDateString(),
                'amount' => '1.00',
                'payment_method' => 'bank_transfer',
            ], User::factory()->create());
            $this->fail('A mismatched invoice group must not be paid.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('Supplier invoice required before this bill can be posted or paid.', $e->getMessage());
        }

        $invoice->update(['gross_total' => \App\Common\Support\Money::add((string) $first->total_amount, (string) $second->total_amount)]);
        $second->update(['status' => 'cancelled']);
        $this->assertFalse($invoice->matchesLinkedBills());
        $this->expectException(BusinessRuleException::class);
        app(BillService::class)->recordPayment($first, [
            'cash_account_id' => $cash->hash_id,
            'payment_date' => now()->toDateString(),
            'amount' => '1.00',
            'payment_method' => 'bank_transfer',
        ], User::factory()->create());
    }
}
