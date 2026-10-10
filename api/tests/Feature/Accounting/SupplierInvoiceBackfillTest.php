<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\B2B\Models\PortalShippingDocument;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierInvoiceBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_additive_migration_keeps_existing_bills_and_documents_through_up_and_down(): void
    {
        $migration = require database_path('migrations/2026_10_09_000100_create_supplier_invoices.php');
        $migration->down();

        $vendor = Vendor::factory()->create();
        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        $unpaid = Bill::factory()->create([
            'vendor_id' => $vendor->id, 'purchase_order_id' => $po->id,
            'status' => 'unpaid', 'total_amount' => '100.00', 'balance' => '100.00',
        ]);
        $paid = Bill::factory()->create([
            'vendor_id' => $vendor->id, 'purchase_order_id' => $po->id,
            'status' => 'paid', 'total_amount' => '200.00', 'amount_paid' => '200.00', 'balance' => '0.00',
        ]);
        $cancelled = Bill::factory()->create([
            'vendor_id' => $vendor->id, 'purchase_order_id' => $po->id,
            'status' => 'cancelled', 'total_amount' => '100.00', 'balance' => '0.00',
        ]);
        foreach ([$unpaid, $paid, $cancelled] as $bill) {
            $bill->forceFill([
                'supplier_invoice_number' => $bill === $paid ? 'LEGACY-PAID' : 'LEGACY-OPEN',
                'supplier_invoice_date' => '2026-10-08',
            ])->save();
        }
        $document = PortalShippingDocument::create([
            'purchase_order_id' => $po->id,
            'bill_id' => $unpaid->id,
            'document_type' => 'supplier_invoice',
            'file_path' => 'legacy/invoice.pdf',
            'original_filename' => 'invoice.pdf',
            'file_size_bytes' => 4,
            'content_sha256' => str_repeat('a', 64),
            'mime_type' => 'application/pdf',
            'uploaded_at' => now(),
        ]);

        $migration->up();

        $this->assertNotNull(DB::table('bills')->where('id', $unpaid->id)->value('supplier_invoice_id'));
        $this->assertNotNull(DB::table('bills')->where('id', $paid->id)->value('supplier_invoice_id'));
        $this->assertNull(DB::table('bills')->where('id', $cancelled->id)->value('supplier_invoice_id'));
        $this->assertSame(2, DB::table('supplier_invoices')->count());
        $this->assertSame(
            DB::table('bills')->where('id', $unpaid->id)->value('supplier_invoice_id'),
            DB::table('portal_shipping_documents')->where('id', $document->id)->value('supplier_invoice_id'),
        );
        $this->assertSame('200.00', (string) DB::table('bills')->where('id', $paid->id)->value('amount_paid'));

        $migration->down();
        $this->assertSame('LEGACY-OPEN', DB::table('bills')->where('id', $unpaid->id)->value('supplier_invoice_number'));
        $this->assertSame('LEGACY-PAID', DB::table('bills')->where('id', $paid->id)->value('supplier_invoice_number'));
        $this->assertSame('legacy/invoice.pdf', DB::table('portal_shipping_documents')->where('id', $document->id)->value('file_path'));
        $migration->up();
    }
}
