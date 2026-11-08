<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Enums\SupplierListingStatus;
use App\Modules\Purchasing\Models\SupplierItemListing;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierListingAvailableActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
    }

    public function test_supplier_listing_actions_are_computed_for_the_requesting_user_and_state(): void
    {
        $reviewer = $this->userWithPermissions(
            'purchasing.supplier_listings.view',
            'purchasing.supplier_listings.review',
        );
        $viewer = $this->userWithPermissions('purchasing.supplier_listings.view');
        $pending = $this->listing();

        $this->actingAs($reviewer)
            ->getJson('/api/v1/purchasing/supplier-listings?id='.$pending->hash_id)
            ->assertOk()
            ->assertJsonPath('data.0.permissions.view', true)
            ->assertJsonPath('data.0.permissions.review', true)
            ->assertJsonPath('data.0.available_actions.approve', true)
            ->assertJsonPath('data.0.available_actions.reject', true);

        $this->actingAs($reviewer)
            ->patchJson('/api/v1/purchasing/supplier-listings/'.$pending->hash_id.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.permissions.review', true)
            ->assertJsonPath('data.available_actions.approve', false)
            ->assertJsonPath('data.available_actions.reject', false);

        $anotherPending = $this->listing();
        $this->actingAs($viewer)
            ->getJson('/api/v1/purchasing/supplier-listings?id='.$anotherPending->hash_id)
            ->assertOk()
            ->assertJsonPath('data.0.permissions.view', true)
            ->assertJsonPath('data.0.permissions.review', false)
            ->assertJsonPath('data.0.available_actions.approve', false)
            ->assertJsonPath('data.0.available_actions.reject', false);
    }

    private function userWithPermissions(string ...$permissions): User
    {
        $role = Role::query()->create([
            'name' => 'Supplier Listing Actions '.substr(uniqid(), -6),
            'slug' => 'supplier_listing_actions_'.substr(uniqid(), -6),
            'is_system' => false,
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', $permissions)->pluck('id')->all());

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function listing(): SupplierItemListing
    {
        $listing = new SupplierItemListing([
            'vendor_id' => Vendor::factory()->create()->id,
            'item_id' => Item::factory()->create()->id,
            'price' => '100.00',
            'lead_time_days' => 5,
            'submitted_at' => now(),
        ]);
        $listing->status = SupplierListingStatus::Pending;
        $listing->save();

        return $listing;
    }
}
