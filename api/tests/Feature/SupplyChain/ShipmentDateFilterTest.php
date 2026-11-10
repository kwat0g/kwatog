<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\SupplyChain\Enums\ShipmentStatus;
use App\Modules\SupplyChain\Models\Shipment;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShipmentDateFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_shipment_list_filters_inclusively_by_actual_or_expected_shipment_date(): void
    {
        $viewer = User::factory()->withRole('impex_officer')->create(['is_active' => true]);
        $insideStart = $this->shipment(eta: '2026-10-01');
        $insideEnd = $this->shipment(ata: '2026-10-07');
        $outside = $this->shipment(eta: '2026-09-30');

        $response = $this->actingAs($viewer)->getJson('/api/v1/supply-chain/shipments?from_date=2026-10-01&to_date=2026-10-07')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$insideStart->hash_id, $insideEnd->hash_id], $ids);
        $this->assertNotContains($outside->hash_id, $ids);
    }

    public function test_shipment_list_rejects_an_inverted_date_range(): void
    {
        $viewer = User::factory()->withRole('impex_officer')->create(['is_active' => true]);

        $this->actingAs($viewer)->getJson('/api/v1/supply-chain/shipments?from_date=2026-10-08&to_date=2026-10-01')
            ->assertUnprocessable()->assertJsonValidationErrors('to_date');
    }

    private function shipment(?string $eta = null, ?string $ata = null): Shipment
    {
        return Shipment::create([
            'shipment_number' => 'SHIP-'.strtoupper(substr(uniqid(), -8)),
            'purchase_order_id' => PurchaseOrder::factory()->create()->id,
            'status' => ShipmentStatus::Ordered,
            'eta' => $eta,
            'ata' => $ata,
            'created_by' => User::factory()->withRole('impex_officer')->create()->id,
        ]);
    }
}
