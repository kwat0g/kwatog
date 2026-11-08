<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Common\Events\ChainStepAdvanced;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\SupplyChain\Models\DeliveryStockReservationBatch;
use App\Modules\SupplyChain\Services\DeliveryStockReservationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HasDeliveryStock;
use Tests\TestCase;

final class DeliveryStockReservationRealtimeTest extends TestCase
{
    use HasDeliveryStock;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_recovering_a_legacy_delivery_reservation_stages_one_refresh(): void
    {
        $actor = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
        [$delivery] = $this->deliveryWithApprovedStock($actor, Customer::factory()->create());

        $reservations = app(DeliveryStockReservationService::class);
        $batch = DeliveryStockReservationBatch::query()->where('delivery_id', $delivery->id)->firstOrFail();
        $reservations->releaseForDelivery($delivery);
        $batch->reservations()->delete();
        $batch->delete();

        $initialSignals = DB::table('event_outbox')->where('event_type', ChainStepAdvanced::class)->count();
        $requestKey = (string) \Illuminate\Support\Str::uuid();

        $this->actingAs($actor)->postJson(
            "/api/v1/supply-chain/deliveries/{$delivery->hash_id}/reserve-stock",
            ['request_key' => $requestKey],
        )->assertOk();

        $this->assertSame(
            $initialSignals + 1,
            DB::table('event_outbox')->where('event_type', ChainStepAdvanced::class)->count(),
            'The recovered reservation is visible on the delivery detail and must stage one refresh.',
        );

        $this->actingAs($actor)->postJson(
            "/api/v1/supply-chain/deliveries/{$delivery->hash_id}/reserve-stock",
            ['request_key' => $requestKey],
        )->assertOk();

        $this->assertSame($initialSignals + 1, DB::table('event_outbox')->where('event_type', ChainStepAdvanced::class)->count());
    }
}
