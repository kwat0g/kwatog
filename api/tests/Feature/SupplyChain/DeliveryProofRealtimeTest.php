<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Common\Events\ChainStepAdvanced;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\SupplyChain\Controllers\DeliveryProofController;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryProof;
use App\Modules\SupplyChain\Models\Vehicle;
use App\Modules\SupplyChain\Services\DeliveryService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class DeliveryProofRealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
    }

    public function test_archiving_a_proof_stages_one_delivery_refresh(): void
    {
        [$delivery, $proof] = $this->deliveryWithProof();
        $initialSignals = $this->signalCount();

        $response = app(DeliveryProofController::class)->destroy($delivery, $proof);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame($initialSignals + 1, $this->signalCount());
    }

    public function test_restoring_a_proof_stages_one_delivery_refresh(): void
    {
        [$delivery, $proof] = $this->deliveryWithProof();
        Storage::disk('local')->put($proof->file_path, 'proof');
        $proof->delete();
        $initialSignals = $this->signalCount();

        $response = app(DeliveryProofController::class)->restore($delivery, $proof);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($initialSignals + 1, $this->signalCount());
    }

    public function test_editing_a_loading_shipment_plan_stages_one_delivery_refresh(): void
    {
        $actor = User::factory()->create();
        $driver = User::factory()->withRole('driver')->create(['is_active' => true]);
        $customer = Customer::create(['name' => 'Shipment plan refresh '.uniqid(), 'is_active' => true]);
        $salesOrder = SalesOrder::create([
            'so_number' => 'SO-SPR-'.substr(uniqid(), -6),
            'customer_id' => $customer->id,
            'date' => now()->toDateString(),
            'subtotal' => '100.00',
            'vat_amount' => '12.00',
            'total_amount' => '112.00',
            'status' => 'confirmed',
            'created_by' => $actor->id,
        ]);
        $vehicle = Vehicle::create([
            'plate_number' => 'SPR-'.substr(uniqid(), -6),
            'name' => 'Shipment plan refresh vehicle',
            'vehicle_type' => 'van',
            'capacity_kg' => '1000.00',
            'status' => 'in_use',
        ]);
        $delivery = Delivery::create([
            'delivery_number' => 'DEL-SPR-'.substr(uniqid(), -6),
            'sales_order_id' => $salesOrder->id,
            'status' => 'loading',
            'scheduled_date' => now()->toDateString(),
            'expected_delivery_date' => now()->addDay()->toDateString(),
            'carrier' => 'Current carrier',
            'vehicle_id' => $vehicle->id,
            'driver_id' => $driver->id,
            'created_by' => $actor->id,
        ]);
        $initialSignals = $this->signalCount();

        app(DeliveryService::class)->saveShipmentPlan($delivery, [
            'vehicle_id' => $vehicle->id,
            'driver_id' => $driver->id,
            'reason' => 'Corrected loading schedule.',
            'scheduled_date' => now()->addDay()->toDateString(),
            'expected_delivery_date' => now()->addDays(2)->toDateString(),
            'carrier' => 'Updated carrier',
            'dispatch' => false,
        ], $actor);

        $this->assertSame($initialSignals + 1, $this->signalCount());
    }

    /** @return array{0: Delivery, 1: DeliveryProof} */
    private function deliveryWithProof(): array
    {
        $user = User::factory()->create();
        $customer = Customer::create(['name' => 'Delivery proof refresh '.uniqid(), 'is_active' => true]);
        $salesOrder = SalesOrder::create([
            'so_number' => 'SO-DPR-'.substr(uniqid(), -6),
            'customer_id' => $customer->id,
            'date' => now()->toDateString(),
            'subtotal' => '100.00',
            'vat_amount' => '12.00',
            'total_amount' => '112.00',
            'status' => 'confirmed',
            'created_by' => $user->id,
        ]);
        $delivery = Delivery::create([
            'delivery_number' => 'DEL-DPR-'.substr(uniqid(), -6),
            'sales_order_id' => $salesOrder->id,
            'status' => 'scheduled',
            'scheduled_date' => now()->toDateString(),
            'created_by' => $user->id,
        ]);
        $proof = DeliveryProof::create([
            'delivery_id' => $delivery->id,
            'proof_type' => 'other',
            'file_name' => 'support.pdf',
            'file_path' => 'deliveries/'.$delivery->id.'/proofs/support.pdf',
            'mime_type' => 'application/pdf',
            'uploaded_by' => $user->id,
        ]);

        return [$delivery, $proof];
    }

    private function signalCount(): int
    {
        return DB::table('event_outbox')->where('event_type', ChainStepAdvanced::class)->count();
    }
}
