<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Enums\ReturnRequestType;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
use App\Modules\ReturnManagement\Services\ReturnRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * 2026-08-08 — restock moved to dispose(): customer-return restock/rework lines
 * are received back into stock when the disposition is recorded. Completion
 * therefore only needs a location when a line STILL has to move (supplier
 * return_to_supplier, or a legacy customer flow that never provided one).
 */
class ReturnRequestCompleteRequiresLocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_throws_when_a_line_still_needs_to_move_and_no_location_given(): void
    {
        $by       = User::factory()->create();
        $vendor   = \App\Modules\Accounting\Models\Vendor::factory()->create(['created_by' => null]);
        $item     = Item::factory()->create();
        $po       = \App\Modules\Purchasing\Models\PurchaseOrder::factory()->create([
            'vendor_id'  => $vendor->id,
            'created_by' => $by->id,
        ]);

        $rma = ReturnRequest::create([
            'rma_number'        => 'RMA-SUP-'.substr(uniqid(), -6),
            'type'              => ReturnRequestType::SupplierReturn->value,
            'status'            => ReturnRequestStatus::Inspected->value,
            'purchase_order_id' => $po->id,
            'vendor_id'         => $vendor->id,
            'reason_code'       => 'quality_issue',
            'return_date'       => now()->toDateString(),
            'created_by'        => $by->id,
        ]);
        $rma->forceFill(['disposition_status' => 'disposed'])->save();

        ReturnRequestItem::create([
            'return_request_id' => $rma->id,
            'item_id'           => $item->id,
            'quantity'          => '2.000',
            'returned_quantity' => '2.000',
            'unit_price'        => '5.00',
            'total'             => '10.00',
            // Supplier-return lines ship out at completion — the movement is
            // still pending here, so a location is mandatory.
            'disposition'       => 'return_to_supplier',
        ]);

        $svc = app(ReturnRequestService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A warehouse location is required to complete a return.');

        $svc->complete($rma->load('items'), $by, null);
    }

    public function test_complete_without_location_succeeds_when_lines_were_restocked_at_dispose(): void
    {
        $by       = User::factory()->create();
        $customer = Customer::create(['name' => 'Test Customer', 'payment_terms_days' => 30]);
        $item     = Item::factory()->create();
        $loc      = WarehouseLocation::factory()->create();

        $rma = ReturnRequest::create([
            'rma_number'  => 'RMA-T-'.substr(uniqid(), -6),
            'type'        => ReturnRequestType::CustomerReturn->value,
            'status'      => ReturnRequestStatus::Inspected->value,
            'customer_id' => $customer->id,
            'reason_code' => 'defective',
            'return_date' => now()->toDateString(),
            'created_by'  => $by->id,
        ]);
        $rma->forceFill(['disposition_status' => 'disposed'])->save();

        $line = ReturnRequestItem::create([
            'return_request_id' => $rma->id,
            'item_id'           => $item->id,
            'quantity'          => '4.000',
            'returned_quantity' => '4.000',
            'unit_price'        => '100.00',
            'total'             => '400.00',
            'disposition'       => 'restock',
        ]);

        // Simulate the dispose-time restock: the line already moved at dispose.
        app(StockMovementService::class)->move(new StockMovementInput(
            type: StockMovementType::AdjustmentIn,
            itemId: $item->id,
            toLocationId: $loc->id,
            quantity: '4.000',
            unitCost: '0.00',
            referenceType: 'return_request',
            referenceId: $rma->id,
            createdBy: $by->id,
        ));
        $line->update(['stock_movement_quantity' => '4.000']);

        $completed = app(ReturnRequestService::class)->complete($rma->load('items'), $by, null);

        $this->assertSame(ReturnRequestStatus::Completed, $completed->status);
        $this->assertSame(
            1,
            StockMovement::query()
                ->where('reference_type', 'return_request')
                ->where('reference_id', $rma->id)
                ->count(),
            'Complete must not move an already-restocked line again.',
        );
    }
}
