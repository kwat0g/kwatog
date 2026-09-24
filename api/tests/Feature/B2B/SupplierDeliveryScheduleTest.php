<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Common\Support\Money;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\B2B\Enums\DeliveryScheduleStatus;
use App\Modules\B2B\Models\DeliverySchedule;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Supplier delivery schedules — partial scheduling, cancellation, and quantity tracking.
 */
class SupplierDeliveryScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class, ChartOfAccountsSeeder::class]);
    }

    private function makeVendor(): Vendor
    {
        return Vendor::factory()->create();
    }

    private function makePortalUser(Vendor $vendor): SupplierPortalUser
    {
        return SupplierPortalUser::create([
            'vendor_id' => $vendor->id,
            'name' => 'SupUser-' . substr(uniqid(), -5),
            'email' => 'su-' . uniqid() . '@t.test',
            'password' => bcrypt('Password1!'),
            'is_active' => true,
        ]);
    }

    private function actAs(SupplierPortalUser $user): self
    {
        Sanctum::actingAs($user, ['*'], 'supplier_portal');
        return $this;
    }

    private function makePo(Vendor $vendor, string $status = 'acknowledged'): PurchaseOrder
    {
        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        $po->forceFill(['status' => $status])->save();
        return $po->refresh();
    }

    private function makePoItem(PurchaseOrder $po, string $quantity = '500.00'): PurchaseOrderItem
    {
        $unitPrice = '100.00';
        $total = (string) ((float) $quantity * (float) $unitPrice);

        return PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => Item::factory()->create()->id,
            'description' => 'Item-' . substr(uniqid(), -5),
            'quantity' => $quantity,
            'unit' => 'pcs',
            'unit_price' => $unitPrice,
            'total' => $total,
        ]);
    }

    private function currentMonth(): string
    {
        return now()->format('Y-m');
    }

    public function test_partial_schedule_for_item_a_then_new_schedule_for_item_b_same_month(): void
    {
        $vendor = $this->makeVendor();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'acknowledged');
        $itemA = $this->makePoItem($po, '100.00');
        $itemB = $this->makePoItem($po, '100.00');

        $this->actAs($user);

        // Schedule only item A
        $scheduleA = $this->postJson('/api/v1/b2b/supplier/delivery-schedules', [
            'purchase_order_id' => $po->hash_id,
            'month' => $this->currentMonth(),
            'lines' => [['purchase_order_item_id' => $itemA->hash_id, 'quantity' => '50.00']],
        ]);
        $scheduleA->assertStatus(201);

        // Schedule only item B in the same month — must succeed
        $scheduleB = $this->postJson('/api/v1/b2b/supplier/delivery-schedules', [
            'purchase_order_id' => $po->hash_id,
            'month' => $this->currentMonth(),
            'lines' => [['purchase_order_item_id' => $itemB->hash_id, 'quantity' => '30.00']],
        ]);
        $scheduleB->assertStatus(201);

        // Two distinct schedules for the same PO and month
        $this->assertDatabaseCount('delivery_schedules', 2);
        $this->assertNotSame($scheduleA->json('data.id'), $scheduleB->json('data.id'));
    }

    public function test_second_schedule_for_item_beyond_remaining_refused(): void
    {
        $vendor = $this->makeVendor();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'acknowledged');
        $item = $this->makePoItem($po, '100.00');

        $this->actAs($user);

        // Schedule 70 qty
        $this->postJson('/api/v1/b2b/supplier/delivery-schedules', [
            'purchase_order_id' => $po->hash_id,
            'month' => $this->currentMonth(),
            'lines' => [['purchase_order_item_id' => $item->hash_id, 'quantity' => '70.00']],
        ])->assertStatus(201);

        // Try to schedule 40 qty (beyond remaining 30) — refused
        $this->postJson('/api/v1/b2b/supplier/delivery-schedules', [
            'purchase_order_id' => $po->hash_id,
            'month' => $this->currentMonth(),
            'lines' => [['purchase_order_item_id' => $item->hash_id, 'quantity' => '40.00']],
        ])->assertStatus(422);

        $this->assertDatabaseCount('delivery_schedules', 1);
    }

    public function test_second_schedule_for_item_within_remaining_accepted(): void
    {
        $vendor = $this->makeVendor();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'acknowledged');
        $item = $this->makePoItem($po, '100.00');

        $this->actAs($user);

        // Schedule 70 qty
        $this->postJson('/api/v1/b2b/supplier/delivery-schedules', [
            'purchase_order_id' => $po->hash_id,
            'month' => $this->currentMonth(),
            'lines' => [['purchase_order_item_id' => $item->hash_id, 'quantity' => '70.00']],
        ])->assertStatus(201);

        // Schedule 20 qty more (within remaining 30) — accepted
        $this->postJson('/api/v1/b2b/supplier/delivery-schedules', [
            'purchase_order_id' => $po->hash_id,
            'month' => $this->currentMonth(),
            'lines' => [['purchase_order_item_id' => $item->hash_id, 'quantity' => '20.00']],
        ])->assertStatus(201);

        $this->assertDatabaseCount('delivery_schedules', 2);
    }

    public function test_rejected_schedule_frees_quantity(): void
    {
        $vendor = $this->makeVendor();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'acknowledged');
        $item = $this->makePoItem($po, '100.00');

        $this->actAs($user);

        // Schedule 70 qty
        $response = $this->postJson('/api/v1/b2b/supplier/delivery-schedules', [
            'purchase_order_id' => $po->hash_id,
            'month' => $this->currentMonth(),
            'lines' => [['purchase_order_item_id' => $item->hash_id, 'quantity' => '70.00']],
        ]);
        $schedule = DeliverySchedule::first();
        $response->assertStatus(201);

        // Manually reject the schedule
        $schedule->forceFill(['status' => DeliveryScheduleStatus::Rejected->value])->save();

        // Now 70 qty should be available again
        $this->postJson('/api/v1/b2b/supplier/delivery-schedules', [
            'purchase_order_id' => $po->hash_id,
            'month' => $this->currentMonth(),
            'lines' => [['purchase_order_item_id' => $item->hash_id, 'quantity' => '70.00']],
        ])->assertStatus(201);

        $this->assertDatabaseCount('delivery_schedules', 2);
    }

    public function test_cancelled_schedule_frees_quantity(): void
    {
        $vendor = $this->makeVendor();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'acknowledged');
        $item = $this->makePoItem($po, '100.00');

        $this->actAs($user);

        // Schedule 70 qty
        $response = $this->postJson('/api/v1/b2b/supplier/delivery-schedules', [
            'purchase_order_id' => $po->hash_id,
            'month' => $this->currentMonth(),
            'lines' => [['purchase_order_item_id' => $item->hash_id, 'quantity' => '70.00']],
        ]);
        $scheduleId = $response->json('data.id');
        $response->assertStatus(201);

        // Cancel the schedule
        $this->postJson("/api/v1/b2b/supplier/delivery-schedules/{$scheduleId}/cancel", [
            'reason' => 'Cannot deliver',
        ])->assertStatus(200);

        // Now 70 qty should be available again
        $this->postJson('/api/v1/b2b/supplier/delivery-schedules', [
            'purchase_order_id' => $po->hash_id,
            'month' => $this->currentMonth(),
            'lines' => [['purchase_order_item_id' => $item->hash_id, 'quantity' => '70.00']],
        ])->assertStatus(201);

        $this->assertDatabaseCount('delivery_schedules', 2);
    }

    public function test_cancel_another_vendors_schedule_returns_404(): void
    {
        $vendor1 = $this->makeVendor();
        $vendor2 = $this->makeVendor();
        $user1 = $this->makePortalUser($vendor1);
        $user2 = $this->makePortalUser($vendor2);
        $po = $this->makePo($vendor1, 'acknowledged');
        $item = $this->makePoItem($po, '100.00');

        $this->actAs($user1);
        $response = $this->postJson('/api/v1/b2b/supplier/delivery-schedules', [
            'purchase_order_id' => $po->hash_id,
            'month' => $this->currentMonth(),
            'lines' => [['purchase_order_item_id' => $item->hash_id, 'quantity' => '50.00']],
        ]);
        $scheduleId = $response->json('data.id');

        // User 2 tries to cancel user 1's schedule
        $this->actAs($user2);
        $this->postJson("/api/v1/b2b/supplier/delivery-schedules/{$scheduleId}/cancel", [
            'reason' => 'Cannot deliver',
        ])->assertStatus(404);
    }

    public function test_cancel_rejected_schedule_returns_422(): void
    {
        $vendor = $this->makeVendor();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'acknowledged');
        $item = $this->makePoItem($po, '100.00');

        $this->actAs($user);
        $response = $this->postJson('/api/v1/b2b/supplier/delivery-schedules', [
            'purchase_order_id' => $po->hash_id,
            'month' => $this->currentMonth(),
            'lines' => [['purchase_order_item_id' => $item->hash_id, 'quantity' => '50.00']],
        ]);
        $scheduleId = $response->json('data.id');
        $schedule = DeliverySchedule::whereKey(app('hashids')->decode($scheduleId)[0])->first();

        // Reject the schedule
        $schedule->forceFill(['status' => DeliveryScheduleStatus::Rejected->value])->save();

        // Try to cancel a rejected schedule
        $this->postJson("/api/v1/b2b/supplier/delivery-schedules/{$scheduleId}/cancel", [
            'reason' => 'Cannot deliver',
        ])->assertStatus(422);
    }

    public function test_po_in_sent_status_refused(): void
    {
        $vendor = $this->makeVendor();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'sent');
        $item = $this->makePoItem($po, '100.00');

        $this->actAs($user);
        $this->postJson('/api/v1/b2b/supplier/delivery-schedules', [
            'purchase_order_id' => $po->hash_id,
            'month' => $this->currentMonth(),
            'lines' => [['purchase_order_item_id' => $item->hash_id, 'quantity' => '50.00']],
        ])->assertStatus(422);
    }

    public function test_fully_received_po_refused(): void
    {
        $vendor = $this->makeVendor();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'acknowledged');
        $item = $this->makePoItem($po, '100.00');

        // Fully received
        $item->forceFill(['quantity_received' => '100.00'])->save();

        $this->actAs($user);
        $this->postJson('/api/v1/b2b/supplier/delivery-schedules', [
            'purchase_order_id' => $po->hash_id,
            'month' => $this->currentMonth(),
            'lines' => [['purchase_order_item_id' => $item->hash_id, 'quantity' => '50.00']],
        ])->assertStatus(422);
    }

    public function test_identical_resubmission_is_idempotent(): void
    {
        $vendor = $this->makeVendor();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'acknowledged');
        $item = $this->makePoItem($po, '100.00');

        $this->actAs($user);

        $payload = [
            'purchase_order_id' => $po->hash_id,
            'month' => $this->currentMonth(),
            'lines' => [['purchase_order_item_id' => $item->hash_id, 'quantity' => '50.00']],
        ];

        $first = $this->postJson('/api/v1/b2b/supplier/delivery-schedules', $payload);
        $first->assertStatus(201);

        $second = $this->postJson('/api/v1/b2b/supplier/delivery-schedules', $payload);
        $second->assertStatus(201);

        // Same row returned
        $this->assertDatabaseCount('delivery_schedules', 1);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
    }

    public function test_past_month_refused(): void
    {
        $vendor = $this->makeVendor();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'acknowledged');
        $item = $this->makePoItem($po, '100.00');

        $this->actAs($user);

        $lastMonth = now()->subMonth()->format('Y-m');
        $this->postJson('/api/v1/b2b/supplier/delivery-schedules', [
            'purchase_order_id' => $po->hash_id,
            'month' => $lastMonth,
            'lines' => [['purchase_order_item_id' => $item->hash_id, 'quantity' => '50.00']],
        ])->assertStatus(422);
    }

    public function test_eligible_po_endpoint_lists_only_eligible_pos(): void
    {
        $vendor = $this->makeVendor();
        $user = $this->makePortalUser($vendor);

        // Acknowledged with open qty — eligible
        $eligible = $this->makePo($vendor, 'acknowledged');
        $item1 = $this->makePoItem($eligible, '100.00');

        // Sent — not eligible
        $sent = $this->makePo($vendor, 'sent');
        $item2 = $this->makePoItem($sent, '100.00');

        // Acknowledged but fully received — not eligible
        $received = $this->makePo($vendor, 'acknowledged');
        $item3 = $this->makePoItem($received, '100.00');
        $item3->forceFill(['quantity_received' => '100.00'])->save();

        $this->actAs($user);
        $response = $this->getJson('/api/v1/b2b/supplier/delivery-schedules/purchase-orders');

        $response->assertStatus(200);
        $pos = $response->json('data');
        $this->assertCount(1, $pos);
        $this->assertSame($eligible->hash_id, $pos[0]['id']);
    }

    public function test_eligible_po_endpoint_shows_correct_string_quantities(): void
    {
        $vendor = $this->makeVendor();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'acknowledged');
        $item = $this->makePoItem($po, '100.50');

        $this->actAs($user);
        $response = $this->getJson('/api/v1/b2b/supplier/delivery-schedules/purchase-orders');

        $response->assertStatus(200);
        $pos = $response->json('data');
        $this->assertSame('100.50', $pos[0]['items'][0]['quantity_ordered']);
        $this->assertSame('0.00', $pos[0]['items'][0]['quantity_received']);
        $this->assertSame('100.50', $pos[0]['items'][0]['quantity_schedulable']);
    }

    public function test_received_then_scheduled_coverage_math(): void
    {
        $vendor = $this->makeVendor();
        $user = $this->makePortalUser($vendor);
        $po = $this->makePo($vendor, 'acknowledged');
        $item = $this->makePoItem($po, '100.00');

        $this->actAs($user);

        // Schedule 60 qty
        $this->postJson('/api/v1/b2b/supplier/delivery-schedules', [
            'purchase_order_id' => $po->hash_id,
            'month' => $this->currentMonth(),
            'lines' => [['purchase_order_item_id' => $item->hash_id, 'quantity' => '60.00']],
        ])->assertStatus(201);

        // Simulate receipt of 60 qty
        $item->forceFill(['quantity_received' => '60.00'])->save();

        // Schedulable should be 40 (ordered 100 − max(received 60, scheduled 60))
        $response = $this->getJson('/api/v1/b2b/supplier/delivery-schedules/purchase-orders');
        $po_data = $response->json('data.0');
        $this->assertSame('40.00', $po_data['items'][0]['quantity_schedulable']);
    }
}
