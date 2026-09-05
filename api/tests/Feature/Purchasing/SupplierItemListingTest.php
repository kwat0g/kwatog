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
 * Supplier Item Listings — suppliers submit offers anchored to Ogami items;
 * Purchasing reviews them; approval syncs the offer into approved_suppliers.
 *
 * Portal endpoints: /api/v1/b2b/supplier/item-catalog, /item-listings
 * Internal endpoints: /api/v1/purchasing/supplier-listings(+/{id}/approve|reject)
 */
class SupplierItemListingTest extends TestCase
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

    private function actingAsSupplier(SupplierPortalUser $user): void
    {
        Sanctum::actingAs($user, ['*'], 'supplier_portal');
    }

    /**
     * status is guarded (not fillable) on SupplierItemListing, so build the
     * row then forceFill the status the way the service layer does.
     */
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

    /* ─── Portal: catalog ──────────────────────────────────── */

    public function test_catalog_returns_active_items_only(): void
    {
        $active = Item::factory()->create(['is_active' => true]);
        Item::factory()->create(['is_active' => false]);

        $this->actingAsSupplier($this->supplier);
        $response = $this->getJson('/api/v1/b2b/supplier/item-catalog')->assertOk();

        $codes = array_column($response->json('data'), 'code');
        $this->assertContains($active->code, $codes);
        $this->assertCount(1, $response->json('data'));
    }

    /* ─── Portal: submit ───────────────────────────────────── */

    public function test_supplier_can_submit_listing_and_it_lands_pending(): void
    {
        $item = Item::factory()->create(['is_active' => true, 'unit_of_measure' => 'kg']);

        $this->actingAsSupplier($this->supplier);
        $response = $this->postJson('/api/v1/b2b/supplier/item-listings', [
            'item_id' => $item->hash_id,
            'supplier_item_code' => 'MR-PP-88',
            'supplier_item_name' => 'Polypropylene Black 88',
            'price' => '2450.00',
            'order_uom' => 'bag',
            'base_qty_per_order_unit' => '25',
            'lead_time_days' => 12,
            'valid_until' => now()->addMonths(3)->format('Y-m-d'),
        ])->assertCreated();

        $this->assertSame('pending', $response->json('data.status'));
        $this->assertDatabaseHas('supplier_item_listings', [
            'vendor_id' => $this->vendor->id,
            'item_id' => $item->id,
            'status' => 'pending',
        ]);
        // Nothing may touch approved_suppliers before approval.
        $this->assertDatabaseCount('approved_suppliers', 0);
    }

    public function test_duplicate_pending_listing_for_same_item_is_rejected(): void
    {
        $item = Item::factory()->create(['is_active' => true]);

        $this->actingAsSupplier($this->supplier);
        $payload = [
            'item_id' => $item->hash_id,
            'price' => '100.00',
            'lead_time_days' => 5,
        ];
        $this->postJson('/api/v1/b2b/supplier/item-listings', $payload)->assertCreated();
        $this->postJson('/api/v1/b2b/supplier/item-listings', $payload)->assertStatus(422);
    }

    public function test_inactive_item_cannot_be_listed(): void
    {
        $item = Item::factory()->create(['is_active' => false]);

        $this->actingAsSupplier($this->supplier);
        $this->postJson('/api/v1/b2b/supplier/item-listings', [
            'item_id' => $item->hash_id,
            'price' => '100.00',
            'lead_time_days' => 5,
        ])->assertStatus(422);
    }

    public function test_supplier_can_update_pending_listing_only(): void
    {
        $item = Item::factory()->create(['is_active' => true]);
        $listing = $this->makeListing(['item_id' => $item->id]);

        $this->actingAsSupplier($this->supplier);
        $this->putJson("/api/v1/b2b/supplier/item-listings/{$listing->hash_id}", [
            'price' => '110.00',
            'lead_time_days' => 7,
        ])->assertOk();
        $this->assertSame('110.00', (string) $listing->fresh()->price);

        $listing->forceFill(['status' => SupplierListingStatus::Approved])->save();
        $this->putJson("/api/v1/b2b/supplier/item-listings/{$listing->hash_id}", [
            'price' => '120.00',
            'lead_time_days' => 7,
        ])->assertStatus(422);
    }

    public function test_supplier_cannot_touch_another_vendors_listing(): void
    {
        $otherVendor = Vendor::factory()->create();
        $item = Item::factory()->create(['is_active' => true]);
        $listing = $this->makeListing(['vendor_id' => $otherVendor->id, 'item_id' => $item->id]);

        $this->actingAsSupplier($this->supplier);
        $this->putJson("/api/v1/b2b/supplier/item-listings/{$listing->hash_id}", [
            'price' => '999.00',
            'lead_time_days' => 1,
        ])->assertNotFound();

        $this->assertSame('100.00', (string) $listing->fresh()->price);
    }

    /* ─── Internal: review queue ───────────────────────────── */

    public function test_review_queue_requires_purchasing_view(): void
    {
        $this->actingAs($this->unauthorized)
            ->getJson('/api/v1/purchasing/supplier-listings')
            ->assertForbidden();

        $this->actingAs($this->purchasingOfficer)
            ->getJson('/api/v1/purchasing/supplier-listings')
            ->assertOk();
    }

    /* ─── Internal: approve ────────────────────────────────── */

    public function test_approve_syncs_offer_into_approved_suppliers_with_base_unit_price(): void
    {
        $item = Item::factory()->create(['is_active' => true, 'unit_of_measure' => 'kg']);
        $listing = $this->makeListing([
            'item_id' => $item->id,
            'supplier_item_code' => 'MR-PP-88',
            'price' => '2450.00', // ₱ per 25kg bag
            'order_uom' => 'bag',
            'base_qty_per_order_unit' => '25',
            'lead_time_days' => 12,
            'valid_until' => '2026-12-31',
        ]);

        $this->actingAs($this->purchasingOfficer)
            ->patchJson("/api/v1/purchasing/supplier-listings/{$listing->hash_id}/approve")
            ->assertOk();

        $listing->refresh();
        $this->assertSame(SupplierListingStatus::Approved, $listing->status);
        $this->assertSame($this->purchasingOfficer->id, $listing->reviewed_by);

        $row = ApprovedSupplier::query()->where('item_id', $item->id)->where('vendor_id', $this->vendor->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('98.00', (string) $row->last_price); // 2450 / 25 per kg
        $this->assertSame(12, $row->lead_time_days);
        $this->assertSame('MR-PP-88', $row->supplier_item_code);
        $this->assertSame('bag', $row->order_uom);
        $this->assertSame('2026-12-31', $row->price_valid_until->format('Y-m-d'));
        $this->assertSame($listing->id, $row->supplier_listing_id);
    }

    public function test_new_approval_supersedes_previous_approved_listing(): void
    {
        $item = Item::factory()->create(['is_active' => true]);
        $first = $this->makeListing([
            'item_id' => $item->id,
            'status' => SupplierListingStatus::Approved,
            'submitted_at' => now()->subDays(10),
        ]);
        $second = $this->makeListing([
            'item_id' => $item->id,
            'price' => '105.00',
            'lead_time_days' => 6,
        ]);

        $this->actingAs($this->purchasingOfficer)
            ->patchJson("/api/v1/purchasing/supplier-listings/{$second->hash_id}/approve")
            ->assertOk();

        $this->assertSame(SupplierListingStatus::Superseded, $first->fresh()->status);
        $row = ApprovedSupplier::query()->where('item_id', $item->id)->where('vendor_id', $this->vendor->id)->first();
        $this->assertSame('105.00', (string) $row->last_price);
        $this->assertSame($second->id, $row->supplier_listing_id);
    }

    public function test_approve_requires_review_permission(): void
    {
        $item = Item::factory()->create(['is_active' => true]);
        $listing = $this->makeListing(['item_id' => $item->id]);

        $this->actingAs($this->unauthorized)
            ->patchJson("/api/v1/purchasing/supplier-listings/{$listing->hash_id}/approve")
            ->assertForbidden();
    }

    /* ─── Internal: reject ─────────────────────────────────── */

    public function test_reject_requires_reason_and_leaves_approved_suppliers_untouched(): void
    {
        $item = Item::factory()->create(['is_active' => true]);
        $listing = $this->makeListing(['item_id' => $item->id]);

        $this->actingAs($this->purchasingOfficer)
            ->patchJson("/api/v1/purchasing/supplier-listings/{$listing->hash_id}/reject")
            ->assertStatus(422);

        $this->actingAs($this->purchasingOfficer)
            ->patchJson("/api/v1/purchasing/supplier-listings/{$listing->hash_id}/reject", [
                'reason' => 'Price exceeds current market benchmark.',
            ])
            ->assertOk();

        $listing->refresh();
        $this->assertSame(SupplierListingStatus::Rejected, $listing->status);
        $this->assertSame('Price exceeds current market benchmark.', $listing->rejection_reason);
        $this->assertDatabaseCount('approved_suppliers', 0);
    }

    public function test_approved_listing_cannot_be_rejected(): void
    {
        $item = Item::factory()->create(['is_active' => true]);
        $listing = $this->makeListing([
            'item_id' => $item->id,
            'status' => SupplierListingStatus::Approved,
        ]);

        $this->actingAs($this->purchasingOfficer)
            ->patchJson("/api/v1/purchasing/supplier-listings/{$listing->hash_id}/reject", [
                'reason' => 'Too late.',
            ])
            ->assertStatus(422);
    }
}
