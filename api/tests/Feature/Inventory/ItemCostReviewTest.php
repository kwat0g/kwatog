<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Common\Services\SettingsService;
use App\Modules\Inventory\Events\GoodsReceiptNoteAccepted;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemCostReview;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\ItemCostReviewService;
use App\Modules\CRM\Services\PricingReviewService;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ItemCostReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_finance_can_apply_a_received_standard_cost_and_repeat_is_safe(): void
    {
        Queue::fake();
        Mail::fake();
        $this->seed(RolePermissionSeeder::class);
        $item = Item::factory()->create(['standard_cost' => '10.0000']);
        $grn = GoodsReceiptNote::factory()->create(['status' => 'accepted', 'accepted_at' => now()]);
        $line = $grn->items()->create(['purchase_order_item_id' => PurchaseOrderItem::create(['purchase_order_id' => $grn->purchase_order_id, 'item_id' => $item->id, 'description' => 'Cost review material', 'quantity' => '5', 'unit' => $item->unit_of_measure, 'unit_price' => '12.00', 'total' => '60.00'])->id, 'location_id' => WarehouseLocation::factory()->create()->id, 'item_id' => $item->id, 'quantity_received' => '5', 'quantity_accepted' => '5', 'unit_cost' => '12.0000']);
        $finance = User::factory()->create(['role_id' => Role::where('slug', 'finance_officer')->value('id')]);
        $financeReviewer = User::factory()->create(['role_id' => Role::where('slug', 'finance_officer')->value('id')]);
        $review = app(ItemCostReviewService::class)->capture($line, '12.0000');
        $this->assertSame('10.0000', $item->fresh()->standard_cost);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $finance->id,
            'type' => 'inventory.receipt_cost_changed',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $financeReviewer->id,
            'type' => 'inventory.receipt_cost_changed',
        ]);
        $costNotice = DB::table('notifications')->where('notifiable_id', $finance->id)->where('type', 'inventory.receipt_cost_changed')->firstOrFail();
        $this->assertSame('/crm/price-agreements/cost-reviews?review='.$review->hash_id, json_decode($costNotice->data, true)['link_to']);
        $warehouse = User::factory()->create(['role_id' => Role::where('slug', 'warehouse_staff')->value('id')]);
        $this->actingAs($warehouse)->putJson('/api/v1/inventory/items/'.$item->hash_id, ['standard_cost' => '12.0000'])->assertForbidden();
        $this->actingAs($warehouse)->postJson('/api/v1/inventory/cost-reviews/'.$review->hash_id.'/decide', ['version' => 1, 'decision' => 'approve', 'notes' => 'Review'])->assertForbidden();
        $this->actingAs($finance)->postJson('/api/v1/inventory/cost-reviews/'.$review->hash_id.'/decide', ['version' => 1, 'decision' => 'approve', 'notes' => 'Approved receipt cost basis'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame('12.0000', $item->fresh()->standard_cost);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $finance->id, 'type' => 'approval.completed']);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $financeReviewer->id, 'type' => 'approval.completed']);
        $outcomeNotice = DB::table('notifications')->where('notifiable_id', $financeReviewer->id)->where('type', 'approval.completed')->firstOrFail();
        $this->assertSame('/crm/price-agreements/cost-reviews?review='.$review->hash_id, json_decode($outcomeNotice->data, true)['link_to']);
        $this->postJson('/api/v1/inventory/cost-reviews/'.$review->hash_id.'/decide', ['version' => 1, 'decision' => 'approve', 'notes' => 'Repeat'])->assertOk();
        $staleLine = $grn->items()->create(['purchase_order_item_id' => $line->purchase_order_item_id, 'location_id' => $line->location_id, 'item_id' => $item->id, 'quantity_received' => '5', 'quantity_accepted' => '5', 'unit_cost' => '14.0000']);
        $staleReview = app(ItemCostReviewService::class)->capture($staleLine, '14.0000');
        $this->actingAs($financeReviewer)->getJson('/api/v1/inventory/cost-reviews?review='.$review->hash_id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $review->hash_id);
        $staleLine->update(['unit_cost' => '15.0000']);
        $this->postJson('/api/v1/inventory/cost-reviews/'.$staleReview->hash_id.'/decide', ['version' => 1, 'decision' => 'approve', 'notes' => 'Review changed receipt'])->assertUnprocessable();
        $this->assertSame('12.0000', $item->fresh()->standard_cost);
    }

    public function test_receipt_cost_change_notice_targets_the_review_once(): void
    {
        $this->seed(RolePermissionSeeder::class);
        app(SettingsService::class)->set('modules.inventory', true, 'modules');
        $finance = User::factory()->create(['role_id' => Role::where('slug', 'finance_officer')->value('id')]);
        $withoutReviewPermission = User::factory()->create();
        $item = Item::factory()->create(['standard_cost' => '10.0000']);
        $grn = GoodsReceiptNote::factory()->create(['status' => 'accepted', 'accepted_at' => now()]);
        $purchaseOrderItem = PurchaseOrderItem::create([
            'purchase_order_id' => $grn->purchase_order_id,
            'item_id' => $item->id,
            'description' => 'Receipt cost review link',
            'quantity' => '5',
            'unit' => $item->unit_of_measure,
            'unit_price' => '12.00',
            'total' => '60.00',
        ]);
        $line = $grn->items()->create([
            'purchase_order_item_id' => $purchaseOrderItem->id,
            'location_id' => WarehouseLocation::factory()->create()->id,
            'item_id' => $item->id,
            'quantity_received' => '5',
            'quantity_accepted' => '5',
            'unit_cost' => '12.0000',
        ]);

        app(PricingReviewService::class)->receiptCostChanged(new GoodsReceiptNoteAccepted($grn));

        $review = ItemCostReview::where('grn_item_id', $line->id)->firstOrFail();
        $notice = DB::table('notifications')->where('notifiable_id', $finance->id)->where('type', 'inventory.receipt_cost_changed')->firstOrFail();
        $this->assertSame(1, DB::table('notifications')->where('type', 'inventory.receipt_cost_changed')->count());
        $this->assertSame('/crm/price-agreements/cost-reviews?review='.$review->hash_id, json_decode($notice->data, true)['link_to']);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $withoutReviewPermission->id,
            'type' => 'inventory.receipt_cost_changed',
        ]);
    }

    public function test_receipt_cost_change_notice_is_suppressed_when_inventory_is_disabled(): void
    {
        $this->seed(RolePermissionSeeder::class);
        app(SettingsService::class)->set('modules.inventory', false, 'modules');
        User::factory()->create(['role_id' => Role::where('slug', 'finance_officer')->value('id')]);
        $item = Item::factory()->create(['standard_cost' => '10.0000']);
        $grn = GoodsReceiptNote::factory()->create(['status' => 'accepted', 'accepted_at' => now()]);
        $purchaseOrderItem = PurchaseOrderItem::create([
            'purchase_order_id' => $grn->purchase_order_id,
            'item_id' => $item->id,
            'description' => 'Disabled inventory cost review',
            'quantity' => '5',
            'unit' => $item->unit_of_measure,
            'unit_price' => '12.00',
            'total' => '60.00',
        ]);
        $line = $grn->items()->create([
            'purchase_order_item_id' => $purchaseOrderItem->id,
            'location_id' => WarehouseLocation::factory()->create()->id,
            'item_id' => $item->id,
            'quantity_received' => '5',
            'quantity_accepted' => '5',
            'unit_cost' => '12.0000',
        ]);

        app(PricingReviewService::class)->receiptCostChanged(new GoodsReceiptNoteAccepted($grn));

        $this->assertDatabaseMissing('notifications', ['type' => 'inventory.receipt_cost_changed']);
        $this->assertDatabaseHas('item_cost_reviews', ['grn_item_id' => $line->id]);
    }
}
