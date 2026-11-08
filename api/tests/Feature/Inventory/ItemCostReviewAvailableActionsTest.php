<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Models\ApprovalDelegation;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\ItemCostReviewStatus;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemCostReview;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\ItemCostReviewService;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemCostReviewAvailableActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_cost_review_actions_are_computed_for_the_current_viewer_and_state(): void
    {
        $finance = $this->userWithRole('finance_officer');
        $purchasing = $this->userWithRole('purchasing_officer');
        $pending = $this->createReview();
        $rejected = $this->createReview();
        $rejected->forceFill(['status' => ItemCostReviewStatus::Rejected])->save();

        $this->actingAs($finance)
            ->getJson('/api/v1/inventory/cost-reviews?review='.$pending->hash_id)
            ->assertOk()
            ->assertJsonPath('data.0.permissions.view', true)
            ->assertJsonPath('data.0.permissions.review', true)
            ->assertJsonPath('data.0.permissions.approve', true)
            ->assertJsonPath('data.0.available_actions.approve', true)
            ->assertJsonPath('data.0.available_actions.reject', true)
            ->assertJsonPath('data.0.available_actions.renew', false);

        $this->actingAs($purchasing)
            ->getJson('/api/v1/inventory/cost-reviews?review='.$pending->hash_id)
            ->assertOk()
            ->assertJsonPath('data.0.permissions.view', true)
            ->assertJsonPath('data.0.permissions.review', true)
            ->assertJsonPath('data.0.permissions.approve', false)
            ->assertJsonPath('data.0.available_actions.approve', false)
            ->assertJsonPath('data.0.available_actions.reject', false)
            ->assertJsonPath('data.0.available_actions.renew', false);

        ApprovalDelegation::query()->create([
            'delegator_user_id' => $finance->id,
            'delegate_user_id' => $purchasing->id,
            'role_slug' => 'finance_officer',
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
            'is_active' => true,
        ]);

        $this->actingAs($purchasing)
            ->getJson('/api/v1/inventory/cost-reviews?review='.$pending->hash_id)
            ->assertOk()
            ->assertJsonPath('data.0.permissions.approve', true)
            ->assertJsonPath('data.0.available_actions.approve', true)
            ->assertJsonPath('data.0.available_actions.reject', true);

        $this->actingAs($purchasing)
            ->getJson('/api/v1/inventory/cost-reviews?review='.$rejected->hash_id)
            ->assertOk()
            ->assertJsonPath('data.0.available_actions.approve', false)
            ->assertJsonPath('data.0.available_actions.reject', false)
            ->assertJsonPath('data.0.available_actions.renew', true);
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
        ]);
    }

    private function createReview(): ItemCostReview
    {
        $item = Item::factory()->create(['standard_cost' => '10.0000']);
        $grn = GoodsReceiptNote::factory()->create(['status' => 'accepted', 'accepted_at' => now()]);
        $purchaseOrderItem = PurchaseOrderItem::query()->create([
            'purchase_order_id' => $grn->purchase_order_id,
            'item_id' => $item->id,
            'description' => 'Available action cost review',
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

        return app(ItemCostReviewService::class)->capture($line, '12.0000', notify: false);
    }
}
