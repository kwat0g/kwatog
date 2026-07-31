<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: POST /supply-chain/shipments with a container number.
 *
 * `container_number` is a real shipments column, accepted by
 * CreateShipmentRequest and submitted by the create form
 * (spa/src/pages/supply-chain/shipments/create.tsx), but it was missing from
 * Shipment::$fillable. With `Model::preventSilentlyDiscardingAttributes()`
 * enabled outside production (AppServiceProvider), the create threw
 * MassAssignmentException — so filling in Container No. broke the form.
 *
 * The pre-existing shipment tests never sent it on the HTTP path: the only
 * `container_number` in the suite is set on the separate `containers` table.
 */
class ShipmentCreateTest extends TestCase
{
    use RefreshDatabase;

    private function impexUser(): User
    {
        $role = Role::create([
            'name' => 'ImpEx Test '.uniqid(),
            'slug' => 'impex_t_'.substr(uniqid(), -6),
            'description' => 'Test',
        ]);
        foreach (['supply_chain.view', 'supply_chain.shipments.manage'] as $slug) {
            $perm = Permission::firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug, 'module' => 'supply_chain'],
            );
            $role->permissions()->syncWithoutDetaching([$perm->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function approvedPo(User $by): PurchaseOrder
    {
        $vendor = Vendor::create(['name' => 'Vendor '.uniqid(), 'is_active' => true]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-T-'.substr(uniqid(), -5),
            'vendor_id' => $vendor->id,
            'date' => now()->toDateString(),
            'subtotal' => '5000.00',
            'vat_amount' => '600.00',
            'total_amount' => '5600.00',
            'created_by' => $by->id,
        ]);
        $po->forceFill(['status' => 'approved'])->save();

        return $po;
    }

    public function test_create_shipment_persists_container_number(): void
    {
        $user = $this->impexUser();
        $po = $this->approvedPo($user);

        $response = $this->actingAs($user)
            ->postJson('/api/v1/supply-chain/shipments', [
                'purchase_order_id' => $po->hash_id,
                'carrier' => 'COSCO',
                'vessel' => 'COSCO FAITH V.045E',
                'container_number' => 'TCLU1234567',
                'bl_number' => 'OOLU7654321',
                'etd' => now()->addDays(3)->toDateString(),
                'eta' => now()->addDays(14)->toDateString(),
            ])
            ->assertSuccessful();

        $response->assertJsonPath('data.container_number', 'TCLU1234567');
        $this->assertDatabaseHas('shipments', [
            'purchase_order_id' => $po->id,
            'container_number' => 'TCLU1234567',
        ]);
    }

    /** Omitting the optional field must still succeed. */
    public function test_create_shipment_without_container_number(): void
    {
        $user = $this->impexUser();
        $po = $this->approvedPo($user);

        $this->actingAs($user)
            ->postJson('/api/v1/supply-chain/shipments', [
                'purchase_order_id' => $po->hash_id,
                'carrier' => 'MAERSK',
            ])
            ->assertSuccessful();

        $this->assertDatabaseHas('shipments', [
            'purchase_order_id' => $po->id,
            'container_number' => null,
        ]);
    }

    /** updateMeta must persist a later container assignment too. */
    public function test_update_meta_persists_container_number(): void
    {
        $user = $this->impexUser();
        $po = $this->approvedPo($user);

        $created = $this->actingAs($user)
            ->postJson('/api/v1/supply-chain/shipments', [
                'purchase_order_id' => $po->hash_id,
            ])
            ->assertSuccessful()
            ->json('data.id');

        $this->actingAs($user)
            ->patchJson("/api/v1/supply-chain/shipments/{$created}", [
                'container_number' => 'MSCU7654321',
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.container_number', 'MSCU7654321');
    }
}
