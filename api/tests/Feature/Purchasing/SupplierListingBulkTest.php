<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Common\Services\NotificationService;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Enums\SupplierListingStatus;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use App\Modules\Purchasing\Models\SupplierItemListing;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Phase 2 — supplier portal bulk submit + catalog pagination/search and the
 * internal bulk approve/reject review surface.
 */
class SupplierListingBulkTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    private SupplierPortalUser $supplier;

    private User $purchasingOfficer;

    private User $unauthorized;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        $notifications = Mockery::mock(NotificationService::class);
        $notifications->shouldReceive('send')->zeroOrMoreTimes();
        $this->app->instance(NotificationService::class, $notifications);

        $this->vendor = Vendor::factory()->create();
        $this->supplier = SupplierPortalUser::factory()->create([
            'vendor_id' => $this->vendor->id,
        ]);

        $this->purchasingOfficer = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'purchasing_officer')->value('id'),
        ]);
        $this->unauthorized = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
        ]);
    }

    private function actingAsSupplier(): void
    {
        Sanctum::actingAs($this->supplier, ['*'], 'supplier_portal');
    }

    private function makeListing(array $overrides): SupplierItemListing
    {
        $status = $overrides['status'] ?? SupplierListingStatus::Pending->value;
        unset($overrides['status']);

        $listing = new SupplierItemListing(array_merge([
            'vendor_id' => $this->vendor->id,
            'price' => '100.00',
            'lead_time_days' => 5,
            'submitted_at' => now(),
        ], $overrides));
        $listing->status = $status;
        $listing->save();

        return $listing;
    }

    /* ─── Portal: catalog pagination/search/filter ─────────── */

    public function test_catalog_defaults_to_purchasable_items_and_paginates(): void
    {
        $raw = Item::factory()->create(['is_active' => true, 'item_type' => 'raw_material']);
        $packaging = Item::factory()->create(['is_active' => true, 'item_type' => 'packaging']);
        Item::factory()->create(['is_active' => true, 'item_type' => 'finished_good']);
        Item::factory()->create(['is_active' => false, 'item_type' => 'raw_material']);

        $this->actingAsSupplier();
        $response = $this->getJson('/api/v1/b2b/supplier/item-catalog')->assertOk();

        $codes = array_column($response->json('data'), 'code');
        $this->assertContains($raw->code, $codes);
        $this->assertContains($packaging->code, $codes);
        $this->assertCount(2, $response->json('data'));
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_catalog_can_request_other_item_types_and_search(): void
    {
        $fg = Item::factory()->create(['is_active' => true, 'item_type' => 'finished_good', 'name' => 'Wiper Sub-assembly']);
        $raw = Item::factory()->create(['is_active' => true, 'item_type' => 'raw_material', 'name' => 'Zebra Resin']);

        $this->actingAsSupplier();

        $byType = $this->getJson('/api/v1/b2b/supplier/item-catalog?item_type=finished_good')->assertOk();
        $this->assertCount(1, $byType->json('data'));
        $this->assertSame($fg->code, $byType->json('data.0.code'));

        $bySearch = $this->getJson('/api/v1/b2b/supplier/item-catalog?search=Zebra')->assertOk();
        $this->assertCount(1, $bySearch->json('data'));
        $this->assertSame($raw->code, $bySearch->json('data.0.code'));
    }

    /* ─── Portal: bulk submit ──────────────────────────────── */

    public function test_supplier_can_bulk_submit_multiple_items(): void
    {
        $a = Item::factory()->create(['is_active' => true]);
        $b = Item::factory()->create(['is_active' => true]);

        $this->actingAsSupplier();
        $response = $this->postJson('/api/v1/b2b/supplier/item-listings/bulk', [
            'items' => [
                ['item_id' => $a->hash_id, 'price' => '10.00', 'lead_time_days' => 3],
                ['item_id' => $b->hash_id, 'price' => '20.00', 'lead_time_days' => 4],
            ],
        ])->assertOk();

        $this->assertSame(2, $response->json('data.created_count'));
        $this->assertSame(0, $response->json('data.failed_count'));
        $this->assertDatabaseCount('supplier_item_listings', 2);
        $this->assertDatabaseCount('approved_suppliers', 0);
    }

    public function test_bulk_submit_reports_duplicate_without_failing_the_batch(): void
    {
        $dupe = Item::factory()->create(['is_active' => true]);
        $fresh = Item::factory()->create(['is_active' => true]);
        $this->makeListing(['item_id' => $dupe->id]);

        $this->actingAsSupplier();
        $response = $this->postJson('/api/v1/b2b/supplier/item-listings/bulk', [
            'items' => [
                ['item_id' => $dupe->hash_id, 'price' => '10.00', 'lead_time_days' => 3],
                ['item_id' => $fresh->hash_id, 'price' => '20.00', 'lead_time_days' => 4],
            ],
        ])->assertOk();

        $this->assertSame(1, $response->json('data.created_count'));
        $this->assertSame(1, $response->json('data.failed_count'));
        $this->assertDatabaseHas('supplier_item_listings', [
            'vendor_id' => $this->vendor->id,
            'item_id' => $fresh->id,
            'status' => 'pending',
        ]);
    }

    /* ─── Internal: bulk review ────────────────────────────── */

    public function test_bulk_approve_approves_pending_and_skips_others(): void
    {
        $itemA = Item::factory()->create(['is_active' => true]);
        $itemB = Item::factory()->create(['is_active' => true]);
        $pending = $this->makeListing(['item_id' => $itemA->id]);
        $approved = $this->makeListing(['item_id' => $itemB->id, 'status' => SupplierListingStatus::Approved->value]);

        $this->actingAs($this->purchasingOfficer)
            ->patchJson('/api/v1/purchasing/supplier-listings/bulk-approve', [
                'ids' => [$pending->hash_id, $approved->hash_id],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'approved')
            ->assertJsonPath('data.1.status', 'skipped');

        $this->assertSame(SupplierListingStatus::Approved, $pending->fresh()->status);
        $this->assertDatabaseHas('approved_suppliers', [
            'item_id' => $itemA->id,
            'vendor_id' => $this->vendor->id,
            'qualification_status' => ApprovedSupplier::QUALIFICATION_APPROVED,
        ]);
    }

    public function test_bulk_reject_requires_reason_and_permission(): void
    {
        $item = Item::factory()->create(['is_active' => true]);
        $listing = $this->makeListing(['item_id' => $item->id]);

        $this->actingAs($this->unauthorized)
            ->patchJson('/api/v1/purchasing/supplier-listings/bulk-reject', [
                'ids' => [$listing->hash_id],
                'reason' => 'Too expensive.',
            ])
            ->assertForbidden();

        $this->actingAs($this->purchasingOfficer)
            ->patchJson('/api/v1/purchasing/supplier-listings/bulk-reject', [
                'ids' => [$listing->hash_id],
            ])
            ->assertStatus(422);

        $this->actingAs($this->purchasingOfficer)
            ->patchJson('/api/v1/purchasing/supplier-listings/bulk-reject', [
                'ids' => [$listing->hash_id],
                'reason' => 'Too expensive.',
            ])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'rejected');

        $this->assertSame(SupplierListingStatus::Rejected, $listing->fresh()->status);
        $this->assertSame('Too expensive.', $listing->fresh()->rejection_reason);
    }
}
