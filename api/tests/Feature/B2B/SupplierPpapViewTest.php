<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Inventory\Models\Item;
use App\Modules\Quality\Enums\PpapElementStatus;
use App\Modules\Quality\Enums\PpapElementType;
use App\Modules\Quality\Enums\PpapLevel;
use App\Modules\Quality\Enums\PpapStatus;
use App\Modules\Quality\Models\PpapElement;
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
            'is_active' => true,
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
            'is_active' => true,
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

    public function test_supplier_ppap_response_never_exposes_the_private_document_path(): void
    {
        // PpapSubmissionResource / PpapElementResource are the INTERNAL Quality
        // contracts and emit `document_path` — the raw private storage path of
        // the uploaded PPAP evidence. There is no supplier PPAP download route,
        // so the field is useless to the portal client and discloses the vault
        // layout to an external principal. The supplier endpoint needs its own
        // allowlist; the Quality resources stay as they are for internal use.
        $vendor = Vendor::factory()->create();
        $supplier = SupplierPortalUser::create([
            'vendor_id' => $vendor->id, 'name' => 'PPAP User',
            'email' => 'ppap-path@test.com', 'password' => bcrypt('pw'),
            'is_active' => true,
        ]);

        $submission = PpapSubmission::create([
            'ppap_number' => 'PP-T-00006', 'vendor_id' => $vendor->id,
            'item_id' => Item::factory()->create()->id,
            'ppap_level' => PpapLevel::Level3->value,
            'submission_date' => '2026-06-01', 'status' => PpapStatus::Submitted->value,
        ]);
        PpapElement::create([
            'ppap_submission_id' => $submission->id,
            'element_type' => PpapElementType::ControlPlan->value,
            'status' => PpapElementStatus::Submitted->value,
            'document_path' => 'ppap/private/vault/control-plan-secret.pdf',
            'notes' => 'Control plan revision C',
        ]);

        Sanctum::actingAs($supplier, ['*'], 'supplier_portal');

        $response = $this->getJson('/api/v1/b2b/supplier/ppap-submissions')->assertOk();

        // Compare against the decoded payload, not the raw body: json_encode
        // escapes "/" as "\/", so a substring check on the raw content silently
        // passes even when the path is present.
        $this->assertStringNotContainsString(
            'ppap/private/vault',
            json_encode($response->json(), JSON_UNESCAPED_SLASHES) ?: '',
            'the supplier PPAP response leaked a private storage path',
        );

        $element = $response->json('data.0.elements.0');
        $this->assertIsArray($element);
        $this->assertArrayNotHasKey('document_path', $element);
        // The supplier still needs the element identity and its own status.
        $this->assertSame('control_plan', $element['element_type']);
        $this->assertSame('submitted', $element['status']);
    }

    public function test_unauthenticated_is_401(): void
    {
        $response = $this->getJson('/api/v1/b2b/supplier/ppap-submissions');
        $response->assertStatus(401);
    }
}
