<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryItem;
use App\Modules\SupplyChain\Services\DeliveryService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryQuantityReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private DeliveryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->service = app(DeliveryService::class);
    }

    public function test_combined_duplicate_lines_cannot_exceed_sales_order_quantity(): void
    {
        [$user, $so, $soItem, $inspection] = $this->arrange(quantity: '10');

        try {
            $this->service->create([
                'sales_order_id' => $so->id,
                'scheduled_date' => now()->toDateString(),
                'items' => [
                    [
                        'sales_order_item_id' => $soItem->id,
                        'quantity' => '6',
                        'inspection_id' => $inspection->id,
                    ],
                    [
                        'sales_order_item_id' => $soItem->id,
                        'quantity' => '5',
                        'inspection_id' => $inspection->id,
                    ],
                ],
            ], $user);
            $this->fail('Combined duplicate lines must not exceed the SO quantity.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('exceeds the remaining quantity', $e->getMessage());
        }

        $this->assertDatabaseCount('deliveries', 0);
    }

    public function test_existing_scheduled_reservation_is_excluded_from_remaining_quantity(): void
    {
        [$user, $so, $soItem, $inspection] = $this->arrange(quantity: '10');

        $first = $this->service->create([
            'sales_order_id' => $so->id,
            'scheduled_date' => now()->toDateString(),
            'items' => [[
                'sales_order_item_id' => $soItem->id,
                'quantity' => '6',
                'inspection_id' => $inspection->id,
            ]],
        ], $user);

        try {
            $this->service->create([
                'sales_order_id' => $so->id,
                'scheduled_date' => now()->addDay()->toDateString(),
                'items' => [[
                    'sales_order_item_id' => $soItem->id,
                    'quantity' => '5',
                    'inspection_id' => $inspection->id,
                ]],
            ], $user);
            $this->fail('A second reservation must not exceed the remaining SO quantity.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('remaining quantity (4.00)', $e->getMessage());
        }

        $this->assertSame(1, Delivery::query()->count());
        $this->assertSame('6.000', (string) $first->items->first()->quantity);
    }

    public function test_output_inspection_capacity_is_independent_and_partial_reservations_cannot_overrun_it(): void
    {
        [$user, $so, $soItem, $inspection] = $this->arrange(quantity: '10');
        $soItem->update(['quantity' => '20']);

        $this->service->create([
            'sales_order_id' => $so->id,
            'scheduled_date' => now()->toDateString(),
            'items' => [[
                'sales_order_item_id' => $soItem->id,
                'quantity' => '6',
                'inspection_id' => $inspection->id,
            ]],
        ], $user);

        try {
            $this->service->create([
                'sales_order_id' => $so->id,
                'scheduled_date' => now()->addDay()->toDateString(),
                'items' => [[
                    'sales_order_item_id' => $soItem->id,
                    'quantity' => '5',
                    'inspection_id' => $inspection->id,
                ]],
            ], $user);
            $this->fail('A delivery must not reserve more than the passed output quantity.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('remaining accepted quantity (4.00)', $e->getMessage());
        }
    }

    public function test_cancelled_reservation_releases_output_inspection_capacity(): void
    {
        [$user, $so, $soItem, $inspection] = $this->arrange(quantity: '10');
        $delivery = $this->service->create([
            'sales_order_id' => $so->id,
            'scheduled_date' => now()->toDateString(),
            'items' => [[
                'sales_order_item_id' => $soItem->id,
                'quantity' => '6',
                'inspection_id' => $inspection->id,
            ]],
        ], $user);
        $this->service->updateStatus($delivery, DeliveryStatus::Cancelled);

        $replacement = $this->service->create([
            'sales_order_id' => $so->id,
            'scheduled_date' => now()->addDay()->toDateString(),
            'items' => [[
                'sales_order_item_id' => $soItem->id,
                'quantity' => '10',
                'inspection_id' => $inspection->id,
            ]],
        ], $user);

        $this->assertSame(DeliveryStatus::Scheduled, $replacement->status);
        $this->assertSame('10.000', (string) $replacement->items->first()->quantity);
    }

    public function test_legacy_passed_inspection_cannot_authorize_delivery(): void
    {
        [$user, $so, $soItem, $inspection] = $this->arrange(quantity: '10');
        $inspection->update(['work_order_output_id' => null]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Legacy product/WO-only inspections cannot authorize');
        $this->service->create([
            'sales_order_id' => $so->id,
            'scheduled_date' => now()->toDateString(),
            'items' => [[
                'sales_order_item_id' => $soItem->id,
                'quantity' => '1',
                'inspection_id' => $inspection->id,
            ]],
        ], $user);
    }

    public function test_inspection_for_different_product_cannot_authorize_delivery(): void
    {
        [$user, $so, $soItem, $inspection] = $this->arrange(quantity: '10');
        $otherProduct = Product::create([
            'part_number' => strtoupper(substr(uniqid('OTHER-'), 0, 18)),
            'name' => 'Other product',
            'unit_of_measure' => 'pcs',
            'standard_cost' => '50.00',
            'is_active' => true,
        ]);
        $inspection->update(['product_id' => $otherProduct->id]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('not provenance-linked');
        $this->service->create([
            'sales_order_id' => $so->id,
            'scheduled_date' => now()->toDateString(),
            'items' => [[
                'sales_order_item_id' => $soItem->id,
                'quantity' => '1',
                'inspection_id' => $inspection->id,
            ]],
        ], $user);
    }

    public function test_inspection_bound_to_different_work_order_cannot_authorize_delivery(): void
    {
        [$user, $so, $soItem, $inspection] = $this->arrange(quantity: '10');
        $inspection->update(['entity_id' => $inspection->entity_id + 999999]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('not provenance-linked');
        $this->service->create([
            'sales_order_id' => $so->id,
            'scheduled_date' => now()->toDateString(),
            'items' => [[
                'sales_order_item_id' => $soItem->id,
                'quantity' => '1',
                'inspection_id' => $inspection->id,
            ]],
        ], $user);
    }

    public function test_output_work_order_for_different_sales_order_cannot_authorize_delivery(): void
    {
        [$user, $so, $soItem, $inspection] = $this->arrange(quantity: '10');
        $otherSo = SalesOrder::create([
            'so_number' => 'SO-OTHER-'.substr(uniqid(), -8),
            'customer_id' => $so->customer_id,
            'date' => now()->toDateString(),
            'subtotal' => '0.00',
            'vat_amount' => '0.00',
            'total_amount' => '0.00',
            'status' => 'confirmed',
            'created_by' => $user->id,
        ]);
        WorkOrder::query()->whereKey($inspection->entity_id)->update(['sales_order_id' => $otherSo->id]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('not provenance-linked');
        $this->service->create([
            'sales_order_id' => $so->id,
            'scheduled_date' => now()->toDateString(),
            'items' => [[
                'sales_order_item_id' => $soItem->id,
                'quantity' => '1',
                'inspection_id' => $inspection->id,
            ]],
        ], $user);
    }

    public function test_delivered_transition_reconciles_sales_order_quantity(): void
    {
        [$user, $so, $soItem] = $this->arrange(quantity: '10');

        $delivery = Delivery::create([
            'delivery_number' => 'DL-QTY-'.substr(uniqid(), -8),
            'sales_order_id' => $so->id,
            'status' => DeliveryStatus::InTransit->value,
            'scheduled_date' => now()->toDateString(),
            'created_by' => $user->id,
        ]);
        DeliveryItem::create([
            'delivery_id' => $delivery->id,
            'sales_order_item_id' => $soItem->id,
            'quantity' => '4',
            'unit_price' => '50.00',
        ]);

        $this->service->updateStatus($delivery, DeliveryStatus::Delivered);

        $this->assertSame('4.00', (string) $soItem->fresh()->quantity_delivered);
        $this->assertSame(DeliveryStatus::Delivered, $delivery->fresh()->status);
    }

    public function test_delivered_shipment_cannot_be_deleted_or_cancelled(): void
    {
        [$user, $so, $soItem] = $this->arrange(quantity: '10');
        $delivery = Delivery::create([
            'delivery_number' => 'DL-QTY-'.substr(uniqid(), -8),
            'sales_order_id' => $so->id,
            'status' => DeliveryStatus::Delivered->value,
            'scheduled_date' => now()->toDateString(),
            'created_by' => $user->id,
        ]);
        DeliveryItem::create([
            'delivery_id' => $delivery->id,
            'sales_order_item_id' => $soItem->id,
            'quantity' => '4',
            'unit_price' => '50.00',
        ]);

        try {
            $this->service->delete($delivery);
            $this->fail('A delivered shipment must not be deleted.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('customer return', $e->getMessage());
        }

        try {
            $this->service->updateStatus($delivery, DeliveryStatus::Cancelled);
            $this->fail('A delivered shipment must not be cancelled.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('customer return', $e->getMessage());
        }
    }

    /**
     * @return array{0: User, 1: SalesOrder, 2: SalesOrderItem, 3: Inspection}
     */
    private function arrange(string $quantity): array
    {
        $role = Role::firstOrCreate(['slug' => 'delivery_qty_test'], ['name' => 'Delivery Quantity Test']);
        $user = User::factory()->create(['role_id' => $role->id]);
        $customer = Customer::create([
            'name' => 'Delivery Quantity Customer '.uniqid(),
            'is_active' => true,
        ]);
        $product = Product::create([
            'part_number' => strtoupper(substr(uniqid('PT-'), 0, 12)),
            'name' => 'Delivery Quantity Product '.uniqid(),
            'unit_of_measure' => 'pcs',
            'standard_cost' => '50.00',
            'is_active' => true,
        ]);
        $so = SalesOrder::create([
            'so_number' => 'SO-QTY-'.substr(uniqid(), -8),
            'customer_id' => $customer->id,
            'date' => now()->toDateString(),
            'subtotal' => '500.00',
            'vat_amount' => '60.00',
            'total_amount' => '560.00',
            'status' => 'confirmed',
            'created_by' => $user->id,
        ]);
        $soItem = SalesOrderItem::create([
            'sales_order_id' => $so->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => '50.00',
            'total' => bcmul($quantity, '50.00', 2),
            'quantity_delivered' => '0.00',
            'delivery_date' => now()->addDays(7)->toDateString(),
        ]);
        $wo = WorkOrder::create([
            'wo_number' => 'WO-QTY-'.substr(uniqid(), -8),
            'product_id' => $product->id,
            'sales_order_id' => $so->id,
            'sales_order_item_id' => $soItem->id,
            'quantity_target' => $quantity,
            'quantity_produced' => $quantity,
            'quantity_good' => $quantity,
            'quantity_rejected' => 0,
            'planned_start' => now()->subDay(),
            'planned_end' => now(),
            'status' => 'completed',
            'created_by' => $user->id,
        ]);
        $output = WorkOrderOutput::create([
            'work_order_id' => $wo->id,
            'recorded_by' => $user->id,
            'recorded_at' => now(),
            'good_count' => (int) $quantity,
            'reject_count' => 0,
            'batch_code' => 'QTY-BATCH-001',
        ]);
        $inspection = Inspection::create([
            'inspection_number' => 'QC-QTY-'.substr(uniqid(), -8),
            'stage' => InspectionStage::Outgoing->value,
            'status' => InspectionStatus::Passed->value,
            'product_id' => $product->id,
            'entity_type' => InspectionEntityType::WorkOrder->value,
            'entity_id' => $wo->id,
            'work_order_output_id' => $output->id,
            'batch_quantity' => (float) $quantity,
            'accepted_quantity' => (int) $quantity,
            'sample_size' => 1,
            'accept_count' => 1,
            'reject_count' => 0,
            'defect_count' => 0,
            'completed_at' => now(),
        ]);

        return [$user, $so, $soItem, $inspection];
    }
}
