<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\SupplyChain\Models\Shipment;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ShipmentPurchaseOrderOptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_impex_can_select_a_sent_po_created_by_a_buyer_without_receiving_po_costs(): void
    {
        $impex = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'impex_officer')->value('id'),
        ]);
        $buyer = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'purchasing_officer')->value('id'),
        ]);
        $sent = PurchaseOrder::factory()->create([
            'po_number' => 'PO-IMPEX-SENT',
            'created_by' => $buyer->id,
        ]);
        $sent->forceFill(['status' => PurchaseOrderStatus::Sent->value])->save();

        foreach ([
            ['PO-IMPEX-ACK', PurchaseOrderStatus::Acknowledged],
            ['PO-IMPEX-PARTIAL', PurchaseOrderStatus::PartiallyReceived],
            ['PO-IMPEX-APPROVED', PurchaseOrderStatus::Approved],
            ['PO-IMPEX-PROPOSED', PurchaseOrderStatus::SupplierProposed],
            ['PO-IMPEX-DECLINED', PurchaseOrderStatus::SupplierDeclined],
        ] as [$number, $status]) {
            $po = PurchaseOrder::factory()->create([
                'po_number' => $number,
                'created_by' => $buyer->id,
            ]);
            $po->forceFill(['status' => $status->value])->save();
        }
        PurchaseOrder::factory()->create([
            'po_number' => 'PO-IMPEX-DRAFT',
            'created_by' => $buyer->id,
        ]);

        $response = $this->actingAs($impex, 'sanctum')
            ->getJson('/api/v1/supply-chain/shipments/purchase-order-options?per_page=100')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->assertEqualsCanonicalizing(
            ['PO-IMPEX-SENT', 'PO-IMPEX-ACK', 'PO-IMPEX-PARTIAL'],
            array_column($response->json('data'), 'po_number'),
        );
        $sentOption = array_values(array_filter(
            $response->json('data'),
            static fn (array $row): bool => $row['po_number'] === 'PO-IMPEX-SENT',
        ))[0];
        $this->assertSame($sent->hash_id, $sentOption['id']);
        $this->assertSame(['id', 'po_number', 'vendor', 'incoterm'], array_keys($sentOption));
        $this->assertArrayNotHasKey('total_amount', $sentOption);
        $this->assertArrayNotHasKey('items', $sentOption);

        $this->actingAs($impex, 'sanctum')
            ->getJson('/api/v1/supply-chain/shipments/purchase-order-options?search=PO-IMPEX-ACK')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.po_number', 'PO-IMPEX-ACK');
    }

    public function test_purchasing_officer_without_shipment_manage_cannot_list_shipment_po_options(): void
    {
        $role = Role::query()->create([
            'name' => 'Shipment PO viewer',
            'slug' => 'shipment_po_viewer_'.substr(uniqid(), -6),
            'is_system' => false,
        ]);
        $role->permissions()->sync([Permission::query()->where('slug', 'purchasing.view')->value('id')]);
        $viewer = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/supply-chain/shipments/purchase-order-options')
            ->assertForbidden();
    }

    public function test_approved_po_cannot_create_shipment_before_supplier_dispatch(): void
    {
        $impex = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'impex_officer')->value('id'),
        ]);
        $buyer = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'purchasing_officer')->value('id'),
        ]);
        $po = PurchaseOrder::factory()->create(['created_by' => $buyer->id]);
        $po->forceFill(['status' => PurchaseOrderStatus::Approved->value])->save();

        $shipmentType = (new Shipment)->getMorphClass();
        $shipmentCount = Shipment::query()->count();
        $auditCount = DB::table('audit_logs')->where('model_type', $shipmentType)->count();
        $sequenceBefore = DB::table('document_sequences')
            ->where('document_type', 'shipment')
            ->value('last_number');

        $this->actingAs($impex, 'sanctum')
            ->postJson('/api/v1/supply-chain/shipments', ['purchase_order_id' => $po->hash_id])
            ->assertUnprocessable()
            ->assertJsonPath('message', "Purchase order {$po->po_number} is not open for shipment.");

        $this->assertSame($shipmentCount, Shipment::query()->count());
        $this->assertDatabaseMissing('shipments', ['purchase_order_id' => $po->id]);
        $this->assertSame($auditCount, DB::table('audit_logs')->where('model_type', $shipmentType)->count());
        $this->assertSame($sequenceBefore, DB::table('document_sequences')
            ->where('document_type', 'shipment')
            ->value('last_number'));
    }
}
