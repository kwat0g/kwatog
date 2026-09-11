<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Modules\Accounting\Models\CreditNote;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Enums\ReturnRequestType;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
use App\Modules\ReturnManagement\Services\ReturnRequestService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG 1 regression: a customer return whose only provenance is a sales-order
 * (or delivery) line — no invoice — must still produce a draft customer credit
 * note. The old `&& $rma->invoice_id` gate on dispose() silently dropped the
 * whole credit whenever the return was not tied to an invoice.
 */
class CustomerReturnNoInvoiceCreditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
    }

    private function user(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    public function test_customer_return_without_an_invoice_credits_from_its_sales_order_line(): void
    {
        $by = $this->user();
        $customer = Customer::create(['name' => 'No-Invoice RMA Customer', 'payment_terms_days' => 30]);
        $item = Item::factory()->create();
        $source = SalesOrderItem::factory()->create();
        $quarantineZone = WarehouseZone::factory()->create(['zone_type' => 'quarantine']);
        $quarantine = WarehouseLocation::factory()->create(['zone_id' => $quarantineZone->id]);
        $destination = WarehouseLocation::factory()->create();

        $rma = ReturnRequest::create([
            'rma_number'  => 'RMA-NOINV-' . substr(uniqid(), -5),
            'type'        => ReturnRequestType::CustomerReturn->value,
            'status'      => ReturnRequestStatus::Approved->value,
            'customer_id' => $customer->id,
            // Deliberately no invoice: the SO line is the only provenance.
            'invoice_id'  => null,
            'reason_code' => 'defective',
            'return_date' => now()->toDateString(),
            'created_by'  => $by->id,
        ]);
        $line = ReturnRequestItem::create([
            'return_request_id'          => $rma->id,
            'item_id'                    => $item->id,
            'source_sales_order_item_id' => $source->id,
            'quantity'                   => '10.000',
            'returned_quantity'          => '8.000',
            'unit_price'                 => '100.00',
            'total'                      => '1000.00',
        ]);

        $service = app(ReturnRequestService::class);
        $received = $service->receive($rma, [$line->id => '8.000'], $quarantine->id, $by);
        $inspected = $service->inspect($received, 'Eight units returned.', $by);
        $disposed = $service->dispose($inspected, [[
            'item_id'     => $line->hash_id,
            'disposition' => 'restock',
        ]], $by, false, $destination->id);

        $disposed = $service->complete($disposed, $by);

        $this->assertSame(ReturnRequestStatus::Completed, $disposed->status);
        $this->assertNotNull($disposed->credit_note_id, 'A no-invoice customer return must still raise a credit note.');

        $credit = CreditNote::findOrFail($disposed->credit_note_id);
        $this->assertSame('customer', $credit->type->value);
        $this->assertSame('draft', $credit->status->value);
        $this->assertNull($credit->invoice_id, 'The credit keeps a null invoice reference when the return has no invoice.');
        $this->assertSame($rma->id, $credit->return_request_id);
        // 8 returned × ₱100.00 = ₱800.00 credited.
        $this->assertSame('800.00', (string) $credit->subtotal);
    }
}
