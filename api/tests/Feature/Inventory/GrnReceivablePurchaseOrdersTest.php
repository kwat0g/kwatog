<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrnReceivablePurchaseOrdersTest extends TestCase
{
    use RefreshDatabase;

    private User $warehouseStaff;
    private User $employee;
    private Vendor $vendor;
    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->warehouseStaff = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'warehouse_staff')->value('id'),
        ]);
        $this->employee = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
        ]);

        $this->vendor = Vendor::factory()->create([
            'name' => 'XX-T-'.substr(uniqid(), -5),
        ]);

        $this->item = Item::factory()->create([
            'code' => 'XX-T-'.substr(uniqid(), -5),
            'name' => 'Test Item',
        ]);
    }

    public function test_warehouse_staff_can_list_receivable_pos(): void
    {
        // Create receivable and non-receivable POs
        $poSent = PurchaseOrder::factory()->create([
            'vendor_id' => $this->vendor->id,
            'status' => PurchaseOrderStatus::Sent,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $poSent->id,
            'item_id' => $this->item->id,
            'description' => 'Test Item',
            'quantity' => '100',
            'unit_price' => '10.00',
            'total' => '1000.00',
        ]);

        $poDraft = PurchaseOrder::factory()->create([
            'vendor_id' => $this->vendor->id,
            'status' => PurchaseOrderStatus::Draft,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $poDraft->id,
            'item_id' => $this->item->id,
            'description' => 'Test Item',
            'quantity' => '100',
            'unit_price' => '10.00',
            'total' => '1000.00',
        ]);

        $response = $this->actingAs($this->warehouseStaff)
            ->getJson('/api/v1/inventory/grn/receivable-purchase-orders');

        $response->assertSuccessful();
        $data = $response->json('data');
        $this->assertIsArray($data);
        $poNumbers = array_column($data, 'po_number');
        $this->assertContains($poSent->po_number, $poNumbers);
        $this->assertNotContains($poDraft->po_number, $poNumbers);
    }

    public function test_ids_in_response_are_strings(): void
    {
        $po = PurchaseOrder::factory()->create([
            'vendor_id' => $this->vendor->id,
            'status' => PurchaseOrderStatus::Sent,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $this->item->id,
            'description' => 'Test Item',
            'quantity' => '100',
            'unit_price' => '10.00',
            'total' => '1000.00',
        ]);

        $response = $this->actingAs($this->warehouseStaff)
            ->getJson('/api/v1/inventory/grn/receivable-purchase-orders');

        $response->assertSuccessful();
        $data = $response->json('data.0');
        $this->assertIsString($data['id']);
        $this->assertIsString($data['vendor']['id']);
    }

    public function test_warehouse_staff_can_show_receivable_po(): void
    {
        $po = PurchaseOrder::factory()->create([
            'vendor_id' => $this->vendor->id,
            'status' => PurchaseOrderStatus::Sent,
        ]);
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $this->item->id,
            'description' => 'Test Item',
            'quantity' => '100',
            'quantity_accepted' => '50',
            'unit_price' => '10.00',
            'total' => '1000.00',
        ]);

        $response = $this->actingAs($this->warehouseStaff)
            ->getJson("/api/v1/inventory/grn/receivable-purchase-orders/{$po->hash_id}");

        $response->assertSuccessful();
        $data = $response->json('data');
        $this->assertEquals($data['po_number'], $po->po_number);
        $this->assertIsArray($data['items']);
        $this->assertCount(1, $data['items']);
        // Verify the item has the expected fields
        $this->assertArrayHasKey('quantity_remaining', $data['items'][0]);
        $this->assertArrayHasKey('quantity', $data['items'][0]);
        $this->assertIsString($data['items'][0]['quantity_remaining']);
    }

    public function test_show_non_receivable_po_returns_422(): void
    {
        $po = PurchaseOrder::factory()->create([
            'vendor_id' => $this->vendor->id,
            'status' => PurchaseOrderStatus::Draft,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $this->item->id,
            'description' => 'Test Item',
            'quantity' => '100',
            'unit_price' => '10.00',
            'total' => '1000.00',
        ]);

        $response = $this->actingAs($this->warehouseStaff)
            ->getJson("/api/v1/inventory/grn/receivable-purchase-orders/{$po->hash_id}");

        $response->assertUnprocessable();
    }

    public function test_employee_without_permission_gets_403(): void
    {
        $po = PurchaseOrder::factory()->create([
            'vendor_id' => $this->vendor->id,
            'status' => PurchaseOrderStatus::Sent,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $this->item->id,
            'description' => 'Test Item',
            'quantity' => '100',
            'unit_price' => '10.00',
            'total' => '1000.00',
        ]);

        $response = $this->actingAs($this->employee)
            ->getJson('/api/v1/inventory/grn/receivable-purchase-orders');

        $response->assertForbidden();

        $response = $this->actingAs($this->employee)
            ->getJson("/api/v1/inventory/grn/receivable-purchase-orders/{$po->hash_id}");

        $response->assertForbidden();
    }
}
