<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\SupplierInvoice;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Accounting\Services\BillService;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierInvoiceGroupingTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_supplier_invoice_links_two_receipt_bills_without_merging_them(): void
    {
        $vendor = Vendor::factory()->create();
        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        $first = Bill::factory()->create(['vendor_id' => $vendor->id, 'purchase_order_id' => $po->id]);
        $second = Bill::factory()->create(['vendor_id' => $vendor->id, 'purchase_order_id' => $po->id]);

        $invoice = SupplierInvoice::create([
            'vendor_id' => $vendor->id,
            'purchase_order_id' => $po->id,
            'number' => 'SUP-100',
            'date' => '2026-10-08',
            'gross_total' => '11200.00',
            'submitted_at' => now(),
        ]);
        $first->update(['supplier_invoice_id' => $invoice->id]);
        $second->update(['supplier_invoice_id' => $invoice->id]);

        $this->assertSame(2, $invoice->bills()->count());
        $this->assertSame($invoice->id, $first->fresh()->supplierInvoice->id);
        $this->assertSame($invoice->id, $second->fresh()->supplierInvoice->id);
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_void_releases_only_unposted_unpaid_bill_portions(): void
    {
        $vendor = Vendor::factory()->create();
        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        $invoice = SupplierInvoice::create([
            'vendor_id' => $vendor->id, 'purchase_order_id' => $po->id,
            'number' => 'SUP-VOID', 'date' => '2026-10-08',
            'gross_total' => '200.00', 'submitted_at' => now(),
        ]);
        $bill = Bill::factory()->create([
            'vendor_id' => $vendor->id, 'purchase_order_id' => $po->id,
            'supplier_invoice_id' => $invoice->id, 'supplier_invoice_number' => 'SUP-VOID',
            'supplier_invoice_date' => '2026-10-08', 'total_amount' => '200.00',
            'status' => 'draft',
        ]);

        app(BillService::class)->voidSupplierInvoice($invoice, User::factory()->create(), 'Supplier corrected invoice.');

        $this->assertNotNull($invoice->fresh()->voided_at);
        $this->assertSame('Supplier corrected invoice.', $invoice->fresh()->void_reason);
        $this->assertNull($bill->fresh()->supplier_invoice_id);
        $this->assertNull($bill->fresh()->supplier_invoice_number);
    }

    public function test_void_rejects_a_posted_bill(): void
    {
        $vendor = Vendor::factory()->create();
        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        $invoice = SupplierInvoice::create([
            'vendor_id' => $vendor->id, 'purchase_order_id' => $po->id,
            'number' => 'SUP-POSTED', 'date' => '2026-10-08',
            'gross_total' => '200.00', 'submitted_at' => now(),
        ]);
        Bill::factory()->create([
            'vendor_id' => $vendor->id, 'purchase_order_id' => $po->id,
            'supplier_invoice_id' => $invoice->id, 'total_amount' => '200.00',
            'status' => 'unpaid',
        ]);

        $this->expectException(BusinessRuleException::class);
        app(BillService::class)->voidSupplierInvoice($invoice, User::factory()->create(), 'Not allowed.');
    }
}
