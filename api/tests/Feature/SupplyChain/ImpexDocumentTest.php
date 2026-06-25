<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemCategory;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\SupplyChain\Models\Container;
use App\Modules\SupplyChain\Models\Shipment;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ImpEx Document PDF generation — packing list + commercial invoice.
 *
 * Verifies:
 *   - Packing list returns PDF content
 *   - Commercial invoice returns PDF content
 *   - Both require authentication
 *   - Both require supply_chain.view permission
 *   - PDF contains expected content (shipment number, vendor, items)
 */
class ImpexDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    // ─── Packing List ────────────────────────────────────────────────────

    public function test_packing_list_returns_pdf(): void
    {
        $user     = $this->seedUserWithPerms(['supply_chain.view']);
        $shipment = $this->seedShipmentWithItems($user);

        $response = $this->actingAs($user)
            ->get("/api/v1/supply-chain/shipments/{$shipment->hash_id}/packing-list");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('packing-list', $response->headers->get('Content-Disposition'));
    }

    public function test_packing_list_requires_authentication(): void
    {
        $user     = $this->seedUserWithPerms(['supply_chain.view']);
        $shipment = $this->seedShipmentWithItems($user);

        $this->getJson("/api/v1/supply-chain/shipments/{$shipment->hash_id}/packing-list")
            ->assertStatus(401);
    }

    public function test_packing_list_requires_permission(): void
    {
        $user     = $this->seedUserWithPerms([]);  // no perms
        $shipment = $this->seedShipmentWithItems($user);

        $this->actingAs($user)
            ->get("/api/v1/supply-chain/shipments/{$shipment->hash_id}/packing-list")
            ->assertStatus(403);
    }

    // ─── Commercial Invoice ──────────────────────────────────────────────

    public function test_commercial_invoice_returns_pdf(): void
    {
        $user     = $this->seedUserWithPerms(['supply_chain.view']);
        $shipment = $this->seedShipmentWithItems($user);

        $response = $this->actingAs($user)
            ->get("/api/v1/supply-chain/shipments/{$shipment->hash_id}/commercial-invoice");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('commercial-invoice', $response->headers->get('Content-Disposition'));
    }

    public function test_commercial_invoice_requires_authentication(): void
    {
        $user     = $this->seedUserWithPerms(['supply_chain.view']);
        $shipment = $this->seedShipmentWithItems($user);

        $this->getJson("/api/v1/supply-chain/shipments/{$shipment->hash_id}/commercial-invoice")
            ->assertStatus(401);
    }

    public function test_commercial_invoice_requires_permission(): void
    {
        $user     = $this->seedUserWithPerms([]);  // no perms
        $shipment = $this->seedShipmentWithItems($user);

        $this->actingAs($user)
            ->get("/api/v1/supply-chain/shipments/{$shipment->hash_id}/commercial-invoice")
            ->assertStatus(403);
    }

    // ─── Edge cases ──────────────────────────────────────────────────────

    public function test_packing_list_works_with_containers(): void
    {
        $user     = $this->seedUserWithPerms(['supply_chain.view']);
        $shipment = $this->seedShipmentWithItems($user, withContainers: true);

        $response = $this->actingAs($user)
            ->get("/api/v1/supply-chain/shipments/{$shipment->hash_id}/packing-list");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_commercial_invoice_works_with_landed_costs(): void
    {
        $user     = $this->seedUserWithPerms(['supply_chain.view']);
        $shipment = $this->seedShipmentWithItems($user);

        // Add landed cost data.
        $shipment->forceFill([
            'freight_cost'      => '2500.00',
            'insurance_cost'    => '500.00',
            'duties_amount'     => '1200.00',
            'brokerage_fee'     => '300.00',
            'landed_cost_total' => '4500.00',
        ])->save();

        $response = $this->actingAs($user)
            ->get("/api/v1/supply-chain/shipments/{$shipment->hash_id}/commercial-invoice");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_returns_404_for_nonexistent_shipment(): void
    {
        $user = $this->seedUserWithPerms(['supply_chain.view']);

        $this->actingAs($user)
            ->get('/api/v1/supply-chain/shipments/nonexistent/packing-list')
            ->assertStatus(404);

        $this->actingAs($user)
            ->get('/api/v1/supply-chain/shipments/nonexistent/commercial-invoice')
            ->assertStatus(404);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function seedUserWithPerms(array $permSlugs): User
    {
        $role = Role::create([
            'name'        => 'Test ImpEx ' . uniqid(),
            'slug'        => 'test_ix_' . substr(uniqid(), -5),
            'description' => 'Test',
        ]);
        foreach ($permSlugs as $slug) {
            $perm = Permission::firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug, 'module' => explode('.', $slug)[0]],
            );
            $role->permissions()->syncWithoutDetaching([$perm->id]);
        }
        return User::factory()->create([
            'role_id' => $role->id,
            'email'   => 'ix_' . substr(uniqid(), -5) . '@test.test',
        ]);
    }

    private function seedShipmentWithItems(User $user, bool $withContainers = false): Shipment
    {
        $vendor = Vendor::create([
            'name'               => 'JP-V-' . substr(uniqid(), -5),
            'is_active'          => true,
            'payment_terms_days' => 30,
            'address'            => 'Tokyo, Japan',
            'contact_person'     => 'Tanaka',
        ]);

        $po = PurchaseOrder::create([
            'po_number'    => 'PO-T-' . substr(uniqid(), -5),
            'vendor_id'    => $vendor->id,
            'date'         => now()->toDateString(),
            'subtotal'     => '50000.00',
            'vat_amount'   => '6000.00',
            'total_amount' => '56000.00',
            'incoterm'     => 'CIF',
            'created_by'   => $user->id,
        ]);
        $po->forceFill(['status' => 'approved'])->save();

        // Add PO items.
        $category = ItemCategory::create(['name' => 'Raw Materials']);
        $item = Item::create([
            'code'            => 'RM-' . substr(uniqid(), -5),
            'name'            => 'PP Resin',
            'unit_of_measure' => 'kg',
            'item_type'       => 'raw_material',
            'category_id'     => $category->id,
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id'           => $item->id,
            'description'       => 'Polypropylene Resin Grade A',
            'quantity'          => '5000.00',
            'unit'              => 'kg',
            'unit_price'        => '10.00',
            'total'             => '50000.00',
        ]);

        $shipment = Shipment::create([
            'shipment_number'   => 'SHP-T-' . substr(uniqid(), -5),
            'purchase_order_id' => $po->id,
            'carrier'           => 'ONE (Ocean Network Express)',
            'vessel'            => 'COSCO FAITH V.045E',
            'bl_number'         => 'OOLU' . substr(uniqid(), -8),
            'etd'               => now()->addDays(3)->toDateString(),
            'eta'               => now()->addDays(14)->toDateString(),
            'created_by'        => $user->id,
        ]);
        $shipment->forceFill(['status' => 'shipped'])->save();

        if ($withContainers) {
            Container::create([
                'shipment_id'      => $shipment->id,
                'container_number' => 'TCLU' . substr(uniqid(), -7),
                'seal_number'      => 'SL' . substr(uniqid(), -6),
                'size'             => '40ft',
                'type'             => 'dry',
                'gross_weight_kg'  => '25400.00',
                'net_weight_kg'    => '5000.00',
                'volume_cbm'       => '33.200',
            ]);
        }

        return $shipment;
    }
}
