<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\Vehicle;
use App\Modules\SupplyChain\Services\DeliveryService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Proof and deletion operations must use the authoritative delivery row at
 * the point where they mutate state, not a stale route-bound instance.
 */
class DeliveryLifecycleConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
    }

    private function makeUser(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->orderBy('id')->value('id'),
        ]);
    }

    private function makeDelivery(User $user, string $status): Delivery
    {
        $customer = Customer::create([
            'name' => 'Delivery concurrency customer '.uniqid(),
            'is_active' => true,
        ]);
        $order = SalesOrder::create([
            'so_number' => 'SO-CONC-'.substr(uniqid(), -8),
            'customer_id' => $customer->id,
            'date' => now()->toDateString(),
            'subtotal' => '100.00',
            'vat_amount' => '12.00',
            'total_amount' => '112.00',
            'status' => 'confirmed',
            'created_by' => $user->id,
        ]);

        return Delivery::create([
            'delivery_number' => 'DEL-CONC-'.substr(uniqid(), -8),
            'sales_order_id' => $order->id,
            'status' => $status,
            'scheduled_date' => now()->toDateString(),
            'created_by' => $user->id,
        ]);
    }

    public function test_stale_delete_cannot_delete_a_delivery_that_is_now_confirmed(): void
    {
        $user = $this->makeUser();
        $delivery = $this->makeDelivery($user, 'scheduled');
        $stale = $delivery->fresh();

        $delivery->forceFill(['status' => 'confirmed'])->save();

        $this->expectException(BusinessRuleException::class);
        app(DeliveryService::class)->delete($stale);

        $this->assertNotNull(Delivery::withTrashed()->find($delivery->id));
    }

    public function test_stale_receipt_upload_cannot_add_proof_to_a_delivery_that_is_now_cancelled(): void
    {
        $user = $this->makeUser();
        $delivery = $this->makeDelivery($user, 'delivered');
        $stale = $delivery->fresh();
        $delivery->forceFill(['status' => 'cancelled'])->save();

        try {
            app(DeliveryService::class)->uploadReceiptPhoto(
                $stale,
                UploadedFile::fake()->image('receipt.jpg'),
                $user,
            );
            $this->fail('A stale receipt upload should be rejected.');
        } catch (BusinessRuleException $e) {
            $this->assertSame(
                'Receipt photo can only be uploaded after delivery is marked delivered.',
                $e->getMessage(),
            );
        }

        $this->assertDatabaseCount('delivery_proofs', 0);
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_vehicle_cannot_be_activated_for_two_deliveries_at_once(): void
    {
        $user = $this->makeUser();
        $vehicle = Vehicle::create([
            'plate_number' => 'CONC-'.substr(uniqid(), -6),
            'name' => 'Concurrency truck',
            'vehicle_type' => 'truck',
            'capacity_kg' => '1000.00',
            'status' => 'available',
        ]);
        $first = $this->makeDelivery($user, DeliveryStatus::Scheduled->value);
        $second = $this->makeDelivery($user, DeliveryStatus::Scheduled->value);
        $first->forceFill(['vehicle_id' => $vehicle->id])->save();
        $second->forceFill(['vehicle_id' => $vehicle->id])->save();

        $service = app(DeliveryService::class);
        $service->updateStatus($first->fresh(), DeliveryStatus::Loading);

        try {
            $service->updateStatus($second->fresh(), DeliveryStatus::Loading);
            $this->fail('A vehicle already carrying an active delivery must not be assigned again.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('already assigned to another active delivery', $e->getMessage());
        }

        $this->assertSame(DeliveryStatus::Loading, $first->fresh()->status);
        $this->assertSame(DeliveryStatus::Scheduled, $second->fresh()->status);
        $this->assertSame('in_use', $vehicle->fresh()->status);
    }
}
