<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorDuplicateGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'finance_officer')->value('id'),
        ]);
    }

    public function test_create_vendor_with_duplicate_tin_different_formatting_rejected(): void
    {
        // Create first vendor with TIN in one format
        Vendor::factory()->create([
            'name' => 'Resin Supplier A',
            'tin' => '123-456-789-000',
        ]);

        // Attempt to create vendor with same TIN in different format (should fail)
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/vendors', [
                'name' => 'Resin Supplier B',
                'tin' => '123456789000',
                'is_active' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('tin');
        $this->assertStringContainsString('A vendor with this TIN already exists', $response->json('errors.tin.0'));
    }

    public function test_create_vendor_with_duplicate_name_different_case_rejected(): void
    {
        // Create vendor with name in one case
        Vendor::factory()->create([
            'name' => 'Walk Resin Supplier WALK1',
            'tin' => '100-100-100-001',
        ]);

        // Attempt to create vendor with same name but different case (should fail)
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/vendors', [
                'name' => 'walk resin supplier walk1',
                'tin' => '200-200-200-002',
                'is_active' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
        $this->assertStringContainsString('A vendor with this name already exists', $response->json('errors.name.0'));
    }

    public function test_create_vendor_with_duplicate_name_different_whitespace_rejected(): void
    {
        // Create vendor
        Vendor::factory()->create([
            'name' => 'Walk Resin Supplier WALK1',
            'tin' => '100-100-100-001',
        ]);

        // Attempt with extra spaces (should fail)
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/vendors', [
                'name' => '  Walk Resin Supplier WALK1  ',
                'tin' => '200-200-200-002',
                'is_active' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
    }

    public function test_update_vendor_keeping_own_tin_succeeds(): void
    {
        $vendor = Vendor::factory()->create([
            'name' => 'Original Name',
            'tin' => '123-456-789-000',
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/vendors/{$vendor->hash_id}", [
                'tin' => '123-456-789-000', // Same TIN
            ]);

        $response->assertStatus(200);
    }

    public function test_update_vendor_keeping_own_name_succeeds(): void
    {
        $vendor = Vendor::factory()->create([
            'name' => 'Original Name',
            'tin' => '123-456-789-000',
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/vendors/{$vendor->hash_id}", [
                'name' => 'Original Name', // Same name
            ]);

        $response->assertStatus(200);
    }

    public function test_legacy_duplicate_can_still_be_edited_without_renaming(): void
    {
        // Two copies that predate the guard (same name, same TIN, second copy
        // left unindexed by migration 0557's backfill).
        Vendor::factory()->create(['name' => 'Legacy Resin', 'tin' => '222-333-444-000']);
        $copy = Vendor::factory()->create(['name' => 'Legacy Resin', 'tin' => null]);
        \Illuminate\Support\Facades\DB::table('vendors')->where('id', $copy->id)
            ->update(['tin' => \Illuminate\Support\Facades\Crypt::encryptString('222333444000')]);

        $this->actingAs($this->user)
            ->putJson("/api/v1/vendors/{$copy->hash_id}", [
                'name' => 'Legacy Resin',
                'tin' => '222-333-444-000',
                'payment_terms_days' => 45,
            ])
            ->assertOk();

        $this->assertSame(45, $copy->fresh()->payment_terms_days);
    }

    public function test_importer_rejects_duplicate_name_and_tin(): void
    {
        Vendor::factory()->create(['name' => 'Import Resin', 'tin' => '555-666-777-000']);
        $importer = app(\App\Modules\Accounting\Imports\VendorImporter::class);

        try {
            $importer->importRow(['name' => '  import resin ']);
            $this->fail('Duplicate name was imported.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
        }

        $this->expectExceptionMessage('A vendor with this TIN already exists.');
        $importer->importRow(['name' => 'Other Resin', 'tin' => '555666777000']);
    }

    public function test_update_vendor_to_duplicate_tin_rejected(): void
    {
        $vendor1 = Vendor::factory()->create([
            'name' => 'Vendor 1',
            'tin' => '111-111-111-111',
        ]);

        $vendor2 = Vendor::factory()->create([
            'name' => 'Vendor 2',
            'tin' => '222-222-222-222',
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/vendors/{$vendor2->hash_id}", [
                'tin' => '111-111-111-111', // Try to change to vendor1's TIN
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('tin');
    }

    public function test_update_vendor_to_duplicate_name_rejected(): void
    {
        $vendor1 = Vendor::factory()->create([
            'name' => 'Vendor One',
            'tin' => '111-111-111-111',
        ]);

        $vendor2 = Vendor::factory()->create([
            'name' => 'Vendor Two',
            'tin' => '222-222-222-222',
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/vendors/{$vendor2->hash_id}", [
                'name' => 'Vendor One', // Try to change to vendor1's name
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
    }

    public function test_deleted_vendor_tin_does_not_block_new_vendor(): void
    {
        // Create and soft-delete a vendor
        $deleted = Vendor::factory()->create([
            'name' => 'Deleted Vendor',
            'tin' => '333-333-333-333',
        ]);
        $deleted->delete();

        // Should be able to create a new vendor with the same TIN
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/vendors', [
                'name' => 'New Vendor',
                'tin' => '333-333-333-333',
                'is_active' => true,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('vendors', [
            'name' => 'New Vendor',
        ]);
    }

    public function test_deleted_vendor_name_does_not_block_new_vendor(): void
    {
        // Create and soft-delete a vendor
        $deleted = Vendor::factory()->create([
            'name' => 'Deleted Name',
            'tin' => '444-444-444-444',
        ]);
        $deleted->delete();

        // Should be able to create a new vendor with the same name
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/vendors', [
                'name' => 'Deleted Name',
                'tin' => '555-555-555-555',
                'is_active' => true,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('vendors', [
            'name' => 'Deleted Name',
        ]);
        // Verify the new vendor has a non-null deleted_at for the old one and null for the new
        $this->assertNotNull(Vendor::withoutGlobalScopes()->where('name', 'Deleted Name')->where('id', $deleted->id)->first()->deleted_at);
        $newVendor = Vendor::where('name', 'Deleted Name')->first();
        $this->assertNotNull($newVendor);
        $this->assertNull($newVendor->deleted_at);
    }

    public function test_tin_hash_not_exposed_in_api(): void
    {
        $vendor = Vendor::factory()->create([
            'name' => 'Test Vendor',
            'tin' => '666-666-666-666',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/vendors/{$vendor->hash_id}");

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('tin_hash', $response->json('data'));
    }

    public function test_tin_list_response_does_not_expose_tin_hash(): void
    {
        Vendor::factory()->count(3)->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/vendors');

        $response->assertStatus(200);
        foreach ($response->json('data') as $vendor) {
            $this->assertArrayNotHasKey('tin_hash', $vendor);
        }
    }

    public function test_create_vendor_without_tin_succeeds(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/vendors', [
                'name' => 'No TIN Vendor',
                'is_active' => true,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('vendors', ['name' => 'No TIN Vendor']);
    }

    public function test_create_multiple_vendors_without_tin_succeeds(): void
    {
        // Multiple vendors can be created without TIN (TIN is optional)
        $response1 = $this->actingAs($this->user)
            ->postJson('/api/v1/vendors', [
                'name' => 'No TIN Vendor 1',
                'is_active' => true,
            ]);

        $response2 = $this->actingAs($this->user)
            ->postJson('/api/v1/vendors', [
                'name' => 'No TIN Vendor 2',
                'is_active' => true,
            ]);

        $response1->assertStatus(201);
        $response2->assertStatus(201);
    }

    public function test_create_vendor_with_empty_string_tin_succeeds(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/vendors', [
                'name' => 'Empty TIN Vendor',
                'tin' => '',
                'is_active' => true,
            ]);

        $response->assertStatus(201);
    }

    public function test_create_vendor_with_non_numeric_tin_only_succeeds(): void
    {
        // TIN with only dashes/hyphens should be allowed to save
        // (normalizes to empty, which is treated as NULL for uniqueness)
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/vendors', [
                'name' => 'Dashes Only Vendor',
                'tin' => '---',
                'is_active' => true,
            ]);

        $response->assertStatus(201);
    }
}
