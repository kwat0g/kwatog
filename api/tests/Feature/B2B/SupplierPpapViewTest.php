<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Inventory\Models\Item;
use App\Modules\Quality\Enums\PpapLevel;
use App\Modules\Quality\Enums\PpapStatus;
use App\Modules\Quality\Models\PpapSubmission;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupplierPpapViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
    }

    public function test_supplier_sees_only_own_ppap_submissions(): void
    {
        $vendor = Vendor::factory()->create();
        $otherVendor = Vendor::factory()->create();
        $supplier = SupplierPortalUser::create([
            'vendor_id' => $vendor->id, 'name' => 'Test User',
            'email' => 'sup@test.com', 'password' => bcrypt('pw'),
        ]);

        $item = Item::factory()->create();
        PpapSubmission::create([
            'ppap_number' => 'PP-T-00001', 'vendor_id' => $vendor->id,
            'item_id' => $item->id, 'ppap_level' => PpapLevel::Level3->value,
            'submission_date' => '2026-06-01', 'status' => PpapStatus::Draft->value,
        ]);
        PpapSubmission::create([
            'ppap_number' => 'PP-T-00002', 'vendor_id' => $vendor->id,
            'item_id' => $item->id, 'ppap_level' => PpapLevel::Level1->value,
            'submission_date' => '2026-06-10', 'status' => PpapStatus::Approved->value,
        ]);
        PpapSubmission::create([
            'ppap_number' => 'PP-T-00003', 'vendor_id' => $otherVendor->id,
            'item_id' => $item->id, 'ppap_level' => PpapLevel::Level2->value,
            'submission_date' => '2026-06-15', 'status' => PpapStatus::Draft->value,
        ]);

        Sanctum::actingAs($supplier, ['*'], 'supplier_portal');

        $response = $this->getJson('/api/v1/b2b/supplier/ppap-submissions');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_supplier_can_filter_by_status(): void
    {
        $vendor = Vendor::factory()->create();
        $supplier = SupplierPortalUser::create([
            'vendor_id' => $vendor->id, 'name' => 'Filter User',
            'email' => 'filter@test.com', 'password' => bcrypt('pw'),
        ]);

        $item = Item::factory()->create();
        PpapSubmission::create([
            'ppap_number' => 'PP-T-00004', 'vendor_id' => $vendor->id,
            'item_id' => $item->id, 'ppap_level' => PpapLevel::Level3->value,
            'submission_date' => '2026-06-01', 'status' => PpapStatus::Approved->value,
        ]);
        PpapSubmission::create([
            'ppap_number' => 'PP-T-00005', 'vendor_id' => $vendor->id,
            'item_id' => $item->id, 'ppap_level' => PpapLevel::Level3->value,
            'submission_date' => '2026-06-10', 'status' => PpapStatus::Draft->value,
        ]);

        Sanctum::actingAs($supplier, ['*'], 'supplier_portal');

        $response = $this->getJson('/api/v1/b2b/supplier/ppap-submissions?status=approved');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_unauthenticated_is_401(): void
    {
        $response = $this->getJson('/api/v1/b2b/supplier/ppap-submissions');
        $response->assertStatus(401);
    }
}
