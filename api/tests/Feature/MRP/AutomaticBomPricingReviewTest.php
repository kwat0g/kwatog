<?php

declare(strict_types=1);

namespace Tests\Feature\MRP;

use App\Common\Services\OutboxDispatcher;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\PriceAgreement;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Resources\PriceAgreementResource;
use App\Modules\CRM\Services\PriceAgreementService;
use App\Modules\CRM\Services\PricingReviewService;
use App\Modules\CRM\Services\ProductService;
use App\Modules\Inventory\Events\GoodsReceiptNoteAccepted;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\ItemService;
use App\Modules\MRP\Services\BomCostingService;
use App\Modules\MRP\Services\BomService;
use App\Modules\Production\Services\ProductionRoutingService;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AutomaticBomPricingReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolePermissionSeeder::class);
        foreach (['ppc_head', 'finance_officer', 'sales_officer', 'warehouse_staff', 'customer_service_officer'] as $role) {
            User::factory()->create(['role_id' => Role::where('slug', $role)->value('id')]);
        }
    }

    private function agreement(array $data = []): PriceAgreement
    {
        return PriceAgreement::create(array_merge([
            'product_id' => $data['product_id'] ?? Product::factory()->create(['minimum_selling_price' => '10.00', 'standard_cost' => '1.00', 'base_selling_price' => '60.00'])->id,
            'customer_id' => Customer::factory()->create()->id,
            'price' => '50.00', 'effective_from' => today(), 'effective_to' => today()->addMonth(),
            'pricing_method' => 'flat',
        ], $data));
    }

    private function drain(): void
    {
        foreach (DB::table('event_outbox')->where('event_type', 'App\\Modules\\MRP\\Events\\BomPricingReviewRequested')->where('status', 'pending')->pluck('id') as $id) {
            app(OutboxDispatcher::class)->dispatch($id);
        }
    }

    private function line(Item $item, string $quantity = '1'): array
    {
        return ['item_id' => $item->id, 'quantity_per_unit' => $quantity, 'unit' => $item->unit_of_measure];
    }

    public function test_component_cost_refreshes_nested_boms_and_warns_owners_without_changing_agreement(): void
    {
        $unlistedRole = Role::firstOrCreate(['slug' => 'unlisted_bom_manager'], ['name' => 'Unlisted BOM Manager']);
        $permissionIds = DB::table('permissions')->whereIn('slug', ['mrp.boms.view', 'mrp.boms.manage'])->pluck('id');
        DB::table('role_permissions')->insert($permissionIds->map(static fn ($id): array => [
            'role_id' => $unlistedRole->id,
            'permission_id' => $id,
        ])->all());
        $unlistedViewer = User::factory()->create(['role_id' => $unlistedRole->id]);
        $material = Item::factory()->create(['standard_cost' => '10']);
        $sub = Product::factory()->create();
        $subItem = Item::factory()->create(['code' => $sub->part_number, 'item_type' => 'finished_good']);
        $subBom = app(BomService::class)->create($sub->id, [$this->line($material)]);
        $product = Product::factory()->create(['base_selling_price' => '60.00', 'minimum_selling_price' => '25.00']);
        $old = app(BomService::class)->create($product->id, [$this->line($subItem)]);
        $bom = app(BomService::class)->create($product->id, [$this->line($subItem, '2')]);
        $agreement = $this->agreement(['product_id' => $product->id, 'price' => '35.00']);
        $this->drain();
        DB::table('notifications')->delete();
        app(ItemService::class)->update($material, ['standard_cost' => '20.0000']);
        $this->assertNull($bom->fresh()->costed_at);
        $this->drain();
        $this->assertSame('20.00', $subBom->fresh()->total_cost);
        $this->assertSame('40.00', $bom->fresh()->total_cost);
        $this->assertSame('10.00', $old->fresh()->total_cost);
        $this->assertSame('35.00', $agreement->fresh()->price);
        $this->assertTrue($unlistedViewer->hasPermission('mrp.boms.view'));
        $this->actingAs($unlistedViewer, 'sanctum')->getJson('/api/v1/mrp/boms')->assertOk();
        $this->assertDatabaseHas('notifications', [
            'type' => 'bom.cost_updated',
            'notifiable_id' => User::whereHas('role', fn ($role) => $role->where('slug', 'ppc_head'))->value('id'),
        ]);
        $this->assertDatabaseMissing('notifications', [
            'type' => 'bom.cost_updated',
            'notifiable_id' => $unlistedViewer->id,
        ]);
        $this->assertDatabaseHas('notifications', ['type' => 'pricing.review_required']);
        $warehouse = User::whereHas('role', fn ($role) => $role->where('slug', 'warehouse_staff'))->firstOrFail();
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $warehouse->id)->count());
    }

    public function test_base_and_floor_changes_review_existing_tiers_without_repricing_orders_or_terms(): void
    {
        $product = Product::factory()->create(['base_selling_price' => '60.00']);
        $agreement = $this->agreement(['product_id' => $product->id, 'price' => '50.00', 'pricing_method' => 'tiered',
            'tiers' => [['min_qty' => 100, 'unit_price' => '30.00']]]);
        app(ProductService::class)->update($product, ['minimum_selling_price' => '40.00', 'base_selling_price' => '70.00']);
        $this->drain();
        $this->assertDatabaseHas('notifications', ['type' => 'pricing.review_required']);
        $this->assertSame('30.00', $agreement->fresh()->tiers[0]['unit_price']);
        $this->artisan('pricing:review-agreements')->assertSuccessful();
        $firstCount = DB::table('notifications')->count();
        $this->artisan('pricing:review-agreements')->assertSuccessful();
        $this->artisan('pricing:review-agreements')->assertSuccessful();
        $this->assertSame($firstCount, DB::table('notifications')->count());
    }

    public function test_expiry_reminders_are_deduplicated_and_exclude_archived_agreements(): void
    {
        $agreement = $this->agreement(['effective_from' => today()->subMonth(), 'effective_to' => today()->addDays(5)]);
        $archived = $this->agreement(['effective_from' => today()->subMonth(), 'effective_to' => today()->addDays(5)]);
        $archived->delete();
        $inactive = $this->agreement(['effective_from' => today()->subMonth(), 'effective_to' => today()->addDays(5)]);
        $inactive->customer->update(['is_active' => false]);
        $this->artisan('pricing:review-agreements')->assertSuccessful();
        $count = DB::table('notifications')->where('type', 'pricing.agreement_expiring')->count();
        $expectedRecipientIds = User::query()->where('is_active', true)->get()
            ->filter(fn (User $user): bool => in_array($user->role?->slug, ['sales_officer', 'customer_service_officer', 'system_admin'], true)
                && $user->hasPermission('crm.price_agreements.view'))
            ->pluck('id')->map(static fn ($id): int => (int) $id)->sort()->values()->all();
        $recipientIds = DB::table('notifications')->where('type', 'pricing.agreement_expiring')
            ->pluck('notifiable_id')->map(static fn ($id): int => (int) $id)->sort()->values()->all();
        $this->assertSame($expectedRecipientIds, $recipientIds);
        $finance = User::whereHas('role', fn ($query) => $query->where('slug', 'finance_officer'))->firstOrFail();
        $this->assertTrue($finance->hasPermission('crm.price_agreements.view'));
        $this->actingAs($finance, 'sanctum')->getJson('/api/v1/crm/price-agreements')->assertOk();
        $this->assertDatabaseMissing('notifications', [
            'type' => 'pricing.agreement_expiring',
            'notifiable_id' => $finance->id,
        ]);
        $this->artisan('pricing:review-agreements')->assertSuccessful();
        $this->assertSame($count, DB::table('notifications')->where('type', 'pricing.agreement_expiring')->count());
    }

    public function test_missing_conversion_does_not_publish_a_false_success_and_recovers_after_fix(): void
    {
        $product = Product::factory()->create();
        $material = Item::factory()->create(['standard_cost' => '10']);
        $bom = app(BomService::class)->create($product->id, [$this->line($material)]);
        $this->drain();
        DB::table('notifications')->delete();
        $bom->items()->first()->update(['unit' => 'UNKNOWN']);
        app(BomCostingService::class)->invalidateForItems([$material->id]);
        $this->drain();
        $this->assertNull($bom->fresh()->costed_at);
        $this->assertDatabaseHas('notifications', ['type' => 'bom.cost_review_failed']);
        $this->assertSame(0, DB::table('notifications')->where('type', 'bom.cost_updated')->count());
        $bom->items()->first()->update(['unit' => $material->unit_of_measure]);
        $this->artisan('pricing:review-agreements')->assertSuccessful();
        $this->assertNotNull($bom->fresh()->costed_at);
        // A missed invalidation must not leave a failed refreshed estimate marked current.
        $this->travel(1)->seconds();
        $bom->items()->first()->update(['unit' => 'UNKNOWN']);
        $this->artisan('pricing:review-agreements')->assertSuccessful();
        $this->assertNull($bom->fresh()->costed_at);
    }

    public function test_receipt_cost_variance_alert_is_deduplicated_and_does_not_replace_standard_cost(): void
    {
        $item = Item::factory()->create(['standard_cost' => '10.0000']);
        $grn = GoodsReceiptNote::factory()->create(['status' => 'accepted', 'accepted_at' => now()]);
        $grn->items()->create(['purchase_order_item_id' => PurchaseOrderItem::create(['purchase_order_id' => $grn->purchase_order_id, 'item_id' => $item->id, 'description' => 'Cost variance material', 'quantity' => '5', 'unit' => $item->unit_of_measure, 'unit_price' => '12.00', 'total' => '60.00'])->id, 'location_id' => WarehouseLocation::factory()->create()->id, 'item_id' => $item->id, 'quantity_received' => '5', 'quantity_accepted' => '5', 'unit_cost' => '12.0000']);
        $review = app(PricingReviewService::class);
        $event = new GoodsReceiptNoteAccepted($grn);
        $review->receiptCostChanged($event);
        $count = DB::table('notifications')->where('type', 'inventory.receipt_cost_changed')->count();
        $this->assertGreaterThan(0, $count);
        $review->receiptCostChanged($event);
        $this->assertSame($count, DB::table('notifications')->where('type', 'inventory.receipt_cost_changed')->count());
        $this->artisan('pricing:review-agreements')->assertSuccessful();
        $this->assertSame($count, DB::table('notifications')->where('type', 'inventory.receipt_cost_changed')->count());
        $this->assertSame('10.0000', $item->fresh()->standard_cost);
    }

    public function test_routing_change_refreshes_parent_and_preserves_previous_routing(): void
    {
        $material = Item::factory()->create(['standard_cost' => '10']);
        $sub = Product::factory()->create();
        $subItem = Item::factory()->create(['code' => $sub->part_number, 'item_type' => 'finished_good']);
        app(BomService::class)->create($sub->id, [$this->line($material)]);
        $parent = app(BomService::class)->create(Product::factory()->create()->id, [$this->line($subItem, '2')]);
        $this->drain();
        $routing = app(ProductionRoutingService::class)->create([
            'product_id' => $sub->id, 'operations' => [[
                'sequence' => 10, 'operation_name' => 'Assembly', 'cycle_time_minutes' => '60',
                'labor_rate_per_hour' => '5', 'overhead_rate_per_hour' => '5',
            ]],
        ]);
        $this->drain();
        $this->assertSame('40.00', $parent->fresh()->total_cost);
        $this->assertSame(1, $routing->fresh()->version);
    }

    public function test_rolled_back_cost_change_leaves_no_refresh_or_notification(): void
    {
        $material = Item::factory()->create(['standard_cost' => '10']);
        $bom = app(BomService::class)->create(Product::factory()->create()->id, [$this->line($material)]);
        $this->drain();
        $before = DB::table('event_outbox')->count();
        DB::beginTransaction();
        app(ItemService::class)->update($material, ['standard_cost' => '20']);
        DB::rollBack();
        $this->assertSame($before, DB::table('event_outbox')->count());
        $this->assertSame('10.00', $bom->fresh()->total_cost);
        $this->assertSame('10.0000', $material->fresh()->standard_cost);
    }

    public function test_customer_agreement_listing_includes_current_bom_risks(): void
    {
        $product = Product::factory()->create(['minimum_selling_price' => '5.00']);
        $material = Item::factory()->create(['standard_cost' => '60.00']);
        app(BomService::class)->create($product->id, [$this->line($material)]);
        $agreement = $this->agreement(['product_id' => $product->id]);
        $rows = app(PriceAgreementService::class)->listForCustomer($agreement->customer_id);
        $data = (new PriceAgreementResource($rows->first()))->resolve();
        $this->assertContains('Agreement price is below a configured unit cost.', $data['pricing_warnings']);
    }

    public function test_refresh_failure_retains_retry_and_does_not_block_other_products(): void
    {
        $review = app(PricingReviewService::class);
        $product = Product::factory()->create();
        $review->request([$product->id], 'fail_once');
        $review->request([$product->id], 'healthy');
        $fail = true;
        $this->mock(PricingReviewService::class, function ($mock) use ($review, &$fail): void {
            $mock->shouldReceive('handle')->andReturnUsing(function ($event) use ($review, &$fail): void {
                if ($event->reason === 'fail_once' && $fail) {
                    $fail = false;
                    throw new \RuntimeException('Temporary pricing refresh failure');
                }
                $review->handle($event);
            });
        });
        $this->artisan('pricing:review-agreements --dispatch-only')->assertExitCode(1);
        $this->assertSame(1, DB::table('event_outbox')->where('status', 'pending')->count());
        $this->assertSame(1, DB::table('event_outbox')->where('status', 'published')->count());
        $this->travel(20)->seconds();
        $this->artisan('pricing:review-agreements --dispatch-only')->assertSuccessful();
        $this->assertSame(0, DB::table('event_outbox')->where('status', 'pending')->count());
    }
}
