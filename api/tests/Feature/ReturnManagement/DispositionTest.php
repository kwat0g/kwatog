<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Enums\ReturnRequestType;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
use App\Modules\ReturnManagement\Services\ReturnRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DispositionTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::factory()->create();
    }

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'name'               => 'Disp Test Customer',
            'payment_terms_days' => 30,
        ]);
    }

    private function makeProduct(): Product
    {
        return Product::create([
            'part_number' => 'PT-' . substr(uniqid(), -5),
            'name'        => 'Test Product',
        ]);
    }

    private function makeInvoice(Customer $customer, User $by): Invoice
    {
        return Invoice::create([
            'invoice_number' => 'INV-T-' . substr(uniqid(), -5),
            'customer_id'    => $customer->id,
            'status'         => 'finalized',
            'subtotal'       => '1000.00',
            'vat_amount'     => '120.00',
            'total_amount'   => '1120.00',
            'balance'        => '1120.00',
            'date'           => now()->toDateString(),
            'due_date'       => now()->addDays(30)->toDateString(),
            'created_by'     => $by->id,
        ]);
    }

    private function makeInspectedRma(
        User $by,
        ?Customer $customer = null,
        ?Invoice $invoice = null,
        ?Product $product = null,
        string $type = 'customer_return',
    ): ReturnRequest {
        $rma = ReturnRequest::create([
            'rma_number'  => 'RMA-T-' . substr(uniqid(), -5),
            'type'        => $type,
            'status'      => ReturnRequestStatus::Inspected->value,
            'customer_id' => $customer?->id,
            'invoice_id'  => $invoice?->id,
            'reason_code' => 'defective',
            'return_date' => now()->toDateString(),
            'created_by'  => $by->id,
        ]);

        ReturnRequestItem::create([
            'return_request_id' => $rma->id,
            'product_id'        => $product?->id,
            'quantity'          => 10,
            'returned_quantity' => 8,
            'unit_price'        => 100.00,
            'total'             => 800.00,
            'reason'            => 'defective',
            'condition'         => 'damaged',
        ]);

        return $rma->load('items');
    }

    public function test_dispose_sets_item_dispositions(): void
    {
        $by = $this->makeUser();
        $customer = $this->makeCustomer();
        $rma = $this->makeInspectedRma($by, $customer);

        $svc = app(ReturnRequestService::class);

        $result = $svc->dispose($rma, [
            [
                'item_id'     => $rma->items->first()->hash_id,
                'disposition' => 'restock',
                'notes'       => 'Good condition after inspection',
            ],
        ], $by);

        $this->assertSame('disposed', $result->disposition_status);

        $item = $result->items->first();
        $this->assertSame('restock', $item->disposition);
        $this->assertSame('Good condition after inspection', $item->disposition_notes);
    }

    public function test_dispose_creates_ncr_for_scrap_items(): void
    {
        $by = $this->makeUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $rma = $this->makeInspectedRma($by, $customer, product: $product);

        $svc = app(ReturnRequestService::class);

        $result = $svc->dispose($rma, [
            [
                'item_id'     => $rma->items->first()->hash_id,
                'disposition' => 'scrap',
                'notes'       => 'Irreparable damage',
            ],
        ], $by);

        $item = $result->items->first();
        $this->assertSame('scrap', $item->disposition);
        $this->assertNotNull($item->ncr_id);

        $ncr = NonConformanceReport::find($item->ncr_id);
        $this->assertNotNull($ncr);
        $this->assertSame($product->id, $ncr->product_id);
        $this->assertStringContains('Auto-created from RMA', $ncr->defect_description);
        $this->assertSame(8, $ncr->affected_quantity);
    }

    public function test_dispose_creates_credit_memo_for_customer_return(): void
    {
        $by = $this->makeUser();
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, $by);
        $rma = $this->makeInspectedRma($by, $customer, $invoice);

        $svc = app(ReturnRequestService::class);

        $result = $svc->dispose($rma, [
            [
                'item_id'     => $rma->items->first()->hash_id,
                'disposition' => 'restock',
            ],
        ], $by);

        $this->assertNotNull($result->credit_memo_id);

        $creditMemo = Invoice::find($result->credit_memo_id);
        $this->assertNotNull($creditMemo);
        $this->assertSame($customer->id, $creditMemo->customer_id);
        $this->assertTrue((float) $creditMemo->subtotal < 0, 'Credit memo subtotal should be negative');
        $this->assertStringContains("RMA {$rma->rma_number}", $creditMemo->remarks);
    }

    public function test_dispose_rejects_non_inspected_rma(): void
    {
        $by = $this->makeUser();
        $customer = $this->makeCustomer();

        $rma = ReturnRequest::create([
            'rma_number'  => 'RMA-T-' . substr(uniqid(), -5),
            'type'        => ReturnRequestType::CustomerReturn->value,
            'status'      => ReturnRequestStatus::Received->value,
            'customer_id' => $customer->id,
            'reason_code' => 'defective',
            'return_date' => now()->toDateString(),
            'created_by'  => $by->id,
        ]);

        $svc = app(ReturnRequestService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Expected status inspected, got received.');

        $svc->dispose($rma, [], $by);
    }

    public function test_dispose_creates_ncr_for_rework_items(): void
    {
        $by = $this->makeUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $rma = $this->makeInspectedRma($by, $customer, product: $product);

        $svc = app(ReturnRequestService::class);

        $result = $svc->dispose($rma, [
            [
                'item_id'     => $rma->items->first()->hash_id,
                'disposition' => 'rework',
                'notes'       => 'Can be reworked',
            ],
        ], $by);

        $item = $result->items->first();
        $this->assertSame('rework', $item->disposition);
        $this->assertNotNull($item->ncr_id);
    }

    /**
     * Custom assertion: str_contains wrapper for readability.
     */
    private static function assertStringContains(string $needle, ?string $haystack, string $message = ''): void
    {
        static::assertNotNull($haystack, $message ?: "Expected non-null string containing '{$needle}'");
        static::assertTrue(
            str_contains($haystack, $needle),
            $message ?: "Failed asserting that '{$haystack}' contains '{$needle}'"
        );
    }
}
