<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Modules\Accounting\Models\CreditNote;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialReviewRecord;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Quality\Enums\InspectionParameterType;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Enums\ReturnRequestType;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
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
        $product = Product::create([
            'part_number' => 'PT-NOINV-'.substr(uniqid(), -5),
            'name' => 'No-invoice returned product',
        ]);
        $item = Item::factory()->create([
            'code' => $product->part_number,
            'item_type' => ItemType::FinishedGood->value,
        ]);
        $spec = InspectionSpec::create([
            'product_id' => $product->id,
            'version' => 1,
            'is_active' => true,
            'created_by' => $by->id,
        ]);
        InspectionSpecItem::create([
            'inspection_spec_id' => $spec->id,
            'parameter_name' => 'Return condition',
            'parameter_type' => InspectionParameterType::Visual->value,
            'is_critical' => true,
            'sort_order' => 1,
        ]);
        $salesOrder = SalesOrder::factory()->create([
            'customer_id' => $customer->id,
            'status' => SalesOrderStatus::PartiallyDelivered,
        ]);
        $source = SalesOrderItem::factory()->create([
            'sales_order_id' => $salesOrder->id,
            'product_id' => $product->id,
            'quantity_delivered' => '10.000',
            'unit_price' => '100.00',
        ]);
        $quarantineZone = WarehouseZone::factory()->create(['zone_type' => 'quarantine']);
        $quarantine = WarehouseLocation::factory()->create(['zone_id' => $quarantineZone->id]);
        $targetZone = WarehouseZone::factory()->create([
            'warehouse_id' => $quarantineZone->warehouse_id,
            'zone_type' => 'finished_goods',
        ]);
        $destination = WarehouseLocation::factory()->create(['zone_id' => $targetZone->id]);

        $rma = ReturnRequest::create([
            'rma_number'  => 'RMA-NOINV-' . substr(uniqid(), -5),
            'type'        => ReturnRequestType::CustomerReturn->value,
            'status'      => ReturnRequestStatus::Approved->value,
            'customer_id' => $customer->id,
            'sales_order_id' => $salesOrder->id,
            // Deliberately no invoice: the SO line is the only provenance.
            'invoice_id'  => null,
            'reason_code' => 'defective',
            'return_date' => now()->toDateString(),
            'created_by'  => $by->id,
        ]);
        $line = ReturnRequestItem::create([
            'return_request_id'          => $rma->id,
            'product_id'                 => $product->id,
            'item_id'                    => $item->id,
            'source_sales_order_item_id' => $source->id,
            'quantity'                   => '10.000',
            'returned_quantity'          => '0.000',
            'unit_price'                 => '100.00',
            'total'                      => '1000.00',
        ]);

        $warehouse = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'warehouse_staff')->value('id'),
        ]);
        $qc = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'qc_inspector')->value('id'),
        ]);
        $this->actingAs($warehouse)
            ->postJson('/api/v1/return-management/return-requests/'.$rma->hash_id.'/receive', [
                'received_quantities' => [$line->hash_id => '8.000'],
                'quarantine_location_id' => $quarantine->hash_id,
                'final_receipt' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'received');
        $this->assertSame(ReturnRequestStatus::Received, $rma->fresh()->status);
        $this->assertSame('8.000', (string) StockLevel::query()
            ->where('item_id', $item->id)->where('location_id', $quarantine->id)->value('quantity'));

        $this->actingAs($qc)
            ->postJson('/api/v1/return-management/return-requests/'.$rma->hash_id.'/inspect')
            ->assertOk()
            ->assertJsonPath('data.status', 'inspected');
        $inspection = Inspection::query()
            ->where('entity_type', 'return_request')
            ->where('entity_id', $rma->id)
            ->firstOrFail();
        $measurements = InspectionMeasurement::query()->where('inspection_id', $inspection->id)->get();
        $this->assertNotEmpty($measurements, 'The return inspection must have real measurement rows.');
        $this->actingAs($qc)
            ->patchJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/measurements', [
                'measurements' => $measurements->map(fn (InspectionMeasurement $measurement): array => [
                    'id' => $measurement->hash_id,
                    'is_pass' => true,
                ])->all(),
            ])
            ->assertOk();
        $this->actingAs($qc)
            ->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/complete')
            ->assertOk();
        $this->assertSame(InspectionStatus::Passed, $inspection->fresh()->status);

        $caseOwner = User::factory()->create(['role_id' => Role::query()->where('slug', 'customer_service_officer')->value('id')]);
        $this->assertFalse($caseOwner->hasPermission('inventory.view'));
        $this->actingAs($caseOwner)->getJson('/api/v1/inventory/warehouse')->assertOk();
        $movementCountBeforeDisposition = StockMovement::query()->count();
        $this->actingAs($caseOwner)->postJson('/api/v1/return-management/return-requests/'.$rma->hash_id.'/dispose', [
            'dispositions' => [['item_id' => $line->hash_id, 'disposition' => 'restock']],
        ])->assertOk();
        $mrb = MaterialReviewRecord::query()->where('return_request_item_id', $line->id)->firstOrFail();
        $this->assertSame('held', $mrb->status->value);
        $this->assertSame($caseOwner->id, (int) $mrb->held_by);
        $this->assertSame($movementCountBeforeDisposition, StockMovement::query()->count());
        $this->assertSame('8.000', (string) StockLevel::query()
            ->where('item_id', $item->id)->where('location_id', $quarantine->id)->value('quantity'));

        $this->actingAs($caseOwner)
            ->postJson('/api/v1/return-management/return-requests/'.$rma->hash_id.'/complete')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Every customer-return restock or rework line needs a completed MRB disposition before the RMA can complete.');
        $this->assertSame(ReturnRequestStatus::Inspected, $rma->fresh()->status);
        $this->assertNotNull($rma->fresh()->credit_note_id);
        $this->assertSame('draft', $rma->fresh()->creditNote->status->value);
        $this->assertSame('held', $mrb->fresh()->status->value);

        $reviewer = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'warehouse_staff')->value('id'),
        ]);
        $this->actingAs($reviewer)
            ->postJson('/api/v1/inventory/mrb/'.$mrb->hash_id.'/release', [
                'disposition' => 'use_as_is',
                'target_location_id' => $destination->hash_id,
                'inspected_quantity' => '8.000',
                'evidence_reference' => 'RMA-NOINVOICE-REINSPECTION-'.$rma->rma_number,
                'recovery_passed' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'released');
        $mrb->refresh();
        $line->refresh();
        $this->assertNotSame($caseOwner->id, $reviewer->id);
        $this->assertSame($reviewer->id, (int) $mrb->released_by);
        $this->assertSame('released', $line->quarantine_status);
        $this->assertSame('8.000', (string) $line->stock_movement_quantity);
        $this->assertSame('0.000', (string) StockLevel::query()
            ->where('item_id', $item->id)->where('location_id', $quarantine->id)->value('quantity'));
        $this->assertSame('8.000', (string) StockLevel::query()
            ->where('item_id', $item->id)->where('location_id', $destination->id)->value('quantity'));
        $movementCountAfterRelease = StockMovement::query()->count();
        $this->actingAs($caseOwner)
            ->postJson('/api/v1/return-management/return-requests/'.$rma->hash_id.'/complete')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
        $this->assertSame($movementCountAfterRelease, StockMovement::query()->count());
        $disposed = $rma->fresh();

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
