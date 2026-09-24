<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Enums\SupplierListingStatus;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use App\Modules\Purchasing\Models\SupplierItemListing;
use App\Modules\Purchasing\Services\VendorSourcingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Test VendorSourcingService price validity checks:
 * - Expired ApprovedSupplier prices are ignored
 * - Pending SupplierItemListings are ignored (only Approved)
 * - Expired SupplierItemListings are ignored
 * - Valid listings are used correctly
 */
class VendorSourcingPriceValidityTest extends TestCase
{
    use RefreshDatabase;

    private VendorSourcingService $service;
    private Vendor $vendor;
    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(VendorSourcingService::class);
        $this->vendor = Vendor::factory()->create(['is_active' => true]);
        $this->item = Item::factory()->create(['unit_of_measure' => 'pcs']);
    }

    /**
     * Test: Expired ApprovedSupplier price is ignored.
     * Falls back to a valid Pending listing (which should be ignored),
     * then to history price if available.
     */
    public function test_expired_approved_supplier_price_ignored(): void
    {
        // Create an Approved Supplier with an expired price.
        ApprovedSupplier::create([
            'item_id'               => $this->item->id,
            'vendor_id'             => $this->vendor->id,
            'qualification_status'  => 'approved',
            'is_preferred'          => false,
            'last_price'            => '10.00',
            'last_price_at'         => now()->subDay(),
            'price_valid_until'     => now()->subDay(), // Expired yesterday
        ]);

        // priceFor should return null (no other valid price source).
        $price = $this->service->priceFor($this->item->id, $this->vendor->id);
        $this->assertNull($price);
    }

    /**
     * Test: Valid ApprovedSupplier price (not expired) is returned.
     */
    public function test_valid_approved_supplier_price_returned(): void
    {
        ApprovedSupplier::create([
            'item_id'               => $this->item->id,
            'vendor_id'             => $this->vendor->id,
            'qualification_status'  => 'approved',
            'is_preferred'          => true,
            'last_price'            => '10.00',
            'last_price_at'         => now()->subDay(),
            'price_valid_until'     => now()->addDays(30), // Expires in 30 days
        ]);

        $price = $this->service->priceFor($this->item->id, $this->vendor->id);
        $this->assertSame('10.00', $price);
    }

    /**
     * Test: ApprovedSupplier with null price_valid_until (never expires) is used.
     */
    public function test_approved_supplier_with_no_expiry_always_valid(): void
    {
        ApprovedSupplier::create([
            'item_id'               => $this->item->id,
            'vendor_id'             => $this->vendor->id,
            'qualification_status'  => 'approved',
            'is_preferred'          => false,
            'last_price'            => '10.00',
            'last_price_at'         => now(),
            'price_valid_until'     => null, // No expiry
        ]);

        $price = $this->service->priceFor($this->item->id, $this->vendor->id);
        $this->assertSame('10.00', $price);
    }

    /**
     * Test: Pending listing is ignored (only Approved listings count).
     */
    public function test_pending_listing_ignored(): void
    {
        // Create a Pending listing (should be ignored).
        $listing = SupplierItemListing::create([
            'vendor_id'             => $this->vendor->id,
            'item_id'               => $this->item->id,
            'price'                 => '12.00',
            'lead_time_days'        => 7,
            'submitted_at'          => now(),
            'valid_until'           => now()->addDays(60),
        ]);
        $listing->forceFill(['status' => SupplierListingStatus::Pending->value])->save();

        // priceFor should return null since we don't count pending.
        $price = $this->service->priceFor($this->item->id, $this->vendor->id);
        $this->assertNull($price);
    }

    /**
     * Test: Expired approved listing is ignored.
     */
    public function test_expired_approved_listing_ignored(): void
    {
        $listing = SupplierItemListing::create([
            'vendor_id'             => $this->vendor->id,
            'item_id'               => $this->item->id,
            'price'                 => '12.00',
            'lead_time_days'        => 7,
            'submitted_at'          => now()->subDays(60),
            'valid_until'           => now()->subDay(), // Expired yesterday
        ]);
        $listing->forceFill(['status' => SupplierListingStatus::Approved->value])->save();

        $price = $this->service->priceFor($this->item->id, $this->vendor->id);
        $this->assertNull($price);
    }

    /**
     * Test: Valid approved listing is used when no ASL exists.
     */
    public function test_valid_approved_listing_used(): void
    {
        $listing = SupplierItemListing::create([
            'vendor_id'             => $this->vendor->id,
            'item_id'               => $this->item->id,
            'price'                 => '12.00',
            'base_qty_per_order_unit' => null, // Price is per base unit
            'lead_time_days'        => 7,
            'submitted_at'          => now()->subDays(30),
            'valid_until'           => now()->addDays(30), // Valid
        ]);
        $listing->forceFill(['status' => SupplierListingStatus::Approved->value])->save();

        $price = $this->service->priceFor($this->item->id, $this->vendor->id);
        $this->assertSame('12.00', $price);
    }

    /**
     * Test: Listing with null valid_until (never expires) is used.
     */
    public function test_listing_with_no_expiry_always_valid(): void
    {
        $listing = SupplierItemListing::create([
            'vendor_id'             => $this->vendor->id,
            'item_id'               => $this->item->id,
            'price'                 => '12.00',
            'lead_time_days'        => 7,
            'submitted_at'          => now()->subDays(30),
            'valid_until'           => null, // Never expires
        ]);
        $listing->forceFill(['status' => SupplierListingStatus::Approved->value])->save();

        $price = $this->service->priceFor($this->item->id, $this->vendor->id);
        $this->assertSame('12.00', $price);
    }

    /**
     * Test: ASL with valid price takes priority over listing.
     */
    public function test_valid_asl_prioritized_over_listing(): void
    {
        ApprovedSupplier::create([
            'item_id'               => $this->item->id,
            'vendor_id'             => $this->vendor->id,
            'qualification_status'  => 'approved',
            'is_preferred'          => true,
            'last_price'            => '10.00', // Cheaper
            'last_price_at'         => now(),
            'price_valid_until'     => now()->addDays(30),
        ]);

        $listing = SupplierItemListing::create([
            'vendor_id'             => $this->vendor->id,
            'item_id'               => $this->item->id,
            'price'                 => '12.00', // More expensive
            'lead_time_days'        => 7,
            'submitted_at'          => now(),
            'valid_until'           => now()->addDays(30),
        ]);
        $listing->forceFill(['status' => SupplierListingStatus::Approved->value])->save();

        // Should use ASL price (10.00), not listing (12.00).
        $price = $this->service->priceFor($this->item->id, $this->vendor->id);
        $this->assertSame('10.00', $price);
    }

    /**
     * Test: Expired ASL falls back to valid listing.
     */
    public function test_expired_asl_falls_back_to_valid_listing(): void
    {
        ApprovedSupplier::create([
            'item_id'               => $this->item->id,
            'vendor_id'             => $this->vendor->id,
            'qualification_status'  => 'approved',
            'is_preferred'          => true,
            'last_price'            => '10.00',
            'last_price_at'         => now(),
            'price_valid_until'     => now()->subDay(), // Expired
        ]);

        $listing = SupplierItemListing::create([
            'vendor_id'             => $this->vendor->id,
            'item_id'               => $this->item->id,
            'price'                 => '12.00',
            'lead_time_days'        => 7,
            'submitted_at'          => now(),
            'valid_until'           => now()->addDays(30), // Valid
        ]);
        $listing->forceFill(['status' => SupplierListingStatus::Approved->value])->save();

        // Should fall back to listing price.
        $price = $this->service->priceFor($this->item->id, $this->vendor->id);
        $this->assertSame('12.00', $price);
    }

    /**
     * Test: candidatesForItem still shows expired prices for visibility
     * (eligibility not changed, only price resolution).
     */
    public function test_candidates_still_show_expired_prices(): void
    {
        ApprovedSupplier::create([
            'item_id'               => $this->item->id,
            'vendor_id'             => $this->vendor->id,
            'qualification_status'  => 'approved',
            'is_preferred'          => true,
            'last_price'            => '10.00',
            'last_price_at'         => now(),
            'price_valid_until'     => now()->subDay(), // Expired
        ]);

        $candidates = $this->service->candidatesForItem($this->item->id);
        $this->assertNotEmpty($candidates);
        $candidate = $candidates[0];
        // Candidate should still show the expired price (for UI awareness).
        $this->assertSame('10.00', $candidate['price']);
    }
}
