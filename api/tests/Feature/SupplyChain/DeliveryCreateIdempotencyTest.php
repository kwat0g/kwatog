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
use App\Modules\SupplyChain\Services\DeliveryService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DeliveryCreateIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_same_key_and_payload_returns_the_original_delivery(): void
    {
        [$user, $payload] = $this->fixture();
        $service = app(DeliveryService::class);

        $first = $service->create($payload, $user, 'delivery-retry-1');
        $second = $service->create($payload, $user, 'delivery-retry-1');

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->delivery_number, $second->delivery_number);
        $this->assertSame(1, $first->items()->count());
        $this->assertSame(1, \App\Modules\SupplyChain\Models\Delivery::query()->count());
    }

    public function test_same_key_with_changed_payload_is_rejected(): void
    {
        [$user, $payload] = $this->fixture();
        $service = app(DeliveryService::class);
        $service->create($payload, $user, 'delivery-retry-2');
        $payload['items'][0]['quantity'] = '4.00';

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('different delivery payload');
        $service->create($payload, $user, 'delivery-retry-2');
    }

    public function test_requests_without_a_key_remain_distinct_commands(): void
    {
        [$user, $payload] = $this->fixture();
        $service = app(DeliveryService::class);

        $first = $service->create($payload, $user);
        $payload['items'][0]['quantity'] = '4.00';
        $second = $service->create($payload, $user);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, \App\Modules\SupplyChain\Models\Delivery::query()->count());
    }

    public function test_invalid_key_is_rejected_by_the_service_contract(): void
    {
        [$user, $payload] = $this->fixture();

        $this->expectException(BusinessRuleException::class);
        app(DeliveryService::class)->create($payload, $user, str_repeat('x', 129));
    }

    /** @return array{0: User, 1: array<string, mixed>} */
    private function fixture(): array
    {
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
        $customer = Customer::create(['name' => 'Idempotency customer '.uniqid(), 'is_active' => true]);
        $product = Product::create([
            'part_number' => 'IDEM-'.substr(uniqid(), -6),
            'name' => 'Idempotency product',
            'unit_of_measure' => 'pcs',
            'standard_cost' => '10.00',
            'is_active' => true,
        ]);
        $so = SalesOrder::create([
            'so_number' => 'SO-IDEM-'.substr(uniqid(), -6),
            'customer_id' => $customer->id,
            'date' => now()->toDateString(),
            'subtotal' => '1000.00',
            'vat_amount' => '120.00',
            'total_amount' => '1120.00',
            'status' => 'confirmed',
            'created_by' => $user->id,
        ]);
        $soItem = SalesOrderItem::create([
            'sales_order_id' => $so->id,
            'product_id' => $product->id,
            'quantity' => '10.00',
            'unit_price' => '100.00',
            'total' => '1000.00',
            'quantity_delivered' => '0.00',
            'delivery_date' => now()->toDateString(),
        ]);
        $wo = WorkOrder::create([
            'wo_number' => 'WO-IDEM-'.substr(uniqid(), -6),
            'product_id' => $product->id,
            'sales_order_id' => $so->id,
            'sales_order_item_id' => $soItem->id,
            'quantity_target' => 10,
            'quantity_produced' => 10,
            'quantity_good' => 10,
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
            'good_count' => 10,
            'reject_count' => 0,
            'batch_code' => 'IDEM-BATCH-'.substr(uniqid(), -6),
        ]);
        $reviewer = User::factory()->create(['role_id' => $user->role_id]);
        $inspection = Inspection::create([
            'inspection_number' => 'QC-IDEM-'.substr(uniqid(), -6),
            'stage' => InspectionStage::Outgoing->value,
            'status' => InspectionStatus::Passed->value,
            'inspector_id' => $user->id,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'product_id' => $product->id,
            'entity_type' => InspectionEntityType::WorkOrder->value,
            'entity_id' => $wo->id,
            'work_order_output_id' => $output->id,
            'batch_quantity' => 10,
            'accepted_quantity' => 10,
            'sample_size' => 1,
            'accept_count' => 1,
            'reject_count' => 0,
            'defect_count' => 0,
            'completed_at' => now(),
        ]);

        return [$user, [
            'sales_order_id' => $so->id,
            'scheduled_date' => now()->toDateString(),
            'items' => [[
                'sales_order_item_id' => $soItem->id,
                'quantity' => '5.00',
                'inspection_id' => $inspection->id,
            ]],
        ]];
    }
}
