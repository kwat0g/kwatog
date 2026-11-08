<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\ShipmentLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShipmentLotRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_shipment_lot_writes_stage_one_content_free_refresh_per_transaction(): void
    {
        $actor = User::factory()->create();
        $customer = Customer::factory()->create();
        $salesOrder = SalesOrder::factory()->create(['customer_id' => $customer->id]);
        $delivery = Delivery::create([
            'delivery_number' => 'DL-LOT-RT-001',
            'sales_order_id' => $salesOrder->id,
            'status' => 'delivered',
            'scheduled_date' => now()->toDateString(),
            'created_by' => $actor->id,
        ]);
        $lotHash = null;

        DB::transaction(function () use ($actor, $customer, $delivery, &$lotHash): void {
            $lot = ShipmentLot::create([
                'lot_number' => 'LOT-RT-0001',
                'delivery_id' => $delivery->id,
                'customer_id' => $customer->id,
                'work_order_ids' => [],
                'quantity' => 10,
                'lot_date' => now()->toDateString(),
                'created_by' => $actor->id,
            ]);
            $lotHash = $lot->hash_id;
            $lot->update(['coc_path' => 'certificates/lot-rt-0001.pdf']);
        });

        $messages = DB::table('event_outbox')
            ->where('event_type', \App\Modules\SupplyChain\Events\ShipmentLotChanged::class)
            ->get();

        $this->assertCount(1, $messages);
        $payload = json_decode((string) $messages->first()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($lotHash, $payload['shipmentLotHashId']);
        $this->assertSame('updated', $payload['action']);
        $this->assertArrayNotHasKey('lot_number', $payload);
        $this->assertArrayNotHasKey('coc_path', $payload);
    }
}
