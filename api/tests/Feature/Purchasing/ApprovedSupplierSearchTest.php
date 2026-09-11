<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The approved-suppliers list rendered a search box whose value the service
 * ignored (`ApprovedSupplierService::list` never read `search`), so typing did
 * nothing. The service also supported item/vendor/preferred filters the page
 * never exposed. These pin the repaired contract.
 */
class ApprovedSupplierSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'purchasing_officer')->value('id'),
        ]);
    }

    private function link(Item $item, Vendor $vendor, array $overrides = []): ApprovedSupplier
    {
        return ApprovedSupplier::create(array_merge([
            'item_id'              => $item->id,
            'vendor_id'            => $vendor->id,
            'is_preferred'         => false,
            'qualification_status' => ApprovedSupplier::QUALIFICATION_APPROVED,
            'lead_time_days'       => 5,
        ], $overrides));
    }

    /** @return array<int, string> */
    private function itemCodes(User $user, array $query): array
    {
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/purchasing/approved-suppliers?per_page=100&'.http_build_query($query))
            ->assertOk();

        return array_map(static fn (array $row): string => (string) $row['item']['code'], $response->json('data'));
    }

    public function test_search_matches_item_code_name_vendor_name_and_supplier_part(): void
    {
        $user = $this->user();
        $resin = Item::factory()->create(['code' => 'RM-1001', 'name' => 'ABS Resin']);
        $pigment = Item::factory()->create(['code' => 'RM-2002', 'name' => 'Pigment Red']);
        $this->link($resin, Vendor::factory()->create(['name' => 'Alpha Plastics']), ['supplier_item_code' => 'AP-777']);
        $this->link($pigment, Vendor::factory()->create(['name' => 'Beta Chemicals']), ['supplier_item_code' => 'BC-999']);

        $this->assertSame(['RM-1001'], $this->itemCodes($user, ['search' => 'RM-1001']));
        $this->assertSame(['RM-2002'], $this->itemCodes($user, ['search' => 'Pigment']));
        $this->assertSame(['RM-1001'], $this->itemCodes($user, ['search' => 'Alpha']));
        $this->assertSame(['RM-2002'], $this->itemCodes($user, ['search' => 'bc-999']));
    }

    public function test_filters_by_item_vendor_preferred_and_qualification(): void
    {
        $user = $this->user();
        $resin = Item::factory()->create(['code' => 'RM-1001', 'name' => 'ABS Resin']);
        $pigment = Item::factory()->create(['code' => 'RM-2002', 'name' => 'Pigment Red']);
        $alpha = Vendor::factory()->create(['name' => 'Alpha Plastics']);
        $beta = Vendor::factory()->create(['name' => 'Beta Chemicals']);

        $this->link($resin, $alpha, ['is_preferred' => true]);
        $this->link($pigment, $beta, ['qualification_status' => ApprovedSupplier::QUALIFICATION_PROVISIONAL]);

        $this->assertSame(['RM-1001'], $this->itemCodes($user, ['item_id' => $resin->hash_id]));
        $this->assertSame(['RM-2002'], $this->itemCodes($user, ['vendor_id' => $beta->hash_id]));
        $this->assertSame(['RM-1001'], $this->itemCodes($user, ['is_preferred' => 'true']));
        $this->assertSame(['RM-2002'], $this->itemCodes($user, ['is_preferred' => 'false']));
        $this->assertSame(['RM-2002'], $this->itemCodes($user, ['qualification_status' => 'provisional']));
        $this->assertSame(['RM-1001'], $this->itemCodes($user, ['qualification_status' => 'approved']));
    }

    public function test_options_only_offer_linked_items_and_vendors(): void
    {
        $user = $this->user();
        $linkedItem = Item::factory()->create(['code' => 'RM-1001', 'name' => 'ABS Resin']);
        Item::factory()->create(['code' => 'RM-9999', 'name' => 'Unlinked']);
        $linkedVendor = Vendor::factory()->create(['name' => 'Alpha Plastics']);
        Vendor::factory()->create(['name' => 'Unlinked Vendor']);

        $this->link($linkedItem, $linkedVendor);

        $data = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/purchasing/approved-suppliers/options')
            ->assertOk()
            ->json('data');

        $itemValues = array_column($data['items'], 'value');
        $vendorValues = array_column($data['vendors'], 'value');

        $this->assertSame([$linkedItem->hash_id], $itemValues);
        $this->assertSame([$linkedVendor->hash_id], $vendorValues);
        $this->assertSame(['approved', 'provisional'], array_column($data['qualification_statuses'], 'value'));
    }
}
