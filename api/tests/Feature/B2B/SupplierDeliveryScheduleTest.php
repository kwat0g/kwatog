<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\B2B\Models\DeliverySchedule;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupplierDeliveryScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
    }

    public function test_existing_supplier_plans_remain_visible_but_cannot_be_created_or_cancelled(): void
    {
        $vendor = Vendor::factory()->create();
        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        $schedule = DeliverySchedule::create([
            'vendor_id' => $vendor->id,
            'purchase_order_id' => $po->id,
            'month' => '2026-10',
            'planned_delivery_date' => '2026-10-13',
            'status' => 'acknowledged',
            'lines' => [],
        ]);
        $user = SupplierPortalUser::create([
            'vendor_id' => $vendor->id,
            'name' => 'Supplier',
            'email' => 'supplier-archive@example.test',
            'password' => bcrypt('Password1!'),
            'is_active' => true,
        ]);
        Sanctum::actingAs($user, ['*'], 'supplier_portal');

        $this->getJson('/api/v1/b2b/supplier/delivery-schedules')
            ->assertOk()->assertJsonPath('data.0.id', $schedule->hash_id)
            ->assertJsonPath('data.0.can_cancel', false);
        $this->postJson('/api/v1/b2b/supplier/delivery-schedules', [
            'purchase_order_id' => $po->hash_id,
        ])->assertStatus(410);
        $this->postJson("/api/v1/b2b/supplier/delivery-schedules/{$schedule->hash_id}/cancel", [
            'reason' => 'Retired',
        ])->assertStatus(410);
        $this->assertDatabaseCount('delivery_schedules', 1);
        $this->assertSame('acknowledged', $schedule->fresh()->status->value);
    }
}
