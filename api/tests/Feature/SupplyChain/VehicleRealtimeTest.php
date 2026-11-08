<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Modules\SupplyChain\Events\VehicleChanged;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class VehicleRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_vehicle_lifecycle_and_delivery_side_effect_writes_stage_a_content_free_refresh(): void
    {
        $vehicle = Vehicle::create([
            'plate_number' => 'RT-'.substr(uniqid(), -8),
            'name' => 'Realtime test truck',
            'vehicle_type' => 'truck',
            'status' => 'available',
        ]);

        DB::transaction(function () use ($vehicle): void {
            // DeliveryService and DeliveryAttemptOutcomeService update Vehicle
            // through Eloquent; the same observer path covers both writers.
            $vehicle->update(['status' => 'in_use']);
            $vehicle->update(['status' => 'available']);
        });

        $messages = DB::table('event_outbox')
            ->where('event_type', VehicleChanged::class)
            ->orderBy('created_at')
            ->get();

        $this->assertCount(1, $messages);
        $payload = json_decode((string) $messages->first()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($vehicle->hash_id, $payload['vehicleHashId']);
        $this->assertArrayHasKey('eventId', $payload);
        $this->assertArrayNotHasKey('plate_number', $payload);
        $this->assertArrayNotHasKey('status', $payload);
    }

    public function test_delivery_assignment_and_active_state_changes_refresh_the_vehicle_aggregate(): void
    {
        $actor = User::factory()->create();
        $order = SalesOrder::factory()->create();
        $vehicle = Vehicle::create([
            'plate_number' => 'RT-'.substr(uniqid(), -8),
            'name' => 'Assigned realtime truck',
            'vehicle_type' => 'truck',
            'status' => 'available',
        ]);

        $delivery = Delivery::create([
            'delivery_number' => 'DR-RT-'.substr(uniqid(), -8),
            'sales_order_id' => $order->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'scheduled',
            'scheduled_date' => now()->toDateString(),
            'created_by' => $actor->id,
        ]);
        $delivery->update(['status' => 'cancelled']);

        $messages = DB::table('event_outbox')
            ->where('event_type', VehicleChanged::class)
            ->get();

        $this->assertCount(1, $messages);
        $payload = json_decode((string) $messages->first()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($vehicle->hash_id, $payload['vehicleHashId']);
        $this->assertArrayNotHasKey('delivery_number', $payload);
        $this->assertArrayNotHasKey('sales_order_id', $payload);
    }
}
