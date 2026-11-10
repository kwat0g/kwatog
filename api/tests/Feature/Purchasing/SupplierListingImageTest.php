<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Common\Services\NotificationService;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Models\SupplierItemListing;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class SupplierListingImageTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    private SupplierPortalUser $supplier;

    private SupplierItemListing $listing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        $notifications = Mockery::mock(NotificationService::class)->makePartial();
        $notifications->shouldReceive('sendForSubject')->zeroOrMoreTimes();
        $this->app->instance(NotificationService::class, $notifications);
        Storage::fake('local');

        $this->vendor = Vendor::factory()->create();
        $this->supplier = SupplierPortalUser::factory()->create(['vendor_id' => $this->vendor->id]);
        $this->listing = new SupplierItemListing([
            'vendor_id' => $this->vendor->id,
            'item_id' => Item::factory()->create()->id,
            'price' => '100.00',
            'lead_time_days' => 5,
            'submitted_at' => now(),
        ]);
        $this->listing->status = 'pending';
        $this->listing->save();
        Sanctum::actingAs($this->supplier, ['*'], 'supplier_portal');
    }

    public function test_supplier_can_upload_and_view_its_private_listing_image(): void
    {
        $upload = $this->post("/api/v1/b2b/supplier/item-listings/{$this->listing->hash_id}/image", [
            'image' => UploadedFile::fake()->image('wiper.jpg', 800, 600)->size(300),
        ])->assertOk()->assertJsonPath('data.image.original_filename', 'wiper.jpg');

        $path = $this->listing->fresh()->image_path;
        $this->assertNotEmpty($path);
        Storage::disk('local')->assertExists($path);
        $this->get($upload->json('data.image.url'))->assertOk()->assertHeader('content-type', 'image/jpeg');
        $this->getJson('/api/v1/b2b/supplier/item-listings')
            ->assertOk()->assertJsonPath('data.0.image.original_filename', 'wiper.jpg');
    }

    public function test_supplier_cannot_view_or_replace_another_vendor_listing_image(): void
    {
        $other = Vendor::factory()->create();
        $listing = new SupplierItemListing([
            'vendor_id' => $other->id,
            'item_id' => Item::factory()->create()->id,
            'price' => '100.00', 'lead_time_days' => 5, 'submitted_at' => now(),
        ]);
        $listing->status = 'pending';
        $listing->save();

        $this->post("/api/v1/b2b/supplier/item-listings/{$listing->hash_id}/image", [
            'image' => UploadedFile::fake()->image('other.png'),
        ])->assertNotFound();
        $this->getJson('/api/v1/b2b/supplier/item-listings')
            ->assertOk()->assertJsonMissing(['id' => $listing->hash_id]);
    }

    public function test_listing_image_rejects_non_image_files_and_large_uploads(): void
    {
        $this->post("/api/v1/b2b/supplier/item-listings/{$this->listing->hash_id}/image", [
            'image' => UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors('image');

        $this->post("/api/v1/b2b/supplier/item-listings/{$this->listing->hash_id}/image", [
            'image' => UploadedFile::fake()->image('large.png')->size(10241),
        ])->assertUnprocessable()->assertJsonValidationErrors('image');
    }
}
